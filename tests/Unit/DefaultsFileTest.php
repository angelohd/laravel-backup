<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Unit;

use angelohd\Backup\Support\DefaultsFile;
use PHPUnit\Framework\TestCase;

class DefaultsFileTest extends TestCase
{
    public function test_quotes_and_escapes_special_characters(): void
    {
        $this->assertSame('"p#a\\"s\\\\s w;o\'rd"', DefaultsFile::quote('p#a"s\\s w;o\'rd'));
        $this->assertSame('"a\\nb"', DefaultsFile::quote("a\nb"));
    }

    public function test_uses_socket_instead_of_host_when_configured(): void
    {
        $contents = DefaultsFile::contents([
            'username' => 'root',
            'password' => 'secret',
            'host' => '127.0.0.1',
            'port' => '3306',
            'unix_socket' => '/tmp/mysql.sock',
        ]);

        $this->assertStringContainsString('socket="/tmp/mysql.sock"', $contents);
        $this->assertStringNotContainsString('host=', $contents);
    }

    public function test_file_is_deleted(): void
    {
        $file = DefaultsFile::create(['username' => 'u', 'password' => 'p', 'host' => 'h', 'port' => 3306]);
        $path = substr($file->argument(), strlen('--defaults-extra-file='));

        $this->assertFileExists($path);
        $this->assertStringContainsString('password="p"', (string) file_get_contents($path));

        $file->delete();

        $this->assertFileDoesNotExist($path);
    }
}
