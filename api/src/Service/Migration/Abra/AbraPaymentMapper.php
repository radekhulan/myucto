<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

/** Bankovní a pokladní pohyby a vazby úhrad z Flexi. */
final class AbraPaymentMapper
{
    /** @param list<array<string,mixed>> $rows @return array<string,array<string,string>> */
    public static function bankAccountLookup(array $rows): array
    {
        $duplicates = [];
        foreach ($rows as $row) {
            $number = trim((string) ($row['buc'] ?? ''));
            if (preg_match('~^([0-9-]{1,30})(?:/[0-9]{4})?$~D', $number, $parts) !== 1) continue;
            $identity = preg_replace('/\D+/', '', $parts[1]) . '|'
                . AbraSource::currency($row['mena'] ?? null);
            $duplicates[$identity] = ($duplicates[$identity] ?? 0) + 1;
        }
        $lookup = [];
        foreach ($rows as $row) {
            $id = AbraSource::reference($row['id'] ?? null);
            $code = AbraSource::reference($row['kod'] ?? null);
            if ($id === '' || $code === '') continue;
            $currency = AbraSource::currency($row['mena'] ?? null);
            $raw = trim((string) ($row['buc'] ?? ''));
            $bankCode = '';
            if (preg_match('~^([0-9-]{1,30})/([0-9]{4})$~D', $raw, $parts) === 1) {
                $raw = $parts[1];
                $bankCode = $parts[2];
            }
            $sourceBankCode = AbraSource::reference($row['smerKod'] ?? null);
            if ($bankCode === '' && preg_match('/^[0-9]{4}$/D', $sourceBankCode) === 1) {
                $bankCode = $sourceBankCode;
            }
            $iban = strtoupper(preg_replace('/\s+/', '', (string) ($row['iban'] ?? '')));
            if ($bankCode === '' && preg_match('/^CZ[0-9]{2}([0-9]{4})[0-9]{16}$/D', $iban, $parts) === 1) {
                $bankCode = $parts[1];
            }
            $identity = preg_replace('/\D+/', '', $raw) . '|' . $currency;
            $number = preg_match('/^[0-9-]{1,30}$/D', $raw) === 1
                && ($duplicates[$identity] ?? 0) <= 1 ? $raw : 'ABRA-' . $id;
            $account = [
                'id' => $id,
                'code' => $code,
                'currency' => $currency,
                'number' => mb_substr($number, 0, 30),
                'bank_code' => $bankCode,
                'label' => mb_substr(trim((string) ($row['nazev'] ?? $code)) ?: $code, 0, 60),
                'bank_name' => mb_substr(trim((string) ($row['nazBanky'] ?? '')), 0, 120),
                'iban' => preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/D', $iban) === 1 ? $iban : '',
                'bic' => mb_substr(trim((string) ($row['bic'] ?? '')), 0, 11),
                'accounting_code' => AbraSource::reference($row['primUcet'] ?? null),
                'loan' => AbraSource::isLoanAccount($row['primUcet'] ?? null),
            ];
            $lookup['id:' . $id] = $account;
            $lookup['code:' . $code] = $account;
        }
        return $lookup;
    }

