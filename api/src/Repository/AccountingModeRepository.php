<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

final class AccountingModeRepository
{
    public function __construct(private readonly Connection $db) {}

    public function forYear(int $supplierId, int $year): string
    {
        return $this->forDate($supplierId, sprintf('%04d-12-31', $year));
    }

    public function forDate(int $supplierId, string $date): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT accounting_mode FROM supplier_accounting_modes
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $date]);
        $mode = $stmt->fetchColumn();
        if (is_string($mode) && $mode !== '') {
            return $mode;
        }
        $fallback = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $fallback->execute([$supplierId]);
        return (string) ($fallback->fetchColumn() ?: 'tax_evidence');
    }

    public function effectiveDatesBetween(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from FROM supplier_accounting_modes
              WHERE supplier_id = ? AND effective_from > ? AND effective_from < ?
              ORDER BY effective_from'
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function record(int $supplierId, string $effectiveFrom, string $mode): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE accounting_mode = VALUES(accounting_mode)'
        )->execute([$supplierId, $effectiveFrom, $mode]);
    }

    public function hasTaxEvidence(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM supplier_accounting_modes WHERE supplier_id = ? AND accounting_mode = 'tax_evidence' LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        $fallback = $this->db->pdo()->prepare("SELECT 1 FROM supplier WHERE id = ? AND accounting_mode = 'tax_evidence'");
        $fallback->execute([$supplierId]);
        return $fallback->fetchColumn() !== false;
    }

    public function hasDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM supplier_accounting_modes WHERE supplier_id = ? AND accounting_mode = 'double_entry' LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        $fallback = $this->db->pdo()->prepare("SELECT 1 FROM supplier WHERE id = ? AND accounting_mode = 'double_entry'");
        $fallback->execute([$supplierId]);
        return $fallback->fetchColumn() !== false;
    }

    public function continuousDoubleEntrySince(int $supplierId, string $before): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, accounting_mode FROM supplier_accounting_modes
              WHERE supplier_id = ? AND effective_from < ?
              ORDER BY effective_from'
        );
        $stmt->execute([$supplierId, $before]);

        $started = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['accounting_mode'] === 'double_entry') {
                $started ??= (string) $row['effective_from'];
            } else {
                $started = null;
            }
        }
        return $started;
    }
}
