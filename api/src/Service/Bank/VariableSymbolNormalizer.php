<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Normalizace variabilního symbolu (VS) — jedno místo pravdy pro celou aplikaci.
 *
 * Proč: VS v `invoices.varsymbol` zároveň slouží jako číslo dokladu, takže může
 * podle dokladové řady obsahovat nečíselné znaky (např. template `{YYYY}-{CCCCC}`
 * → `2026-00001`). Pro banku ale VS MUSÍ být jen číslice, max 10 znaků — pomlčka
 * ani lomítko přes bankovní převod neprojdou a do QR (SPAYD) je knihovna odmítne.
 *
 *   - forPayment()  — co se posílá do banky / QR / tiskne jako platební VS:
 *                     jen číslice, max 10 (vodicí nuly zachované, jsou platné).
 *   - forMatching() — kanonický klíč pro párování s bankovní transakcí:
 *                     číslice bez vodicích nul (banka i GPC vodicí nuly ořezávají).
 *   - matchKey()    — tentýž klíč jako indexované sloupce `*_match_key` dokladů
 *                     (migrace 2010), podle kterých `StatementMatcher` hledá doklad.
 *   - digits()      — holé číslice (bez ořezu délky i vodicích nul).
 *
 * @see \MyInvoice\Service\Bank\AccountNumberNormalizer  obdobná normalizace čísel účtů
 */
final class VariableSymbolNormalizer
{
    /** Maximální délka VS dle bankovního standardu (SPAYD/tuzemský platební styk). */
    public const MAX_LENGTH = 10;

    /** Jen číslice — odstraní vše ostatní (pomlčky, lomítka, mezery, písmena). */
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * VS vhodný pro platbu / QR / tisk: jen číslice, max 10 znaků.
     * Vodicí nuly zachováváme — jsou platná součást VS a banka je akceptuje.
     */
    public static function forPayment(string $value): string
    {
        $digits = self::digits($value);
        return $digits === '' ? '' : substr($digits, 0, self::MAX_LENGTH);
    }

    /**
     * Platební VS vydané faktury: samostatný `payment_variable_symbol`, pokud je
     * vyplněný, jinak odvozený z čísla dokladu (`varsymbol`). Jediné místo, odkud
     * PDF, QR, e-maily, exporty i API berou VS, který klient zadá do příkazu.
     *
     * @param array<string,mixed> $invoice řádek `invoices` (stačí varsymbol + payment_variable_symbol)
     */
    public static function forInvoicePayment(array $invoice): string
    {
        $explicit = self::forPayment((string) ($invoice['payment_variable_symbol'] ?? ''));
        return $explicit !== '' ? $explicit : self::forPayment((string) ($invoice['varsymbol'] ?? ''));
    }

    /**
     * Platební VS přijaté faktury: `payment_variable_symbol` → číslo dokladu dodavatele
     * (`vendor_invoice_number`) → interní číslo (`varsymbol`), první neprázdný.
     *
     * @param array<string,mixed> $invoice řádek `purchase_invoices`
     */
    public static function forPurchasePayment(array $invoice): string
    {
        foreach (['payment_variable_symbol', 'vendor_invoice_number', 'varsymbol'] as $key) {
            $vs = self::forPayment((string) ($invoice[$key] ?? ''));
            if ($vs !== '') {
                return $vs;
            }
        }
        return '';
    }

    /**
     * `payment_variable_symbol` pro vydaný doklad převzatý z jiného systému (import,
     * AI vytěžení): VS ze zdroje se uloží jen tehdy, když se liší od VS, který by se
     * odvodil z čísla dokladu. Typicky daňový doklad k proformě, který nese VS proformy.
     * Shodný VS se neukládá (odvodí se), nepoužitelný (prázdný, delší než 10 číslic)
     * taky ne, protože by tiskl jiný symbol, než jaký doklad nese.
     */
    public static function importedPaymentOverride(string $documentNumber, ?string $sourceVs): ?string
    {
        $vs = self::digits((string) $sourceVs);
        if ($vs === '' || strlen($vs) > self::MAX_LENGTH) {
            return null;
        }
        return $vs === self::forPayment($documentNumber) ? null : $vs;
    }

    /**
     * Kanonický klíč pro párování: číslice bez vodicích nul (konzistentní s GPC
     * parserem i bankovními e-mailovými avízy). Když by ořez vodicích nul vrátil
     * prázdno (samé nuly), vrátí původní číslice — VS „0" tak nezmizí.
     */
    public static function forMatching(string $value): string
    {
        $digits = self::digits($value);
        $trimmed = ltrim($digits, '0');
        return $trimmed !== '' ? $trimmed : $digits;
    }

    /**
     * Klíč pro vyhledání dokladu podle VS: číslice bez vodicích nul. Shoduje se se
     * sloupci `*_match_key` (`NULLIF(TRIM(LEADING '0' FROM REGEXP_REPLACE(…)), '')`),
     * takže VS bez nenulové číslice vrací prázdný řetězec a žádný doklad nenajde.
     */
    public static function matchKey(string $value): string
    {
        return ltrim(self::digits($value), '0');
    }
}
