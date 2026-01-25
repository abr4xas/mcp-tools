<?php

namespace Abr4xas\McpTools;

use Abr4xas\McpTools\Commands\ClearCacheCommand;
use Abr4xas\McpTools\Commands\ContractVersionCommand;
use Abr4xas\McpTools\Commands\ExportOpenApiCommand;
use Abr4xas\McpTools\Commands\GenerateApiContractCommand;
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
            ->hasCommand(ContractVersionCommand::class);
    }
}
