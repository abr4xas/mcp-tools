<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Exceptions;

class FormRequestAnalysisException extends AnalysisException
{
    public static function instantiationFailed(string $formRequestClass, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Could not instantiate FormRequest '{$formRequestClass}': {$reason}",
            'FORM_REQUEST_INSTANTIATION_FAILED',
            ['form_request_class' => $formRequestClass, 'reason' => $reason],
            $previous
        );
    }

    public static function rulesMethodNotFound(string $formRequestClass, ?\Throwable $previous = null): self
    {
        return new self(
            "FormRequest '{$formRequestClass}' does not have a 'rules()' method. Ensure it extends Illuminate\Foundation\Http\FormRequest.",
            'FORM_REQUEST_RULES_NOT_FOUND',
            ['form_request_class' => $formRequestClass],
            $previous
        );
    }

    public static function rulesReturnedInvalid(string $formRequestClass, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "FormRequest '{$formRequestClass}' returned invalid rules: {$reason}",
            'FORM_REQUEST_INVALID_RULES',
            ['form_request_class' => $formRequestClass, 'reason' => $reason],
            $previous
        );
    }

    public static function classNotFound(string $formRequestClass, ?\Throwable $previous = null): self
    {
        return new self(
            "FormRequest class not found: '{$formRequestClass}'. Ensure the class exists and is properly namespaced.",
            'FORM_REQUEST_CLASS_NOT_FOUND',
            ['form_request_class' => $formRequestClass],
            $previous
        );
    }

    protected function getSuggestion(): string
    {
        $context = $this->getContext();
        $formRequestClass = $context['form_request_class'] ?? 'unknown';

        return match ($this->errorCode) {
            'FORM_REQUEST_INSTANTIATION_FAILED' => 'Check if the FormRequest has required dependencies in its constructor. '.
                'Consider making dependencies optional or using dependency injection. '.
                "Review the FormRequest class: {$formRequestClass}. ".
                'Common issues: missing database connection, required model relationships, or authorization logic.',
            'FORM_REQUEST_RULES_NOT_FOUND' => 'Ensure your FormRequest class extends Illuminate\Foundation\Http\FormRequest and implements the rules() method. '.
                "Check the class: {$formRequestClass}. ".
                'The rules() method must return an array of validation rules.',
            'FORM_REQUEST_INVALID_RULES' => 'Verify that the rules() method returns a valid array of validation rules. '.
                "Check the FormRequest: {$formRequestClass}. ".
                'See Laravel validation documentation: https://laravel.com/docs/validation',
            'FORM_REQUEST_CLASS_NOT_FOUND' => 'Check that the FormRequest class exists and the namespace is correct. '.
                "Expected class: {$formRequestClass}. ".
                'Run: composer dump-autoload. Verify the file exists in app/Http/Requests/',
            default => 'Review the FormRequest class and ensure it follows Laravel conventions. '.
                'For debugging, check: php artisan mcp-tools:health-check',
        };
    }
}
