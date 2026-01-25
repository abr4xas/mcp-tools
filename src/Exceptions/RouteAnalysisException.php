<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Exceptions;

class RouteAnalysisException extends AnalysisException
{
    public static function invalidRouteAction(string $action, ?\Throwable $previous = null): self
    {
        return new self(
            "Invalid route action format: '{$action}'. Expected format: 'Controller@method'.",
            'ROUTE_INVALID_ACTION',
            ['action' => $action],
            $previous
        );
    }

    public static function controllerNotFound(string $controller, ?\Throwable $previous = null): self
    {
        return new self(
            "Controller class not found: '{$controller}'. Ensure the controller exists and is properly namespaced.",
            'ROUTE_CONTROLLER_NOT_FOUND',
            ['controller' => $controller],
            $previous
        );
    }

    public static function methodNotFound(string $controller, string $method, ?\Throwable $previous = null): self
    {
        return new self(
            "Method '{$method}' not found in controller '{$controller}'. Check that the method exists and is public.",
            'ROUTE_METHOD_NOT_FOUND',
            ['controller' => $controller, 'method' => $method],
            $previous
        );
    }

    public static function reflectionFailed(string $controller, string $method, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to reflect controller method '{$controller}::{$method}': {$reason}",
            'ROUTE_REFLECTION_FAILED',
            ['controller' => $controller, 'method' => $method, 'reason' => $reason],
            $previous
        );
    }

    protected function getSuggestion(): string
    {
        $context = $this->getContext();

        return match ($this->errorCode) {
            'ROUTE_INVALID_ACTION' => 'Verify the route definition in your routes file. Ensure it uses the format: ControllerClass@methodName. '.
                'Check routes/api.php or routes/web.php for the route definition.',
            'ROUTE_CONTROLLER_NOT_FOUND' => 'Check that the controller class exists and the namespace is correct. '.
                'Run: php artisan route:list to verify routes. '.
                'If the controller is in a subdirectory, ensure the namespace matches the directory structure.',
            'ROUTE_METHOD_NOT_FOUND' => 'Ensure the method exists in the controller and is declared as public. '.
                'Check the controller file: '.($context['controller'] ?? 'unknown').'. '.
                'Method names are case-sensitive in PHP.',
            'ROUTE_REFLECTION_FAILED' => 'Check that the controller file is readable and the class can be autoloaded. '.
                'Try running: composer dump-autoload. '.
                'Verify file permissions and that the class follows PSR-4 autoloading standards.',
            default => 'Review the route configuration and ensure all controllers and methods are properly defined. '.
                'For more help, run: php artisan mcp-tools:health-check',
        };
    }
}
