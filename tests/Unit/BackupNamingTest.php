<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Unit;

use angelohd\Backup\Support\BackupNaming;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BackupNamingTest extends TestCase
{
    public function test_timestamp_uses_sortable_format(): void
    {
        $this->assertSame('2026-09-01_03-00-00', BackupNaming::timestamp(Carbon::create(2026, 9, 1, 3)));
    }

    public function test_parses_current_and_legacy_names(): void
    {
        $this->assertSame('2026-09-01 03:04:05', BackupNaming::parse('2026-09-01_03-04-05')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 03:04:05', BackupNaming::parse('01-09-2026_03-04-05')?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 03:04:05', BackupNaming::parse('2026-09-01_03-04-05.zip')?->format('Y-m-d H:i:s'));
    }

    public function test_returns_null_for_unknown_names(): void
    {
        $this->assertNull(BackupNaming::parse('manual-backup'));
        $this->assertNull(BackupNaming::parse('2026-09-01'));
    }
}
