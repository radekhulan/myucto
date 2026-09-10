<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusAboBatchMapper
{
    /** @return array{exchange_identification:string,processing_mode:string,instruction_name:string,payments:list<array<string,mixed>>} */
    public function map(#[\SensitiveParameter] string $abo, string $expectedPayerIban): array
    {
        if ($abo === '' || strlen($abo) > 2 * 1024 * 1024 || preg_match('/[^\x0D\x0A\x20-\x7E]/', $abo)) {
            throw $this->invalid();
        }
        $lines = preg_split('/\r\n/', $abo);
        if (!is_array($lines) || array_pop($lines) !== '' || count($lines) < 6
            || !preg_match('/^UHL1\d{6}.{20}\d{10}000999\d{12}$/D', $lines[0])
            || !preg_match('/^1 1501 \d{6} (\d{4})$/D', $lines[1], $file)
            || !preg_match('/^2 (\d{6})-(\d{10}) (\d{14}) (\d{6})$/D', $lines[2], $group)
            || $lines[count($lines) - 2] !== '3 +' || $lines[count($lines) - 1] !== '5 +'
        ) {
            throw $this->invalid();
        }
        $payerIban = $this->czechIban($group[1], $group[2], $file[1]);
        if (!hash_equals(strtoupper($expectedPayerIban), $payerIban)) {
            throw $this->invalid();
        }
        $executionDate = $this->date($group[4]);
        $itemLines = array_slice($lines, 3, -2);
        if ($itemLines === [] || count($itemLines) > 100) {
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
            $constant = ltrim(substr($item[5], 4), '0');
            $variable = ltrim($item[4], '0');
            $specific = ltrim($item[6], '0');
            $message = trim($item[7]);
            $references = array_values(array_filter([
                $variable === '' ? null : 'VS:' . $variable,
                $constant === '' ? null : 'KS:' . $constant,
                $specific === '' ? null : 'SS:' . $specific,
            ], static fn (?string $value): bool => $value !== null));
            $payment = [
                'PaymentIdentification' => [
                    'instructionIdentification' => sprintf('MU%03d%s', $index + 1, substr(hash('sha256', $line), 0, 20)),
                ],
                'amount' => ['instructedAmount' => ['value' => $minor / 100, 'currency' => 'CZK']],
                'requestedExecutionDate' => $executionDate,
                'debtorAccount' => ['identification' => ['iban' => $payerIban], 'currency' => 'CZK'],
                'creditorAccount' => ['identification' => ['iban' => $this->czechIban($item[1], $item[2], $bankCode)], 'currency' => 'CZK'],
            ];
            if ($message !== '' || $references !== []) {
                $payment['remittanceInformation'] = [];
                if ($message !== '') {
                    $payment['remittanceInformation']['unstructured'] = $message;
                }
                if ($references !== []) {
                    $payment['remittanceInformation']['structured'] = [
                        'creditorReferenceInformation' => ['reference' => $references],
                    ];
                }
            }
            $payments[] = $payment;
        }
        if ($total !== (int) $group[3]) {
            throw $this->invalid();
        }
        return [
            'exchange_identification' => substr(hash('sha256', $abo), 0, 14),
            // KB+ (New Era) zvolený režim ignoruje a dávku zpracuje vždy ONLINE; jiný režim
            // by neprošel kontrolou batchProcessingMode v odpovědi a přijatá dávka by skončila jako nejistá.
            'processing_mode' => 'ONLINE',
            'instruction_name' => 'MyUcto ' . $executionDate,
            'payments' => $payments,
        ];
    }

    private function date(string $ddmmyy): string
    {
        $date = \DateTimeImmutable::createFromFormat('!dmy', $ddmmyy);
        if ($date === false || $date->format('dmy') !== $ddmmyy) {
            throw $this->invalid();
        }
        return $date->format('Y-m-d');
    }

    private function czechIban(string $prefix, string $number, string $bankCode): string
    {
        if (!preg_match('/^\d{4}$/D', $bankCode)
            || !$this->validCzechPart($prefix)
            || !$this->validCzechPart($number)
        ) {
            throw $this->invalid();
        }
        $bban = $bankCode . str_pad(ltrim($prefix, '0'), 6, '0', STR_PAD_LEFT)
            . str_pad(ltrim($number, '0'), 10, '0', STR_PAD_LEFT);
        $remainder = 0;
        foreach (str_split($bban . '123500') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        return 'CZ' . str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT) . $bban;
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
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'ABO dávku nelze bezpečně převést pro KB+.');
    }
}
