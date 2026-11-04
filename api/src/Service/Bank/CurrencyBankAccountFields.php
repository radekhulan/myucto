<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Bankovní údaje vlastního účtu (tabulka `currencies`): kontrola vstupu před uložením
 * a zahraniční kód banky pro doklady.
 *
 * `bank_code` nese český čtyřmístný kód banky, nebo u zahraničního účtu národní kód
 * (britský sort code, americký routing number). Výpisy, registr vlastních účtů a
 * párování pracují jen s českým kódem; rozlišuje ho
 * {@see AccountNumberNormalizer::canonicalBankCode()}, zahraniční kód tam nevstupuje.
 */
final class CurrencyBankAccountFields
{
    /**
     * Šířky sloupců. Kód banky omezuje `payment_orders.payer_bank_code` (10), kam se
     * kód účtu plátce přenáší; delší text by v DB skončil chybou místo validace.
     */
    private const MAX_LENGTH = ['account_number' => 30, 'bank_code' => 10, 'bank_name' => 120, 'iban' => 34, 'bic' => 11];

    /**
     * Chyby vstupu podle pole. Kontroluje jen pole, která požadavek posílá.
     *
     * @param array<string,mixed> $body
     * @return array<string,list<string>>
     */
    public static function validationErrors(array $body): array
    {
        $errors = [];
        foreach (array_keys(self::MAX_LENGTH) as $field) {
            if (!array_key_exists($field, $body) || $body[$field] === null) continue;
            if (!is_scalar($body[$field])) {
                $errors[$field] = ['Neplatná hodnota.'];
                continue;
            }
            $value = trim((string) $body[$field]);
            if ($value === '') continue;
            if (mb_strlen($value) > self::MAX_LENGTH[$field]) {
                $errors[$field] = [sprintf('Hodnota může mít nejvýše %d znaků.', self::MAX_LENGTH[$field])];
            } elseif ($field === 'bank_code' && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 -]*[A-Za-z0-9])?$/D', $value) !== 1) {
                $errors[$field] = ['Kód banky může obsahovat jen písmena, číslice, mezery a pomlčky (např. 0800 nebo sort code 60-84-64).'];
            }
        }
        return $errors;
    }

    /** Hodnota k uložení: oříznutý text, prázdný jako NULL. */
    public static function normalized(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Zahraniční kód banky pro doklad: `label` je `sort_code` nebo `bank_code`.
     * Zadaný kód, který není český, má přednost; bez něj se britský sort code
     * vezme z IBANu (`GBkk BANK SSSSSS AAAAAAAA`). Český kód vrací null, ten
     * doklad tiskne jako `číslo účtu / kód`.
     *
     * @return array{label:'sort_code'|'bank_code', value:string}|null
     */
    public static function foreignBankCode(?string $bankCode, ?string $iban): ?array
    {
        $code = trim((string) $bankCode);
        $ibanSortCode = self::ukSortCode($iban);
        if ($code !== '') {
            if (AccountNumberNormalizer::canonicalBankCode($code) !== null) return null;
            $digits = (string) preg_replace('/\D/', '', $code);
            if (preg_match('/^\d{2}[ -]?\d{2}[ -]?\d{2}$/D', $code) === 1 && ($ibanSortCode !== null || trim((string) $iban) === '')) {
                return ['label' => 'sort_code', 'value' => substr($digits, 0, 2) . '-' . substr($digits, 2, 2) . '-' . substr($digits, 4, 2)];
            }
            return ['label' => 'bank_code', 'value' => $code];
        }
        return $ibanSortCode !== null ? ['label' => 'sort_code', 'value' => $ibanSortCode] : null;
    }

    /** Sort code z britského IBANu ve tvaru `60-84-64`, jinak null. */
    public static function ukSortCode(?string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        if (preg_match('/^GB\d{2}[A-Z]{4}(\d{2})(\d{2})(\d{2})\d{8}$/D', $compact, $m) !== 1) return null;
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
}
