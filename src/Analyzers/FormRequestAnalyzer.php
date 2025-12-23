<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Contracts\FormRequestAnalyzerInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Form Request Analyzer
 *
 * Analyzes FormRequest classes to extract validation rules and convert them
 * to request schemas for API contracts.
 *
 * @package Abr4xas\McpTools\Analyzers
 */
class FormRequestAnalyzer implements FormRequestAnalyzerInterface
{
    /** @var array<string, ReflectionMethod> Cache of reflection methods */
    protected array $reflectionCache = [];

    /**
     * Extract request schema from FormRequest or method parameters.
     *
     * @param string|object|null $action The route action
     * @param bool $isQuery Whether this is a query parameter request
     * @param callable(string, string): void $onError Error handler callback
     * @return array<string, mixed> Request schema
     */
    public function extractRequestSchema($action, bool $isQuery, callable $onError): array
    {
        if (! is_string($action) || ! Str::contains($action, '@')) {
            return [];
        }

        [$controller, $controllerMethod] = explode('@', $action);
        $cacheKey = "{$controller}::{$controllerMethod}";

        try {
            $reflection = $this->getReflectionMethod($controller, $controllerMethod, $cacheKey);
            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $class = $type->getName();
                    if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                        try {
                            $formRequest = new $class();
                            if (method_exists($formRequest, 'rules')) {
                                $rules = $formRequest->rules();
                                $parsed = $this->parseValidationRules($rules);

                                return [
                                    'location' => $isQuery ? 'query' : 'body',
                                    'properties' => $parsed,
                                ];
                            }
                        } catch (Throwable $e) {
                            $onError("Could not instantiate FormRequest {$class}", "FormRequest {$class} may require dependencies or have constructor parameters that cannot be resolved automatically.");

                            return ['location' => 'unknown', 'properties' => [], 'error' => 'Could not instantiate FormRequest'];
                        }
                    }
                }
            }

            // If no FormRequest found, check if method accepts Request parameter for query params
            if ($isQuery) {
                foreach ($reflection->getParameters() as $param) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === Request::class || $typeName === 'Request') {
                            // Method accepts Request, might have query params but we can't infer them
                            return ['location' => 'query', 'properties' => []];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $onError("Could not reflect method {$cacheKey}", "Method {$cacheKey} may not exist or may not be accessible. Check that the controller and method are properly defined.");

            return [];
        }

        return [];
    }

    /**
     * Get reflection method with caching.
     *
     * @param string $controller Controller class name
     * @param string $method Method name
     * @param string $cacheKey Cache key
     * @return ReflectionMethod The reflection method
     */
    protected function getReflectionMethod(string $controller, string $method, string $cacheKey): ReflectionMethod
    {
        if (! isset($this->reflectionCache[$cacheKey])) {
            $this->reflectionCache[$cacheKey] = new ReflectionMethod($controller, $method);
        }

        return $this->reflectionCache[$cacheKey];
    }

    /**
     * Parse Laravel validation rules into schema format.
     *
     * @param array<string, string|array> $rules Validation rules
     * @return array<string, array{type: string, required: bool, constraints: array<int, string>}> Parsed schema
     */
    protected function parseValidationRules(array $rules): array
    {
        $schema = [];
        foreach ($rules as $field => $rule) {
            // Convert dot notation to bracket notation for query params
            if (Str::contains($field, '.')) {
                $parts = explode('.', (string) $field);
                $root = array_shift($parts);
                $transformedField = $root . '[' . implode('][', $parts) . ']';
            } else {
                $transformedField = (string) $field;
            }

            $constraints = [];
            $ruleParts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []);

            $type = 'string';
            $required = false;

            foreach ($ruleParts as $part) {
                if (! is_string($part)) {
                    continue;
                }

                $part = mb_trim($part);

                if ($part === 'required') {
                    $required = true;
                } elseif ($part === 'integer' || $part === 'int') {
                    $type = 'integer';
                } elseif (in_array($part, ['numeric', 'float', 'double'], true)) {
                    $type = 'number';
                } elseif ($part === 'boolean' || $part === 'bool') {
                    $type = 'boolean';
                } elseif ($part === 'array') {
                    $type = 'array';
                } elseif ($part === 'string') {
                    $type = 'string';
                } elseif ($part === 'email') {
                    $constraints[] = 'email';
                } elseif ($part === 'url') {
                    $constraints[] = 'url';
                } elseif ($part === 'uuid') {
                    $constraints[] = 'uuid';
                } elseif ($part === 'date') {
                    $constraints[] = 'date';
                } elseif (Str::startsWith($part, 'min:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'max:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'in:')) {
                    $constraints[] = 'enum: ' . mb_substr($part, 3);
                } elseif (Str::startsWith($part, 'regex:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'exists:')) {
                    $constraints[] = $part;
                } elseif (Str::startsWith($part, 'unique:')) {
                    $constraints[] = $part;
                }
            }

            $schema[$transformedField] = [
                'type' => $type,
                'required' => $required,
                'constraints' => $constraints,
            ];
        }

        return $schema;
    }
}
