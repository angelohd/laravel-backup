<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests;

use angelohd\Backup\BackupServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $backupPath;

    protected function getPackageProviders($app): array
    {
        return [BackupServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'angelohd-backup-tests-' . bin2hex(random_bytes(4));

        $app['config']->set('angelohd-backup.default_backup_path', $this->backupPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);

        parent::tearDown();
    }
}
