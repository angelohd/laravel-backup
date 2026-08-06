<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\MySqlRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'angelohd:backup-database {--path=} {--connection=} {--gzip} {--zip}';
    protected $description = 'Cria backup das bases de dados configuradas em config/database.php.';

    public function handle(): int
    {
        if (!MySqlRunner::isAvailable()) {
            $this->error('mysqldump ou mysql nao encontrados no PATH.');
            return Command::FAILURE;
        }

        $timestamp = date('d-m-Y_H-i-s');
        $this->info('Iniciando backup das bases de dados configuradas...');

        $backupPath = $this->option('path')
            ?: config('angelohd-backup.default_backup_path', storage_path('app/backups-databases'));

        $backupDir = $backupPath . DIRECTORY_SEPARATOR . $timestamp;
        File::ensureDirectoryExists($backupDir);

        $connections = MySqlConnectionResolver::resolve($this->option('connection') ?: null);

        if (empty($connections)) {
            $this->warn('Nenhuma conexao MySQL/MariaDB encontrada.');
            return Command::FAILURE;
        }

        $this->applyTimeout();
        $useGzip = $this->option('gzip') || config('angelohd-backup.compression.enabled', false);
        $useZip = $this->option('zip') || config('angelohd-backup.compression.zip', false);

        $count = 0;
        $errors = 0;

        foreach ($connections as $name => $connection) {
            $database = $connection['database'];
            $ext = $useGzip ? '.sql.gz' : '.sql';
            $fileName = "{$name}{$ext}";
            $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

            $this->comment("Executando backup da base de dado: {$database} aguarde...");

            $runner = new MySqlRunner($connection, $this);
            $defaultsArg = $runner->getDefaultsFileArg();

            $mysqldumpOptions = $this->buildMysqldumpOptions();

            if ($useGzip) {
                $command = sprintf(
                    'mysqldump %s %s %s | gzip > %s',
                    $defaultsArg,
                    $mysqldumpOptions,
                    escapeshellarg($database),
                    escapeshellarg($filePath)
                );
            } else {
                $command = sprintf(
                    'mysqldump %s %s %s > %s',
                    $defaultsArg,
                    $mysqldumpOptions,
                    escapeshellarg($database),
                    escapeshellarg($filePath)
                );
            }

            $result = $runner->execute($command);

            if ($result === 0) {
                $count++;
                $this->info("Backup criado: {$fileName}");
            } else {
                $errors++;
                $this->error("Erro ao criar backup da base de dados: {$database}");
            }
        }

        if ($count > 0) {
            if ($useZip) {
                $zipPath = $this->zipBackup($backupDir, $backupPath, $timestamp);
                if ($zipPath !== null) {
                    $this->newLine();
                    $this->info("Backup concluido com sucesso! Total: {$count} base(s) de dados.");
                    $this->info("Ficheiro ZIP: {$zipPath}");
                } else {
                    $this->newLine();
                    $this->info("Backup concluido com sucesso! Total: {$count} base(s) de dados.");
                    $this->info("Directorio: {$backupDir}");
                    $this->warn('Nao foi possivel criar ZIP. A pasta foi mantida.');
                }
            } else {
                $this->newLine();
                $this->info("Backup concluido com sucesso! Total: {$count} base(s) de dados.");
                $this->info("Directorio: {$backupDir}");
            }
        } else {
            $this->warn('Nenhuma base de dados foi exportada.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function zipBackup(string $backupDir, string $backupPath, string $timestamp): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->warn('ext-zip nao esta disponivel. Instale ext-zip para usar a funcionalidade de ZIP.');

            return null;
        }

        $this->comment('A criar ficheiro ZIP...');

        $zipPath = $backupPath . DIRECTORY_SEPARATOR . $timestamp . '.zip';

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Erro ao criar ficheiro ZIP.');

            return null;
        }

        $files = File::allFiles($backupDir);

        foreach ($files as $file) {
            $localName = $timestamp . '/' . $file->getRelativePathname();
            $zip->addFile($file->getRealPath(), $localName);
        }

        $zip->close();

        File::deleteDirectory($backupDir);

        $this->info('Pasta apagada. Apenas o ZIP foi mantido.');

        return $zipPath;
    }

    private function buildMysqldumpOptions(): string
    {
        $options = '';

        if (config('angelohd-backup.mysqldump.single_transaction', true)) {
            $options .= ' --single-transaction';
        }

        if (config('angelohd-backup.mysqldump.routines', true)) {
            $options .= ' --routines';
        }

        if (config('angelohd-backup.mysqldump.triggers', true)) {
            $options .= ' --triggers';
        }

        if (config('angelohd-backup.mysqldump.skip_lock_tables', true)) {
            $options .= ' --skip-lock-tables';
        }

        $maxAllowedPacket = config('angelohd-backup.mysqldump.max_allowed_packet');
        if ($maxAllowedPacket) {
            $options .= ' --max-allowed-packet=' . escapeshellarg((string) $maxAllowedPacket);
        }

        $netBufferLength = config('angelohd-backup.mysqldump.net_buffer_length');
        if ($netBufferLength) {
            $options .= ' --net-buffer-length=' . escapeshellarg((string) $netBufferLength);
        }

        return $options;
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
