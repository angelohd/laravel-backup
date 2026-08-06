<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;

class DropAllDatabasesCommand extends Command
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-all-databases {--force}';
    protected $description = 'Apaga todas as bases de dados de todas as conexoes configuradas.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysql nao encontrado no PATH.');
            return Command::FAILURE;
        }

        if (!$this->confirmDestructiveOperation('Tem a certeza que deseja APAGAR TODAS as bases de dados de TODAS as conexoes?')) {
            return Command::SUCCESS;
        }

        $connections = MySqlConnectionResolver::resolve();
        $this->applyTimeout();

        $count = 0;
        $errors = 0;

        foreach ($connections as $name => $connection) {
            $database = $connection['database'];

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
                $this->info("Base de dados [{$database}] apagada.");
            } else {
                $errors++;
                $this->error("Erro ao apagar base de dados [{$database}] na conexao [{$name}].");
            }
        }

        if ($count > 0) {
            $this->newLine();
            $this->info("Total: {$count} base(s) de dados apagada(s).");
        } else {
            $this->warn('Nenhuma base de dados foi apagada.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
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
