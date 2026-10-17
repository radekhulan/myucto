<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

/**
 * Staging fronta dokladů před vznikem přijaté faktury.
 *
 * Tenant filtr je součástí každého čtení i zápisu. Řádek ve frontě je auditní
 * obálka nad neměnným DMS originálem; účetní entity vznikají až metodou complete().
 */
final class PurchaseInvoiceSubmissionRepository
{
    private const SELECT = <<<'SQL'
        SELECT s.*,
               d.original_name, d.mime_type, d.doc_type, d.size_bytes,
               d.filename AS document_filename, d.thumb_status,
               submitter.name AS submitted_by_name,
               processor.name AS processed_by_name,
               pi.vendor_invoice_number, pi.varsymbol AS purchase_invoice_varsymbol,
               vendor.company_name AS vendor_name,
               (SELECT COUNT(*)
                  FROM document_requests dr
                 WHERE dr.submission_id = s.id AND dr.supplier_id = s.supplier_id) AS request_count,
               (SELECT COUNT(*) FROM document_links dl
                  JOIN documents linked ON linked.id = dl.document_id AND linked.supplier_id = dl.supplier_id
                 WHERE dl.supplier_id = s.supplier_id
                   AND (linked.id = s.document_id OR linked.parent_document_id = s.document_id)) AS document_link_count,
               (SELECT COUNT(*) FROM document_requests resolved
                 WHERE resolved.submission_id = s.id AND resolved.supplier_id = s.supplier_id
                   AND resolved.status = 'resolved') AS resolved_request_count,
               (SELECT replacement.id
                  FROM purchase_invoice_submissions replacement
                 WHERE replacement.supersedes_submission_id = s.id
                   AND replacement.supplier_id = s.supplier_id
                 ORDER BY replacement.id DESC
                 LIMIT 1) AS replacement_submission_id
          FROM purchase_invoice_submissions s
          JOIN documents d ON d.id = s.document_id AND d.supplier_id = s.supplier_id
        LEFT JOIN users submitter ON submitter.id = s.submitted_by
        LEFT JOIN users processor ON processor.id = s.processed_by
        LEFT JOIN purchase_invoices pi
               ON pi.id = s.purchase_invoice_id AND pi.supplier_id = s.supplier_id
        LEFT JOIN clients vendor ON vendor.id = pi.vendor_id AND vendor.supplier_id = s.supplier_id
        SQL;

    public function __construct(private readonly Connection $db) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function paginate(int $supplierId, ?string $status, int $limit, int $offset): array
    {
        $where = ' WHERE s.supplier_id = ?';
        $params = [$supplierId];
        if ($status !== null && $status !== '') {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . $where
            . " ORDER BY FIELD(s.status, 'submitted','processing','needs_information','processed','rejected'),"
            . ' s.created_at DESC, s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoice_submissions s' . $where);
        $count->execute($params);
        return [
            'items' => array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE s.id = ? AND s.supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByHash(int $supplierId, string $sha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            self::SELECT . ' WHERE s.supplier_id = ? AND s.document_sha256 = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array{document_id:int,document_sha256:string,submitted_by:?int,submitted_via:string,
     *   note:?string,document_kind_hint:?string,bank_transaction_id:?int,supersedes_submission_id:?int} $data
     */
    public function create(int $supplierId, array $data): int
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, isset($data['bank_transaction_id']) ? (int) $data['bank_transaction_id'] : null, fn () => $this->createMutable($supplierId, $data));
    }

    private function createMutable(int $supplierId, array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_submissions
                (supplier_id, document_id, document_sha256, submitted_by, submitted_via,
                 note, document_kind_hint, bank_transaction_id, supersedes_submission_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $data['document_id'],
            $data['document_sha256'],
            $data['submitted_by'] ?? null,
            $data['submitted_via'],
            $data['note'] ?? null,
            $data['document_kind_hint'] ?? null,
            $data['bank_transaction_id'] ?? null,
            $data['supersedes_submission_id'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Atomicky převezme čekající podání. Stará `processing` lze po 30 minutách
     * převzít znovu, aby pád workeru nezanechal frontu trvale zaseknutou.
     */
    public function claimForExtraction(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'processing', extraction_status = 'running', extraction_error = NULL,
                    processing_started_at = NOW(), status_reason = NULL
              WHERE id = ? AND supplier_id = ?
                AND (status = 'submitted'
                     OR (status = 'processing' AND processing_started_at < NOW() - INTERVAL 30 MINUTE))"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    /** Ruční přepis se přebírá až ve stejné transakci, ve které vzniká draft. */
    public function claimForManual(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'processing', processing_started_at = NOW(), status_reason = NULL
              WHERE id = ? AND supplier_id = ? AND status = 'submitted'"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function extractionFailed(int $id, int $supplierId, string $source, string $error): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'submitted', extraction_status = 'failed', extraction_source = ?,
                    extraction_error = ?, processing_started_at = NULL
              WHERE id = ? AND supplier_id = ? AND status = 'processing'"
        );
        $stmt->execute([$source, mb_substr($error, 0, 8000), $id, $supplierId]);
    }

    /** Odběratel, kterého na dokladu našel ISDOC nebo AI; podle něj fronta nabízí přesun. */
    public function recordDetectedBuyer(int $id, int $supplierId, ?string $ic, ?string $dic): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoice_submissions
                SET detected_buyer_ic = ?, detected_buyer_dic = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $ic !== null && $ic !== '' ? mb_substr($ic, 0, 20) : null,
            $dic !== null && $dic !== '' ? mb_substr($dic, 0, 20) : null,
            $id,
            $supplierId,
        ]);
    }

