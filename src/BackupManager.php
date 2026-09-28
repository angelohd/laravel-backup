<?php

declare(strict_types=1);

namespace angelohd\Backup;

use angelohd\Backup\Events\BackupFailed;
use angelohd\Backup\Events\BackupSucceeded;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\BackupNaming;
use angelohd\Backup\Support\Manifest;
use angelohd\Backup\Support\MySqlClient;
use angelohd\Backup\Support\MySqlConnectionResolver;
use angelohd\Backup\Support\RemoteBackupStorage;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/**
 * Logica de backup, independente do Artisan. Pode ser usada em jobs ou controllers:
 *
 *     app(BackupManager::class)->run(gzip: true);
 */
class BackupManager
{
    /** @var (callable(string, string): void)|null */
    private $progress = null;

    /**
     * @param  string[]|null  $disks  disks remotos (null = config)
     * @param  (callable(string $level, string $message): void)|null  $progress
     */
    public function run(
        ?string $connection = null,
        ?string $path = null,
        ?bool $gzip = null,
        ?bool $zip = null,
        ?array $disks = null,
        ?callable $progress = null,
    ): BackupReport {
        $this->progress = $progress;

        $report = new BackupReport(BackupNaming::timestamp());

        try {
            $this->backup(
                $report,
                $connection,
                $path ?: (string) config('angelohd-backup.default_backup_path'),
                $gzip ?? (bool) config('angelohd-backup.compression.enabled', false),
                $zip ?? (bool) config('angelohd-backup.compression.zip', false),
                $disks ?? (array) config('angelohd-backup.disks', []),
            );
        } catch (Throwable $e) {
            $report->errors['*'] = $e->getMessage();
            $this->emit('error', $e->getMessage());
        }

        event($report->successful() ? new BackupSucceeded($report) : new BackupFailed($report));

        return $report;
    }

    private function backup(BackupReport $report, ?string $connection, string $path, bool $gzip, bool $zip, array $disks): void
    {
        if ($missing = MySqlClient::missingBinaries('mysqldump')) {
            throw new BackupException(implode(', ', $missing) . ' nao encontrado. Instale o cliente MySQL ou configure angelohd-backup.binaries.');
        }

        $connections = MySqlConnectionResolver::resolve($connection);

        if ($connections === []) {
            throw new BackupException('Nenhuma conexao MySQL/MariaDB encontrada.');
        }

        $directory = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $report->timestamp;
        File::ensureDirectoryExists($directory);
        $report->directory = $directory;

        foreach ($connections as $name => $config) {
            $this->emit('comment', "A exportar a base de dados [{$config['database']}] (conexao {$name})...");

            try {
                $file = $this->dumpConnection($config, $directory, (string) $name, $gzip);
                $report->files[basename($file)] = [
                    'connection' => (string) $name,
                    'database' => $config['database'],
                    'path' => $file,
                    'size' => (int) filesize($file),
                    'sha256' => (string) hash_file('sha256', $file),
                ];
                $this->emit('info', 'Backup criado: ' . basename($file));
            } catch (Throwable $e) {
                $report->errors[(string) $name] = $e->getMessage();
                $this->emit('error', $e->getMessage());
            }
        }

        if ($report->files === []) {
            File::deleteDirectory($directory);
            $report->directory = null;

            return;
        }

        Manifest::write($directory, $report->files);

        if ($zip) {
            $report->zipPath = $this->zip($directory);
            if ($report->zipPath !== null) {
                $report->directory = null;
            }
        }

        foreach ($disks as $disk) {
            $this->emit('comment', "A enviar para o disk [{$disk}]...");

            try {
                (new RemoteBackupStorage($disk))->upload((string) $report->location());
                $report->uploadedTo[] = $disk;
                $this->emit('info', "Enviado para o disk [{$disk}].");
            } catch (Throwable $e) {
                $report->uploadErrors[$disk] = $e->getMessage();
                $this->emit('error', "Falha ao enviar para o disk [{$disk}]: {$e->getMessage()}");
            }
        }
    }

    /**
     * @return string caminho do ficheiro final (.sql ou .sql.gz)
     */
    private function dumpConnection(array $config, string $directory, string $name, bool $gzip): string
    {
        $sqlFile = $directory . DIRECTORY_SEPARATOR . $name . '.sql';

        try {
            (new MySqlClient($config))->dump($config['database'], $sqlFile);
            $this->assertDumpComplete($sqlFile, $config['database']);

            if (!$gzip) {
                return $sqlFile;
            }

            $gzFile = $sqlFile . '.gz';
            try {
                self::compress($sqlFile, $gzFile);
            } catch (Throwable $e) {
                File::delete($gzFile);
                throw $e;
            }
            File::delete($sqlFile);

            return $gzFile;
        } catch (Throwable $e) {
            // Nunca deixar um dump parcial com aspecto de backup valido.
            File::delete($sqlFile);
            throw $e;
        }
    }

    private function assertDumpComplete(string $sqlFile, string $database): void
    {
        $size = is_file($sqlFile) ? (int) filesize($sqlFile) : 0;

        if ($size === 0) {
            throw new BackupException("O dump da base de dados [{$database}] esta vazio.");
        }

        $handle = fopen($sqlFile, 'rb');
        fseek($handle, max(0, $size - 512));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (!str_contains($tail, '-- Dump completed')) {
            throw new BackupException("O dump da base de dados [{$database}] esta incompleto.");
        }
    }

    public static function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        if ($in === false || $out === false) {
            throw new BackupException("Nao foi possivel comprimir [{$source}].");
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                    throw new BackupException("Erro ao comprimir [{$source}].");
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function zip(string $directory): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->emit('warn', 'ext-zip nao esta disponivel. A pasta foi mantida.');

            return null;
        }

        $this->emit('comment', 'A criar ficheiro ZIP...');

        $name = basename($directory);
        $zipPath = dirname($directory) . DIRECTORY_SEPARATOR . $name . '.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->emit('warn', 'Erro ao criar ficheiro ZIP. A pasta foi mantida.');

            return null;
        }

        foreach (File::allFiles($directory) as $file) {
            $zip->addFile($file->getRealPath(), $name . '/' . str_replace('\\', '/', $file->getRelativePathname()));
        }

        if (!$zip->close()) {
            File::delete($zipPath);
            $this->emit('warn', 'Erro ao gravar ficheiro ZIP. A pasta foi mantida.');

            return null;
        }

        File::deleteDirectory($directory);

        return $zipPath;
    }

    private function emit(string $level, string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($level, $message);
        }
    }
}
