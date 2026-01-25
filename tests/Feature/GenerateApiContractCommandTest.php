<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Abr4xas\McpTools\Commands\GenerateApiContractCommand;

it('command generates API contract successfully', function () {
    // Simulate some API routes
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]))->name('api.v1.test');
    });

    $this->artisan(GenerateApiContractCommand::class)
        ->assertSuccessful();

    expect(File::exists(storage_path('api-contracts/api.json')))->toBeTrue();
});

it('command creates JSON file with valid structure', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn() => response()->json([]))->name('api.v1.test');
    });

    $this->artisan(GenerateApiContractCommand::class);

    $content = File::get(storage_path('api-contracts/api.json'));
    $json = json_decode($content, true);

    expect($json)->toBeArray();
    
    // The path is normalized with leading slash: /api/v1/test
    $keys = array_keys($json);
    expect($keys)->not->toBeEmpty();
    // Verify at least one key contains 'test' (the route we created)
    $hasTestRoute = false;
    foreach ($keys as $key) {
        if (str_contains($key, 'test')) {
            $hasTestRoute = true;
            break;
        }
    }
    expect($hasTestRoute)->toBeTrue();
});
