<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Concerns\FormatsBytes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneBackupsCommand extends Command
{
    use ConfirmsInProduction;
    use FormatsBytes;

    protected $signature = 'angelohd:prune-backups {--older-than=} {--path=} {--dry-run} {--force}';
    protected $description = 'Apaga backups mais antigos que o numero de dias especificado.';

    public function handle(): int
    {
        $olderThan = (int) ($this->option('older-than') ?: config('angelohd-backup.prune_older_than_days', 7));
        $dryRun = $this->option('dry-run');

        if ($olderThan < 1) {
            $this->error('--older-than deve ser no minimo 1 dia.');
            return Command::FAILURE;
        }

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

        $cutoffDate = now()->subDays($olderThan);
        $toDelete = [];

        foreach ($folders as $folder) {
            $folderName = basename($folder);
            $folderDate = $this->parseDateFromFolder($folderName);

            if ($folderDate && $folderDate->lt($cutoffDate)) {
                $size = $this->folderSize($folder);
                $toDelete[] = [
                    'folder' => $folder,
                    'name' => $folderName,
                    'size' => $size,
                ];
            }
        }

        if (empty($toDelete)) {
            $this->info("Nenhum backup com mais de {$olderThan} dia(s) encontrado.");
            return Command::SUCCESS;
        }

        $this->warn("Backups com mais de {$olderThan} dia(s) encontrados:");
        $this->newLine();

        $rows = [];
        $totalSize = 0;
        foreach ($toDelete as $item) {
            $rows[] = [
                'pasta' => $item['name'],
                'tamanho' => $this->formatSize($item['size']),
            ];
            $totalSize += $item['size'];
        }

        $this->table(['Pasta', 'Tamanho'], $rows);
        $this->newLine();
        $this->warn('Total: ' . count($toDelete) . ' backup(s) - ' . $this->formatSize($totalSize));

        if ($dryRun) {
            $this->info('[--dry-run] Nenhum backup foi apagado.');
            return Command::SUCCESS;
        }

        if (!$this->confirmDestructiveOperation('Tem a certeza que deseja APAGAR estes backups?')) {
            return Command::SUCCESS;
        }

        $deleted = 0;
        foreach ($toDelete as $item) {
            File::deleteDirectory($item['folder']);
            $deleted++;
            $this->info("Apagado: {$item['name']}");
        }

        $this->newLine();
        $this->info("{$deleted} backup(s) apagado(s). Libertado: " . $this->formatSize($totalSize));

        return Command::SUCCESS;
    }

    private function parseDateFromFolder(string $folderName): mixed
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})_(\d{2})-(\d{2})-(\d{2})$/', $folderName, $m)) {
            return \DateTime::createFromFormat('d-m-Y H:i:s', "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
        }

        return null;
    }

    private function folderSize(string $folder): int
    {
        $size = 0;
        foreach (File::allFiles($folder) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
}
