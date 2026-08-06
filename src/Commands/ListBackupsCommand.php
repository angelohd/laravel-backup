<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\FormatsBytes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ListBackupsCommand extends Command
{
    use FormatsBytes;

    protected $signature = 'angelohd:list-backups {--path=}';
    protected $description = 'Lista todos os backups existentes com tamanho e data.';

    public function handle(): int
    {
        $backupPath = $this->option('path')
            ?: config('angelohd-backup.default_backup_path', storage_path('app/backups-databases'));

        if (!File::exists($backupPath)) {
            $this->warn("Directorio [{$backupPath}] nao existe.");
            return Command::SUCCESS;
        }

        $rows = [];
        $entries = $this->collectBackupEntries($backupPath);

        if (empty($entries)) {
            $this->warn('Nenhum backup encontrado.');
            return Command::SUCCESS;
        }

        uasort($entries, fn ($a, $b) => $b['name'] <=> $a['name']);

        foreach ($entries as $entry) {
            $rows[] = [
                'pasta' => $entry['name'],
                'ficheiros' => $entry['files'],
                'tamanho' => $this->formatSize($entry['size']),
            ];
        }

        $this->info("Directorio: {$backupPath}");
        $this->newLine();
        $this->table(['Pasta', 'Ficheiros SQL', 'Tamanho'], $rows);
        $this->newLine();
        $this->info('Total: ' . count($rows) . ' backup(s).');

        return Command::SUCCESS;
    }

    private function collectBackupEntries(string $backupPath): array
    {
        $entries = [];

        foreach (File::directories($backupPath) as $folder) {
            $folderName = basename($folder);
            $files = File::files($folder);
            $sqlFiles = array_filter($files, fn ($f) => str_ends_with($f->getFilename(), '.sql') || str_ends_with($f->getFilename(), '.sql.gz'));

            $totalSize = 0;
            foreach ($files as $f) {
                $totalSize += $f->getSize();
            }

            $entries[] = [
                'name' => $folderName,
                'path' => $folder,
                'files' => count($sqlFiles),
                'size' => $totalSize,
                'type' => 'dir',
            ];
        }

        foreach (File::glob($backupPath . DIRECTORY_SEPARATOR . '*.zip') as $zipFile) {
            $entries[] = [
                'name' => basename($zipFile),
                'path' => $zipFile,
                'files' => '-',
                'size' => filesize($zipFile),
                'type' => 'zip',
            ];
        }

        return $entries;
    }
}
