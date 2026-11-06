<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Formáty identifikátorů datové zprávy EET (XSD EET v4) — jediné místo pravdy
 * pro nastavení, zařízení, certifikáty i tvorbu záznamů.
 */
final class EetIdentifiers
{
    /** `dic_popl`: CZ + 8 až 10 číslic. */
    public const EIC_PATTERN = '/^CZ[0-9]{8,10}$/D';

    /** `id_pokl`: XSD typ string20 s omezenou abecedou. */
    public const DEVICE_CODE_PATTERN = '/^[0-9a-zA-Z\.,:;\/#\-_ ]{1,20}$/D';

    /** `porad_cis`: XSD typ string25 se stejnou abecedou jako `id_pokl`. */
    public const SEQUENCE_NUMBER_PATTERN = '/^[0-9a-zA-Z\.,:;\/#\-_ ]{1,25}$/D';

    /** `pok` (XSD PokType): UUIDv4 a dvě hexa číslice, celkem 39 znaků. */
    public const POK_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}-[0-9a-fA-F]{2}$/D';

    /** `dat_trzby` (XSD dateTime): bez zlomků sekund, s časovou zónou. */
    public const DATE_TIME_PATTERN = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(Z|[+\-]\d\d:\d\d)$/D';

    /** `id_jednotky` (XSD IdEvidJednotkyType): kladné celé číslo, nejvýš devět číslic. */
    public const UNIT_NUMBER_MAX = 999999999;

    public const ENVIRONMENTS = ['playground', 'production'];
    public const REGIMES = ['standard', 'exempt', 'eet_off'];
    public const UNIT_KINDS = ['premises', 'online', 'vehicle', 'mobile', 'other'];

    public static function isEic(string $value): bool
    {
        return preg_match(self::EIC_PATTERN, $value) === 1;
    }

    public static function isDeviceCode(string $value): bool
    {
        return preg_match(self::DEVICE_CODE_PATTERN, $value) === 1;
    }

    public static function isSequenceNumber(string $value): bool
    {
        return preg_match(self::SEQUENCE_NUMBER_PATTERN, $value) === 1;
    }

    public static function isPok(string $value): bool
    {
        return preg_match(self::POK_PATTERN, $value) === 1;
    }

    public static function isDateTime(string $value): bool
    {
        return preg_match(self::DATE_TIME_PATTERN, $value) === 1;
    }

    /** Normalizace vstupu EIČ: mezery pryč, velká písmena; validaci dělá volající. */
    public static function normalizeEic(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
