<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use angelohd\Backup\Exceptions\BackupException;

/**
 * Ficheiro temporario de opcoes do cliente MySQL (--defaults-extra-file),
 * usado para nao expor credenciais na linha de comandos.
 */
class DefaultsFile
{
    private ?string $path;

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function create(array $connection): self
    {
        $path = tempnam(sys_get_temp_dir(), 'mycnf_');

        if ($path === false) {
            throw new BackupException('Nao foi possivel criar o ficheiro temporario de credenciais.');
        }

        chmod($path, 0600);
        file_put_contents($path, self::contents($connection));

        return new self($path);
    }

    public static function contents(array $connection): string
    {
        $lines = ['[client]'];
        $lines[] = 'user=' . self::quote((string) $connection['username']);
        $lines[] = 'password=' . self::quote((string) ($connection['password'] ?? ''));

        if (!empty($connection['unix_socket'])) {
            $lines[] = 'socket=' . self::quote((string) $connection['unix_socket']);
        } else {
            $lines[] = 'host=' . self::quote((string) $connection['host']);
            $lines[] = 'port=' . (int) $connection['port'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Coloca o valor entre aspas para que caracteres como #, ; ou espacos
     * nao sejam interpretados pelo parser de ficheiros de opcoes do MySQL.
     */
    public static function quote(string $value): string
    {
        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]) . '"';
    }

    public function argument(): string
    {
        if ($this->path === null) {
            throw new BackupException('O ficheiro de credenciais ja foi apagado.');
        }

        return '--defaults-extra-file=' . $this->path;
    }

    public function delete(): void
    {
        if ($this->path !== null && file_exists($this->path)) {
            unlink($this->path);
        }

        $this->path = null;
    }

    public function __destruct()
    {
        $this->delete();
    }
}
