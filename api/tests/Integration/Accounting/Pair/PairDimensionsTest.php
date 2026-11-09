<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Středisko a dimenze na obou stranách dvojice (jednoduchý zápis): saldokontní strana
 * (321, 211 …) nese dimenzi výsledkového řádku své dvojice, takže předvaha po dimenzi
 * je vyrovnaná. Složená firma zůstává beze změny: dimenze položky jen na nákladu.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PairDimensionsTest extends PairsTestCase
{
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private int $centerType = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->assignments = $this->container->get(DimensionAssignmentRepository::class);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->centerType = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
    }

    public function testItemDimensionIsOnBothSidesOfEachPairAndTrialBalanceByDimensionIsBalanced(): void
    {
        [$a, $b] = [$this->center('ST-A'), $this->center('ST-B')];
        $purchase = $this->purchaseWithItemCenters([[600.00, $a], [400.00, $b]]);

        $entryId = $this->postPurchase($purchase);

        $byAccount = $this->dimensionsByAccount($entryId);
        self::assertSame([$a => '600.00', $b => '400.00'], $byAccount['321'] ?? [], 'Závazek 321 nese středisko dvojice.');
        self::assertSame([$a => '600.00', $b => '400.00'], $byAccount['518'] ?? []);

        $tb = $this->container->get(TrialBalanceService::class)
            ->build($this->supplierId, $this->periodId, null, null, false, false, $this->dimensions->filter($this->supplierId, $a));
        self::assertTrue($tb['checks']['turnover_balanced'], 'Předvaha po středisku je vyrovnaná.');
        self::assertEqualsWithDelta(600.00, $tb['totals']['turnover_md'], 0.01);
    }

    public function testCompositeFirmKeepsItemDimensionOnlyOnExpenseLine(): void
    {
        $this->db->pdo()->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        EntryFormService::forgetShared($this->supplierId);
        [$a, $b] = [$this->center('SL-A'), $this->center('SL-B')];
        $purchase = $this->purchaseWithItemCenters([[600.00, $a], [400.00, $b]]);

        $entryId = $this->postPurchase($purchase);

        $byAccount = $this->dimensionsByAccount($entryId);
        self::assertArrayNotHasKey('321', $byAccount, 'Složená firma: na 321 žádná dimenze navíc.');
        self::assertSame([$a => '600.00', $b => '400.00'], $byAccount['518'] ?? []);
    }

    public function testManualPairCarriesCenterOnBothLines(): void
    {
        $center = $this->center('ST-RUC');
        $manual = $this->container->get(ManualJournalEntryService::class);
        $lines = $manual->normalizePairs($this->supplierId, [[
            'debit_account_code' => '518', 'credit_account_code' => '211', 'amount' => 100,
            'dimensions' => [$this->centerType => $center],
        ]], true);
        $entryId = $manual->post($this->supplierId, $lines, ['entry_date' => self::YEAR . '-04-01', 'user_id' => $this->userId]);

        self::assertSame(['211' => [$center => '100.00'], '518' => [$center => '100.00']], $this->dimensionsByAccount($entryId));
        $stmt = $this->db->pdo()->prepare('SELECT cost_center FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no');
        $stmt->execute([$this->supplierId, $entryId]);
        self::assertSame(['ST-RUC', 'ST-RUC'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function center(string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $this->centerType, ['code' => $code, 'name' => 'Středisko ' . $code])['id'];
    }

    /** @return array<string, array<int,string>> syntetika účtu => hodnota střediska => částka */
    private function dimensionsByAccount(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.amount FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.line_no'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line) {
            $value = $dims[(int) $line['id']][$this->centerType] ?? null;
            if ($value === null) {
                continue;
            }
            $account = substr((string) $line['account_code'], 0, 3);
            $out[$account][$value] = number_format((float) ($out[$account][$value] ?? 0) + (float) $line['amount'], 2, '.', '');
        }
        ksort($out);
        return $out;
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId, 'purchase_invoice', $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
    }

    /** @param list<array{0:float,1:int}> $items základ položky bez DPH, středisko */
    private function purchaseWithItemCenters(array $items): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        $base = round(array_sum(array_column($items, 0)), 2);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, is_fixed_asset, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", 0, 1, ?)'
        )->execute([$this->supplierId, $this->client('Dodavatel středisek'), 'FS' . random_int(100000, 999999), $date, $date, $date, $date,
            $this->currencyId, $base, $base, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $itemDims = [];
        foreach ($items as $i => [$net, $center]) {
            $pdo->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, "Nákup", 1, "ks", ?, ?, 0, ?, 0, ?, ?)'
            )->execute([$id, $net, $this->vatRateId(0), $net, $net, $i]);
            $itemDims[$i + 1] = [$this->centerType => $center];
        }
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $id, [], $itemDims);
        return $id;
    }
}
