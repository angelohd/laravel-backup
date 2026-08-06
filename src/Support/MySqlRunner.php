<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Illuminate\Console\Command;

class MySqlRunner
{
    private array $connection;
    private Command $console;
    private ?string $defaultsFile = null;

    public function __construct(array $connection, Command $console)
    {
        $this->connection = $connection;
        $this->console = $console;
    }

    public static function isAvailable(): bool
    {
        $checked = [];

        if (PHP_OS_FAMILY === 'Windows') {
            exec('where mysqldump 2>nul', resultCode: $mysqldumpResult);
            exec('where mysql 2>nul', resultCode: $mysqlResult);
        } else {
            exec('which mysqldump 2>/dev/null', resultCode: $mysqldumpResult);
            exec('which mysql 2>/dev/null', resultCode: $mysqlResult);
        }

        return $mysqldumpResult === 0 && $mysqlResult === 0;
    }

    public function getDefaultsFileArg(): string
    {
        $this->defaultsFile = tempnam(sys_get_temp_dir(), 'mycnf_');

        $content = "[client]\n";
        $content .= "user={$this->connection['username']}\n";
        $content .= "password={$this->connection['password']}\n";

        if (!empty($this->connection['unix_socket'])) {
            $content .= "socket={$this->connection['unix_socket']}\n";
        } else {
            $content .= "host={$this->connection['host']}\n";
            $content .= "port={$this->connection['port']}\n";
        }

        file_put_contents($this->defaultsFile, $content);
        chmod($this->defaultsFile, 0600);

        return '--defaults-extra-file=' . escapeshellarg($this->defaultsFile);
    }

    public function execute(string $command): int
    {
        if ($this->defaultsFile === null) {
            throw new \RuntimeException('getDefaultsFileArg() must be called before execute().');
        }

        try {
            if ($this->console->getOutput()->isVerbose()) {
                $this->console->line("<comment>CMD:</comment> {$command}");
            }

            $redirect = '';

            if (!$this->console->getOutput()->isVerbose()) {
                $redirect = PHP_OS_FAMILY === 'Windows' ? ' 2>nul' : ' 2>/dev/null';
            }

            exec($command . $redirect, $output, $resultCode);

            if ($this->console->getOutput()->isVeryVerbose() && !empty($output)) {
                foreach ($output as $line) {
                    $this->console->line("  {$line}");
                }
            }

            return $resultCode;
        } finally {
            $this->cleanup();
        }
    }

    public function cleanup(): void
    {
        if ($this->defaultsFile !== null && file_exists($this->defaultsFile)) {
            unlink($this->defaultsFile);
        }
        $this->defaultsFile = null;
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
