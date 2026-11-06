<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\AboDomesticPaymentParser;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\CsasAboBatchMapper;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

final class CsasAboBatchMapperTest extends TestCase
{
    private const PAYER_IBAN = 'CZ7508000000001000000005';

    public function testPlaceholderAccountsConvertToIbanWithMod97(): void
    {
        self::assertSame(self::PAYER_IBAN, AboDomesticPaymentParser::czechIban('0', '1000000005', '0800'));
        self::assertSame('CZ1801000000001000000005', AboDomesticPaymentParser::czechIban('0', '1000000005', '0100'));
    }

    public function testMapsDomesticPaymentToPremiumPaymentsShape(): void
    {
        $batch = (new CsasAboBatchMapper())->map($this->abo([$this->item()]), self::PAYER_IBAN);

        self::assertMatchesRegularExpression('/^MU[a-f0-9]{14}$/D', $batch['exchange_identification']);
        self::assertCount(1, $batch['payments']);
        $payment = $batch['payments'][0];
        self::assertSame(1, $payment['line_no']);
        self::assertSame(123456, $payment['amount_minor']);
        self::assertSame('CZ1801000000001000000005', $payment['creditor_iban']);
        self::assertMatchesRegularExpression('/^MU001[a-f0-9]{30}$/D', $payment['instruction_identification']);
        self::assertLessThanOrEqual(35, strlen($payment['instruction_identification']));
        $body = $payment['body'];
        self::assertSame($payment['instruction_identification'], $body['paymentIdentification']['instructionIdentification']);
        self::assertSame('NORM', $body['paymentTypeInformation']['instructionPriority']);
        self::assertSame(['value' => 1234.56, 'currency' => 'CZK'], $body['amount']['instructedAmount']);
        self::assertSame('2026-11-02', $body['requestedExecutionDate']);
        self::assertSame(self::PAYER_IBAN, $body['debtorAccount']['identification']['iban']);
        self::assertSame('CZ1801000000001000000005', $body['creditorAccount']['identification']['iban']);
        self::assertSame('Synthetic payment', $body['remittanceInformation']['unstructured']);
        self::assertSame(['VS:123456', 'KS:308', 'SS:77'], $body['remittanceInformation']['structured']['creditorReferenceInformation']['reference']);
    }

    public function testZeroSymbolsAreOmitted(): void
    {
        $item = ['account_number' => '1000000005', 'bank_code' => '0100', 'amount_minor' => 100, 'variable_symbol' => '5'];
        $body = (new CsasAboBatchMapper())->map($this->abo([$item]), self::PAYER_IBAN)['payments'][0]['body'];
        self::assertSame(['VS:5'], $body['remittanceInformation']['structured']['creditorReferenceInformation']['reference']);
        self::assertSame('5', $body['remittanceInformation']['unstructured'], 'ABO bez zprávy nese VS (AboPaymentOrderWriter).');
    }

    public function testIdentifiersAreDeterministicAndDifferBetweenOrders(): void
    {
        $mapper = new CsasAboBatchMapper();
        $items = [$this->item(), ['specific_symbol' => '78'] + $this->item()];
        $first = $mapper->map($this->abo($items, '000101'), self::PAYER_IBAN);
        $again = $mapper->map($this->abo($items, '000101'), self::PAYER_IBAN);
        $other = $mapper->map($this->abo($items, '000102'), self::PAYER_IBAN);

        self::assertSame($first, $again, 'Stejné ABO dá po přerušení stejné položky.');
        $ids = array_column($first['payments'], 'instruction_identification');
        self::assertCount(2, array_unique($ids), 'Každý řádek dávky má vlastní identifikátor.');
        self::assertNotSame($ids, array_column($other['payments'], 'instruction_identification'));
        self::assertNotSame($first['exchange_identification'], $other['exchange_identification']);
    }

    public function testAcceptsFourHundredItemsAndRejectsMore(): void
    {
        $mapper = new CsasAboBatchMapper();
        $small = static fn (int $count): array => array_map(static fn (int $i): array =>
            ['account_number' => '1000000005', 'bank_code' => '0100', 'amount_minor' => 1, 'variable_symbol' => (string) $i], range(1, $count));
        self::assertCount(400, $mapper->map($this->abo($small(400)), self::PAYER_IBAN)['payments']);
        try {
            $mapper->map($this->abo($small(401)), self::PAYER_IBAN);
            self::fail('Dávka nad 400 položek se do banky nesmí poslat.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    public function testDuplicateAccountAmountAndSymbolsAreRejectedWithLineNumbersBeforeAnyPayment(): void
    {
        $other = ['account_number' => '19-1000000005', 'bank_code' => '0300', 'amount_minor' => 500, 'variable_symbol' => '9'];
        $items = [$this->item(), $other, ['message' => 'Jiná zpráva'] + $this->item(), $other];
        try {
            (new CsasAboBatchMapper())->map($this->abo($items), self::PAYER_IBAN);
            self::fail('Shodné platby banka v dávce odmítne až po založení; musí se odmítnout předem.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_order_duplicate_items', $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
            self::assertSame(['duplicate_lines' => [[1, 3], [2, 4]]], $e->details);
        }
        $distinct = [$this->item(), ['specific_symbol' => '78'] + $this->item()];
        self::assertCount(2, (new CsasAboBatchMapper())->map($this->abo($distinct), self::PAYER_IBAN)['payments'], 'Jiný symbol není duplicita.');
    }

    public function testRejectsTamperedTotalAndInvalidAccount(): void
    {
        $abo = $this->abo([$this->item()]);
        foreach ([
            'součet' => str_replace('00000000123456', '00000000123457', $abo),
            'mod 11' => str_replace('000000-1000000005 000000123456', '000000-0000000124 000000123456', $abo),
        ] as $label => $tampered) {
            try {
                (new CsasAboBatchMapper())->map($tampered, self::PAYER_IBAN);
                self::fail("Upravené ABO ({$label}) nesmí projít.");
            } catch (BankConnectorException $e) {
                self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode, $label);
            }
        }
    }

    public function testRejectsPayerAccountOtherThanConnectedOne(): void
    {
        try {
            (new CsasAboBatchMapper())->map($this->abo([$this->item()]), 'CZ4108000000000782553098');
            self::fail('Příkaz z jiného účtu nesmí projít.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_order_account_mismatch', $e->errorCode);
        }
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return ['account_number' => '1000000005', 'bank_code' => '0100', 'amount_minor' => 123456, 'variable_symbol' => '123456',
            'constant_symbol' => '0308', 'specific_symbol' => '77', 'message' => 'Synthetic payment'];
    }

    /** @param list<array<string,mixed>> $items */
    private function abo(array $items, string $fileNumber = '000001'): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Client',
            'file_number' => $fileNumber,
            'payer_account_number' => '1000000005',
            'payer_bank_code' => '0800',
            'payment_date' => '2026-11-02',
            'items' => $items,
        ]);
    }
}
