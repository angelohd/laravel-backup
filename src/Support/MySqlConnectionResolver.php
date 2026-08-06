<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Illuminate\Support\Facades\Config;

class MySqlConnectionResolver
{
    public static function resolve(?string $specificConnection = null): array
    {
        $connections = Config::get('database.connections', []);

        if ($specificConnection) {
            if (!isset($connections[$specificConnection])) {
                throw new \InvalidArgumentException("Conexao [{$specificConnection}] nao encontrada.");
            }
            $connections = [$specificConnection => $connections[$specificConnection]];
        }

        $result = [];

        foreach ($connections as $name => $config) {
            $driver = $config['driver'] ?? null;
            if (!in_array($driver, ['mysql', 'mariadb'])) {
                continue;
            }

            $database = $config['database'] ?? null;
            $username = $config['username'] ?? null;
            $password = $config['password'] ?? '';
            $port = $config['port'] ?? '3306';
            $host = $config['host'] ?? '127.0.0.1';
            $unixSocket = $config['unix_socket'] ?? null;

            if (!$database || !$username) {
                continue;
            }

            $result[$name] = [
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => $port,
                'host' => $host,
                'unix_socket' => $unixSocket,
            ];
        }

        return $result;
    }
}
