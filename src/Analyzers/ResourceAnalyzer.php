<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Analyzers\ExampleGenerator;
use Abr4xas\McpTools\Exceptions\ResourceAnalysisException;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

class ResourceAnalyzer
{
    protected AnalysisCacheService $cacheService;

    protected ExampleGenerator $exampleGenerator;

    protected array $availableResources = [];

    public function __construct(AnalysisCacheService $cacheService, ExampleGenerator $exampleGenerator)
    {
        $this->cacheService = $cacheService;
        $this->exampleGenerator = $exampleGenerator;
    }

    /**
     * Preload all available resources with metadata caching
     */
    public function preloadResources(): void
    {
        $cacheKey = 'resources_metadata';
        
        // Check cache first
        if ($this->cacheService->has('resource', $cacheKey)) {
            $cached = $this->cacheService->get('resource', $cacheKey);
            if (is_array($cached)) {
                $this->availableResources = $cached;
                return;
            }
        }

        $basePath = app_path('Http/Resources');
        if (File::exists($basePath)) {
            $files = File::allFiles($basePath);
            $metadata = [];
            
            foreach ($files as $file) {
                $className = $this->getClassNameFromFile($file);
                $metadata[$file->getFilenameWithoutExtension()] = [
                    'class' => $className,
                    'namespace' => $this->extractNamespace($className),
                    'path' => $file->getPath(),
                    'name' => $file->getFilenameWithoutExtension(),
                ];
                $this->availableResources[$file->getFilenameWithoutExtension()] = $className;
            }
            
            // Cache metadata
            $this->cacheService->put('resource', $cacheKey, $this->availableResources);
        }
    }

    /**
     * Extract namespace from full class name
     */
    protected function extractNamespace(string $className): string
    {
        $parts = explode('\\', $className);
        array_pop($parts); // Remove class name
        return implode('\\', $parts);
    }

