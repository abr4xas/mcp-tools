<?php

namespace Abr4xas\McpTools\Commands;


use Abr4xas\McpTools\Contracts\FormRequestAnalyzerInterface;
use Abr4xas\McpTools\Contracts\ResourceAnalyzerInterface;
use Abr4xas\McpTools\Contracts\RouteAnalyzerInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * Generate API Contract Command
 *
 * Scans Laravel routes and generates a comprehensive JSON contract describing
 * all API endpoints, including authentication, request/response schemas, and metadata.
 *
 * @package Abr4xas\McpTools\Commands
 */
class GenerateApiContractCommand extends Command
{
    protected $signature = 'api:generate-contract
                            {--strict : Fail on critical errors instead of continuing}
                            {--detailed : Show detailed error messages}';

    protected $description = 'Generate a public-facing API contract for MCP consumption';

    protected RouteAnalyzerInterface $routeAnalyzer;

    protected FormRequestAnalyzerInterface $formRequestAnalyzer;

    protected ResourceAnalyzerInterface $resourceAnalyzer;

    /** @var int Count of warnings during generation */
    protected int $warningCount = 0;

    /** @var int Count of errors during generation */
    protected int $errorCount = 0;

    /**
     * Create a new command instance.
     *
     * @param RouteAnalyzerInterface|null $routeAnalyzer Optional route analyzer
     * @param FormRequestAnalyzerInterface|null $formRequestAnalyzer Optional form request analyzer
     * @param ResourceAnalyzerInterface|null $resourceAnalyzer Optional resource analyzer
     */
    public function __construct(
        ?RouteAnalyzerInterface $routeAnalyzer = null,
        ?FormRequestAnalyzerInterface $formRequestAnalyzer = null,
        ?ResourceAnalyzerInterface $resourceAnalyzer = null
    ) {
        parent::__construct();
        $this->routeAnalyzer = $routeAnalyzer ?? new \Abr4xas\McpTools\Analyzers\RouteAnalyzer();
        $this->formRequestAnalyzer = $formRequestAnalyzer ?? new \Abr4xas\McpTools\Analyzers\FormRequestAnalyzer();
        $this->resourceAnalyzer = $resourceAnalyzer ?? new \Abr4xas\McpTools\Analyzers\ResourceAnalyzer();
    }

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function handle(): int
    {
        $this->info('Generating API contract...');

        // Reset counters
        $this->warningCount = 0;
        $this->errorCount = 0;

        // Optimization: Pre-scan resources to avoid File scanning inside the loop
        $this->resourceAnalyzer->preloadResources();

        $routes = Route::getRoutes()->getRoutes();
        $contract = [];
        $queryMethods = ['GET', 'HEAD', 'DELETE'];

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! Str::startsWith($uri, 'api/')) {
                continue;
            }

            $normalizedUri = '/' . mb_ltrim($uri, '/');
            $methods = $route->methods();
            $action = $route->getAction('uses');

            foreach ($methods as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                if ($this->option('detailed')) {
                    $this->output->write("Processing {$method} {$normalizedUri} ... ");
                }

                $pathParams = $this->routeAnalyzer->extractPathParams($normalizedUri);
                $auth = $this->routeAnalyzer->determineAuth($route);
                $customHeaders = $this->routeAnalyzer->extractCustomHeaders($route);
                $rateLimit = $this->routeAnalyzer->extractRateLimit($route);
                $apiVersion = $this->routeAnalyzer->extractApiVersion($normalizedUri);

                $isQuery = in_array($method, $queryMethods, true);
                $requestSchema = $this->formRequestAnalyzer->extractRequestSchema(
                    $action,
                    $isQuery,
                    fn($msg, $suggestion = '') => $this->handleError($msg, $suggestion)
                );

                // Get reflection method for response schema analysis
                $reflection = null;
                if (is_string($action) && Str::contains($action, '@')) {
                    [$controller, $controllerMethod] = explode('@', $action);
                    try {
                        $reflection = new ReflectionMethod($controller, $controllerMethod);
                    } catch (\Throwable) {
                        // Ignore reflection errors
                    }
                }

                $responseSchema = $this->resourceAnalyzer->extractResponseSchema(
                    $action,
                    $normalizedUri,
                    $reflection,
                    fn($msg, $suggestion = '') => $this->handleError($msg, $suggestion)
                );

                $contract[$normalizedUri][$method] = [
                    'auth' => $auth,
                    'path_parameters' => $pathParams,
                    'request_schema' => $requestSchema,
                    'response_schema' => $responseSchema,
                    'custom_headers' => $customHeaders,
                    'rate_limit' => $rateLimit,
                    'api_version' => $apiVersion,
                ];

                if ($this->option('detailed')) {
                    $this->output->writeln('Done.');
                }
            }
        }

        $fullPath = $this->getContractPath();
        $directory = dirname($fullPath);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        $json = json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            $errorMsg = 'Failed to encode contract to JSON. Error: ' . json_last_error_msg();
            $this->error($errorMsg);
            $this->errorCount++;

            if ($this->option('strict')) {
                return self::FAILURE;
            }
        } else {
            File::put($fullPath, $json);
            $this->info("Contract generated at: {$fullPath}");
        }

        // Show summary
        $this->displaySummary();

        // Return appropriate exit code
        if ($this->option('strict') && ($this->errorCount > 0 || $this->warningCount > 0)) {
            return self::FAILURE;
        }

        if ($this->errorCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Handle errors and warnings with appropriate messaging
     */
    protected function handleError(string $message, string $suggestion = ''): void
    {
        $this->warningCount++;

        if ($this->option('detailed') && $suggestion) {
            $this->warn("{$message}. Suggestion: {$suggestion}");
        } else {
            $this->warn($message);
        }

        if ($this->option('strict')) {
            $this->errorCount++;
        }
    }

    /**
     * Display summary of warnings and errors at the end
     */
    protected function displaySummary(): void
    {
        $this->newLine();

        if ($this->warningCount > 0 || $this->errorCount > 0) {
            if ($this->errorCount > 0) {
                $this->error("Errors: {$this->errorCount}");
            }

            if ($this->warningCount > 0) {
                $this->warn("Warnings: {$this->warningCount}");
            }

            $this->newLine();

            if ($this->option('strict')) {
                $this->error('Strict mode enabled: Contract generation completed with errors/warnings.');
            } else {
                $this->info('Contract generation completed with warnings. Use --strict to fail on errors.');
            }
        } else {
            $this->info('Contract generation completed successfully with no warnings or errors.');
        }
    }

    /**
     * Get the contract file path from configuration
     */
    protected function getContractPath(): string
    {
        return Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));
    }

    /**
     * Get the resources directory path from configuration
     */
    protected function getResourcesPath(): string
    {
        return Config::get('mcp-tools.resources_path', app_path('Http/Resources'));
    }

    /**
     * Get the resources namespace from configuration
     */
    protected function getResourcesNamespace(): string
    {
        return Config::get('mcp-tools.resources_namespace', 'App\\Http\\Resources');
    }

    /**
     * Get the models namespace from configuration
     */
    protected function getModelsNamespace(): string
    {
        return Config::get('mcp-tools.models_namespace', 'App\\Models');
    }
}
