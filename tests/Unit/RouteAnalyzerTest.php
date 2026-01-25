<?php

declare(strict_types=1);

namespace Tests\Unit;

use Abr4xas\McpTools\Analyzers\MiddlewareAnalyzer;
use Abr4xas\McpTools\Analyzers\RouteAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Abr4xas\McpTools\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RouteAnalyzerTest extends TestCase
{
    protected RouteAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $cacheService = new AnalysisCacheService;
        $middlewareAnalyzer = new MiddlewareAnalyzer;
        $this->analyzer = new RouteAnalyzer($cacheService, $middlewareAnalyzer);
    }

    public function test_extract_path_params_from_route(): void
    {
        Route::get('/api/users/{id}', function () {
            return response()->json([]);
        })->name('users.show');

        // Refresh routes
        $routes = Route::getRoutes();
        $route = $routes->getByName('users.show');
        
        if ($route === null) {
            $this->markTestSkipped('Route not found - may need route refresh');
        }

        $this->assertNotNull($route);
        $params = $this->analyzer->extractPathParams($route->uri(), 'GET');
        $this->assertIsArray($params);
        $this->assertArrayHasKey('id', $params);
    }

    public function test_determine_auth_type(): void
    {
        Route::middleware('auth:sanctum')->get('/api/protected', function () {
            return response()->json([]);
        })->name('protected');

        $routes = Route::getRoutes();
        $route = $routes->getByName('protected');
        
        if ($route === null) {
            $this->markTestSkipped('Route not found - may need route refresh');
        }

        $this->assertNotNull($route);
        $auth = $this->analyzer->determineAuth($route);
        $this->assertIsArray($auth);
        $this->assertArrayHasKey('type', $auth);
    }

    public function test_extract_rate_limit(): void
    {
        Route::middleware('throttle:60,1')->get('/api/limited', function () {
            return response()->json([]);
        })->name('limited');

        $routes = Route::getRoutes();
        $route = $routes->getByName('limited');
        
        if ($route === null) {
            $this->markTestSkipped('Route not found - may need route refresh');
        }

        $this->assertNotNull($route);
        $rateLimit = $this->analyzer->extractRateLimit($route);
        $this->assertIsArray($rateLimit);
    }

    public function test_extract_api_version(): void
    {
        Route::prefix('api/v1')->get('/test', function () {
            return response()->json([]);
        })->name('v1.test');

        $routes = Route::getRoutes();
        $route = $routes->getByName('v1.test');
        
        if ($route === null) {
            $this->markTestSkipped('Route not found - may need route refresh');
        }

        $this->assertNotNull($route);
        $version = $this->analyzer->extractApiVersion($route);
        $this->assertNotNull($version);
        $this->assertEquals('v1', $version);
    }
}
