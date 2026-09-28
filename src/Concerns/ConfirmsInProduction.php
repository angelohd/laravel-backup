<?php

declare(strict_types=1);

namespace angelohd\Backup\Concerns;

trait ConfirmsInProduction
{
    private function isProduction(): bool
    {
        return app()->environment('production');
    }

    /**
     * Pede confirmacao apenas em producao (ou nunca, com --force).
     */
    private function confirmDestructiveOperation(string $message): bool
    {
        if ($this->option('force') || !$this->isProduction()) {
            return true;
        }

        $this->warn('***** ATENCAO: ESTA EM AMBIENTE DE PRODUCAO *****');

        return $this->askConfirmation($message);
    }

    /**
     * Pede confirmacao em qualquer ambiente (salvo --force). Em modo nao
     * interactivo a resposta e "nao", pelo que a operacao e cancelada.
     */
    private function confirmDangerousOperation(string $message): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if ($this->isProduction()) {
            $this->warn('***** ATENCAO: ESTA EM AMBIENTE DE PRODUCAO *****');
        }

        return $this->askConfirmation($message);
    }

    private function askConfirmation(string $message): bool
    {
        if ($this->confirm($message)) {
            return true;
        }

        $this->info('Operacao cancelada.');

        return false;
    }
}
