<?php

use Abr4xas\McpTools\Tools\ListApiRoutes;
use Laravel\Mcp\Request;

beforeEach(function () {
    $this->tool = new ListApiRoutes;
    $this->createSampleContract();
});

afterEach(function () {
    // Clear static cache to prevent test pollution
    $reflection = new ReflectionClass(ListApiRoutes::class);
    $property = $reflection->getProperty('contractCache');
    $property->setAccessible(true);
    $property->setValue(null, []);
});

it('lists all routes without filters', function () {
    $request = new Request(['arguments' => []]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKeys(['total', 'limit', 'routes'])
        ->and($result['total'])->toBeGreaterThan(0)
        ->and($result['routes'])->toBeArray();
});

it('filters routes by HTTP method', function () {
    $request = new Request(['arguments' => ['method' => 'GET']]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKey('routes')
        ->and($result['routes'])->toBeArray();
});

it('filters routes by API version', function () {
    $request = new Request(['arguments' => ['version' => 'v1']]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKey('routes')
        ->and($result['routes'])->toBeArray();
});

it('searches routes by path term', function () {
    $request = new Request(['arguments' => ['search' => 'posts']]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKey('routes')
        ->and($result['routes'])->toBeArray();
});

it('combines filters', function () {
    $request = new Request(['arguments' => [
        'method' => 'GET',
        'version' => 'v1',
        'search' => 'api',
    ]]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKeys(['total', 'limit', 'routes']);
});

it('applies limit parameter', function () {
    $request = new Request(['arguments' => ['limit' => 2]]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result)->toHaveKey('limit');
});

it('caches contract data in memory', function () {
    $request1 = new Request(['arguments' => []]);
    $response1 = $this->tool->handle($request1);

    $request2 = new Request(['arguments' => []]);
    $response2 = $this->tool->handle($request2);

    expect($this->getResponseText($response1))->toBe($this->getResponseText($response2));
});

it('sorts routes by path', function () {
    $request = new Request(['arguments' => []]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);
    $paths = array_column($result['routes'], 'path');

    $sortedPaths = $paths;
    sort($sortedPaths);

    expect($paths)->toBe($sortedPaths);
});

it('returns JSON format response', function () {
    $request = new Request(['arguments' => []]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    expect(json_decode($text, true))->toBeArray();
});

it('includes route metadata', function () {
    $request = new Request(['arguments' => []]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    foreach ($result['routes'] as $route) {
        expect($route)->toHaveKeys(['path', 'method', 'auth']);
    }
});

it('handles empty search matches', function () {
    $request = new Request(['arguments' => ['search' => 'nonexistent']]);
    $response = $this->tool->handle($request);

    $result = json_decode($this->getResponseText($response), true);

    expect($result['routes'])->toBeArray();
});
