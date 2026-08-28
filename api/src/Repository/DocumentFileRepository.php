<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * N souborů na DMS dokument (Epic F7, §4.3) — role primary|attachment.
 *
 * Bajty leží na disku ve stejném content-addressed `sup-{id}` sha stromu jako
 * `documents` (subsystém A); tady jen DB evidence, které sha patří k dokumentu.
 * Právě JEDEN `role='primary'` na dokument se vynucuje v app vrstvě (setPrimary).
 * Vše scoped supplier_id (denormalizace pro tenant filtr).
 */
final class DocumentFileRepository
{
    public function __construct(private readonly Connection $db) {}

    private const COLS = 'id, document_id, supplier_id, role, sha256, filename, original_name,
        mime_type, size_bytes, doc_type, sort_order, uploaded_by, created_at, deleted_at';

    /**
     * Přidá řádek souboru dokumentu. Vrací nové id.
     * @param array{
     *   document_id:int, supplier_id:int, role?:string, sha256:string, filename:string,
     *   original_name?:?string, mime_type?:?string, size_bytes?:?int, doc_type?:?string,
     *   sort_order?:int, uploaded_by?:?int
     * } $d
     */
    public function add(array $d): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_files
                (document_id, supplier_id, role, sha256, filename, original_name,
                 mime_type, size_bytes, doc_type, sort_order, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['document_id'],
            $d['supplier_id'],
            $d['role'] ?? 'attachment',
            $d['sha256'],
            $d['filename'],
            $d['original_name'] ?? null,
            $d['mime_type'] ?? null,
            $d['size_bytes'] ?? null,
            $d['doc_type'] ?? null,
            $d['sort_order'] ?? 0,
            $d['uploaded_by'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Aktivní soubory dokumentu (primary první). @return list<array<string,mixed>> */
    public function listByDocument(int $documentId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM document_files
              WHERE document_id = ? AND supplier_id = ? AND deleted_at IS NULL
              ORDER BY (role = \'primary\') DESC, sort_order, id'
        );
        $stmt->execute([$documentId, $supplierId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $id, int $documentId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM document_files
              WHERE id = ? AND document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $documentId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @param list<int> $documentIds @return list<array<string,mixed>> */
    public function listForPrivacyPurge(int $supplierId, array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
        if ($documentIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($documentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'SELECT ' . self::COLS . ' FROM document_files
                  WHERE supplier_id = ? AND document_id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $result[] = $this->hydrate($row);
            }
        }
        return $result;
    }

    /** Soft-delete souboru (bajty čistí až ref-counting nad diskem). */
    public function remove(int $id, int $documentId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_files SET deleted_at = CURRENT_TIMESTAMP
              WHERE id = ? AND document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $documentId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Přeřadí roli souboru (bez vynucení unikátnosti primary — viz setPrimary). */
    public function setRole(int $id, int $documentId, int $supplierId, string $role): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_files SET role = ?
              WHERE id = ? AND document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$role, $id, $documentId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Nastaví daný soubor jako primary — atomicky degraduje stávající primary na
     * attachment a povýší cílový. Vynucuje „právě jeden primary na dokument".
     *
     * Po povýšení RE-ZRCADLÍ inline `documents` sloupce (SoT primárního souboru —
     * čte je download/preview/thumb/export/dedup/fulltext, §4.5) na hodnoty nově
     * povýšeného `document_files` řádku; jinak by upstream servíroval STARÝ soubor.
     * Zároveň invaliduje starý náhled (thumb_path=NULL, thumb_status='none' — default
     * pipeline stav, viz {@see \MyInvoice\Service\Document\DocumentIngestService}), ať
     * se náhled nepřipne k původnímu (už neprimárnímu) souboru.
     */
    public function setPrimary(int $id, int $documentId, int $supplierId): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $demote = $pdo->prepare(
                'UPDATE document_files SET role = \'attachment\'
                  WHERE document_id = ? AND supplier_id = ? AND role = \'primary\'
                    AND id <> ? AND deleted_at IS NULL'
            );
            $demote->execute([$documentId, $supplierId, $id]);

            $promote = $pdo->prepare(
                'UPDATE document_files SET role = \'primary\'
                  WHERE id = ? AND document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
            );
            $promote->execute([$id, $documentId, $supplierId]);
            $ok = $promote->rowCount() > 0;

            if ($ok) {
                // Re-mirror inline documents SoT z povýšeného souboru + invalidace náhledu.
                // sha256/filename jsou na document_files NOT NULL → přímo; ostatní mohou
                // být NULL (COALESCE ponechá stávající documents hodnotu, ať neporušíme
                // NOT NULL a nepřijdeme o metadata).
                $mirror = $pdo->prepare(
                    'UPDATE documents d
                        JOIN document_files f
                          ON f.id = ? AND f.document_id = d.id AND f.supplier_id = d.supplier_id
                       SET d.sha256        = f.sha256,
                           d.filename      = f.filename,
                           d.original_name = COALESCE(f.original_name, d.original_name),
                           d.mime_type     = COALESCE(f.mime_type, d.mime_type),
                           d.size_bytes    = COALESCE(f.size_bytes, d.size_bytes),
                           d.doc_type      = COALESCE(f.doc_type, d.doc_type),
                           d.thumb_path    = NULL,
                           d.thumb_status  = \'none\'
                     WHERE d.id = ? AND d.supplier_id = ?'
                );
                $mirror->execute([$id, $documentId, $supplierId]);
            }

            $pdo->commit();
            return $ok;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function setSortOrder(int $id, int $documentId, int $supplierId, int $sortOrder): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_files SET sort_order = ?
              WHERE id = ? AND document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$sortOrder, $id, $documentId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'document_id'   => (int) $r['document_id'],
            'supplier_id'   => (int) $r['supplier_id'],
            'role'          => (string) $r['role'],
            'sha256'        => (string) $r['sha256'],
            'filename'      => (string) $r['filename'],
            'original_name' => $r['original_name'] !== null ? (string) $r['original_name'] : null,
            'mime_type'     => $r['mime_type'] !== null ? (string) $r['mime_type'] : null,
            'size_bytes'    => $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
            'doc_type'      => $r['doc_type'] !== null ? (string) $r['doc_type'] : null,
            'sort_order'    => (int) $r['sort_order'],
            'uploaded_by'   => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'created_at'    => (string) $r['created_at'],
            'deleted_at'    => $r['deleted_at'] !== null ? (string) $r['deleted_at'] : null,
        ];
    }
}
