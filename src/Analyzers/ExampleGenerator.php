<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Analyzers;

class ExampleGenerator
{
    /**
     * Generate example data from schema
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateFromSchema(array $schema): array
    {
        if (empty($schema)) {
            return [];
        }

        $example = [];

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                if (isset($value['type'])) {
                    $example[$key] = $this->generateValueForType($value);
                } elseif (isset($value['properties'])) {
                    // Nested object
                    $example[$key] = $this->generateFromSchema($value['properties']);
                } elseif (isset($value['items'])) {
                    // Array
                    $itemExample = $this->generateValueForType($value['items']);
                    $example[$key] = [$itemExample];
                } else {
                    // Recursive
                    $example[$key] = $this->generateFromSchema($value);
                }
            }
        }

        return $example;
    }

    /**
     * Generate example values for each field in schemas
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateFieldExamples(array $schema): array
    {
        $examples = [];

        foreach ($schema as $field => $definition) {
            if (is_array($definition) && isset($definition['type'])) {
                $examples[$field] = $this->generateValueForType($definition);
            }
        }

        return $examples;
    }

    /**
     * Generate example value for a type
     *
     * @param  array<string, mixed>  $property
     * @return mixed
     */
    protected function generateValueForType(array $property)
    {
        $type = $property['type'] ?? 'string';

        return match ($type) {
            'integer', 'int' => $this->generateInteger($property),
            'number', 'float', 'double' => $this->generateNumber($property),
            'boolean', 'bool' => $this->generateBoolean(),
            'array' => $this->generateArray($property),
            'object' => $this->generateObject($property),
            default => $this->generateString($property),
        };
    }

    /**
     * Generate example integer
     *
     * @param  array<string, mixed>  $property
     */
    protected function generateInteger(array $property): int
    {
        $min = $this->extractMin($property);
        $max = $this->extractMax($property);

        if ($min !== null && $max !== null) {
            return (int) (($min + $max) / 2);
        }

        if ($min !== null) {
            return $min;
        }

        if ($max !== null) {
            return min($max, 100);
        }

        return 42;
    }

    /**
     * Generate example number
     *
     * @param  array<string, mixed>  $property
     */
    protected function generateNumber(array $property): float
    {
        $min = $this->extractMin($property);
        $max = $this->extractMax($property);

        if ($min !== null && $max !== null) {
            return ($min + $max) / 2.0;
        }

        if ($min !== null) {
            return (float) $min;
        }

        if ($max !== null) {
            return min((float) $max, 100.0);
        }

        return 3.14;
    }

    /**
     * Generate example boolean
     */
    protected function generateBoolean(): bool
    {
        return true;
    }

    /**
     * Generate example string
     *
     * @param  array<string, mixed>  $property
     */
    protected function generateString(array $property): string
    {
        $constraints = $property['constraints'] ?? [];

        foreach ($constraints as $constraint) {
            if ($constraint === 'email') {
                return 'user@example.com';
            }
            if ($constraint === 'url') {
                return 'https://example.com';
            }
            if ($constraint === 'uuid') {
                return '550e8400-e29b-41d4-a716-446655440000';
            }
            if ($constraint === 'date') {
                return '2024-01-01';
            }
            if (str_starts_with($constraint, 'enum:')) {
                $values = explode(',', substr($constraint, 5));

                return trim($values[0] ?? 'value');
            }
        }

        $min = $this->extractMin($property);
        $max = $this->extractMax($property);

        $length = 10;
        if ($min !== null) {
            $length = max($length, $min);
        }
        if ($max !== null) {
            $length = min($length, $max);
        }

        return str_repeat('a', $length);
    }

    /**
     * Generate example array
     *
     * @param  array<string, mixed>  $property
     * @return array<int, mixed>
     */
    protected function generateArray(array $property): array
    {
        if (isset($property['items'])) {
            $itemExample = $this->generateValueForType($property['items']);

            return [$itemExample];
        }

        return ['item1', 'item2'];
    }

    /**
     * Generate example object
     *
     * @param  array<string, mixed>  $property
     * @return array<string, mixed>
     */
    protected function generateObject(array $property): array
    {
        if (isset($property['properties'])) {
            return $this->generateFromSchema($property['properties']);
        }

        return ['key' => 'value'];
    }

    /**
     * Extract min value from constraints
     *
     * @param  array<string, mixed>  $property
     */
    protected function extractMin(array $property): ?int
    {
        $constraints = $property['constraints'] ?? [];

        foreach ($constraints as $constraint) {
            if (str_starts_with($constraint, 'min:')) {
                return (int) substr($constraint, 4);
            }
        }

        return null;
    }

    /**
     * Extract max value from constraints
     *
     * @param  array<string, mixed>  $property
     */
    protected function extractMax(array $property): ?int
    {
        $constraints = $property['constraints'] ?? [];

        foreach ($constraints as $constraint) {
            if (str_starts_with($constraint, 'max:')) {
                return (int) substr($constraint, 4);
            }
        }

        return null;
    }
}
