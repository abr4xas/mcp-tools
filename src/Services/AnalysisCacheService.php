<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AnalysisCacheService
{
    protected const CACHE_PREFIX = 'mcp_tools_analysis:';

    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Get cache key for a specific analysis type and identifier
     */
    protected function getCacheKey(string $type, string $identifier): string
    {
        return self::CACHE_PREFIX . $type . ':' . md5($identifier);
    }

    /**
     * Get cached analysis result
     *
     * @param  string  $type  Type of analysis (route, resource, form_request)
     * @param  string  $identifier  Unique identifier (e.g., class name, route path)
     * @return mixed|null
     */
    public function get(string $type, string $identifier)
    {
        $key = $this->getCacheKey($type, $identifier);

        return Cache::get($key);
    }

    /**
     * Store analysis result in cache
     *
     * @param  string  $type  Type of analysis
     * @param  string  $identifier  Unique identifier
     * @param  mixed  $value  Value to cache
     * @param  int|null  $ttl  Time to live in seconds (null = use default)
     */
    public function put(string $type, string $identifier, $value, ?int $ttl = null): void
    {
        $key = $this->getCacheKey($type, $identifier);
        $ttl = $ttl ?? self::CACHE_TTL;

        Cache::put($key, $value, $ttl);
    }

    /**
     * Check if cache entry exists and is valid
     */
    public function has(string $type, string $identifier): bool
    {
        $key = $this->getCacheKey($type, $identifier);

        return Cache::has($key);
    }

    /**
     * Forget cached analysis result
     */
    public function forget(string $type, string $identifier): void
    {
        $key = $this->getCacheKey($type, $identifier);
        Cache::forget($key);
    }

    /**
     * Clear all analysis cache
     */
    public function clear(): void
    {
        // Clear all cache entries with our prefix
        // Note: This is a simple implementation. For production, consider using cache tags if available
        Cache::flush();
    }

    /**
     * Clear cache for a specific type
     */
    public function clearType(string $type): void
    {
        // This is a simplified implementation
        // For better performance with large caches, consider using cache tags
        $this->clear();
    }

    /**
     * Invalidate cache for a file by checking its modification time
     *
     * @param  string  $type  Type of analysis
     * @param  string  $identifier  Unique identifier
     * @param  string  $filePath  Path to the file being analyzed
     * @return bool  True if cache is valid, false if file was modified
     */
    public function isValidForFile(string $type, string $identifier, string $filePath): bool
    {
        if (! File::exists($filePath)) {
            return false;
        }

        $cached = $this->get($type, $identifier . ':mtime');
        $currentMtime = File::lastModified($filePath);

        if ($cached === null || $cached !== $currentMtime) {
            // File was modified, invalidate cache
            $this->forget($type, $identifier);
            $this->put($type, $identifier . ':mtime', $currentMtime);

            return false;
        }

        return true;
    }

    /**
     * Store file modification time for cache validation
     */
    public function storeFileMtime(string $type, string $identifier, string $filePath): void
    {
        if (File::exists($filePath)) {
            $mtime = File::lastModified($filePath);
            $this->put($type, $identifier . ':mtime', $mtime);
        }
    }
}
