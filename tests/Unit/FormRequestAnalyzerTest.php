<?php

use Abr4xas\McpTools\Analyzers\FormRequestAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;

beforeEach(function () {
    $cacheService = new AnalysisCacheService;
    $this->analyzer = new FormRequestAnalyzer($cacheService);
});

it('parses validation rules', function () {
    $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email',
        'age' => 'nullable|integer|min:18',
    ];

    $schema = $this->analyzer->parseValidationRules($rules);

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKeys(['name', 'email', 'age'])
        ->and($schema['name']['type'])->toBe('string')
        ->and($schema['name']['required'])->toBeTrue();
});

it('parses nested validation rules', function () {
    $rules = [
        'user.name' => 'required|string',
        'user.email' => 'required|email',
    ];

    $schema = $this->analyzer->parseValidationRules($rules);

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('user');
});

it('extracts query params from method', function () {
    // Create a simple test class with a method
    $testClass = new class
    {
        /**
         * @param  string  $name
         * @param  int  $age
         */
        public function testMethod($name, $age)
        {
            return $name.$age;
        }
    };

    $reflection = new ReflectionClass($testClass);
    $method = $reflection->getMethod('testMethod');

    $params = $this->analyzer->extractQueryParamsFromMethod($method);
    expect($params)->toBeArray();
});
