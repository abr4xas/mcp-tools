<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ViewLogsCommand extends Command
{
    protected $signature = 'mcp-tools:logs {--lines=50 : Number of log lines to show} {--level= : Filter by log level (info, warning, error)}';

    protected $description = 'View MCP Tools logs for debugging';

    public function handle(): int
    {
        $lines = (int) $this->option('lines');
        $level = $this->option('level');

        $this->info('MCP Tools Logs');
        $this->newLine();

        // Note: Laravel's default logging doesn't provide easy log viewing
        // This is a simplified implementation that shows recent log entries
        // In production, you might want to use a log viewer package

        $logPath = storage_path('logs/laravel.log');
        if (! file_exists($logPath)) {
            $this->info('No log file found.');

            return self::SUCCESS;
        }

        $logContent = file_get_contents($logPath);
        if ($logContent === false) {
            $this->error('Could not read log file.');

            return self::FAILURE;
        }

        // Extract MCP Tools related log entries
        $entries = [];
        $allLines = explode("\n", $logContent);
        $mcpLines = array_filter($allLines, fn ($line) => str_contains($line, 'MCP Tools'));

        if ($level) {
            $mcpLines = array_filter($mcpLines, fn ($line) => str_contains($line, "[{$level}]"));
        }

        $mcpLines = array_slice($mcpLines, -$lines);

        if (empty($mcpLines)) {
            $this->info('No MCP Tools log entries found.');

            return self::SUCCESS;
        }

        foreach ($mcpLines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
