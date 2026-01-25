<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

use Abr4xas\McpTools\Exceptions\FormRequestAnalysisException;
use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class FormRequestAnalyzer
{
    protected AnalysisCacheService $cacheService;

    public function __construct(AnalysisCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }
    /**
     * Extract request schema from FormRequest
     *
     * @param  string  $formRequestClass
     * @param  bool  $isQuery
     * @return array{location: string, properties: array}
     * @throws FormRequestAnalysisException
     */
    public function extractSchema(string $formRequestClass, bool $isQuery): array
    {
        $cacheKey = $formRequestClass . ':' . ($isQuery ? 'query' : 'body');

        // Check cache with file modification time validation
        try {
            $reflection = new ReflectionClass($formRequestClass);
            $filePath = $reflection->getFileName();
            if ($filePath && $this->cacheService->isValidForFile('form_request', $cacheKey, $filePath)) {
                $cached = $this->cacheService->get('form_request', $cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
        } catch (Throwable) {
            // If reflection fails, continue without cache
        }

        if (! class_exists($formRequestClass)) {
            throw FormRequestAnalysisException::classNotFound($formRequestClass);
        }

        if (! is_subclass_of($formRequestClass, FormRequest::class)) {
            throw FormRequestAnalysisException::instantiationFailed(
                $formRequestClass,
                "Class does not extend Illuminate\Foundation\Http\FormRequest"
            );
        }

        try {
            $formRequest = new $formRequestClass();
        } catch (Throwable $e) {
            throw FormRequestAnalysisException::instantiationFailed($formRequestClass, $e->getMessage(), $e);
        }

        if (! method_exists($formRequest, 'rules')) {
            throw FormRequestAnalysisException::rulesMethodNotFound($formRequestClass);
        }

        try {
            $rules = $formRequest->rules();
        } catch (Throwable $e) {
            throw FormRequestAnalysisException::rulesReturnedInvalid($formRequestClass, $e->getMessage(), $e);
        }

        if (! is_array($rules)) {
            throw FormRequestAnalysisException::rulesReturnedInvalid(
                $formRequestClass,
                'Rules method must return an array'
            );
        }

        $parsed = $this->parseValidationRules($rules);

        $result = [
            'location' => $isQuery ? 'query' : 'body',
            'properties' => $parsed,
        ];

        // Store in cache with file modification time
        try {
            $reflection = new ReflectionClass($formRequestClass);
            $filePath = $reflection->getFileName();
            if ($filePath) {
                $this->cacheService->put('form_request', $cacheKey, $result);
                $this->cacheService->storeFileMtime('form_request', $cacheKey, $filePath);
            }
        } catch (Throwable) {
            // If reflection fails, just cache without file validation
            $this->cacheService->put('form_request', $cacheKey, $result);
        }

        return $result;
    }

    /**
     * Check if parameter is a FormRequest
     */
    public function isFormRequestParameter(ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $class = $type->getName();

            return class_exists($class) && is_subclass_of($class, FormRequest::class);
        }

        return false;
    }

    /**
     * Extract query parameters from controller method without FormRequest
     *
     * @param  \ReflectionMethod  $reflection
     * @return array<string, array{type: string, required: bool, constraints: array<string>}>
     */
    public function extractQueryParamsFromMethod(\ReflectionMethod $reflection): array
    {
        $params = [];
        $phpDoc = $this->cacheService->get('form_request', 'phpdoc:' . $reflection->getName());

        // Try to extract from PHPDoc @param tags
        $docComment = $reflection->getDocComment();
        if ($docComment !== false) {
            if (preg_match_all('/@param\s+(\S+)\s+\$(\w+)(?:\s+(.+))?/', $docComment, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $type = $match[1] ?? 'string';
                    $name = $match[2] ?? '';
                    $description = $match[3] ?? null;

                    if ($name) {
                        // Check if it's a query parameter (not injected dependency)
                        $isQueryParam = true;
                        foreach ($reflection->getParameters() as $param) {
                            if ($param->getName() === $name) {
                                $paramType = $param->getType();
                                if ($paramType instanceof \ReflectionNamedType && ! $paramType->isBuiltin()) {
                                    // It's a type-hinted class, not a query param
                                    $isQueryParam = false;
                                }
                                break;
                            }
                        }

                        if ($isQueryParam) {
                            $params[$name] = [
                                'type' => $this->normalizeType($type),
                                'required' => false, // Query params are usually optional
                                'constraints' => $description ? [$description] : [],
                            ];
                        }
                    }
                }
            }
        }

        // Also check method body for Request::input() or Request::get() calls
        try {
            $fileName = $reflection->getFileName();
            if ($fileName) {
                $content = file_get_contents($fileName);
                if ($content !== false) {
                    $startLine = $reflection->getStartLine();
                    $endLine = $reflection->getEndLine();
                    if ($startLine && $endLine) {
                        $lines = explode("\n", $content);
                        $methodBody = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

                        // Look for Request::input('param') or Request::get('param')
                        if (preg_match_all("/Request::(?:input|get)\(['\"](\w+)['\"]/", $methodBody, $matches)) {
                            foreach ($matches[1] as $paramName) {
                                if (! isset($params[$paramName])) {
                                    $params[$paramName] = [
                                        'type' => 'string',
                                        'required' => false,
                                        'constraints' => [],
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Ignore errors
        }

        return $params;
    }

    /**
     * Normalize PHP type to schema type
     */
    protected function normalizeType(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer' => 'integer',
            'float', 'double', 'numeric' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * Parse validation rules into schema format
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, array{type: string, required: bool, constraints: array<string>}>
     */
    public function parseValidationRules(array $rules): array
    {
        $schema = [];
        foreach ($rules as $field => $rule) {
            // Handle nested arrays (e.g., "items.*.name" or "items.0.name")
            $transformedField = $this->transformFieldName((string) $field);

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
                } elseif (class_exists($part) && is_subclass_of($part, \Illuminate\Contracts\Validation\Rule::class)) {
                    // Custom validation rule class
                    $constraints[] = 'custom_rule:' . $part;
                } elseif (Str::contains($part, '\\') && class_exists($part)) {
                    // Custom rule class (full namespace)
                    $constraints[] = 'custom_rule:' . $part;
                } elseif (! in_array($part, ['required', 'integer', 'int', 'numeric', 'float', 'double', 'boolean', 'bool', 'array', 'string', 'email', 'url', 'uuid', 'date'], true)) {
                    // Unknown rule - likely custom
                    if (! Str::startsWith($part, 'min:') && ! Str::startsWith($part, 'max:') && ! Str::startsWith($part, 'in:') && ! Str::startsWith($part, 'regex:') && ! Str::startsWith($part, 'exists:') && ! Str::startsWith($part, 'unique:')) {
                        $constraints[] = 'custom:' . $part;
                    }
                }
            }

            // Handle nested structures
            if (Str::contains($transformedField, '[') && Str::contains($transformedField, ']')) {
                $this->setNestedField($schema, $transformedField, [
                    'type' => $type,
                    'required' => $required,
                    'constraints' => $constraints,
                ]);
            } else {
                $schema[$transformedField] = [
                    'type' => $type,
                    'required' => $required,
                    'constraints' => $constraints,
                ];
            }
        }

        return $schema;
    }

    /**
     * Transform field name handling nested structures
     */
    protected function transformFieldName(string $field): string
    {
        // Convert dot notation to bracket notation for query params
        // Handle nested arrays: "items.*.name" -> "items[*][name]"
        if (Str::contains($field, '.')) {
            $parts = explode('.', $field);
            $root = array_shift($parts);
            $transformed = $root;

            foreach ($parts as $part) {
                if ($part === '*') {
                    $transformed .= '[*]';
                } else {
                    $transformed .= '[' . $part . ']';
                }
            }

            return $transformed;
        }

        return $field;
    }

    /**
     * Set nested field in schema maintaining hierarchy
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     */
    protected function setNestedField(array &$schema, string $field, array $value): void
    {
        // Parse bracket notation: "items[*][name]" -> ["items", "*", "name"]
        preg_match_all('/\[([^\]]+)\]/', $field, $matches);
        $baseField = preg_replace('/\[.*$/', '', $field);
        $path = $matches[1] ?? [];

        if (empty($path)) {
            $schema[$baseField] = $value;
            return;
        }

        // Build nested structure
        if (! isset($schema[$baseField])) {
            $schema[$baseField] = [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => []],
            ];
        }

        $current = &$schema[$baseField];
        foreach ($path as $index => $key) {
            if ($key === '*') {
                // Array item
                if (! isset($current['items'])) {
                    $current['items'] = ['type' => 'object', 'properties' => []];
                }
                $current = &$current['items'];
            } else {
                // Object property
                if (! isset($current['properties'])) {
                    $current['properties'] = [];
                }
                if (! isset($current['properties'][$key])) {
                    $current['properties'][$key] = ['type' => 'string'];
                }
                $current = &$current['properties'][$key];
            }
        }

        // Set the final value
        $current = array_merge($current, $value);
    }
}
