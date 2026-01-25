<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Abr4xas\McpTools\Services\AnalysisCacheService;
use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    protected $signature = 'mcp-tools:clear-cache {--type= : Clear cache for specific type (route, resource, form_request)}';

    protected $description = 'Clear the analysis cache for MCP tools';

    public function handle(AnalysisCacheService $cacheService): int
    {
        $type = $this->option('type');

        if ($type) {
            $cacheService->clearType($type);
            $this->info("Cache cleared for type: {$type}");
        } else {
            $cacheService->clear();
            $this->info('All analysis cache cleared successfully.');
        }

        return self::SUCCESS;
    }
}
