<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraDocumentMapper;
use MyInvoice\Service\Migration\Abra\AbraOtherItemWriter;
use MyInvoice\Service\Migration\Abra\AbraPaymentMapper;
use MyInvoice\Service\Migration\Abra\AbraSource;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PHPUnit\Framework\TestCase;

final class AbraOtherItemRouteTest extends TestCase
{
    /** @param array<string,mixed> $row */
    private static function plan(string $evidence, array $row = []): array
    {
        return (new AbraDocumentMapper())->map(array_replace([
            'id' => '1', 'kod' => 'SYN-1', 'datVyst' => '2025-06-01', 'datUcto' => '2025-06-01', 'datSplat' => '2025-06-15',
            'mena' => 'code:CZK', 'sumZklCelkem' => '1000.00', 'sumDphCelkem' => '0.00', 'sumCelkem' => '1000.00',
            'nazFirmy' => 'Synthetic partner', 'primUcet' => 'code:325000', 'protiUcet' => 'code:518000',
            'popis' => 'Synthetic', 'typDokl' => 'code:OST',
        ], $row), $evidence);
    }

    public function testPayableAndReceivableWithoutVatBecomeOtherItems(): void
    {
        self::assertSame(AbraOtherItemWriter::ROUTE_OTHER_ITEM, AbraOtherItemWriter::route(self::plan('zavazek')));
        $receivable = self::plan('pohledavka', ['primUcet' => 'code:378000']);
        self::assertSame(AbraOtherItemWriter::ROUTE_OTHER_ITEM, AbraOtherItemWriter::route($receivable));
        self::assertSame('receivable', AbraOtherItemWriter::side($receivable));
        self::assertSame(AbraOtherItemWriter::ROUTE_DOCUMENT, AbraOtherItemWriter::route(self::plan('faktura-prijata')));
    }

    public function testPayrollAndTaxAccountsStayInJournal(): void
    {
        foreach (['331000', '336100', '341000', '342000', '343900'] as $account) {
            self::assertSame(AbraOtherItemWriter::ROUTE_MODULE,
                AbraOtherItemWriter::route(self::plan('zavazek', ['primUcet' => 'code:' . $account])), $account);
        }
        self::assertTrue(MigratedOtherItemPolicy::moduleOwned('343.100'));
        self::assertFalse(MigratedOtherItemPolicy::moduleOwned('333001'));
    }

    public function testVatForeignCurrencyAndCancellationAreNotOtherItems(): void
    {
        $taxed = ['sumZklCelkem' => '1000.00', 'sumDphCelkem' => '210.00', 'sumCelkem' => '1210.00'];
        self::assertSame(AbraOtherItemWriter::ROUTE_DOCUMENT, AbraOtherItemWriter::route(self::plan('zavazek', $taxed)));
        self::assertSame(AbraOtherItemWriter::ROUTE_REVIEW, AbraOtherItemWriter::route(self::plan('pohledavka', $taxed)));
        self::assertSame(AbraOtherItemWriter::ROUTE_REVIEW, AbraOtherItemWriter::route(self::plan('zavazek', ['storno' => true])));
        self::assertSame(AbraOtherItemWriter::ROUTE_REVIEW, AbraOtherItemWriter::route(self::plan('zavazek', [
            'mena' => 'code:EUR', 'kurz' => '25', 'sumCelkemMen' => '40', 'sumZklCelkemMen' => '40', 'sumDphCelkemMen' => '0',
        ])));
    }

    public function testOtherItemMapKindDiffersFromInvoiceKind(): void
    {
        self::assertSame('ostatni-zavazek', AbraSource::otherItemMapKind('zavazek'));
        self::assertSame('zavazek', AbraSource::evidenceOfMapKind('ostatni-zavazek'));
        self::assertSame('faktura-prijata', AbraSource::evidenceOfMapKind('faktura-prijata'));
    }

    public function testLoanAccountsAreRecognizedFromSourceAccount(): void
    {
        foreach (['code:461002', 'code:231000', '232100'] as $account) {
            self::assertTrue(AbraSource::isLoanAccount($account), $account);
        }
        foreach (['code:221001', 'code:379110', ''] as $account) {
            self::assertFalse(AbraSource::isLoanAccount($account), $account);
        }
        $lookup = AbraPaymentMapper::bankAccountLookup([
            ['id' => '1', 'kod' => 'KB', 'buc' => '1000000005/0100', 'mena' => 'code:CZK', 'primUcet' => 'code:221001'],
            ['id' => '2', 'kod' => 'UVER', 'buc' => '1000000013/0100', 'mena' => 'code:CZK', 'primUcet' => 'code:461002'],
        ]);
        self::assertFalse($lookup['id:1']['loan']);
        self::assertTrue($lookup['id:2']['loan']);
        $movement = (new AbraPaymentMapper())->mapMovement(['id' => '5', 'datUcto' => '2025-06-01', 'mena' => 'code:CZK',
            'sumCelkem' => '10.00', 'banka' => ['id' => '2', 'evidencePath' => 'bankovni-ucet/2']], 'banka', $lookup);
        self::assertTrue($movement['loan_account']);
    }
}
