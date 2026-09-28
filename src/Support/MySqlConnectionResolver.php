<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use angelohd\Backup\Exceptions\BackupException;
use Illuminate\Support\Facades\Config;

class MySqlConnectionResolver
{
    /**
     * @return array<string, array{database: string, username: string, password: string, port: string|int, host: string, unix_socket: ?string}>
     */
    public static function resolve(?string $specificConnection = null): array
    {
        $connections = Config::get('database.connections', []);
        $drivers = Config::get('angelohd-backup.supported_drivers', ['mysql', 'mariadb']);

        if ($specificConnection) {
            if (!isset($connections[$specificConnection])) {
                throw new BackupException("Conexao [{$specificConnection}] nao encontrada.");
            }

            $driver = $connections[$specificConnection]['driver'] ?? null;
            if (!in_array($driver, $drivers, true)) {
                throw new BackupException("Conexao [{$specificConnection}] usa o driver [{$driver}], que nao e suportado.");
            }

            $connections = [$specificConnection => $connections[$specificConnection]];
        }

        $result = [];

        foreach ($connections as $name => $config) {
            if (!in_array($config['driver'] ?? null, $drivers, true)) {
                continue;
            }

            $database = $config['database'] ?? null;
            $username = $config['username'] ?? null;

            if (!$database || !$username) {
                continue;
            }

            $result[$name] = [
                'database' => (string) $database,
                'username' => (string) $username,
                'password' => (string) ($config['password'] ?? ''),
                'port' => $config['port'] ?? '3306',
                'host' => $config['host'] ?? '127.0.0.1',
                'unix_socket' => $config['unix_socket'] ?? null,
            ];
        }

        return $result;
    }
}
