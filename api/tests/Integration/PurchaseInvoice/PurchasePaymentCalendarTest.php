<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\PurchaseInvoice\PurchasePaymentCalendarService;
use MyInvoice\Tests\Integration\TaxEvidence\CashJournalTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Platební kalendář přijatých dokladů (issue #140): z první platby vzniknou další doklady
 * se stejným číslem, každý se svou splatností, DUZP a částkou, ve stavu vzoru.
 */
#[Group('integration')]
final class PurchasePaymentCalendarTest extends CashJournalTestCase
{
    public function testCreatesInstallmentsWithSameNumberOwnDatesAndAmounts(): void
    {
        // Vzor zadaný v cenách s DPH: přenos prices_include_vat musí platbám zachovat brutto ceny.
        $template = $this->template(1210.0, 21.0, true);
        $pdo = $this->db->pdo();
        self::assertEqualsWithDelta(1210.0, (float) $pdo->query("SELECT total_with_vat FROM purchase_invoices WHERE id = {$template}")->fetchColumn(), 0.01);

        $ids = $this->container->get(PurchasePaymentCalendarService::class)->createInstallments(
            $this->supplierId,
            $template,
            [
                ['due_date' => self::YEAR . '-07-15', 'amount' => 1210.0],
                ['due_date' => self::YEAR . '-08-15', 'amount' => 605.0],
            ],
            $this->userId,
        );

        self::assertCount(2, $ids);
        $rows = $pdo->query(
            'SELECT vendor_invoice_number, vendor_number_seq, due_date, tax_date, total_with_vat, total_vat,
                    status, prices_include_vat, vendor_id
               FROM purchase_invoices WHERE id IN (' . implode(',', $ids) . ') ORDER BY due_date'
        )->fetchAll(PDO::FETCH_ASSOC);
        $number = (string) $pdo->query("SELECT vendor_invoice_number FROM purchase_invoices WHERE id = {$template}")->fetchColumn();

        self::assertSame([$number, $number], array_column($rows, 'vendor_invoice_number'));
        self::assertSame(['1', '2'], array_map('strval', array_column($rows, 'vendor_number_seq')));
        self::assertSame([self::YEAR . '-07-15', self::YEAR . '-08-15'], array_column($rows, 'tax_date'), 'DUZP = splatnost platby');
        self::assertEqualsWithDelta(1210.0, (float) $rows[0]['total_with_vat'], 0.01);
        self::assertEqualsWithDelta(210.0, (float) $rows[0]['total_vat'], 0.01);
        self::assertEqualsWithDelta(605.0, (float) $rows[1]['total_with_vat'], 0.01);
        self::assertSame(['received', 'received'], array_column($rows, 'status'), 'stav podle vzoru');
        self::assertSame(['1', '1'], array_map('strval', array_column($rows, 'prices_include_vat')));
    }

    public function testValidationRejectsEmptyAndInvalidRows(): void
    {
        self::assertNotSame([], PurchasePaymentCalendarService::validate([])['errors']);
        $v = PurchasePaymentCalendarService::validate([['due_date' => '2099-02-30', 'amount' => 10], ['due_date' => '2099-03-01', 'amount' => 0]]);
        self::assertCount(2, $v['errors']);
    }

    private function template(float $unitPrice, float $rate, bool $pricesIncludeVat): int
    {
        $base = $pricesIncludeVat ? round($unitPrice / (1 + $rate / 100), 2) : $unitPrice;
        $pdo = $this->db->pdo();
        $vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE rate_percent = {$rate} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($vatRateId === 0) {
            self::markTestSkipped('Chybí sazba DPH.');
        }
        $id = $this->purchaseInvoice($this->supplierId, [
            'without' => $base, 'with' => round($base * (1 + $rate / 100), 2), 'issue_date' => self::YEAR . '-06-01',
        ]);
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, order_index)
             VALUES (?, "Záloha na elektřinu", 1, "ks", ?, ?, ?, 0)'
        )->execute([$id, $unitPrice, $vatRateId, $rate]);
        $pdo->prepare('UPDATE purchase_invoices SET prices_include_vat = ? WHERE id = ?')->execute([$pricesIncludeVat ? 1 : 0, $id]);
        $this->container->get(PurchaseInvoiceCalculator::class)->recompute($id);
        return $id;
    }
}
