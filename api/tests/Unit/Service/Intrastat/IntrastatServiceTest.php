<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Intrastat;

use MyInvoice\Repository\IntrastatDataSource;
use MyInvoice\Service\Intrastat\IntrastatInputException;
use MyInvoice\Service\Intrastat\IntrastatService;
use PHPUnit\Framework\TestCase;

final class IntrastatServiceTest extends TestCase
{
    public function testBuildsDispatchRowAndProratesInvoiceValueInCzk(): void
    {
        $service = new IntrastatService($this->source([$this->row()]));

        $result = $service->prepare(7, [
            'period' => '2026-09',
            'direction' => 'dispatch',
            'transaction_code' => '11',
            'transport_mode' => '3',
            'delivery_terms' => 'K',
            'record_type' => 'ST',
            'statistical_code' => '50',
        ]);

        self::assertSame(1, $result['preview']['summary']['row_count']);
        self::assertSame(0, $result['preview']['summary']['error_count']);
        self::assertSame([
            'id' => 17,
            'number' => 'VYD-2026-0017',
            'date' => '2026-09-10',
            'line_id' => 1,
            'type' => 'issue',
        ], $result['preview']['rows'][0]['source_document']);
        self::assertSame('0.326', $result['preview']['rows'][0]['net_mass_kg']);
        self::assertSame('3', $result['preview']['rows'][0]['supplementary_quantity']);
        self::assertSame(1250, $result['preview']['rows'][0]['invoiced_value']);
        self::assertSame('0.326', $result['preview']['summary']['total_net_mass_kg']);
        self::assertSame(1250, $result['preview']['summary']['total_invoiced_value']);
        self::assertCount(20, $result['csv_rows'][0]);
        self::assertSame([
            '9', '2026', 'CZ12345678', 'D', 'SK2020123456', 'SK', '', 'DE', '11', '3', 'K', 'ST',
            '84137021', '50', 'Průmyslové čerpadlo', '0.326', '3', '1250', '', '',
        ], $result['csv_rows'][0]);
    }

    public function testArrivalLeavesPartnerVatIdEmpty(): void
    {
        $row = $this->row();
        $row['doc_type'] = 'receipt';
        $row['invoice_item_id'] = null;
        $row['purchase_invoice_item_id'] = 91;
        $service = new IntrastatService($this->source([$row]));

        $result = $service->prepare(7, ['period' => '2026-09', 'direction' => 'arrival']);

        self::assertSame('A', $result['csv_rows'][0][3]);
        self::assertSame('', $result['csv_rows'][0][4]);
    }

    public function testUsesOfficialPlaceholderForConsumerWithoutVatIdOnTransactionTwelve(): void
    {
        $row = $this->row();
        $row['partner_snapshot'] = json_encode([
            'company_name' => 'Konečný spotřebitel',
            'country_iso2' => 'SK',
        ]);
        $row['partner_vat_id'] = null;
        $service = new IntrastatService($this->source([$row]));

        $result = $service->prepare(7, [
            'period' => '2026-09',
            'direction' => 'dispatch',
            'transaction_code' => '12',
        ]);

        self::assertSame(0, $result['preview']['summary']['error_count']);
        self::assertSame('QV123', $result['preview']['rows'][0]['partner_vat_id']);
        self::assertSame('QV123', $result['csv_rows'][0][4]);
    }

    public function testExplicitNullVatIdInSnapshotDoesNotFallBackToCurrentPartner(): void
    {
        $row = $this->row();
        $row['partner_snapshot'] = json_encode([
            'company_name' => 'Historický spotřebitel',
            'country_iso2' => 'SK',
            'dic' => null,
        ]);
        $row['partner_vat_id'] = 'SK2020123456';

        $consumer = (new IntrastatService($this->source([$row])))->prepare(7, [
            'period' => '2026-09',
            'direction' => 'dispatch',
            'transaction_code' => '12',
        ]);
        self::assertSame('QV123', $consumer['preview']['rows'][0]['partner_vat_id']);
        self::assertSame(0, $consumer['preview']['summary']['error_count']);

        $business = (new IntrastatService($this->source([$row])))->prepare(7, [
            'period' => '2026-09',
            'direction' => 'dispatch',
            'transaction_code' => '11',
        ]);
        self::assertSame('', $business['preview']['rows'][0]['partner_vat_id']);
        self::assertContains(
            'partner_vat_id_missing',
            array_column($business['preview']['rows'][0]['issues'], 'code'),
        );

        $row['partner_snapshot'] = json_encode([
            'company_name' => 'Partner bez historického pole DIČ',
            'country_iso2' => 'SK',
        ]);
        $fallback = (new IntrastatService($this->source([$row])))->prepare(7, [
            'period' => '2026-09',
            'direction' => 'dispatch',
            'transaction_code' => '11',
        ]);
        self::assertSame('SK2020123456', $fallback['preview']['rows'][0]['partner_vat_id']);
        self::assertNotContains(
            'partner_vat_id_missing',
            array_column($fallback['preview']['rows'][0]['issues'], 'code'),
        );
    }

