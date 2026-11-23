<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Převod pohybů RB Premium API na kontrakt importéru výpisů.
 *
 * Dokumentace RB nezaručuje znaménko částky, délku `entryReference`, časové pásmo
 * `bookingDate` ani formát čísla účtu protistrany a symbolů. Odmítnutý pohyb přitom
 * shodí celý výpis, takže napojení nejde ani ověřit. Pohyb se proto odmítne jen tehdy,
 * když chybí to, bez čeho ho nejde zaúčtovat (reference, částka, směr, datum, měna účtu);
 * doplňkové údaje se normalizují, zkrátí na šířku sloupce, nebo vynechají.
 */
final class RaiffeisenbankTransactionParser
{
    private const TIMEZONE = 'Europe/Prague';
    private const BANK_REF_MAX = 40;

    /**
     * @param list<array<string,mixed>> $transactions
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(
        array $transactions,
        string $accountNumber,
        string $currency,
        string $from,
        string $to,
    ): array {
        $accountNumber = trim($accountNumber);
        if ($accountNumber === '' || mb_strlen($accountNumber) > 40) {
            throw new \RuntimeException('RB transactions: neplatné číslo vlastního účtu.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException('RB transactions: neplatná měna účtu.');
        }
        $fromDate = $this->date($from, 'počátek období');
        $toDate = $this->date($to, 'konec období');
        if ($fromDate > $toDate) {
            throw new \RuntimeException('RB transactions: neplatné období.');
        }

        $parsed = [];
        foreach ($transactions as $index => $transaction) {
            if (!is_array($transaction)) {
                throw $this->invalid($index, 'pohyb není objekt');
            }
            $row = $this->transaction($transaction, $currency, $index);
            if ($row !== null) {
                $parsed[] = $row;
            }
        }

        return [
            'header' => [
                'account_number' => $accountNumber,
                'statement_date' => $toDate->format('Y-m-d'),
                'statement_number' => null,
                'prev_balance' => null,
                'curr_balance' => null,
                'debit_total' => null,
                'credit_total' => null,
            ],
            'transactions' => $parsed,
        ];
    }

    /** @return array<string,mixed>|null null = pohyb bez peněžního dopadu (nulová částka) */
    private function transaction(array $transaction, string $currency, int|string $index): ?array
    {
        $reference = $this->requiredString($transaction, 'entryReference', $index);

        $amount = $transaction['amount'] ?? null;
        if (!is_array($amount) || !array_key_exists('value', $amount)) {
            throw $this->invalid($index, 'chybí amount.value');
        }
        $transactionCurrency = strtoupper($this->requiredString($amount, 'currency', $index));
        if ($transactionCurrency !== $currency) {
            throw $this->invalid($index, 'měna pohybu neodpovídá účtu');
        }
        $direction = $this->requiredString($transaction, 'creditDebitIndication', $index);
        if (!in_array($direction, ['DBIT', 'CRDT'], true)) {
            throw $this->invalid($index, 'neznámý směr pohybu');
        }
        $cents = $this->cents($amount['value'], $index);
        if ($cents === 0) {
            return null;
        }
        // Směr určuje creditDebitIndication; RB může vracet částku se znaménkem i bez něj.
        $cents = $direction === 'DBIT' ? -abs($cents) : abs($cents);

        $rawDate = $this->optionalString($transaction, 'bookingDate')
            ?? $this->optionalString($transaction, 'valueDate')
            ?? throw $this->invalid($index, 'chybí bookingDate');
        $bookingDate = $this->bookingDate($rawDate, $index);

        $details = $this->object($this->object($transaction['entryDetails'] ?? null)['transactionDetails'] ?? null);
        $counterParty = $this->object($this->object($details['relatedParties'] ?? null)['counterParty'] ?? null);
        $organisation = $this->object($counterParty['organisationIdentification'] ?? null);
        $remittance = $this->object($details['remittanceInformation'] ?? null);
        $creditorReference = $this->object($remittance['creditorReferenceInformation'] ?? null);

        $name = $this->optionalString($counterParty, 'name') ?? $this->optionalString($organisation, 'name');
        $description = implode(' | ', array_values(array_filter([
            $this->optionalString($remittance, 'unstructured'),
            $this->optionalString($details, 'originatorMessage'),
        ], static fn (?string $value): bool => $value !== null)));

        return [
            'posted_at' => $bookingDate,
            'amount' => (float) ($cents / 100),
            'currency' => $transactionCurrency,
            'variable_symbol' => $this->symbol($creditorReference, 'variable', 20),
            'constant_symbol' => $this->symbol($creditorReference, 'constant', 10),
            'specific_symbol' => $this->symbol($creditorReference, 'specific', 20),
            'counterparty_account' => $this->counterpartyAccount($this->object($counterParty['account'] ?? null)),
            'counterparty_bank' => $this->bankCode($organisation),
            'counterparty_name' => $this->truncate($name, 190),
            'description' => $this->truncate($description !== '' ? $description : null, 255),
            'bank_ref' => $this->bankRef($reference),
        ];
    }

