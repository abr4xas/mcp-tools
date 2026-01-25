<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Analyzers\MiddlewareAnalyzer;
use Abr4xas\McpTools\Exceptions\RouteAnalysisException;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionMethod;

class RouteAnalyzer
{
    protected AnalysisCacheService $cacheService;

    protected MiddlewareAnalyzer $middlewareAnalyzer;

    /** @var array<string, ReflectionMethod> */
    protected array $reflectionCache = [];

    public function __construct(AnalysisCacheService $cacheService, MiddlewareAnalyzer $middlewareAnalyzer)
    {
        $this->cacheService = $cacheService;
        $this->middlewareAnalyzer = $middlewareAnalyzer;
    }

    public function extractPathParams(string $uri, $action = null): array
    {
        $cacheKey = 'path_params:' . $uri . ($action ? ':' . md5((string) $action) : '');
        if ($this->cacheService->has('route', $cacheKey)) {
            return $this->cacheService->get('route', $cacheKey);
        }

        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        $params = [];
        foreach ($matches[1] as $paramName) {
            $type = $this->detectParamType($paramName, $action);
            $params[$paramName] = [
                'type' => $type,
            ];
        }

        $this->cacheService->put('route', $cacheKey, $params);

        return $params;
    }

    /**
     * Detect parameter type from route model binding
     *
     * @param  mixed  $action
     */
    protected function detectParamType(string $paramName, $action): string
    {
        // Default to string
        $type = 'string';

        if (! $action || ! is_string($action) || ! str_contains($action, '@')) {
            return $type;
        }

        try {
            [$controller, $method] = explode('@', $action);
            if (! class_exists($controller)) {
                return $type;
            }

            $reflection = new \ReflectionMethod($controller, $method);
            foreach ($reflection->getParameters() as $param) {
                if ($param->getName() === $paramName) {
                    $paramType = $param->getType();
                    if ($paramType instanceof \ReflectionNamedType) {
                        $typeName = $paramType->getName();
                        
                        // Check if it's a model (route model binding)
                        if (class_exists($typeName)) {
                            // Check if it's an Eloquent model
                            if (is_subclass_of($typeName, \Illuminate\Database\Eloquent\Model::class)) {
                                // Try to infer type from model's primary key
                                try {
                                    $model = new $typeName();
                                    $keyType = $model->getKeyType();
                                    $type = match ($keyType) {
                                        'int' => 'integer',
                                        'string' => 'string',
                                        default => 'string',
                                    };
                                } catch (\Throwable) {
                                    // Default to integer for models (most common)
                                    $type = 'integer';
                                }
                            } else {
                                // Type hint exists but not a model
                                $type = match ($typeName) {
                                    'int', 'integer' => 'integer',
                                    'float', 'double' => 'number',
                                    'bool', 'boolean' => 'boolean',
                                    default => 'string',
                                };
                            }
                        } else {
                            // Built-in type
                            $type = match ($typeName) {
                                'int', 'integer' => 'integer',
                                'float', 'double' => 'number',
                                'bool', 'boolean' => 'boolean',
                                default => 'string',
                            };
                        }
                    }
                    break;
                }
            }

            // If no type hint, try to infer from parameter name
            if ($type === 'string') {
                $type = $this->inferTypeFromName($paramName);
            }
        } catch (\Throwable) {
            // Fallback to inference from name
            $type = $this->inferTypeFromName($paramName);
        }

        return $type;
    }

    /**
     * Infer type from parameter name
     */
    protected function inferTypeFromName(string $paramName): string
    {
        // Common patterns: id, uuid, slug, etc.
        if (preg_match('/\b(id|uuid)\b/i', $paramName)) {
            return 'integer';
        }
        if (preg_match('/\b(slug|hash|token)\b/i', $paramName)) {
            return 'string';
        }

        return 'string';
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
                $throttleValue = Str::after($middleware, 'throttle:');

                // Check if it's a named throttle or direct values (e.g., "throttle:60,1")
                if (preg_match('/^(\d+),(\d+)$/', $throttleValue, $matches)) {
                    // Direct values: throttle:60,1 means 60 requests per 1 minute
                    $maxAttempts = (int) $matches[1];
                    $decayMinutes = (int) $matches[2];

                    return [
                        'max_attempts' => $maxAttempts,
                        'decay_minutes' => $decayMinutes,
                        'description' => "{$maxAttempts} requests per {$decayMinutes} minute(s)",
                    ];
                }

                // Named throttle - try to get from config
                $throttleName = $throttleValue;
                $rateLimit = $this->getRateLimitFromConfig($throttleName);

                if ($rateLimit !== null) {
                    return $rateLimit;
                }

                // Fallback to common descriptions
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

    /**
     * Get rate limit configuration from Laravel config
     *
     * @return array{max_attempts: int, decay_minutes: int, description: string}|null
     */
    protected function getRateLimitFromConfig(string $throttleName): ?array
    {
        // Check RouteServiceProvider for throttle configuration
        try {
            // Try to get from config/throttle.php if it exists
            if (config()->has("throttle.{$throttleName}")) {
                $config = config("throttle.{$throttleName}");
                if (is_array($config) && isset($config['max_attempts']) && isset($config['decay_minutes'])) {
                    return [
                        'max_attempts' => (int) $config['max_attempts'],
                        'decay_minutes' => (int) $config['decay_minutes'],
                        'description' => "{$config['max_attempts']} requests per {$config['decay_minutes']} minute(s)",
                    ];
                }
            }

            // Check RouteServiceProvider throttle method
            $routeServiceProvider = app()->getProvider(\Illuminate\Foundation\Support\Providers\RouteServiceProvider::class);
            if ($routeServiceProvider && method_exists($routeServiceProvider, 'configureRateLimiting')) {
                // Try to get throttle limits from RouteServiceProvider
                // This is a bit tricky as we need to access the RateLimiter
                $rateLimiter = app(\Illuminate\Cache\RateLimiter::class);
                if ($rateLimiter) {
                    // Check if there's a named limit
                    $limiter = $rateLimiter->limiter($throttleName);
                    if ($limiter && is_callable($limiter)) {
                        // Try to call with a test request to get the limit
                        // This is a simplified approach
                        try {
                            $testRequest = request();
                            $result = $limiter($testRequest);
                            if (is_array($result) && isset($result[0]) && isset($result[1])) {
                                return [
                                    'max_attempts' => (int) $result[0],
                                    'decay_minutes' => (int) $result[1],
                                    'description' => "{$result[0]} requests per {$result[1]} minute(s)",
                                ];
                            }
                        } catch (Throwable) {
                            // Ignore errors
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Ignore errors and fall back to default
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

        // Extract headers from middleware
        $middlewares = $route->gatherMiddleware();
        $middlewareHeaders = $this->middlewareAnalyzer->extractRequiredHeaders($middlewares);
        $headers = array_merge($headers, $middlewareHeaders);

        return $headers;
    }

    /**
     * Analyze all middleware applied to route
     *
     * @return array<int, array{name: string, category: string, parameters: array<string, mixed>}>
     */
    public function analyzeMiddleware(Route $route): array
    {
        return $this->middlewareAnalyzer->analyze($route);
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