    public function testSkipsDomesticAndNonEuMovements(): void
    {
        $domestic = $this->row();
        $domestic['partner_snapshot'] = json_encode(['country_iso2' => 'CZ', 'dic' => 'CZ87654321']);
        $outsideEu = $this->row();
        $outsideEu['source_line_id'] = 2;
        $outsideEu['partner_snapshot'] = json_encode(['country_iso2' => 'US', 'dic' => 'US123']);

        $result = (new IntrastatService($this->source([$domestic, $outsideEu])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

        self::assertSame(0, $result['preview']['summary']['row_count']);
        self::assertContains('no_rows', array_column($result['preview']['issues'], 'code'));
    }

    public function testReportsMissingCardFieldsAndForeignExchangeRate(): void
    {
        $row = $this->row();
        $row['intrastat_cn8_code'] = null;
        $row['intrastat_country_of_origin'] = null;
        $row['intrastat_net_mass_kg'] = null;
        $row['exchange_rate'] = null;
        $row['partner_snapshot'] = json_encode(['country_iso2' => 'SK']);
        $row['partner_vat_id'] = null;

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);
        $codes = array_column($result['preview']['rows'][0]['issues'], 'code');

        self::assertGreaterThanOrEqual(5, $result['preview']['summary']['error_count']);
        self::assertContains('cn8_missing', $codes);
        self::assertContains('country_of_origin_missing', $codes);
        self::assertContains('net_mass_missing', $codes);
        self::assertContains('exchange_rate_missing', $codes);
        self::assertContains('partner_vat_id_missing', $codes);
    }

    public function testRejectsPeriodsBeforeInstatEvoCsvContract(): void
    {
        $this->expectException(IntrastatInputException::class);
        (new IntrastatService($this->source([])))
            ->prepare(7, ['period' => '2025-12', 'direction' => 'dispatch']);
    }

    public function testUsesOfficialConstantsForSpecialCommodityAndZzzUnit(): void
    {
        $row = $this->row();
        $row['intrastat_cn8_code'] = '27160000';
        $row['intrastat_net_mass_kg'] = null;
        $row['intrastat_supplementary_unit'] = 'ZZZ';
        $row['intrastat_supplementary_unit_coefficient'] = null;

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

        self::assertSame(0, $result['preview']['summary']['error_count']);
        self::assertSame('0.001', $result['csv_rows'][0][15]);
        self::assertSame('0', $result['csv_rows'][0][16]);
    }

    public function testRejectsCodesOutsidePublishedCodebooks(): void
    {
        foreach ([
            ['transaction_code' => '13'],
            ['transport_mode' => '6'],
            ['delivery_terms' => 'X'],
            ['record_type' => 'XX'],
        ] as $invalid) {
            try {
                (new IntrastatService($this->source([])))->prepare(7, [
                    'period' => '2026-09',
                    'direction' => 'dispatch',
                    ...$invalid,
                ]);
                self::fail('Neplatný číselníkový kód nebyl odmítnut.');
            } catch (IntrastatInputException $e) {
                self::assertStringEndsWith('_invalid', $e->issues[0]['code']);
            }
        }
    }

    public function testReportsCsvRangeAndPartnerVatPrefixErrors(): void
    {
        $row = $this->row();
        $row['intrastat_net_mass_kg'] = '500000000';
        $row['invoice_item_value'] = '100000000000000';
        $row['partner_snapshot'] = json_encode([
            'company_name' => 'Partner s.r.o.',
            'country_iso2' => 'SK',
            'dic' => '2020123456',
        ]);

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);
        $codes = array_column($result['preview']['rows'][0]['issues'], 'code');

        self::assertContains('net_mass_out_of_range', $codes);
        self::assertContains('invoiced_value_out_of_range', $codes);
        self::assertContains('partner_vat_id_missing', $codes);
    }

    public function testAllocatesOnlyRecognizedGoodsAndRoundsInvoicedValueUp(): void
    {
        $row = $this->row();
        $row['invoice_total_value'] = '120.00';
        $row['invoice_goods_value'] = '100.00';

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);
        self::assertSame(1250, $result['preview']['rows'][0]['invoiced_value']);

        $row = $this->row();
        $row['qty'] = '1';
        $row['invoice_item_quantity'] = '1';
        $row['invoice_item_value'] = '100.01';
        $row['currency_code'] = 'CZK';
        $row['exchange_rate'] = '1';
        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

