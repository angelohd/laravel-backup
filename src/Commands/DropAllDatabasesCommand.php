<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\MySqlClient;

class DropAllDatabasesCommand extends BaseCommand
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-all-databases {--force : Nao pedir confirmacao}';

    protected $description = 'Apaga todas as bases de dados de todas as conexoes configuradas.';

    public function handle(): int
    {
        if (!$this->requireBinaries('mysql')) {
            return self::FAILURE;
        }

        if (($connections = $this->connections()) === null) {
            return self::FAILURE;
        }

        $this->table(['Conexao', 'Base de dados'], array_map(
            fn ($name, $connection) => [$name, $connection['database']],
            array_keys($connections),
            $connections
        ));

        // Pede confirmacao em qualquer ambiente, nao apenas em producao.
        if (!$this->confirmDangerousOperation('Tem a certeza que deseja APAGAR TODAS estas bases de dados?')) {
            return self::SUCCESS;
        }

        $count = 0;
        $failed = false;

        foreach ($connections as $name => $connection) {
            $database = $connection['database'];
            $this->warn("A apagar a base de dados [{$database}] na conexao [{$name}]...");

            try {
                (new MySqlClient($connection))->statement('DROP DATABASE IF EXISTS ' . MySqlClient::quoteIdentifier($database));
                $count++;
                $this->info("Base de dados [{$database}] apagada.");
            } catch (BackupException $e) {
                $failed = true;
                $this->error($e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Total: {$count} base(s) de dados apagada(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
