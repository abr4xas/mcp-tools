<?php

use Abr4xas\McpTools\Analyzers\MiddlewareAnalyzer;
use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $cacheService = new AnalysisCacheService;
    $middlewareAnalyzer = new MiddlewareAnalyzer;
    $this->analyzer = new RouteAnalyzer($cacheService, $middlewareAnalyzer);
});

it('extracts path params from route', function () {
    Route::get('/api/users/{id}', function () {
        return response()->json([]);
    })->name('users.show');

    // Force route compilation
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $routes = Route::getRoutes();
    $route = $routes->getByName('users.show');

    expect($route)->not->toBeNull('Route should be registered and findable');
    $params = $this->analyzer->extractPathParams($route->uri(), 'GET');
    expect($params)->toBeArray()
        ->and($params)->toHaveKey('id');
});

it('determines auth type', function () {
    Route::middleware('auth:sanctum')->get('/api/protected', function () {
        return response()->json([]);
    })->name('protected');

    // Force route compilation
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $routes = Route::getRoutes();
    $route = $routes->getByName('protected');

    expect($route)->not->toBeNull('Route should be registered and findable');
    $auth = $this->analyzer->determineAuth($route);
    expect($auth)->toBeArray()
        ->and($auth)->toHaveKey('type');
});

it('extracts rate limit', function () {
    Route::middleware('throttle:60,1')->get('/api/limited', function () {
        return response()->json([]);
    })->name('limited');

    // Force route compilation
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $routes = Route::getRoutes();
    $route = $routes->getByName('limited');

    expect($route)->not->toBeNull('Route should be registered and findable');
    $rateLimit = $this->analyzer->extractRateLimit($route);
    expect($rateLimit)->toBeArray();
});

it('extracts api version', function () {
    Route::prefix('api/v1')->get('/test', function () {
        return response()->json([]);
    })->name('v1.test');

    // Force route compilation
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $routes = Route::getRoutes();
    $route = $routes->getByName('v1.test');

    expect($route)->not->toBeNull('Route should be registered and findable');

    // Get the full URI path (with prefix)
    $uri = $route->uri();
    // Normalize to include leading slash if needed
    $normalizedUri = '/'.ltrim($uri, '/');

    $version = $this->analyzer->extractApiVersion($normalizedUri);
    expect($version)->not->toBeNull("Version should be extracted from URI: {$normalizedUri}")
        ->and($version)->toBe('v1');
});
