<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Contracts\RouteAnalyzerInterface;
use Illuminate\Support\Str;

/**
 * Route Analyzer
 *
 * Analyzes Laravel routes to extract metadata like path parameters,
 * authentication, rate limits, API version, and custom headers.
 *
 * @package Abr4xas\McpTools\Analyzers
 */
class RouteAnalyzer implements RouteAnalyzerInterface
{
    /**
     * Extract path parameters from route URI.
     *
     * @param string $uri The route URI
     * @return array<int, string> Array of parameter names
     */
    public function extractPathParams(string $uri): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        return $matches[1];
    }

    /**
     * Determine authentication type from route middleware.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array{type: string} Auth configuration
     */
    public function determineAuth($route): array
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

    /**
     * Extract rate limit information from route.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array{name: string, description: string}|null Rate limit info or null
     */
    public function extractRateLimit($route): ?array
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, 'throttle:')) {
                $throttleName = Str::after($middleware, 'throttle:');

                // Common rate limit names and their descriptions for frontend
                $rateLimitDescriptions = [
                    'api' => '60 requests per minute',
                    'webhook' => '5000 requests per minute',
                    'login' => '5 requests per minute',
                    'signup' => '5 requests per minute',
                    'sessions' => '5 requests per minute',
                    'phone-number' => '3 requests per minute',
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
     * Extract API version from route path.
     *
     * @param string $uri The route URI
     * @return string|null API version (v1, v2, etc.) or null
     */
    public function extractApiVersion(string $uri): ?string
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
     * Extract custom headers required for the route based on middleware.
     *
     * @param \Illuminate\Routing\Route $route The Laravel route
     * @return array<int, array{name: string, required: bool, description: string}> Custom headers
     */
    public function extractCustomHeaders($route): array
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
