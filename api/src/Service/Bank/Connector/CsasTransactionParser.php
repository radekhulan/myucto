<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class CsasTransactionParser
{
    public function parse(array $rows, string $iban, string $currency, string $from, string $to): array
    {
        $this->date($from);
        $this->date($to);
        if ($from > $to || !preg_match('/^CZ\d{22}$/D', $iban) || !preg_match('/^[A-Z]{3}$/D', $currency)) throw $this->invalid();
        $transactions = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw $this->invalid();
            if (in_array($row['status'] ?? null, ['INFO', 'PDNG', 'PENDING'], true)) continue;
            if (($row['status'] ?? null) !== 'BOOK') throw $this->invalid();
            $reference = $row['entryReference'] ?? null;
            if (!is_string($reference) || $reference === '' || strlen($reference) > 512) throw $this->invalid();
            if (isset($seen[$reference])) throw $this->invalid();
            $seen[$reference] = true;
            $date = $row['bookingDate']['date'] ?? '';
            if (!is_string($date)) throw $this->invalid();
            if (preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00(?:\.0+)?Z$/D', $date)) $date = substr($date, 0, 10);
            $this->date($date);
            if ($date < $from || $date > $to || ($row['amount']['currency'] ?? null) !== $currency) throw $this->invalid();
            $value = $row['amount']['value'] ?? null;
            if (!is_int($value) && !is_float($value) && !is_string($value)) throw $this->invalid();
            $decimal = (string) $value;
            if (!preg_match('/^(\d{1,11})(?:\.(\d{1,2}))?$/D', $decimal, $match)) throw $this->invalid();
            $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
            $direction = $row['creditDebitIndicator'] ?? null;
            if ($cents < 1 || !in_array($direction, ['CRDT', 'DBIT'], true)) throw $this->invalid();
            $details = $row['entryDetails']['transactionDetails'] ?? [];
            if (!is_array($details)) throw $this->invalid();
            $side = $direction === 'CRDT' ? 'debtor' : 'creditor';
            $parties = $details['relatedParties'] ?? [];
            $account = $parties[$side . 'Account']['identification'] ?? [];
            $counterparty = $account['iban'] ?? $account['other']['identification'] ?? null;
            $bank = null;
            if (is_string($counterparty) && preg_match('/^CZ\d{22}$/D', $counterparty)) $bank = substr($counterparty, 4, 4);
            if (is_string($counterparty) && preg_match('#/([0-9]{4})$#D', $counterparty, $bankMatch)) {
                $bank = $bankMatch[1];
                $counterparty = explode('/', $counterparty)[0];
            }
            $remittance = $details['remittanceInformation'] ?? [];
            $references = $remittance['structured']['creditorReferenceInformation']['reference'] ?? [];
            if (!is_array($references)) throw $this->invalid();
            $symbols = ['VS' => null, 'KS' => null, 'SS' => null];
            foreach ($references as $item) {
                if (!is_string($item)) throw $this->invalid();
                if (preg_match('/^(VS|KS|SS):(\d{1,10})$/D', $item, $symbol)) {
                    if ($symbols[$symbol[1]] !== null && $symbols[$symbol[1]] !== $symbol[2]) throw $this->invalid();
                    $symbols[$symbol[1]] = $symbol[2];
                }
            }
            $transactions[] = [
                'posted_at' => $date, 'amount' => ($direction === 'DBIT' ? -$cents : $cents) / 100,
                'currency' => $currency, 'bank_ref' => 'csas:' . substr(hash('sha256', $reference), 0, 35),
                'variable_symbol' => $symbols['VS'], 'constant_symbol' => $symbols['KS'], 'specific_symbol' => $symbols['SS'],
                'counterparty_account' => $this->text($counterparty, 40), 'counterparty_bank' => $bank,
                'counterparty_name' => $this->text($parties[$side]['name'] ?? null, 190),
                'description' => $this->text($remittance['unstructured'] ?? $details['additionalTransactionInformation'] ?? null, 255),
            ];
        }
        return ['header' => ['account_number' => $iban, 'currency' => $currency, 'statement_date' => $to,
            'statement_number' => null, 'prev_balance' => null, 'curr_balance' => null, 'debit_total' => null, 'credit_total' => null],
            'transactions' => $transactions];
    }

    private function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw $this->invalid();
    }

    private function text(mixed $value, int $length): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw $this->invalid();
        return mb_substr($value, 0, $length);
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatné pohyby České spořitelny.');
    }
}
