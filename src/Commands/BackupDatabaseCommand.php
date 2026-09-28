<?php

declare(strict_types=1);

namespace angelohd\Backup\Commands;

use angelohd\Backup\BackupManager;
use angelohd\Backup\Concerns\FormatsBytes;

class BackupDatabaseCommand extends BaseCommand
{
    use FormatsBytes;

    protected $signature = 'angelohd:backup-database
        {--path= : Pasta de destino}
        {--connection= : Apenas esta conexao}
        {--gzip : Comprimir cada dump com gzip}
        {--zip : Agrupar o backup num ficheiro .zip}
        {--disk=* : Disk(s) remoto(s) para onde enviar o backup}';

    protected $description = 'Cria backup das bases de dados configuradas em config/database.php.';

    public function handle(BackupManager $manager): int
    {
        $this->info('A iniciar backup das bases de dados configuradas...');

        $report = $manager->run(
            connection: $this->option('connection') ?: null,
            path: $this->option('path') ?: null,
            gzip: $this->option('gzip') ?: null,
            zip: $this->option('zip') ?: null,
            disks: $this->option('disk') ?: null,
            progress: function (string $level, string $message) {
                match ($level) {
                    'error' => $this->error($message),
                    'warn' => $this->warn($message),
                    'comment' => $this->comment($message),
                    default => $this->info($message),
                };
            },
        );

        $this->newLine();

        if ($report->files === []) {
            $this->error('Nenhuma base de dados foi exportada.');

            return self::FAILURE;
        }

        $total = array_sum(array_column($report->files, 'size'));
        $this->info(sprintf('Backup concluido: %d base(s) de dados, %s.', count($report->files), $this->formatSize($total)));
        $this->info('Local: ' . $report->location());

        if (!$report->successful()) {
            $this->warn('O backup terminou com erros (ver acima).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
