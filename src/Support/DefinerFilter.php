<?php

declare(strict_types=1);

namespace angelohd\Backup\Support;

use Generator;

/**
 * Remove as clausulas DEFINER=`user`@`host` de views, triggers, rotinas e
 * eventos. Sem isto, restaurar um dump com outro utilizador falha com
 * "you need the SUPER or SET_ANY_DEFINER privilege".
 */
class DefinerFilter
{
    private const PATTERN = '/\bDEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s@*]+)@(?:`(?:[^`]|``)*`|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s*]+)\s*/i';

    public static function strip(string $line): string
    {
        // So linhas de DDL: nunca tocar em dados de INSERT.
        $start = ltrim($line);
        if (!str_starts_with($start, '/*!') && stripos($start, 'CREATE') !== 0) {
            return $line;
        }

        return (string) preg_replace(self::PATTERN, '', $line);
    }

    /**
     * Le o stream linha a linha e devolve blocos filtrados de ~1 MB.
     *
     * @param  resource  $stream
     * @return Generator<int, string>
     */
    public static function chunks($stream, int $chunkSize = 1048576): Generator
    {
        $buffer = '';

        while (($line = fgets($stream)) !== false) {
            $buffer .= self::strip($line);

            if (strlen($buffer) >= $chunkSize) {
                yield $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }
}
