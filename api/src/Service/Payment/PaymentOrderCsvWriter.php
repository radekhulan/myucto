<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Service\Export\CsvWriter;

/**
 * CSV export platebního příkazu (UTF-8 BOM, `;` oddělovač) — pro ruční zadání do
 * banky nebo archiv. Sloupce kryjí vše potřebné k platbě i ověření účtu příjemce.
 *
 * Bezpečnost: OWASP CSV-injection guard (prefix `'` u buněk začínajících `= + - @ TAB CR`),
 * shodně s `Service\Export\CsvWriter`.
 */
final class PaymentOrderCsvWriter
{
    /**
     * @param array<string,mixed> $order kanonický snapshot příkazu (viz PaymentOrderService::orderView)
     */
    public function build(array $order): string
    {
        $header = [
            'Příjemce', 'Účet', 'Kód banky', 'IBAN', 'BIC',
            'Částka', 'Měna', 'VS', 'KS', 'SS', 'Splatnost', 'Zpráva', 'Ověření účtu',
        ];

        $safe = CsvWriter::safe(...);
        $rows = [];

        $dueDate = $this->czDate((string) ($order['payment_date'] ?? ''));
        foreach ((array) ($order['items'] ?? []) as $it) {
            $rows[] = [
                $safe($it['payee_name'] ?? ''),
                $safe($it['account_number'] ?? ''),
                $safe($it['bank_code'] ?? ''),
                $safe($it['iban'] ?? ''),
                $safe($it['bic'] ?? ''),
                number_format((float) ($it['amount'] ?? 0), 2, '.', ''),
                $safe($it['currency'] ?? $order['currency'] ?? ''),
                $safe($it['variable_symbol'] ?? ''),
                $safe($it['constant_symbol'] ?? ''),
                $safe($it['specific_symbol'] ?? ''),
                $safe($dueDate),
                $safe($it['message'] ?? ''),
                $this->verificationLabel((string) ($it['account_verified'] ?? 'na')),
            ];
        }

        return CsvWriter::build($header, $rows);
    }

    private function verificationLabel(string $v): string
    {
        return match ($v) {
            'verified'   => 'Zveřejněný účet',
            'not_listed' => 'Nezveřejněný účet',
            'unreliable' => 'Nespolehlivý plátce',
            default      => '',
        };
    }

    private function czDate(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($raw))->format('d.m.Y');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
