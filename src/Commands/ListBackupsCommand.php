<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\FormatsBytes;
use angelohd\Backup\Support\BackupRepository;

class ListBackupsCommand extends BaseCommand
{
    use FormatsBytes;

    protected $signature = 'angelohd:list-backups {--path= : Pasta dos backups}';

    protected $description = 'Lista todos os backups existentes com tamanho e data.';

    public function handle(): int
    {
        $repository = new BackupRepository($this->backupPath());

        if (!$repository->exists()) {
            $this->warn("Directorio [{$repository->path()}] nao existe.");

            return self::SUCCESS;
        }

        $entries = $repository->entries();

        if ($entries === []) {
            $this->warn('Nenhum backup encontrado.');

            return self::SUCCESS;
        }

        $this->info("Directorio: {$repository->path()}");
        $this->newLine();
        $this->table(['Backup', 'Data', 'Ficheiros SQL', 'Tamanho'], array_map(fn ($entry) => [
            $entry['name'],
            $entry['date']?->format('Y-m-d H:i:s') ?? '-',
            $entry['files'] ?? '-',
            $this->formatSize($entry['size']),
        ], $entries));
        $this->newLine();
        $this->info('Total: ' . count($entries) . ' backup(s).');

        return self::SUCCESS;
    }
}
