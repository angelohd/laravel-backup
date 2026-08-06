<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;

class DropDatabaseCommand extends Command
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-database {database} {--connection=} {--force}';
    protected $description = 'Apaga uma base de dados especifica de uma conexao.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysql nao encontrado no PATH.');
            return Command::FAILURE;
        }

        $database = $this->argument('database');
        $specificConnection = $this->option('connection');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $database)) {
            $this->error("Nome de base de dados invalido: [{$database}]");
            return Command::FAILURE;
        }

        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja APAGAR a base de dados [{$database}]?")) {
            return Command::SUCCESS;
        }

        $connections = MySqlConnectionResolver::resolve($specificConnection ?: null);
        $this->applyTimeout();

        $count = 0;

        foreach ($connections as $name => $connection) {
            if ($connection['database'] !== $database) {
                continue;
            }

            $this->warn("Apagando base de dados [{$database}] na conexao [{$name}]...");

            $runner = new MySqlRunner($connection, $this);
            $defaultsArg = $runner->getDefaultsFileArg();

            $command = sprintf(
                'mysql %s -e %s',
                $defaultsArg,
                escapeshellarg("DROP DATABASE IF EXISTS `{$database}`")
            );

            $result = $runner->execute($command);

            if ($result === 0) {
                $count++;
                $this->info("Base de dados [{$database}] apagada com sucesso.");
            } else {
                $this->error("Erro ao apagar base de dados [{$database}] na conexao [{$name}].");
            }
        }

        if ($count === 0) {
            $this->warn("Base de dados [{$database}] nao encontrada em nenhuma conexao.");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
