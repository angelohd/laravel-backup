<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\FormatsBytes;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\MySqlClient;

class DatabaseInfoCommand extends BaseCommand
{
    use FormatsBytes;

    protected $signature = 'angelohd:database-info {--connection= : Apenas esta conexao}';

    protected $description = 'Mostra informacao das bases de dados: tabelas, numero de registos e tamanho.';

    public function handle(): int
    {
        if (!$this->requireBinaries('mysql')) {
            return self::FAILURE;
        }

        if (($connections = $this->connections($this->option('connection'))) === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($connections as $name => $connection) {
            $database = $connection['database'];

            $this->newLine();
            $this->info("Conexao: {$name}");
            $this->info("Base de dados: {$database}");

            try {
                $tables = $this->tables(new MySqlClient($connection), $database);
            } catch (BackupException $e) {
                $failed = true;
                $this->error($e->getMessage());

                continue;
            }

            if ($tables === []) {
                $this->warn('Nenhuma tabela encontrada.');

                continue;
            }

            $this->info('Total tabelas: ' . count($tables));
            $this->info('Total registos (aprox.): ' . number_format(array_sum(array_column($tables, 'rows'))));
            $this->info('Tamanho total: ' . $this->formatSize(array_sum(array_column($tables, 'size'))));

            $this->newLine();
            $this->table(['Tabela', 'Registos (aprox.)', 'Tamanho'], array_map(fn ($table) => [
                $table['name'],
                number_format($table['rows']),
                $this->formatSize($table['size']),
            ], $tables));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * TABLE_ROWS e uma estimativa em InnoDB.
     *
     * @return array<int, array{name: string, rows: int, size: int}>
     */
    private function tables(MySqlClient $client, string $database): array
    {
        $rows = $client->select(
            'SELECT TABLE_NAME, IFNULL(TABLE_ROWS, 0), COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS size '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . MySqlClient::quoteString($database)
            . ' ORDER BY size DESC'
        );

        return array_map(fn ($row) => [
            'name' => $row[0],
            'rows' => (int) ($row[1] ?? 0),
            'size' => (int) ($row[2] ?? 0),
        ], $rows);
    }
}
