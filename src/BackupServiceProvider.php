<?php

declare(strict_types=1);

namespace angelohd\Backup;

use angelohd\Backup\Commands\BackupDatabaseCommand;
use angelohd\Backup\Commands\DatabaseInfoCommand;
use angelohd\Backup\Commands\DropAllDatabasesCommand;
use angelohd\Backup\Commands\DropAllTablesCommand;
use angelohd\Backup\Commands\DropDatabaseCommand;
use angelohd\Backup\Commands\ListBackupsCommand;
use angelohd\Backup\Commands\PruneBackupsCommand;
use angelohd\Backup\Commands\RestoreDatabaseCommand;
use angelohd\Backup\Events\BackupFailed;
use angelohd\Backup\Events\BackupSucceeded;
use angelohd\Backup\Listeners\SendBackupNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/angelohd-backup.php',
            'angelohd-backup'
        );

        $this->app->bind(BackupManager::class);
    }

    public function boot(): void
    {
        Event::listen(BackupSucceeded::class, SendBackupNotification::class);
        Event::listen(BackupFailed::class, SendBackupNotification::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/angelohd-backup.php' => config_path('angelohd-backup.php'),
            ], 'angelohd-backup-config');

            $this->commands([
                BackupDatabaseCommand::class,
                DatabaseInfoCommand::class,
                DropAllDatabasesCommand::class,
                DropAllTablesCommand::class,
                DropDatabaseCommand::class,
                ListBackupsCommand::class,
                PruneBackupsCommand::class,
                RestoreDatabaseCommand::class,
            ]);
        }
    }
}
