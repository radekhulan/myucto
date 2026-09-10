<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusAboBatchMapper;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

final class KbPlusAboBatchMapperTest extends TestCase
{
    public function testMapsExistingAboSnapshotToDocumentedKbBatchShape(): void
    {
        $batch = (new KbPlusAboBatchMapper())->map($this->abo(), 'CZ0401000000191000000005');

        self::assertSame('ONLINE', $batch['processing_mode']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{14}$/', $batch['exchange_identification']);
        self::assertCount(1, $batch['payments']);
        $payment = $batch['payments'][0];
        self::assertSame(1234.56, $payment['amount']['instructedAmount']['value']);
        self::assertSame('CZK', $payment['amount']['instructedAmount']['currency']);
        self::assertSame('2026-09-08', $payment['requestedExecutionDate']);
        self::assertSame('CZ0401000000191000000005', $payment['debtorAccount']['identification']['iban']);
        self::assertSame('CZ6108000000191000000005', $payment['creditorAccount']['identification']['iban']);
        self::assertSame('Synthetic payment', $payment['remittanceInformation']['unstructured']);
        self::assertSame(
            ['VS:123456', 'KS:308', 'SS:77'],
            $payment['remittanceInformation']['structured']['creditorReferenceInformation']['reference'],
        );
    }

    public function testRejectsDifferentPayerBeforeBankRequest(): void
    {
        $this->expectException(BankConnectorException::class);
        $this->expectExceptionMessage('ABO dávku nelze bezpečně převést');
        (new KbPlusAboBatchMapper())->map($this->abo(), 'CZ6501000000001000000005');
    }

    public function testRejectsTamperedAboTotal(): void
    {
        $abo = str_replace('00000000123456', '00000000123457', $this->abo());
        try {
            (new KbPlusAboBatchMapper())->map($abo, 'CZ0401000000191000000005');
            self::fail('Nesouhlasící kontrolní součet ABO nesmí projít.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_PAYMENT_ORDER, $e->errorCode);
        }
    }

    public function testRejectsNationalAccountThatFailsCzechModuloEleven(): void
    {
        $abo = str_replace('000019-1000000005 000000123456', '000000-0000000124 000000123456', $this->abo());
        $this->expectException(BankConnectorException::class);
        (new KbPlusAboBatchMapper())->map($abo, 'CZ0401000000191000000005');
    }

    private function abo(): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Client',
            'payer_account_number' => '19-1000000005',
            'payer_bank_code' => '0100',
            'payment_date' => '2026-09-08',
            'items' => [[
                'account_number' => '19-1000000005',
                'bank_code' => '0800',
                'amount_minor' => 123456,
                'variable_symbol' => '123456',
                'constant_symbol' => '0308',
                'specific_symbol' => '77',
                'message' => 'Synthetic payment',
            ]],
        ]);
    }
}
