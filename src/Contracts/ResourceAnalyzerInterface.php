<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Contracts;

use ReflectionMethod;

/**
 * Resource Analyzer Interface
 *
 * @package Abr4xas\McpTools\Contracts
 */
interface ResourceAnalyzerInterface
{
    /**
     * Preload available resources from file system.
     */
    public function preloadResources(): void;

    /**
     * Extract response schema from Resource classes or method return types.
     *
     * @param string|object|null $action The route action
     * @param string $uri The route URI
     * @param ReflectionMethod|null $reflection Optional reflection method
     * @param callable(string, string): void $onError Error handler callback
     * @return array<string, mixed> Response schema
     */
    public function extractResponseSchema($action, string $uri, ?ReflectionMethod $reflection, callable $onError): array;
}
