<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Interfaces;

interface SchemaTransformerInterface
{
    /**
     * Transform schema
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function transform(array $schema): array;

    /**
     * Get transformer priority (higher = applied first)
     */
    public function getPriority(): int;
}
