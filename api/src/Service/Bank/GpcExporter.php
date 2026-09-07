<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class GpcExporter
{
    public function export(array $snapshot): string
    {
        if (!in_array($snapshot['status'] ?? '', ['calculated', 'confirmed'], true)
            || $snapshot['opening'] === null || $snapshot['closing'] === null) {
            throw new \InvalidArgumentException('gpc_balance_unavailable');
        }
        $currency = ['CZK' => '00203', 'EUR' => '00978', 'USD' => '00840', 'GBP' => '00826',
            'CHF' => '00756', 'PLN' => '00985', 'HUF' => '00348'][$snapshot['currency']] ?? null;
        if ($currency === null) throw new \InvalidArgumentException('gpc_currency_unsupported');
        $account = $this->digits($snapshot['account_number'], 16);
        $opening = StatementBalanceService::cents($snapshot['opening']);
        $closing = StatementBalanceService::cents($snapshot['closing']);
        $credit = StatementBalanceService::cents($snapshot['credit']);
        $debit = StatementBalanceService::cents($snapshot['debit']);
        if ($opening + $credit - $debit !== $closing) throw new \InvalidArgumentException('balance_conflict');
        $lines = ['074' . $account . $this->text('MYUCTO EXPORT', 20)
            . $this->date(date('Y-m-d', strtotime($snapshot['from'] . ' -1 day')))
            . $this->digits(abs($opening), 14) . ($opening < 0 ? '-' : '+')
            . $this->digits(abs($closing), 14) . ($closing < 0 ? '-' : '+')
            . $this->digits($debit, 14) . '0' . $this->digits($credit, 14) . '0'
            . $this->digits(substr($snapshot['to'], 5, 2), 3) . $this->date($snapshot['to']) . str_repeat(' ', 14)];
        $references = [];
        $sumCredit = 0;
        $sumDebit = 0;
        foreach ($snapshot['transactions'] as $row) {
            $cents = StatementBalanceService::cents($row['amount']);
            if ($cents >= 0) $sumCredit += $cents; else $sumDebit -= $cents;
            if ($row['posted_at'] < $snapshot['from'] || $row['posted_at'] > $snapshot['to']) throw new \InvalidArgumentException('gpc_date_invalid');
            $ref = (string) ($row['bank_ref'] ?? '');
            if (!preg_match('/^\d{1,13}$/D', $ref) || trim($ref, '0') === '') {
                $ref = '9' . $this->digits($row['id'], 12);
            }
            $ref = $this->digits($ref, 13);
            if (isset($references[$ref])) throw new \InvalidArgumentException('gpc_reference_conflict');
            $references[$ref] = true;
            $counterparty = AuthoritativeTransactionReconciler::account((string) ($row['counterparty_account'] ?? ''), (string) ($row['counterparty_bank'] ?? ''));
            $description = (string) ($row['description'] ?? '');
            if ($counterparty === null && !empty($row['counterparty_account'])) $description = $row['counterparty_account'] . ' ' . $description;
            if ((string) ($row['bank_ref'] ?? '') !== '') $description = 'REF: ' . $row['bank_ref'] . ' ' . $description;
            $lines[] = '075' . $account . ($counterparty === null ? str_repeat('0', 16) : substr($counterparty, 5))
                . $ref . $this->digits(abs($cents), 12) . ($cents < 0 ? '1' : '2')
                . $this->digits($row['variable_symbol'] ?? '', 10) . '00'
                . ($counterparty === null ? '0000' : substr($counterparty, 0, 4))
                . $this->digits($row['constant_symbol'] ?? '', 4) . $this->digits($row['specific_symbol'] ?? '', 10)
                . $this->date($row['posted_at']) . $this->text((string) ($row['counterparty_name'] ?? ''), 20)
                . $currency . $this->date($row['posted_at']);
            $encoded = $this->text(trim($description), 250);
            if (trim($encoded) !== '') {
                $lines[] = '078' . substr($encoded, 0, 125);
                if (trim(substr($encoded, 125)) !== '') $lines[] = '079' . substr($encoded, 125, 125);
            }
        }
        if ($sumCredit !== $credit || $sumDebit !== $debit) throw new \InvalidArgumentException('balance_conflict');
        foreach ($lines as $line) if (strlen($line) !== 128) throw new \LogicException('Invalid GPC record length.');
        return implode("\r\n", $lines) . "\r\n";
    }

    private function digits(string|int $value, int $length): string
    {
        $value = ltrim((string) $value, '0');
        if (strlen($value) > $length || ($value !== '' && !ctype_digit($value))) throw new \InvalidArgumentException('gpc_field_overflow');
        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }

    private function text(string $value, int $length): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);
        if ($value === null) throw new \InvalidArgumentException('gpc_text_invalid');
        $encoded = iconv('UTF-8', 'Windows-1250//TRANSLIT', $value);
        if ($encoded === false) throw new \InvalidArgumentException('gpc_text_invalid');
        return str_pad(substr($encoded, 0, $length), $length, ' ');
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > '2099-12-31') {
            throw new \InvalidArgumentException('gpc_date_invalid');
        }
        return $parsed->format('dmy');
    }
}
