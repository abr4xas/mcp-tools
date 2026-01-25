<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

class JsonSchemaValidator
{
    /**
     * Validate schema against JSON Schema draft
     *
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, errors: array<int, array{path: string, message: string}>}
     */
    public function validate(array $schema): array
    {
        $errors = [];

        // Basic validation - check required fields for objects
        $this->validateSchemaStructure($schema, '', $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate schema structure recursively
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    protected function validateSchemaStructure($schema, string $path, array &$errors): void
    {
        if (! is_array($schema)) {
            return;
        }

        // Check if it's a property definition
        if (isset($schema['type'])) {
            $type = $schema['type'];
            $validTypes = ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null', 'enum'];

            if (! in_array($type, $validTypes, true)) {
                $errors[] = [
                    'path' => $path,
                    'message' => "Invalid type '{$type}'. Must be one of: " . implode(', ', $validTypes),
                ];
            }

            // Validate array items
            if ($type === 'array' && isset($schema['items'])) {
                $this->validateSchemaStructure($schema['items'], $path . '.items', $errors);
            }

            // Validate object properties
            if ($type === 'object' && isset($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propSchema) {
                    $this->validateSchemaStructure($propSchema, $path . '.' . $propName, $errors);
                }
            }
        } else {
            // It's a schema object with properties
            if (isset($schema['properties'])) {
                foreach ($schema['properties'] as $propName => $propSchema) {
                    $this->validateSchemaStructure($propSchema, $path . '.' . $propName, $errors);
                }
            }
        }
    }
}
