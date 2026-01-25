<?php

declare(strict_types=1);

namespace Tests\Integration;

use Abr4xas\McpTools\Tools\ListApiRoutes;
use Abr4xas\McpTools\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;

class ListApiRoutesTest extends TestCase
{
    protected ListApiRoutes $tool;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_list_routes_without_filters(): void
    {
        $request = new Request(['arguments' => ['page' => 1, 'per_page' => 10]]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
        $this->assertArrayHasKey('pagination', $result);
    }

    public function test_list_routes_with_method_filter(): void
    {
        $request = new Request(['arguments' => ['method' => 'GET', 'page' => 1, 'per_page' => 10]]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
    }

    public function test_list_routes_with_search(): void
    {
        $request = new Request(['arguments' => ['search' => 'test', 'page' => 1, 'per_page' => 10]]);
        $response = $this->tool->handle($request);
        $text = $this->getResponseText($response);
        $result = json_decode($text, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
    }
}
