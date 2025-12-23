<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Contracts;

/**
 * Route Analyzer Interface
 *
 * @package Abr4xas\McpTools\Contracts
 */
interface RouteAnalyzerInterface
{
    /**
     * Extract path parameters from route URI.
     *
     * @param string $uri The route URI
     * @return array<int, string> Array of parameter names
     */
    public function extractPathParams(string $uri): array;

    /**
     * Determine authentication type from route middleware.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array{type: string} Auth configuration
     */
    public function determineAuth($route): array;

    /**
     * Extract rate limit information from route.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array{name: string, description: string}|null Rate limit info or null
     */
    public function extractRateLimit($route): ?array;

    /**
     * Extract API version from route path.
     *
     * @param string $uri The route URI
     * @return string|null API version (v1, v2, etc.) or null
     */
    public function extractApiVersion(string $uri): ?string;

    /**
     * Extract custom headers required for the route based on middleware.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array<int, array{name: string, required: bool, description: string}> Custom headers
     */
    public function extractCustomHeaders($route): array;
}
