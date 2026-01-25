<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DescribeApiRoute extends Tool
{
    protected string $description = 'Get the public API contract for a specific route and method.';

    /** @var array<string, array> */
    protected static array $contractCache = [];

    public function handle(Request $request): Response
    {
        $path = $request->get('path');
        $method = $request->get('method', 'GET');

        // Support batch operations: accept array of paths
        if (is_array($path)) {
            return $this->handleBatch($request, $path, $method);
        }

        if (! $path || ! is_string($path)) {
            return Response::text("Error: 'path' parameter is required and must be a string.");
        }

        $method = mb_strtoupper((string) $method);

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            return Response::text("Error: Invalid HTTP method '{$method}'. Must be one of: GET, POST, PUT, PATCH, DELETE, OPTIONS.");
        }

        // Normalize path: ensure leading slash
        $normalizedPath = '/'.mb_ltrim($path, '/');

        $contract = $this->loadContract();
        if ($contract === null) {
            return Response::text("Error: Contract not found. Run 'php artisan api:contract:generate'.");
        }

        $routeData = $this->findRouteData($contract, $normalizedPath, $method);

        if ($routeData === null) {
            return Response::text(json_encode(['undocumented' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Support searching by route name
        $routeName = $request->get('route_name');
        if ($routeName && isset($routeData['route_name']) && $routeData['route_name'] !== $routeName) {
            // Try to find by route name
            $contract = $this->loadContract();
            if ($contract) {
                foreach ($contract as $contractPath => $methods) {
                    foreach ($methods as $contractMethod => $data) {
                        if (($data['route_name'] ?? null) === $routeName && $contractMethod === $method) {
                            $routeData = $data;
                            $routeData['matched_route'] = $contractPath;
                            break 2;
                        }
                    }
                }
            }
        }

        return Response::text(json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle batch operations with multiple paths
     *
     * @param  array<string>  $paths
     * @param  string|array<string>  $method
     */
    protected function handleBatch(Request $request, array $paths, $method): Response
    {
        $contract = $this->loadContract();
        if ($contract === null) {
            return Response::text("Error: Contract not found. Run 'php artisan api:contract:generate'.");
        }

        $results = [];
        $methods = is_array($method) ? $method : [$method];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $normalizedPath = '/'.mb_ltrim($path, '/');

            foreach ($methods as $m) {
                $m = mb_strtoupper((string) $m);
                if (! in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                    continue;
                }

                $routeData = $this->findRouteData($contract, $normalizedPath, $m);

                $results[] = [
                    'path' => $normalizedPath,
                    'method' => $m,
                    'data' => $routeData ?? ['undocumented' => true],
                ];
            }
        }

        return Response::text(json_encode([
            'batch_results' => $results,
            'total_operations' => count($results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The API route path (e.g., /api/v1/posts or /api/v1/posts/{id})')
                ->required(),
            'method' => $schema->string()
                ->description('HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS)')
                ->enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
                ->default('GET'),
            'route_name' => $schema->string()
                ->description('Search by route name instead of path'),
        ];
    }

    /**
     * Load contract from cache or file system
     *
     * @return array<string, array>|null
     */
    protected function loadContract(): ?array
    {
        $cacheKey = 'contract_api';

        if (isset(self::$contractCache[$cacheKey])) {
            return self::$contractCache[$cacheKey];
        }

        $fullPath = storage_path('api-contracts/api.json');

        if (! File::exists($fullPath)) {
            return null;
        }

        $content = File::get($fullPath);
        $contract = json_decode($content, true);

        if (! is_array($contract)) {
            return null;
        }

        self::$contractCache[$cacheKey] = $contract;

        return $contract;
    }

    /**
     * Find route data in contract, trying exact match first, then pattern matching
     *
     * @param  array<string, array>  $contract
     * @return array<string, mixed>|null
     */
    protected function findRouteData(array $contract, string $path, string $method): ?array
    {
        // Try exact match first
        if (isset($contract[$path][$method])) {
            $routeData = $contract[$path][$method];
            $routeData['matched_route'] = $path;

            return $routeData;
        }

        // Try pattern matching for routes with parameters
        foreach ($contract as $contractPath => $methods) {
            if ($contractPath === $path) {
                continue;
            }

            if ($this->matchesRoutePattern($contractPath, $path)) {
                $routeData = $methods[$method] ?? null;
                if ($routeData !== null) {
                    $routeData['matched_route'] = $contractPath;

                    return $routeData;
                }
            }
        }

        return null;
    }

    /**
     * Check if a concrete path matches a route pattern (e.g., /api/v1/posts/123 matches /api/v1/posts/{post})
     */
    protected function matchesRoutePattern(string $pattern, string $path): bool
    {
        // Normalize paths
        $pattern = '/'.trim($pattern, '/');
        $path = '/'.trim($path, '/');

        // Handle exact match first
        if ($pattern === $path) {
            return true;
        }

        // Escape special regex characters except our placeholders
        $escaped = preg_quote($pattern, '#');

        // Replace {param?} with optional segment (?:/[^/]+)?
        // Handle optional parameters with constraints: {param?} or {param:constraint?}
        $escaped = preg_replace('#\\\\\{([^}:]+)(?::([^}]+))?\\\\\?\}#', '(?:/[^/]+)?', $escaped);

        // Replace {param:constraint} with constrained segment
        // e.g., {id:number} -> \d+ or {slug:alpha} -> [a-zA-Z]+
        $escaped = preg_replace_callback(
            '#\\\\\{([^}:]+):([^}]+)\\\\}#',
            function ($matches) {
                $constraint = $matches[2] ?? '';

                return match (strtolower($constraint)) {
                    'number', 'int', 'integer' => '\d+',
                    'alpha' => '[a-zA-Z]+',
                    'alphanum', 'alphanumeric' => '[a-zA-Z0-9]+',
                    'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
                    default => '[^/]+',
                };
            },
            $escaped
        );

        // Replace {param} with required segment [^/]+
        $escaped = preg_replace('#\\\\\{([^}]+)\\\\}#', '[^/]+', (string) $escaped);

        // Handle multiple parameters and edge cases
        // Support for paths with dots, dashes, etc.
        $escaped = str_replace(['\.', '\-'], ['.', '-'], $escaped);

        // Match with case-insensitive option for better matching
        return (bool) preg_match('#^'.$escaped.'$#i', $path);
    }
}
