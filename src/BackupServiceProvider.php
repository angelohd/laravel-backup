<?php

declare(strict_types=1);

namespace angelohd\Backup;

use Illuminate\Support\ServiceProvider;
use angelohd\Backup\Commands\BackupDatabaseCommand;
use angelohd\Backup\Commands\DatabaseInfoCommand;
use angelohd\Backup\Commands\DropAllDatabasesCommand;
use angelohd\Backup\Commands\DropAllTablesCommand;
use angelohd\Backup\Commands\DropDatabaseCommand;
use angelohd\Backup\Commands\ListBackupsCommand;
use angelohd\Backup\Commands\PruneBackupsCommand;
use angelohd\Backup\Commands\RestoreDatabaseCommand;

class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/angelohd-backup.php',
            'angelohd-backup'
        );
    }

    public function boot(): void
    {
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
