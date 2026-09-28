<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Concerns\FormatsBytes;
use angelohd\Backup\Support\BackupRepository;
use angelohd\Backup\Support\RemoteBackupStorage;
use Throwable;

class PruneBackupsCommand extends BaseCommand
{
    use ConfirmsInProduction;
    use FormatsBytes;

    protected $signature = 'angelohd:prune-backups
        {--older-than= : Idade minima em dias}
        {--path= : Pasta dos backups}
        {--disk=* : Disk(s) remoto(s) a limpar (por omissao, os de config)}
        {--local-only : Nao limpar disks remotos}
        {--dry-run : Apenas mostrar o que seria apagado}
        {--force : Nao pedir confirmacao}';

    protected $description = 'Apaga backups mais antigos que o numero de dias especificado.';

    public function handle(): int
    {
        $option = $this->option('older-than');
        $olderThan = $option !== null && $option !== ''
            ? (int) $option
            : (int) config('angelohd-backup.prune_older_than_days', 7);

        if ($olderThan < 1) {
            $this->error('--older-than deve ser no minimo 1 dia.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($olderThan);
        $repository = new BackupRepository($this->backupPath());
        $disks = $this->option('local-only') ? [] : ($this->option('disk') ?: (array) config('angelohd-backup.disks', []));

        $local = $repository->olderThan($cutoff);
        $remote = [];
        $failed = false;

        foreach ($disks as $disk) {
            $storage = new RemoteBackupStorage($disk);
            try {
                foreach ($storage->olderThan($cutoff) as $entry) {
                    $remote[] = [$storage, $entry];
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("Erro ao listar o disk [{$disk}]: {$e->getMessage()}");
            }
        }

        if ($local === [] && $remote === []) {
            $this->info("Nenhum backup com mais de {$olderThan} dia(s) encontrado.");

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $totalSize = array_sum(array_column($local, 'size'));
        $rows = array_map(fn ($entry) => ['local', $entry['name'], $this->formatSize($entry['size'])], $local);
        foreach ($remote as [$storage, $entry]) {
            $rows[] = [$storage->disk(), $entry['name'], '-'];
        }

        $this->warn("Backups com mais de {$olderThan} dia(s):");
        $this->table(['Local', 'Backup', 'Tamanho'], $rows);
        $this->warn(sprintf('Total: %d backup(s) - %s localmente', count($rows), $this->formatSize($totalSize)));

        if ($this->option('dry-run')) {
            $this->info('[--dry-run] Nenhum backup foi apagado.');

            return self::SUCCESS;
        }

        if (!$this->confirmDestructiveOperation('Tem a certeza que deseja APAGAR estes backups?')) {
            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($local as $entry) {
            $repository->delete($entry);
            $deleted++;
            $this->info("Apagado: {$entry['name']}");
        }

        foreach ($remote as [$storage, $entry]) {
            try {
                $storage->delete($entry);
                $deleted++;
                $this->info("Apagado: {$entry['name']} ({$storage->disk()})");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("Erro ao apagar {$entry['name']} ({$storage->disk()}): {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("{$deleted} backup(s) apagado(s). Libertado localmente: " . $this->formatSize($totalSize));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
