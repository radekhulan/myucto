<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{DimensionAssignmentRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{JournalLineComparison, PostingException};

final class AssetReserveSourceUseGuard
{
    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal) {}

    public function assertEntryChange(int $supplierId, int $entryId, ?array $replacementLines, ?string $replacementDate, ?array $replacementHeader = null): bool
    {
        $claims = (new AssetReserveSourceRepository($this->db))->protectedReceipts($supplierId);
        $used = isset($this->pinnedEntries($supplierId)[$entryId]);
        foreach ($claims as $claim) foreach ($claim['receipt']['contributions'] as $contribution) {
            if ($contribution['entry_id'] === $entryId) $used = true;
        }
        if (!$used) return false;
        $entry = $this->journal->findForUpdate($entryId, $supplierId);
        if ($entry === null || $replacementLines === null || $replacementDate !== $entry['entry_date']) throw self::used();
        $assignment = new DimensionAssignmentRepository($this->db);
        $ids = array_column($entry['lines'], 'id');
        $dimensions = $assignment->lineDimensions($supplierId, $ids, true);
        $splits = $assignment->lineSplits($supplierId, $ids, true);
        foreach ($entry['lines'] as &$line) {
            $line['dimensions'] = $dimensions[$line['id']] ?? [];
            $line['dimension_splits'] = $splits[$line['id']] ?? [];
        }
        unset($line);
        if (!JournalLineComparison::sameLines($entry['lines'], $replacementLines)) throw self::used();
        if ($replacementHeader !== null) {
            foreach (['period_id', 'source_type', 'source_id', 'document_date', 'document_no', 'description'] as $field) {
                if ((string) ($replacementHeader[$field] ?? '') !== (string) ($entry[$field] ?? '')) throw self::used();
            }
            if (($replacementHeader['posted_at'] ?? null) === null) throw self::used();
        }
        return true;
    }

    public function assertReversal(int $supplierId, int $entryId): void
    {
        $this->assertEntryChange($supplierId, $entryId, null, null);
        foreach ((new AssetReserveSourceRepository($this->db))->bindings($supplierId) as $state) {
            foreach ($state['records'] as $stored) {
                if ($stored['receipt']['source_hash'] === $state['current']['source_hash']) continue;
                $id = (int) $stored['record']['entry_id'];
                $legal = new LegalClosing504Repository($this->db, $this->journal);
                $evidence = $legal->reserveReversalEvidence($supplierId, $id, $stored['period_id']);
                if (in_array($entryId, $evidence['entry_ids'], true)) throw self::used();
                $seen = [];
                while ($id > 0) {
                    if ($id === $entryId) throw self::used();
                    if (isset($seen[$id]) || count($seen) >= 100) throw self::used();
                    $seen[$id] = true;
                    $entry = $this->journal->findForUpdate($id, $supplierId);
                    if ($entry === null) throw self::used();
                    $id = (int) ($entry['reversed_by'] ?? 0);
                }
            }
        }
    }

    public function assertEntriesMutable(int $supplierId, array $entryIds): void
    {
        $ids = array_fill_keys(array_map('intval', $entryIds), true);
        if (array_intersect_key($ids, $this->pinnedEntries($supplierId)) !== []) throw self::used();
        foreach ((new AssetReserveSourceRepository($this->db))->protectedReceipts($supplierId) as $stored) {
            foreach ($stored['receipt']['contributions'] as $line) if (isset($ids[$line['entry_id']])) throw self::used();
        }
    }

    public function assertAssignmentEntriesMutable(int $supplierId, array $entryIds): void
    {
        $protected = (new AssetReserveSourceRepository($this->db))->protectedAssignmentEntryIds($supplierId);
        foreach ($entryIds as $id) if (isset($protected[(int) $id])) throw self::used();
    }

    public function assertLinesMutable(int $supplierId, array $lineIds): void
    {
        if ($lineIds === []) return;
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $ids = array_values(array_unique(array_map('intval', $lineIds)));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->pdo()->prepare("SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id IN ($marks) FOR UPDATE");
        $statement->execute([$supplierId, ...$ids]);
        $this->assertEntriesMutable($supplierId, $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function assertAssetChange(int $supplierId, int $assetId, ?array $replacement): void
    {
        $claims = (new AssetReserveSourceRepository($this->db))->protectedReceipts($supplierId);
        foreach ($claims as $claim) {
            $receipt = $claim['receipt'];
            if ($receipt['asset_id'] !== $assetId) continue;
            if ($replacement === null) throw self::used();
            foreach (['put_into_use_date', 'asset_account_code'] as $key) {
                if (array_key_exists($key, $replacement) && $replacement[$key] !== $receipt[$key]) throw self::used();
            }
            if (array_key_exists('input_price', $replacement)
                && \MyInvoice\Service\Accounting\JournalLineAmount::cents($replacement['input_price']) !== $receipt['input_cents']) throw self::used();
        }
    }

    public function assertAssetLifecycleChange(int $supplierId, int $assetId, array $current, string $status, ?string $disposalDate): void
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        foreach ((new AssetReserveSourceRepository($this->db))->protectedReceipts($supplierId) as $claim) {
            if ($claim['receipt']['asset_id'] !== $assetId) continue;
            $date = $claim['ends_on'];
            foreach ([[$current['status'], $current['disposal_date']], [$status, $disposalDate]] as [$state, $disposed]) {
                if ($state !== 'in_use' && !($state === 'disposed' && $disposed !== null && $disposed > $date)) throw self::used();
            }
        }
    }

    public function assertImprovementChange(int $supplierId, int $assetId, int $improvementId, ?array $replacement): void
    {
        foreach ((new AssetReserveSourceRepository($this->db))->protectedReceipts($supplierId) as $claim) {
            if ($claim['receipt']['asset_id'] !== $assetId) continue;
            if ($improvementId === 0 && $replacement !== null && ($replacement['completed_on'] ?? '') <= $claim['ends_on']) throw self::used();
            foreach ($claim['receipt']['improvements'] as $improvement) {
                if ($improvement['id'] !== $improvementId) continue;
                if ($replacement === null
                    || (isset($replacement['completed_on']) && $replacement['completed_on'] !== $improvement['completed_on'])
                    || (isset($replacement['amount']) && \MyInvoice\Service\Accounting\JournalLineAmount::cents($replacement['amount']) !== $improvement['amount_cents'])) throw self::used();
            }
        }
    }

    private function pinnedEntries(int $supplierId): array
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        return (new AssetReserveSourceRepository($this->db))->protectedAssignmentEntryIds($supplierId);
    }

    private static function used(): PostingException
    {
        return new PostingException('asset_reserve_source_used', 'Zdroj majetku je navázán na doložené rozhodnutí o rezervě. Jeho finanční údaje a společné dimenze nelze přepsat.', 409);
    }
}
