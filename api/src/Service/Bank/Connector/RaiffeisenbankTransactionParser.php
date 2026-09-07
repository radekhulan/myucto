<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class RaiffeisenbankTransactionParser
{
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
            $parsed[] = $this->transaction($transaction, $currency, $fromDate, $toDate, $index);
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

    /** @return array<string,mixed> */
    private function transaction(
        array $transaction,
        string $currency,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int|string $index,
    ): array {
        $reference = $this->requiredString($transaction, 'entryReference', $index);
        if (
            strlen($reference) > 40
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $reference)
        ) {
            throw $this->invalid($index, 'neplatné entryReference');
        }

        $amount = $transaction['amount'] ?? null;
        if (!is_array($amount) || !array_key_exists('value', $amount)) {
            throw $this->invalid($index, 'chybí amount.value');
        }
        $transactionCurrency = strtoupper($this->requiredString($amount, 'currency', $index));
        if (!preg_match('/^[A-Z]{3}$/', $transactionCurrency) || $transactionCurrency !== $currency) {
            throw $this->invalid($index, 'měna pohybu neodpovídá účtu');
        }
        $amountValue = $this->amount($amount['value'], $index);
        $direction = $this->requiredString($transaction, 'creditDebitIndication', $index);
        if (
            !in_array($direction, ['DBIT', 'CRDT'], true)
            || ($direction === 'DBIT' && $amountValue >= 0)
            || ($direction === 'CRDT' && $amountValue <= 0)
        ) {
            throw $this->invalid($index, 'částka neodpovídá DBIT/CRDT');
        }

        $bookingDate = $this->bookingDate(
            $this->requiredString($transaction, 'bookingDate', $index),
            $index,
        );
        if ($bookingDate < $from || $bookingDate > $to) {
            throw $this->invalid($index, 'bookingDate je mimo požadované období');
        }

        $entryDetails = $transaction['entryDetails'] ?? [];
        if (!is_array($entryDetails)) {
            throw $this->invalid($index, 'neplatné entryDetails');
        }
        $details = $entryDetails['transactionDetails'] ?? [];
        if (!is_array($details)) {
            throw $this->invalid($index, 'neplatné transactionDetails');
        }
        $relatedParties = $details['relatedParties'] ?? [];
        if (!is_array($relatedParties)) {
            throw $this->invalid($index, 'neplatné relatedParties');
        }
        $counterParty = $relatedParties['counterParty'] ?? [];
        if (!is_array($counterParty)) {
            throw $this->invalid($index, 'neplatná protistrana');
        }
        $organisation = $counterParty['organisationIdentification'] ?? [];
        if (!is_array($organisation)) {
            throw $this->invalid($index, 'neplatná identifikace protistrany');
        }
        $counterAccount = $counterParty['account'] ?? [];
        if (!is_array($counterAccount)) {
            throw $this->invalid($index, 'neplatný účet protistrany');
        }
        $remittance = $details['remittanceInformation'] ?? [];
        if (!is_array($remittance)) {
            throw $this->invalid($index, 'neplatné remittanceInformation');
        }
        $creditorReference = $remittance['creditorReferenceInformation'] ?? [];
        if (!is_array($creditorReference)) {
            throw $this->invalid($index, 'neplatné creditorReferenceInformation');
        }

        $name = $this->optionalString($counterParty, 'name', $index)
            ?? $this->optionalString($organisation, 'name', $index);
        $name = $this->bounded($name, 190, $index, 'název protistrany');
        $descriptionParts = array_values(array_filter([
            $this->optionalString($remittance, 'unstructured', $index),
            $this->optionalString($details, 'originatorMessage', $index),
        ], static fn (?string $value): bool => $value !== null));
        $description = $descriptionParts !== [] ? implode(' | ', $descriptionParts) : null;
        $description = $this->bounded($description, 255, $index, 'popis');

        return [
            'posted_at' => $bookingDate->format('Y-m-d'),
            'amount' => $amountValue,
            'currency' => $transactionCurrency,
            'variable_symbol' => $this->symbol($creditorReference, 'variable', 20, $index),
            'constant_symbol' => $this->symbol($creditorReference, 'constant', 10, $index),
            'specific_symbol' => $this->symbol($creditorReference, 'specific', 20, $index),
            'counterparty_account' => $this->counterpartyAccount($counterAccount, $index),
            'counterparty_bank' => $this->bankCode($organisation, $index),
            'counterparty_name' => $name,
            'description' => $description,
            'bank_ref' => $reference,
        ];
    }

    private function amount(mixed $raw, int|string $index): float
    {
        if (is_int($raw)) {
            $decimal = (string) $raw;
        } elseif (is_float($raw) && is_finite($raw)) {
            $decimal = json_encode($raw, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } else {
            throw $this->invalid($index, 'amount.value není konečné číslo');
        }
        if (!preg_match('/^(-?)(0|[1-9]\d{0,11})(?:\.(\d{1,2}))?$/D', $decimal, $match)) {
            throw $this->invalid($index, 'amount.value nemá přesnost na celé centy');
        }
        $cents = ((int) $match[2] * 100) + (int) str_pad($match[3] ?? '', 2, '0');
        if ($cents === 0) {
            throw $this->invalid($index, 'nulová částka');
        }
        if ($match[1] === '-') {
            $cents = -$cents;
        }
        return $cents / 100;
    }

    private function bookingDate(string $raw, int|string $index): \DateTimeImmutable
    {
        if (!preg_match(
            '/^(\d{4}-\d{2}-\d{2})(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D',
            $raw,
            $match,
        )) {
            throw $this->invalid($index, 'neplatné bookingDate');
        }
        try {
            return $this->date($match[1], 'bookingDate');
        } catch (\RuntimeException) {
            throw $this->invalid($index, 'neplatné bookingDate');
        }
    }

    private function counterpartyAccount(array $account, int|string $index): ?string
    {
        $iban = $this->optionalString($account, 'iban', $index);
        if ($iban !== null) {
            $iban = strtoupper(str_replace(' ', '', $iban));
            if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
                throw $this->invalid($index, 'neplatný IBAN protistrany');
            }
            return $iban;
        }
        $number = $this->optionalString($account, 'accountNumber', $index);
        $prefix = $this->optionalString($account, 'accountNumberPrefix', $index);
        if ($number === null && $prefix === null) {
            return null;
        }
        if ($number === null || !preg_match('/^[A-Za-z0-9]{1,34}$/D', $number)) {
            throw $this->invalid($index, 'neplatné číslo účtu protistrany');
        }
        if ($prefix === null || preg_match('/^0+$/D', $prefix)) {
            return $number;
        }
        if (!preg_match('/^\d{1,6}$/D', $prefix) || !preg_match('/^\d{1,10}$/D', $number)) {
            throw $this->invalid($index, 'neplatný prefix účtu protistrany');
        }
        return ltrim($prefix, '0') . '-' . $number;
    }

    private function bankCode(array $organisation, int|string $index): ?string
    {
        $bankCode = $this->optionalString($organisation, 'bankCode', $index);
        if ($bankCode !== null && !preg_match('/^\d{4}$/D', $bankCode)) {
            throw $this->invalid($index, 'neplatný kód banky protistrany');
        }
        return $bankCode;
    }

    private function symbol(array $source, string $key, int $maxLength, int|string $index): ?string
    {
        $symbol = $this->optionalString($source, $key, $index);
        if ($symbol === null) {
            return null;
        }
        if (!preg_match('/^\d+$/D', $symbol)) {
            throw $this->invalid($index, "neplatný symbol {$key}");
        }
        $symbol = ltrim($symbol, '0');
        if ($symbol === '') {
            return null;
        }
        if (strlen($symbol) > $maxLength) {
            throw $this->invalid($index, "příliš dlouhý symbol {$key}");
        }
        return $symbol;
    }

    private function requiredString(array $source, string $key, int|string $index): string
    {
        $value = $this->optionalString($source, $key, $index);
        if ($value === null) {
            throw $this->invalid($index, "chybí {$key}");
        }
        return $value;
    }

    private function optionalString(array $source, string $key, int|string $index): ?string
    {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key]) || !mb_check_encoding($source[$key], 'UTF-8')) {
            throw $this->invalid($index, "neplatné textové pole {$key}");
        }
        $value = trim($source[$key]);
        return $value !== '' ? $value : null;
    }

    private function bounded(?string $value, int $maxLength, int|string $index, string $field): ?string
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw $this->invalid($index, "příliš dlouhé pole {$field}");
        }
        return $value;
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
