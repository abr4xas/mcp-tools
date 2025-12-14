<?php

use Abr4xas\McpTools\Commands\GenerateApiContractCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('command generates API contract successfully', function () {
    // Simulate some API routes
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn () => response()->json([]))->name('api.v1.test');
    });

    $this->artisan(GenerateApiContractCommand::class)
        ->assertSuccessful();

    expect(File::exists(storage_path('api-contracts/api.json')))->toBeTrue();
});

it('command creates JSON file with valid structure', function () {
    Route::group(['prefix' => 'api/v1'], function () {
        Route::get('/test', fn () => response()->json([]))->name('api.v1.test');
    });

    $this->artisan(GenerateApiContractCommand::class);

    $content = File::get(storage_path('api-contracts/api.json'));
    $json = json_decode($content, true);

    expect($json)->toBeArray()
        ->and($json)->toHaveKey('/api/v1/test');
});