        self::assertSame(101, $result['preview']['rows'][0]['invoiced_value']);
    }

    public function testAllocatesSourceCzkValueForImportedForeignInvoice(): void
    {
        $row = $this->row();
        $row['invoice_item_source_czk'] = '2500.02';

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

        self::assertSame(1251, $result['preview']['rows'][0]['invoiced_value']);
    }

    public function testUnmappedInvoiceValueIsNotGuessedAndBlocksExport(): void
    {
        $row = $this->row();
        $row['qty'] = '1.000';
        $row['invoice_item_quantity'] = '1.000';
        $row['invoice_item_value'] = '100.00';
        $row['invoice_goods_value'] = '100.00';
        $row['invoice_total_value'] = '70.00';
        $row['invoice_unmapped_value'] = '20.00';
        $row['currency_code'] = 'CZK';
        $row['exchange_rate'] = '1';

        $result = (new IntrastatService($this->source([$row])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

        self::assertSame(100, $result['preview']['rows'][0]['invoiced_value']);
        self::assertContains(
            'invoice_unmapped_value_present',
            array_column($result['preview']['rows'][0]['issues'], 'code'),
        );
        self::assertGreaterThan(0, $result['preview']['summary']['error_count']);
    }

    public function testProratedValueKeepsEveryPositiveRemainderBeforeCeiling(): void
    {
        foreach ([
            ['item_value' => '1.01', 'qty' => '1', 'item_qty' => '1'],
            ['item_value' => '1000.01', 'qty' => '0.001', 'item_qty' => '1'],
        ] as $case) {
            $row = $this->row();
            $row['invoice_item_value'] = $case['item_value'];
            $row['invoice_goods_value'] = '3000000.00';
            $row['qty'] = $case['qty'];
            $row['invoice_item_quantity'] = $case['item_qty'];
            $row['currency_code'] = 'CZK';
            $row['exchange_rate'] = '1';

            $result = (new IntrastatService($this->source([$row])))
                ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);

            self::assertSame(2, $result['preview']['rows'][0]['invoiced_value'], json_encode($case));
        }
    }

    public function testReportsMissingMovementCountryAndIncludesNorthernIreland(): void
    {
        $missing = $this->row();
        $missing['partner_snapshot'] = json_encode(['dic' => 'QV123']);
        $missing['partner_country_iso2'] = null;

        $result = (new IntrastatService($this->source([$missing])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);
        self::assertSame(1, $result['preview']['summary']['row_count']);
        self::assertContains(
            'movement_country_missing',
            array_column($result['preview']['rows'][0]['issues'], 'code'),
        );

        $northernIreland = $this->row();
        $northernIreland['partner_snapshot'] = json_encode(['country_iso2' => 'XI', 'dic' => 'XI123']);
        $result = (new IntrastatService($this->source([$northernIreland])))
            ->prepare(7, ['period' => '2026-09', 'direction' => 'dispatch']);
        self::assertSame(1, $result['preview']['summary']['row_count']);
        self::assertSame('XI', $result['csv_rows'][0][5]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function source(array $rows): IntrastatDataSource
    {
        return new class ($rows) implements IntrastatDataSource {
            public function __construct(private readonly array $rows) {}
            public function declarant(int $supplierId): ?array
            {
                return ['vat_id' => 'CZ12345678', 'country_iso2' => 'CZ'];
            }
            public function euCountryCodes(): array
            {
                return ['CZ', 'DE', 'SK'];
            }
            public function movementRows(int $supplierId, string $from, string $toExclusive, string $direction): array
            {
                return $this->rows;
            }
        };
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return [
            'document_id' => 17,
            'doc_number' => 'VYD-2026-0017',
            'doc_date' => '2026-09-10',
            'doc_type' => 'issue',
            'origin' => 'invoice',
            'source_line_id' => 1,
            'qty' => '2.000',
            'invoice_item_id' => 71,
            'purchase_invoice_item_id' => null,
            'sku' => 'CER-01',
            'stock_item_name' => 'Průmyslové čerpadlo',
            'unit' => 'ks',
            'intrastat_cn8_code' => '84137021',
            'intrastat_country_of_origin' => 'DE',
            'intrastat_net_mass_kg' => '0.163',
            'intrastat_supplementary_unit' => 'PCE',
            'intrastat_supplementary_unit_coefficient' => '1.5',
            'item_description' => 'Průmyslové čerpadlo',
            'invoice_item_quantity' => '4.000',
            'invoice_item_value' => '100.00',
            'exchange_rate' => '25.000000',
            'currency_code' => 'EUR',
            'partner_snapshot' => json_encode(['company_name' => 'Partner s.r.o.', 'country_iso2' => 'SK', 'dic' => 'SK2020123456']),
            'partner_name' => 'Partner s.r.o.',
            'partner_vat_id' => 'SK2020123456',
            'partner_country_iso2' => 'SK',
        ];
    }
}
