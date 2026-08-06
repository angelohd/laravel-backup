<?php

declare(strict_types=1);

namespace angelohd\Backup\Concerns;

trait ConfirmsInProduction
{
    private function isProduction(): bool
    {
        return app()->environment('production');
    }

    private function confirmDestructiveOperation(string $message): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (!$this->isProduction()) {
            return true;
        }

        $this->warn('***** ATENCAO: ESTA EM AMBIENTE DE PRODUCAO *****');

        if ($this->confirm($message)) {
            return true;
        }

        $this->info('Operacao cancelada.');

        return false;
    }
}
