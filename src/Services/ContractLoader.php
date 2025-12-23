<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class ContractLoader
{
    /** @var array<string, array> */
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
     * Clear the contract cache
     */
    public function clearCache(): void
    {
        self::$contractCache = [];
    }

    /**
     * Validate the structure of the contract
     *
     * @param  array<string, mixed>  $contract
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
     * Get the contract file path
     */
    public function getContractPath(): string
    {
        return Config::get('mcp-tools.contract_path', storage_path('api-contracts/api.json'));
    }
}

