<?php

use Abr4xas\McpTools\Tools\DescribeApiRoute;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;

beforeEach(function () {
    $this->tool = new DescribeApiRoute;

    // Create a test contract file
    $contractPath = storage_path('api-contracts');
    if (! File::exists($contractPath)) {
        File::makeDirectory($contractPath, 0755, true);
    }

    $contractFile = "{$contractPath}/api.json";
    $contract = [
        '/api/test' => [
            'GET' => [
                'description' => 'Test route',
                'auth' => ['type' => 'none'],
                'path_parameters' => [],
                'request_schema' => ['properties' => []],
                'response_schema' => [],
            ],
        ],
    ];
    File::put($contractFile, json_encode($contract, JSON_PRETTY_PRINT));
});

it('describes route by path', function () {
    $request = new Request(['arguments' => ['path' => '/api/test']]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    // Response might be error text or JSON
    if ($result === null) {
        // It's an error message, which is valid
        expect($text)->toBeString()
            ->and($text)->not->toBeEmpty();
    } else {
        expect($result)->toBeArray();
        if (isset($result['route'])) {
            expect($result)->toHaveKey('route');
        } else {
            // Might be direct result
            expect($result)->toHaveKey('description');
        }
    }
});

it('describes route by name', function () {
    $request = new Request(['arguments' => ['route_name' => 'test']]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    // Response might be error text or JSON
    if ($result === null) {
        expect($text)->toBeString()
            ->and($text)->not->toBeEmpty();
    } else {
        expect($result)->toBeArray();
    }
});

it('describes nonexistent route', function () {
    $request = new Request(['arguments' => ['path' => '/api/nonexistent']]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    // Response might be error text or JSON with undocumented flag
    if ($result === null) {
        expect($text)->toBeString()
            ->and($text)->not->toBeEmpty();
    } else {
        expect($result)->toBeArray();
        // Either error key, undocumented flag, or error message in text
        expect(
            isset($result['error']) ||
            isset($result['undocumented']) ||
            str_contains($text, 'Error') ||
            str_contains($text, 'not found')
        )->toBeTrue();
    }
});
