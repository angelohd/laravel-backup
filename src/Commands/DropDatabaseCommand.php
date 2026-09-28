<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\MySqlClient;

class DropDatabaseCommand extends BaseCommand
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-database
        {database : Nome da base de dados}
        {--connection= : Procurar apenas nesta conexao}
        {--force : Nao pedir confirmacao}';

    protected $description = 'Apaga uma base de dados configurada numa conexao.';

    public function handle(): int
    {
        if (!$this->requireBinaries('mysql')) {
            return self::FAILURE;
        }

        $database = (string) $this->argument('database');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $database)) {
            $this->error("Nome de base de dados invalido: [{$database}]");

            return self::FAILURE;
        }

        if (($connections = $this->connections($this->option('connection'))) === null) {
            return self::FAILURE;
        }

        $matches = array_filter($connections, fn ($connection) => $connection['database'] === $database);

        if ($matches === []) {
            $this->warn("Base de dados [{$database}] nao encontrada em nenhuma conexao.");

            return self::FAILURE;
        }

        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja APAGAR a base de dados [{$database}]?")) {
            return self::SUCCESS;
        }

        $failed = false;

        foreach ($matches as $name => $connection) {
            $this->warn("A apagar a base de dados [{$database}] na conexao [{$name}]...");

            try {
                (new MySqlClient($connection))->statement('DROP DATABASE IF EXISTS ' . MySqlClient::quoteIdentifier($database));
                $this->info("Base de dados [{$database}] apagada com sucesso.");
            } catch (BackupException $e) {
                $failed = true;
                $this->error($e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
