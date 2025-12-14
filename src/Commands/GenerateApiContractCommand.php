<?php

namespace Abr4xas\McpTools\Commands;


use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class GenerateApiContractCommand extends Command
{
    protected $signature = 'api:contract:generate';

    protected $description = 'Generate a public-facing API contract for MCP consumption';

    protected array $availableResources = [];

    /** @var array<string, ReflectionMethod> */
    protected array $reflectionCache = [];

    /** @var array<string, array> */
    protected array $resourceSchemaCache = [];

    public function handle(): int
    {
        $this->info('Generating API contract...');

        // Optimization: Pre-scan resources to avoid File scanning inside the loop
        $this->preloadResources();

        $routes = Route::getRoutes()->getRoutes();
        $contract = [];
        $queryMethods = ['GET', 'HEAD', 'DELETE'];

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! Str::startsWith($uri, 'api/')) {
                continue;
            }

            $normalizedUri = '/' . mb_ltrim($uri, '/');
            $methods = $route->methods();
            $action = $route->getAction('uses');

            foreach ($methods as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $this->output->write("Processing {$method} {$normalizedUri} ... ");

                $pathParams = $this->extractPathParams($normalizedUri);
                $auth = $this->determineAuth($route);
                $customHeaders = $this->extractCustomHeaders($route);
                $rateLimit = $this->extractRateLimit($route);
                $apiVersion = $this->extractApiVersion($normalizedUri);

                $isQuery = in_array($method, $queryMethods, true);
                $requestSchema = $this->extractRequestSchema($action, $isQuery);

                $responseSchema = $this->extractResponseSchema($action, $normalizedUri);

                $contract[$normalizedUri][$method] = [
                    'auth' => $auth,
                    'path_parameters' => $pathParams,
                    'request_schema' => $requestSchema,
                    'response_schema' => $responseSchema,
                    'custom_headers' => $customHeaders,
                    'rate_limit' => $rateLimit,
                    'api_version' => $apiVersion,
                ];

                $this->output->writeln('Done.');
            }
        }

        $directory = storage_path('api-contracts');
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $fullPath = "{$directory}/api.json";
        $json = json_encode($contract, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('Failed to encode contract to JSON');

            return self::FAILURE;
        }

        File::put($fullPath, $json);

        $this->info("Contract generated at: {$fullPath}");

        return self::SUCCESS;
    }

    private function extractPathParams(string $uri): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        return $matches[1];
    }

    private function determineAuth($route): array
    {
        $middlewares = $route->gatherMiddleware();
        $auth = ['type' => 'none'];

        foreach ($middlewares as $mw) {
            if (is_string($mw)) {
                if (Str::contains($mw, 'auth:sanctum') || Str::contains($mw, 'auth:api')) {
                    $auth = ['type' => 'bearer'];

                    break;
                }
                if (Str::contains($mw, 'guest')) {
                    // Explicit guest
                }
            }
        }

        return $auth;
    }

    private function extractRequestSchema($action, bool $isQuery): array
    {
        if (! is_string($action) || ! Str::contains($action, '@')) {
            return [];
        }

        [$controller, $controllerMethod] = explode('@', $action);
        $cacheKey = "{$controller}::{$controllerMethod}";

        try {
            $reflection = $this->getReflectionMethod($controller, $controllerMethod, $cacheKey);
            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $class = $type->getName();
                    if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                        try {
                            $formRequest = new $class();
                            if (method_exists($formRequest, 'rules')) {
                                $rules = $formRequest->rules();
                                $parsed = $this->parseValidationRules($rules);

                                return [
                                    'location' => $isQuery ? 'query' : 'body',
                                    'properties' => $parsed,
                                ];
                            }
                        } catch (Throwable $e) {
                            $this->warn("Could not instantiate FormRequest {$class}: {$e->getMessage()}");

                            return ['location' => 'unknown', 'properties' => [], 'error' => 'Could not instantiate FormRequest'];
                        }
                    }
                }
            }

            // If no FormRequest found, check if method accepts Request parameter for query params
            if ($isQuery) {
                foreach ($reflection->getParameters() as $param) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === Request::class || $typeName === 'Request') {
                            // Method accepts Request, might have query params but we can't infer them
                            return ['location' => 'query', 'properties' => []];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->warn("Could not reflect method {$cacheKey}: {$e->getMessage()}");

            return [];
        }

        return [];
    }

    private function getReflectionMethod(string $controller, string $method, string $cacheKey): ReflectionMethod
    {
        if (! isset($this->reflectionCache[$cacheKey])) {
            $this->reflectionCache[$cacheKey] = new ReflectionMethod($controller, $method);
        }

        return $this->reflectionCache[$cacheKey];
    }

    private function parseValidationRules(array $rules): array
    {
        $schema = [];
        foreach ($rules as $field => $rule) {
            // Convert dot notation to bracket notation for query params
            if (Str::contains($field, '.')) {
                $parts = explode('.', (string) $field);
                $root = array_shift($parts);
                $transformedField = $root . '[' . implode('][', $parts) . ']';
            } else {
                $transformedField = (string) $field;
            }

            $constraints = [];
            $ruleParts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []);

            $type = 'string';
            $required = false;

            foreach ($ruleParts as $part) {
                if (! is_string($part)) {
                    continue;
                }

                $part = mb_trim($part);

                if ($part === 'required') {
                    $required = true;
                } elseif ($part === 'integer' || $part === 'int') {
                    $type = 'integer';
                } elseif (in_array($part, ['numeric', 'float', 'double'], true)) {
                    $type = 'number';
                } elseif ($part === 'boolean' || $part === 'bool') {
                    $type = 'boolean';
                } elseif ($part === 'array') {
                    $type = 'array';
                } elseif ($part === 'string') {
                    $type = 'string';
                } elseif ($part === 'email') {
                    $constraints[] = 'email';
                } elseif ($part === 'url') {
                    $constraints[] = 'url';
                } elseif ($part === 'uuid') {
                    $constraints[] = 'uuid';
                } elseif ($part === 'date') {
                    $constraints[] = 'date';
                } elseif (Str::startsWith($part, 'min:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'max:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'in:')) {
                    $constraints[] = 'enum: ' . mb_substr($part, 3);
                } elseif (Str::startsWith($part, 'regex:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'exists:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'unique:')) {
                    $constraints[] = $part;
                }
            }

            $schema[$transformedField] = [
                'type' => $type,
                'required' => $required,
                'constraints' => $constraints,
            ];
        }

        return $schema;
    }

    private function extractResponseSchema($action, string $uri): array
    {
        if (is_string($action) && Str::contains($action, '@')) {
            [$controller, $controllerMethod] = explode('@', $action);
            $cacheKey = "{$controller}::{$controllerMethod}";

            try {
                $reflection = $this->getReflectionMethod($controller, $controllerMethod, $cacheKey);
                $returnType = $reflection->getReturnType();

                if ($returnType instanceof ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    // Direct Resource
                    if (Str::contains($typeName, 'Resource')) {
                        $simulated = $this->simulateResourceOutput($typeName);
                        if (! isset($simulated['undocumented'])) {
                            return $simulated;
                        }
                    }
                    // JsonResponse - analyze method body
                    if ($typeName === JsonResponse::class || $typeName === 'JsonResponse') {
                        $resourceFromCode = $this->extractResourceFromMethodBody($reflection);
                        if ($resourceFromCode) {
                            $simulated = $this->simulateResourceOutput($resourceFromCode);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        }
                    }
                    // Response Wrapper (e.g. PostsIndexResponse)
                    if (class_exists($typeName) && ! Str::startsWith($typeName, 'Illuminate\\')) {
                        // Inspect the class for Resource usage
                        $detectedResource = $this->inspectResponseClass($typeName);
                        if ($detectedResource) {
                            $simulated = $this->simulateResourceOutput($detectedResource);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        }
                    }
                } else {
                    // No return type, analyze method body
                    $resourceFromCode = $this->extractResourceFromMethodBody($reflection);
                    if ($resourceFromCode) {
                        $simulated = $this->simulateResourceOutput($resourceFromCode);
                        if (! isset($simulated['undocumented'])) {
                            return $simulated;
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->warn("Could not extract response schema for {$cacheKey}: {$e->getMessage()}");
            }
        }

        // Heuristic Strategy
        $urlParts = explode('/', mb_trim($uri, '/'));
        $resourceName = end($urlParts);
        if (Str::startsWith($resourceName, '{')) {
            $resourceName = prev($urlParts);
        }

        if (! $resourceName) {
            return ['undocumented' => true];
        }

        $resourceName = Str::singular($resourceName); // Intelligent singularization
        $resourceName = ucfirst($resourceName);

        $candidates = [
            "App\\Http\\Resources\\{$resourceName}Resource",
            "App\\Http\\Resources\\{$resourceName}OverviewResource",
            "App\\Http\\Resources\\{$resourceName}Collection",
            'App\\Http\\Resources\\' . ucfirst($resourceName) . 'Resource',
            'App\\Http\\Resources\\Posts\\' . ucfirst($resourceName) . 'Resource', // Heuristic folder
        ];

        // Use pre-loaded matching
        foreach ($this->availableResources as $name => $fullClass) {
            if (Str::contains($name, $resourceName) && Str::endsWith($name, 'Resource')) {
                $candidates[] = $fullClass;
            }
            if (Str::contains($name, $resourceName) && Str::endsWith($name, 'Collection')) {
                $candidates[] = $fullClass;
            }
        }

        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                $simulated = $this->simulateResourceOutput($class);
                if (! isset($simulated['undocumented'])) {
                    return $simulated;
                }
            }
        }

        return ['undocumented' => true];
    }

    private function preloadResources(): void
    {
        $basePath = app_path('Http/Resources');
        if (File::exists($basePath)) {
            $files = File::allFiles($basePath);
            foreach ($files as $file) {
                $this->availableResources[$file->getFilenameWithoutExtension()] = $this->getClassNameFromFile($file);
            }
        }
    }

    private function inspectResponseClass(string $responseClass): ?string
    {
        static $cache = [];

        if (isset($cache[$responseClass])) {
            return $cache[$responseClass];
        }

        try {
            // Very simple static analysis: check use statements or new keywords in file
            $ref = new ReflectionClass($responseClass);
            $content = file_get_contents($ref->getFileName());

            if ($content === false) {
                $cache[$responseClass] = null;

                return null;
            }

            // Look for "use App\Http\Resources\...\SomeResource;" using a somewhat loose regex
            // or "new SomeResource"
            // Let's grab all "use App\Http\Resources\..." statements
            preg_match_all('/use (App\\\Http\\\Resources\\\.*);/', $content, $matches);
            if ($matches[1] !== []) {
                // If there's a Collection, prefer it for Index logic? Or just try all.
                // Logic: if class name contains Index, prioritize Collection
                // if class name contains Show, prioritize Resource

                $basename = class_basename($responseClass);
                foreach ($matches[1] as $resClass) {
                    if (Str::contains($basename, 'Index') && Str::contains($resClass, 'Collection')) {
                        $cache[$responseClass] = $resClass;

                        return $resClass;
                    }
                }

                // Fallback to first one
                $result = $matches[1][0];
                $cache[$responseClass] = $result;

                return $result;
            }
        } catch (Throwable $e) {
            $this->warn("Could not inspect response class {$responseClass}: {$e->getMessage()}");
        }

        $cache[$responseClass] = null;

        return null;
    }

    private function getClassNameFromFile($fileItem): string
    {
        $path = $fileItem->getPath();
        $base = app_path('Http/Resources');
        $relative = mb_trim(str_replace($base, '', $path), DIRECTORY_SEPARATOR);
        $namespace = 'App\\Http\\Resources';
        if ($relative !== '' && $relative !== '0') {
            $namespace .= '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        return $namespace . '\\' . $fileItem->getFilenameWithoutExtension();
    }

    private function simulateResourceOutput(string $resourceClass): array
    {
        // Cache schema results to avoid recreating models
        if (isset($this->resourceSchemaCache[$resourceClass])) {
            return $this->resourceSchemaCache[$resourceClass];
        }

        $basics = class_basename($resourceClass);
        // Identify model from name
        // PostCollection -> Post
        // PostResource -> Post
        $modelName = str_replace(['Resource', 'Overview', 'Collection'], '', $basics);
        $modelClass = "App\\Models\\{$modelName}";

        if (! class_exists($modelClass)) {
            $result = ['undocumented' => true];
            $this->resourceSchemaCache[$resourceClass] = $result;

            return $result;
        }

        if (method_exists($modelClass, 'factory')) {
            try {
                $model = $modelClass::factory()->make();

                // Ensure critical date fields are set to prevent accessor crashes (e.g. publish_date)
                $dateFields = ['publish_date', 'published_at', 'created_at', 'updated_at', 'deleted_at'];
                foreach ($dateFields as $dateField) {
                    if (isset($model->$dateField) && ! $model->$dateField) {
                        $model->$dateField = now();
                    }
                }

                // If it's a collection resource or ends in Collection
                if (Str::endsWith($basics, 'Collection') || is_subclass_of($resourceClass, ResourceCollection::class)) {
                    // Pass a Paginator with fake items
                    $items = collect([$model]);
                    $paginator = new LengthAwarePaginator($items, 1, 15);

                    $resource = new $resourceClass($paginator);
                    // toResponse(request())->getData(true) is safer for Collections usually
                    $resp = $resource->toResponse(request())->getData(true); // returns array with meta/links usually
                    // We just want 'data'

                    $result = $this->dataToSchema($resp);
                    $this->resourceSchemaCache[$resourceClass] = $result;

                    return $result;
                }

                // Single Resource
                $resource = new $resourceClass($model);
                $data = $resource->resolve(request());

                $result = $this->dataToSchema($data);
                $this->resourceSchemaCache[$resourceClass] = $result;

                return $result;
            } catch (Throwable $e) {
                $this->warn("Factory failed for {$resourceClass}: {$e->getMessage()}");
                $result = ['undocumented' => true, 'error' => 'Factory failed'];
                $this->resourceSchemaCache[$resourceClass] = $result;

                return $result;
            }
        }

        $result = ['undocumented' => true, 'hint' => $basics];
        $this->resourceSchemaCache[$resourceClass] = $result;

        return $result;
    }

    private function dataToSchema(array $data): array
    {
        $schema = [];
        foreach ($data as $key => $value) {
            $type = gettype($value);
            if ($type === 'array') {
                if (Arr::isAssoc($value)) {
                    $schema[$key] = ['type' => 'object', 'properties' => $this->dataToSchema($value)];
                } else {
                    $first = $value[0] ?? null;
                    if (is_array($first)) {
                        $schema[$key] = ['type' => 'array', 'items' => $this->dataToSchema($first)];
                    } else {
                        $schema[$key] = ['type' => 'array', 'items' => ['type' => gettype($first)]];
                    }
                }
            } else {
                $schema[$key] = ['type' => $type];
            }
        }

        return $schema;
    }

    /**
     * Extract Resource class name from method body by analyzing source code
     */
    private function extractResourceFromMethodBody(ReflectionMethod $reflection): ?string
    {
        try {
            $fileName = $reflection->getFileName();
            if (! $fileName) {
                return null;
            }

            $content = file_get_contents($fileName);
            if ($content === false) {
                return null;
            }

            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if ($startLine === false || $endLine === false) {
                return null;
            }

            $lines = explode("\n", $content);
            $methodBody = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

            // Look for patterns like:
            // Resource::make(...)
            // Resource::collection(...)
            // new Resource(...)
            // return Resource::make(...)
            // return Resource::collection(...)
            // ApiResponse::data([...Resource::...])

            // Pattern 1: Resource::make() or Resource::collection() - most common
            if (preg_match('/(\w+Resource)::(make|collection)\(/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
                // Try common namespaces
                $commonPaths = [
                    "App\\Http\\Resources\\{$resourceName}",
                    "App\\Http\\Resources\\Cards\\{$resourceName}",
                    "App\\Http\\Resources\\Users\\{$resourceName}",
                    "App\\Http\\Resources\\Companies\\{$resourceName}",
                    "App\\Http\\Resources\\ServiceCompanies\\{$resourceName}",
                    "App\\Http\\Resources\\Invest\\{$resourceName}",
                ];
                foreach ($commonPaths as $path) {
                    if (class_exists($path)) {
                        return $path;
                    }
                }
            }

            // Pattern 2: new Resource(...)
            if (preg_match('/new (\w+Resource)\(/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 3: Look in ApiResponse::data() calls
            if (preg_match('/ApiResponse::data\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 4: Look for toResourceCollection calls
            if (preg_match('/->toResourceCollection\((\w+Resource)::class\)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 5: response()->json([...Resource::...])
            if (preg_match('/response\(\)->json\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 6: Response::json([...Resource::...])
            if (preg_match('/Response::json\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }
        } catch (Throwable $e) {
            $this->warn("Could not extract resource from method body: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Find full class name from use statements in the file
     */
    private function findFullClassName(ReflectionMethod $reflection, string $shortName): ?string
    {
        try {
            $fileName = $reflection->getFileName();
            if (! $fileName) {
                return null;
            }

            $content = file_get_contents($fileName);
            if ($content === false) {
                return null;
            }

            // Look for use statements - match the full line
            // Pattern: use App\Http\Resources\CardResource;
            // Pattern: use App\Http\Resources\Cards\CardResource;
            if (preg_match('/use\s+([^;]+' . preg_quote($shortName, '/') . ')\s*;/', $content, $matches)) {
                return mb_trim($matches[1]);
            }

            // Look for use statements with as alias
            // Pattern: use App\Http\Resources\Cards\CardResource as CardResource;
            if (preg_match('/use\s+([^;]+)\s+as\s+' . preg_quote($shortName, '/') . '\s*;/', $content, $matches)) {
                return mb_trim($matches[1]);
            }

            // If not found in use statements, try to find in available resources
            foreach ($this->availableResources as $name => $fullClass) {
                if ($name === $shortName || Str::endsWith($fullClass, "\\{$shortName}")) {
                    return $fullClass;
                }
            }
        } catch (Throwable) {
            // Ignore errors
        }

        return null;
    }

    /**
     * Extract rate limit information from route (useful for frontend)
     */
    private function extractRateLimit($route): ?array
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, 'throttle:')) {
                $throttleName = Str::after($middleware, 'throttle:');

                // Common rate limit names and their descriptions for frontend
                $rateLimitDescriptions = [
                    'api' => '60 requests per minute',
                    'webhook' => '5000 requests per minute',
                    'login' => '5 requests per minute (100 with x-wb-postman header)',
                    'signup' => '5 requests per minute (100 with x-wb-postman header)',
                    'sessions' => '5 requests per minute (100 with x-wb-postman header)',
                    'phone-number' => '3 requests per minute (100 with x-wb-postman header)',
                ];

                return [
                    'name' => $throttleName,
                    'description' => $rateLimitDescriptions[$throttleName] ?? "Rate limit: {$throttleName}",
                ];
            }
        }

        return null;
    }

    /**
     * Extract API version from route path (useful for frontend to know which version to use)
     */
    private function extractApiVersion(string $uri): ?string
    {
        if (preg_match('#/api/(v\d+)/#', $uri, $matches)) {
            return $matches[1];
        }

        if (Str::startsWith($uri, '/api/v1/')) {
            return 'v1';
        }

        if (Str::startsWith($uri, '/api/v2/')) {
            return 'v2';
        }

        return null;
    }

    /**
     * Extract custom headers required for the route based on middleware
     * @todo: find a way to extract custom headers from middleware
     */
    private function extractCustomHeaders($route): array
    {
        $headers = [];


        // Check route action for hints about required headers
        $action = $route->getAction('uses');
        // Webhook endpoints typically need signature headers
        if (is_string($action) && Str::contains($action, 'Webhook')) {
            $headers[] = [
                'name' => 'X-Signature',
                'required' => true,
                'description' => 'Webhook signature for request validation',
            ];
        }

        return $headers;
    }
}
