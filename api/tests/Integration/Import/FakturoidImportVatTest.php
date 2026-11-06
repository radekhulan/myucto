<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use PHPUnit\Framework\Attributes\Group;

/**
 * Import z Fakturoidu u dokladů s DPH (#128 až #131): ceny včetně DPH, stav úlohy
 * s chybami, dry-run se stejnými kontrolami jako ostrý import a převzetí
 * zaokrouhlení i rekapitulace DPH přijatého dokladu.
 */
#[Group('integration')]
final class FakturoidImportVatTest extends FakturoidImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(760001), $this->subject(760002, 'supplier')];
    }

    /** #128: cena 1 210 Kč včetně 21 % je základ 1 000 a DPH 210, ne 1 210 + 254,10. */
    public function testPricesIncludingVatAreKeptForIssuedAndReceived(): void
    {
        $gross = [
            'vat_price_mode' => 'from_total_with_vat',
            'vat_rates_summary' => [['vat_rate' => 21, 'base' => '1000.0', 'vat' => '210.0']],
            'subtotal' => '1000.0',
            'total' => '1210.0',
        ];
        $line = [['name' => 'Licence', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => '1210.0', 'vat_rate' => 21]];
        $this->api['invoices.json'] = [$this->document(770001, 760001, $line, $gross)];
        $this->api['expenses.json'] = [$this->document(780001, 760002, $line, $gross)];

        $job = $this->runImport();

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $issued = $this->issued(770001);
        self::assertSame(1, (int) $issued['header']['prices_include_vat']);
        self::assertSame(['1000.00', '210.00', '1210.00'], [
            $issued['header']['total_without_vat'], $issued['header']['total_vat'], $issued['header']['total_with_vat'],
        ]);
        $received = $this->received(780001);
        self::assertSame(1, (int) $received['header']['prices_include_vat']);
        self::assertSame(['1000.00', '210.00', '1210.00'], [
            $received['header']['total_without_vat'], $received['header']['total_vat'], $received['header']['total_with_vat'],
        ]);
        self::assertNull($received['header']['extraction_warning']);
    }

    /** #129: odmítnutý doklad udělá z úlohy „dokončeno s chybami" a je v přehledu s důvodem. */
    public function testRejectedDocumentMakesJobCompletedWithWarnings(): void
    {
        $this->api['expenses.json'] = [
            $this->document(780011, 760002, [['name' => 'Zboží', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 23]], ['vat_price_mode' => 'without_vat']),
            $this->document(780012, 760002, [['name' => 'Služba', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 21]], ['vat_price_mode' => 'without_vat']),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed_with_warnings', $job['status'], (string) $job['log_text']);
        self::assertSame(1, $job['failed_count']);
        $report = $job['report'];
        self::assertIsArray($report);
        self::assertSame(1, $report['agendas']['received']['created']);
        self::assertSame(1, $report['agendas']['received']['failed']);
        self::assertCount(1, $report['problems']);
        self::assertSame('received', $report['problems'][0]['agenda']);
        self::assertSame(780011, $report['problems'][0]['fakturoid_id']);
        self::assertSame('error', $report['problems'][0]['severity']);
        self::assertSame('vat_rate', $report['problems'][0]['hint']);
        self::assertStringContainsString('23', $report['problems'][0]['reason']);
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ? AND fakturoid_id = 780011');
        $count->execute([$this->sid]);
        self::assertSame(0, (int) $count->fetchColumn(), 'Nepodporovaná sazba se nesmí nahradit jinou.');
    }

    /** #130: dry-run ověří sazby stejně jako ostrý import a nic nezapíše. */
    public function testDryRunValidatesVatRates(): void
    {
        $this->api['invoices.json'] = [
            $this->document(770021, 760001, [['name' => 'Služba', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 23]], ['vat_price_mode' => 'without_vat']),
        ];
        $this->api['expenses.json'] = [
            $this->document(780021, 760002, [['name' => 'Zboží', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 23]], ['vat_price_mode' => 'without_vat']),
            $this->document(780022, 760002, [['name' => 'Služba', 'quantity' => 1, 'unit_price' => '1210.0', 'vat_rate' => 21]], [
                'vat_price_mode' => 'from_total_with_vat',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '1000.0', 'vat' => '210.0']],
            ]),
        ];

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame('completed_with_warnings', $job['status'], (string) $job['log_text']);
        self::assertSame(2, $job['failed_count']);
        self::assertTrue($job['report']['dry_run']);
        self::assertSame(1, $job['report']['agendas']['issued']['failed']);
        self::assertSame(1, $job['report']['agendas']['received']['failed']);
        self::assertSame(1, $job['report']['agendas']['received']['created']);
        self::assertSame([770021, 780021], array_column($job['report']['problems'], 'fakturoid_id'));
        $count = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM invoices WHERE supplier_id = ?) + (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?)'
        );
        $count->execute([$this->sid, $this->sid]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /** #131: zaokrouhlení i rekapitulace DPH přijatého dokladu sedí na zdroj. */
    public function testReceivedKeepsRoundingAndSourceVatSummary(): void
    {
        $this->api['expenses.json'] = [
            $this->document(780031, 760002, [
                ['name' => 'Položka A', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
                ['name' => 'Položka B', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
            ], [
                'vat_price_mode' => 'without_vat',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '20.26', 'vat' => '4.25']],
                'subtotal' => '20.26',
                'total' => '24.5',
                'rounding_adjustment' => '-0.01',
            ]),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $h = $this->received(780031)['header'];
        self::assertSame(['20.26', '4.25', '24.51', '-0.01'], [$h['total_without_vat'], $h['total_vat'], $h['total_with_vat'], $h['rounding']]);
        self::assertNotNull($h['vat_overrides']);
        self::assertNull($h['extraction_warning']);
    }

    /** #131: rozdíl, který nejde věrně převzít, doklad označí ke kontrole a nic nepřepisuje. */
    public function testReceivedWithUnexplainedDifferenceIsMarkedForReview(): void
    {
        $this->api['expenses.json'] = [
            $this->document(780041, 760002, [['name' => 'Zboží', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 21]], [
                'vat_price_mode' => 'without_vat',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '900.0', 'vat' => '189.0']],
            ]),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed_with_warnings', $job['status'], (string) $job['log_text']);
        self::assertSame(0, $job['failed_count']);
        self::assertSame(1, $job['report']['agendas']['received']['review']);
        self::assertSame('review', $job['report']['problems'][0]['severity']);
        $h = $this->received(780041)['header'];
        self::assertSame(['1000.00', '210.00'], [$h['total_without_vat'], $h['total_vat']]);
        self::assertNull($h['vat_overrides']);
        self::assertNotNull($h['extraction_warning']);
    }

    /** Vydaný doklad nemá ruční rekapitulaci DPH — rozdíl proti zdroji jen nahlásí ke kontrole. */
    public function testIssuedDifferenceIsReportedForReview(): void
    {
        $this->api['invoices.json'] = [
            $this->document(770031, 760001, [
                ['name' => 'Položka A', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
                ['name' => 'Položka B', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
            ], [
                'vat_price_mode' => 'without_vat',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '20.26', 'vat' => '4.25']],
            ]),
        ];

        $job = $this->runImport(['include_received' => false]);

        self::assertSame('completed_with_warnings', $job['status'], (string) $job['log_text']);
        self::assertSame(1, $job['report']['agendas']['issued']['created']);
        self::assertSame(1, $job['report']['agendas']['issued']['review']);
        self::assertSame(770031, $job['report']['problems'][0]['fakturoid_id']);
    }

    /** #229: poměrný odpočet DPH z Fakturoidu se převezme do režimu odpočtu dokladu. */
    public function testReceivedTakesOverProportionalVatDeduction(): void
    {
        $line = [['name' => 'Pohonné hmoty', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 21]];
        $this->api['expenses.json'] = [
            $this->document(780061, 760002, $line, ['vat_price_mode' => 'without_vat', 'proportional_vat_deduction' => 80]),
            $this->document(780062, 760002, $line, ['vat_price_mode' => 'without_vat', 'proportional_vat_deduction' => 100]),
            $this->document(780063, 760002, $line, ['vat_price_mode' => 'without_vat', 'proportional_vat_deduction' => 0]),
            $this->document(780064, 760002, $line, ['vat_price_mode' => 'without_vat']),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_id, vat_deduction, vat_deduction_percent, total_vat
               FROM purchase_invoices WHERE supplier_id = ? ORDER BY fakturoid_id'
        );
        $stmt->execute([$this->sid]);
        self::assertSame([
            ['780061', 'proportional', '80.00', '210.00'],
            ['780062', 'full', '100.00', '210.00'],
            ['780063', 'none', '0.00', '210.00'],
            ['780064', 'full', '100.00', '210.00'],
        ], array_map(static fn (array $r) => array_map('strval', array_values($r)), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /** Daňová uznatelnost nákladu (Fakturoid `tax_deductible`, výchozí true) se převezme. */
    public function testReceivedTakesOverTaxDeductibility(): void
    {
        $line = [['name' => 'Reprezentace', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 21]];
        $this->api['expenses.json'] = [
            $this->document(780071, 760002, $line, ['vat_price_mode' => 'without_vat', 'tax_deductible' => false]),
            $this->document(780072, 760002, $line, ['vat_price_mode' => 'without_vat', 'tax_deductible' => true]),
            $this->document(780073, 760002, $line, ['vat_price_mode' => 'without_vat']),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT fakturoid_id, tax_deductible FROM purchase_invoices WHERE supplier_id = ? ORDER BY fakturoid_id'
        );
        $stmt->execute([$this->sid]);
        self::assertSame([
            ['780071', '0'],
            ['780072', '1'],
            ['780073', '1'],
        ], array_map(static fn (array $r) => array_map('strval', array_values($r)), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * Vědomá výjimka z otisku bez DPH (#131): `rounding_adjustment` se převezme i u
     * dokladu neplátce. Mění se jen zaokrouhlení, DPH ani sazba nevznikne.
     */
    public function testNonVatReceivedTakesOverRoundingOnly(): void
    {
        $this->api['expenses.json'] = [
            $this->document(780051, 760002, [['name' => 'Materiál', 'quantity' => 1, 'unit_price' => '349.4', 'vat_rate' => 0]], [
                'vat_price_mode' => 'without_vat',
                'vat_rates_summary' => [['vat_rate' => 0, 'base' => '349.4', 'vat' => '0.0']],
                'subtotal' => '349.4',
                'total' => '350.0',
                'rounding_adjustment' => '0.6',
            ]),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status'], (string) $job['log_text']);
        $r = $this->received(780051);
        self::assertSame([
            'document_kind' => 'invoice', 'prices_include_vat' => '0',
            'total_without_vat' => '349.40', 'total_vat' => '0.00', 'total_with_vat' => '349.40', 'rounding' => '0.60',
            'vat_overrides' => null, 'extraction_warning' => null,
        ], array_map(static fn ($v) => $v === null ? null : (string) $v, $r['header']));
        self::assertSame('0.00', $r['items'][0]['vat_rate_snapshot']);
        self::assertSame('0.00', $r['items'][0]['total_vat']);
    }
}
