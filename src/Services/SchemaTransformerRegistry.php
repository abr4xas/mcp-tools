<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

use Abr4xas\McpTools\Interfaces\SchemaTransformerInterface;

class SchemaTransformerRegistry
{
    /** @var array<int, array<int, SchemaTransformerInterface>> */
    protected array $transformers = [];

    /**
     * Register a schema transformer
     */
    public function register(SchemaTransformerInterface $transformer): void
    {
        $priority = $transformer->getPriority();
        if (! isset($this->transformers[$priority])) {
            $this->transformers[$priority] = [];
        }
        $this->transformers[$priority][] = $transformer;
    }

    /**
     * Apply all registered transformers to schema
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function apply(array $schema): array
    {
        // Sort by priority (descending)
        krsort($this->transformers);

        foreach ($this->transformers as $priority => $transformers) {
            foreach ($transformers as $transformer) {
                $schema = $transformer->transform($schema);
            }
        }

        return $schema;
    }
}
