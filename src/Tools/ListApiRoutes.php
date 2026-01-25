<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListApiRoutes extends Tool
{
    protected string $name = 'list-api-routes';

    protected string $description = 'List all available API routes. Can filter by method, version, or search term.';

    /** @var array<string, array> */
    protected static array $contractCache = [];

    public function handle(Request $request): Response
    {
        // Support batch operations: accept array of filters
        $method = $request->get('method', '');
        $version = $request->get('version', '');
        $search = $request->get('search', '');
        $limit = min(max((int) $request->get('limit', 50), 1), 200);

        // Handle array of filters for batch operations
        if (is_array($method) || is_array($version) || is_array($search)) {
            return $this->handleBatch($request);
        }

        $method = mb_strtoupper((string) $method);

        $contract = $this->loadContract();
        if ($contract === null) {
            return Response::text("Error: Contract not found. Run 'php artisan api:contract:generate'.");
        }

        $routes = [];

        foreach ($contract as $path => $methods) {
            foreach ($methods as $httpMethod => $routeData) {
                // Filter by method if provided
                if ($method && $httpMethod !== $method) {
                    continue;
                }

                // Filter by version if provided
                if ($version && ($routeData['api_version'] ?? null) !== $version) {
                    continue;
                }

                // Filter by search term if provided
                if ($search && ! $this->matchesSearch($path, $routeData, $search)) {
                    continue;
                }

                $routes[] = [
                    'path' => $path,
                    'method' => $httpMethod,
                    'auth' => $routeData['auth']['type'] ?? 'none',
                    'api_version' => $routeData['api_version'] ?? null,
                ];
            }
        }

        // Sort by path
        usort($routes, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        // Apply limit
        $routes = array_slice($routes, 0, $limit);

        return Response::text(json_encode([
            'total' => count($routes),
            'limit' => $limit,
            'routes' => $routes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()
                ->description('Filter by HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS)')
                ->enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']),
            'version' => $schema->string()
                ->description('Filter by API version (v1, v2)'),
            'search' => $schema->string()
                ->description('Search term to filter routes by path'),
            'limit' => $schema->integer()
                ->description('Maximum number of routes to return (default: 50, max: 200)')
                ->default(50),
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
     * Check if route matches search term
     */
    protected function matchesSearch(string $path, array $routeData, string $search): bool
    {
        $searchLower = mb_strtolower($search);

        // Search in path
        if (mb_strpos(mb_strtolower($path), $searchLower) !== false) {
            return true;
        }

        // Search in path parameters
        $pathParams = $routeData['path_parameters'] ?? [];
        foreach ($pathParams as $param) {
            if (mb_strpos(mb_strtolower((string) $param), $searchLower) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle batch operations with multiple filters
     */
    protected function handleBatch(Request $request): Response
    {
        $methods = $request->get('method', []);
        $versions = $request->get('version', []);
        $searches = $request->get('search', []);
        $limit = min(max((int) $request->get('limit', 50), 1), 200);

        // Normalize to arrays
        if (! is_array($methods)) {
            $methods = $methods ? [$methods] : [];
        }
        if (! is_array($versions)) {
            $versions = $versions ? [$versions] : [];
        }
        if (! is_array($searches)) {
            $searches = $searches ? [$searches] : [];
        }

        $contract = $this->loadContract();
        if ($contract === null) {
            return Response::text("Error: Contract not found. Run 'php artisan api:contract:generate'.");
        }

        $results = [];

        // Process each combination of filters
        $filters = [];
        foreach ($methods as $m) {
            foreach ($versions as $v) {
                foreach ($searches as $s) {
                    $filters[] = [
                        'method' => mb_strtoupper((string) $m),
                        'version' => $v,
                        'search' => $s,
                    ];
                }
            }
        }

        // If no filters provided, use empty filter
        if (empty($filters)) {
            $filters[] = ['method' => '', 'version' => '', 'search' => ''];
        }

        foreach ($filters as $filter) {
            $routes = [];
            foreach ($contract as $path => $methods) {
                foreach ($methods as $httpMethod => $routeData) {
                    // Apply filters
                    if ($filter['method'] && $httpMethod !== $filter['method']) {
                        continue;
                    }
                    if ($filter['version'] && ($routeData['api_version'] ?? null) !== $filter['version']) {
                        continue;
                    }
                    if ($filter['search'] && ! $this->matchesSearch($path, $routeData, $filter['search'])) {
                        continue;
                    }

                    $routes[] = [
                        'path' => $path,
                        'method' => $httpMethod,
                        'auth' => $routeData['auth']['type'] ?? 'none',
                        'api_version' => $routeData['api_version'] ?? null,
                    ];
                }
            }

            usort($routes, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
            $routes = array_slice($routes, 0, $limit);

            $results[] = [
                'filters' => $filter,
                'total' => count($routes),
                'limit' => $limit,
                'routes' => $routes,
            ];
        }

        return Response::text(json_encode([
            'batch_results' => $results,
            'total_operations' => count($results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
