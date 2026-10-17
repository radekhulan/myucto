<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

/**
 * Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07).
 *
 * Tenant izolace: KAŽDÁ metoda, která čte/mění konkrétní řádek, filtruje
 * supplier_id = ? (kritické — role client je scoped na jednu firmu).
 */
final class DocumentRequestRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param string[] $statuses prázdné = všechny stavy
     * @return array<int,array<string,mixed>>
     */
    public function listForSupplier(int $supplierId, array $statuses = []): array
    {
        $sql = "SELECT dr.*, pi.vendor_invoice_number AS pi_vendor_invoice_number,
                       pi.status AS pi_status, c.company_name AS pi_vendor_name,
                       sub.status AS submission_status, sub.status_reason AS submission_status_reason,
                       d.original_name AS submission_original_name,
                       CASE WHEN bs.id IS NOT NULL THEN bt.amount ELSE NULL END AS bank_tx_amount,
                       CASE WHEN bs.id IS NOT NULL THEN bt.posted_at ELSE NULL END AS bank_tx_posted_at,
                       cu.name AS created_by_name, ru.name AS resolved_by_name
                  FROM document_requests dr
                  LEFT JOIN purchase_invoices pi
                         ON pi.id = dr.purchase_invoice_id AND pi.supplier_id = dr.supplier_id
                  LEFT JOIN purchase_invoice_submissions sub
                         ON sub.id = dr.submission_id AND sub.supplier_id = dr.supplier_id
                  LEFT JOIN documents d
                         ON d.id = sub.document_id AND d.supplier_id = dr.supplier_id
                  LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = dr.supplier_id
                  LEFT JOIN bank_transactions bt ON bt.id = dr.bank_transaction_id
                  LEFT JOIN bank_statements bs
                         ON bs.id = bt.statement_id AND bs.supplier_id = dr.supplier_id
                  LEFT JOIN users cu ON cu.id = dr.created_by
                  LEFT JOIN users ru ON ru.id = dr.resolved_by
                 WHERE dr.supplier_id = ?";
        $params = [$supplierId];
        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND dr.status IN ($placeholders)";
            array_push($params, ...$statuses);
        }
        $sql .= " ORDER BY (dr.status = 'requested') DESC, dr.deadline IS NULL, dr.deadline ASC, dr.created_at DESC";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dr.*, pi.vendor_invoice_number AS pi_vendor_invoice_number,
                    pi.status AS pi_status, sub.status AS submission_status,
                    sub.status_reason AS submission_status_reason,
                    d.original_name AS submission_original_name
               FROM document_requests dr
               LEFT JOIN purchase_invoices pi
                      ON pi.id = dr.purchase_invoice_id AND pi.supplier_id = dr.supplier_id
               LEFT JOIN purchase_invoice_submissions sub
                      ON sub.id = dr.submission_id AND sub.supplier_id = dr.supplier_id
               LEFT JOIN documents d
                      ON d.id = sub.document_id AND d.supplier_id = dr.supplier_id
              WHERE dr.id = ? AND dr.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array{description:string,amount:?float,context_date:?string,deadline:?string,bank_transaction_id:?int} $data
     */
    public function create(int $supplierId, array $data, ?int $createdBy): int
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, isset($data['bank_transaction_id']) ? (int) $data['bank_transaction_id'] : null, fn () => $this->createMutable($supplierId, $data, $createdBy));
    }

    private function createMutable(int $supplierId, array $data, ?int $createdBy): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO document_requests
                (supplier_id, description, amount, context_date, deadline, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId,
            $data['description'],
            $data['amount'] ?? null,
            $data['context_date'] ?? null,
            $data['deadline'] ?? null,
            $data['bank_transaction_id'] ?? null,
            $createdBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Klient nahrál soubor → status uploaded + vazba na vzniklý doklad. Vrací false, pokud request neexistuje/nepatří firmě. */
    public function markUploaded(int $id, int $supplierId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'uploaded', purchase_invoice_id = ?
              WHERE id = ? AND supplier_id = ? AND status <> 'resolved'"
        );
        $stmt->execute([$purchaseInvoiceId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Klient předal originál do staging fronty; účetní doklad zatím nevzniká. */
    public function markSubmitted(int $id, int $supplierId, int $submissionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'uploaded', submission_id = ?, purchase_invoice_id = NULL
              WHERE id = ? AND supplier_id = ? AND status = 'requested'"
        );
        $stmt->execute([$submissionId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Doplní výslednou fakturu všem požadavkům, které ukazují na dané podání. */
    public function markProcessedBySubmission(int $submissionId, int $supplierId, int $purchaseInvoiceId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'uploaded', purchase_invoice_id = ?
              WHERE submission_id = ? AND supplier_id = ? AND status <> 'resolved'"
        );
        $stmt->execute([$purchaseInvoiceId, $submissionId, $supplierId]);
    }

    /** Přesměruje otevřený pull požadavek na nový originál v řetězci náhrad. */
    public function replaceSubmissionReference(
        int $oldSubmissionId,
        int $newSubmissionId,
        int $supplierId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET submission_id = ?, purchase_invoice_id = NULL
              WHERE submission_id = ? AND supplier_id = ? AND status <> 'resolved'"
        );
        $stmt->execute([$newSubmissionId, $oldSubmissionId, $supplierId]);
        return $stmt->rowCount();
    }

    public function reopenBySubmission(int $submissionId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'requested', submission_id = NULL
              WHERE submission_id = ? AND supplier_id = ? AND status = 'uploaded' AND purchase_invoice_id IS NULL"
        );
        $stmt->execute([$submissionId, $supplierId]);
        return $stmt->rowCount();
    }

    public function resolve(int $id, int $supplierId, ?int $resolvedBy): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'resolved', resolved_by = ?, resolved_at = NOW()
              WHERE id = ? AND supplier_id = ? AND status <> 'resolved'"
        );
        $stmt->execute([$resolvedBy, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Vrátí uploaded/resolved zpět na requested (chybný upload) — nesmaže purchase_invoice, jen odváže. */
    public function reopen(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE document_requests
                SET status = 'requested', purchase_invoice_id = NULL, submission_id = NULL,
                    resolved_by = NULL, resolved_at = NULL
              WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM document_requests WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Badge pro dashboard (obě strany): otevřené (requested+uploaded) a po termínu. */
    public function openCounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN status IN ('requested','uploaded') THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN status = 'requested' AND deadline IS NOT NULL AND deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue
               FROM document_requests
              WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['open' => 0, 'overdue' => 0];
        return [
            'open'    => (int) ($row['open'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }

    /**
     * Kandidáti na e-mailovou urgenci (cron): status='requested', starší než $days dní
     * od založení (nebo poslední urgence), cooldown $cooldownDays mezi urgencemi.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForReminder(int $days, int $cooldownDays): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dr.*, s.company_name AS supplier_name, s.display_name AS supplier_display_name
               FROM document_requests dr
               JOIN supplier s ON s.id = dr.supplier_id
              WHERE dr.status = 'requested'
                AND dr.created_at < (NOW() - INTERVAL ? DAY)
                AND (dr.last_reminder_at IS NULL OR dr.last_reminder_at < (NOW() - INTERVAL ? DAY))
              ORDER BY dr.created_at ASC"
        );
        $stmt->execute([$days, $cooldownDays]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] e-maily portálových uživatelů (role client) přiřazených k firmě */
    public function clientRecipientEmails(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT u.email
               FROM users u
               JOIN user_suppliers us ON us.user_id = u.id
               JOIN roles r ON r.id = COALESCE(us.role_id, u.role_id)
              WHERE us.supplier_id = ? AND r.role_type = 'client'
                AND r.is_active = 1 AND u.is_active = 1"
        );
        $stmt->execute([$supplierId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function bumpReminder(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_requests SET last_reminder_at = NOW(), reminder_count = reminder_count + 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }
}
