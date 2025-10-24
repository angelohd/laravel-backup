<?php

namespace angelohd\Backup;

use Illuminate\Support\ServiceProvider;
use angelohd\Backup\Commands\BackupDatabaseCommand;

class BackupServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupDatabaseCommand::class,
            ]);
        }
    }
}
