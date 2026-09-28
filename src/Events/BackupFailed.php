<?php

declare(strict_types=1);

namespace angelohd\Backup\Events;

use angelohd\Backup\BackupReport;

class BackupFailed
{
    public function __construct(public BackupReport $report) {}
}
