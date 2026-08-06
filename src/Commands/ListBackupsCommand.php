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

        $folders = File::directories($backupPath);

        if (empty($folders)) {
            $this->warn('Nenhum backup encontrado.');
            return Command::SUCCESS;
        }

        rsort($folders);

        $rows = [];

        foreach ($folders as $folder) {
            $folderName = basename($folder);
            $files = File::files($folder);
            $sqlFiles = array_filter($files, fn ($f) => str_ends_with($f->getFilename(), '.sql') || str_ends_with($f->getFilename(), '.sql.gz'));

            $totalSize = 0;
            foreach ($files as $f) {
                $totalSize += $f->getSize();
            }

            $rows[] = [
                'pasta' => $folderName,
                'ficheiros' => count($sqlFiles),
                'tamanho' => $this->formatSize($totalSize),
            ];
        }

        $this->info("Directorio: {$backupPath}");
        $this->newLine();
        $this->table(['Pasta', 'Ficheiros SQL', 'Tamanho'], $rows);
        $this->newLine();
        $this->info('Total: ' . count($rows) . ' backup(s).');

        return Command::SUCCESS;
    }
}
