<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * Pohyby MONETA API (`/aisp/my/accounts/{id}/transactions`) na řádky importu.
 *
 * Banka filtruje období podle `valueDate`, ale pohyb se eviduje k `bookingDate`,
 * takže datum zaúčtování smí ležet mimo požadované období. Blokace (`PDNG`) se
 * neimportují, objeví se až jako zaúčtovaný pohyb.
 */
final class MonetaTransactionParser
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(array $rows, string $iban, string $currency, string $statementDate): array
    {
        $this->date($statementDate);
        if (preg_match('/^CZ\d{2}0600\d{16}$/D', $iban) !== 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $this->invalid();
        }
        $transactions = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw $this->invalid();
            }
            $status = $row['status'] ?? null;
            if ($status === 'PDNG') {
                continue;
            }
            if ($status !== 'BOOK') {
                throw $this->invalid();
            }
            $reference = $row['entryReference'] ?? null;
            if (!is_string($reference) || $reference === '' || strlen($reference) > 512 || isset($seen[$reference])) {
                throw $this->invalid();
            }
            $seen[$reference] = true;

            $date = $this->bookingDate($row);
            $amount = $row['amount'] ?? null;
            if (!is_array($amount) || ($amount['currency'] ?? null) !== $currency) {
                throw $this->invalid();
            }
            $cents = $this->cents($amount['value'] ?? null);
            $direction = $row['creditDebitIndicator'] ?? null;
            if (!in_array($direction, ['CRDT', 'DBIT'], true)) {
                throw $this->invalid();
            }

            $details = $row['entryDetails']['transactionDetails'] ?? [];
            if (!is_array($details)) {
                throw $this->invalid();
            }
            $side = $direction === 'CRDT' ? 'debtor' : 'creditor';
            $parties = is_array($details['relatedParties'] ?? null) ? $details['relatedParties'] : [];
            [$counterparty, $bank] = $this->counterparty($parties[$side . 'Account']['identification'] ?? null);
            $remittance = is_array($details['remittanceInformation'] ?? null) ? $details['remittanceInformation'] : [];
            $symbols = $this->symbols($remittance['structured']['creditorReferenceInformation']['reference'] ?? null);
            $references = is_array($details['references'] ?? null) ? $details['references'] : [];

            $transactions[] = [
                'posted_at' => $date,
                'amount' => ($direction === 'DBIT' ? -$cents : $cents) / 100,
                'currency' => $currency,
                'variable_symbol' => $symbols['VS'],
                'constant_symbol' => $symbols['KS'],
                'specific_symbol' => $symbols['SS'],
                'counterparty_account' => $counterparty,
                'counterparty_bank' => $bank,
                'counterparty_name' => $this->text($parties[$side]['name'] ?? null, 190),
                'description' => $this->text(
                    $remittance['unstructured']
                        ?? $references['transactionDescription']
                        ?? $details['additionalTransactionInformation']
                        ?? null,
                    255,
                ),
                'bank_ref' => 'moneta:' . substr(hash('sha256', $reference), 0, 33),
            ];
        }

        return [
            'header' => [
                'account_number' => $iban,
                'currency' => $currency,
                'statement_date' => $statementDate,
                'statement_number' => null,
                'prev_balance' => null,
                'curr_balance' => null,
                'debit_total' => null,
                'credit_total' => null,
            ],
            'transactions' => $transactions,
        ];
    }

    /** @param array<string,mixed> $row */
    private function bookingDate(array $row): string
    {
        foreach (['bookingDate', 'valueDate'] as $key) {
            $value = $row[$key]['date'] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
                $date = substr($value, 0, 10);
                $this->date($date);
                return $date;
            }
        }
        throw $this->invalid();
    }

    private function cents(mixed $value): int
    {
        if (is_int($value)) {
            $cents = $value * 100;
        } elseif (is_float($value) && is_finite($value)) {
            $cents = (int) round($value * 100);
            if (abs($value * 100 - $cents) > 0.000001) {
                throw $this->invalid();
            }
        } elseif (is_string($value) && preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $value, $match) === 1) {
            $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        } else {
            throw $this->invalid();
        }
        if ($cents < 1 || $cents > 99_999_999_999_999) {
            throw $this->invalid();
        }
        return $cents;
    }

    /**
     * Účet protistrany chodí jako IBAN, nebo tuzemsky `předčíslí číslo/kód`
     * s předčíslím odděleným MEZEROU (`0 0000000019/2250`).
     *
     * @return array{0:?string,1:?string}
     */
    private function counterparty(mixed $identification): array
    {
        if (!is_array($identification)) {
            return [null, null];
        }
        $iban = $identification['iban'] ?? null;
        if (is_string($iban) && $iban !== '') {
            $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
            if (preg_match('/^CZ\d{2}(\d{4})(\d{6})(\d{10})$/D', $compact, $m) === 1) {
                return [$this->national($m[2], $m[3]), $m[1]];
            }
            return [mb_substr($compact, 0, 40), null];
        }
        $other = $identification['other']['identification'] ?? null;
        if (!is_string($other) || trim($other) === '') {
            return [null, null];
        }
        $other = trim($other);
        if (preg_match('/^(?:(\d{1,6})[\s-]+)?(\d{1,10})\/(\d{4})$/D', $other, $m) === 1) {
            return [$this->national($m[1], $m[2]), $m[3]];
        }
        return [$this->text($other, 40), null];
    }

    private function national(string $prefix, string $number): ?string
    {
        $prefix = ltrim($prefix, '0');
        $number = ltrim($number, '0');
        if ($number === '') {
            return null;
        }
        return $prefix === '' ? $number : $prefix . '-' . $number;
    }

    /**
     * `VS:123,KS:0308,SS:1` nebo `VS:123 KS:0308 SS:1` (zápis z OpenAPI MONETA), případně pole
     * takových položek (formát ČOBS).
     *
     * @return array{VS:?string,KS:?string,SS:?string}
     */
    private function symbols(mixed $reference): array
    {
        $symbols = ['VS' => null, 'KS' => null, 'SS' => null];
        if ($reference === null) {
            return $symbols;
        }
        $parts = [];
        foreach (is_array($reference) ? $reference : [$reference] as $item) {
            if (!is_string($item)) {
                throw $this->invalid();
            }
            array_push($parts, ...preg_split('/\s*,\s*|\s+(?=(?:VS|KS|SS)\s*:)/', $item));
        }
        foreach ($parts as $part) {
            if (preg_match('/^\s*(VS|KS|SS)\s*:\s*(\d{1,10})\s*$/D', $part, $m) !== 1) {
                continue;
            }
            $value = ltrim($m[2], '0');
            if ($value === '') {
                continue;
            }
            if ($symbols[$m[1]] !== null && $symbols[$m[1]] !== $value) {
                throw $this->invalid();
            }
            $symbols[$m[1]] = $value;
        }
        return $symbols;
    }

    private function text(mixed $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw $this->invalid();
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw $this->invalid();
        }
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatné pohyby MONETA Money Bank.');
    }
}
