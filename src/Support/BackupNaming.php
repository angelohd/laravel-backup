<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Illuminate\Support\Carbon;

class BackupNaming
{
    /** Formato actual: ordena correctamente como texto. */
    public const FORMAT = 'Y-m-d_H-i-s';

    /** Formato usado ate a versao 1.x, ainda reconhecido para listar, limpar e restaurar. */
    public const LEGACY_FORMAT = 'd-m-Y_H-i-s';

    public static function timestamp(?Carbon $date = null): string
    {
        return ($date ?? Carbon::now())->format(self::FORMAT);
    }

    /**
     * Extrai a data do nome de um backup (pasta ou ficheiro .zip).
     */
    public static function parse(string $name): ?Carbon
    {
        $name = preg_replace('/\.zip$/i', '', basename($name));

        $patterns = [
            self::FORMAT => '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/',
            self::LEGACY_FORMAT => '/^\d{2}-\d{2}-\d{4}_\d{2}-\d{2}-\d{2}$/',
        ];

        foreach ($patterns as $format => $pattern) {
            if (!preg_match($pattern, $name)) {
                continue;
            }

            $date = Carbon::createFromFormat('!' . $format, $name);

            return $date instanceof Carbon ? $date : null;
        }

        return null;
    }
}
