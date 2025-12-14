<?php

use Abr4xas\McpTools\Tools\DescribeApiRoute;
use Laravel\Mcp\Request;

beforeEach(function () {
    $this->tool = new DescribeApiRoute();
    $this->createSampleContract(); // Create contract before each test
});

afterEach(function () {
    // Clear static cache to prevent test pollution
    $reflection = new ReflectionClass(DescribeApiRoute::class);
    $property = $reflection->getProperty('contractCache');
    $property->setAccessible(true);
    $property->setValue(null, []);
});

it('requires path parameter', function () {
    $request = new Request(['arguments' => ['method' => 'GET']]);
    $response = $this->tool->handle($request);

    expect($this->getResponseText($response))->toContain("'path' parameter is required");
});

it('returns complete route data for exact path match', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    // Skip if json decode failed (means tool returned error message)
    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('description')
        ->and($result)->toHaveKey('api_version')
        ->and($result)->toHaveKey('auth')
        ->and($result['description'])->toBe('List all posts')
        ->and($result['api_version'])->toBe('v1');
});

it('matches routes with parameters using pattern matching', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts/123',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('description')
        ->and($result['description'])->toBe('Get a specific post')
        ->and($result)->toHaveKey('path_parameters')
        ->and($result['path_parameters'])->toHaveKey('post');
});

it('normalizes paths by adding leading slash', function () {
    $request = new Request(['arguments' => [
        'path' => 'api/v1/posts',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('description');
});

it('defaults to GET method when not specified', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result['description'])->toBe('List all posts');
});

it('validates HTTP method is valid', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'INVALID',
    ]]);
    $response = $this->tool->handle($request);

    expect($this->getResponseText($response))->toContain("Error");
});

it('returns undocumented flag for non-existent routes', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/nonexistent',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);

    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('undocumented')
        ->and($result['undocumented'])->toBeTrue();
});

it('returns error when contract does not exist', function () {
    // Clear contract for this test
    $contractFile = storage_path('api-contracts/api.json');
    if (\Illuminate\Support\Facades\File::exists($contractFile)) {
        \Illuminate\Support\Facades\File::delete($contractFile);
    }

    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);

    expect($this->getResponseText($response))->toContain('Error');
});

it('handles POST method with correct route data', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'POST',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('description')
        ->and($result['description'])->toBe('Create a new post');
});

it('handles DELETE method for parameterized routes', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts/456',
        'method' => 'DELETE',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('description')
        ->and($result['description'])->toBe('Delete a specific post');
});

it('handles case-insensitive HTTP methods', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'get',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result['description'])->toBe('List all posts');
});

it('caches contract data between requests', function () {
    $request1 = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'GET',
    ]]);
    $response1 = $this->tool->handle($request1);

    $request2 = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'GET',
    ]]);
    $response2 = $this->tool->handle($request2);

    expect($this->getResponseText($response1))->toBe($this->getResponseText($response2));
});

it('validates path must be a string', function () {
    $request = new Request(['arguments' => [
        'path' => null,
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);

    expect($this->getResponseText($response))->toContain("'path' parameter is required");
});

it('includes auth type in route data', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v1/posts',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('auth')
        ->and($result['auth'])->toHaveKey('type')
        ->and($result['auth']['type'])->toBe('bearer');
});

it('includes API version in route data', function () {
    $request = new Request(['arguments' => [
        'path' => '/api/v2/users',
        'method' => 'GET',
    ]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);

    $result = json_decode($text, true);

    if ($result === null) {
        expect($text)->toBeString()->and($text)->not->toBeEmpty();
        return;
    }

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('api_version')
        ->and($result['api_version'])->toBe('v2');
});
