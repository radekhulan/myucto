<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;

final class MoneyS3ClosingTakeover
{
    public function __construct(private readonly Connection $db) {}

    public function isImportedOpening(int $supplierId, array $entries): bool
    {
        if (count($entries) !== 1) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM money_s3_import_map WHERE supplier_id = ? AND kind = ? AND target_id = ?');
        $stmt->execute([$supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $entries[0]['id']]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasOnlyImportedEntries(int $supplierId, int $periodId): bool
    {
        $pdo = $this->db->pdo();
        $posted = $pdo->prepare('SELECT 1 FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND posted_at IS NOT NULL LIMIT 1 FOR UPDATE');
        $posted->execute([$supplierId, $periodId]);
        if ($posted->fetchColumn() === false) {
            return false;
        }
        $other = $pdo->prepare(
            'SELECT 1 FROM journal_entries e WHERE e.supplier_id = ? AND e.period_id = ?
                AND (e.posted_at IS NULL OR NOT EXISTS (SELECT 1 FROM money_s3_import_map m
                    WHERE m.supplier_id = e.supplier_id AND m.kind = ? AND m.target_id = e.id FOR UPDATE))
              LIMIT 1 FOR UPDATE'
        );
        $other->execute([$supplierId, $periodId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY]);
        return $other->fetchColumn() === false;
    }
}
