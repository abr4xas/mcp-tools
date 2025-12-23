<?php

declare(strict_types=1);

use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Illuminate\Routing\Route;

it('extracts path parameters from route URI', function () {
    $analyzer = new RouteAnalyzer;

    expect($analyzer->extractPathParams('/api/v1/posts/{id}'))
        ->toBe(['id']);

    expect($analyzer->extractPathParams('/api/v1/users/{userId}/posts/{postId}'))
        ->toBe(['userId', 'postId']);

    expect($analyzer->extractPathParams('/api/v1/posts'))
        ->toBe([]);
});

it('extracts optional path parameters', function () {
    $analyzer = new RouteAnalyzer;

    expect($analyzer->extractPathParams('/api/v1/posts/{id?}'))
        ->toBe(['id']);
});

it('determines authentication type from middleware', function () {
    $analyzer = new RouteAnalyzer;

    $route = Mockery::mock(Route::class);
    $route->shouldReceive('gatherMiddleware')
        ->andReturn(['auth:sanctum']);

    $auth = $analyzer->determineAuth($route);

    expect($auth)->toBe(['type' => 'bearer']);
});

it('returns none auth when no auth middleware', function () {
    $analyzer = new RouteAnalyzer;

    $route = Mockery::mock(Route::class);
    $route->shouldReceive('gatherMiddleware')
        ->andReturn([]);

    $auth = $analyzer->determineAuth($route);

    expect($auth)->toBe(['type' => 'none']);
});

it('extracts API version from URI', function () {
    $analyzer = new RouteAnalyzer;

    expect($analyzer->extractApiVersion('/api/v1/posts'))
        ->toBe('v1');

    expect($analyzer->extractApiVersion('/api/v2/users'))
        ->toBe('v2');

    expect($analyzer->extractApiVersion('/api/v3/posts/{id}'))
        ->toBe('v3');

    expect($analyzer->extractApiVersion('/posts'))
        ->toBeNull();
});

it('extracts rate limit from throttle middleware', function () {
    $analyzer = new RouteAnalyzer;

    $route = Mockery::mock(Route::class);
    $route->shouldReceive('gatherMiddleware')
        ->andReturn(['throttle:api']);

    $rateLimit = $analyzer->extractRateLimit($route);

    expect($rateLimit)->toBe([
        'name' => 'api',
        'description' => '60 requests per minute',
    ]);
});

it('returns null when no rate limit middleware', function () {
    $analyzer = new RouteAnalyzer;

    $route = Mockery::mock(Route::class);
    $route->shouldReceive('gatherMiddleware')
        ->andReturn([]);

    $rateLimit = $analyzer->extractRateLimit($route);

    expect($rateLimit)->toBeNull();
});
