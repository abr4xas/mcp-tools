<?php

namespace Abr4xas\McpTools;

use Abr4xas\McpTools\Commands\ClearCacheCommand;
use Abr4xas\McpTools\Commands\ContractVersionCommand;
use Abr4xas\McpTools\Commands\ExportOpenApiCommand;
use Abr4xas\McpTools\Commands\GenerateApiContractCommand;
use Abr4xas\McpTools\Commands\HealthCheckCommand;
use Abr4xas\McpTools\Commands\MetricsCommand;
use Abr4xas\McpTools\Commands\ViewLogsCommand;
use Illuminate\Support\Facades\File;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class McpToolsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/spatie/laravel-package-tools
         */
        $package
            ->name('mcp-tools')
            ->hasCommand(GenerateApiContractCommand::class)
            ->hasCommand(ClearCacheCommand::class)
            ->hasCommand(ExportOpenApiCommand::class)
            ->hasCommand(ContractVersionCommand::class)
            ->hasCommand(HealthCheckCommand::class)
            ->hasCommand(MetricsCommand::class)
            ->hasCommand(ViewLogsCommand::class);

        // Validate configuration on boot
        $this->validateConfiguration();
    }

    /**
     * Validate package configuration
     */
    protected function validateConfiguration(): void
    {
        $contractPath = storage_path('api-contracts');
        $contractFile = "{$contractPath}/api.json";

        // Check if contract directory is writable
        if (File::exists($contractPath) && ! is_writable($contractPath)) {
            \Log::warning('MCP Tools: Contract directory is not writable', ['path' => $contractPath]);
        }

        // Validate contract file if it exists
        if (File::exists($contractFile)) {
            $content = File::get($contractFile);
            $contract = json_decode($content, true);
            if (! is_array($contract)) {
                \Log::warning('MCP Tools: Contract file is not valid JSON', ['path' => $contractFile]);
            }
        }

        // Check required directories
        $requiredDirs = [
            app_path('Http/Controllers'),
            app_path('Http/Resources'),
        ];

        foreach ($requiredDirs as $dir) {
            if (! File::exists($dir)) {
                \Log::warning('MCP Tools: Required directory does not exist', ['path' => $dir]);
            }
        }
    }
}
