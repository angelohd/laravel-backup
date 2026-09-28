<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\Concerns\ConfirmsInProduction;
use angelohd\Backup\Exceptions\BackupException;
use angelohd\Backup\Support\BackupRepository;
use angelohd\Backup\Support\DefinerFilter;
use angelohd\Backup\Support\Manifest;
use angelohd\Backup\Support\MySqlClient;
use Illuminate\Support\Facades\File;
use ZipArchive;

class RestoreDatabaseCommand extends BaseCommand
{
    use ConfirmsInProduction;

    protected $signature = 'angelohd:restore-database
        {file? : Ficheiro .sql ou .sql.gz}
        {--connection= : Conexao de destino}
        {--database= : Base de dados de destino}
        {--latest : Usar o backup mais recente desta conexao}
        {--path= : Pasta dos backups (com --latest)}
        {--gzip : Forcar leitura como gzip}
        {--create : Criar a base de dados se nao existir}
        {--skip-verify : Nao verificar o checksum do manifest.json}
        {--force : Nao pedir confirmacao}';

    protected $description = 'Restaura um ficheiro .sql ou .sql.gz para uma base de dados.';

    private ?string $tempDirectory = null;

    public function handle(): int
    {
        try {
            return $this->restore();
        } finally {
            if ($this->tempDirectory !== null) {
                File::deleteDirectory($this->tempDirectory);
            }
        }
    }

    private function restore(): int
    {
        if (!$this->requireBinaries('mysql')) {
            return self::FAILURE;
        }

        $connectionName = $this->option('connection');
        $targetDatabase = $this->option('database');

        if (!$connectionName) {
            $this->error('Especifique a conexao de destino com --connection=');

            return self::FAILURE;
        }

        if (!$targetDatabase) {
            $this->error('Especifique a base de dados de destino com --database=');

            return self::FAILURE;
        }

        if (($connections = $this->connections($connectionName)) === null) {
            return self::FAILURE;
        }

        $file = $this->resolveFile($connectionName);

        if ($file === null) {
            return self::FAILURE;
        }

        $isGzip = $this->option('gzip') || str_ends_with(strtolower($file), '.gz');

        if (!str_ends_with(strtolower($file), $isGzip ? '.sql.gz' : '.sql')) {
            $this->warn("O ficheiro [{$file}] nao parece ser um ficheiro " . ($isGzip ? '.sql.gz' : '.sql') . '.');
        }

        if (!$this->option('skip-verify')) {
            $verified = Manifest::verify($file);

            if ($verified === false) {
                $this->error("Checksum invalido: o ficheiro [{$file}] esta corrompido ou foi alterado. Use --skip-verify para ignorar.");

                return self::FAILURE;
            }

            $this->comment($verified ? 'Checksum SHA-256 verificado.' : 'Sem manifest.json: checksum nao verificado.');
        }

        if (!$this->confirmDestructiveOperation("Tem a certeza que deseja RESTAURAR [{$file}] na base de dados [{$targetDatabase}] da conexao [{$connectionName}]?")) {
            return self::SUCCESS;
        }

        $client = new MySqlClient($connections[$connectionName]);

        $this->warn("A restaurar [{$file}] para a base de dados [{$targetDatabase}]...");

        $input = fopen(($isGzip ? 'compress.zlib://' : '') . $file, 'rb');

        if ($input === false) {
            $this->error("Nao foi possivel abrir [{$file}].");

            return self::FAILURE;
        }

        try {
            if ($this->option('create')) {
                $client->statement('CREATE DATABASE IF NOT EXISTS ' . MySqlClient::quoteIdentifier($targetDatabase));
            }

            $client->restore(
                config('angelohd-backup.restore.strip_definers', true) ? DefinerFilter::chunks($input) : $input,
                $targetDatabase
            );
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        $this->info("Ficheiro restaurado com sucesso na base de dados [{$targetDatabase}].");

        return self::SUCCESS;
    }

    private function resolveFile(string $connectionName): ?string
    {
        if (!$this->option('latest')) {
            $file = $this->argument('file');

            if (!$file) {
                $this->error('Indique o ficheiro a restaurar ou use --latest.');

                return null;
            }

            if (!File::isFile($file)) {
                $this->error("Ficheiro [{$file}] nao encontrado.");

                return null;
            }

            return $file;
        }

        $latest = (new BackupRepository($this->backupPath()))->latest();

        if ($latest === null) {
            $this->error("Nenhum backup encontrado em [{$this->backupPath()}].");

            return null;
        }

        $this->comment("Backup mais recente: {$latest['name']}");

        $file = $latest['type'] === 'zip'
            ? $this->extractFromZip($latest['path'], $connectionName)
            : $this->findDump($latest['path'], $connectionName);

        if ($file === null) {
            $this->error("O backup [{$latest['name']}] nao contem a conexao [{$connectionName}].");
        }

        return $file;
    }

    private function findDump(string $directory, string $connectionName): ?string
    {
        foreach (['.sql.gz', '.sql'] as $ext) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $connectionName . $ext;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractFromZip(string $zipPath, string $connectionName): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->error('ext-zip nao esta disponivel para ler o ficheiro ZIP.');

            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            $this->error("Nao foi possivel abrir [{$zipPath}].");

            return null;
        }

        $folder = basename($zipPath, '.zip');
        $wanted = array_map(
            fn ($name) => "{$folder}/{$name}",
            [$connectionName . '.sql.gz', $connectionName . '.sql', Manifest::FILE]
        );
        $entries = array_values(array_filter($wanted, fn ($entry) => $zip->locateName($entry) !== false));

        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'angelohd-restore-' . bin2hex(random_bytes(6));
        $extracted = $entries !== [] && $zip->extractTo($this->tempDirectory, $entries);
        $zip->close();

        return $extracted ? $this->findDump($this->tempDirectory . DIRECTORY_SEPARATOR . $folder, $connectionName) : null;
    }
}
