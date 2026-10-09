<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\TaxEvidence\CashJournalDimensionService;

/**
 * Dimenze v daňové evidenci (P3 parity): výsledovka po dimenzi a roční statistika
 * stojí na peněžním deníku, hodnotu nese doklad pohybu a chybějící hodnotu doplní
 * pravidlo dimenzí. Součet sestavy sedí na rozdíl příjmů a výdajů deníku.
 */
final class CashJournalDimensionTest extends CashJournalTestCase
{
    private int $typeId = 0;
    private int $valueA = 0;
    private int $valueB = 0;
    private int $unassignedPi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'ZAK', 'Zakázka', 'project')")
            ->execute([$this->supplierId]);
        $this->typeId = (int) $pdo->lastInsertId();
        $this->valueA = $this->value('A', 'Zakázka A');
        $this->valueB = $this->value('B', 'Zakázka B');
        $this->setVatPayer($this->supplierId, false);

        // Příjem 10 000 z faktury s hodnotou A (ruční úhrada, noha C1).
        $invoice = $this->saleInvoice($this->supplierId, ['without' => 10000.0, 'status' => 'paid']);
        $this->header('invoice', $invoice, $this->valueA);
        $this->invoicePayment($this->supplierId, $invoice, 10000.0, 'manual');

        // Výdaj 4 000 z přijaté faktury s rozpadem A/B napůl (ručně zaplacená, noha C2).
        $split = $this->purchaseInvoice($this->supplierId, ['without' => 4000.0, 'paid_at' => self::YEAR . '-06-20']);
        foreach ([$this->valueA, $this->valueB] as $value) {
            $pdo->prepare(
                'INSERT INTO document_dimension_splits (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, share)
                 VALUES (?, ?, ?, 0, ?, ?, 0.5)'
            )->execute([$this->supplierId, 'purchase_invoice', $split, $this->typeId, $value]);
        }

        // Výdaj 3 000 bankou za přijatou fakturu bez dimenze.
        $this->unassignedPi = $this->purchaseInvoice($this->supplierId, ['without' => 3000.0]);
        $stmt = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($stmt, -3000.0);
        $this->paymentMatch($this->supplierId, $tx, $this->unassignedPi, 3000.0);

        // Nedaňový pohyb (pokladna „ostatní") do sestavy nevstupuje.
        $this->cashDoc('out', 'other', 700.0);
    }

    public function testProfitByDimensionComesFromCashJournal(): void
    {
        $report = $this->profit();

        self::assertSame('cash_journal', $report['basis']);
        $rows = array_column($report['rows'], null, 'value_id');
        self::assertEqualsWithDelta(10000.0, $rows[$this->valueA]['total']['revenue'], 0.001);
        self::assertEqualsWithDelta(2000.0, $rows[$this->valueA]['total']['cost'], 0.001);
        self::assertEqualsWithDelta(2000.0, $rows[$this->valueB]['total']['cost'], 0.001);
        self::assertEqualsWithDelta(3000.0, $report['unassigned']['cost'], 0.001);

        $journal = $this->service->build($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', ['year' => self::YEAR]);
        self::assertEqualsWithDelta($journal['totals']['net'], $report['totals']['result'], 0.001,
            'Součet sestavy musí sedět na rozdíl daňových příjmů a výdajů peněžního deníku.');
    }

    public function testRuleDefaultFillsMovementWithoutValue(): void
    {
        $this->rule('5', 'none', $this->valueB);

        $report = $this->profit();
        $rows = array_column($report['rows'], null, 'value_id');
        self::assertEqualsWithDelta(5000.0, $rows[$this->valueB]['total']['cost'], 0.001);
        self::assertEqualsWithDelta(0.0, $report['unassigned']['cost'], 0.001);
    }

    public function testRuleAuditListsMovementWithoutRequiredValue(): void
    {
        $this->rule('5', 'error', null);
        $this->rule('6', 'error', null);

        $audit = $this->container->get(DimensionRuleAudit::class)
            ->violations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame(1, $audit['total']);
        self::assertSame('bank', $audit['rows'][0]['source_type']);
        self::assertSame('5', $audit['rows'][0]['account_code']);
        self::assertSame('error', $audit['rows'][0]['enforcement']);
        self::assertEqualsWithDelta(3000.0, $audit['rows'][0]['amount'], 0.001);

        $coverage = $this->container->get(DimensionRuleAudit::class)
            ->coverage($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $byCode = array_column($coverage, null, 'synthetic');
        self::assertSame(2, $byCode['5']['lines']);
        self::assertSame(1, $byCode['5']['covered']);
        self::assertSame(1, $byCode['6']['covered']);
    }

    public function testAnalyticsMonthlyUsesCashJournal(): void
    {
        $data = $this->container->get(DimensionProfitService::class)->analytics(
            $this->supplierId,
            $this->typeId,
            self::YEAR,
            [['id' => $this->supplierId, 'company_name' => 'Firma']],
        );
        $june = array_column($data['monthly'], null, 'month')[self::YEAR . '-06'];
        self::assertEqualsWithDelta(10000.0, $june['revenue'], 0.001);
        self::assertEqualsWithDelta(7000.0, $june['cost'], 0.001);
        self::assertEqualsWithDelta(3000.0, $data['totals']['result'], 0.001);
    }

    public function testDistributeKeepsEveryCent(): void
    {
        self::assertSame([1 => 334, 2 => 333, 3 => 333], CashJournalDimensionService::distribute(1000, [1 => 1 / 3, 2 => 1 / 3, 3 => 1 / 3]));
        self::assertSame([1 => -334, 2 => -333, 3 => -333], CashJournalDimensionService::distribute(-1000, [1 => 1 / 3, 2 => 1 / 3, 3 => 1 / 3]));
        self::assertSame([0 => 500], CashJournalDimensionService::distribute(500, []));
    }

    /** @return array<string,mixed> */
    private function profit(): array
    {
        return $this->container->get(DimensionProfitService::class)->build(
            $this->supplierId,
            $this->typeId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            [$this->supplierId],
        );
    }

    private function value(string $code, string $name): int
    {
        $this->db->pdo()->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$this->typeId, $this->supplierId, $code, $name]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function header(string $docType, int $docId, int $valueId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, ?, ?, 0, ?, ?)'
        )->execute([$this->supplierId, $docType, $docId, $this->typeId, $valueId]);
    }

    private function rule(string $mask, string $enforcement, ?int $defaultValue): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO dimension_account_rules (supplier_id, dimension_type_id, account_mask, enforcement, default_value_id, default_from_card, is_active)
             VALUES (?, ?, ?, ?, ?, 0, 1)'
        )->execute([$this->supplierId, $this->typeId, $mask, $enforcement, $defaultValue]);
    }
}
