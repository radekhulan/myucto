<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusAboBatchMapper
{
    public function __construct(private readonly AboDomesticPaymentParser $parser = new AboDomesticPaymentParser()) {}

    /** @return array{exchange_identification:string,processing_mode:string,instruction_name:string,payments:list<array<string,mixed>>} */
    public function map(#[\SensitiveParameter] string $abo, string $expectedPayerIban): array
    {
        try {
            $batch = $this->parser->parse($abo, 100);
        } catch (BankConnectorException) {
            throw $this->invalid();
        }
        if (!hash_equals(strtoupper($expectedPayerIban), $batch['payer_iban'])) {
            throw $this->invalid();
        }
        $payments = [];
        foreach ($batch['items'] as $item) {
            $references = AboDomesticPaymentParser::references($item);
            $payment = [
                'paymentIdentification' => [
                    'instructionIdentification' => sprintf('MU%03d%s', $item['line_no'], substr(hash('sha256', $item['line']), 0, 20)),
                ],
                'amount' => ['instructedAmount' => ['value' => $item['amount_minor'] / 100, 'currency' => 'CZK']],
                'requestedExecutionDate' => $batch['execution_date'],
                'debtorAccount' => ['identification' => ['iban' => $batch['payer_iban']], 'currency' => 'CZK'],
                'creditorAccount' => ['identification' => ['iban' => $item['creditor_iban']], 'currency' => 'CZK'],
            ];
            if ($item['message'] !== '' || $references !== []) {
                $payment['remittanceInformation'] = [];
                if ($item['message'] !== '') {
                    $payment['remittanceInformation']['unstructured'] = $item['message'];
                }
                if ($references !== []) {
                    $payment['remittanceInformation']['structured'] = [
                        'creditorReferenceInformation' => ['reference' => $references],
                    ];
                }
            }
            $payments[] = $payment;
        }
        return [
            'exchange_identification' => substr(hash('sha256', $abo), 0, 14),
            // KB+ (New Era) zvolený režim ignoruje a dávku zpracuje vždy ONLINE; jiný režim
            // by neprošel kontrolou batchProcessingMode v odpovědi a přijatá dávka by skončila jako nejistá.
            'processing_mode' => 'ONLINE',
            'instruction_name' => 'MyUcto ' . $batch['execution_date'],
            'payments' => $payments,
        ];
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'ABO dávku nelze bezpečně převést pro KB+.');
    }
}
