<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Backups locais: pastas "<timestamp>/" e ficheiros "<timestamp>.zip".
 *
 * @phpstan-type Entry array{name: string, path: string, type: string, date: ?Carbon, size: int, files: ?int}
 */
class BackupRepository
{
    public function __construct(private string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return File::isDirectory($this->path);
    }

    /**
     * @return array<int, Entry> do mais recente para o mais antigo
     */
    public function entries(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $entries = [];

        foreach (File::directories($this->path) as $folder) {
            $files = File::allFiles($folder);

            $entries[] = [
                'name' => basename($folder),
                'path' => $folder,
                'type' => 'dir',
                'date' => BackupNaming::parse(basename($folder)),
                'size' => array_sum(array_map(fn ($file) => $file->getSize(), $files)),
                'files' => count(array_filter($files, fn ($file) => self::isDumpFile($file->getFilename()))),
            ];
        }

        foreach (File::glob($this->path . DIRECTORY_SEPARATOR . '*.zip') as $zipFile) {
            $entries[] = [
                'name' => basename($zipFile),
                'path' => $zipFile,
                'type' => 'zip',
                'date' => BackupNaming::parse(basename($zipFile)),
                'size' => (int) filesize($zipFile),
                'files' => null,
            ];
        }

        // Entradas sem data reconhecivel ficam no fim.
        usort($entries, fn ($a, $b) => ($b['date']?->getTimestamp() ?? PHP_INT_MIN) <=> ($a['date']?->getTimestamp() ?? PHP_INT_MIN));

        return $entries;
    }

    /**
     * @return Entry|null
     */
    public function latest(): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['date'] !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int, Entry>
     */
    public function olderThan(Carbon $cutoff): array
    {
        return array_values(array_filter(
            $this->entries(),
            fn ($entry) => $entry['date'] !== null && $entry['date']->lt($cutoff)
        ));
    }

    /**
     * @param  Entry  $entry
     */
    public function delete(array $entry): void
    {
        $entry['type'] === 'zip'
            ? File::delete($entry['path'])
            : File::deleteDirectory($entry['path']);
    }

    public static function isDumpFile(string $fileName): bool
    {
        return str_ends_with($fileName, '.sql') || str_ends_with($fileName, '.sql.gz');
    }
}
