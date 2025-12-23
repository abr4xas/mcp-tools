<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Contract Loader Service
 *
 * Loads and validates API contracts from the file system.
 * Provides caching to avoid repeated file reads and validates
 * contract structure before returning.
 *
 * @package Abr4xas\McpTools\Services
 */
class ContractLoader
{
    /** @var array<string, array<string, array>> Static cache of loaded contracts */
    protected static array $contractCache = [];

    /**
     * Load contract from cache or file system
     *
     * @return array<string, array>|null
     */
    public function load(): ?array
    {
        $cacheKey = 'contract_api';

        if (isset(self::$contractCache[$cacheKey])) {
            return self::$contractCache[$cacheKey];
        }

        $fullPath = Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));

        if (! File::exists($fullPath)) {
            return null;
        }

        $content = File::get($fullPath);
        $contract = json_decode($content, true);

        if (! is_array($contract)) {
            return null;
        }

        // Validate contract structure
        if (! $this->validateContractStructure($contract)) {
            return null;
        }

        self::$contractCache[$cacheKey] = $contract;

        return $contract;
    }

    /**
     * Clear the contract cache.
     *
     * Useful for testing or when contract file is updated.
     */
    public function clearCache(): void
    {
        self::$contractCache = [];
    }

    /**
     * Validate the structure of the contract.
     *
     * Ensures contract has correct structure: routes as keys, methods as nested keys,
     * and required fields (auth) are present.
     *
     * @param array<string, mixed> $contract The contract to validate
     * @return bool True if contract structure is valid
     */
    protected function validateContractStructure(array $contract): bool
    {
        // Contract should be an associative array where keys are route paths
        foreach ($contract as $path => $methods) {
            if (! is_string($path)) {
                return false;
            }

            if (! is_array($methods)) {
                return false;
            }

            // Each route should have HTTP methods as keys
            foreach ($methods as $method => $routeData) {
                if (! is_string($method) || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                    continue; // Skip invalid methods but don't fail
                }

                if (! is_array($routeData)) {
                    return false;
                }

                // Validate required fields exist (at minimum, auth should be present)
                if (! isset($routeData['auth']) || ! is_array($routeData['auth'])) {
                    return false;
                }

                // Validate auth structure
                if (! isset($routeData['auth']['type']) || ! is_string($routeData['auth']['type'])) {
                    return false;
                }

                // path_parameters should be an array if present
                if (isset($routeData['path_parameters']) && ! is_array($routeData['path_parameters'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the contract file path from configuration.
     *
     * @return string Full path to the contract JSON file
     */
    public function getContractPath(): string
    {
        return Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));
    }
}
