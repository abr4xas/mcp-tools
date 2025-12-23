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

it('uses mocked analyzers when provided', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]));
    });

    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $routeAnalyzer->shouldReceive('extractPathParams')
        ->once()
        ->andReturn([]);
    $routeAnalyzer->shouldReceive('determineAuth')
        ->once()
        ->andReturn(['type' => 'none']);
    $routeAnalyzer->shouldReceive('extractCustomHeaders')
        ->once()
        ->andReturn([]);
    $routeAnalyzer->shouldReceive('extractRateLimit')
        ->once()
        ->andReturn(null);
    $routeAnalyzer->shouldReceive('extractApiVersion')
        ->once()
        ->andReturn('v1');

    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $formRequestAnalyzer->shouldReceive('extractRequestSchema')
        ->once()
        ->andReturn([]);

    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);
    $resourceAnalyzer->shouldReceive('preloadResources')
        ->once();
    $resourceAnalyzer->shouldReceive('extractResponseSchema')
        ->once()
        ->andReturn(['undocumented' => true]);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    $this->artisan($command)
        ->assertSuccessful();

    // Verify the contract was created
    expect(File::exists(storage_path('api-contracts/api.json')))->toBeTrue();
});

