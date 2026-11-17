<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import\PamicaPosting;

/**
 * Rozpoznání souboru se zaúčtováním mezd z PAMICY. Jediný detektor pro všechna místa,
 * kam ho uživatel může nahrát: průvodce PAMICA (vedle ZIP exportu celých mezd) i import
 * přijatých a vydaných faktur, který z něj nesmí založit přijaté faktury.
 *
 * PAMICA vyváží zaúčtování mezd pro POHODU jako STORMWARE dataPack s `application="PAMICA"`:
 * interní doklad (`int:intDoc`) s mzdovými náklady a ostatní závazky
 * (`inv:invoice` s typem `commitment`). Detektor čte jen hlavičku kořene a hledá tyto
 * elementy, soubor neparsuje; úplnou kontrolu dělá až {@see PamicaPostingParser}.
 */
final class PamicaPostingFormat
{
    /** Stejný limit souboru jako dávkový import faktur. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const HEAD_BYTES = 8192;

    /** Kořen je STORMWARE dataPack vyvezený PAMICOU (bez ohledu na obsah). */
    public static function isPamicaDataPack(string $bytes): bool
    {
        $head = substr(self::withoutBom($bytes), 0, self::HEAD_BYTES);
        if (preg_match('/<(?:[A-Za-z_][\w.-]*:)?dataPack\b([^>]*)>/', $head, $m) !== 1) {
            return false;
        }
        return preg_match('/\bapplication\s*=\s*(["\'])\s*PAMICA\s*\1/i', $m[1]) === 1;
    }

    /** dataPack z PAMICY s interním dokladem nebo ostatním závazkem = zaúčtování mezd. */
    public static function isPayrollPosting(string $bytes): bool
    {
        if (!self::isPamicaDataPack($bytes)) {
            return false;
        }
        return preg_match('/<(?:[A-Za-z_][\w.-]*:)?intDoc[\s>]/', $bytes) === 1
            || preg_match('/<(?:[A-Za-z_][\w.-]*:)?invoiceType>\s*commitment\s*</', $bytes) === 1;
    }

    private static function withoutBom(string $bytes): string
    {
        return str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
    }
}
