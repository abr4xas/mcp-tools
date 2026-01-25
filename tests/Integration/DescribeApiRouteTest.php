<?php

declare(strict_types=1);

namespace Tests\Integration;

use Abr4xas\McpTools\Tools\DescribeApiRoute;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\File;

class DescribeApiRouteTest extends TestCase
{
    protected DescribeApiRoute $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new DescribeApiRoute();

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
    }

    public function test_describe_route_by_path(): void
    {
        $result = $this->tool->handle([
            'path' => '/api/test',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('route', $result);
    }

    public function test_describe_route_by_name(): void
    {
        $result = $this->tool->handle([
            'route_name' => 'test',
        ]);

        $this->assertIsArray($result);
    }

    public function test_describe_nonexistent_route(): void
    {
        $result = $this->tool->handle([
            'path' => '/api/nonexistent',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }
}
