<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Poznámky k pohybům peněžního deníku daňové evidence (migrace 1993) — protějšek
 * {@see JournalEntryNoteRepository} pro firmu bez účetního deníku.
 *
 * Pohyb je dvojice (source_type, source_id) řádku deníku ({@see CashJournalRepository}).
 * Zdroje nemají společného rodiče, proto vlastnictví ověřuje {@see sourceBelongsToSupplier()}
 * před každým zápisem; čtení i úpravy jsou vždy scoped firmou a pohybem.
 */
final class CashJournalNoteRepository
{
    public const SOURCE_TYPES = ['cash', 'bank', 'invoice_payment', 'purchase_invoice', 'gopay'];

    public const MAX_BODY_LENGTH = JournalEntryNoteRepository::MAX_BODY_LENGTH;

    public const MAX_NOTES_PER_SOURCE = JournalEntryNoteRepository::MAX_NOTES_PER_ENTRY;

    /**
     * Entita přílohy (document_links) k pohybu deníku. Pohyb GoPay je systémový řádek
     * vyúčtování, přílohu nemá.
     */
    public const ATTACHMENT_ENTITY = [
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
        'invoice_payment' => 'invoice_payment',
        'purchase_invoice' => 'purchase_invoice',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CashJournalRepository $journal,
    ) {}

    public function sourceBelongsToSupplier(string $sourceType, int $sourceId, int $supplierId): bool
    {
        if ($sourceId <= 0) {
            return false;
        }
        if ($sourceType === 'bank') {
            $stmt = $this->db->pdo()->prepare('SELECT statement_id FROM bank_transactions WHERE id = ?');
            $stmt->execute([$sourceId]);
            $statementId = $stmt->fetchColumn();
            return $statementId !== false
                && in_array((int) $statementId, $this->journal->matchingStatementIds($supplierId), true);
        }
        $sql = match ($sourceType) {
            'cash' => 'SELECT 1 FROM cash_documents WHERE id = ? AND supplier_id = ?',
            'invoice_payment' => 'SELECT 1 FROM invoice_payments WHERE id = ? AND supplier_id = ?',
            'purchase_invoice' => 'SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?',
            'gopay' => 'SELECT 1 FROM gopay_movements WHERE id = ? AND supplier_id = ?',
            default => null,
        };
        if ($sql === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sourceId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, string $sourceType, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.id, n.source_type, n.source_id, n.body, n.pinned,
                    n.created_by, n.created_at, n.updated_by, n.updated_at,
                    cu.name AS created_by_name, uu.name AS updated_by_name
               FROM cash_journal_notes n
               LEFT JOIN users cu ON cu.id = n.created_by
               LEFT JOIN users uu ON uu.id = n.updated_by
              WHERE n.supplier_id = ? AND n.source_type = ? AND n.source_id = ? AND n.deleted_at IS NULL
              ORDER BY n.pinned DESC, n.created_at DESC, n.id DESC'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countLive(int $supplierId, string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM cash_journal_notes
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(int $supplierId, string $sourceType, int $sourceId, string $body, bool $pinned, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO cash_journal_notes (supplier_id, source_type, source_id, body, pinned, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $sourceType, $sourceId, $body, $pinned ? 1 : 0, $userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, string $sourceType, int $sourceId, ?string $body, ?bool $pinned, ?int $userId): bool
    {
        $sets = [];
        $params = [];
        if ($body !== null) {
            $sets[] = 'body = ?';
            $params[] = $body;
        }
        if ($pinned !== null) {
            $sets[] = 'pinned = ?';
            $params[] = $pinned ? 1 : 0;
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_by = ?';
        $params[] = $userId;
        array_push($params, $id, $supplierId, $sourceType, $sourceId);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE cash_journal_notes SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND supplier_id = ? AND source_type = ? AND source_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id, int $supplierId, string $sourceType, int $sourceId, ?int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE cash_journal_notes SET deleted_at = CURRENT_TIMESTAMP, updated_by = ?
              WHERE id = ? AND supplier_id = ? AND source_type = ? AND source_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$userId, $id, $supplierId, $sourceType, $sourceId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Živé poznámky všech pohybů firmy v jednom dotazu — řádky deníku je ukazují bez N+1.
     *
     * @return array<string,list<array{id:int,body:string,pinned:bool}>> "source_type:source_id" => poznámky
     */
    public function briefForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source_type, source_id, body, pinned FROM cash_journal_notes
              WHERE supplier_id = ? AND deleted_at IS NULL
              ORDER BY source_type, source_id, pinned DESC, created_at DESC, id DESC'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['source_type'] . ':' . (int) $r['source_id']][] = [
                'id' => (int) $r['id'],
                'body' => (string) $r['body'],
                'pinned' => (bool) $r['pinned'],
            ];
        }
        return $out;
    }

    /**
     * Počty dokumentů připojených k pohybům (document_links), klíč "source_type:source_id".
     *
     * @return array<string,int>
     */
    public function attachmentCounts(int $supplierId): array
    {
        $entities = array_flip(self::ATTACHMENT_ENTITY);
        $marks = implode(',', array_fill(0, count($entities), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT dl.entity_type, dl.entity_id, COUNT(*) AS c
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id AND d.deleted_at IS NULL AND d.scope = 'company'
              WHERE dl.supplier_id = ? AND dl.entity_type IN ({$marks})
              GROUP BY dl.entity_type, dl.entity_id"
        );
        $stmt->execute([$supplierId, ...array_keys($entities)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$entities[(string) $r['entity_type']] . ':' . (int) $r['entity_id']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'source_type' => (string) $r['source_type'],
            'source_id' => (int) $r['source_id'],
            'body' => (string) $r['body'],
            'pinned' => (bool) $r['pinned'],
            'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_by_name' => $r['created_by_name'] !== null ? (string) $r['created_by_name'] : null,
            'created_at' => (string) $r['created_at'],
            'updated_by' => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_by_name' => $r['updated_by_name'] !== null ? (string) $r['updated_by_name'] : null,
            'updated_at' => $r['updated_at'] !== null ? (string) $r['updated_at'] : null,
        ];
    }
}
