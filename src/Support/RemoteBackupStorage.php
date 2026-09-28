<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use angelohd\Backup\Exceptions\BackupException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Copia de backups para disks do Laravel (s3, ftp, sftp, ...).
 */
class RemoteBackupStorage
{
    public function __construct(private string $disk) {}

    public function disk(): string
    {
        return $this->disk;
    }

    /**
     * Envia uma pasta de backup ou um ficheiro .zip.
     */
    public function upload(string $localPath): void
    {
        $name = basename($localPath);

        if (File::isDirectory($localPath)) {
            foreach (File::allFiles($localPath) as $file) {
                $this->put($file->getRealPath(), $this->remotePath($name . '/' . str_replace('\\', '/', $file->getRelativePathname())));
            }

            return;
        }

        $this->put($localPath, $this->remotePath($name));
    }

    /**
     * @return array<int, array{name: string, path: string, type: string}>
     */
    public function olderThan(Carbon $cutoff): array
    {
        $storage = Storage::disk($this->disk);
        $base = $this->remotePath('');
        $entries = [];

        foreach ($storage->directories($base) as $directory) {
            $entries[] = ['name' => basename($directory), 'path' => $directory, 'type' => 'dir'];
        }

        foreach ($storage->files($base) as $file) {
            if (str_ends_with(strtolower($file), '.zip')) {
                $entries[] = ['name' => basename($file), 'path' => $file, 'type' => 'zip'];
            }
        }

        return array_values(array_filter($entries, function ($entry) use ($cutoff) {
            $date = BackupNaming::parse($entry['name']);

            return $date !== null && $date->lt($cutoff);
        }));
    }

    /**
     * @param  array{name: string, path: string, type: string}  $entry
     */
    public function delete(array $entry): void
    {
        $storage = Storage::disk($this->disk);

        $entry['type'] === 'zip'
            ? $storage->delete($entry['path'])
            : $storage->deleteDirectory($entry['path']);
    }

    private function put(string $localFile, string $remotePath): void
    {
        $stream = fopen($localFile, 'rb');

        if ($stream === false) {
            throw new BackupException("Nao foi possivel abrir [{$localFile}] para envio.");
        }

        try {
            if (!Storage::disk($this->disk)->writeStream($remotePath, $stream)) {
                throw new BackupException("Falha ao enviar [{$remotePath}] para o disk [{$this->disk}].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function remotePath(string $path): string
    {
        $base = trim((string) config('angelohd-backup.remote_path', 'backups-databases'), '/');

        return ltrim($base . '/' . $path, '/');
    }
}
