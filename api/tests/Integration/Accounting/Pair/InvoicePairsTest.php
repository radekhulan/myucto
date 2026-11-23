<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use PHPUnit\Framework\Attributes\Group;

/**
 * Kritérium c: zaúčtování vydané a přijaté faktury ve formě single vytvoří dvojice
 * podle předkontací a součet dvojic je částka dokladu (Zadání: vydaná faktura se
 * dvěma sazbami = dvojice 311 proti výnosům a DPH).
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class InvoicePairsTest extends PairsTestCase
{
    public function testIssuedInvoiceWithTwoVatRatesIsPairedAgainst311(): void
    {
        $invoiceId = $this->issuedInvoice([[10000.00, 21], [1000.00, 12]]);

        $entryId = $this->posting->postDocument(
            $this->supplierId, 'invoice', $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );

        $pairs = $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId, '13220.00');
        self::assertSame(['311'], array_values(array_unique(array_map(static fn (array $p): string => substr($p[0], 0, 3), $pairs))));
        self::assertGreaterThanOrEqual(2, count($pairs));
    }

    public function testPurchaseInvoiceIsPairedAgainst321(): void
    {
        $purchaseId = $this->purchaseInvoice([[200.00, 21], [1000.00, 21]]);

        $entryId = $this->posting->postDocument(
            $this->supplierId, 'purchase_invoice', $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );

        $pairs = $this->assertEntryPaired($this->db->pdo(), $this->supplierId, $entryId, '1452.00');
        self::assertSame(['321'], array_values(array_unique(array_map(static fn (array $p): string => substr($p[1], 0, 3), $pairs))));
    }

    /** @param list<array{0:float,1:int}> $items základ, sazba */
    private function issuedInvoice(array $items): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        [$base, $vat] = self::totals($items);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                 reverse_charge, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, 'DV' . random_int(100000, 999999), $this->client('Odběratel dvojic'), $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$net, $rate]) {
            $itemVat = round($net * $rate / 100, 2);
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                 VALUES (?, "Položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?, "1")'
            )->execute([$id, $net, $this->vatRateId($rate), $rate, $net, $itemVat, round($net + $itemVat, 2), $i]);
        }
        return $id;
    }

    /** @param list<array{0:float,1:int}> $items */
    private function purchaseInvoice(array $items): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        [$base, $vat] = self::totals($items);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, is_fixed_asset, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", 0, 1, ?)'
        )->execute([$this->supplierId, $this->client('Dodavatel dvojic'), 'FP' . random_int(100000, 999999), $date, $date, $date, $date,
            $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$net, $rate]) {
            $itemVat = round($net * $rate / 100, 2);
            $pdo->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, "Nákup", 1, "ks", ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $net, $this->vatRateId($rate), $rate, $net, $itemVat, round($net + $itemVat, 2), $i]);
        }
        return $id;
    }

    /** @param list<array{0:float,1:int}> $items @return array{float,float} */
    private static function totals(array $items): array
    {
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => round($i[0] * $i[1] / 100, 2), $items)), 2);
        return [$base, $vat];
    }
}