    /**
     * Simulate resource output to generate schema
     *
     * @throws ResourceAnalysisException
     */
    public function simulateResourceOutput(string $resourceClass): array
    {
        $cacheKey = $resourceClass;

        // Check cache with file modification time validation
        try {
            $reflection = new ReflectionClass($resourceClass);
            $filePath = $reflection->getFileName();
            if ($filePath && $this->cacheService->isValidForFile('resource', $cacheKey, $filePath)) {
                $cached = $this->cacheService->get('resource', $cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
        } catch (Throwable) {
            // If reflection fails, check cache without file validation
            if ($this->cacheService->has('resource', $cacheKey)) {
                $cached = $this->cacheService->get('resource', $cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        if (! class_exists($resourceClass)) {
            $result = ['undocumented' => true];
            $this->cacheService->put('resource', $cacheKey, $result);

            return $result;
        }

        $basics = class_basename($resourceClass);
        // Identify model from name
        // PostCollection -> Post
        // PostResource -> Post
        $modelName = str_replace(['Resource', 'Overview', 'Collection'], '', $basics);
        $modelClass = "App\\Models\\{$modelName}";

        if (! class_exists($modelClass)) {
            throw ResourceAnalysisException::modelNotFound($modelClass, $resourceClass);
        }

        if (! method_exists($modelClass, 'factory')) {
            throw ResourceAnalysisException::factoryNotAvailable($modelClass, $resourceClass);
        }

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

                try {
                    $resource = new $resourceClass($paginator);
                } catch (Throwable $e) {
                    throw ResourceAnalysisException::resourceInstantiationFailed($resourceClass, $e->getMessage(), $e);
                }

                // toResponse(request())->getData(true) is safer for Collections usually
                try {
                    $resp = $resource->toResponse(request())->getData(true); // returns array with meta/links usually
                } catch (Throwable $e) {
                    throw ResourceAnalysisException::resourceResolutionFailed($resourceClass, $e->getMessage(), $e);
                }

                // We just want 'data'
                $result = $this->dataToSchema($resp);

                // Generate examples from schema
                if (! empty($result) && ! isset($result['undocumented'])) {
                    $result['example'] = $this->exampleGenerator->generateFromSchema($result);
                }

                $this->storeInCache($resourceClass, $cacheKey, $result);

                return $result;
            }

            // Single Resource
            try {
                $resource = new $resourceClass($model);
            } catch (Throwable $e) {
                throw ResourceAnalysisException::resourceInstantiationFailed($resourceClass, $e->getMessage(), $e);
            }

            try {
                $data = $resource->resolve(request());
            } catch (Throwable $e) {
                throw ResourceAnalysisException::resourceResolutionFailed($resourceClass, $e->getMessage(), $e);
            }

            $result = $this->dataToSchema($data);

            // Generate examples from schema
            if (! empty($result) && ! isset($result['undocumented'])) {
                $result['example'] = $this->exampleGenerator->generateFromSchema($result);
            }

            $this->storeInCache($resourceClass, $cacheKey, $result);

            return $result;
        } catch (ResourceAnalysisException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResourceAnalysisException::factoryFailed($resourceClass, $modelClass, $e->getMessage(), $e);
        }
    }

    /**
     * Convert data array to schema format
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function dataToSchema(array $data): array
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
     * Get available resources
     *
     * @return array<string, string>
     */
    public function getAvailableResources(): array
    {
        return $this->availableResources;
    }

    /**
     * Get class name from file
     */
    protected function getClassNameFromFile($fileItem): string
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
     * Detect relationships in resource data
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{type: string, resource: string|null}>
     */
    protected function detectRelationships(string $resourceClass, array $data): array
    {
        $relationships = [];

        // Analyze resource class for relationship methods
        try {
            $reflection = new ReflectionClass($resourceClass);
            $content = file_get_contents($reflection->getFileName());
            if ($content !== false) {
                // Look for whenLoaded, when, etc. patterns
                if (preg_match_all('/whenLoaded\([\'"](\w+)[\'"]/', $content, $matches)) {
                    foreach ($matches[1] as $relation) {
                        $relationships[$relation] = [
                            'type' => 'loaded',
                            'resource' => $this->inferResourceFromRelation($relation),
                        ];
                    }
                }

                // Look for Resource::make or Resource::collection in whenLoaded
                if (preg_match_all('/whenLoaded\([\'"](\w+)[\'"].*?(\w+Resource)::(make|collection)/', $content, $matches)) {
                    foreach ($matches[1] as $index => $relation) {
                        $resourceName = $matches[2][$index] ?? null;
                        if ($resourceName) {
                            $relationships[$relation] = [
                                'type' => 'nested_resource',
                                'resource' => $this->findFullResourceClass($resourceClass, $resourceName),
                            ];
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Ignore errors
        }

        // Also check data for nested resource structures
        foreach ($data as $key => $value) {
            if (is_array($value) && Arr::isAssoc($value)) {
                // Check if it looks like a resource structure (has id, type, etc.)
                if (isset($value['id']) && isset($value['type'])) {
                    $relationships[$key] = [
                        'type' => 'has_one',
                        'resource' => $this->inferResourceFromKey($key),
                    ];
                }
            } elseif (is_array($value) && ! Arr::isAssoc($value) && ! empty($value) && is_array($value[0])) {
                // Array of objects - likely has_many
                if (isset($value[0]['id'])) {
                    $relationships[$key] = [
                        'type' => 'has_many',
                        'resource' => $this->inferResourceFromKey($key),
                    ];
                }
            }
        }

        return $relationships;
    }

    /**
     * Infer resource class name from relation name
     */
    protected function inferResourceFromRelation(string $relation): ?string
    {
        $resourceName = ucfirst(Str::singular($relation)) . 'Resource';
        $candidates = [
            "App\\Http\\Resources\\{$resourceName}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Infer resource class name from data key
     */
    protected function inferResourceFromKey(string $key): ?string
    {
        $resourceName = ucfirst(Str::singular($key)) . 'Resource';
        $candidates = [
            "App\\Http\\Resources\\{$resourceName}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Find full resource class name from short name
     */
    protected function findFullResourceClass(string $contextClass, string $shortName): ?string
    {
        // Try common namespaces
        $candidates = [
            "App\\Http\\Resources\\{$shortName}",
        ];

        // Try to find in use statements of context class
        try {
            $reflection = new ReflectionClass($contextClass);
            $content = file_get_contents($reflection->getFileName());
            if ($content !== false && preg_match('/use\s+([^;]+' . preg_quote($shortName, '/') . ')\s*;/', $content, $matches)) {
                $candidates[] = trim($matches[1]);
            }
        } catch (Throwable) {
            // Ignore
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Store result in cache with file modification time
     */
    protected function storeInCache(string $resourceClass, string $cacheKey, array $result): void
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $filePath = $reflection->getFileName();
            if ($filePath) {
                $this->cacheService->put('resource', $cacheKey, $result);
                $this->cacheService->storeFileMtime('resource', $cacheKey, $filePath);
            } else {
                $this->cacheService->put('resource', $cacheKey, $result);
            }
        } catch (Throwable) {
            // If reflection fails, just cache without file validation
            $this->cacheService->put('resource', $cacheKey, $result);
        }
    }
}
