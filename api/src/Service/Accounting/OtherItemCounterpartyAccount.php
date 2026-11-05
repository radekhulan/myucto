<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;

/**
 * Účet protistrany ostatní položky: ověření vstupu a porovnání s účtem bankovního pohybu.
 *
 * Uložený tvar: český účet `předčíslí-číslo` bez vodicích nul + čtyřmístný kód banky
 * (stejně jako platební příkazy, {@see CzechBankAccountValidator}); český IBAN se převede
 * na tentýž tvar, zahraniční IBAN zůstává jako IBAN bez kódu banky. Pohyb z výpisu nese
 * účet v různých zápisech (GPC 16 cifer, národní tvar, IBAN), {@see fromTransaction} ho
 * převede na stejný tvar, takže porovnání je prostá rovnost.
 */
final class OtherItemCounterpartyAccount
{
    /**
     * @return array{counterparty_account:?string,counterparty_bank_code:?string}
     * @throws OtherItemException neplatný účet, IBAN nebo kód banky
     */
    public static function normalize(mixed $account, mixed $bankCode): array
    {
        $raw = trim((string) ($account ?? ''));
        $bank = trim((string) ($bankCode ?? ''));
        if ($raw === '' && $bank === '') {
            return ['counterparty_account' => null, 'counterparty_bank_code' => null];
        }
        if ($raw === '') {
            throw new OtherItemException('invalid_counterparty_account', 'Zadejte číslo účtu protistrany, nejen kód banky.');
        }
        if (mb_strlen($raw) > 60 || mb_strlen($bank) > 10) {
            throw new OtherItemException('invalid_counterparty_account', 'Účet protistrany je příliš dlouhý.');
        }
        $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/D', $compact) === 1) {
            if (!(new IbanValidator())->isValid($compact)) {
                throw new OtherItemException('invalid_counterparty_account', 'IBAN protistrany není platný.');
            }
            if (str_starts_with($compact, 'CZ') && strlen($compact) === 24) {
                $ibanBank = substr($compact, 4, 4);
                if ($bank !== '' && $bank !== $ibanBank) {
                    throw new OtherItemException('invalid_counterparty_account', 'Kód banky neodpovídá IBANu protistrany.');
                }
                $national = AccountNumberNormalizer::czechNational(substr($compact, 8))
                    ?? throw new OtherItemException('invalid_counterparty_account', 'IBAN protistrany není platný.');
                return ['counterparty_account' => $national, 'counterparty_bank_code' => $ibanBank];
            }
            if ($bank !== '') {
                throw new OtherItemException('invalid_counterparty_account', 'U zahraničního IBANu se kód banky nezadává.');
            }
            return ['counterparty_account' => $compact, 'counterparty_bank_code' => null];
        }
        if (str_contains($compact, '/')) {
            [$compact, $inline] = explode('/', $compact, 2);
            if ($bank !== '' && $bank !== $inline) {
                throw new OtherItemException('invalid_counterparty_account', 'Kód banky je uvedený dvakrát a liší se.');
            }
            $bank = $inline;
        }
        if (preg_match('/^\d{4}$/D', $bank) !== 1) {
            throw new OtherItemException('invalid_counterparty_account', 'Zadejte účet protistrany ve tvaru předčíslí-číslo/kód banky nebo IBAN.');
        }
        try {
            // GPC tvar (16 cifer) a vodicí nuly převede na předčíslí-číslo, mod 11 pak ověří validátor.
            $national = AccountNumberNormalizer::czechNational($compact) ?? $compact;
            $parsed = (new CzechBankAccountValidator())->parse($national . '/' . $bank);
        } catch (\InvalidArgumentException $e) {
            throw new OtherItemException('invalid_counterparty_account', $e->getMessage());
        }
        return ['counterparty_account' => $parsed['account_number'], 'counterparty_bank_code' => $parsed['bank_code']];
    }

    /**
     * Účet z bankovního pohybu v uloženém tvaru; null, když pohyb účet nenese nebo ho
     * nejde převést. Na rozdíl od {@see normalize} nic neodmítá (data z výpisu).
     *
     * @return array{account:string,bank_code:?string}|null
     */
    public static function fromTransaction(?string $account, ?string $bankCode): ?array
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', (string) $account));
        if ($compact === '' || preg_match('/[1-9]/', $compact) !== 1) return null;
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/D', $compact) === 1) {
            if (str_starts_with($compact, 'CZ') && strlen($compact) === 24) {
                $national = AccountNumberNormalizer::czechNational(substr($compact, 8));
                return $national === null ? null : ['account' => $national, 'bank_code' => substr($compact, 4, 4)];
            }
            return ['account' => $compact, 'bank_code' => null];
        }
        $bank = trim((string) $bankCode);
        if (str_contains($compact, '/')) {
            [$compact, $inline] = explode('/', $compact, 2);
            if ($bank === '') $bank = $inline;
        }
        $national = AccountNumberNormalizer::czechNational($compact);
        if ($national === null) return null;
        return ['account' => $national, 'bank_code' => AccountNumberNormalizer::canonicalBankCode($bank)];
    }

    /** Je účet pohybu účtem protistrany položky? Kód banky se porovná, když ho znají obě strany. */
    public static function matches(?string $itemAccount, ?string $itemBankCode, ?array $transaction): bool
    {
        if ($itemAccount === null || $itemAccount === '' || $transaction === null) return false;
        if ($transaction['account'] !== $itemAccount) return false;
        return $itemBankCode === null || $transaction['bank_code'] === null || $transaction['bank_code'] === $itemBankCode;
    }
}
