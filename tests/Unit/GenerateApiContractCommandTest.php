<?php

declare(strict_types=1);

use Abr4xas\McpTools\Commands\GenerateApiContractCommand;
use Abr4xas\McpTools\Contracts\FormRequestAnalyzerInterface;
use Abr4xas\McpTools\Contracts\ResourceAnalyzerInterface;
use Abr4xas\McpTools\Contracts\RouteAnalyzerInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('generates contract using mocked analyzers', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]));
    });

    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $routeAnalyzer->shouldReceive('extractPathParams')
        ->with('/api/v1/test')
        ->andReturn([]);
    $routeAnalyzer->shouldReceive('determineAuth')
        ->andReturn(['type' => 'bearer']);
    $routeAnalyzer->shouldReceive('extractCustomHeaders')
        ->andReturn([]);
    $routeAnalyzer->shouldReceive('extractRateLimit')
        ->andReturn(['name' => 'api', 'description' => '60 requests per minute']);
    $routeAnalyzer->shouldReceive('extractApiVersion')
        ->with('/api/v1/test')
        ->andReturn('v1');

    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $formRequestAnalyzer->shouldReceive('extractRequestSchema')
        ->andReturn(['location' => 'query', 'properties' => []]);

    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);
    $resourceAnalyzer->shouldReceive('preloadResources')
        ->once();
    $resourceAnalyzer->shouldReceive('extractResponseSchema')
        ->andReturn(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    $this->artisan($command)
        ->assertSuccessful();

    expect(File::exists(storage_path('api-contracts/api.json')))->toBeTrue();

    $content = File::get(storage_path('api-contracts/api.json'));
    $json = json_decode($content, true);

    expect($json)->toHaveKey('/api/v1/test')
        ->and($json['/api/v1/test']['GET']['auth'])->toBe(['type' => 'bearer'])
        ->and($json['/api/v1/test']['GET']['api_version'])->toBe('v1')
        ->and($json['/api/v1/test']['GET']['rate_limit'])->toBe(['name' => 'api', 'description' => '60 requests per minute']);
});

it('handles errors from analyzers gracefully', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]));
    });

    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $routeAnalyzer->shouldReceive('extractPathParams')->andReturn([]);
    $routeAnalyzer->shouldReceive('determineAuth')->andReturn(['type' => 'none']);
    $routeAnalyzer->shouldReceive('extractCustomHeaders')->andReturn([]);
    $routeAnalyzer->shouldReceive('extractRateLimit')->andReturn(null);
    $routeAnalyzer->shouldReceive('extractApiVersion')->andReturn(null);

    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $formRequestAnalyzer->shouldReceive('extractRequestSchema')
        ->andReturn([]);

    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);
    $resourceAnalyzer->shouldReceive('preloadResources')->once();
    $resourceAnalyzer->shouldReceive('extractResponseSchema')
        ->andReturn(['undocumented' => true]);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    $this->artisan($command)
        ->assertSuccessful();
});

it('returns failure code when strict mode is enabled and errors occur', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]));
    });

    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $routeAnalyzer->shouldReceive('extractPathParams')->andReturn([]);
    $routeAnalyzer->shouldReceive('determineAuth')->andReturn(['type' => 'none']);
    $routeAnalyzer->shouldReceive('extractCustomHeaders')->andReturn([]);
    $routeAnalyzer->shouldReceive('extractRateLimit')->andReturn(null);
    $routeAnalyzer->shouldReceive('extractApiVersion')->andReturn(null);

    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $formRequestAnalyzer->shouldReceive('extractRequestSchema')
        ->andReturn(['location' => 'unknown', 'properties' => [], 'error' => 'Could not instantiate FormRequest']);

    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);
    $resourceAnalyzer->shouldReceive('preloadResources')->once();
    $resourceAnalyzer->shouldReceive('extractResponseSchema')
        ->andReturn(['undocumented' => true]);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    $this->artisan($command, ['--strict' => true])
        ->assertFailed();
});

it('calls preloadResources on resource analyzer', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]));
    });

    $routeAnalyzer = Mockery::mock(RouteAnalyzerInterface::class);
    $routeAnalyzer->shouldReceive('extractPathParams')->andReturn([]);
    $routeAnalyzer->shouldReceive('determineAuth')->andReturn(['type' => 'none']);
    $routeAnalyzer->shouldReceive('extractCustomHeaders')->andReturn([]);
    $routeAnalyzer->shouldReceive('extractRateLimit')->andReturn(null);
    $routeAnalyzer->shouldReceive('extractApiVersion')->andReturn(null);

    $formRequestAnalyzer = Mockery::mock(FormRequestAnalyzerInterface::class);
    $formRequestAnalyzer->shouldReceive('extractRequestSchema')->andReturn([]);

    $resourceAnalyzer = Mockery::mock(ResourceAnalyzerInterface::class);
    $resourceAnalyzer->shouldReceive('preloadResources')
        ->once();
    $resourceAnalyzer->shouldReceive('extractResponseSchema')
        ->andReturn(['undocumented' => true]);

    $command = new GenerateApiContractCommand(
        $routeAnalyzer,
        $formRequestAnalyzer,
        $resourceAnalyzer
    );

    $this->artisan($command)
        ->assertSuccessful();
});