    public function complete(
        int $id,
        int $supplierId,
        int $purchaseInvoiceId,
        int $processedBy,
        string $source,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'processed', status_reason = NULL,
                    extraction_status = 'succeeded', extraction_source = ?, extraction_error = NULL,
                    purchase_invoice_id = ?, processed_by = ?, processed_at = NOW(),
                    processing_started_at = NULL
              WHERE id = ? AND supplier_id = ? AND status = 'processing'"
        );
        $stmt->execute([$source, $purchaseInvoiceId, $processedBy, $id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function needsInformation(int $id, int $supplierId, string $reason): bool
    {
        return $this->setReviewStatus($id, $supplierId, 'needs_information', $reason);
    }

    public function reject(int $id, int $supplierId, string $reason): bool
    {
        return $this->setReviewStatus($id, $supplierId, 'rejected', $reason);
    }

    /** @param list<int> $purchaseInvoiceIds @return array<int,bool> */
    public function accountantManagedMap(int $supplierId, array $purchaseInvoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchaseInvoiceIds))));
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT purchase_invoice_id FROM purchase_invoice_submissions
              WHERE supplier_id = ? AND status = 'processed' AND purchase_invoice_id IN ($in)"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) $map[(int) $id] = true;
        return $map;
    }

    public function isAccountantManaged(int $supplierId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM purchase_invoice_submissions
              WHERE supplier_id = ? AND purchase_invoice_id = ? AND status = 'processed' LIMIT 1"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Smazání výsledného konceptu vrátí podání do fronty místo osiřelého stavu
     * `processed` bez faktury. Vrací ID podání pro audit po commitu.
     *
     * @return list<int>
     */
    public function reopenForDeletedInvoice(int $supplierId, int $purchaseInvoiceId): array
    {
        $find = $this->db->pdo()->prepare(
            "SELECT id FROM purchase_invoice_submissions
              WHERE supplier_id = ? AND purchase_invoice_id = ? AND status = 'processed'
              FOR UPDATE"
        );
        $find->execute([$supplierId, $purchaseInvoiceId]);
        $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) return [];

        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'submitted', status_reason = NULL, purchase_invoice_id = NULL,
                    processed_at = NULL, processed_by = NULL, processing_started_at = NULL
              WHERE supplier_id = ? AND purchase_invoice_id = ? AND status = 'processed'"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $ids;
    }

    public function findForUpdate(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE s.id = ? AND s.supplier_id = ? FOR UPDATE');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Vyřadí podání z fronty. Zpracované podání se nemaže — na jeho originálu
     * stojí vazba k existující faktuře; nejdřív musí zmizet ta faktura, což
     * podání vrátí do fronty. Rozdělané `processing` taky ne, doběhlo by do
     * prázdna. Vrací smazaný řádek pro navazující úklid a auditní zápis.
     *
     * @return array<string,mixed>|null
     */
    public function deleteFromQueue(int $id, int $supplierId, ?int $ownerId = null, bool $pendingOnly = false): ?array
    {
        $row = $this->find($id, $supplierId);
        if ($row === null || !in_array((string) $row['status'], ['submitted', 'needs_information', 'rejected'], true)) {
            return null;
        }
        $sql = "DELETE FROM purchase_invoice_submissions
                  WHERE id = ? AND supplier_id = ? AND purchase_invoice_id IS NULL
                    AND status IN ('submitted','needs_information','rejected')
                    AND NOT EXISTS (
                        SELECT 1 FROM document_links dl
                        JOIN documents linked ON linked.id = dl.document_id AND linked.supplier_id = dl.supplier_id
                         WHERE dl.supplier_id = purchase_invoice_submissions.supplier_id
                           AND (linked.id = purchase_invoice_submissions.document_id OR linked.parent_document_id = purchase_invoice_submissions.document_id)
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM document_requests resolved
                         WHERE resolved.supplier_id = purchase_invoice_submissions.supplier_id
                           AND resolved.submission_id = purchase_invoice_submissions.id AND resolved.status = 'resolved'
                    )";
        $params = [$id, $supplierId];
        if ($ownerId !== null) {
            $sql .= ' AND submitted_by = ?';
            $params[] = $ownerId;
        }
        if ($pendingOnly) {
            $sql .= " AND status = 'submitted' AND processing_started_at IS NULL AND extraction_status <> 'running'";
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() === 1 ? $row : null;
    }

    private function setReviewStatus(int $id, int $supplierId, string $status, string $reason): bool
    {
        $allowedStatuses = $status === 'rejected' ? "('submitted','needs_information')" : "('submitted')";
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = ?, status_reason = ?, processing_started_at = NULL,
                    extraction_status = IF(extraction_status = 'running', 'failed', extraction_status)
              WHERE id = ? AND supplier_id = ? AND status IN $allowedStatuses AND purchase_invoice_id IS NULL"
        );
        $stmt->execute([$status, mb_substr(trim($reason), 0, 8000), $id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id','supplier_id','document_id','bank_transaction_id','supersedes_submission_id',
            'submitted_by','purchase_invoice_id','processed_by','size_bytes','request_count',
            'replacement_submission_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int) $row[$key];
        }
        return $row;
    }
}