    /** @return array<string,mixed> */
    public function mapMovement(array $row, string $evidence, array $bankAccounts = []): array
    {
        $kind = $evidence === 'pokladni-pohyb' ? 'cash' : 'bank';
        $key = AbraSource::sourceKey($row);
        $date = AbraSource::date($row['datUcto'] ?? $row['datVyst'] ?? $row['datum'] ?? null);
        $currency = AbraSource::currency($row['mena'] ?? null);
        $amount = AbraSource::number($currency === 'CZK' ? ($row['sumCelkem'] ?? null) : ($row['sumCelkemMen'] ?? null));
        $direction = mb_strtolower(AbraSource::reference($row['typPohybuK'] ?? null));
        if ($amount !== null && $amount > 0 && (str_contains($direction, 'vydej') || str_contains($direction, 'výdej')
            || str_contains($direction, 'odchoz') || str_contains($direction, 'debet'))) {
            $amount *= -1;
        }
        $account = AbraSource::relation($row['banka@ref'] ?? $row['banka'] ?? $row['pokladna'] ?? null);
        $accountKey = $account['key'] ?? 'default';
        $bankAccount = $kind === 'bank' ? ($bankAccounts['id:' . $accountKey] ?? null) : null;
        if ($bankAccount === null && $kind === 'bank') {
            $code = AbraSource::reference($row['banka'] ?? null);
            $bankAccount = $bankAccounts['code:' . $code] ?? null;
        }
        if ($bankAccount !== null) $accountKey = $bankAccount['id'];
        $statementLabel = trim((string) ($row['vypisCisDokl'] ?? ''));
        $bankRelation = is_array($row['banka'] ?? null) ? $row['banka'] : [];
        $accountNumber = $bankAccount['number'] ?? trim((string) ($row['bankaUcet'] ?? $row['cisUctu'] ?? $bankRelation['buc'] ?? ''));
        $blockers = [];
        $warnings = [];
        if ($key === '' || $date === null || $amount === null || abs($amount) < 0.005) {
            $blockers[] = 'payment_movement_invalid';
        }
        if ($currency === '' || ($kind === 'cash' && $currency !== 'CZK')) {
            $blockers[] = 'payment_foreign_currency_unsupported';
        }
        $taxedCash = ($row['_taxedCash'] ?? false) === true
            || abs(AbraSource::number($row['sumDphCelkem'] ?? null) ?? 0.0) > 0.005;
        if ($kind === 'cash' && !$taxedCash) {
            foreach (is_array($row['polozkyDokladu'] ?? null) ? $row['polozkyDokladu'] : [] as $item) {
                if (is_array($item) && abs(AbraSource::number($item['sumDph'] ?? $item['sumDphCelkem'] ?? null) ?? 0.0) > 0.005) {
                    $taxedCash = true;
                    break;
                }
            }
        }
        if ($kind === 'cash' && $taxedCash) {
            $blockers[] = 'cash_vat_requires_review';
        }
        if ($kind === 'bank' && $accountNumber === '') {
            $accountNumber = 'ABRA-' . $accountKey;
            $warnings[] = 'bank_account_number_unavailable';
        }
        if ($kind === 'bank' && $bankAccount === null && $bankAccounts !== []) {
            $warnings[] = 'bank_account_catalog_reference_missing';
        }
        return [
            'evidence' => $evidence,
            'kind' => $kind,
            'source_key' => $key,
            'source_hash' => AbraSource::movementHash($row),
            'storno' => AbraSource::bool($row['storno'] ?? null) === true,
            'year' => $date !== null ? (int) substr($date, 0, 4) : 0,
            'date' => $date,
            'amount' => $amount !== null ? round($amount, 2) : 0.0,
            'currency' => $currency,
            'account_key' => mb_substr($accountKey, 0, 80),
            'loan_account' => (bool) ($bankAccount['loan'] ?? false),
            'statement_key' => mb_substr($kind === 'bank' && $date !== null ? substr($date, 0, 7)
                : ($statementLabel !== '' ? $statementLabel : (string) ($date ?? 'unknown')), 0, 80),
            'statement_label' => mb_substr($kind === 'bank' && $date !== null ? substr($date, 0, 7)
                : ($statementLabel !== '' ? $statementLabel : (string) ($date ?? 'ABRA')), 0, 20),
            'account_number' => mb_substr($accountNumber, 0, 40),
            'bank_code' => $bankAccount['bank_code'] ?? mb_substr(trim((string) ($row['bankaKod'] ?? $row['kodBanky'] ?? $bankRelation['kodBanky'] ?? '')), 0, 4),
            'account_label' => $bankAccount['label'] ?? '',
            'account_bank_name' => $bankAccount['bank_name'] ?? '',
            'account_iban' => $bankAccount['iban'] ?? '',
            'account_bic' => $bankAccount['bic'] ?? '',
            'accounting_code' => $bankAccount['accounting_code'] ?? '',
            'posted' => AbraSource::bool($row['zuctovano'] ?? null),
            'document_no' => mb_substr(trim((string) ($row['kod'] ?? $row['vypisCisDokl'] ?? $key)), 0, 50),
            'variable_symbol' => mb_substr(preg_replace('/\D+/', '', (string) ($row['varSym'] ?? '')) ?: '', 0, 20),
            'constant_symbol' => mb_substr(preg_replace('/\D+/', '', (string) ($row['konSym'] ?? '')) ?: '', 0, 10),
            'specific_symbol' => mb_substr(preg_replace('/\D+/', '', (string) ($row['specSym'] ?? '')) ?: '', 0, 20),
            'counterparty_account' => mb_substr(trim((string) ($row['protiUcet'] ?? $row['ucetProti'] ?? '')), 0, 40),
            'counterparty_bank' => mb_substr(trim((string) ($row['protiKodBanky'] ?? '')), 0, 4),
            'counterparty_name' => mb_substr(trim((string) ($row['nazFirmy'] ?? $row['firmaNazev'] ?? '')), 0, 190),
            'description' => mb_substr(trim((string) ($row['popis'] ?? $row['poznam'] ?? 'Pohyb ABRA Flexi')), 0, 255),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    public function mapLink(array $row): array
    {
        $a = AbraSource::relation($row['a@ref'] ?? $row['a'] ?? null);
        $b = AbraSource::relation($row['b@ref'] ?? $row['b'] ?? null);
        $currency = AbraSource::currency($row['mena'] ?? null);
        $nativeAmount = AbraSource::number($currency === 'CZK' ? ($row['castka'] ?? null) : ($row['castkaMen'] ?? null));
        $amount = $nativeAmount;
        $amount ??= AbraSource::number($row['castka'] ?? $row['castkaMen'] ?? null);
        $blockers = [];
        if ($a === null || $b === null || $amount === null) {
            $blockers[] = 'payment_link_invalid';
        }
        if ($currency === '') {
            $blockers[] = 'payment_currency_invalid';
        }
        return [
            'source_key' => AbraSource::sourceKey($row),
            'source_hash' => AbraSource::hash($row),
            'a' => $a,
            'b' => $b,
            'amount' => $amount !== null ? abs(round($amount, 2)) : 0.0,
            'amount_currency_verified' => $nativeAmount !== null,
            'currency' => $currency,
            'storno' => AbraSource::bool($row['storno'] ?? null) === true,
            'link_type' => AbraSource::reference($row['typVazbyK'] ?? null),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
}
