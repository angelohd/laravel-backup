<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;

class DropAllTablesCommand extends Command
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:drop-all-tables {--connection=} {--force}';
    protected $description = 'Apaga todas as tabelas de uma conexao especifica.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysqldump e mysql nao encontrados no PATH.');
            return Command::FAILURE;
        }

        $specificConnection = $this->option('connection');

        if (!$specificConnection) {
            $this->error('Especifique a conexao com --connection=');
            return Command::FAILURE;
        }

        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja APAGAR TODAS AS TABELAS da conexao [{$specificConnection}]?")) {
            return Command::SUCCESS;
        }

        $connections = MySqlConnectionResolver::resolve($specificConnection);

        if (!isset($connections[$specificConnection])) {
            $this->error("Conexao [{$specificConnection}] nao encontrada ou invalida.");
            return Command::FAILURE;
        }

        $connection = $connections[$specificConnection];
        $database = $connection['database'];

        $this->applyTimeout();
        $this->warn("Apagando todas as tabelas da base de dados [{$database}]...");

        $runner = new MySqlRunner($connection, $this);
        $defaultsArg = $runner->getDefaultsFileArg();

        $filterCmd = PHP_OS_FAMILY === 'Windows' ? 'findstr "^DROP"' : 'grep "^DROP"';

        $command = sprintf(
            'mysqldump %s --no-data --add-drop-table %s 2>/dev/null | %s | mysql %s %s',
            $defaultsArg,
            escapeshellarg($database),
            $filterCmd,
            $defaultsArg,
            escapeshellarg($database)
        );

        $result = $runner->execute($command);

        if ($result === 0) {
            $this->info("Todas as tabelas da base de dados [{$database}] foram apagadas.");
            return Command::SUCCESS;
        }

        $this->error("Erro ao apagar tabelas da base de dados [{$database}].");
        return Command::FAILURE;
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
