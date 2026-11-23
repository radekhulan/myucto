<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Schvalování přijatých dokladů manažerem střediska (`purchase_invoice_approvals`,
 * migrace 1959). Jen zápis a čtení; pravidla drží
 * {@see \MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService}.
 *
 * Každý dotaz nese `supplier_id` — výjimkou je jen vyhledání podle hashe tokenu
 * z veřejného odkazu, kde firmu teprve zjišťujeme (256 bitů entropie tokenu je
 * sama o sobě autorizace, tenantovou doménu ověří akce přes PublicTenantGuard).
 */
final class PurchaseInvoiceApprovalRepository
{
    private const ROW_SELECT = "SELECT a.id, a.supplier_id, a.purchase_invoice_id, a.dimension_value_id, a.approver_user_id,
                a.round, a.status, a.amount_czk, a.token_expires_at, a.requested_at, a.requested_by,
                a.decided_at, a.decided_via, a.comment, a.reminders_sent, a.last_reminder_at,
                v.code AS dimension_value_code, v.name AS dimension_value_name, v.semantic_key AS dimension_value_semantic_key,
                t.id AS dimension_type_id, t.name AS dimension_type_name,
                u.name AS approver_name, u.email AS approver_email,
                pi.varsymbol AS invoice_varsymbol, pi.vendor_invoice_number AS invoice_vendor_number,
                pi.issue_date AS invoice_issue_date, pi.tax_date AS invoice_tax_date, pi.due_date AS invoice_due_date,
                pi.total_without_vat AS invoice_total_without_vat, pi.total_vat AS invoice_total_vat,
                pi.total_with_vat AS invoice_total_with_vat,
                pi.status AS invoice_status, pi.approval_status AS invoice_approval_status,
                pi.pdf_path AS invoice_pdf_path, pi.vendor_snapshot AS invoice_vendor_snapshot,
                cur.code AS invoice_currency,
                c.company_name AS invoice_vendor_name, c.ic AS invoice_vendor_ic
           FROM purchase_invoice_approvals a
           JOIN purchase_invoices pi ON pi.id = a.purchase_invoice_id AND pi.supplier_id = a.supplier_id
           JOIN currencies cur ON cur.id = pi.currency_id
           JOIN clients c ON c.id = pi.vendor_id
           JOIN dimension_values v ON v.id = a.dimension_value_id
           JOIN dimension_types t ON t.id = v.type_id
           JOIN users u ON u.id = a.approver_user_id";

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::ROW_SELECT . ' WHERE a.id = ? AND a.supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Vyhledání podle SHA-256 tokenu z e-mailu. Porovnání hashe běží v indexu
     * (rovnost hashů neprozrazuje nic o tokenu); akce navíc ověří hash_equals.
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $sql = str_replace(' FROM purchase_invoice_approvals a', ', a.token_hash FROM purchase_invoice_approvals a', self::ROW_SELECT)
            . ' WHERE a.token_hash = ? AND a.supplier_id = pi.supplier_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> všechna kola dokladu, nejnovější první */
    public function forInvoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::ROW_SELECT . ' WHERE a.supplier_id = ? AND a.purchase_invoice_id = ? ORDER BY a.round DESC, a.id DESC'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Schránka schvalovatele / přehled účetní.
     *
     * @param 'pending'|'decided' $status
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function inbox(int $supplierId, ?int $approverUserId, string $status, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [$supplierId];
        if ($approverUserId !== null) {
            $where[] = 'a.approver_user_id = ?';
            $params[] = $approverUserId;
        }
        $where[] = $status === 'pending' ? "a.status = 'pending'" : "a.status IN ('approved','rejected')";
        $order = $status === 'pending' ? 'a.requested_at ASC, a.id ASC' : 'a.decided_at DESC, a.id DESC';
        $sql = str_replace('SELECT a.id,', 'SELECT COUNT(*) OVER() AS total_rows, a.id,', self::ROW_SELECT)
            . ' WHERE a.supplier_id = ? AND ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = $rows === [] ? 0 : (int) $rows[0]['total_rows'];
        foreach ($rows as &$r) {
            unset($r['total_rows']);
        }
        unset($r);
        if ($rows === [] && $page > 1) {
            $count = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM purchase_invoice_approvals a WHERE a.supplier_id = ? AND ' . implode(' AND ', $where)
            );
            $count->execute($params);
            $total = (int) $count->fetchColumn();
        }
        return ['rows' => $rows, 'total' => $total];
    }

    public function pendingCountForApprover(int $supplierId, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoice_approvals
              WHERE supplier_id = ? AND approver_user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$supplierId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> čekající schválení uživatele (akční položky) */
    public function pendingIdsForApprover(int $supplierId, int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM purchase_invoice_approvals
              WHERE supplier_id = ? AND approver_user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$supplierId, $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> zamítnuté koncepty (akční položka účetní) */
    public function rejectedInvoiceIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND approval_status = 'rejected' AND status = 'draft'"
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function isApproverOf(int $supplierId, int $userId, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoice_approvals
              WHERE supplier_id = ? AND approver_user_id = ? AND purchase_invoice_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $userId, $invoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    public function invoiceExists(int $supplierId, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function maxRound(int $supplierId, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(round), 0) FROM purchase_invoice_approvals WHERE supplier_id = ? AND purchase_invoice_id = ?'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    public function create(
        int $supplierId,
        int $invoiceId,
        int $valueId,
        int $approverUserId,
        int $round,
        float $amountCzk,
        string $tokenHash,
        string $tokenExpiresAt,
        ?int $requestedBy,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_approvals
                (supplier_id, purchase_invoice_id, dimension_value_id, approver_user_id, round, status,
                 amount_czk, token_hash, token_expires_at, requested_at, requested_by)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, ?, NOW(), ?)'
        )->execute([
            $supplierId, $invoiceId, $valueId, $approverUserId, $round,
            round($amountCzk, 2), $tokenHash, $tokenExpiresAt, $requestedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Rozhodnutí — atomicky jen nad čekajícím řádkem. Souběžné kliknutí v aplikaci
     * a v e-mailu tak rozhodne právě jednou; druhý dostane false.
     */
    public function decideIfPending(int $supplierId, int $id, string $status, string $via, ?string $comment): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_approvals
                SET status = ?, decided_at = NOW(), decided_via = ?, comment = ?
              WHERE id = ? AND supplier_id = ? AND status = 'pending'"
        );
        $stmt->execute([$status, $via, $comment, $id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Zruší čekající schválení dokladu (vše, nebo jen vybraná id).
     *
     * @param list<int>|null $ids
     */
    public function cancelPending(int $supplierId, int $invoiceId, ?array $ids = null): int
    {
        $sql = "UPDATE purchase_invoice_approvals SET status = 'cancelled', decided_at = NOW()
                 WHERE supplier_id = ? AND purchase_invoice_id = ? AND status = 'pending'";
        $params = [$supplierId, $invoiceId];
        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function rotateToken(int $supplierId, int $id, string $tokenHash, string $expiresAt, bool $isReminder): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_invoice_approvals
                SET token_hash = ?, token_expires_at = ?'
                . ($isReminder ? ', reminders_sent = reminders_sent + 1, last_reminder_at = NOW()' : '') . "
              WHERE id = ? AND supplier_id = ? AND status = 'pending'"
        );
        $stmt->execute([$tokenHash, $expiresAt, $id, $supplierId]);
        return $stmt->rowCount() === 1;
    }

    public function setInvoiceStatus(int $supplierId, int $invoiceId, string $approvalStatus): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET approval_status = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$approvalStatus, $invoiceId, $supplierId]);
    }

    public function pendingCount(int $supplierId, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoice_approvals
              WHERE supplier_id = ? AND purchase_invoice_id = ? AND status = 'pending'"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Kandidáti připomínky napříč firmami (cron): čekající, poslední kontakt starší
     * než N dní, pod limitem připomínek.
     *
     * @return list<array{id:int, supplier_id:int}>
     */
    public function reminderCandidates(int $minDays, int $maxReminders): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id, a.supplier_id
               FROM purchase_invoice_approvals a
               JOIN purchase_invoices pi ON pi.id = a.purchase_invoice_id AND pi.supplier_id = a.supplier_id
              WHERE a.status = 'pending' AND pi.status = 'draft'
                AND a.reminders_sent < ?
                AND COALESCE(a.last_reminder_at, a.requested_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY a.supplier_id, a.id"
        );
        $stmt->execute([$maxReminders, $minDays]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
