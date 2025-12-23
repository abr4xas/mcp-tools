<?php

namespace Abr4xas\McpTools;

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
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('mcp-tools')
            ->hasCommand(GenerateApiContractCommand::class)
            ->hasConfigFile();
    }
}
