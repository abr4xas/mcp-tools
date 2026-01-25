<?php

declare(strict_types=1);

namespace Tests\Unit;

use Abr4xas\McpTools\Analyzers\FormRequestAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Http\FormRequest;

class FormRequestAnalyzerTest extends TestCase
{
    protected FormRequestAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $cacheService = new AnalysisCacheService();
        $this->analyzer = new FormRequestAnalyzer($cacheService);
    }

    public function test_parse_validation_rules(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
        ];

        $schema = $this->analyzer->parseValidationRules($rules);

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('name', $schema);
        $this->assertArrayHasKey('email', $schema);
        $this->assertArrayHasKey('age', $schema);
        $this->assertEquals('string', $schema['name']['type']);
        $this->assertTrue($schema['name']['required']);
    }

    public function test_parse_nested_validation_rules(): void
    {
        $rules = [
            'user.name' => 'required|string',
            'user.email' => 'required|email',
        ];

        $schema = $this->analyzer->parseValidationRules($rules);

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('user', $schema);
    }

    public function test_extract_query_params_from_method(): void
    {
        $reflection = new \ReflectionClass(FormRequest::class);
        $method = $reflection->getMethod('authorize');

        $params = $this->analyzer->extractQueryParamsFromMethod($method);
        $this->assertIsArray($params);
    }
}
