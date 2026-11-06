<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

final class JointAllocationUseGuard
{
    public function __construct(private readonly Connection $db) {}

    public function assertLinesMutable(int $supplierId, array $lineIds): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $this->assertLineClaims($supplierId, $lineIds);
    }

    public function assertAssignmentInsertMutable(int $supplierId, int $lineId): void
    {
        $this->assertAssignmentInsertsMutable($supplierId, [$lineId]);
    }

    public function assertAssignmentInsertsMutable(int $supplierId, array $lineIds): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Assignment insertion requires a transaction.');
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        sort($lineIds, SORT_NUMERIC);
        if ($lineIds === []) return;
        $marks = implode(',', array_fill(0, count($lineIds), '?'));
        // Hint ze snapshotu jen vybírá první zámek. Aktuální řádek se ověřuje až
        // po něm; nový řádek vlastní transakce proto nerozšiřuje zámky na jiné roky.
        $find = $pdo->prepare("SELECT DISTINCT e.period_id FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id WHERE l.supplier_id = ? AND l.id IN ({$marks})");
        $find->execute([$supplierId, ...$lineIds]);
        $periodIds = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        $allPeriods = $periodIds === [];
        if ($allPeriods) {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        } else {
            $periodMarks = implode(',', array_fill(0, count($periodIds), '?'));
            $period = $pdo->prepare("SELECT id FROM accounting_periods WHERE supplier_id = ? AND id IN ({$periodMarks}) ORDER BY starts_on, id FOR UPDATE");
            $period->execute([$supplierId, ...$periodIds]);
            $periodIds = array_map('intval', $period->fetchAll(PDO::FETCH_COLUMN));
        }
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertAssignmentInsertsAfterPeriodPreflight($supplierId, $lineIds);
        $paymentReference = \MyInvoice\Service\Property\Payments\PaymentUseGuard::entryReferenceSql();
        $assetReserveEvidence = \MyInvoice\Service\Accounting\Closing\AssetReserveSourceRepository::assignmentEvidenceSql();
        $capitalEvidence = \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard::assignmentEvidenceSql();
        $current = $pdo->prepare("SELECT l.id, e.id AS entry_id, e.period_id, c.batch_id AS source_batch_id, b.id AS allocation_batch_id, x.id AS cancellation_id
            FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            LEFT JOIN joint_allocation_source_claims c ON c.line_id = l.id AND c.supplier_id = l.supplier_id
            LEFT JOIN joint_allocation_batches b ON b.journal_entry_id = e.id AND b.supplier_id = e.supplier_id
            LEFT JOIN joint_allocation_cancellations x ON x.reversal_entry_id = e.id AND x.supplier_id = e.supplier_id
            WHERE l.supplier_id = ? AND l.id IN ({$marks}) ORDER BY l.id FOR UPDATE");
        $current->execute([$supplierId, ...$lineIds]);
        $rows = $current->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($lineIds)) {
            throw new PostingException('allocation_source_changed', 'Řádek nebo jeho účetní období se změnily. Načtěte aktuální stav.', 409);
        }
        // Odkazy na zápis závisí jen na hlavičce, proto se vyhodnocují jednou za zápis,
        // ne za každý jeho řádek (uzávěrkový zápis jich mívá stovky).
        $entryIds = array_values(array_unique(array_map('intval', array_column($rows, 'entry_id'))));
        sort($entryIds, SORT_NUMERIC);
        $entryMarks = implode(',', array_fill(0, count($entryIds), '?'));
        $references = $pdo->prepare(\MyInvoice\Infrastructure\Database\SubqueryCache::OFF."SELECT e.id, ({$paymentReference}) AS property_payment_in_use, ({$assetReserveEvidence}) AS asset_reserve_evidence_exists, ({$capitalEvidence}) AS capital_evidence_exists
            FROM journal_entries e WHERE e.supplier_id = ? AND e.id IN ({$entryMarks}) ORDER BY e.id FOR UPDATE");
        $references->execute([$supplierId, ...$entryIds]);
        $byEntry = array_column($references->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        foreach ($rows as &$row) {
            $reference = $byEntry[$row['entry_id']] ?? null;
            if ($reference === null) {
                throw new PostingException('allocation_source_changed', 'Řádek nebo jeho účetní období se změnily. Načtěte aktuální stav.', 409);
            }
            $row += array_diff_key($reference, ['id' => true]);
        }
        unset($row);
        foreach ($rows as $row) {
            \MyInvoice\Service\Property\Payments\PaymentUseGuard::assertEntryReferenceMutable((bool) $row['property_payment_in_use']);
            if (!$allPeriods && !in_array((int) $row['period_id'], $periodIds, true)) {
                throw new PostingException('allocation_source_changed', 'Řádek nebo jeho účetní období se změnily. Načtěte aktuální stav.', 409);
            }
            if ($row['allocation_batch_id'] !== null || $row['cancellation_id'] !== null) {
                throw new PostingException('allocation_entry_immutable', 'Alokační zápis je neměnný. Použijte zrušení celé alokace.', 409);
            }
            if ($row['source_batch_id'] !== null) {
                throw new PostingException('allocation_source_in_use', 'Zdroj je součástí aktivní alokace. Před jeho změnou nejprve zrušte alokaci.', 409,
                    ['claims' => [['line_id' => (int) $row['id'], 'batch_id' => (int) $row['source_batch_id']]]]);
            }
        }
        if (array_filter(array_column($rows, 'capital_evidence_exists')) !== []) {
            (new \MyInvoice\Service\Nonprofit\Capital\CapitalUseGuard($this->db))->assertAssignmentEntriesMutable($supplierId, array_column($rows, 'entry_id'));
        }
        if (array_filter(array_column($rows, 'asset_reserve_evidence_exists')) !== []) {
            (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db,
                new \MyInvoice\Repository\JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db))))
                ->assertAssignmentEntriesMutable($supplierId, array_column($rows, 'entry_id'));
        }
    }

    private function assertLineClaims(int $supplierId, array $lineIds): void
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        sort($lineIds, SORT_NUMERIC);
        foreach (array_chunk($lineIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $lines = $this->db->pdo()->prepare("SELECT DISTINCT entry_id FROM journal_entry_lines WHERE supplier_id = ? AND id IN ({$marks}) ORDER BY entry_id FOR UPDATE");
            $lines->execute([$supplierId, ...$chunk]);
            $this->assertNotAllocationEntries($supplierId, array_map('intval', $lines->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertUnclaimed($supplierId, $chunk);
        }
    }

    private function assertUnclaimed(int $supplierId, array $lineIds): void
    {
        foreach (array_chunk($lineIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT line_id, batch_id FROM joint_allocation_source_claims WHERE supplier_id = ? AND line_id IN ({$marks}) ORDER BY line_id FOR UPDATE");
            $stmt->execute([$supplierId, ...$chunk]);
            $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($claims !== []) {
                throw new PostingException('allocation_source_in_use', 'Zdroj je součástí aktivní alokace. Před jeho změnou nejprve zrušte alokaci.', 409, ['claims' => $claims]);
            }
        }
    }

    public function assertEntriesMutable(int $supplierId, array $entryIds): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        sort($entryIds, SORT_NUMERIC);
        $this->assertNotAllocationEntries($supplierId, $entryIds);
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id IN ({$marks}) ORDER BY id FOR UPDATE");
            $stmt->execute([$supplierId, ...$chunk]);
            $this->assertUnclaimed($supplierId, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
    }

    public function assertOwnedSources(int $supplierId, int $batchId, array $sourceIds): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT line_id FROM joint_allocation_source_claims WHERE supplier_id = ? AND batch_id = ? ORDER BY line_id FOR UPDATE');
        $stmt->execute([$supplierId, $batchId]);
        $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($sourceIds, SORT_NUMERIC);
        if ($owned !== $sourceIds || $sourceIds === []) {
            throw new PostingException('allocation_source_ownership_changed', 'Alokace již nevlastní přesně své zdrojové řádky.', 409);
        }
    }

    public function assertSourcesMutable(int $supplierId, array $sources): void
    {
        $entries = (new DimensionPeriodGuard($this->db))->lockSources($supplierId, $sources);
        $this->assertEntriesMutable($supplierId, array_map('intval', array_keys($entries)));
    }

    public function assertDocumentMutable(int $supplierId, string $docType, int $docId): void
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $sourceType = match ($docType) {
            'cash_document' => 'cash', 'bank_transaction' => 'bank',
            'journal_template', 'recurring_template' => null,
            default => $docType,
        };
        $sources = $sourceType === null ? [] : [[$sourceType, $docId]];
        if ($docType === 'asset') {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM depreciation_entries WHERE supplier_id = ? AND asset_id = ? AND kind = 'accounting' ORDER BY id FOR UPDATE");
            $stmt->execute([$supplierId, $docId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $sources[] = ['depreciation', (int) $id];
            $sources[] = ['asset_disposal', $docId];
        }
        $this->assertSourcesMutable($supplierId, $sources);
    }

    private function assertNotAllocationEntries(int $supplierId, array $entryIds): void
    {
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['joint_allocation_batches' => 'journal_entry_id', 'joint_allocation_cancellations' => 'reversal_entry_id'] as $table => $column) {
                $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND {$column} IN ({$marks}) ORDER BY id FOR UPDATE");
                $stmt->execute([$supplierId, ...$chunk]);
                if ($stmt->fetchColumn() !== false) {
                    throw new PostingException('allocation_entry_immutable', 'Alokační zápis je neměnný. Použijte zrušení celé alokace.', 409);
                }
            }
        }
    }
}
