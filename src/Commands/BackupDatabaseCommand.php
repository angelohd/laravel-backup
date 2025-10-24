<?php

namespace angelohd\Backup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'angelohd:backup-database {--path=}';
    protected $description = 'Cria backup das bases de dados configuradas em config/database.php.';
    public function handle()
    {
        $pasta_bkp = date('Y-m-d_H-i-s');
        $this->info('🔄 Iniciando backup das bases de dados configuradas...');

        $connections = Config::get('database.connections', []);
        $backupDir = $this->option('path') ?? storage_path('app/backups-databases/' . $pasta_bkp);
        File::ensureDirectoryExists($backupDir);

        $count = 0;

        foreach ($connections as $name => $config) {
            $driver = $config['driver'] ?? null;
            if (!in_array($driver, ['mysql', 'mariadb'])) {
                $this->warn("⏭️ Ignorando conexão [$name] (driver [$driver] não é suportado).");
                continue;
            }

            $database = $config['database'];
            $username = $config['username'];
            $password = $config['password'];
            $port = $config['port'];
            $host = $config['host'] ?? '127.0.0.1';

            $fileName = "{$name}_{$database}.sql";
            $filePath = $backupDir . '/' . $fileName;

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->comment("ℹ️ Executando backup da base de dado: {$database} aguarde...");
                $command = sprintf(
                    'mysqldump --user=%s --password=%s --host=%s --port=%s %s > %s',
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($database),
                    escapeshellarg($filePath)
                );

                if (PHP_OS_FAMILY === 'Windows') {
                    exec($command . ' 2>nul', $output, $result);
                } else {
                    exec($command . ' 2>/dev/null', $output, $result);
                }
                //exec($command, $output, $result);

                if ($result === 0) {
                    $count++;
                    $this->info("✅ Backup criado: {$fileName}");
                } else {
                    $this->error("⚠️ Erro ao criar backup da base de dados: {$database}");
                }
            }
        }

        if ($count > 0) {
            $this->info("\n🎉 Backup concluído com sucesso! Total: {$count} base(s) de dados.");
        } else {
            $this->warn("Nenhuma base de dados foi exportada.");
        }

        return Command::SUCCESS;
    }
}
