<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Feature;

use angelohd\Backup\Events\BackupFailed;
use angelohd\Backup\Tests\TestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class CommandsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Qualquer ficheiro existente serve: os processos sao simulados.
        $app['config']->set('angelohd-backup.binaries', ['mysqldump' => PHP_BINARY, 'mysql' => PHP_BINARY]);
        $app['config']->set('database.connections.main', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app_db',
            'username' => 'root',
            'password' => 'secret',
        ]);
    }

    public function test_unknown_connection_shows_error_instead_of_exception(): void
    {
        $this->artisan('angelohd:database-info', ['--connection' => 'nope'])
            ->expectsOutputToContain('Conexao [nope] nao encontrada.')
            ->assertFailed();
    }

    public function test_failed_dump_is_reported_and_leaves_no_files(): void
    {
        Event::fake([BackupFailed::class]);
        Process::fake(['*' => Process::result(errorOutput: 'Access denied for user', exitCode: 2)]);

        $this->artisan('angelohd:backup-database', ['--connection' => 'main', '--gzip' => true])
            ->expectsOutputToContain('Access denied for user')
            ->assertFailed();

        $this->assertSame([], File::isDirectory($this->backupPath) ? File::directories($this->backupPath) : []);
        Event::assertDispatched(BackupFailed::class, fn ($event) => isset($event->report->errors['main']));
    }

    public function test_incomplete_dump_is_rejected(): void
    {
        Process::fake(function (PendingProcess $process) {
            // Simula um mysqldump que termina com codigo 0 mas escreve um dump truncado.
            foreach ($process->command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, 14), "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1");
                }
            }

            return Process::result();
        });

        $this->artisan('angelohd:backup-database', ['--connection' => 'main'])
            ->expectsOutputToContain('esta incompleto')
            ->assertFailed();
    }

    public function test_drop_all_tables_disables_foreign_keys_and_quotes_names(): void
    {
        Process::fake(function (PendingProcess $process) {
            return str_contains((string) $process->input, 'information_schema')
                ? Process::result("users\tBASE TABLE\nweird`name\tBASE TABLE\nreport\tVIEW\n")
                : Process::result();
        });

        $this->artisan('angelohd:drop-all-tables', ['--connection' => 'main'])->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $sql = (string) $process->input;

            return str_starts_with($sql, 'SET FOREIGN_KEY_CHECKS=0;')
                && str_contains($sql, 'DROP VIEW IF EXISTS `report`;')
                && str_contains($sql, 'DROP TABLE IF EXISTS `users`, `weird``name`;')
                && end($process->command) === 'app_db';
        });
    }

    public function test_credentials_are_never_passed_on_the_command_line(): void
    {
        Process::fake();

        $this->artisan('angelohd:drop-database', ['database' => 'app_db'])->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            return str_starts_with($process->command[1], '--defaults-extra-file=')
                && !str_contains(implode(' ', $process->command), 'secret');
        });
    }

    public function test_drop_all_databases_asks_for_confirmation_outside_production(): void
    {
        Process::fake();

        $this->artisan('angelohd:drop-all-databases')
            ->expectsConfirmation('Tem a certeza que deseja APAGAR TODAS estas bases de dados?', 'no')
            ->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_failure_notification_is_sent_to_slack(): void
    {
        config([
            'angelohd-backup.notifications.enabled' => true,
            'angelohd-backup.notifications.slack_webhook_url' => 'https://hooks.slack.test/abc',
        ]);
        Http::fake();
        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        $this->artisan('angelohd:backup-database')->assertFailed();

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/abc'
            && str_contains($request['text'], 'FALHOU')
            && str_contains($request['text'], 'boom'));
    }
}
