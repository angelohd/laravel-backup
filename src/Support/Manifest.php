<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Illuminate\Support\Carbon;

/**
 * manifest.json guardado em cada backup com o checksum SHA-256 de cada dump.
 */
class Manifest
{
    public const FILE = 'manifest.json';

    /**
     * @param  array<string, array{connection: string, database: string, path: string, size: int, sha256: string}>  $files
     */
    public static function write(string $directory, array $files): void
    {
        $data = [
            'created_at' => Carbon::now()->toIso8601String(),
            'app' => config('app.name'),
            'files' => [],
        ];

        foreach ($files as $fileName => $file) {
            $data['files'][$fileName] = [
                'connection' => $file['connection'],
                'database' => $file['database'],
                'size' => $file['size'],
                'sha256' => $file['sha256'],
            ];
        }

        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . self::FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * Verifica o checksum de um dump com base no manifest.json da mesma pasta.
     *
     * @return bool|null null quando nao existe manifest (backups antigos)
     */
    public static function verify(string $dumpFile): ?bool
    {
        $manifestPath = dirname($dumpFile) . DIRECTORY_SEPARATOR . self::FILE;

        if (!is_file($manifestPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($manifestPath), true);
        $expected = $data['files'][basename($dumpFile)]['sha256'] ?? null;

        if (!is_string($expected)) {
            return null;
        }

        return hash_equals($expected, (string) hash_file('sha256', $dumpFile));
    }
}
