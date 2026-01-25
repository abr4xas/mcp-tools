<?php

declare(strict_types=1);

namespace Tests\Unit;

use Abr4xas\McpTools\Analyzers\FormRequestAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Abr4xas\McpTools\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;

class FormRequestAnalyzerTest extends TestCase
{
    protected FormRequestAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $cacheService = new AnalysisCacheService;
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
        // Create a simple test class with a method
        $testClass = new class {
            /**
             * @param string $name
             * @param int $age
             */
            public function testMethod($name, $age)
            {
                return $name.$age;
            }
        };

        $reflection = new \ReflectionClass($testClass);
        $method = $reflection->getMethod('testMethod');

        $params = $this->analyzer->extractQueryParamsFromMethod($method);
        $this->assertIsArray($params);
    }
}
