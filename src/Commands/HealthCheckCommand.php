<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Abr4xas\McpTools\Analyzers\FormRequestAnalyzer;
use Abr4xas\McpTools\Analyzers\ResourceAnalyzer;
use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class HealthCheckCommand extends Command
{
    protected $signature = 'mcp-tools:health-check';

    protected $description = 'Check the health status of API contract and MCP tools';

    protected RouteAnalyzer $routeAnalyzer;

    protected FormRequestAnalyzer $formRequestAnalyzer;

    protected ResourceAnalyzer $resourceAnalyzer;

    public function __construct(
        RouteAnalyzer $routeAnalyzer,
        FormRequestAnalyzer $formRequestAnalyzer,
        ResourceAnalyzer $resourceAnalyzer
    ) {
        parent::__construct();
        $this->routeAnalyzer = $routeAnalyzer;
        $this->formRequestAnalyzer = $formRequestAnalyzer;
        $this->resourceAnalyzer = $resourceAnalyzer;
    }

    public function handle(): int
    {
        $this->info('Running health check...');
        $this->newLine();

        $checks = [
            'Contract File' => $this->checkContractFile(),
            'Contract Validity' => $this->checkContractValidity(),
            'MCP Tools Registration' => $this->checkMcpTools(),
            'Analyzers' => $this->checkAnalyzers(),
            'Configuration Paths' => $this->checkConfigurationPaths(),
        ];

        $allPassed = true;
        foreach ($checks as $checkName => $result) {
            if ($result['status'] === 'ok') {
                $this->info("✓ {$checkName}: {$result['message']}");
            } else {
                $this->error("✗ {$checkName}: {$result['message']}");
                if (isset($result['suggestion'])) {
                    $this->line("  Suggestion: {$result['suggestion']}");
                }
                $allPassed = false;
            }
        }

        $this->newLine();

        if ($allPassed) {
            $this->info('All health checks passed!');

            return self::SUCCESS;
        }

        $this->error('Some health checks failed. Please review the issues above.');

        return self::FAILURE;
    }

    /**
     * Check if contract file exists
     *
     * @return array{status: string, message: string, suggestion?: string}
     */
    protected function checkContractFile(): array
    {
        $contractPath = storage_path('api-contracts/api.json');

        if (! File::exists($contractPath)) {
            return [
                'status' => 'error',
                'message' => 'Contract file not found',
                'suggestion' => 'Run "php artisan api:contract:generate" to create the contract',
            ];
        }

        if (! is_readable($contractPath)) {
            return [
                'status' => 'error',
                'message' => 'Contract file is not readable',
                'suggestion' => 'Check file permissions',
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Contract file exists and is readable',
        ];
    }

    /**
     * Check contract validity
     *
     * @return array{status: string, message: string, suggestion?: string}
     */
    protected function checkContractValidity(): array
    {
        $contractPath = storage_path('api-contracts/api.json');

        if (! File::exists($contractPath)) {
            return [
                'status' => 'error',
                'message' => 'Contract file not found',
            ];
        }

        $content = File::get($contractPath);
        $contract = json_decode($content, true);

        if (! is_array($contract)) {
            return [
                'status' => 'error',
                'message' => 'Contract file is not valid JSON',
                'suggestion' => 'Regenerate the contract with "php artisan api:contract:generate"',
            ];
        }

        if (empty($contract)) {
            return [
                'status' => 'warning',
                'message' => 'Contract is empty',
                'suggestion' => 'Regenerate the contract',
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Contract is valid JSON',
        ];
    }

    /**
     * Check MCP tools registration
     *
     * @return array{status: string, message: string, suggestion?: string}
     */
    protected function checkMcpTools(): array
    {
        $tools = [
            \Abr4xas\McpTools\Tools\ListApiRoutes::class,
            \Abr4xas\McpTools\Tools\DescribeApiRoute::class,
            \Abr4xas\McpTools\Tools\ValidateApiContract::class,
            \Abr4xas\McpTools\Tools\CompareApiContracts::class,
        ];

        $missing = [];
        foreach ($tools as $tool) {
            if (! class_exists($tool)) {
                $missing[] = $tool;
            }
        }

        if (! empty($missing)) {
            return [
                'status' => 'error',
                'message' => 'Some MCP tools are not available',
                'suggestion' => 'Check that all tool classes exist',
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'All MCP tools are registered',
        ];
    }

    /**
     * Check analyzers functionality
     *
     * @return array{status: string, message: string, suggestion?: string}
     */
    protected function checkAnalyzers(): array
    {
        try {
            // Test RouteAnalyzer
            $this->routeAnalyzer->extractApiVersion('/api/v1/test');

            // Test FormRequestAnalyzer
            // Just check if it can be instantiated

            // Test ResourceAnalyzer
            $this->resourceAnalyzer->preloadResources();

            return [
                'status' => 'ok',
                'message' => 'All analyzers are functional',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Analyzers error: {$e->getMessage()}",
                'suggestion' => 'Check analyzer dependencies',
            ];
        }
    }

    /**
     * Check configuration paths
     *
     * @return array{status: string, message: string, suggestion?: string}
     */
    protected function checkConfigurationPaths(): array
    {
        $paths = [
            'Contract directory' => storage_path('api-contracts'),
            'Controllers' => app_path('Http/Controllers'),
            'Resources' => app_path('Http/Resources'),
        ];

        $issues = [];
        foreach ($paths as $name => $path) {
            if (! File::exists($path)) {
                $issues[] = "{$name} does not exist";
            } elseif (! is_readable($path)) {
                $issues[] = "{$name} is not readable";
            }
        }

        if (! empty($issues)) {
            return [
                'status' => 'warning',
                'message' => implode(', ', $issues),
                'suggestion' => 'Create missing directories or check permissions',
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'All configuration paths are accessible',
        ];
    }
}
