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
}
