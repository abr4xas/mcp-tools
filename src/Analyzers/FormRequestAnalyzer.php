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
     * Parse validation rules into schema format
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, array{type: string, required: bool, constraints: array<string>}>
     */
    public function parseValidationRules(array $rules): array
    {
        $schema = [];
        foreach ($rules as $field => $rule) {
            // Convert dot notation to bracket notation for query params
            if (Str::contains((string) $field, '.')) {
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
