<?php

declare(strict_types=1);

namespace angelohd\Backup\Listeners;

use angelohd\Backup\BackupReport;
use angelohd\Backup\Events\BackupFailed;
use angelohd\Backup\Events\BackupSucceeded;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendBackupNotification
{
    public function handle(BackupSucceeded|BackupFailed $event): void
    {
        $config = config('angelohd-backup.notifications', []);
        $failed = $event instanceof BackupFailed;

        if (empty($config['enabled']) || !in_array($failed ? 'failure' : 'success', (array) ($config['notify_on'] ?? ['failure']), true)) {
            return;
        }

        $subject = sprintf('[%s] Backup da base de dados %s', config('app.name'), $failed ? 'FALHOU' : 'concluido');
        $body = $this->body($event->report);

        // Uma falha na notificacao nunca deve interromper o backup.
        if (!empty($config['slack_webhook_url'])) {
            try {
                Http::timeout(15)->post($config['slack_webhook_url'], ['text' => "*{$subject}*\n{$body}"])->throw();
            } catch (Throwable $e) {
                Log::warning('angelohd-backup: falha ao notificar o Slack.', ['exception' => $e]);
            }
        }

        if (!empty($config['mail_to'])) {
            try {
                Mail::raw($body, fn ($message) => $message->to($config['mail_to'])->subject($subject));
            } catch (Throwable $e) {
                Log::warning('angelohd-backup: falha ao enviar email.', ['exception' => $e]);
            }
        }
    }

    private function body(BackupReport $report): string
    {
        $lines = ["Backup: {$report->timestamp}"];

        if ($report->location() !== null) {
            $lines[] = "Local: {$report->location()}";
        }

        foreach ($report->files as $fileName => $file) {
            $lines[] = sprintf('OK  %s (%s) - %s bytes', $file['database'], $fileName, number_format($file['size']));
        }

        foreach ($report->errors as $connection => $error) {
            $lines[] = ($connection === '*' ? 'ERRO' : "ERRO [{$connection}]") . ": {$error}";
        }

        foreach ($report->uploadErrors as $disk => $error) {
            $lines[] = "ERRO envio [{$disk}]: {$error}";
        }

        if ($report->uploadedTo !== []) {
            $lines[] = 'Enviado para: ' . implode(', ', $report->uploadedTo);
        }

        return implode("\n", $lines);
    }
}
