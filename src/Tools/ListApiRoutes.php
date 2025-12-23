<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Abr4xas\McpTools\Services\ContractLoader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListApiRoutes extends Tool
{
    protected string $name = 'list-api-routes';

    protected string $description = 'List all available API routes. Can filter by method, version, or search term.';

    protected ContractLoader $contractLoader;

    public function __construct(ContractLoader $contractLoader = null)
    {
        $this->contractLoader = $contractLoader ?? new ContractLoader();
    }

    public function handle(Request $request): Response
    {
        $method = mb_strtoupper((string) $request->get('method', ''));
        $version = $request->get('version', '');
        $search = $request->get('search', '');
        $limit = min(max((int) $request->get('limit', 50), 1), 200);
        $includeMetadata = (bool) $request->get('include_metadata', false);
        
        // Pagination support: can use either 'page' or 'offset'
        $page = (int) $request->get('page', 0);
        $offset = (int) $request->get('offset', 0);
        
        // If page is provided, calculate offset
        if ($page > 0) {
            $offset = ($page - 1) * $limit;
        }
        
        $offset = max($offset, 0);

        $contract = $this->contractLoader->load();
        if ($contract === null) {
            $fullPath = $this->contractLoader->getContractPath();
            if (! File::exists($fullPath)) {
                return Response::text("Error: Contract not found. Run 'php artisan api:generate-contract'.");
            }
            
            return Response::text("Error: Contract file exists but has invalid structure. Please regenerate the contract with 'php artisan api:generate-contract'.");
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

                $routeInfo = [
                    'path' => $path,
                    'method' => $httpMethod,
                    'auth' => $routeData['auth']['type'] ?? 'none',
                    'api_version' => $routeData['api_version'] ?? null,
                ];

                // Include metadata if requested
                if ($includeMetadata) {
                    $routeInfo['rate_limit'] = $routeData['rate_limit'] ?? null;
                    $routeInfo['custom_headers'] = $routeData['custom_headers'] ?? [];
                    $routeInfo['path_parameters'] = $routeData['path_parameters'] ?? [];
                    $routeInfo['has_request_schema'] = ! empty($routeData['request_schema'] ?? []);
                    $routeInfo['has_response_schema'] = ! empty($routeData['response_schema'] ?? []) && ! isset($routeData['response_schema']['undocumented']);
                }

                $routes[] = $routeInfo;
            }
        }

        // Sort by path
        usort($routes, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        $total = count($routes);
        $totalPages = (int) ceil($total / $limit);
        $currentPage = $page > 0 ? $page : (int) floor($offset / $limit) + 1;

        // Apply pagination
        $paginatedRoutes = array_slice($routes, $offset, $limit);

        return Response::text(json_encode([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'page' => $currentPage,
            'total_pages' => $totalPages,
            'has_next' => $offset + $limit < $total,
            'has_previous' => $offset > 0,
            'routes' => $paginatedRoutes,
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
            'page' => $schema->integer()
                ->description('Page number for pagination (starts at 1). Mutually exclusive with offset.')
                ->default(1)
                ->minimum(1),
            'offset' => $schema->integer()
                ->description('Number of routes to skip. Mutually exclusive with page.')
                ->default(0)
                ->minimum(0),
            'include_metadata' => $schema->boolean()
                ->description('Include additional metadata (rate limits, custom headers, path parameters, schema info)')
                ->default(false),
        ];
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

        // Search in API version
        $apiVersion = $routeData['api_version'] ?? null;
        if ($apiVersion && mb_strpos(mb_strtolower((string) $apiVersion), $searchLower) !== false) {
            return true;
        }

        // Search in auth type
        $authType = $routeData['auth']['type'] ?? null;
        if ($authType && mb_strpos(mb_strtolower((string) $authType), $searchLower) !== false) {
            return true;
        }

        // Search in request schema properties (field names)
        $requestSchema = $routeData['request_schema'] ?? [];
        if (isset($requestSchema['properties']) && is_array($requestSchema['properties'])) {
            foreach (array_keys($requestSchema['properties']) as $field) {
                if (mb_strpos(mb_strtolower((string) $field), $searchLower) !== false) {
                    return true;
                }
            }
        }

        // Search in response schema properties (field names)
        $responseSchema = $routeData['response_schema'] ?? [];
        if (isset($responseSchema['properties']) && is_array($responseSchema['properties'])) {
            foreach (array_keys($responseSchema['properties']) as $field) {
                if (mb_strpos(mb_strtolower((string) $field), $searchLower) !== false) {
                    return true;
                }
            }
        }

        // Search in rate limit name
        $rateLimit = $routeData['rate_limit'] ?? null;
        if ($rateLimit && isset($rateLimit['name'])) {
            if (mb_strpos(mb_strtolower((string) $rateLimit['name']), $searchLower) !== false) {
                return true;
            }
        }

        return false;
    }
}
