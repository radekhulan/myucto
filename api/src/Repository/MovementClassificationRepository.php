<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

/**
 * Ruční klasifikační override pohybů peněžního deníku (Epic DE, G2 — migrace 1027).
 *
 * Zapisuje do de_movement_classification; {@see CashJournalRepository} ji jen ČTE
 * (LEFT JOIN v unionSql, ccls/bcls). Identifikace pohybu = (source_type, source_id) —
 * stejný klíč jako řádek deníku (CashJournalRepository::movements source_type/
 * source_id), NE vlastní auto-increment `id` tabulky 1027 (FE ho nezná, dokud
 * klasifikaci nezaloží; source_type/source_id má z řádku deníku vždy).
 *
 * Tenant izolace: bank_transactions nemá supplier_id (stejně jako
 * CashJournalRepository, R4) — tenant scope se ověřuje přes
 * CashJournalRepository::matchingStatementIds (shoda bank_statements.account_number
 * s účty supplieru). cash_documents má supplier_id přímo. belongsToSupplier() MUSÍ
 * proběhnout před upsert()/delete() — de_movement_classification nemá FK na
 * bank_transactions (viz migrace 1027), takže by jinak šlo zapsat klasifikaci
 * cizího bankovního pohybu jen se správným supplier_id v těle requestu.
 */
final class MovementClassificationRepository
{
    /** ENUM tax_bucket dle migrace 1027. */
    public const TAX_BUCKETS = [
        'income_taxable', 'income_exempt', 'income_nontax',
        'expense_taxable', 'expense_nontax', 'transfer', 'private',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CashJournalRepository $journal,
    ) {}

    /** Ověří, že pohyb (source_type/source_id) patří danému supplieru. */
    public function belongsToSupplier(int $supplierId, string $sourceType, int $sourceId): bool
    {
        if ($sourceType === 'bank') {
            $ids = $this->journal->matchingStatementIds($supplierId);
            if ($ids === []) {
                return false;
            }
            $in = implode(',', array_map('intval', $ids));
            $stmt = $this->db->pdo()->prepare(
                "SELECT 1 FROM bank_transactions WHERE id = ? AND statement_id IN ({$in})"
            );
            $stmt->execute([$sourceId]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM cash_documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$sourceId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Vloží / přepíše klasifikaci pohybu (ON DUPLICATE KEY UPDATE — unikát
     * (supplier_id, bank_transaction_id) resp. (supplier_id, cash_document_id)).
     * Volající MUSÍ nejdřív ověřit belongsToSupplier() (tenant izolace).
     *
     * @return array<string,mixed> uložený řádek
     */
    public function upsert(
        int $supplierId,
        string $sourceType,
        int $sourceId,
        string $taxBucket,
        ?string $note,
        ?int $userId,
    ): array {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $sourceType === 'bank' ? $sourceId : null, fn () => $this->upsertMutable($supplierId, $sourceType, $sourceId, $taxBucket, $note, $userId));
    }

    private function upsertMutable(
        int $supplierId,
        string $sourceType,
        int $sourceId,
        string $taxBucket,
        ?string $note,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $bankId = $sourceType === 'bank' ? $sourceId : null;
        $cashId = $sourceType === 'cash' ? $sourceId : null;
        $previous = $this->find($supplierId, $sourceType, $sourceId);

        $pdo->prepare(
            'INSERT INTO de_movement_classification
                (supplier_id, source_type, bank_transaction_id, cash_document_id, tax_bucket, note, classified_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tax_bucket = VALUES(tax_bucket),
                note = VALUES(note),
                classified_by = VALUES(classified_by)'
        )->execute([$supplierId, $sourceType, $bankId, $cashId, $taxBucket, $note, $userId]);
        if (($previous['tax_bucket'] ?? null) !== $taxBucket) {
            $this->recordHistory($supplierId, $sourceType, $sourceId, $previous['tax_bucket'] ?? null, $taxBucket, $userId);
        }

        /** @var array<string,mixed> $row */
        $row = $this->find($supplierId, $sourceType, $sourceId);
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $col = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, source_type, bank_transaction_id, cash_document_id,
                    tax_bucket, note, classified_by, classified_at, updated_at
               FROM de_movement_classification
              WHERE supplier_id = ? AND source_type = ? AND {$col} = ?"
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Smaže override (pohyb se vrátí k auto-klasifikaci). Vrací, zda něco smazal. */
    public function delete(int $supplierId, string $sourceType, int $sourceId, ?int $userId = null): bool
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $sourceType === 'bank' ? $sourceId : null, fn () => $this->deleteMutable($supplierId, $sourceType, $sourceId, $userId));
    }

    private function deleteMutable(int $supplierId, string $sourceType, int $sourceId, ?int $userId = null): bool
    {
        $previous = $this->find($supplierId, $sourceType, $sourceId);
        $col = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM de_movement_classification WHERE supplier_id = ? AND source_type = ? AND {$col} = ?"
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        if ($stmt->rowCount() > 0) {
            $this->recordHistory($supplierId, $sourceType, $sourceId, $previous['tax_bucket'] ?? null, null, $userId);
        }
        return $stmt->rowCount() > 0;
    }

    private function recordHistory(
        int $supplierId,
        string $sourceType,
        int $sourceId,
        ?string $previous,
        ?string $new,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO de_movement_classification_history
                (supplier_id, source_type, source_id, previous_tax_bucket, new_tax_bucket, changed_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $sourceType, $sourceId, $previous, $new, $userId]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['bank_transaction_id'] = $r['bank_transaction_id'] === null ? null : (int) $r['bank_transaction_id'];
        $r['cash_document_id'] = $r['cash_document_id'] === null ? null : (int) $r['cash_document_id'];
        $r['classified_by'] = $r['classified_by'] === null ? null : (int) $r['classified_by'];
        return $r;
    }
}
