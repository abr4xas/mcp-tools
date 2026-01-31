<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Exceptions;

class ResourceAnalysisException extends AnalysisException
{
    public static function classNotFound(string $resourceClass, ?\Throwable $previous = null): self
    {
        return new self(
            "Resource class not found: '{$resourceClass}'. Ensure the resource exists and is properly namespaced.",
            'RESOURCE_CLASS_NOT_FOUND',
            ['resource_class' => $resourceClass],
            $previous
        );
    }

    public static function modelNotFound(string $modelClass, string $resourceClass, ?\Throwable $previous = null): self
    {
        return new self(
            "Model class '{$modelClass}' not found for resource '{$resourceClass}'. Ensure the model exists.",
            'RESOURCE_MODEL_NOT_FOUND',
            ['model_class' => $modelClass, 'resource_class' => $resourceClass],
            $previous
        );
    }

    public static function factoryFailed(string $resourceClass, string $modelClass, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Factory failed for resource '{$resourceClass}' with model '{$modelClass}': {$reason}",
            'RESOURCE_FACTORY_FAILED',
            ['resource_class' => $resourceClass, 'model_class' => $modelClass, 'reason' => $reason],
            $previous
        );
    }

    public static function factoryNotAvailable(string $modelClass, string $resourceClass, ?\Throwable $previous = null): self
    {
        return new self(
            "Model '{$modelClass}' does not have a factory method. Create a factory or ensure the model uses HasFactory trait.",
            'RESOURCE_FACTORY_NOT_AVAILABLE',
            ['model_class' => $modelClass, 'resource_class' => $resourceClass],
            $previous
        );
    }

    public static function resourceInstantiationFailed(string $resourceClass, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to instantiate resource '{$resourceClass}': {$reason}",
            'RESOURCE_INSTANTIATION_FAILED',
            ['resource_class' => $resourceClass, 'reason' => $reason],
            $previous
        );
    }

    public static function resourceResolutionFailed(string $resourceClass, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to resolve resource '{$resourceClass}': {$reason}",
            'RESOURCE_RESOLUTION_FAILED',
            ['resource_class' => $resourceClass, 'reason' => $reason],
            $previous
        );
    }

    protected function getSuggestion(): string
    {
        $context = $this->getContext();
        $resourceClass = $context['resource_class'] ?? 'unknown';
        $modelClass = $context['model_class'] ?? 'unknown';

        return match ($this->errorCode) {
            'RESOURCE_CLASS_NOT_FOUND' => 'Check that the Resource class exists and the namespace is correct. '.
                "Expected class: {$resourceClass}. ".
                'Run: composer dump-autoload. Verify the file exists in app/Http/Resources/',
            'RESOURCE_MODEL_NOT_FOUND' => 'Ensure the model class exists. The resource name should map to a model (e.g., PostResource -> Post model). '.
                "Expected model: {$modelClass}. ".
                'Check that the model exists in app/Models/ or the configured models directory.',
            'RESOURCE_FACTORY_FAILED' => 'Check the model factory definition. Ensure all required fields are defined and the factory can create valid instances. '.
                "Model: {$modelClass}, Resource: {$resourceClass}. ".
                'Review the factory in database/factories/ and ensure it handles all required attributes.',
            'RESOURCE_FACTORY_NOT_AVAILABLE' => 'Add the HasFactory trait to your model and create a factory. '.
                "Model: {$modelClass}. ".
                'Run: php artisan make:factory '.class_basename($modelClass).'Factory. '.
                'Then add: use Illuminate\Database\Eloquent\Factories\HasFactory; to your model.',
            'RESOURCE_INSTANTIATION_FAILED' => 'Check that the resource constructor accepts the provided data type. '.
                "Resource: {$resourceClass}. ".
                'Review the resource class definition and ensure the constructor signature matches the data being passed.',
            'RESOURCE_RESOLUTION_FAILED' => 'Ensure the resource can resolve its data. Check for any accessor issues or missing model attributes. '.
                "Resource: {$resourceClass}. ".
                'Common issues: accessing relationships that are not loaded, missing database columns, or accessor methods that throw exceptions.',
            default => 'Review the Resource class and ensure it follows Laravel conventions. '.
                'For more help: php artisan mcp-tools:health-check',
        };
    }
}
