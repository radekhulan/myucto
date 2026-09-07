<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusTransactionParser
{
    /**
     * @param list<array<string,mixed>> $transactions
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(
        #[\SensitiveParameter] array $transactions,
        string $accountNumber,
        string $currency,
        string $from,
        string $to,
    ): array {
        $accountNumber = trim($accountNumber);
        if ($accountNumber === '' || mb_strlen($accountNumber) > 40) {
            throw new \RuntimeException('KB+ transactions: neplatné číslo vlastního účtu.');
        }
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \RuntimeException('KB+ transactions: neplatná měna účtu.');
        }
        $fromDate = $this->date($from, 'počátek období');
        $toDate = $this->date($to, 'konec období');
        if ($fromDate > $toDate) {
            throw new \RuntimeException('KB+ transactions: neplatné období.');
        }

        $parsed = [];
        $references = [];
        foreach ($transactions as $index => $transaction) {
            if (!is_array($transaction)) {
                throw $this->invalid($index, 'pohyb není objekt');
            }
            $status = $this->requiredString($transaction, 'status', $index);
            if ($status === 'PDNG') {
                $this->validatePending($transaction, $currency, $index);
                continue;
            }
            if ($status !== 'BOOK') {
                throw $this->invalid($index, 'neznámý status pohybu');
            }
            $mapped = $this->transaction($transaction, $currency, $fromDate, $toDate, $index);
            if (isset($references[$mapped['bank_ref']])) {
                throw $this->invalid($index, 'duplicitní references.accountServicer');
            }
            $references[$mapped['bank_ref']] = true;
            $parsed[] = $mapped;
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
        $this->validateCommon($transaction, $currency, $index);
        $reference = $this->accountServicer($transaction, $index);
        $amount = $this->amount($transaction['amount']['value'], $index);
        $direction = $this->requiredString($transaction, 'creditDebitIndicator', $index);
        $signedAmount = $direction === 'DEBIT' ? -$amount : $amount;
        $bookingDate = $this->date(
            $this->requiredString($transaction, 'bookingDate', $index),
            'bookingDate',
            $index,
        );
        if ($bookingDate < $from || $bookingDate > $to) {
            throw $this->invalid($index, 'bookingDate je mimo požadované období');
        }

        $counterParty = $transaction['counterParty'] ?? [];
        if (!is_array($counterParty)) {
            throw $this->invalid($index, 'neplatná protistrana');
        }
        $references = $transaction['references'] ?? [];
        if (!is_array($references)) {
            throw $this->invalid($index, 'neplatné references');
        }

        $descriptionParts = [];
        foreach ([
            $this->optionalString($references, 'receiver', $index),
            $this->optionalString($references, 'myDescription', $index),
            $this->optionalString($transaction, 'additionalTransactionInformation', $index),
        ] as $part) {
            if ($part !== null && !in_array($part, $descriptionParts, true)) {
                $descriptionParts[] = $part;
            }
        }
        $description = $descriptionParts === [] ? null : implode(' | ', $descriptionParts);
        if ($description !== null && mb_strlen($description) > 255) {
            throw $this->invalid($index, 'příliš dlouhý popis');
        }

        return [
            'posted_at' => $bookingDate->format('Y-m-d'),
            'amount' => $signedAmount,
            'currency' => $currency,
            'variable_symbol' => $this->symbol($references, 'variable', 20, $index),
            'constant_symbol' => $this->symbol($references, 'constant', 10, $index),
            'specific_symbol' => $this->symbol($references, 'specific', 20, $index),
            'counterparty_account' => $this->counterpartyAccount($counterParty, $index),
            'counterparty_bank' => $this->bankCode($counterParty, $index),
            'counterparty_name' => $this->bounded(
                $this->optionalString($counterParty, 'name', $index),
                190,
                $index,
                'název protistrany',
            ),
            'description' => $description,
            'bank_ref' => 'kbplus:' . $reference,
        ];
    }

    private function validatePending(array $transaction, string $currency, int|string $index): void
    {
        $this->validateCommon($transaction, $currency, $index);
        $this->accountServicer($transaction, $index);
    }

    private function validateCommon(array $transaction, string $currency, int|string $index): void
    {
        $lastUpdated = $this->requiredString($transaction, 'lastUpdated', $index);
        if (!$this->isDateTime($lastUpdated)) {
            throw $this->invalid($index, 'neplatné lastUpdated');
        }
        if (!in_array($this->requiredString($transaction, 'accountType', $index), ['KB', 'AG'], true)) {
            throw $this->invalid($index, 'neplatné accountType');
        }
        $iban = $this->requiredString($transaction, 'iban', $index);
        if (!$this->isIban($iban)) {
            throw $this->invalid($index, 'neplatný IBAN vlastního účtu');
        }
        if (!in_array($this->requiredString($transaction, 'creditDebitIndicator', $index), ['CREDIT', 'DEBIT'], true)) {
            throw $this->invalid($index, 'neplatný creditDebitIndicator');
        }
        if (!in_array($this->requiredString($transaction, 'transactionType', $index), [
            'INTEREST', 'FEE', 'DOMESTIC', 'FOREIGN', 'SEPA', 'CASH', 'CARD', 'OTHER',
        ], true)) {
            throw $this->invalid($index, 'neplatný transactionType');
        }
        $amount = $transaction['amount'] ?? null;
        if (!is_array($amount) || !array_key_exists('value', $amount)) {
            throw $this->invalid($index, 'chybí amount.value');
        }
        $transactionCurrency = strtoupper($this->requiredString($amount, 'currency', $index));
        if (!preg_match('/^[A-Z]{3}$/D', $transactionCurrency) || $transactionCurrency !== $currency) {
            throw $this->invalid($index, 'měna pohybu neodpovídá účtu');
        }
        $this->amount($amount['value'], $index);
    }

    private function accountServicer(array $transaction, int|string $index): string
    {
        $references = $transaction['references'] ?? null;
        if (!is_array($references)) {
            throw $this->invalid($index, 'chybí references.accountServicer');
        }
        $reference = $this->requiredString($references, 'accountServicer', $index);
        if (strlen($reference) > 33 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $reference)) {
            throw $this->invalid($index, 'neplatné references.accountServicer');
        }
        return $reference;
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
        if (!preg_match('/^(0|[1-9]\d{0,11})(?:\.(\d{1,2}))?$/D', $decimal, $match)) {
            throw $this->invalid($index, 'amount.value nemá přesnost na celé centy');
        }
        $cents = ((int) $match[1] * 100) + (int) str_pad($match[2] ?? '', 2, '0');
        if ($cents === 0) {
            throw $this->invalid($index, 'nulová částka');
        }
        return $cents / 100;
    }

    private function counterpartyAccount(array $counterParty, int|string $index): ?string
    {
        $iban = $this->optionalString($counterParty, 'iban', $index);
        if ($iban !== null) {
            if (!$this->isIban($iban)) {
                throw $this->invalid($index, 'neplatný IBAN protistrany');
            }
            return strtoupper($iban);
        }
        $account = $this->optionalString($counterParty, 'accountNo', $index);
        if ($account !== null && !preg_match('/^[A-Za-z0-9-]{1,34}$/D', $account)) {
            throw $this->invalid($index, 'neplatné číslo účtu protistrany');
        }
        return $account;
    }

    private function bankCode(array $counterParty, int|string $index): ?string
    {
        $bankCode = $this->optionalString($counterParty, 'bankCode', $index);
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
        return $value === '' ? null : $value;
    }

    private function bounded(?string $value, int $maxLength, int|string $index, string $field): ?string
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw $this->invalid($index, "příliš dlouhé pole {$field}");
        }
        return $value;
    }

    private function date(string $raw, string $field, int|string|null $index = null): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            if ($index !== null) {
                throw $this->invalid($index, "neplatné {$field}");
            }
            throw new \RuntimeException("KB+ transactions: neplatný {$field}.");
        }
        return $date;
    }

    private function isDateTime(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function isIban(string $value): bool
    {
        $iban = strtoupper($value);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $remainder = 0;
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }

    private function invalid(int|string $index, string $reason): \RuntimeException
    {
        return new \RuntimeException("KB+ transactions: neplatný pohyb na pozici {$index}: {$reason}.");
    }
}
