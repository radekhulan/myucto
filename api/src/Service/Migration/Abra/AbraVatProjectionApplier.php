<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AbraVatProjectionApplier
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $plan @param list<array<string,mixed>> $groups */
    public function apply(int $supplierId, int $targetId, array $plan, array $groups): array
    {
        $taxGroups = array_values(array_filter($groups, static fn (array $group): bool =>
            !in_array((string) ($group['class'] ?? ''), ['000P', '000U'], true)
            && (abs((float) ($group['base'] ?? 0)) >= 0.005 || abs((float) ($group['vat'] ?? 0)) >= 0.005)));
        if (count($taxGroups) !== 1 || $plan['status'] === 'draft' || $plan['status'] === 'cancelled') {
            return ['updated' => 0, 'warning' => null];
        }
        $group = $taxGroups[0];
        if ($plan['kind'] === 'purchase') {
            return $this->purchase($supplierId, $targetId, $plan, $group);
        }
        return $this->sale($supplierId, $targetId, $plan, $group);
    }

    private function purchase(int $supplierId, int $targetId, array $plan, array $group): array
    {
        $items = array_values(array_filter($plan['items'], static fn (array $item): bool =>
            empty($item['import_tax_excluded']) && ($item['source_vat_class'] ?? '') === $group['class']));
        if (count($items) !== 1) return ['updated' => 0, 'warning' => null];
        $item = $items[0];
        $sign = \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSign(
            $plan['document_kind'] !== null ? (string) $plan['document_kind'] : null,
            (float) $plan['total_with_vat'],
        );
        $rate = $plan['exchange_rate'] ?? 1.0;
        $baseCzk = $item['import_tax_base_czk'] !== null
            ? (float) $item['import_tax_base_czk'] : round((float) $item['base'] * (float) $rate, 2);
        if (abs($baseCzk * $sign - (float) $group['base']) > 2.0) {
            return ['updated' => 0, 'warning' => 'document_vat_projection_mismatch_requires_review'];
        }
        $query = $this->db->pdo()->prepare('SELECT i.id, i.import_projection_vat_czk,
                i.total_without_vat, i.import_tax_excluded, p.status
            FROM purchase_invoice_items i
            JOIN purchase_invoices p ON p.id = i.purchase_invoice_id AND p.supplier_id = ?
            WHERE i.purchase_invoice_id = ? ORDER BY i.order_index, i.id');
        $query->execute([$supplierId, $targetId]);
        $targetItems = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($targetItems) !== count($plan['items'])
            || in_array($targetItems[0]['status'], ['draft', 'cancelled'], true)) {
            return ['updated' => 0, 'warning' => 'document_vat_projection_target_modified_requires_review'];
        }
        foreach ($targetItems as $index => $targetItem) {
            if (abs((float) $targetItem['total_without_vat'] - (float) $plan['items'][$index]['base']) > 0.02
                || (int) $targetItem['import_tax_excluded'] !== (int) !empty($plan['items'][$index]['import_tax_excluded'])) {
                return ['updated' => 0, 'warning' => 'document_vat_projection_target_modified_requires_review'];
            }
        }
        $itemIndex = array_search($item, $plan['items'], true);
        $targetItem = $targetItems[$itemIndex];
        $snapshotVat = round((float) $group['vat'] * $sign, 2);
        if ($targetItem['import_projection_vat_czk'] !== null
            && abs((float) $targetItem['import_projection_vat_czk'] - $snapshotVat) < 0.005) {
            return ['updated' => 0, 'warning' => null];
        }
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET import_projection_vat_czk = ?
            WHERE id = ? AND purchase_invoice_id = ?')->execute([$snapshotVat, $targetItem['id'], $targetId]);
        return ['updated' => 1, 'warning' => null];
    }

    private function sale(int $supplierId, int $targetId, array $plan, array $group): array
    {
        if ($plan['invoice_type'] !== 'credit_note' || $plan['total_with_vat'] <= 0) {
            return ['updated' => 0, 'warning' => null];
        }
        $items = array_values(array_filter($plan['items'], static fn (array $item): bool =>
            ($item['source_vat_class'] ?? '') === $group['class']));
        if ($items === [] || count($items) !== count($plan['items'])) {
            return ['updated' => 0, 'warning' => null];
        }
        $rate = $plan['exchange_rate'] ?? 1.0;
        $baseCzk = round(array_sum(array_column($items, 'base')) * (float) $rate, 2);
        if (abs(abs($baseCzk) - abs((float) $group['base'])) > 2.0 || abs($baseCzk) < 0.005) {
            return ['updated' => 0, 'warning' => 'document_vat_projection_mismatch_requires_review'];
        }
        $query = $this->db->pdo()->prepare('SELECT invoice_type, status, total_with_vat, import_tax_sign
            FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $query->execute([$supplierId, $targetId]);
        $target = $query->fetch(PDO::FETCH_ASSOC);
        if ($target === false || $target['invoice_type'] !== 'credit_note'
            || in_array($target['status'], ['draft', 'cancelled'], true)
            || abs((float) $target['total_with_vat'] - (float) $plan['total_with_vat']) > 0.02) {
            return ['updated' => 0, 'warning' => 'document_vat_projection_target_modified_requires_review'];
        }
        $wanted = $baseCzk * (float) $group['base'] > 0 ? 1 : -1;
        $current = $target['import_tax_sign'] === null ? -1 : (int) $target['import_tax_sign'];
        if ($current === $wanted) return ['updated' => 0, 'warning' => null];
        $this->db->pdo()->prepare('UPDATE invoices SET import_tax_sign = ?
            WHERE supplier_id = ? AND id = ?')->execute([$wanted, $supplierId, $targetId]);
        return ['updated' => 1, 'warning' => null];
    }
}
