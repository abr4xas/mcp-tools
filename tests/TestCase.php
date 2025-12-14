<?php

namespace Abr4xas\McpTools\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Abr4xas\McpTools\McpToolsServiceProvider;

/**
 * @method void createSampleContract(?array $contract = null)
 * @method array getDefaultContract()
 * @method string getResponseText(\Laravel\Mcp\Response $response)
 * @property string $contractPath
 */
class TestCase extends Orchestra
{
    protected string $contractPath;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn(string $modelName) => 'Abr4xas\\McpTools\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        // Create temporary storage directory for test contracts
        $this->contractPath = storage_path('api-contracts');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($this->contractPath);
    }

    protected function getPackageProviders($app)
    {
        return [
            McpToolsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    protected function tearDown(): void
    {
        // Clean up test contract file (not entire directory)
        $contractFile = $this->contractPath . '/api.json';
        if (\Illuminate\Support\Facades\File::exists($contractFile)) {
            \Illuminate\Support\Facades\File::delete($contractFile);
        }

        parent::tearDown();
    }

    protected function createSampleContract(?array $contract = null): void
    {
        $contract = $contract ?? $this->getDefaultContract();

        \Illuminate\Support\Facades\File::put(
            $this->contractPath . '/api.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function getDefaultContract(): array
    {
        return [
            '/api/v1/posts' => [
                'GET' => [
                    'api_version' => 'v1',
                    'auth' => ['type' => 'bearer'],
                    'description' => 'List all posts',
                    'path_parameters' => [],
                ],
                'POST' => [
                    'api_version' => 'v1',
                    'auth' => ['type' => 'bearer'],
                    'description' => 'Create a new post',
                    'path_parameters' => [],
                ],
            ],
            '/api/v1/posts/{post}' => [
                'GET' => [
                    'api_version' => 'v1',
                    'auth' => ['type' => 'bearer'],
                    'description' => 'Get a specific post',
                    'path_parameters' => ['post' => ['type' => 'integer']],
                ],
                'DELETE' => [
                    'api_version' => 'v1',
                    'auth' => ['type' => 'bearer'],
                    'description' => 'Delete a specific post',
                    'path_parameters' => ['post' => ['type' => 'integer']],
                ],
            ],
            '/api/v2/users' => [
                'GET' => [
                    'api_version' => 'v2',
                    'auth' => ['type' => 'none'],
                    'description' => 'List all users',
                    'path_parameters' => [],
                ],
            ],
        ];
    }

    protected function getResponseText(\Laravel\Mcp\Response $response): string
    {
        return (string) $response->content();
    }
}
