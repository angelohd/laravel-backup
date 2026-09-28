<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Feature;

use angelohd\Backup\Support\BackupRepository;
use angelohd\Backup\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class BackupRepositoryTest extends TestCase
{
    public function test_sorts_entries_chronologically_mixing_formats(): void
    {
        foreach (['28-08-2026_10-00-00', '2026-09-01_10-00-00', 'manual'] as $folder) {
            File::ensureDirectoryExists($this->backupPath . '/' . $folder);
            File::put($this->backupPath . '/' . $folder . '/mysql.sql', 'x');
        }
        File::put($this->backupPath . '/2026-08-30_10-00-00.zip', 'zip');

        $names = array_column((new BackupRepository($this->backupPath))->entries(), 'name');

        $this->assertSame(['2026-09-01_10-00-00', '2026-08-30_10-00-00.zip', '28-08-2026_10-00-00', 'manual'], $names);
    }

    public function test_older_than_ignores_unknown_names(): void
    {
        File::ensureDirectoryExists($this->backupPath . '/2020-01-01_00-00-00');
        File::ensureDirectoryExists($this->backupPath . '/manual');

        $old = (new BackupRepository($this->backupPath))->olderThan(Carbon::now()->subDay());

        $this->assertSame(['2020-01-01_00-00-00'], array_column($old, 'name'));
    }

    public function test_prune_deletes_only_old_backups(): void
    {
        $old = $this->backupPath . '/2020-01-01_00-00-00';
        $recent = $this->backupPath . '/' . Carbon::now()->format('Y-m-d_H-i-s');
        File::ensureDirectoryExists($old);
        File::ensureDirectoryExists($recent);

        $this->artisan('angelohd:prune-backups', ['--older-than' => 7])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
    }
}
