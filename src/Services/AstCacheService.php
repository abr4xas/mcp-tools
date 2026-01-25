<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AstCacheService
{
    protected const CACHE_PREFIX = 'mcp_tools_ast:';

    protected const CACHE_TTL = 7200; // 2 hours

    /**
     * Get cached AST for a file
     *
     * @return mixed|null
     */
    public function get(string $filePath)
    {
        if (! File::exists($filePath)) {
            return null;
        }

        $mtime = File::lastModified($filePath);
        $cacheKey = $this->getCacheKey($filePath, $mtime);

        return Cache::get($cacheKey);
    }

    /**
     * Store AST in cache
     */
    public function put(string $filePath, $ast): void
    {
        if (! File::exists($filePath)) {
            return;
        }

        $mtime = File::lastModified($filePath);
        $cacheKey = $this->getCacheKey($filePath, $mtime);

        Cache::put($cacheKey, $ast, self::CACHE_TTL);
    }

    /**
     * Check if AST is cached and valid
     */
    public function has(string $filePath): bool
    {
        if (! File::exists($filePath)) {
            return false;
        }

        $mtime = File::lastModified($filePath);
        $cacheKey = $this->getCacheKey($filePath, $mtime);

        return Cache::has($cacheKey);
    }

    /**
     * Get cache key for file
     */
    protected function getCacheKey(string $filePath, int $mtime): string
    {
        return self::CACHE_PREFIX . md5($filePath . ':' . $mtime);
    }
}
