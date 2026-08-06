<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;

class DatabaseInfoCommand extends Command
{
    protected $signature = 'angelohd:database-info {--connection=}';
    protected $description = 'Mostra informacao das bases de dados: tabelas, numero de registos e tamanho.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysql nao encontrado no PATH.');
            return Command::FAILURE;
        }

        $connections = MySqlConnectionResolver::resolve($this->option('connection') ?: null);
        $this->applyTimeout();

        $found = false;

        foreach ($connections as $name => $connection) {
            $database = $connection['database'];
            $found = true;

            $tables = $this->getTables($connection);

            $this->newLine();
            $this->info("Conexao: {$name}");
            $this->info("Base de dados: {$database}");

            if ($tables === false) {
                $this->error("Erro ao obter informacao da base de dados [{$database}].");
                continue;
            }

            if (empty($tables)) {
                $this->warn('Nenhuma tabela encontrada.');
                $this->info('Tamanho total: 0 B');
                continue;
            }

            $dbSize = array_sum(array_column($tables, 'size'));
            $dbRows = array_sum(array_column($tables, 'rows'));

            $this->info('Total tabelas: ' . count($tables));
            $this->info('Total registos: ' . number_format($dbRows));
            $this->info('Tamanho total: ' . $this->formatSize($dbSize));

            $this->newLine();
            $this->table(['Tabela', 'Registos', 'Tamanho'], array_map(function ($table) {
                return [
                    'nome' => $table['name'],
                    'registos' => number_format($table['rows']),
                    'tamanho' => $this->formatSize($table['size']),
                ];
            }, $tables));
        }

        if (!$found) {
            $this->warn('Nenhuma conexao MySQL/MariaDB encontrada.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getTables(array $connection): array|false
    {
        $sql = sprintf(
            "SELECT TABLE_NAME, IFNULL(TABLE_ROWS, 0), COALESCE(data_length, 0) + COALESCE(index_length, 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s' ORDER BY (COALESCE(data_length, 0) + COALESCE(index_length, 0)) DESC",
            str_replace(["\\", "'"], ["\\\\", "\\'"], $connection['database'])
        );

        $runner = new MySqlRunner($connection, $this);
        $defaultsArg = $runner->getDefaultsFileArg();

        $command = sprintf(
            'mysql %s --batch --skip-column-names -e %s',
            $defaultsArg,
            escapeshellarg($sql)
        );

        $output = [];
        $redirect = '';
        if (!$this->getOutput()->isVerbose()) {
            $redirect = PHP_OS_FAMILY === 'Windows' ? ' 2>nul' : ' 2>/dev/null';
        }

        exec($command . $redirect, $output, $resultCode);
        $runner->cleanup();

        if ($resultCode !== 0) {
            return false;
        }

        $tables = [];
        foreach ($output as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) >= 3) {
                $rowCount = is_numeric($parts[1]) ? (int) $parts[1] : 0;
                $tables[] = [
                    'name' => $parts[0],
                    'rows' => $rowCount,
                    'size' => (int) $parts[2],
                ];
            }
        }

        return $tables;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    private function applyTimeout(): void
    {
        $timeout = (int) config('angelohd-backup.timeout', 0);
        if ($timeout > 0) {
            set_time_limit($timeout);
        } else {
            set_time_limit(0);
        }
    }
}