    /** Reference delší než sloupec nebo s neobvyklými znaky se nahradí stabilním otiskem, aby párování duplicit zůstalo deterministické. */
    private function bankRef(string $reference): string
    {
        if (strlen($reference) <= self::BANK_REF_MAX && preg_match('/^[\x21-\x7E]+$/D', $reference)) {
            return $reference;
        }
        return 'RB-' . substr(hash('sha256', $reference), 0, self::BANK_REF_MAX - 3);
    }

    private function cents(mixed $raw, int|string $index): int
    {
        if (is_string($raw) && is_numeric(trim($raw))) {
            $raw = (float) trim($raw);
        }
        if (is_int($raw)) {
            return $raw * 100;
        }
        if (!is_float($raw) || !is_finite($raw) || abs($raw) >= 1e12) {
            throw $this->invalid($index, 'amount.value není konečné číslo');
        }
        return (int) round($raw * 100);
    }

    /** Datum zaúčtování v kalendáři banky: čas s pásmem se převede do SEČ/SELČ, stejně jako se RB ptáme na období. */
    private function bookingDate(string $raw, int|string $index): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
            try {
                return $this->date($raw, 'bookingDate')->format('Y-m-d');
            } catch (\RuntimeException) {
                throw $this->invalid($index, 'neplatné bookingDate');
            }
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/D', $raw, $match)) {
            throw $this->invalid($index, 'neplatné bookingDate');
        }
        try {
            $this->date($match[1], 'bookingDate');
            $local = new \DateTimeZone(self::TIMEZONE);
            $moment = new \DateTimeImmutable($raw, $local);
        } catch (\Throwable) {
            throw $this->invalid($index, 'neplatné bookingDate');
        }
        return ($match[2] ?? '') === '' ? $match[1] : $moment->setTimezone($local)->format('Y-m-d');
    }

    /** @param array<string,mixed> $account */
    private function counterpartyAccount(array $account): ?string
    {
        $iban = $this->optionalString($account, 'iban');
        if ($iban !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
            if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
                return $iban;
            }
        }
        $number = $this->optionalString($account, 'accountNumber');
        if ($number === null) {
            return null;
        }
        $number = (string) preg_replace('/\s+/', '', $number);
        $prefix = $this->optionalString($account, 'accountNumberPrefix');
        if ($prefix === null && preg_match('/^(\d{1,6})-(\d{1,10})$/D', $number, $parts)) {
            [$prefix, $number] = [$parts[1], $parts[2]];
        }
        if (!preg_match('/^[A-Za-z0-9]{1,34}$/D', $number)) {
            return null;
        }
        if ($prefix === null || preg_match('/^0+$/D', $prefix) || !preg_match('/^\d{1,6}$/D', $prefix) || !preg_match('/^\d{1,10}$/D', $number)) {
            return $number;
        }
        return ltrim($prefix, '0') . '-' . $number;
    }

    /** @param array<string,mixed> $organisation */
    private function bankCode(array $organisation): ?string
    {
        $bankCode = $this->optionalString($organisation, 'bankCode');
        return $bankCode !== null && preg_match('/^\d{4}$/D', $bankCode) ? $bankCode : null;
    }

    /** Symbol jiný než číselný nebo delší než sloupec se vynechá; pohyb zůstane, jen bez symbolu. */
    private function symbol(array $source, string $key, int $maxLength): ?string
    {
        $symbol = $this->optionalString($source, $key);
        if ($symbol === null) {
            return null;
        }
        $symbol = ltrim((string) preg_replace('/\s+/', '', $symbol), '0');
        return $symbol !== '' && preg_match('/^\d+$/D', $symbol) && strlen($symbol) <= $maxLength ? $symbol : null;
    }

    private function requiredString(array $source, string $key, int|string $index): string
    {
        return $this->optionalString($source, $key) ?? throw $this->invalid($index, "chybí {$key}");
    }

    private function optionalString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function truncate(?string $value, int $maxLength): ?string
    {
        return $value !== null && mb_strlen($value) > $maxLength ? rtrim(mb_substr($value, 0, $maxLength)) : $value;
    }

    private function date(string $raw, string $field): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new \RuntimeException("RB transactions: neplatný {$field}.");
        }
        return $date;
    }

    private function invalid(int|string $index, string $reason): \RuntimeException
    {
        return new \RuntimeException("RB transactions: neplatný pohyb na pozici {$index}: {$reason}.");
    }
}
