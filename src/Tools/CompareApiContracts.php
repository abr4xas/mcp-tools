<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CompareApiContracts extends Tool
{
    protected string $name = 'compare-api-contracts';

    protected string $description = 'Compare two versions of API contracts and detect changes (added routes, removed routes, modified routes, schema changes).';

    public function handle(Request $request): Response
    {
        $contract1Path = $request->get('contract1_path');
        $contract2Path = $request->get('contract2_path');

        if (! $contract1Path || ! is_string($contract1Path)) {
            $json = json_encode([
                'error' => true,
                'message' => 'contract1_path parameter is required',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{"error":true}' : $json);
        }

        if (! $contract2Path || ! is_string($contract2Path)) {
            $json = json_encode([
                'error' => true,
                'message' => 'contract2_path parameter is required',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{"error":true}' : $json);
        }

        if (! File::exists($contract1Path)) {
            $json = json_encode([
                'error' => true,
                'message' => "Contract file not found: {$contract1Path}",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{"error":true}' : $json);
        }

        if (! File::exists($contract2Path)) {
            $json = json_encode([
                'error' => true,
                'message' => "Contract file not found: {$contract2Path}",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{"error":true}' : $json);
        }

        $contract1 = json_decode(File::get($contract1Path), true);
        $contract2 = json_decode(File::get($contract2Path), true);

        if (! is_array($contract1) || ! is_array($contract2)) {
            $json = json_encode([
                'error' => true,
                'message' => 'Invalid contract file format. Expected JSON object.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return Response::text($json === false ? '{"error":true}' : $json);
        }

        $comparison = $this->compareContracts($contract1, $contract2);
        $json = json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return Response::text($json === false ? '{}' : $json);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contract1_path' => $schema->string()
                ->description('Path to the first API contract file')
                ->required(),
            'contract2_path' => $schema->string()
                ->description('Path to the second API contract file')
                ->required(),
        ];
    }

    /**
     * Compare two contracts and detect differences
     *
     * @param  array<string, array<string, mixed>>  $contract1
     * @param  array<string, array<string, mixed>>  $contract2
     * @return array{summary: array<string, int>, changes: list<array<string, mixed>>}
     */
    protected function compareContracts(array $contract1, array $contract2): array
    {
        $changes = [];
        $summary = [
            'added_routes' => 0,
            'removed_routes' => 0,
            'added_methods' => 0,
            'removed_methods' => 0,
            'modified_routes' => 0,
            'schema_changes' => 0,
            'auth_changes' => 0,
        ];

        // Find routes in contract2 but not in contract1 (added)
        foreach ($contract2 as $path => $methods) {
            if (! isset($contract1[$path])) {
                $changes[] = [
                    'type' => 'added_route',
                    'path' => $path,
                    'methods' => array_keys($methods),
                    'details' => $methods,
                ];
                $summary['added_routes']++;
            } else {
                // Compare methods in existing routes
                foreach ($methods as $method => $data) {
                    if (! isset($contract1[$path][$method])) {
                        $changes[] = [
                            'type' => 'added_method',
                            'path' => $path,
                            'method' => $method,
                            'details' => $data,
                        ];
                        $summary['added_methods']++;
                    } else {
                        // Compare route data for changes
                        $routeChanges = $this->compareRouteData($contract1[$path][$method], $data, $path, $method);
                        if (! empty($routeChanges)) {
                            $changes[] = [
                                'type' => 'modified_route',
                                'path' => $path,
                                'method' => $method,
                                'changes' => $routeChanges,
                            ];
                            $summary['modified_routes']++;
                            if (isset($routeChanges['auth'])) {
                                $summary['auth_changes']++;
                            }
                            if (isset($routeChanges['request_schema']) || isset($routeChanges['response_schema'])) {
                                $summary['schema_changes']++;
                            }
                        }
                    }
                }
            }
        }

        // Find routes in contract1 but not in contract2 (removed)
        foreach ($contract1 as $path => $methods) {
            if (! isset($contract2[$path])) {
                $changes[] = [
                    'type' => 'removed_route',
                    'path' => $path,
                    'methods' => array_keys($methods),
                    'details' => $methods,
                ];
                $summary['removed_routes']++;
            } else {
                // Find removed methods
                foreach ($methods as $method => $data) {
                    if (! isset($contract2[$path][$method])) {
                        $changes[] = [
                            'type' => 'removed_method',
                            'path' => $path,
                            'method' => $method,
                            'details' => $data,
                        ];
                        $summary['removed_methods']++;
                    }
                }
            }
        }

        return [
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    /**
     * Compare route data between two versions
     *
     * @param  array<string, mixed>  $oldData
     * @param  array<string, mixed>  $newData
     * @return array<string, mixed>
     */
    protected function compareRouteData(array $oldData, array $newData, string $path, string $method): array
    {
        $changes = [];

        // Compare auth
        if (isset($oldData['auth']) && isset($newData['auth'])) {
            if ($oldData['auth'] !== $newData['auth']) {
                $changes['auth'] = [
                    'old' => $oldData['auth'],
                    'new' => $newData['auth'],
                ];
            }
        } elseif (isset($oldData['auth']) || isset($newData['auth'])) {
            $changes['auth'] = [
                'old' => $oldData['auth'] ?? null,
                'new' => $newData['auth'] ?? null,
            ];
        }

        // Compare request schema
        if (isset($oldData['request_schema']) && isset($newData['request_schema'])) {
            if ($this->schemasDiffer($oldData['request_schema'], $newData['request_schema'])) {
                $changes['request_schema'] = [
                    'old' => $oldData['request_schema'],
                    'new' => $newData['request_schema'],
                ];
            }
        } elseif (isset($oldData['request_schema']) || isset($newData['request_schema'])) {
            $changes['request_schema'] = [
                'old' => $oldData['request_schema'] ?? null,
                'new' => $newData['request_schema'] ?? null,
            ];
        }

        // Compare response schema
        if (isset($oldData['response_schema']) && isset($newData['response_schema'])) {
            if ($this->schemasDiffer($oldData['response_schema'], $newData['response_schema'])) {
                $changes['response_schema'] = [
                    'old' => $oldData['response_schema'],
                    'new' => $newData['response_schema'],
                ];
            }
        } elseif (isset($oldData['response_schema']) || isset($newData['response_schema'])) {
            $changes['response_schema'] = [
                'old' => $oldData['response_schema'] ?? null,
                'new' => $newData['response_schema'] ?? null,
            ];
        }

        // Compare path parameters
        if (isset($oldData['path_parameters']) && isset($newData['path_parameters'])) {
            if ($oldData['path_parameters'] !== $newData['path_parameters']) {
                $changes['path_parameters'] = [
                    'old' => $oldData['path_parameters'],
                    'new' => $newData['path_parameters'],
                ];
            }
        }

        // Compare rate limit
        if (isset($oldData['rate_limit']) && isset($newData['rate_limit'])) {
            if ($oldData['rate_limit'] !== $newData['rate_limit']) {
                $changes['rate_limit'] = [
                    'old' => $oldData['rate_limit'],
                    'new' => $newData['rate_limit'],
                ];
            }
        }

        return $changes;
    }

    /**
     * Check if two schemas are different
     *
     * @param  array<string, mixed>  $schema1
     * @param  array<string, mixed>  $schema2
     */
    protected function schemasDiffer(array $schema1, array $schema2): bool
    {
        // Simple comparison - can be enhanced with deep diff
        $json1 = json_encode($schema1, JSON_THROW_ON_ERROR | 64); // JSON_SORT_KEYS = 64
        $json2 = json_encode($schema2, JSON_THROW_ON_ERROR | 64); // JSON_SORT_KEYS = 64

        return $json1 !== $json2;
    }
}
