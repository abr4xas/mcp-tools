<?php

declare(strict_types=1);

namespace Abr4xas\McpTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ContractVersionCommand extends Command
{
    protected $signature = 'api:contract:versions {action=list : Action to perform (list, restore)} {--file= : Version file to restore}';

    protected $description = 'Manage API contract versions with history';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listVersions(),
            'restore' => $this->restoreVersion(),
            default => $this->handleUnknownAction($action),
        };
    }

    protected function listVersions(): int
    {
        $versionsPath = storage_path('api-contracts/versions');

        if (! File::exists($versionsPath)) {
            $this->info('No versions found.');

            return self::SUCCESS;
        }

        $files = File::files($versionsPath);
        $versions = [];

        foreach ($files as $file) {
            if (preg_match('/api-(\d{4}-\d{2}-\d{2}-\d{6})\.json/', $file->getFilename(), $matches)) {
                $versions[] = [
                    'filename' => $file->getFilename(),
                    'timestamp' => $matches[1],
                    'date' => $this->formatTimestamp($matches[1]),
                    'size' => $file->getSize(),
                    'modified' => File::lastModified($file->getPathname()),
                ];
            }
        }

        // Sort by modified time (newest first)
        usort($versions, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        if (empty($versions)) {
            $this->info('No versions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Version', 'Date', 'Size'],
            array_map(fn ($v) => [
                $v['filename'],
                $v['date'],
                $this->formatBytes($v['size']),
            ], $versions)
        );

        return self::SUCCESS;
    }

    protected function restoreVersion(): int
    {
        $versionFile = $this->option('file');

        if (! $versionFile) {
            $this->error('Version file is required. Use --file option.');

            return self::FAILURE;
        }

        $versionsPath = storage_path('api-contracts/versions');
        $fullPath = "{$versionsPath}/{$versionFile}";

        if (! File::exists($fullPath)) {
            $this->error("Version file not found: {$versionFile}");

            return self::FAILURE;
        }

        // Backup current contract
        $currentContract = storage_path('api-contracts/api.json');
        if (File::exists($currentContract)) {
            $backupName = 'api-'.date('Y-m-d-His').'.json';
            File::copy($currentContract, "{$versionsPath}/{$backupName}");
            $this->info("Current contract backed up as: {$backupName}");
        }

        // Restore version
        File::copy($fullPath, $currentContract);
        $this->info("Contract restored from version: {$versionFile}");

        return self::SUCCESS;
    }

    protected function formatTimestamp(string $timestamp): string
    {
        // Format: 2024-01-15-143022
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})/', $timestamp, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
        }

        return $timestamp;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min((int) $pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }

    protected function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");

        return self::FAILURE;
    }
}
