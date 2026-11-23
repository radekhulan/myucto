<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * #326: doklad se záporným základem a kladnou částkou k úhradě. Vrácené zálohované obaly
 * (0 % DPH) převáží v základu dodané zboží (21 %), daň zboží ale celkovou částku drží
 * kladnou. Výnos (vydaná) i náklad (přijatá) se záporným základem patří na opačnou stranu
 * než obvykle, jinak se zápis rozváží o dvojnásobek základu.
 *
 * Příklad z issue: 16 800 Kč při 21 % a −17 082 Kč při 0 % → základ −282, DPH 3 528,
 * celkem 3 246.
 */
#[Group('integration')]
final class NegativeBasePositiveTotalPostingTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    public function testIssuedInvoiceWithNegativeBasePostsRevenueOnDebit(): void
    {
        $id = $this->issuedInvoice('FV-326', [
            [16800.00, 3528.00, 21.00],
            [-17082.00, 0.00, 0.00],
        ]);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $id);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $id, $lines, ['entry_date' => self::YEAR . '-06-10']);
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(3246.00, $this->side($byAcc, '311', 'debit'), 0.001);
        self::assertEqualsWithDelta(282.00, $this->side($byAcc, '602', 'debit'), 0.001, 'Záporný základ snižuje výnos, patří na MD.');
        self::assertEqualsWithDelta(0.0, $this->side($byAcc, '602', 'credit'), 0.001);
        self::assertEqualsWithDelta(3528.00, $this->sideByPrefix($byAcc, '343', 'credit'), 0.001);
    }

    public function testPurchaseInvoiceWithNegativeBasePostsExpenseOnCredit(): void
    {
        $id = $this->purchase('PF-326', [
            [16800.00, 3528.00],
            [-17082.00, 0.00],
        ]);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $lines, ['entry_date' => self::YEAR . '-06-10']);
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(3246.00, $this->side($byAcc, '321', 'credit'), 0.001);
        self::assertEqualsWithDelta(282.00, $this->side($byAcc, '518', 'credit'), 0.001, 'Záporný základ snižuje náklad, patří na D.');
        self::assertEqualsWithDelta(0.0, $this->side($byAcc, '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(3528.00, $this->sideByPrefix($byAcc, '343', 'debit'), 0.001);
    }

    /** Regrese: běžný doklad s kladným základem účtuje beze změny. */
    public function testPositiveBaseStillPostsOnUsualSides(): void
    {
        $id = $this->issuedInvoice('FV-326-OK', [[1000.00, 210.00, 21.00]]);
        $lines = $this->posting->buildFromInvoice($this->supplierId, $id);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $id, $lines, ['entry_date' => self::YEAR . '-06-10']);
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(1210.00, $this->side($byAcc, '311', 'debit'), 0.001);
        self::assertEqualsWithDelta(1000.00, $this->side($byAcc, '602', 'credit'), 0.001);
        self::assertEqualsWithDelta(210.00, $this->sideByPrefix($byAcc, '343', 'credit'), 0.001);
    }

    /** @param array<string,array{debit?:float,credit?:float}> $byAcc */
    private function side(array $byAcc, string $account, string $side): float
    {
        return (float) ($byAcc[$account][$side] ?? 0.0);
    }

    /** @param array<string,array{debit?:float,credit?:float}> $byAcc */
    private function sideByPrefix(array $byAcc, string $prefix, string $side): float
    {
        $sum = 0.0;
        foreach ($byAcc as $code => $sides) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += (float) ($sides[$side] ?? 0.0);
            }
        }
        return $sum;
    }

    /** @param list<array{0:float,1:float,2:float}> $items základ, DPH, sazba */
    private function issuedInvoice(string $varsymbol, array $items): int
    {
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_column($items, 1)), 2);
        $issue = self::YEAR . '-06-10';
        $clientId = $this->client('Odběratel ' . $varsymbol);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId,
            $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => [$itemBase, $itemVat, $rate]) {
            $stmt->execute([$id, 'Položka ' . $i, $itemBase, $this->vatRateId, $rate, $itemBase, $itemVat, round($itemBase + $itemVat, 2), $i]);
        }
        return $id;
    }

    /** @param list<array{0:float,1:float}> $items základ, DPH */
    private function purchase(string $number, array $items): int
    {
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_column($items, 1)), 2);
        $issue = self::YEAR . '-06-10';
        $vendorId = $this->client('Dodavatel ' . $number);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?, '40', 0)"
        );
        foreach ($items as $i => [$itemBase, $itemVat]) {
            $stmt->execute([$id, 'Položka ' . $i, $itemBase, $this->vatRateId, $itemBase, $itemVat, round($itemBase + $itemVat, 2), $i]);
        }
        return $id;
    }
}
