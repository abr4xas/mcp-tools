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
        $groupBy = $request->get('group_by', '');
        $controller = $request->get('controller', '');
        $resource = $request->get('resource', '');

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

                // Filter by controller if provided
                if ($controller) {
                    $routeController = $this->extractControllerFromPath($path);
                    if (! $this->matchesController($routeController, $controller)) {
                        continue;
                    }
                }

                // Filter by resource if provided
                if ($resource) {
                    $routeResource = $this->extractResourceFromRouteData($routeData);
                    if (! $this->matchesResource($routeResource, $resource)) {
                        continue;
                    }
                }

                // Filter by search term if provided
                if ($search && ! $this->matchesSearch($path, $routeData, $search)) {
                    continue;
                }

                $routeInfo = [
                    'path' => $path,
                    'method' => $httpMethod,
                    'auth' => $routeData['auth']['type'] ?? 'none',
                    'api_version' => $routeData['api_version'] ?? null,
                ];

                // Add controller if available
                $routeController = $this->extractControllerFromPath($path);
                if ($routeController) {
                    $routeInfo['controller'] = $routeController;
                }

                // Extract grouping key
                if ($groupBy) {
                    $routeInfo['_group_key'] = $this->extractGroupKey($path, $routeData, $groupBy);
                }

                $routes[] = $routeInfo;
            }
        }

        // Sort by path
        usort($routes, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        // Group if requested
        if ($groupBy) {
            $grouped = $this->groupRoutes($routes, $groupBy);
            return Response::text(json_encode([
                'total' => count($routes),
                'limit' => $limit,
                'grouped_by' => $groupBy,
                'groups' => $grouped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

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
            'group_by' => $schema->string()
                ->description('Group routes by: controller, prefix, or version')
                ->enum(['controller', 'prefix', 'version']),
            'controller' => $schema->string()
                ->description('Filter by controller name (supports partial matching)'),
            'resource' => $schema->string()
                ->description('Filter by resource name used in response (supports partial matching)'),
        ];
    }

    /**
     * Extract group key from route
     *
     * @param  array<string, mixed>  $routeData
     */
    protected function extractGroupKey(string $path, array $routeData, string $groupBy): string
    {
        return match ($groupBy) {
            'controller' => $this->extractControllerFromPath($path),
            'prefix' => $this->extractPrefixFromPath($path),
            'version' => $routeData['api_version'] ?? 'unknown',
            default => 'unknown',
        };
    }

    /**
     * Extract controller name from path (heuristic)
     */
    protected function extractControllerFromPath(string $path): string
    {
        // Extract resource name from path (e.g., /api/v1/users -> UsersController)
        $parts = explode('/', trim($path, '/'));
        $resource = end($parts);
        $resource = str_replace(['{', '}'], '', $resource);
        $resource = \Illuminate\Support\Str::singular($resource);
        return ucfirst($resource) . 'Controller';
    }

    /**
     * Check if controller matches filter
     */
    protected function matchesController(string $controller, string $filter): bool
    {
        $controllerLower = mb_strtolower($controller);
        $filterLower = mb_strtolower($filter);

        return str_contains($controllerLower, $filterLower) || str_contains($filterLower, $controllerLower);
    }

    /**
     * Extract resource name from route data
     */
    protected function extractResourceFromRouteData(array $routeData): ?string
    {
        // Try to extract from response schema
        if (isset($routeData['response_schema']) && is_array($routeData['response_schema'])) {
            // Look for resource class name in schema
            // This is a heuristic approach
            return null; // Would need more analysis
        }

        return null;
    }

    /**
     * Check if resource matches filter
     */
    protected function matchesResource(?string $resource, string $filter): bool
    {
        if ($resource === null) {
            return false;
        }

        $resourceLower = mb_strtolower($resource);
        $filterLower = mb_strtolower($filter);

        return str_contains($resourceLower, $filterLower) || str_contains($filterLower, $resourceLower);
    }

    /**
     * Extract prefix from path
     */
    protected function extractPrefixFromPath(string $path): string
    {
        // Extract prefix (e.g., /api/v1/users -> /api/v1)
        $parts = explode('/', trim($path, '/'));
        if (count($parts) >= 2) {
            return '/' . implode('/', array_slice($parts, 0, 2));
        }
        return '/';
    }

    /**
     * Group routes by key
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function groupRoutes(array $routes, string $groupBy): array
    {
        $grouped = [];

        foreach ($routes as $route) {
            $key = $route['_group_key'] ?? 'unknown';
            unset($route['_group_key']);

            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $route;
        }

        return $grouped;
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
     * Check if route matches search term with fuzzy matching and relevance
     */
    protected function matchesSearch(string $path, array $routeData, string $search): bool
    {
        $searchLower = mb_strtolower(trim($search));
        $searchTerms = $this->parseSearchTerms($searchLower);

        // If search has operators (AND, OR), handle them
        if (count($searchTerms) > 1) {
            return $this->matchesSearchWithOperators($path, $routeData, $searchTerms);
        }

        $searchTerm = $searchTerms[0];

        // Exact match (highest relevance)
        if (mb_strtolower($path) === $searchTerm) {
            return true;
        }

        // Search in path with fuzzy matching
        if ($this->fuzzyMatch(mb_strtolower($path), $searchTerm)) {
            return true;
        }

        // Search in description
        $description = $routeData['description'] ?? '';
        if ($description && $this->fuzzyMatch(mb_strtolower($description), $searchTerm)) {
            return true;
        }

        // Search in path parameters
        $pathParams = $routeData['path_parameters'] ?? [];
        foreach ($pathParams as $paramName => $paramData) {
            if ($this->fuzzyMatch(mb_strtolower((string) $paramName), $searchTerm)) {
                return true;
            }
        }

        // Search in request schema properties
        if (isset($routeData['request_schema']['properties'])) {
            foreach (array_keys($routeData['request_schema']['properties']) as $prop) {
                if ($this->fuzzyMatch(mb_strtolower((string) $prop), $searchTerm)) {
                    return true;
                }
            }
        }

        // Search in response schema properties
        if (isset($routeData['response_schema']) && is_array($routeData['response_schema'])) {
            foreach (array_keys($routeData['response_schema']) as $prop) {
                if ($prop !== 'undocumented' && $this->fuzzyMatch(mb_strtolower((string) $prop), $searchTerm)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse search terms handling AND/OR operators
     *
     * @return array<int, string>
     */
    protected function parseSearchTerms(string $search): array
    {
        // Simple parsing: split by AND/OR (case insensitive)
        $terms = preg_split('/\s+(?:and|or)\s+/i', $search);
        return array_filter(array_map('trim', $terms));
    }

    /**
     * Match search with AND/OR operators
     *
     * @param  array<int, string>  $searchTerms
     */
    protected function matchesSearchWithOperators(string $path, array $routeData, array $searchTerms): bool
    {
        $originalSearch = implode(' ', $searchTerms);
        $matches = [];

        foreach ($searchTerms as $term) {
            $matches[] = $this->matchesSearch($path, $routeData, $term);
        }

        // Default to AND logic (all terms must match)
        // Could be enhanced to detect OR from original search string
        return ! in_array(false, $matches, true);
    }

    /**
     * Fuzzy match with typo tolerance (simple Levenshtein-based)
     */
    protected function fuzzyMatch(string $text, string $search): bool
    {
        // Exact substring match
        if (mb_strpos($text, $search) !== false) {
            return true;
        }

        // Word boundary match
        if (preg_match('/\b' . preg_quote($search, '/') . '\b/i', $text)) {
            return true;
        }

        // Fuzzy match for short searches (tolerate 1-2 character differences)
        if (mb_strlen($search) <= 10) {
            $distance = levenshtein($text, $search);
            $maxDistance = min(2, (int) (mb_strlen($search) * 0.3));
            if ($distance <= $maxDistance) {
                return true;
            }
        }

        // Partial word match
        $searchWords = explode(' ', $search);
        foreach ($searchWords as $word) {
            if (mb_strlen($word) >= 3 && mb_strpos($text, $word) !== false) {
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
