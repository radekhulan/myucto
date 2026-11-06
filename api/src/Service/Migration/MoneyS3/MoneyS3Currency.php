<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Měna z pole `Mena` zálohy Money S3.
 *
 * Money nechává u korunových dokladů pole prázdné, v peněžním deníku ale píše „Kč".
 * Velká písmena se proto dělají přes `mb_strtoupper`: `strtoupper('Kč')` mění jen
 * ASCII a „Kč" by se pak četlo jako cizí měna.
 */
final class MoneyS3Currency
{
    private const CZK_ALIASES = ['', 'CZK', 'KČ', 'KC'];

    /** Kód měny: `CZK` pro korunu (i prázdné pole), jinak velkými písmeny kód z Money. */
    public static function code(mixed $mena): string
    {
        $code = mb_strtoupper(trim((string) ($mena ?? '')));
        return in_array($code, self::CZK_ALIASES, true) ? 'CZK' : $code;
    }

    public static function isCzk(mixed $mena): bool
    {
        return self::code($mena) === 'CZK';
    }
}
