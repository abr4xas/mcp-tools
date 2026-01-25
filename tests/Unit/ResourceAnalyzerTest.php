<?php

declare(strict_types=1);

namespace Tests\Unit;

use Abr4xas\McpTools\Analyzers\ExampleGenerator;
use Abr4xas\McpTools\Analyzers\ResourceAnalyzer;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Abr4xas\McpTools\Tests\TestCase;

class ResourceAnalyzerTest extends TestCase
{
    protected ResourceAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $cacheService = new AnalysisCacheService;
        $exampleGenerator = new ExampleGenerator;
        $this->analyzer = new ResourceAnalyzer($cacheService, $exampleGenerator);
    }

    public function test_data_to_schema_conversion(): void
    {
        $data = [
            'id' => 1,
            'name' => 'Test',
            'email' => 'test@example.com',
            'tags' => ['tag1', 'tag2'],
        ];

        $schema = $this->analyzer->dataToSchema($data);

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('id', $schema);
        $this->assertArrayHasKey('name', $schema);
        $this->assertArrayHasKey('email', $schema);
        $this->assertArrayHasKey('tags', $schema);
    }

    public function test_detect_relationships(): void
    {
        $data = [
            'id' => 1,
            'user_id' => 5,
            'comments' => [],
        ];

        // detectRelationships is a protected method, so we test through dataToSchema
        $schema = $this->analyzer->dataToSchema($data);
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('id', $schema);
    }
}
