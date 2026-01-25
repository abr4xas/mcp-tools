<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ValidateApiContract extends Tool
{
    protected string $name = 'validate-api-contract';

    protected string $description = 'Validate that the API contract is up to date with current routes. Detects new routes, removed routes, and changes in HTTP methods.';

    protected RouteAnalyzer $routeAnalyzer;

    public function __construct(RouteAnalyzer $routeAnalyzer)
    {
        $this->routeAnalyzer = $routeAnalyzer;
    }

    public function handle(Request $request): Response
    {
        $contractPath = $request->get('contract_path', storage_path('api-contracts/api.json'));

        if (! File::exists($contractPath)) {
            return Response::text(json_encode([
                'valid' => false,
                'errors' => ['Contract file not found. Run "php artisan api:contract:generate" to create it.'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $contract = json_decode(File::get($contractPath), true);
        if (! is_array($contract)) {
            return Response::text(json_encode([
                'valid' => false,
                'errors' => ['Invalid contract file format. Expected JSON object.'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $validation = $this->validateContract($contract);

        return Response::text(json_encode($validation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_path' => $schema->string()
                ->description('Path to the API contract file (default: storage/api-contracts/api.json)')
                ->default(storage_path('api-contracts/api.json')),
        ];
    }

    /**
     * Validate contract against current routes
     *
     * @param  array<string, array>  $contract
     * @return array{valid: bool, issues: array<string, mixed>, summary: array<string, int>}
     */
    protected function validateContract(array $contract): array
    {
        $currentRoutes = $this->getCurrentRoutes();
        $contractRoutes = $this->normalizeContractRoutes($contract);

        $issues = [];
        $summary = [
            'new_routes' => 0,
            'removed_routes' => 0,
            'method_changes' => 0,
            'total_current' => count($currentRoutes),
            'total_contract' => count($contractRoutes),
        ];

        // Find new routes (in current but not in contract)
        foreach ($currentRoutes as $path => $methods) {
            if (! isset($contractRoutes[$path])) {
                $issues[] = [
                    'type' => 'new_route',
                    'path' => $path,
                    'methods' => array_keys($methods),
                    'message' => "New route found: {$path} with methods: ".implode(', ', array_keys($methods)),
                ];
                $summary['new_routes']++;
            } else {
                // Check for new methods in existing routes
                foreach ($methods as $method => $data) {
                    if (! isset($contractRoutes[$path][$method])) {
                        $issues[] = [
                            'type' => 'new_method',
                            'path' => $path,
                            'method' => $method,
                            'message' => "New HTTP method found: {$method} for route {$path}",
                        ];
                        $summary['method_changes']++;
                    }
                }
            }
        }

        // Find removed routes (in contract but not in current)
        foreach ($contractRoutes as $path => $methods) {
            if (! isset($currentRoutes[$path])) {
                $issues[] = [
                    'type' => 'removed_route',
                    'path' => $path,
                    'methods' => array_keys($methods),
                    'message' => "Route removed from code: {$path} with methods: ".implode(', ', array_keys($methods)),
                ];
                $summary['removed_routes']++;
            } else {
                // Check for removed methods in existing routes
                foreach ($methods as $method => $data) {
                    if (! isset($currentRoutes[$path][$method])) {
                        $issues[] = [
                            'type' => 'removed_method',
                            'path' => $path,
                            'method' => $method,
                            'message' => "HTTP method removed: {$method} for route {$path}",
                        ];
                        $summary['method_changes']++;
                    }
                }
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'summary' => $summary,
        ];
    }

    /**
     * Get current routes from application
     *
     * @return array<string, array<string, array>>
     */
    protected function getCurrentRoutes(): array
    {
        $routes = Route::getRoutes()->getRoutes();
        $currentRoutes = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! Str::startsWith($uri, 'api/')) {
                continue;
            }

            $normalizedUri = '/'.mb_ltrim($uri, '/');
            $methods = $route->methods();

            foreach ($methods as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                if (! isset($currentRoutes[$normalizedUri])) {
                    $currentRoutes[$normalizedUri] = [];
                }

                $currentRoutes[$normalizedUri][$method] = [
                    'path' => $normalizedUri,
                    'method' => $method,
                ];
            }
        }

        return $currentRoutes;
    }

    /**
     * Normalize contract routes to same format as current routes
     *
     * @param  array<string, array>  $contract
     * @return array<string, array<string, array>>
     */
    protected function normalizeContractRoutes(array $contract): array
    {
        $normalized = [];

        foreach ($contract as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }

            foreach ($methods as $method => $data) {
                if (! isset($normalized[$path])) {
                    $normalized[$path] = [];
                }

                $normalized[$path][$method] = $data;
            }
        }

        return $normalized;
    }
}
