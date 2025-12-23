<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Abr4xas\McpTools\Services\ContractLoader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Describe API Route Tool
 *
 * MCP tool that provides detailed information about a specific API route,
 * including authentication, schemas, and metadata.
 *
 * @package Abr4xas\McpTools\Tools
 */
class DescribeApiRoute extends Tool
{
    protected string $description = 'Get the public API contract for a specific route and method.';

    protected ContractLoader $contractLoader;

    /**
     * Create a new DescribeApiRoute instance.
     *
     * @param ContractLoader|null $contractLoader Optional contract loader instance
     */
    public function __construct(?ContractLoader $contractLoader = null)
    {
        $this->contractLoader = $contractLoader ?? new ContractLoader();
    }

    /**
     * Handle the MCP tool request.
     *
     * @param Request $request The MCP request with path and method
     * @return Response JSON response with route details or error message
     */
    public function handle(Request $request): Response
    {
        $path = $request->get('path');
        $method = mb_strtoupper((string) $request->get('method', 'GET'));

        if (! $path || ! is_string($path)) {
            return Response::text("Error: 'path' parameter is required and must be a string.");
        }

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            return Response::text("Error: Invalid HTTP method '{$method}'. Must be one of: GET, POST, PUT, PATCH, DELETE, OPTIONS.");
        }

        // Normalize path: ensure leading slash
        $normalizedPath = '/' . mb_ltrim($path, '/');

        $contract = $this->contractLoader->load();
        if ($contract === null) {
            $fullPath = $this->contractLoader->getContractPath();
            if (! File::exists($fullPath)) {
                return Response::text("Error: Contract not found. Run 'php artisan api:generate-contract'.");
            }

            return Response::text("Error: Contract file exists but has invalid structure. Please regenerate the contract with 'php artisan api:generate-contract'.");
        }

        $routeData = $this->findRouteData($contract, $normalizedPath, $method);

        if ($routeData === null) {
            return Response::text(json_encode(['undocumented' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return Response::text(json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Define the JSON schema for tool arguments.
     *
     * @param JsonSchema $schema The schema builder
     * @return array<string, mixed> Schema definition
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The API route path (e.g., /api/v1/posts or /api/v1/posts/{id})')
                ->required(),
            'method' => $schema->string()
                ->description('HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS)')
                ->enum(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
                ->default('GET'),
        ];
    }


    /**
     * Find route data in contract, trying exact match first, then pattern matching.
     *
     * First attempts an exact path match, then tries pattern matching for
     * dynamic routes with parameters.
     *
     * @param array<string, array<string, array<string, mixed>>> $contract The loaded contract
     * @param string $path The route path to find
     * @param string $method The HTTP method
     * @return array<string, mixed>|null Route data or null if not found
     */
    protected function findRouteData(array $contract, string $path, string $method): ?array
    {
        // Try exact match first
        if (isset($contract[$path][$method])) {
            $routeData = $contract[$path][$method];
            $routeData['matched_route'] = $path;

            return $routeData;
        }

        // Try pattern matching for routes with parameters
        foreach ($contract as $contractPath => $methods) {
            if ($contractPath === $path) {
                continue;
            }

            if ($this->matchesRoutePattern($contractPath, $path)) {
                $routeData = $methods[$method] ?? null;
                if ($routeData !== null) {
                    $routeData['matched_route'] = $contractPath;

                    return $routeData;
                }
            }
        }

        return null;
    }

    /**
     * Check if a concrete path matches a route pattern (e.g., /api/v1/posts/123 matches /api/v1/posts/{post})
     */
    protected function matchesRoutePattern(string $pattern, string $path): bool
    {
        // Escape special regex characters except our placeholders
        $escaped = preg_quote($pattern, '#');

        // Replace {param?} with optional segment (?:/[^/]+)?
        // We need to match \{param\?\} literal from preg_quote output.
        // Regex needs to match literal \ then literal ? So \\ \? -> \\\?
        // PHP string needs \\\\ \\? -> \\\\\\?
        $escaped = preg_replace('#\\\\\{([^}]+)\\\\\\?\}#', '(?:/[^/]+)?', $escaped);

        // Replace {param} with required segment [^/]+
        // Match \{param\} literal. Regex \\ \{ ... \\ \}
        $escaped = preg_replace('#\\\\\{([^}]+)\\\\}#', '[^/]+', (string) $escaped);

        // Handle case where {param} might be at start or have no leading slash in pattern?
        // Usually /api/v1/posts/{post} -> /api/v1/posts/[^/]+

        return (bool) preg_match('#^' . $escaped . '$#', $path);
    }
}
