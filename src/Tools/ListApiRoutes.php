<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

class ListApiRoutes extends Tool
{
    protected string $name = 'list-api-routes';

    protected string $description = 'List all available API routes. Can filter by method, version, or search term.';

    /** @var array<string, array<string, mixed>> */
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
        $sort = $request->get('sort', '');

        $contract = $this->loadContract();
        if ($contract === null) {
            $fullPath = storage_path('api-contracts/api.json');
            if (! File::exists($fullPath)) {
                return Response::text('Error: Contract not found. Run \'php artisan api:contract:generate\'.');
            }
            return Response::text('Error: Contract file exists but has invalid structure. Please regenerate the contract with \'php artisan api:contract:generate\'.');
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

                // Filter by search term if provided (including route name)
                if ($search) {
                    $matchesSearch = $this->matchesSearch($path, $routeData, $search);
                    $matchesRouteName = isset($routeData['route_name']) &&
                        mb_stripos($routeData['route_name'], $search) !== false;
                    if (! $matchesSearch && ! $matchesRouteName) {
                        continue;
                    }
                }

                $routeInfo = [
                    'path' => $path,
                    'method' => $httpMethod,
                    'auth' => $routeData['auth']['type'] ?? 'none',
                    'api_version' => $routeData['api_version'] ?? null,
                    'route_name' => $routeData['route_name'] ?? null,
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

        // Sort routes
        $this->sortRoutes($routes, $sort);

        // Group if requested
        if ($groupBy) {
            $grouped = $this->groupRoutes($routes, $groupBy);

            $json = json_encode([
                'total' => count($routes),
                'limit' => $limit,
                'grouped_by' => $groupBy,
                'groups' => $grouped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{}' : $json);
        }

        $totalRoutes = count($routes);
        $page = (int) $request->get('page', 1);
        $offset = ($page - 1) * $limit;

        // Apply pagination
        $paginatedRoutes = array_slice($routes, $offset, $limit);
        $totalPages = (int) ceil($totalRoutes / $limit);

        $response = [
            'total' => $totalRoutes,
            'limit' => $limit,
            'routes' => $paginatedRoutes,
        ];

        // Add pagination information
        $response['pagination'] = [
            'current_page' => $page,
            'from' => $offset + 1,
            'last_page' => $totalPages,
            'per_page' => $limit,
            'to' => min($offset + $limit, $totalRoutes),
            'total' => $totalRoutes,
        ];

        // Add pagination links if request context is available
        try {
            $response['links'] = $this->generatePaginationLinks($page, $totalPages, $limit);
        } catch (Throwable) {
            // If request() is not available in test context, skip links
            $response['links'] = [];
        }

        // Add meta for backward compatibility
        $response['meta'] = $response['pagination'];

        // Add statistics
        $response['statistics'] = $this->calculateStatistics($routes);

        return Response::text((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
            'page' => $schema->integer()
                ->description('Page number for pagination (default: 1)')
                ->default(1),
            'group_by' => $schema->string()
                ->description('Group routes by: controller, prefix, or version')
                ->enum(['controller', 'prefix', 'version']),
            'controller' => $schema->string()
                ->description('Filter by controller name (supports partial matching)'),
            'resource' => $schema->string()
                ->description('Filter by resource name used in response (supports partial matching)'),
            'sort' => $schema->string()
                ->description('Sort routes by: path, method, version (e.g., "path", "method,path", "version,method")'),
        ];
    }

    /**
     * Sort routes by specified criteria
     *
     * @param  array<int, array<string, mixed>>  $routes
     */
    protected function sortRoutes(array &$routes, string $sort): void
    {
        if (empty($sort)) {
            // Default: sort by path
            usort($routes, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

            return;
        }

        $sortFields = array_map('trim', explode(',', $sort));
        $sortFields = array_filter($sortFields);

        if (empty($sortFields)) {
            usort($routes, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

            return;
        }

        usort($routes, function (array $a, array $b) use ($sortFields): int {
            foreach ($sortFields as $field) {
                $field = trim($field);
                $direction = 'asc';

                if (str_ends_with($field, ':desc') || str_ends_with($field, ':DESC')) {
                    $field = substr($field, 0, -5);
                    $direction = 'desc';
                } elseif (str_ends_with($field, ':asc') || str_ends_with($field, ':ASC')) {
                    $field = substr($field, 0, -4);
                    $direction = 'asc';
                }

                $valueA = $this->getSortValue($a, $field);
                $valueB = $this->getSortValue($b, $field);

                $comparison = match (true) {
                    is_numeric($valueA) && is_numeric($valueB) => $valueA <=> $valueB,
                    is_string($valueA) && is_string($valueB) => strcmp($valueA, $valueB),
                    default => strcmp((string) $valueA, (string) $valueB),
                };

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }

            return 0;
        });
    }

    /**
     * Get sort value from route data
     *
     * @param  array<string, mixed>  $route
     */
    protected function getSortValue(array $route, string $field): mixed
    {
        return match ($field) {
            'path' => $route['path'] ?? '',
            'method' => $route['method'] ?? '',
            'version' => $route['api_version'] ?? '',
            'controller' => $route['controller'] ?? '',
            default => $route[$field] ?? '',
        };
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

        return ucfirst($resource).'Controller';
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
     *
     * @param  array<string, mixed>  $routeData
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
            return '/'.implode('/', array_slice($parts, 0, 2));
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
     * @return array<string, array<string, mixed>>|null
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

        // Validate contract structure
        foreach ($contract as $path => $methods) {
            if (! is_array($methods)) {
                return null;
            }
            foreach ($methods as $httpMethod => $routeData) {
                if (! is_array($routeData)) {
                    return null;
                }
                // Check if path_parameters exists and is array
                if (isset($routeData['path_parameters']) && ! is_array($routeData['path_parameters'])) {
                    return null;
                }
            }
        }

        self::$contractCache[$cacheKey] = $contract;

        return $contract;
    }

    /**
     * Check if route matches search term with fuzzy matching and relevance
     *
     * @param  array<string, mixed>  $routeData
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
        if ($terms === false) {
            return [];
        }

        return array_filter(array_map('trim', $terms));
    }

    /**
     * Match search with AND/OR operators
     *
     * @param  array<string, mixed>  $routeData
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
        if (preg_match('/\b'.preg_quote($search, '/').'\b/i', $text)) {
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
            return Response::text('Error: Contract not found. Run \'php artisan api:contract:generate\'.');
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

            usort($routes, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
            $routes = array_slice($routes, 0, $limit);

            $results[] = [
                'filters' => $filter,
                'total' => count($routes),
                'limit' => $limit,
                'routes' => $routes,
            ];
        }

        $json = json_encode([
            'batch_results' => $results,
            'total_operations' => count($results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return Response::text($json === false ? '{}' : $json);
    }

    /**
     * Calculate statistics for routes
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    protected function calculateStatistics(array $routes): array
    {
        $stats = [
            'total_routes' => count($routes),
            'by_method' => [],
            'by_version' => [],
            'with_auth' => 0,
            'without_auth' => 0,
        ];

        foreach ($routes as $route) {
            $method = $route['method'] ?? 'GET';
            $version = $route['api_version'] ?? 'unknown';
            $auth = $route['auth'] ?? 'none';

            $stats['by_method'][$method] = ($stats['by_method'][$method] ?? 0) + 1;
            $stats['by_version'][$version] = ($stats['by_version'][$version] ?? 0) + 1;

            if ($auth !== 'none') {
                $stats['with_auth']++;
            } else {
                $stats['without_auth']++;
            }
        }

        return $stats;
    }

    /**
     * Generate pagination links
     *
     * @return array<string, string|null>
     */
    protected function generatePaginationLinks(int $currentPage, int $lastPage, int $perPage): array
    {
        $baseUrl = request()->url() ?? '/';
        $queryParams = request()->query();

        $links = [
            'first' => $currentPage > 1 ? $this->buildPaginationUrl($baseUrl, 1, $queryParams) : null,
            'last' => $currentPage < $lastPage ? $this->buildPaginationUrl($baseUrl, $lastPage, $queryParams) : null,
            'prev' => $currentPage > 1 ? $this->buildPaginationUrl($baseUrl, $currentPage - 1, $queryParams) : null,
            'next' => $currentPage < $lastPage ? $this->buildPaginationUrl($baseUrl, $currentPage + 1, $queryParams) : null,
        ];

        return $links;
    }

    /**
     * Build pagination URL
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function buildPaginationUrl(string $baseUrl, int $page, array $queryParams): string
    {
        $queryParams['page'] = $page;
        $queryString = http_build_query($queryParams);

        return $baseUrl.($queryString ? '?'.$queryString : '');
    }
}
