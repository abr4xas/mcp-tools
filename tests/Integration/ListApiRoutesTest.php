<?php

use Abr4xas\McpTools\Tools\ListApiRoutes;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;

beforeEach(function () {
    $this->tool = new ListApiRoutes;

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
            ],
        ],
    ];
    File::put($contractFile, json_encode($contract, JSON_PRETTY_PRINT));
});

it('lists routes without filters', function () {
    $request = new Request(['arguments' => ['page' => 1, 'per_page' => 10]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    expect($result)->toBeArray()
        ->and($result)->toHaveKeys(['routes', 'pagination']);
});

it('lists routes with method filter', function () {
    $request = new Request(['arguments' => ['method' => 'GET', 'page' => 1, 'per_page' => 10]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('routes');
});

it('lists routes with search', function () {
    $request = new Request(['arguments' => ['search' => 'test', 'page' => 1, 'per_page' => 10]]);
    $response = $this->tool->handle($request);
    $text = $this->getResponseText($response);
    $result = json_decode($text, true);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('routes');
});
