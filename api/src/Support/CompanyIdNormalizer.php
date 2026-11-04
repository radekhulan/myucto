<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Normalizace IČO/DIČ pro POROVNÁNÍ — jediný zdroj pravdy pro tvar, který dřív
 * duplikovaly per-file `normalizeIc()` metody v importních třídách.
 *
 * IČO: české IČO má vždy 8 číslic. AI extrakce z PDF čte IČO jako číslo, takže u
 * firem s IČO začínajícím nulou (např. „01234567") ztratí úvodní nulu a vrátí jen
 * 7 číslic. Bez zero-padu na 8 míst pak řetězcové porovnání dvou correctních IČO
 * vypadá jako neshoda — cross-tenant guard (»Faktura adresovaná jinému plátci«)
 * legitimní doklad odmítne (BUG 2, vendor bugreport 2026-08-06) a karta dodavatele
 * se založí podruhé místo napárování na existující (FR 2, tamtéž).
 *
 * DIČ: porovnáváme bez mezer a bez jiných než alfanumerických znaků, velkými
 * písmeny — v datech se vyskytuje jak „CZ 12345678", tak „CZ12345678" pro týž
 * subjekt.
 *
 * Použití: guard adresáta v {@see \MyInvoice\Service\Import\AiPdfExtractor},
 * {@see \MyInvoice\Service\Import\AiIssuedInvoiceExtractor},
 * {@see \MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper}, a zakládání/dedup
 * karty dodavatele v {@see \MyInvoice\Service\Import\ClientResolver} a
 * {@see \MyInvoice\Service\Client\VendorDuplicateFinder}.
 */
final class CompanyIdNormalizer
{
    /**
     * Jen číslice; pokud jich je 1–8, doplní zleva nulami na 8 (kanonický tvar
     * českého IČO). Delší (chybná/zahraniční) hodnoty necháváme beze změny — tady
     * neřešíme validitu, jen odstraňujeme zero-pad artefakt AI extrakce.
     */
    public static function ic(?string $ic): ?string
    {
        $clean = preg_replace('/\D/', '', (string) $ic) ?? '';
        if ($clean === '') {
            return null;
        }
        return strlen($clean) <= 8 ? str_pad($clean, 8, '0', STR_PAD_LEFT) : $clean;
    }

    /**
     * SQL výraz se stejnou normalizací jako {@see self::ic()} nad sloupcem s IČO.
     * Samotný `LPAD(…, 8, '0')` delší hodnotu ořízne na 8 znaků, takže zahraniční
     * identifikátor s víc číslicemi (maďarské daňové číslo apod.) se s výsledkem
     * ic() nikdy neshodl a import zakládal ke každému dokladu novou kartu.
     */
    public static function icSql(string $column): string
    {
        $digits = "REGEXP_REPLACE({$column}, '[^0-9]', '')";
        return "IF(CHAR_LENGTH({$digits}) <= 8, LPAD({$digits}, 8, '0'), {$digits})";
    }

    /**
     * Odstraní mezery a jakékoli nealfanumerické znaky, převede na velká písmena.
     * „CZ 12345678" i „cz12345678" normalizuje na „CZ12345678".
     */
    public static function dic(?string $dic): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string) $dic) ?? '';
        $clean = strtoupper($clean);
        return $clean !== '' ? $clean : null;
    }
}
