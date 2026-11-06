<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class DocumentDeletionBoundary
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentPaymentLinks $paymentLinks,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
    ) {}

    /** @param list<array<string,mixed>> $documents */
    public function changed(int $supplierId, string $sourceType, array $documents, bool $accountantManaged): bool
    {
        if (!in_array($sourceType, ['invoice', 'purchase_invoice'], true) || $documents === []) {
            throw new \InvalidArgumentException('Neplatné doklady ke smazání.');
        }
        $pdo = $this->db->pdo();
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $rootId = (int) $documents[0]['id'];
        $expected = [];
        foreach ($documents as $document) $expected[(int) $document['id']] = $document;
        $ids = array_keys($expected);
        sort($ids, SORT_NUMERIC);
        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $dateColumn = $sourceType === 'invoice' ? 'effective_tax_date' : 'effective_cost_date';
        $parentColumn = $sourceType === 'invoice' ? ', parent_invoice_id' : '';
        $refDate = $sourceType === 'invoice' ? DocumentLockService::invoiceRefDate(...) : DocumentLockService::purchaseRefDate(...);
        foreach (array_chunk($ids, 500) as $batch) {
            $in = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $pdo->prepare("SELECT id, status, booked_at, issue_date, tax_date, $dateColumn$parentColumn
                FROM $table FORCE INDEX (PRIMARY) WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
            $stmt->execute([$supplierId, ...$batch]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($batch)) return true;
            foreach ($rows as $row) {
                $before = $expected[(int) $row['id']];
                if ($row['status'] !== 'draft' || $row['booked_at'] !== null || $refDate($row) !== $refDate($before)
                    || ($sourceType === 'invoice' && ($row['parent_invoice_id'] ?? null) !== ($before['parent_invoice_id'] ?? null))) return true;
            }
        }
        if ($sourceType === 'invoice') {
            $children = [];
            foreach (array_chunk($ids, 500) as $batch) {
                $in = implode(',', array_fill(0, count($batch), '?'));
                $stmt = $pdo->prepare("SELECT id FROM invoices FORCE INDEX (idx_inv_parent) WHERE supplier_id = ? AND parent_invoice_id IN ($in) ORDER BY id FOR UPDATE");
                $stmt->execute([$supplierId, ...$batch]);
                array_push($children, ...array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }
            sort($children, SORT_NUMERIC);
            if ($children !== array_values(array_filter($ids, static fn (int $id): bool => $id !== $rootId))) return true;
        } elseif (!$accountantManaged && $this->submissions->accountantManagedMap($supplierId, $ids, true) !== []) {
            return true;
        }
        foreach (array_chunk($ids, 500) as $batch) {
            if ((new DocumentPostingGroup($this->db))->activeMany($supplierId, $sourceType, $batch, true) !== []) return true;
        }
        return $this->paymentLinks->existsForDocuments($supplierId, $sourceType, $ids, true);
    }
}
