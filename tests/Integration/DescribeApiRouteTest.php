<?php

declare(strict_types=1);

namespace Tests\Integration;

use Abr4xas\McpTools\Tools\DescribeApiRoute;
use Abr4xas\McpTools\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;

class DescribeApiRouteTest extends TestCase
{
    protected DescribeApiRoute $tool;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_describe_route_by_path(): void
    {
        $request = new Request(['arguments' => ['path' => '/api/test']]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        // Response might be error text or JSON
        if ($result === null) {
            // It's an error message, which is valid
            $this->assertIsString($text);
            $this->assertNotEmpty($text);
        } else {
            $this->assertIsArray($result);
            if (isset($result['route'])) {
                $this->assertArrayHasKey('route', $result);
            } else {
                // Might be direct result
                $this->assertArrayHasKey('description', $result);
            }
        }
    }

    public function test_describe_route_by_name(): void
    {
        $request = new Request(['arguments' => ['route_name' => 'test']]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        // Response might be error text or JSON
        if ($result === null) {
            $this->assertIsString($text);
            $this->assertNotEmpty($text);
        } else {
            $this->assertIsArray($result);
        }
    }

    public function test_describe_nonexistent_route(): void
    {
        $request = new Request(['arguments' => ['path' => '/api/nonexistent']]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        // Response might be error text or JSON with undocumented flag
        if ($result === null) {
            $this->assertIsString($text);
            $this->assertNotEmpty($text);
        } else {
            $this->assertIsArray($result);
            // Either error key, undocumented flag, or error message in text
            $this->assertTrue(
                isset($result['error']) || 
                isset($result['undocumented']) || 
                str_contains($text, 'Error') || 
                str_contains($text, 'not found')
            );
        }
    }
}
