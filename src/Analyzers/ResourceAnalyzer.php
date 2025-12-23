<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Resource Analyzer
 *
 * Analyzes Laravel Resource classes to extract response schemas.
 * Handles Resource discovery, simulation, and schema generation.
 *
 * @package Abr4xas\McpTools\Analyzers
 */
class ResourceAnalyzer
{
    /** @var array<string, string> Map of resource names to full class names */
    protected array $availableResources = [];

    /** @var array<string, array<string, mixed>> Cache of resource schemas */
    protected array $resourceSchemaCache = [];

    protected string $resourcesNamespace;

    protected string $modelsNamespace;

    protected string $resourcesPath;

    /**
     * Create a new ResourceAnalyzer instance.
     */
    public function __construct()
    {
        $this->resourcesNamespace = Config::get('mcp-tools.resources_namespace', 'App\\Http\\Resources');
        $this->modelsNamespace = Config::get('mcp-tools.models_namespace', 'App\\Models');
        $this->resourcesPath = Config::get('mcp-tools.resources_path', app_path('Http/Resources'));
    }

    /**
     * Preload available resources from file system.
     */
    public function preloadResources(): void
    {
        if (File::exists($this->resourcesPath)) {
            $files = File::allFiles($this->resourcesPath);
            foreach ($files as $file) {
                $this->availableResources[$file->getFilenameWithoutExtension()] = $this->getClassNameFromFile($file);
            }
        }
    }

    /**
     * Extract response schema from Resource classes or method return types.
     *
     * @param string|object|null $action The route action
     * @param string $uri The route URI
     * @param ReflectionMethod|null $reflection Optional reflection method
     * @param callable(string, string): void $onError Error handler callback
     * @return array<string, mixed> Response schema
     */
    public function extractResponseSchema($action, string $uri, ?ReflectionMethod $reflection, callable $onError): array
    {
        if (is_string($action) && Str::contains($action, '@')) {
            [$controller, $controllerMethod] = explode('@', $action);
            $cacheKey = "{$controller}::{$controllerMethod}";

            if ($reflection === null) {
                try {
                    $reflection = new ReflectionMethod($controller, $controllerMethod);
                } catch (Throwable $e) {
                    $onError("Could not reflect method {$cacheKey}", "Method {$cacheKey} may not exist or may not be accessible.");
                    return $this->fallbackToHeuristic($uri);
                }
            }

            try {
                $returnType = $reflection->getReturnType();

                if ($returnType instanceof ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    // Direct Resource
                    if (Str::contains($typeName, 'Resource')) {
                        $simulated = $this->simulateResourceOutput($typeName, $onError);
                        if (! isset($simulated['undocumented'])) {
                            return $simulated;
                        }
                    }
                    // JsonResponse - analyze method body
                    if ($typeName === JsonResponse::class || $typeName === 'JsonResponse') {
                        $resourceFromCode = $this->extractResourceFromMethodBody($reflection, $onError);
                        if ($resourceFromCode) {
                            $simulated = $this->simulateResourceOutput($resourceFromCode, $onError);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        }
                    }
                    // Response Wrapper (e.g. PostsIndexResponse)
                    if (class_exists($typeName) && ! Str::startsWith($typeName, 'Illuminate\\')) {
                        // Inspect the class for Resource usage
                        $detectedResource = $this->inspectResponseClass($typeName, $onError);
                        if ($detectedResource) {
                            $simulated = $this->simulateResourceOutput($detectedResource, $onError);
                            if (! isset($simulated['undocumented'])) {
                                return $simulated;
                            }
                        }
                    }
                } else {
                    // No return type, analyze method body
                    $resourceFromCode = $this->extractResourceFromMethodBody($reflection, $onError);
                    if ($resourceFromCode) {
                        $simulated = $this->simulateResourceOutput($resourceFromCode, $onError);
                        if (! isset($simulated['undocumented'])) {
                            return $simulated;
                        }
                    }
                }
            } catch (Throwable $e) {
                $onError("Could not extract response schema for {$cacheKey}", "Unable to analyze response schema for {$cacheKey}. The method may have complex return types or dependencies.");
            }
        }

        return $this->fallbackToHeuristic($uri);
    }

