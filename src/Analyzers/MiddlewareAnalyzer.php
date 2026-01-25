<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class MiddlewareAnalyzer
{
    /**
     * Analyze all middleware applied to a route
     *
     * @return array<int, array{name: string, category: string, parameters: array<string, mixed>}>
     */
    public function analyze(Route $route): array
    {
        $middlewares = $route->gatherMiddleware();
        $analyzed = [];

        foreach ($middlewares as $middleware) {
            if (is_string($middleware)) {
                $analyzed[] = $this->analyzeStringMiddleware($middleware);
            } elseif (is_object($middleware)) {
                $analyzed[] = $this->analyzeObjectMiddleware($middleware);
            }
        }

        return $analyzed;
    }

    /**
     * Analyze string middleware
     *
     * @return array{name: string, category: string, parameters: array<string, mixed>}
     */
    protected function analyzeStringMiddleware(string $middleware): array
    {
        $name = $middleware;
        $category = 'other';
        $parameters = [];

        // Extract parameters if present (e.g., "throttle:60,1")
        if (str_contains($middleware, ':')) {
            [$name, $params] = explode(':', $middleware, 2);
            $parameters = $this->parseMiddlewareParameters($params);
        }

        // Categorize middleware
        $category = $this->categorizeMiddleware($name);

        return [
            'name' => $name,
            'category' => $category,
            'parameters' => $parameters,
        ];
    }

    /**
     * Analyze object middleware
     *
     * @return array{name: string, category: string, parameters: array<string, mixed>}
     */
    protected function analyzeObjectMiddleware(object $middleware): array
    {
        $className = get_class($middleware);
        $name = class_basename($className);
        $category = $this->categorizeMiddleware($name);

        return [
            'name' => $name,
            'category' => $category,
            'parameters' => [],
        ];
    }

    /**
     * Parse middleware parameters
     *
     * @return array<string, mixed>
     */
    protected function parseMiddlewareParameters(string $params): array
    {
        $parsed = [];

        // Handle comma-separated values (e.g., "60,1" for throttle)
        if (str_contains($params, ',')) {
            $parts = explode(',', $params);
            $parsed['values'] = array_map('trim', $parts);
        } else {
            $parsed['value'] = $params;
        }

        return $parsed;
    }

    /**
     * Categorize middleware
     */
    protected function categorizeMiddleware(string $name): string
    {
        $nameLower = mb_strtolower($name);

        if (Str::contains($nameLower, 'auth') || Str::contains($nameLower, 'sanctum') || Str::contains($nameLower, 'passport')) {
            return 'authentication';
        }

        if (Str::contains($nameLower, 'throttle') || Str::contains($nameLower, 'rate')) {
            return 'rate_limiting';
        }

        if (Str::contains($nameLower, 'validate') || Str::contains($nameLower, 'verify')) {
            return 'validation';
        }

        if (Str::contains($nameLower, 'cors') || Str::contains($nameLower, 'cross')) {
            return 'cors';
        }

        if (Str::contains($nameLower, 'guest')) {
            return 'guest';
        }

        if (Str::contains($nameLower, 'cache')) {
            return 'caching';
        }

        if (Str::contains($nameLower, 'encrypt') || Str::contains($nameLower, 'decrypt')) {
            return 'encryption';
        }

        return 'other';
    }

    /**
     * Extract required headers from middleware
     *
     * @return array<int, array{name: string, required: bool, description: string}>
     */
    public function extractRequiredHeaders(array $middlewares): array
    {
        $headers = [];

        foreach ($middlewares as $middleware) {
            $name = is_string($middleware) ? $middleware : get_class($middleware);
            $nameLower = mb_strtolower($name);

            // CORS headers
            if (Str::contains($nameLower, 'cors')) {
                $headers[] = [
                    'name' => 'Origin',
                    'required' => false,
                    'description' => 'Origin header for CORS requests',
                ];
                $headers[] = [
                    'name' => 'Access-Control-Request-Method',
                    'required' => false,
                    'description' => 'CORS preflight request method',
                ];
            }

            // Rate limiting headers
            if (Str::contains($nameLower, 'throttle') || Str::contains($nameLower, 'rate')) {
                $headers[] = [
                    'name' => 'X-RateLimit-Limit',
                    'required' => false,
                    'description' => 'Rate limit information',
                ];
            }

            // Custom API key headers
            if (Str::contains($nameLower, 'apikey') || Str::contains($nameLower, 'api-key')) {
                $headers[] = [
                    'name' => 'X-API-Key',
                    'required' => true,
                    'description' => 'API key for authentication',
                ];
            }
        }

        return $headers;
    }

    /**
     * Detect content negotiation support from middleware
     *
     * @return array<string, array{format: string, description: string}>
     */
    public function detectContentNegotiation(array $middlewares): array
    {
        $formats = [];

        foreach ($middlewares as $middleware) {
            $name = is_string($middleware) ? $middleware : get_class($middleware);
            $nameLower = mb_strtolower($name);

            // Default JSON support
            $formats['application/json'] = [
                'format' => 'json',
                'description' => 'JSON format (default)',
            ];

            // Check for XML support
            if (Str::contains($nameLower, 'xml')) {
                $formats['application/xml'] = [
                    'format' => 'xml',
                    'description' => 'XML format',
                ];
            }
        }

        return $formats;
    }
}
