<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Exceptions\RouteAnalysisException;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionMethod;

class RouteAnalyzer
{
    protected AnalysisCacheService $cacheService;

    /** @var array<string, ReflectionMethod> */
    protected array $reflectionCache = [];

    public function __construct(AnalysisCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function extractPathParams(string $uri): array
    {
        $cacheKey = 'path_params:' . $uri;
        if ($this->cacheService->has('route', $cacheKey)) {
            return $this->cacheService->get('route', $cacheKey);
        }

        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        $params = [];
        foreach ($matches[1] as $param) {
            $params[$param] = [
                'type' => 'string', // Default type, can be improved with route model binding detection
            ];
        }

        $this->cacheService->put('route', $cacheKey, $params);

        return $params;
    }

    public function determineAuth(Route $route): array
    {
        $routeName = $route->getName() ?? $route->uri();
        $cacheKey = 'auth:' . $routeName;
        if ($this->cacheService->has('route', $cacheKey)) {
            return $this->cacheService->get('route', $cacheKey);
        }

        $middlewares = $route->gatherMiddleware();
        $auth = ['type' => 'none'];

        foreach ($middlewares as $mw) {
            if (is_string($mw)) {
                // Laravel Sanctum / API Token
                if (Str::contains($mw, 'auth:sanctum') || Str::contains($mw, 'auth:api')) {
                    $auth = ['type' => 'bearer', 'scheme' => 'Bearer'];

                    break;
                }

                // Laravel Passport
                if (Str::contains($mw, 'auth:api') && class_exists('Laravel\Passport\Passport')) {
                    $auth = ['type' => 'oauth2', 'scheme' => 'Bearer', 'provider' => 'passport'];

                    break;
                }

                // JWT (tymon/jwt-auth)
                if (Str::contains($mw, 'jwt') || Str::contains($mw, 'jwt.auth')) {
                    $auth = ['type' => 'bearer', 'scheme' => 'Bearer', 'provider' => 'jwt'];

                    break;
                }

                // API Key detection (common patterns)
                if (Str::contains($mw, 'api.key') || Str::contains($mw, 'apikey') || Str::contains($mw, 'api-key')) {
                    $auth = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'];

                    break;
                }

                // OAuth2 (generic)
                if (Str::contains($mw, 'oauth') || Str::contains($mw, 'oauth2')) {
                    $auth = ['type' => 'oauth2', 'scheme' => 'Bearer'];

                    break;
                }

                // Basic Auth
                if (Str::contains($mw, 'auth.basic')) {
                    $auth = ['type' => 'basic', 'scheme' => 'Basic'];

                    break;
                }

                // Guest (explicit no auth)
                if (Str::contains($mw, 'guest')) {
                    $auth = ['type' => 'none'];

                    break;
                }
            } elseif (is_object($mw)) {
                // Check middleware class name for custom authentication
                $middlewareClass = get_class($mw);
                if (Str::contains($middlewareClass, 'Passport')) {
                    $auth = ['type' => 'oauth2', 'scheme' => 'Bearer', 'provider' => 'passport'];

                    break;
                }
                if (Str::contains($middlewareClass, 'Jwt') || Str::contains($middlewareClass, 'JWT')) {
                    $auth = ['type' => 'bearer', 'scheme' => 'Bearer', 'provider' => 'jwt'];

                    break;
                }
                if (Str::contains($middlewareClass, 'ApiKey') || Str::contains($middlewareClass, 'ApiKey')) {
                    $auth = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'];

                    break;
                }
            }
        }

        $this->cacheService->put('route', $cacheKey, $auth);

        return $auth;
    }

    public function extractApiVersion(string $uri): ?string
    {
        if (preg_match('#/api/(v\d+)/#', $uri, $matches)) {
            return $matches[1];
        }

        if (Str::startsWith($uri, '/api/v1/')) {
            return 'v1';
        }

        if (Str::startsWith($uri, '/api/v2/')) {
            return 'v2';
        }

        return null;
    }

    public function extractRateLimit(Route $route): ?array
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, 'throttle:')) {
                $throttleName = Str::after($middleware, 'throttle:');

                // Common rate limit names and their descriptions for frontend
                $rateLimitDescriptions = [
                    'api' => '60 requests per minute',
                    'webhook' => '5000 requests per minute',
                    'login' => '5 requests per minute (100 with x-wb-postman header)',
                    'signup' => '5 requests per minute (100 with x-wb-postman header)',
                    'sessions' => '5 requests per minute (100 with x-wb-postman header)',
                    'phone-number' => '3 requests per minute (100 with x-wb-postman header)',
                ];

                return [
                    'name' => $throttleName,
                    'description' => $rateLimitDescriptions[$throttleName] ?? "Rate limit: {$throttleName}",
                ];
            }
        }

        return null;
    }

    public function extractCustomHeaders(Route $route): array
    {
        $headers = [];

        // Check route action for hints about required headers
        $action = $route->getAction('uses');
        // Webhook endpoints typically need signature headers
        if (is_string($action) && Str::contains($action, 'Webhook')) {
            $headers[] = [
                'name' => 'X-Signature',
                'required' => true,
                'description' => 'Webhook signature for request validation',
            ];
        }

        return $headers;
    }

    /**
     * Get reflection method for controller action
     *
     * @throws RouteAnalysisException
     */
    public function getReflectionMethod(string $controller, string $method, string $cacheKey): ReflectionMethod
    {
        if (! isset($this->reflectionCache[$cacheKey])) {
            if (! class_exists($controller)) {
                throw RouteAnalysisException::controllerNotFound($controller);
            }

            if (! method_exists($controller, $method)) {
                throw RouteAnalysisException::methodNotFound($controller, $method);
            }

            try {
                $this->reflectionCache[$cacheKey] = new ReflectionMethod($controller, $method);
            } catch (\ReflectionException $e) {
                throw RouteAnalysisException::reflectionFailed($controller, $method, $e->getMessage(), $e);
            }
        }

        return $this->reflectionCache[$cacheKey];
    }

    /**
     * Validate route action format
     *
     * @throws RouteAnalysisException
     */
    public function validateRouteAction($action): void
    {
        if (! is_string($action) || ! Str::contains($action, '@')) {
            throw RouteAnalysisException::invalidRouteAction((string) $action);
        }
    }
}
