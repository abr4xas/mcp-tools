<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MetricsCommand extends Command
{
    protected $signature = 'mcp-tools:metrics';

    protected $description = 'Show metrics about the API contract (number of routes, coverage, etc.)';

    public function handle(): int
    {
        $contractPath = storage_path('api-contracts/api.json');

        if (! File::exists($contractPath)) {
            $this->error('Contract file not found. Run "php artisan api:contract:generate" first.');
            return self::FAILURE;
        }

        $contract = json_decode(File::get($contractPath), true);
        if (! is_array($contract)) {
            $this->error('Invalid contract file.');
            return self::FAILURE;
        }

        // Remove metadata if present
        unset($contract['_metadata']);

        $metrics = $this->calculateMetrics($contract);

        $this->info('API Contract Metrics');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Routes', $metrics['total_routes']],
                ['Routes by Method', $this->formatMethodCounts($metrics['routes_by_method'])],
                ['Routes by Version', $this->formatVersionCounts($metrics['routes_by_version'])],
                ['Routes with Auth', $metrics['routes_with_auth']],
                ['Routes without Auth', $metrics['routes_without_auth']],
                ['Routes with Request Schema', $metrics['routes_with_request_schema']],
                ['Routes with Response Schema', $metrics['routes_with_response_schema']],
                ['Routes with Complete Schemas', $metrics['routes_with_complete_schemas']],
                ['Schema Coverage', $metrics['schema_coverage'] . '%'],
                ['Deprecated Routes', $metrics['deprecated_routes']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Calculate metrics from contract
     *
     * @param  array<string, array>  $contract
     * @return array<string, mixed>
     */
    protected function calculateMetrics(array $contract): array
    {
        $totalRoutes = 0;
        $routesByMethod = [];
        $routesByVersion = [];
        $routesWithAuth = 0;
        $routesWithoutAuth = 0;
        $routesWithRequestSchema = 0;
        $routesWithResponseSchema = 0;
        $routesWithCompleteSchemas = 0;
        $deprecatedRoutes = 0;

        foreach ($contract as $path => $methods) {
            foreach ($methods as $method => $routeData) {
                $totalRoutes++;

                // Count by method
                $routesByMethod[$method] = ($routesByMethod[$method] ?? 0) + 1;

                // Count by version
                $version = $routeData['api_version'] ?? 'unknown';
                $routesByVersion[$version] = ($routesByVersion[$version] ?? 0) + 1;

                // Count auth
                if (isset($routeData['auth']) && ($routeData['auth']['type'] ?? 'none') !== 'none') {
                    $routesWithAuth++;
                } else {
                    $routesWithoutAuth++;
                }

                // Count schemas
                $hasRequestSchema = ! empty($routeData['request_schema'] ?? []);
                $hasResponseSchema = ! empty($routeData['response_schema'] ?? []) 
                    && ! isset($routeData['response_schema']['undocumented']);

                if ($hasRequestSchema) {
                    $routesWithRequestSchema++;
                }
                if ($hasResponseSchema) {
                    $routesWithResponseSchema++;
                }
                if ($hasRequestSchema && $hasResponseSchema) {
                    $routesWithCompleteSchemas++;
                }

                // Count deprecated
                if (isset($routeData['deprecated']) && $routeData['deprecated'] !== null) {
                    $deprecatedRoutes++;
                }
            }
        }

        $schemaCoverage = $totalRoutes > 0 
            ? round((($routesWithRequestSchema + $routesWithResponseSchema) / ($totalRoutes * 2)) * 100, 1)
            : 0;

        return [
            'total_routes' => $totalRoutes,
            'routes_by_method' => $routesByMethod,
            'routes_by_version' => $routesByVersion,
            'routes_with_auth' => $routesWithAuth,
            'routes_without_auth' => $routesWithoutAuth,
            'routes_with_request_schema' => $routesWithRequestSchema,
            'routes_with_response_schema' => $routesWithResponseSchema,
            'routes_with_complete_schemas' => $routesWithCompleteSchemas,
            'schema_coverage' => $schemaCoverage,
            'deprecated_routes' => $deprecatedRoutes,
        ];
    }

    /**
     * Format method counts for display
     *
     * @param  array<string, int>  $counts
     */
    protected function formatMethodCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $method => $count) {
            $parts[] = "{$method}: {$count}";
        }
        return implode(', ', $parts);
    }

    /**
     * Format version counts for display
     *
     * @param  array<string, int>  $counts
     */
    protected function formatVersionCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $version => $count) {
            $parts[] = "{$version}: {$count}";
        }
        return implode(', ', $parts);
    }
}
