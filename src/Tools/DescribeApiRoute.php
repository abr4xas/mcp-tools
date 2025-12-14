<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DescribeApiRoute extends Tool
{
    protected string $description = 'Get the public API contract for a specific route and method.';

    /** @var array<string, array> */
    protected static array $contractCache = [];

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

        $contract = $this->loadContract();
        if ($contract === null) {
            return Response::text("Error: Contract not found. Run 'php artisan api:contract:generate'.");
        }

        $routeData = $this->findRouteData($contract, $normalizedPath, $method);

        if ($routeData === null) {
            return Response::text(json_encode(['undocumented' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return Response::text(json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

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
     * Load contract from cache or file system
     *
     * @return array<string, array>|null
     */
    protected function loadContract(): ?array
    {
        $cacheKey = 'contract_api';

        if (isset(self::$contractCache[$cacheKey])) {
            return self::$contractCache[$cacheKey];
        }

        $fullPath = storage_path('api-contracts/api.json');

        if (! File::exists($fullPath)) {
            return null;
        }

        $content = File::get($fullPath);
        $contract = json_decode($content, true);

        if (! is_array($contract)) {
            return null;
        }

        self::$contractCache[$cacheKey] = $contract;

        return $contract;
    }

    /**
     * Find route data in contract, trying exact match first, then pattern matching
     *
     * @param  array<string, array>  $contract
     * @return array<string, mixed>|null
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
