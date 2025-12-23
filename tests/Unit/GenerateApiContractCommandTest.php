<?php

declare(strict_types=1);

use Abr4xas\McpTools\Commands\GenerateApiContractCommand;
use Abr4xas\McpTools\Contracts\FormRequestAnalyzerInterface;
use Abr4xas\McpTools\Contracts\ResourceAnalyzerInterface;
use Abr4xas\McpTools\Contracts\RouteAnalyzerInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('can be instantiated with custom analyzers for testing', function () {
    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    expect($command)->toBeInstanceOf(GenerateApiContractCommand::class);
});

it('demonstrates how interfaces enable dependency injection for testing', function () {
    // This test demonstrates that the command can accept interfaces
    // In a real scenario, you would use Laravel's service container
    // to bind the interfaces to implementations or mocks
    
    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);

    // The command can now be instantiated with mocks
    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    // This allows for isolated unit testing without needing
    // to set up actual routes, files, or dependencies
    expect($command)->toBeInstanceOf(GenerateApiContractCommand::class);
    
    // In a real test, you would configure the mocks and test
    // the command's behavior in isolation
});

