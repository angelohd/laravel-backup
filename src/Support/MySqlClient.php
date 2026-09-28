<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use angelohd\Backup\Exceptions\BackupException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Executa mysqldump/mysql sem passar pela shell: os argumentos sao enviados
 * como array, o codigo de saida e o do proprio binario e o stderr e capturado.
 */
class MySqlClient
{
    public function __construct(private array $connection) {}

    public static function binary(string $name): string
    {
        return (string) (config("angelohd-backup.binaries.{$name}") ?: $name);
    }

    /**
     * @return string[] binarios que nao foram encontrados
     */
    public static function missingBinaries(string ...$names): array
    {
        $finder = new ExecutableFinder;

        return array_values(array_filter($names, function (string $name) use ($finder) {
            $binary = self::binary($name);

            return !is_file($binary) && $finder->find($binary) === null;
        }));
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function quoteString(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    public function dump(string $database, string $resultFile): void
    {
        $result = $this->run('mysqldump', [
            ...$this->dumpOptions(),
            '--result-file=' . $resultFile,
            $database,
        ]);

        $this->ensureSuccessful($result, "mysqldump falhou para a base de dados [{$database}]");
    }

    /**
     * @param  resource|iterable<string>  $input
     */
    public function restore($input, string $database): void
    {
        $arguments = [];
        $initCommand = $this->restoreInitCommand();

        if ($initCommand !== '') {
            $arguments[] = '--init-command=' . $initCommand;
        }

        $arguments[] = $database;

        $result = $this->run('mysql', $arguments, $input);

        $this->ensureSuccessful($result, "Restauro falhou na base de dados [{$database}]");
    }

    /**
     * Executa SQL enviado por stdin (evita limites de tamanho da linha de comandos).
     */
    public function statement(string $sql, ?string $database = null): void
    {
        $result = $this->run('mysql', $database !== null ? [$database] : [], $sql);

        $this->ensureSuccessful($result, 'Erro ao executar SQL');
    }

    /**
     * @return array<int, string[]>
     */
    public function select(string $sql): array
    {
        $result = $this->run('mysql', ['--batch', '--skip-column-names'], $sql);

        $this->ensureSuccessful($result, 'Erro ao executar consulta');

        $rows = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = array_map([self::class, 'unescapeBatchValue'], explode("\t", $line));
        }

        return $rows;
    }

    private static function unescapeBatchValue(string $value): string
    {
        return strtr($value, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"]);
    }

    /**
     * @param  string|resource|iterable<string>|null  $input
     */
    private function run(string $binary, array $arguments, $input = null): ProcessResult
    {
        $defaults = DefaultsFile::create($this->connection);

        try {
            $timeout = (int) config('angelohd-backup.timeout', 0);
            $process = $timeout > 0 ? Process::timeout($timeout) : Process::forever();

            if ($input !== null) {
                $process->input($input);
            }

            // --defaults-extra-file tem de ser o primeiro argumento.
            return $process->run([self::binary($binary), $defaults->argument(), ...$arguments]);
        } finally {
            $defaults->delete();
        }
    }

    private function ensureSuccessful(ProcessResult $result, string $message): void
    {
        if ($result->successful()) {
            return;
        }

        $error = trim($result->errorOutput()) ?: trim($result->output());

        throw new BackupException($message . ($error !== '' ? ": {$error}" : " (codigo {$result->exitCode()})."));
    }

    /**
     * @return string[]
     */
    private function dumpOptions(): array
    {
        $options = [];

        foreach ([
            'single_transaction' => '--single-transaction',
            'routines' => '--routines',
            'triggers' => '--triggers',
            'skip_lock_tables' => '--skip-lock-tables',
        ] as $key => $flag) {
            if (config("angelohd-backup.mysqldump.{$key}", true)) {
                $options[] = $flag;
            }
        }

        if ($maxAllowedPacket = config('angelohd-backup.mysqldump.max_allowed_packet')) {
            $options[] = '--max-allowed-packet=' . $maxAllowedPacket;
        }

        if ($netBufferLength = config('angelohd-backup.mysqldump.net_buffer_length')) {
            $options[] = '--net-buffer-length=' . $netBufferLength;
        }

        return $options;
    }

    private function restoreInitCommand(): string
    {
        $settings = [];

        if (config('angelohd-backup.restore.disable_foreign_key_checks', true)) {
            $settings[] = 'FOREIGN_KEY_CHECKS=0';
        }

        if (config('angelohd-backup.restore.disable_unique_checks', true)) {
            $settings[] = 'UNIQUE_CHECKS=0';
        }

        return $settings === [] ? '' : 'SET ' . implode(', ', $settings);
    }
}