    /**
     * Fallback to heuristic strategy when direct analysis fails.
     *
     * @param string $uri The route URI
     * @return array<string, mixed> Response schema
     */
    protected function fallbackToHeuristic(string $uri): array
    {
        $urlParts = explode('/', mb_trim($uri, '/'));
        $resourceName = end($urlParts);
        if (Str::startsWith($resourceName, '{')) {
            $resourceName = prev($urlParts);
        }

        if (! $resourceName) {
            return ['undocumented' => true];
        }

        $resourceName = Str::singular($resourceName);
        $resourceName = ucfirst($resourceName);

        $candidates = [
            "{$this->resourcesNamespace}\\{$resourceName}Resource",
            "{$this->resourcesNamespace}\\{$resourceName}OverviewResource",
            "{$this->resourcesNamespace}\\{$resourceName}Collection",
            "{$this->resourcesNamespace}\\" . ucfirst($resourceName) . 'Resource',
            "{$this->resourcesNamespace}\\Posts\\" . ucfirst($resourceName) . 'Resource',
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
                $simulated = $this->simulateResourceOutput($class, function () {});
                if (! isset($simulated['undocumented'])) {
                    return $simulated;
                }
            }
        }

        return ['undocumented' => true];
    }

    /**
     * Simulate resource output to generate schema.
     *
     * @param string $resourceClass The resource class name
     * @param callable(string, string): void $onError Error handler callback
     * @return array<string, mixed> Resource schema
     */
    public function simulateResourceOutput(string $resourceClass, callable $onError): array
    {
        // Cache schema results to avoid recreating models
        if (isset($this->resourceSchemaCache[$resourceClass])) {
            return $this->resourceSchemaCache[$resourceClass];
        }

        $basics = class_basename($resourceClass);
        $modelName = str_replace(['Resource', 'Overview', 'Collection'], '', $basics);
        $modelClass = "{$this->modelsNamespace}\\{$modelName}";

        if (! class_exists($modelClass)) {
            $result = ['undocumented' => true];
            $this->resourceSchemaCache[$resourceClass] = $result;

            return $result;
        }

        if (method_exists($modelClass, 'factory')) {
            try {
                $model = $modelClass::factory()->make();

                // Ensure critical date fields are set
                $dateFields = ['publish_date', 'published_at', 'created_at', 'updated_at', 'deleted_at'];
                foreach ($dateFields as $dateField) {
                    if (isset($model->$dateField) && ! $model->$dateField) {
                        $model->$dateField = now();
                    }
                }

                // If it's a collection resource
                if (Str::endsWith($basics, 'Collection') || is_subclass_of($resourceClass, ResourceCollection::class)) {
                    $items = collect([$model]);
                    $paginator = new LengthAwarePaginator($items, 1, 15);
                    $resource = new $resourceClass($paginator);
                    $resp = $resource->toResponse(request())->getData(true);

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
                $onError("Factory failed for {$resourceClass}", "Model factory for {$resourceClass} failed. Ensure the model has a factory defined and all required dependencies are available.");
                $result = ['undocumented' => true, 'error' => 'Factory failed'];
                $this->resourceSchemaCache[$resourceClass] = $result;

                return $result;
            }
        }

        $result = ['undocumented' => true, 'hint' => $basics];
        $this->resourceSchemaCache[$resourceClass] = $result;

        return $result;
    }

    /**
     * Convert data array to schema format.
     *
     * @param array<string, mixed> $data The data to convert
     * @return array<string, mixed> Schema representation
     */
    protected function dataToSchema(array $data): array
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
     * Extract Resource class name from method body by analyzing source code.
     *
     * @param ReflectionMethod $reflection The method reflection
     * @param callable(string, string): void $onError Error handler callback
     * @return string|null Resource class name or null
     */
    protected function extractResourceFromMethodBody(ReflectionMethod $reflection, callable $onError): ?string
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

            // Pattern 1: Resource::make() or Resource::collection()
            if (preg_match('/(\w+Resource)::(make|collection)\(/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
                // Try common namespaces
                $commonPaths = [
                    "{$this->resourcesNamespace}\\{$resourceName}",
                    "{$this->resourcesNamespace}\\Cards\\{$resourceName}",
                    "{$this->resourcesNamespace}\\Users\\{$resourceName}",
                    "{$this->resourcesNamespace}\\Companies\\{$resourceName}",
                    "{$this->resourcesNamespace}\\ServiceCompanies\\{$resourceName}",
                    "{$this->resourcesNamespace}\\Invest\\{$resourceName}",
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

            // Pattern 3: ApiResponse::data()
            if (preg_match('/ApiResponse::data\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 4: toResourceCollection
            if (preg_match('/->toResourceCollection\((\w+Resource)::class\)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 5: response()->json()
            if (preg_match('/response\(\)->json\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }

            // Pattern 6: Response::json()
            if (preg_match('/Response::json\([^)]*(\w+Resource)::(make|collection)/', $methodBody, $matches)) {
                $resourceName = $matches[1];
                $fullClass = $this->findFullClassName($reflection, $resourceName);
                if ($fullClass && class_exists($fullClass)) {
                    return $fullClass;
                }
            }
        } catch (Throwable $e) {
            $onError("Could not extract resource from method body", "Unable to analyze method body for resource extraction. The code may use patterns not recognized by the analyzer.");
        }

        return null;
    }

    /**
     * Find full class name from use statements in the file.
     *
     * @param ReflectionMethod $reflection The method reflection
     * @param string $shortName The short class name
     * @return string|null Full class name or null
     */
    protected function findFullClassName(ReflectionMethod $reflection, string $shortName): ?string
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

            // Look for use statements
            $resourcesNamespace = preg_quote($this->resourcesNamespace, '/');
            if (preg_match('/use\s+([^;]+' . preg_quote($shortName, '/') . ')\s*;/', $content, $matches)) {
                return mb_trim($matches[1]);
            }

            // Look for use statements with as alias
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
     * Inspect response class for Resource usage.
     *
     * @param string $responseClass The response class name
     * @param callable(string, string): void $onError Error handler callback
     * @return string|null Resource class name or null
     */
    protected function inspectResponseClass(string $responseClass, callable $onError): ?string
    {
        static $cache = [];

        if (isset($cache[$responseClass])) {
            return $cache[$responseClass];
        }

        try {
            $ref = new ReflectionClass($responseClass);
            $content = file_get_contents($ref->getFileName());

            if ($content === false) {
                $cache[$responseClass] = null;

                return null;
            }

            // Look for use statements for Resources
            $resourcesNamespace = preg_quote($this->resourcesNamespace, '/');
            preg_match_all("/use ({$resourcesNamespace}\\\\.*);/", $content, $matches);
            if ($matches[1] !== []) {
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
            $onError("Could not inspect response class {$responseClass}", "Unable to inspect response class {$responseClass}. The class file may be inaccessible or corrupted.");
        }

        $cache[$responseClass] = null;

        return null;
    }

    /**
     * Get class name from file path.
     *
     * @param \Illuminate\Filesystem\Filesystem|\SplFileInfo $fileItem The file item
     * @return string Full class name
     */
    protected function getClassNameFromFile($fileItem): string
    {
        $path = $fileItem->getPath();
        $base = $this->resourcesPath;
        $relative = mb_trim(str_replace($base, '', $path), DIRECTORY_SEPARATOR);
        $namespace = $this->resourcesNamespace;
        if ($relative !== '' && $relative !== '0') {
            $namespace .= '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        return $namespace . '\\' . $fileItem->getFilenameWithoutExtension();
    }
}

