<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
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
        $method = mb_strtoupper((string) $request->get('method', ''));
        $version = $request->get('version', '');
        $search = $request->get('search', '');
        $limit = min(max((int) $request->get('limit', 50), 1), 200);

        $contract = $this->loadContract();
        if ($contract === null) {
            $fullPath = Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));
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

        $fullPath = Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));

        if (! File::exists($fullPath)) {
            return null;
        }

        $content = File::get($fullPath);
        $contract = json_decode($content, true);

        if (! is_array($contract)) {
            return null;
        }

        // Validate contract structure
        if (! $this->validateContractStructure($contract)) {
            return null;
        }

        self::$contractCache[$cacheKey] = $contract;

        return $contract;
    }

    /**
     * Validate the structure of the contract
     *
     * @param  array<string, mixed>  $contract
     */
    protected function validateContractStructure(array $contract): bool
    {
        // Contract should be an associative array where keys are route paths
        foreach ($contract as $path => $methods) {
            if (! is_string($path)) {
                return false;
            }

            if (! is_array($methods)) {
                return false;
            }

            // Each route should have HTTP methods as keys
            foreach ($methods as $method => $routeData) {
                if (! is_string($method) || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                    continue; // Skip invalid methods but don't fail
                }

                if (! is_array($routeData)) {
                    return false;
                }

                // Validate required fields exist (at minimum, auth should be present)
                if (! isset($routeData['auth']) || ! is_array($routeData['auth'])) {
                    return false;
                }

                // Validate auth structure
                if (! isset($routeData['auth']['type']) || ! is_string($routeData['auth']['type'])) {
                    return false;
                }

                // path_parameters should be an array if present
                if (isset($routeData['path_parameters']) && ! is_array($routeData['path_parameters'])) {
                    return false;
                }
            }
        }

        return true;
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
