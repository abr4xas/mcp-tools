<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Contracts;

/**
 * Contract Loader Interface
 */
interface ContractLoaderInterface
{
    /**
     * Load contract from cache or file system.
     *
     * @return array<string, array<string, array<string, mixed>>>|null Contract data or null if invalid/not found
     */
    public function load(): ?array;

    /**
     * Clear the contract cache.
     */
    public function clearCache(): void;

    /**
     * Get the contract file path from configuration.
     *
     * @return string Full path to the contract JSON file
     */
    public function getContractPath(): string;
}
