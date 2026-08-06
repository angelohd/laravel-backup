<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RestoreDatabaseCommand extends Command
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:restore-database {file} {--connection=} {--database=} {--gzip} {--force}';
    protected $description = 'Restaura um ficheiro .sql para uma base de dados.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysqldump ou mysql nao encontrados no PATH.');
            return Command::FAILURE;
        }

        $file = $this->argument('file');
        $targetDatabase = $this->option('database');
        $specificConnection = $this->option('connection');
        $isGzip = $this->option('gzip') || str_ends_with(strtolower($file), '.gz');

        if (!File::exists($file)) {
            $this->error("Ficheiro [{$file}] nao encontrado.");
            return Command::FAILURE;
        }

        $ext = $isGzip ? '.sql.gz' : '.sql';
        if (!str_ends_with(strtolower($file), $ext)) {
            $this->warn("O ficheiro [{$file}] nao parece ser um ficheiro {$ext}.");
        }

        if (!$specificConnection) {
            $this->error('Especifique a conexao de destino com --connection=');
            return Command::FAILURE;
        }

        if (!$targetDatabase) {
            $this->error('Especifique a base de dados de destino com --database=');
            return Command::FAILURE;
        }

        $this->applyTimeout();

        $dbMsg = " a base de dados [{$targetDatabase}] na conexao [{$specificConnection}]";
        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja RESTAURAR o ficheiro [{$file}]{$dbMsg}?")) {
            return Command::SUCCESS;
        }

        $connections = MySqlConnectionResolver::resolve($specificConnection);

        if (!isset($connections[$specificConnection])) {
            $this->error("Conexao [{$specificConnection}] nao encontrada ou invalida.");
            return Command::FAILURE;
        }

        $connection = $connections[$specificConnection];

        $this->warn("Restaurando [{$file}] para base de dados [{$targetDatabase}] na conexao [{$specificConnection}]...");

        $runner = new MySqlRunner($connection, $this);
        $defaultsArg = $runner->getDefaultsFileArg();

        if ($isGzip) {
            $command = sprintf(
                'gunzip < %s | mysql %s %s',
                escapeshellarg($file),
                $defaultsArg,
                escapeshellarg($targetDatabase)
            );
        } else {
            $command = sprintf(
                'mysql %s %s < %s',
                $defaultsArg,
                escapeshellarg($targetDatabase),
                escapeshellarg($file)
            );
        }

        $result = $runner->execute($command);

        if ($result === 0) {
            $this->info("Ficheiro [{$file}] restaurado com sucesso na base de dados [{$targetDatabase}].");
            return Command::SUCCESS;
        }

        $this->error("Erro ao restaurar ficheiro na base de dados [{$targetDatabase}].");
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
