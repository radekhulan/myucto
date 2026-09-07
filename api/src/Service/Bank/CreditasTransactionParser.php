<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class CreditasTransactionParser
{
    /**
     * @param list<array<string,mixed>> $transactions
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parse(array $transactions, string $accountNumber, string $statementDate): array
    {
        if ($accountNumber === '' || strlen($accountNumber) > 42 || !$this->isDate($statementDate)) {
            throw new \InvalidArgumentException('Neplatná identita nebo datum výpisu CREDITAS.');
        }
        $parsed = [];
        $creditTotal = 0.0;
        $debitTotal = 0.0;
        foreach ($transactions as $transaction) {
            $parsedTransaction = $this->transaction($transaction);
            $parsed[] = $parsedTransaction;
            if ($parsedTransaction['amount'] >= 0) {
                $creditTotal += $parsedTransaction['amount'];
            } else {
                $debitTotal += abs($parsedTransaction['amount']);
            }
        }
        return [
            'header' => [
                'account_number' => $accountNumber,
                'statement_date' => $statementDate,
                'statement_number' => 'creditas-' . str_replace('-', '', $statementDate),
                'prev_balance' => null,
                'curr_balance' => null,
                'debit_total' => round($debitTotal, 2),
                'credit_total' => round($creditTotal, 2),
            ],
            'transactions' => $parsed,
        ];
    }

    /**
     * @param array<string,mixed> $transaction
     * @return array<string,mixed>
     */
    private function transaction(array $transaction): array
    {
        $id = $transaction['transactionId'] ?? null;
        $type = $transaction['type'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $date = $transaction['effectiveDate'] ?? null;
        if (
            !is_string($id)
            || $id === ''
            || !in_array($type, ['CREDIT', 'DEBIT'], true)
            || !is_array($amount)
            || !isset($amount['value'], $amount['currency'])
            || !is_string($amount['value'])
            || !is_string($amount['currency'])
            || preg_match('/^[A-Z]{3}$/D', $amount['currency']) !== 1
            || !is_string($date)
            || !$this->isDate($date)
        ) {
            throw new \InvalidArgumentException('Neplatná transakce CREDITAS.');
        }
        $value = $this->amount($amount['value']);
        $partner = isset($transaction['partnerAccount']) && is_array($transaction['partnerAccount'])
            ? $transaction['partnerAccount']
            : [];
        return [
            'posted_at' => $date,
            'amount' => round($type === 'DEBIT' ? -$value : $value, 2),
            'currency' => $amount['currency'],
            'variable_symbol' => $this->optional($transaction, 'variableSymbol', 10),
            'constant_symbol' => $this->optional($transaction, 'constantSymbol', 4),
            'specific_symbol' => $this->optional($transaction, 'specificSymbol', 10),
            'counterparty_account' => $this->optional($partner, 'number', 42),
            'counterparty_bank' => $this->optional($partner, 'bankCode', 11),
            'counterparty_name' => $this->optional($partner, 'partnerName', 190),
            'description' => $this->description($transaction),
            'bank_ref' => strlen($id) <= 40 ? $id : 'creditas:' . substr(hash('sha256', $id), 0, 32),
            'metadata' => $transaction,
        ];
    }

    private function amount(string $raw): float
    {
        if (preg_match('/^-?(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?$/D', $raw, $match) !== 1) {
            throw new \InvalidArgumentException('Neplatná částka transakce CREDITAS.');
        }
        $fraction = $match[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > 2) {
            throw new \InvalidArgumentException('Částka transakce CREDITAS má nepodporovanou přesnost.');
        }
        $cents = ((int) $match[1] * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        if ($cents === 0) {
            throw new \InvalidArgumentException('Částka transakce CREDITAS nesmí být nulová.');
        }
        return $cents / 100;
    }

    /** @param array<string,mixed> $data */
    private function optional(array $data, string $key, int $maxLength): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Neplatné pole transakce CREDITAS.');
        }
        return $value;
    }

    /** @param array<string,mixed> $transaction */
    private function description(array $transaction): ?string
    {
        foreach (['remittanceInfo', 'payersReference', 'codeI18N', 'userNote'] as $key) {
            $value = $this->optional($transaction, $key, 255);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
