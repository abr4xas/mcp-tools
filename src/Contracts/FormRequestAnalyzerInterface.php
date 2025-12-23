<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Contracts;

/**
 * Form Request Analyzer Interface
 */
interface FormRequestAnalyzerInterface
{
    /**
     * Extract request schema from FormRequest or method parameters.
     *
     * @param  string|object|null  $action  The route action
     * @param  bool  $isQuery  Whether this is a query parameter request
     * @param  callable(string, string): void  $onError  Error handler callback
     * @return array<string, mixed> Request schema
     */
    public function extractRequestSchema($action, bool $isQuery, callable $onError): array;
}
