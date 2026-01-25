<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

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

    protected array $availableResources = [];

    public function __construct(AnalysisCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Preload all available resources
     */
    public function preloadResources(): void
    {
        $basePath = app_path('Http/Resources');
        if (File::exists($basePath)) {
            $files = File::allFiles($basePath);
            foreach ($files as $file) {
                $this->availableResources[$file->getFilenameWithoutExtension()] = $this->getClassNameFromFile($file);
            }
        }
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
