<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\MySqlClient;
use angelohd\Backup\Support\MySqlConnectionResolver;
use Illuminate\Console\Command;

abstract class BaseCommand extends Command
{
    protected function backupPath(): string
    {
        $path = $this->hasOption('path') ? $this->option('path') : null;

        return $path ?: (string) config('angelohd-backup.default_backup_path');
    }

    protected function requireBinaries(string ...$names): bool
    {
        $missing = MySqlClient::missingBinaries(...$names);

        if ($missing === []) {
            return true;
        }

        $this->error(implode(', ', $missing) . ' nao encontrado. Instale o cliente MySQL ou configure angelohd-backup.binaries.');

        return false;
    }

    /**
     * @return array<string, array>|null null quando a conexao pedida e invalida
     */
    protected function connections(?string $name = null): ?array
    {
        try {
            $connections = MySqlConnectionResolver::resolve($name ?: null);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if ($connections === []) {
            $this->warn('Nenhuma conexao MySQL/MariaDB encontrada.');

            return null;
        }

        return $connections;
    }
}
