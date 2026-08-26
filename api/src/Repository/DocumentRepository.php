<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Deletion\ForeignKeyDeletionGuard;
use PDO;

/**
 * Dokumenty sekce Dokumenty. Vše per-supplier, soft-delete přes deleted_at (koš).
 * Fyzické soubory leží na disku (viz DocumentStorage); tady jen metadata.
 */
final class DocumentRepository
{
    public function __construct(private readonly Connection $db) {}

    private const COLS = 'id, supplier_id, folder_id, title, description, original_name,
        filename, sha256, mime_type, size_bytes, doc_type, source, parent_document_id,
        signature_for_id, text_status, thumb_path, thumb_status, uploaded_by,
        scope, owner_user_id, deleted_at, created_at';

    /**
     * Per-user scope guard (Epic F7, §4.2 — bezpečnostní jádro). Vrací SQL fragment
     * (`AND …`) + poziční parametry, které se PŘIPOJÍ na KONEC WHERE (před ORDER BY/LIMIT).
     *  - admin → prázdný fragment (vidí vše tenanta),
     *  - user  → company + vlastní user doklady,
     *  - user=NULL → fail-closed: jen company.
     * `supplier_id` zůstává VNĚJŠÍ guard beze změny; tohle je vnitřní osa.
     *
     * @return array{0:string,1:list<int>}
     */
    private function scopeClause(DocumentViewerContext $viewer, string $alias = ''): array
    {
        if ($viewer->isAdmin) {
            return ['', []];
        }
        $col = $alias !== '' ? $alias . '.' : '';
        if ($viewer->userId === null) {
            return [" AND {$col}scope = 'company'", []];
        }
        return [
            " AND ({$col}scope = 'company' OR ({$col}scope = 'user' AND {$col}owner_user_id = ?))",
            [$viewer->userId],
        ];
    }

