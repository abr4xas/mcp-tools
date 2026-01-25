<?php

declare(strict_types=1);

namespace Tests\Integration;

use Abr4xas\McpTools\Tools\ListApiRoutes;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

class ListApiRoutesTest extends TestCase
{
    protected ListApiRoutes $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ListApiRoutes();

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
        $result = $this->tool->handle([
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
        $this->assertArrayHasKey('pagination', $result);
    }

    public function test_list_routes_with_method_filter(): void
    {
        $result = $this->tool->handle([
            'method' => 'GET',
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
    }

    public function test_list_routes_with_search(): void
    {
        $result = $this->tool->handle([
            'search' => 'test',
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('routes', $result);
    }
}
