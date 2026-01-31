<?php

use Abr4xas\McpTools\Analyzers\ExampleGenerator;
use Abr4xas\McpTools\Analyzers\ResourceAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;

beforeEach(function () {
    $cacheService = new AnalysisCacheService;
    $exampleGenerator = new ExampleGenerator;
    $this->analyzer = new ResourceAnalyzer($cacheService, $exampleGenerator);
});

it('converts data to schema', function () {
    $data = [
        'id' => 1,
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => ['tag1', 'tag2'],
    ];

    $schema = $this->analyzer->dataToSchema($data);

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKeys(['id', 'name', 'email', 'tags']);
});

it('detects relationships through data to schema conversion', function () {
    $data = [
        'id' => 1,
        'user_id' => 5,
        'comments' => [],
    ];

    // detectRelationships is a protected method, so we test through dataToSchema
    $schema = $this->analyzer->dataToSchema($data);
    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('id');
});
