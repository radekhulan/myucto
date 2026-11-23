<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\AccountNumberNormalizer;

/**
 * Hromadný příkaz ABO (AboPaymentOrderWriter) na dávku MONETA API
 * `/pisp/my/payments/batch`. Účty se posílají tuzemsky (`[předčíslí-]číslo/kód`),
 * symboly jako `VS:…,KS:…,SS:…` ve strukturované referenci.
 */
final class MonetaAboBatchMapper
{
    /** @return list<array<string,mixed>> */
    public function map(#[\SensitiveParameter] string $abo, string $expectedPayerAccount): array
    {
        if ($abo === '' || strlen($abo) > 2 * 1024 * 1024 || preg_match('/[^\x0D\x0A\x20-\x7E]/', $abo)) {
            throw $this->invalid();
        }
        $lines = preg_split('/\r\n/', $abo);
        if (!is_array($lines) || array_pop($lines) !== '' || count($lines) < 6
            || !preg_match('/^UHL1\d{6}.{20}\d{10}\d{3}999\d{12}$/D', $lines[0])
            || !preg_match('/^1 1501 \d{6} 0600$/D', $lines[1])
            || !preg_match('/^2 (\d{6})-(\d{10}) (\d{14}) (\d{6})$/D', $lines[2], $group)
            || $lines[count($lines) - 2] !== '3 +' || $lines[count($lines) - 1] !== '5 +'
        ) {
            throw $this->invalid();
        }
        $payer = $this->account($group[1], $group[2]);
        if (!AccountNumberNormalizer::equalsCzech($payer, $expectedPayerAccount)) {
            throw $this->invalid();
        }
        $executionDate = $this->date($group[4]);
        $itemLines = array_slice($lines, 3, -2);
        if ($itemLines === [] || count($itemLines) > MonetaApiClient::MAX_BATCH_PAYMENTS) {
            throw $this->invalid();
        }

        $payments = [];
        $total = 0;
        foreach ($itemLines as $index => $line) {
            if (!preg_match('/^(\d{6})-(\d{10}) (\d{12}) (\d{1,10}) (\d{8}) (\d{10}) AV:(.{0,32})$/D', $line, $item)) {
                throw $this->invalid();
            }
            $minor = (int) $item[3];
            if ($minor < 1) {
                throw $this->invalid();
            }
            $total += $minor;
            $bankCode = substr($item[5], 0, 4);
            $references = array_values(array_filter([
                $this->symbol('VS', $item[4]),
                ltrim(substr($item[5], 4), '0') === '' ? null : 'KS:' . substr($item[5], 4),
                $this->symbol('SS', $item[6]),
            ]));
            $payment = [
                'paymentIdentification' => [
                    'instructionIdentification' => sprintf('MU%03d%s', $index + 1, substr(hash('sha256', $abo . "\n" . $line), 0, 30)),
                ],
                'amount' => ['instructedAmount' => ['value' => BankPaymentAmount::fromMinor($minor), 'currency' => 'CZK']],
                'requestedExecutionDate' => $executionDate,
                'debtorAccount' => ['identification' => ['other' => ['identification' => $payer . '/0600']], 'currency' => 'CZK'],
                'creditorAccount' => ['identification' => ['other' => ['identification' => $this->account($item[1], $item[2]) . '/' . $bankCode]], 'currency' => 'CZK'],
            ];
            $message = trim($item[7]);
            if ($message !== '' || $references !== []) {
                $payment['remittanceInformation'] = [];
                if ($message !== '') {
                    $payment['remittanceInformation']['unstructured'] = $message;
                }
                if ($references !== []) {
                    $payment['remittanceInformation']['structured'] = [
                        'creditorReferenceInformation' => ['reference' => implode(',', $references)],
                    ];
                }
            }
            $payments[] = $payment;
        }
        if ($total !== (int) $group[3]) {
            throw $this->invalid();
        }
        return $payments;
    }

    private function symbol(string $name, string $digits): ?string
    {
        $value = ltrim($digits, '0');
        return $value === '' ? null : $name . ':' . $value;
    }

    private function account(string $prefix, string $number): string
    {
        if (!$this->validCzechPart($prefix) || !$this->validCzechPart($number) || ltrim($number, '0') === '') {
            throw $this->invalid();
        }
        $prefix = ltrim($prefix, '0');
        $number = ltrim($number, '0');
        return $prefix === '' ? $number : $prefix . '-' . $number;
    }

    private function date(string $ddmmyy): string
    {
        $date = \DateTimeImmutable::createFromFormat('!dmy', $ddmmyy);
        if ($date === false || $date->format('dmy') !== $ddmmyy) {
            throw $this->invalid();
        }
        return $date->format('Y-m-d');
    }

    private function validCzechPart(string $part): bool
    {
        if (!preg_match('/^\d{1,10}$/D', $part)) {
            return false;
        }
        $digits = str_pad($part, 10, '0', STR_PAD_LEFT);
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }
        return $sum % 11 === 0;
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'ABO dávku nelze bezpečně převést pro MONETA API.');
    }
}