    /**
     * @param array{
     *   supplier_id:int, folder_id:?int, title:string, description:?string,
     *   original_name:string, filename:string, sha256:string, mime_type:string,
     *   size_bytes:int, doc_type:string, source?:string, parent_document_id?:?int,
     *   signature_for_id?:?int, uploaded_by:?int, scope?:string, owner_user_id?:?int
     * } $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, folder_id, title, description, original_name, filename,
                 sha256, mime_type, size_bytes, doc_type, source, parent_document_id,
                 signature_for_id, uploaded_by, scope, owner_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $scope = $d['scope'] ?? 'company';
        $stmt->execute([
            $d['supplier_id'],
            $d['folder_id'] ?? null,
            $d['title'],
            $d['description'] ?? null,
            $d['original_name'],
            $d['filename'],
            $d['sha256'],
            $d['mime_type'],
            $d['size_bytes'],
            $d['doc_type'],
            $d['source'] ?? 'manual',
            $d['parent_document_id'] ?? null,
            $d['signature_for_id'] ?? null,
            $d['uploaded_by'] ?? null,
            $scope,
            $scope === 'user' ? ($d['owner_user_id'] ?? null) : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id, int $supplierId, DocumentViewerContext $viewer, bool $includeTrashed = false): ?array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $sql = 'SELECT * FROM documents WHERE id = ? AND supplier_id = ?'
             . ($includeTrashed ? '' : ' AND deleted_at IS NULL')
             . $scopeSql;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$id, $supplierId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return array{text_status:string,total_chars:int,content_text:?string}|null */
    public function findExtractedText(
        int $id,
        int $supplierId,
        DocumentViewerContext $viewer,
        int $offset,
        int $maxChars,
    ): ?array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $stmt = $this->db->pdo()->prepare(
            'SELECT text_status,
                    COALESCE(CHAR_LENGTH(content_text), 0) AS total_chars,
                    SUBSTRING(content_text, ?, ?) AS content_text
               FROM documents
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
            . $scopeSql
        );
        $stmt->execute(array_merge([$offset + 1, $maxChars, $id, $supplierId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'text_status' => (string) $row['text_status'],
            'total_chars' => (int) $row['total_chars'],
            'content_text' => $row['content_text'] !== null ? (string) $row['content_text'] : null,
        ];
    }

    /** Surový řádek (vč. filename/sha) — pro download/preview. */
    public function findRaw(int $id, int $supplierId, DocumentViewerContext $viewer, bool $includeTrashed = false): ?array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $sql = 'SELECT * FROM documents WHERE id = ? AND supplier_id = ?'
             . ($includeTrashed ? '' : ' AND deleted_at IS NULL')
             . $scopeSql;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$id, $supplierId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return array{id:int,sha256:string}|null */
    public function findActiveReferenceForUpdate(
        int $id,
        int $supplierId,
        DocumentViewerContext $viewer,
    ): ?array {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sha256
               FROM documents
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
            . $scopeSql
            . ' FOR UPDATE'
        );
        $stmt->execute(array_merge([$id, $supplierId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $rowId = $row['id'] ?? null;
        $sha256 = $row['sha256'] ?? null;
        if (
            (!is_int($rowId) && !(is_string($rowId) && ctype_digit($rowId)))
            || !is_string($sha256)
        ) {
            return null;
        }
        return [
            'id' => (int) $rowId,
            'sha256' => $sha256,
        ];
    }

    /**
     * WHERE fragment (bez SELECT/ORDER/LIMIT) pro aktivní dokumenty ve složce — sdílené
     * mezi {@see listInFolder()} a {@see countInFolder()}, aby COUNT a data dotaz vždy
     * viděly STEJNOU sadu řádků.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function folderWhere(
        int $supplierId,
        ?int $folderId,
        DocumentViewerContext $viewer,
        ?string $docType,
        ?string $scopeFilter,
        ?int $ownerFilter,
    ): array {
        $sql = 'FROM documents
                 WHERE supplier_id = ? AND deleted_at IS NULL AND parent_document_id IS NULL
                   AND ' . ($folderId === null ? 'folder_id IS NULL' : 'folder_id = ?');
        $params = $folderId === null ? [$supplierId] : [$supplierId, $folderId];
        if ($docType !== null && $docType !== '') {
            $sql .= ' AND doc_type = ?';
            $params[] = $docType;
        }
        // Aditivní scope FILTR (Epic F7 §6 — Firemní/Osobní taby). NIKDY nerozšiřuje
        // viditelnost — scopeClause() guard se stejně připojí za ním. company → jen
        // firemní; user → jen osobní (guard omezí na vlastní; admin může ownerFilter).
        if ($scopeFilter === 'company') {
            $sql .= " AND scope = 'company'";
        } elseif ($scopeFilter === 'user') {
            $sql .= " AND scope = 'user'";
            if ($ownerFilter !== null && $ownerFilter > 0) {
                $sql .= ' AND owner_user_id = ?';
                $params[] = $ownerFilter;
            }
        }
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $sql .= $scopeSql;
        $params = array_merge($params, $scopeParams);
        return [$sql, $params];
    }

    /**
     * Aktivní dokumenty ve složce (top-level — ne přílohy ZFO). Stránkované —
     * $limit/$offset se inlinují jako validované inty (vzor {@see search()}).
     * @return list<array<string,mixed>>
     */
    public function listInFolder(
        int $supplierId,
        ?int $folderId,
        DocumentViewerContext $viewer,
        ?string $docType = null,
        ?string $scopeFilter = null,
        ?int $ownerFilter = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        [$where, $params] = $this->folderWhere($supplierId, $folderId, $viewer, $docType, $scopeFilter, $ownerFilter);
        $sql = 'SELECT ' . self::COLS . ' ' . $where . ' ORDER BY created_at DESC, id DESC'
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** COUNT(*) se stejnými filtry jako {@see listInFolder()}, bez LIMIT — pro `meta.total`. */
    public function countInFolder(
        int $supplierId,
        ?int $folderId,
        DocumentViewerContext $viewer,
        ?string $docType = null,
        ?string $scopeFilter = null,
        ?int $ownerFilter = null,
    ): int {
        [$where, $params] = $this->folderWhere($supplierId, $folderId, $viewer, $docType, $scopeFilter, $ownerFilter);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Surové řádky aktivních top-level dokumentů v dané sadě složek — pro strukturovaný
     * ZIP export (potřebujeme folder_id k rekonstrukci stromu + sha/filename k souboru).
     *
     * @param int[] $folderIds
     * @return list<array{id:int,folder_id:int,sha256:string,filename:string,original_name:string}>
     */
    public function rawByFolderIds(int $supplierId, array $folderIds, DocumentViewerContext $viewer): array
    {
        $folderIds = array_values(array_filter(array_map('intval', $folderIds), static fn(int $v): bool => $v > 0));
        if ($folderIds === []) return [];
        $in = implode(',', array_fill(0, count($folderIds), '?'));
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, folder_id, sha256, filename, original_name FROM documents
              WHERE supplier_id = ? AND deleted_at IS NULL AND parent_document_id IS NULL
                AND folder_id IN ($in)" . $scopeSql
        );
        $stmt->execute(array_merge([$supplierId], $folderIds, $scopeParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Přílohy ZFO kontejneru. @return list<array<string,mixed>> */
    public function listChildren(int $parentId, int $supplierId, DocumentViewerContext $viewer): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM documents
              WHERE parent_document_id = ? AND supplier_id = ? AND deleted_at IS NULL'
              . $scopeSql . ' ORDER BY id'
        );
        $stmt->execute(array_merge([$parentId, $supplierId], $scopeParams));
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * WHERE fragment (bez SELECT/ORDER/LIMIT) pro koš — sdílené mezi {@see listTrash()}
     * a {@see countTrash()}.
     * @return array{0:string,1:list<mixed>}
     */
    private function trashWhere(int $supplierId, DocumentViewerContext $viewer): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $sql = 'FROM documents
                 WHERE supplier_id = ? AND deleted_at IS NOT NULL AND parent_document_id IS NULL'
             . $scopeSql;
        return [$sql, array_merge([$supplierId], $scopeParams)];
    }

    /** Stránkované — $limit/$offset se inlinují jako validované inty. @return list<array<string,mixed>> */
    public function listTrash(int $supplierId, DocumentViewerContext $viewer, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->trashWhere($supplierId, $viewer);
        $sql = 'SELECT ' . self::COLS . ' ' . $where . ' ORDER BY deleted_at DESC, id DESC'
             . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** COUNT(*) se stejnými filtry jako {@see listTrash()}, bez LIMIT — pro `meta.total`. */
    public function countTrash(int $supplierId, DocumentViewerContext $viewer): int
    {
        [$where, $params] = $this->trashWhere($supplierId, $viewer);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fulltext hledání v metadatech i obsahu (MariaDB FULLTEXT, NATURAL LANGUAGE).
     * Fallback na LIKE pro krátké dotazy (pod min. délkou tokenu fulltextu).
     * @return list<array<string,mixed>>
     */
    public function search(int $supplierId, string $q, DocumentViewerContext $viewer, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') return [];
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);

        // LIMIT inlinujeme jako int (vlastní hodnota) — native prepared statements
        // neumí LIMIT s parametrem typu string. Placeholdery jsou poziční (?),
        // protože MySQL native prepare nepovoluje opakování pojmenovaného placeholderu.
        $lim = max(1, min(500, $limit));
        // Escapuj LIKE wildcardy (%/_) — jinak `q=%%%…` vynutí full scan content_text
        // (slow-query DoS) a nekonzistentní match. MATCH AGAINST params zůstávají raw.
        $like = '%' . addcslashes($q, '%_\\') . '%';

        if (mb_strlen($q) >= 3) {
            $sql = 'SELECT ' . self::COLS . ',
                       (MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE) * 2
                        + MATCH(content_text) AGAINST (? IN NATURAL LANGUAGE MODE)) AS score
                      FROM documents
                     WHERE supplier_id = ? AND deleted_at IS NULL
                       AND (MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE)
                            OR MATCH(content_text) AGAINST (? IN NATURAL LANGUAGE MODE)
                            OR title LIKE ? OR original_name LIKE ?)'
                     . $scopeSql . '
                     ORDER BY score DESC, created_at DESC
                     LIMIT ' . $lim;
            $params = array_merge([$q, $q, $supplierId, $q, $q, $like, $like], $scopeParams);
        } else {
            $sql = 'SELECT ' . self::COLS . ', 0 AS score FROM documents
                     WHERE supplier_id = ? AND deleted_at IS NULL
                       AND (title LIKE ? OR original_name LIKE ? OR description LIKE ?)'
                     . $scopeSql . '
                     ORDER BY created_at DESC
                     LIMIT ' . $lim;
            $params = array_merge([$supplierId, $like, $like, $like], $scopeParams);
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function updateMeta(int $id, int $supplierId, string $title, ?string $description): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET title = ?, description = ?
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$title, $description, $id, $supplierId]);
        return $stmt->rowCount() >= 0;
    }

    public function setText(int $id, ?string $text, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET content_text = ?, text_status = ? WHERE id = ?'
        );
        $stmt->execute([$text, $status, $id]);
    }

    public function setSignatureFor(int $id, int $signedDocumentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET signature_for_id = ? WHERE id = ?'
        );
        $stmt->execute([$signedDocumentId, $id]);
    }

    public function setThumb(int $id, ?string $thumbPath, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET thumb_path = ?, thumb_status = ? WHERE id = ?'
        );
        $stmt->execute([$thumbPath, $status, $id]);
    }

