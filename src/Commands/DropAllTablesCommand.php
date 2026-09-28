<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\MySqlClient;

class DropAllTablesCommand extends BaseCommand
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-all-tables
        {--connection= : Conexao cuja base de dados sera limpa}
        {--force : Nao pedir confirmacao}';

    protected $description = 'Apaga todas as tabelas e views de uma conexao especifica.';

    public function handle(): int
    {
        if (!$this->requireBinaries('mysql')) {
            return self::FAILURE;
        }

        $connectionName = $this->option('connection');

        if (!$connectionName) {
            $this->error('Especifique a conexao com --connection=');

            return self::FAILURE;
        }

        if (($connections = $this->connections($connectionName)) === null) {
            return self::FAILURE;
        }

        $connection = $connections[$connectionName];
        $database = $connection['database'];

        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja APAGAR TODAS AS TABELAS da base de dados [{$database}] (conexao {$connectionName})?")) {
            return self::SUCCESS;
        }

        $client = new MySqlClient($connection);

        try {
            $objects = $client->select(
                'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
                . MySqlClient::quoteString($database)
            );

            if ($objects === []) {
                $this->info("A base de dados [{$database}] ja nao tem tabelas.");

                return self::SUCCESS;
            }

            $this->warn('A apagar ' . count($objects) . " tabela(s)/view(s) da base de dados [{$database}]...");
            $client->statement($this->dropStatements($objects), $database);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Todas as tabelas da base de dados [{$database}] foram apagadas.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string[]>  $objects  pares [nome, tipo]
     */
    private function dropStatements(array $objects): string
    {
        $views = [];
        $tables = [];

        foreach ($objects as [$name, $type]) {
            if ($type === 'VIEW') {
                $views[] = MySqlClient::quoteIdentifier($name);
            } else {
                $tables[] = MySqlClient::quoteIdentifier($name);
            }
        }

        // Sem FOREIGN_KEY_CHECKS=0 a ordem das tabelas faria o DROP falhar.
        $sql = "SET FOREIGN_KEY_CHECKS=0;\n";

        if ($views !== []) {
            $sql .= 'DROP VIEW IF EXISTS ' . implode(', ', $views) . ";\n";
        }

        if ($tables !== []) {
            $sql .= 'DROP TABLE IF EXISTS ' . implode(', ', $tables) . ";\n";
        }

        return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
    }
}
