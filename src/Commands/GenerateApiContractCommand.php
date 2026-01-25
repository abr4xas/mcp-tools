<?php

namespace Abr4xas\McpTools\Commands;

use Abr4xas\McpTools\Analyzers\FormRequestAnalyzer;
use Abr4xas\McpTools\Analyzers\PhpDocAnalyzer;
use Abr4xas\McpTools\Analyzers\ResponseCodeAnalyzer;
use Abr4xas\McpTools\Analyzers\ResourceAnalyzer;
use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Abr4xas\McpTools\Services\AstCacheService;
use Abr4xas\McpTools\Exceptions\AnalysisException;
use Abr4xas\McpTools\Exceptions\FormRequestAnalysisException;
use Abr4xas\McpTools\Exceptions\ResourceAnalysisException;
use Abr4xas\McpTools\Exceptions\RouteAnalysisException;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class GenerateApiContractCommand extends Command
{
    protected $signature = 'api:contract:generate 
                            {--incremental : Only update routes that have been modified}
                            {--log : Enable detailed logging for debugging}
                            {--dry-run : Validate and show summary without writing file}
                            {--validate-schemas : Validate generated schemas against JSON Schema}';

    protected $description = 'Generate a public-facing API contract for MCP consumption';

    protected RouteAnalyzer $routeAnalyzer;

    protected FormRequestAnalyzer $formRequestAnalyzer;

    protected ResourceAnalyzer $resourceAnalyzer;

    protected PhpDocAnalyzer $phpDocAnalyzer;

    protected AstCacheService $astCache;

    public function __construct(
        RouteAnalyzer $routeAnalyzer,
        FormRequestAnalyzer $formRequestAnalyzer,
        ResourceAnalyzer $resourceAnalyzer,
        PhpDocAnalyzer $phpDocAnalyzer,
        AstCacheService $astCache
    ) {
        parent::__construct();
        $this->routeAnalyzer = $routeAnalyzer;
        $this->formRequestAnalyzer = $formRequestAnalyzer;
        $this->resourceAnalyzer = $resourceAnalyzer;
        $this->phpDocAnalyzer = $phpDocAnalyzer;
        $this->astCache = $astCache;
    }

    public function handle(): int
    {
        $incremental = $this->option('incremental');
        $enableLogging = $this->option('log');
        $dryRun = $this->option('dry-run');
        $validateSchemas = $this->option('validate-schemas');
        
        if ($dryRun) {
            $this->info('Running in dry-run mode (no file will be written)...');
        } elseif ($incremental) {
            $this->info('Generating API contract (incremental mode)...');
        } else {
            $this->info('Generating API contract...');
        }

        if ($enableLogging) {
            \Log::info('MCP Tools: Starting API contract generation', [
                'incremental' => $incremental,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // Optimization: Pre-scan resources to avoid File scanning inside the loop
        $this->resourceAnalyzer->preloadResources();

        // Load existing contract if incremental
        $existingContract = [];
        $contractPath = storage_path('api-contracts/api.json');
        if ($incremental && File::exists($contractPath)) {
            $existing = json_decode(File::get($contractPath), true);
            if (is_array($existing)) {
                $existingContract = $existing;
            }
        }

        $routes = Route::getRoutes()->getRoutes();
        $contract = $incremental ? $existingContract : [];
        $queryMethods = ['GET', 'HEAD', 'DELETE'];
        $errors = [];
        $modifiedFiles = $this->getModifiedFiles($incremental);

        // Filter API routes
        $apiRoutes = [];
        foreach ($routes as $route) {
            $uri = $route->uri();
            if (Str::startsWith($uri, 'api/')) {
                $apiRoutes[] = $route;
            }
        }

        // Create progress bar
        $totalRoutes = 0;
        foreach ($apiRoutes as $route) {
            $methods = $route->methods();
            foreach ($methods as $method) {
                if ($method !== 'HEAD') {
                    $totalRoutes++;
                }
            }
        }

        $progressBar = $this->output->createProgressBar($totalRoutes);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        foreach ($apiRoutes as $route) {
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

                if ($enableLogging) {
                    \Log::info("MCP Tools: Processing route {$method} {$normalizedUri}", [
                        'action' => $action,
                    ]);
                }

                // Skip if incremental and route hasn't changed
                if ($incremental && ! $this->shouldUpdateRoute($normalizedUri, $method, $action, $existingContract, $modifiedFiles)) {
                    $progressBar->setMessage("Skipped: {$method} {$normalizedUri}");
                    $progressBar->advance();
                    if ($enableLogging) {
                        \Log::debug("MCP Tools: Skipped route {$method} {$normalizedUri} (unchanged)");
                    }
                    continue;
                }

                $progressBar->setMessage("Processing: {$method} {$normalizedUri}");

                try {
                    $pathParams = $this->routeAnalyzer->extractPathParams($normalizedUri, $action);
                    $auth = $this->routeAnalyzer->determineAuth($route);
                    $customHeaders = $this->routeAnalyzer->extractCustomHeaders($route);
                    $rateLimit = $this->routeAnalyzer->extractRateLimit($route);
                    $apiVersion = $this->routeAnalyzer->extractApiVersion($normalizedUri);
                    $middleware = $this->routeAnalyzer->analyzeMiddleware($route);
                    $routeName = $route->getName();

                    $isQuery = in_array($method, $queryMethods, true);
                    $requestSchema = $this->extractRequestSchema($action, $isQuery);

                    $responseSchema = $this->extractResponseSchema($action, $normalizedUri);

                    // Extract PHPDoc description and deprecated status
                    $descriptionData = $this->extractDescription($action, $method);
                    $description = is_array($descriptionData) ? ($descriptionData['description'] ?? null) : $descriptionData;
                    $deprecated = is_array($descriptionData) ? ($descriptionData['deprecated'] ?? null) : null;

                    // Extract possible HTTP status codes
                    $statusCodes = [];
                    if (is_string($action) && str_contains($action, '@')) {
                        [$controller, $controllerMethod] = explode('@', $action);
                        try {
                            $reflection = $this->routeAnalyzer->getReflectionMethod($controller, $controllerMethod, "{$controller}::{$controllerMethod}");
                            $statusCodes = $this->responseCodeAnalyzer->analyze($reflection);
                        } catch (\Throwable) {
                            // Use default codes
                            $statusCodes = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
                        }
                    } else {
                        $statusCodes = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
                    }

                    // Extract response headers
                    $responseHeaders = $this->extractResponseHeaders($responseSchema, $routeData);

                    // Detect content negotiation
                    $contentNegotiation = $this->middlewareAnalyzer->detectContentNegotiation($route->gatherMiddleware());

                    $contract[$normalizedUri][$method] = [
                        'description' => $description,
                        'deprecated' => $deprecated,
                        'auth' => $auth,
                        'path_parameters' => $pathParams,
                        'request_schema' => $requestSchema,
                        'response_schema' => $responseSchema,
                        'response_headers' => $responseHeaders,
                        'custom_headers' => $customHeaders,
                        'rate_limit' => $rateLimit,
                        'api_version' => $apiVersion,
                        'middleware' => $middleware,
                        'route_name' => $routeName,
                        'status_codes' => $statusCodes,
                        'content_negotiation' => $contentNegotiation,
                    ];

                    $progressBar->setMessage("Done: {$method} {$normalizedUri}");
                    $progressBar->advance();
                    if ($enableLogging) {
                        \Log::info("MCP Tools: Successfully processed route {$method} {$normalizedUri}");
                    }
                } catch (AnalysisException $e) {
                    $errorInfo = $e->toArray();
                    $errors[] = [
                        'route' => "{$method} {$normalizedUri}",
                        'error_code' => $errorInfo['error_code'],
                        'message' => $errorInfo['message'],
                        'suggestion' => $errorInfo['suggestion'],
                    ];
                    $progressBar->setMessage("Error: {$errorInfo['error_code']} - {$normalizedUri}");
                    $progressBar->advance();
                    
                    if ($enableLogging) {
                        \Log::warning("MCP Tools: Error processing route {$method} {$normalizedUri}", [
                            'error_code' => $errorInfo['error_code'],
                            'message' => $errorInfo['message'],
                            'context' => $errorInfo['context'],
                        ]);
                    }

                    // Continue processing but mark route as having errors
                    $contract[$normalizedUri][$method] = [
                        'auth' => ['type' => 'none'],
                        'path_parameters' => [],
                        'request_schema' => ['location' => 'unknown', 'properties' => [], 'error' => $errorInfo],
                        'response_schema' => ['undocumented' => true, 'error' => $errorInfo],
                        'custom_headers' => [],
                        'rate_limit' => null,
                        'api_version' => null,
                    ];
                } catch (Throwable $e) {
                    $errors[] = [
                        'route' => "{$method} {$normalizedUri}",
                        'error_code' => 'UNEXPECTED_ERROR',
                        'message' => $e->getMessage(),
                        'suggestion' => 'Check the error message and review the route configuration.',
                    ];
                    $progressBar->setMessage("Unexpected Error: {$normalizedUri}");
                    $progressBar->advance();
                    
                    if ($enableLogging) {
                        \Log::error("MCP Tools: Unexpected error processing route {$method} {$normalizedUri}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }
        }

        $progressBar->setMessage('Completed');
        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $totalRoutes = 0;
        foreach ($contract as $methods) {
            $totalRoutes += count($methods);
        }

        // Validate schemas if requested
        $schemaErrors = [];
        if ($validateSchemas) {
            $this->info('Validating schemas...');
            foreach ($contract as $path => $methods) {
                foreach ($methods as $method => $routeData) {
                    if (isset($routeData['request_schema']['properties'])) {
                        $validation = $this->schemaValidator->validate($routeData['request_schema']['properties']);
                        if (! $validation['valid']) {
                            $schemaErrors["{$path}:{$method}:request"] = $validation['errors'];
                        }
                    }
                    if (isset($routeData['response_schema']) && ! isset($routeData['response_schema']['undocumented'])) {
                        $validation = $this->schemaValidator->validate($routeData['response_schema']);
                        if (! $validation['valid']) {
                            $schemaErrors["{$path}:{$method}:response"] = $validation['errors'];
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line("  Total routes: {$totalRoutes}");
        $this->line('  Errors: ' . count($errors));
        if ($validateSchemas) {
            $this->line('  Schema validation errors: ' . count($schemaErrors));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN MODE: No file was written.');
            $this->info('The contract would be generated with the above summary.');
            
            if (! empty($errors)) {
                $this->newLine();
                $this->warn('Errors that would be included:');
                foreach ($errors as $error) {
                    $this->line("  - {$error['route']}: [{$error['error_code']}] {$error['message']}");
                }
            }

            if ($validateSchemas && ! empty($schemaErrors)) {
                $this->newLine();
                $this->warn('Schema validation errors:');
                foreach ($schemaErrors as $key => $errors) {
                    $this->line("  - {$key}:");
                    foreach ($errors as $error) {
                        $this->line("    {$error['path']}: {$error['message']}");
                    }
                }
            }

            return (empty($errors) && empty($schemaErrors)) ? self::SUCCESS : self::FAILURE;
        }

        $directory = storage_path('api-contracts');
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $fullPath = "{$directory}/api.json";
        
        // Save version before overwriting
        if (File::exists($fullPath) && ! $incremental) {
            $versionsDir = "{$directory}/versions";
            if (! File::exists($versionsDir)) {
                File::makeDirectory($versionsDir, 0755, true);
            }
            $versionName = 'api-' . date('Y-m-d-His') . '.json';
            File::copy($fullPath, "{$versionsDir}/{$versionName}");
        }

        // Add metadata
        $metadata = [
            'generated_at' => now()->toIso8601String(),
            'git_commit' => $this->getGitCommit(),
            'version' => '1.0.0',
        ];
        $contract['_metadata'] = $metadata;

        $json = json_encode($contract, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('Failed to encode contract to JSON');
            $this->error('Error: ' . json_last_error_msg());

            return self::FAILURE;
        }

        File::put($fullPath, $json);

        $this->info("Contract generated at: {$fullPath}");

        if (! empty($errors)) {
            $this->newLine();
            $this->warn('Some routes had errors during analysis:');
            foreach ($errors as $error) {
                $this->line("  - {$error['route']}: [{$error['error_code']}] {$error['message']}");
            }
            $this->newLine();
        }

        if ($enableLogging) {
            \Log::info('MCP Tools: API contract generation completed', [
                'total_routes' => count($contract),
                'errors' => count($errors),
                'duration' => microtime(true) - LARAVEL_START,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Extract request schema from route action
     *
     * @param  mixed  $action
     * @param  bool  $isQuery
     * @return array{location: string, properties: array}|array<string, mixed>
     */
    private function extractRequestSchema($action, bool $isQuery): array
    {
        if (! is_string($action) || ! Str::contains($action, '@')) {
            return [];
        }

        try {
            $this->routeAnalyzer->validateRouteAction($action);
        } catch (RouteAnalysisException $e) {
            throw $e;
        }

        [$controller, $controllerMethod] = explode('@', $action);
        $cacheKey = "{$controller}::{$controllerMethod}";

        try {
            $reflection = $this->routeAnalyzer->getReflectionMethod($controller, $controllerMethod, $cacheKey);
            foreach ($reflection->getParameters() as $param) {
                if ($this->formRequestAnalyzer->isFormRequestParameter($param)) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType) {
                        $formRequestClass = $type->getName();
                        try {
                            return $this->formRequestAnalyzer->extractSchema($formRequestClass, $isQuery);
                        } catch (FormRequestAnalysisException $e) {
                            throw $e;
                        }
                    }
                }
            }

            // If no FormRequest found, check if method accepts Request parameter for query params
            if ($isQuery) {
                $hasRequestParam = false;
                foreach ($reflection->getParameters() as $param) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === Request::class || $typeName === 'Request') {
                            $hasRequestParam = true;
                            break;
                        }
                    }
                }

                if ($hasRequestParam) {
                    // Try to extract query params from method
                    $queryParams = $this->formRequestAnalyzer->extractQueryParamsFromMethod($reflection);
                    if (! empty($queryParams)) {
                        return ['location' => 'query', 'properties' => $queryParams];
                    }

                    // Method accepts Request, might have query params but we can't infer them
                    return ['location' => 'query', 'properties' => []];
                }
            }
        } catch (RouteAnalysisException $e) {
            throw $e;
        } catch (FormRequestAnalysisException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw RouteAnalysisException::reflectionFailed($controller, $controllerMethod, $e->getMessage(), $e);
        }

        return [];
    }

    /**
     * Extract response schema from route action
     *
     * @param  mixed  $action
     * @param  string  $uri
     * @return array<string, mixed>
     */
    private function extractResponseSchema($action, string $uri): array
    {
        if (is_string($action) && Str::contains($action, '@')) {
            [$controller, $controllerMethod] = explode('@', $action);
            $cacheKey = "{$controller}::{$controllerMethod}";

            try {
                $reflection = $this->routeAnalyzer->getReflectionMethod($controller, $controllerMethod, $cacheKey);
                $returnType = $reflection->getReturnType();

                if ($returnType instanceof ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    // Direct Resource
                    if (Str::contains($typeName, 'Resource')) {
                        try {
                            $simulated = $this->resourceAnalyzer->simulateResourceOutput($typeName);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        } catch (ResourceAnalysisException $e) {
                            // Continue to try other methods
                        }
                    }
                    // JsonResponse - analyze method body
                    if ($typeName === JsonResponse::class || $typeName === 'JsonResponse') {
                        $resourceFromCode = $this->extractResourceFromMethodBody($reflection);
                        if ($resourceFromCode) {
                            try {
                                $simulated = $this->resourceAnalyzer->simulateResourceOutput($resourceFromCode);
                                if (! isset($simulated['undocumented'])) {
                                    return $simulated;
                                }
                            } catch (ResourceAnalysisException $e) {
                                // Continue to try other methods
                            }
                        }
                    }
                    // Response Wrapper (e.g. PostsIndexResponse)
                    if (class_exists($typeName) && ! Str::startsWith($typeName, 'Illuminate\\')) {
                        // Inspect the class for Resource usage
                        $detectedResource = $this->inspectResponseClass($typeName);
                        if ($detectedResource) {
                            try {
                                $simulated = $this->resourceAnalyzer->simulateResourceOutput($detectedResource);
                                if (! isset($simulated['undocumented'])) {
                                    return $simulated;
                                }
                            } catch (ResourceAnalysisException $e) {
                                // Continue to try other methods
                            }
                        }
                    }
                } else {
                    // No return type, analyze method body
                    $resourceFromCode = $this->extractResourceFromMethodBody($reflection);
                    if ($resourceFromCode) {
                        try {
                            $simulated = $this->resourceAnalyzer->simulateResourceOutput($resourceFromCode);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        } catch (ResourceAnalysisException $e) {
                            // Continue to try other methods
                        }
                    }
                }
            } catch (RouteAnalysisException $e) {
                // If we can't reflect, return undocumented
            } catch (Throwable $e) {
                // If we can't extract, return undocumented
            }
        }

        // Improved static analysis strategy
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

        // Build comprehensive candidate list using static analysis
        $candidates = $this->buildResourceCandidates($resourceName, $uri);

        // Use pre-loaded matching with improved search
        $availableResources = $this->resourceAnalyzer->getAvailableResources();
        foreach ($availableResources as $name => $fullClass) {
            // More intelligent matching
            $nameLower = mb_strtolower($name);
            $resourceLower = mb_strtolower($resourceName);
            
            if ((Str::contains($nameLower, $resourceLower) || Str::contains($resourceLower, $nameLower)) 
                && (Str::endsWith($name, 'Resource') || Str::endsWith($name, 'Collection'))) {
                $candidates[] = $fullClass;
            }
        }

        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                try {
                    $simulated = $this->resourceAnalyzer->simulateResourceOutput($class);
                    if (! isset($simulated['undocumented'])) {
                        return $simulated;
                    }
                } catch (ResourceAnalysisException $e) {
                    // Continue to try other candidates
                }
            }
        }

        return ['undocumented' => true];
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

            // Check AST cache first
            if ($this->astCache->has($fileName)) {
                $cached = $this->astCache->get($fileName);
                if (isset($cached['resources'][$reflection->getName()])) {
                    return $cached['resources'][$reflection->getName()];
                }
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
                    // Cache result
                    $this->cacheAstResult($fileName, $reflection->getName(), $fullClass);
                    return $fullClass;
                }
            }

            // Cache negative result
            $this->cacheAstResult($fileName, $reflection->getName(), null);
        } catch (Throwable $e) {
            $this->warn("Could not extract resource from method body: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Cache AST analysis result
     */
    protected function cacheAstResult(string $fileName, string $methodName, ?string $resourceClass): void
    {
        try {
            $cached = $this->astCache->get($fileName) ?? ['resources' => []];
            $cached['resources'][$methodName] = $resourceClass;
            $this->astCache->put($fileName, $cached);
        } catch (Throwable) {
            // Ignore cache errors
        }
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
     * Extract description from PHPDoc of controller method
     *
     * @param  mixed  $action
     * @param  string  $method
     * @return string|null
     */
    private function extractDescription($action, string $method): ?string
    {
        if (! is_string($action) || ! Str::contains($action, '@')) {
            return null;
        }

        try {
            [$controller, $controllerMethod] = explode('@', $action);
            $this->routeAnalyzer->validateRouteAction($action);
            $reflection = $this->routeAnalyzer->getReflectionMethod($controller, $controllerMethod, "{$controller}::{$controllerMethod}");

            $phpDoc = $this->phpDocAnalyzer->extractFromMethod($reflection);

            // Check for PHP 8 #[Deprecated] attribute
            $deprecated = $phpDoc['deprecated'];
            $attributes = $reflection->getAttributes();
            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();
                if (str_contains($attributeName, 'Deprecated')) {
                    $deprecated = [
                        'deprecated' => true,
                        'message' => 'This route is deprecated',
                    ];
                    $args = $attribute->getArguments();
                    if (isset($args[0])) {
                        $deprecated['message'] = $args[0];
                    }
                    break;
                }
            }

            return [
                'description' => $phpDoc['description'],
                'deprecated' => $deprecated,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build comprehensive list of resource candidates using static analysis
     *
     * @return array<int, string>
     */
    protected function buildResourceCandidates(string $resourceName, string $uri): array
    {
        $candidates = [
            "App\\Http\\Resources\\{$resourceName}Resource",
            "App\\Http\\Resources\\{$resourceName}OverviewResource",
            "App\\Http\\Resources\\{$resourceName}Collection",
            'App\\Http\\Resources\\' . ucfirst($resourceName) . 'Resource',
        ];

        // Extract namespace hints from URI path
        $urlParts = explode('/', mb_trim($uri, '/'));
        if (count($urlParts) > 2) {
            // e.g., /api/v1/posts/cards -> try Posts\Cards namespace
            $namespaceParts = array_slice($urlParts, 2, -1); // Skip api, version, and last part
            if (! empty($namespaceParts)) {
                $namespace = 'App\\Http\\Resources\\' . implode('\\', array_map('ucfirst', $namespaceParts));
                $candidates[] = "{$namespace}\\{$resourceName}Resource";
                $candidates[] = "{$namespace}\\{$resourceName}Collection";
            }
        }

        // Common folder patterns
        $commonFolders = ['Posts', 'Users', 'Companies', 'Cards', 'Invest'];
        foreach ($commonFolders as $folder) {
            $candidates[] = "App\\Http\\Resources\\{$folder}\\{$resourceName}Resource";
            $candidates[] = "App\\Http\\Resources\\{$folder}\\{$resourceName}Collection";
        }

        return array_unique($candidates);
    }

}