    public function move(int $id, int $supplierId, ?int $folderId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET folder_id = ?
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL AND parent_document_id IS NULL'
        );
        $stmt->execute([$folderId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Soft-delete dokumentu + jeho příloh (ZFO děti). */
    public function softDelete(int $id, int $supplierId, ?int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
              WHERE supplier_id = ? AND deleted_at IS NULL AND (id = ? OR parent_document_id = ?)'
        );
        $stmt->execute([$userId, $supplierId, $id, $id]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = NULL, deleted_by = NULL
              WHERE supplier_id = ? AND (id = ? OR parent_document_id = ?)'
        );
        $stmt->execute([$supplierId, $id, $id]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array{id:int,sha256:string,filename:string,thumb_path:?string}> */
    public function privacyPurgeRows(int $supplierId, int $rootId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE document_tree AS (
                SELECT id, parent_document_id
                  FROM documents
                 WHERE supplier_id = ? AND id = ?
                UNION ALL
                SELECT child.id, child.parent_document_id
                  FROM documents child
                  JOIN document_tree parent ON child.parent_document_id = parent.id
                 WHERE child.supplier_id = ?
             )
             SELECT document.id, document.sha256, document.filename, document.thumb_path
               FROM documents document
               JOIN document_tree tree ON tree.id = document.id
              WHERE document.supplier_id = ?
              ORDER BY document.id'
        );
        $stmt->execute([$supplierId, $rootId, $supplierId, $supplierId]);
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'sha256' => (string) $row['sha256'],
                'filename' => (string) $row['filename'],
                'thumb_path' => $row['thumb_path'] !== null ? (string) $row['thumb_path'] : null,
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Surové řádky v koši (vč. filename/sha) — pro fyzické mazání. Scope-aware:
     * non-admin vysype jen company + vlastní user doklady (nesmí odpojit cizí user bajty).
     * @return list<array<string,mixed>>
     */
    public function listTrashedRaw(int $supplierId, DocumentViewerContext $viewer): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, title, folder_id, sha256, filename, thumb_path FROM documents
              WHERE supplier_id = ? AND deleted_at IS NOT NULL' . $scopeSql
        );
        $stmt->execute(array_merge([$supplierId], $scopeParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Tvrdé smazání VYJMENOVANÝCH dokladů v koši.
     *
     * Dřív to byl jeden hromadný `DELETE ... WHERE deleted_at IS NOT NULL`. Jenže
     * mzdový modul přidal na `documents` cizí klíče RESTRICT, takže jediný navázaný
     * doklad shodil celý příkaz — koš pak nešlo vysypat vůbec nikdy a uživatel
     * neměl jak zjistit, který doklad to způsobil. Proto se maže po dávkách a dávka,
     * která narazí na cizí klíč, se dojede po jednom: co jde, zmizí, a co nejde,
     * zůstane v koši a volající o něm řekne.
     *
     * Návratová hodnota se ZÁMĚRNĚ neopírá o `rowCount()` — `documents.parent_document_id`
     * kaskáduje, takže smazání rodiče odstraní i potomka a součet řádků by lhal.
     * Pravdu říká až kontrola, které id v tabulce zbyla.
     *
     * @param list<int> $ids
     * @return list<int> id dokladů, které v tabulce zůstaly (blokované nebo souběh)
     */
    public function hardDeleteTrashedByIds(int $supplierId, DocumentViewerContext $viewer, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        foreach (array_chunk($ids, 200) as $chunk) {
            try {
                $this->deleteTrashedChunk($supplierId, $viewer, $chunk);
            } catch (\PDOException $e) {
                if (!ForeignKeyDeletionGuard::isForeignKeyViolation($e)) {
                    throw $e;
                }
                foreach ($chunk as $id) {
                    try {
                        $this->deleteTrashedChunk($supplierId, $viewer, [$id]);
                    } catch (\PDOException $inner) {
                        if (!ForeignKeyDeletionGuard::isForeignKeyViolation($inner)) {
                            throw $inner;
                        }
                    }
                }
            }
        }

        return $this->existingIds($supplierId, $ids);
    }

    /** @param list<int> $ids */
    private function deleteTrashedChunk(int $supplierId, DocumentViewerContext $viewer, array $ids): void
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM documents WHERE supplier_id = ? AND deleted_at IS NOT NULL AND id IN ({$placeholders})"
            . $scopeSql
        );
        $stmt->execute(array_merge([$supplierId], $ids, $scopeParams));
    }

    /**
     * Která z daných id v tabulce pořád jsou — jediný spolehlivý způsob, jak po
     * mazání s kaskádou zjistit, co reálně zmizelo.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function existingIds(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM documents WHERE supplier_id = ? AND id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $found[] = (int) $id;
            }
        }

        return $found;
    }

    /**
     * Ref-counting (Epic F7, §4.4): union přes documents + document_files a neměnné
     * originály klientských podání, protože všechny sdílejí `sup-{id}` content-addressed
     * sha strom. Bajt se odpojí
     * teprve při součtu 0 — smazání jednoho document_files řádku NESMÍ odpojit bajt
     * referencovaný jiným řádkem/dokumentem. Union je supplier-scoped (NE per-user):
     * bajty jsou sdílené v rámci tenanta bez ohledu na scope company/user.
     * `journal_entry_attachments` mají VLASTNÍ disk namespace → NEspadají do union.
     *
     * Počítáme VŠECHNY řádky (i soft-deleted/v koši), NE jen deleted_at IS NULL:
     * hard-deleted řádky už v tabulce nejsou, takže každý zbylý řádek — smazaný v koši
     * nebo aktivní — je živá reference na bajt, dokud i on není fyzicky smazán. Volající
     * před hard-delete vylučuje právě mazaný řádek přes $excludeIds/$excludeFileIds.
     * (Původní `deleted_at IS NULL` filtr odpojoval sdílený bajt, na který ještě ukazoval
     * cizí doklad v koši → restore 404.)
     *
     * @param list<int> $excludeIds     documents.id právě mazaných řádků
     * @param list<int> $excludeFileIds document_files.id právě mazaných řádků
     */
    public function countBySha(int $supplierId, string $sha256, array $excludeIds = [], array $excludeFileIds = []): int
    {
        $sql1 = 'SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND sha256 = ?';
        $params = [$supplierId, $sha256];
        if ($excludeIds !== []) {
            $in = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql1 .= " AND id NOT IN ($in)";
            $params = array_merge($params, array_map('intval', $excludeIds));
        }

        // document_files větev POČÍTÁ jen aktivní řádky (deleted_at IS NULL): remove() je
        // tvrdý záměr bez restore cesty (L2), takže soft-deleted file řádek už bajt nedrží.
        // (documents větev naopak drží i trashed řádky kvůli restore z koše — Commit-3 fix.)
        $sql2 = 'SELECT COUNT(*) FROM document_files WHERE supplier_id = ? AND sha256 = ? AND deleted_at IS NULL';
        $params[] = $supplierId;
        $params[] = $sha256;
        if ($excludeFileIds !== []) {
            $in = implode(',', array_fill(0, count($excludeFileIds), '?'));
            $sql2 .= " AND id NOT IN ($in)";
            $params = array_merge($params, array_map('intval', $excludeFileIds));
        }

        // stock_media větev (Epic ESHOP) — produktová média sdílejí týž content-addressed
        // sha pool (storage_key = sha256). Bez tohoto by DMS mazání odpojilo bajt, na který
        // ještě ukazuje obrázek produktu (tichá ztráta dat). ESHOP nemá koš — počítá se vždy.
        $sql3 = 'SELECT COUNT(*) FROM stock_media WHERE supplier_id = ? AND storage_key = ?';
        $params[] = $supplierId;
        $params[] = $sha256;

        // Staging podání drží svůj původní SHA nezávisle na aktuálním primary souboru
        // DMS dokumentu. Účetní tak může k dokumentu spravovat přílohy, ale původní
        // klientský originál se nesmí orphan-cleanupem fyzicky odpojit.
        $sql4 = 'SELECT COUNT(*) FROM purchase_invoice_submissions WHERE supplier_id = ? AND document_sha256 = ?';
        $params[] = $supplierId;
        $params[] = $sha256;

        $stmt = $this->db->pdo()->prepare("SELECT ($sql1) + ($sql2) + ($sql3) + ($sql4)");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Dokumenty s daným tagem (globálně přes všechny složky). @return list<array<string,mixed>> */
    public function listByTag(int $supplierId, string $tag, DocumentViewerContext $viewer): array
    {
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer, 'd');
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.* FROM documents d
                JOIN document_tag_map m ON m.document_id = d.id
                JOIN document_tags t ON t.id = m.tag_id
              WHERE d.supplier_id = ? AND d.deleted_at IS NULL AND d.parent_document_id IS NULL
                AND t.name = ?' . $scopeSql . '
              ORDER BY d.title'
        );
        $stmt->execute(array_merge([$supplierId, $tag], $scopeParams));
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Dokumenty navázané na entitu (oboustranné provázání). @return list<array<string,mixed>> */
    public function listByEntity(int $supplierId, string $entityType, int $entityId, DocumentViewerContext $viewer): array
    {
        // d.* (ne self::COLS) — join s document_links zavádí sloupec created_at i tam,
        // takže nekvalifikované názvy by byly nejednoznačné. hydrate() si vybere potřebné.
        [$scopeSql, $scopeParams] = $this->scopeClause($viewer, 'd');
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.* FROM documents d
                JOIN document_links l ON l.document_id = d.id
              WHERE d.supplier_id = ? AND d.deleted_at IS NULL
                AND l.entity_type = ? AND l.entity_id = ?' . $scopeSql . '
              ORDER BY d.created_at DESC'
        );
        $stmt->execute(array_merge([$supplierId, $entityType, $entityId], $scopeParams));
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): array
    {
        return [
            'id'                 => (int) $r['id'],
            'supplier_id'        => (int) $r['supplier_id'],
            'folder_id'          => $r['folder_id'] !== null ? (int) $r['folder_id'] : null,
            'title'              => (string) $r['title'],
            'description'        => $r['description'] !== null ? (string) $r['description'] : null,
            'original_name'      => (string) $r['original_name'],
            'filename'           => (string) $r['filename'],
            'sha256'             => (string) $r['sha256'],
            'mime_type'          => (string) $r['mime_type'],
            'size_bytes'         => (int) $r['size_bytes'],
            'doc_type'           => (string) $r['doc_type'],
            'source'             => (string) $r['source'],
            'parent_document_id' => $r['parent_document_id'] !== null ? (int) $r['parent_document_id'] : null,
            'signature_for_id'   => $r['signature_for_id'] !== null ? (int) $r['signature_for_id'] : null,
            'text_status'        => (string) $r['text_status'],
            'thumb_status'       => (string) $r['thumb_status'],
            'has_thumb'          => ($r['thumb_status'] ?? '') === 'generated',
            'uploaded_by'        => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'scope'              => (string) ($r['scope'] ?? 'company'),
            'owner_user_id'      => isset($r['owner_user_id']) && $r['owner_user_id'] !== null ? (int) $r['owner_user_id'] : null,
            'deleted_at'         => $r['deleted_at'] !== null ? (string) $r['deleted_at'] : null,
            'created_at'         => (string) $r['created_at'],
        ];
    }
}
