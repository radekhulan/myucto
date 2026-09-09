<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro cash_documents + cash_document_vat_lines (mini-epic POKLADNA #14).
 * Per tenant (supplier_id). Číslo dokladu (doc_number) se přiděluje až při post().
 * Zaúčtování/idempotenci drží PostingService; tady jen CRUD + zámek pro post.
 */
final class CashDocumentRepository
{
    private const COLUMNS =
        'id, supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
         partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code,
         fx_rate, amount_foreign, rule_key, counter_account_code, project_id, invoice_id, purchase_invoice_id, invoice_payment_id,
         journal_entry_id, reversal_entry_id, status, created_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * Vloží hlavičku dokladu (bez DPH řádků — ty přes replaceVatLines). Vrací id.
     *
     * @param array<string,mixed> $data
     */
    public function insert(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount,
                 currency_code, fx_rate, amount_foreign, rule_key, counter_account_code, project_id,
                 invoice_id, purchase_invoice_id, status, created_by)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (int) $data['register_id'],
            (string) $data['doc_type'],
            (string) $data['purpose'],
            (string) $data['issue_date'],
            $data['tax_date'] ?? null,
            $data['partner_name'] ?? null,
            $data['partner_ic'] ?? null,
            $data['partner_dic'] ?? null,
            (string) $data['description'],
            (string) $data['vat_mode'],
            (float) $data['total_amount'],
            (string) ($data['currency_code'] ?? 'CZK'),
            (float) ($data['fx_rate'] ?? 1),
            isset($data['amount_foreign']) && $data['amount_foreign'] !== null ? (float) $data['amount_foreign'] : null,
            $data['rule_key'] ?? null,
            $data['counter_account_code'] ?? null,
            isset($data['project_id']) && (int) $data['project_id'] > 0 ? (int) $data['project_id'] : null,
            isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            isset($data['purchase_invoice_id']) ? (int) $data['purchase_invoice_id'] : null,
            (string) ($data['status'] ?? 'draft'),
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Přepíše hlavičku draftu (jen editovatelná pole). Volající ověří status=draft.
     *
     * @param array<string,mixed> $data
     */
    public function updateHeader(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE cash_documents SET
                register_id = ?, doc_type = ?, purpose = ?, issue_date = ?, tax_date = ?,
                partner_name = ?, partner_ic = ?, partner_dic = ?, description = ?, vat_mode = ?,
                total_amount = ?, currency_code = ?, fx_rate = ?, amount_foreign = ?, rule_key = ?, counter_account_code = ?,
                project_id = ?, invoice_id = ?, purchase_invoice_id = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            (int) $data['register_id'],
            (string) $data['doc_type'],
            (string) $data['purpose'],
            (string) $data['issue_date'],
            $data['tax_date'] ?? null,
            $data['partner_name'] ?? null,
            $data['partner_ic'] ?? null,
            $data['partner_dic'] ?? null,
            (string) $data['description'],
            (string) $data['vat_mode'],
            (float) $data['total_amount'],
            (string) ($data['currency_code'] ?? 'CZK'),
            (float) ($data['fx_rate'] ?? 1),
            isset($data['amount_foreign']) && $data['amount_foreign'] !== null ? (float) $data['amount_foreign'] : null,
            $data['rule_key'] ?? null,
            $data['counter_account_code'] ?? null,
            isset($data['project_id']) && (int) $data['project_id'] > 0 ? (int) $data['project_id'] : null,
            isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
            isset($data['purchase_invoice_id']) ? (int) $data['purchase_invoice_id'] : null,
            $id,
            $supplierId,
        ]);
    }

    /**
     * Nahradí DPH rozpad dokladu (smaž + vlož). Prázdné pole = smazat vše (vat_mode=none).
     *
     * M-7: zapisuje i `vat_deduction` / `vat_deduction_percent` / `tax_treatment`
     * (migrace 1120). Bez nich se rozpad při každé úpravě draftu přepsal na default
     * (`full`/100/`deductible`), takže hotovostní nákup byl v daňové evidenci vždy
     * plně daňový s plným nárokem na odpočet a poměrný odpočet nešlo zaznamenat.
     *
     * @param list<array{vat_rate:float, base_amount:float, vat_amount:float, vat_classification_code?:?string, vat_deduction?:string, vat_deduction_percent?:float, tax_treatment?:string, is_fixed_asset?:bool|int}> $lines
     */
    public function replaceVatLines(int $cashDocumentId, array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM cash_document_vat_lines WHERE cash_document_id = ?')
            ->execute([$cashDocumentId]);
        if ($lines === []) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO cash_document_vat_lines
                (cash_document_id, vat_rate, base_amount, vat_amount, vat_classification_code,
                 vat_deduction, vat_deduction_percent, tax_treatment, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $stmt->execute([
                $cashDocumentId,
                (float) $l['vat_rate'],
                (float) $l['base_amount'],
                (float) $l['vat_amount'],
                $l['vat_classification_code'] ?? null,
                (string) ($l['vat_deduction'] ?? 'full'),
                (float) ($l['vat_deduction_percent'] ?? 100.0),
                (string) ($l['tax_treatment'] ?? 'deductible'),
                !empty($l['is_fixed_asset']) ? 1 : 0,
            ]);
        }
    }

    /**
     * @return list<array{vat_rate:float, base_amount:float, vat_amount:float, vat_classification_code:?string, vat_deduction:string, vat_deduction_percent:float, tax_treatment:string, is_fixed_asset:bool}>
     */
    public function vatLinesFor(int $cashDocumentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_rate, base_amount, vat_amount, vat_classification_code,
                    vat_deduction, vat_deduction_percent, tax_treatment, is_fixed_asset
               FROM cash_document_vat_lines WHERE cash_document_id = ? ORDER BY id'
        );
        $stmt->execute([$cashDocumentId]);
        return array_map(static fn (array $r): array => [
            'vat_rate'                => (float) $r['vat_rate'],
            'base_amount'             => (float) $r['base_amount'],
            'vat_amount'              => (float) $r['vat_amount'],
            'vat_classification_code' => $r['vat_classification_code'] !== null ? (string) $r['vat_classification_code'] : null,
            'vat_deduction'           => (string) $r['vat_deduction'],
            'vat_deduction_percent'   => (float) $r['vat_deduction_percent'],
            'tax_treatment'           => (string) $r['tax_treatment'],
            'is_fixed_asset'          => (bool) $r['is_fixed_asset'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM cash_documents WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** Řádek dokladu pod zámkem pro post/reverse — volat VÝHRADNĚ v transakci. */
    public function lockForPost(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM cash_documents
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * L-1: zapisovací metody scopují `supplier_id` stejně jako čtecí a mazací.
     * Dnes sem `id` chodí ze scopovaného `lockForPost()`/`create()`, takže cross-tenant
     * zápis nehrozí — jenže ta bezpečnost stojí na volajícím, ne na dotazu. První
     * volání z jiného kontextu (import, CLI, budoucí batch) by z toho udělalo díru.
     */
    public function markPosted(int $supplierId, int $id, string $docNumber, int $journalEntryId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE cash_documents SET status = 'posted', doc_number = ?, journal_entry_id = ?
              WHERE id = ? AND supplier_id = ?"
        )->execute([$docNumber, $journalEntryId, $id, $supplierId]);
    }

    /**
     * Daňová evidence (Epic DE §6): posted doklad BEZ journalu (journal_entry_id NULL).
     * Používá se výhradně pro supplier.accounting_mode='tax_evidence' — kasová báze
     * neúčtuje do deníku, zámek řeší invoices.booked_at (R14).
     */
    public function markPostedNoJournal(int $supplierId, int $id, string $docNumber): void
    {
        $this->db->pdo()->prepare(
            "UPDATE cash_documents SET status = 'posted', doc_number = ?, journal_entry_id = NULL
              WHERE id = ? AND supplier_id = ?"
        )->execute([$docNumber, $id, $supplierId]);
    }

    public function markReversed(int $supplierId, int $id, int $reversalEntryId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE cash_documents SET status = 'reversed', reversal_entry_id = ?
              WHERE id = ? AND supplier_id = ?"
        )->execute([$reversalEntryId, $id, $supplierId]);
    }

    /**
     * Daňová evidence (Epic DE §6): storno posted dokladu BEZ protizápisu
     * (reversal_entry_id NULL) — v tax_evidence neexistuje posting engine.
     */
    public function markReversedNoJournal(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE cash_documents SET status = 'reversed', reversal_entry_id = NULL
              WHERE id = ? AND supplier_id = ?"
        )->execute([$id, $supplierId]);
    }

    public function setInvoicePaymentId(int $supplierId, int $id, ?int $paymentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$paymentId, $id, $supplierId]);
    }

    public function deleteDraft(int $supplierId, int $id): void
    {
        // ON DELETE CASCADE smaže i vat_lines.
        $this->db->pdo()->prepare(
            "DELETE FROM cash_documents WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        )->execute([$id, $supplierId]);
    }

    /** Tvrdé smazání dokladu bez ohledu na status (vat_lines přes CASCADE). */
    public function deleteDocument(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM cash_documents WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /** Smaže účetní zápis dokladu; řádky i přílohy padnou přes ON DELETE CASCADE. */
    public function deleteJournalEntry(int $supplierId, int $entryId): void
    {
        $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')
            ->execute([$entryId, $supplierId]);
    }

    /**
     * Stránkovaný seznam dokladů s filtry + celkový počet (window function).
     *
     * @param array{register_id?:int, doc_type?:string, purpose?:string, status?:string,
     *              from?:string, to?:string, q?:string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function listDocuments(int $supplierId, array $filters, int $limit, int $offset): array
    {
        $where = ['cd.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['register_id'])) {
            $where[] = 'cd.register_id = ?';
            $params[] = (int) $filters['register_id'];
        }
        if (!empty($filters['doc_type'])) {
            $where[] = 'cd.doc_type = ?';
            $params[] = (string) $filters['doc_type'];
        }
        if (!empty($filters['purpose'])) {
            $where[] = 'cd.purpose = ?';
            $params[] = (string) $filters['purpose'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'cd.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'cd.issue_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'cd.issue_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(cd.doc_number LIKE ? OR cd.partner_name LIKE ? OR cd.description LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        // COLUMNS kvalifikované aliasem cd. — join má překrývající se názvy (id, created_at…).
        $cols = implode(', ', array_map(
            static fn (string $c): string => 'cd.' . trim($c),
            explode(',', self::COLUMNS)
        ));
        $sql = 'SELECT ' . $cols . ', COUNT(*) OVER() AS total_rows,
                       r.name AS register_name, r.account_code AS register_account_code,
                       inv.varsymbol AS invoice_number, pin.vendor_invoice_number AS purchase_invoice_number,
                       u.name AS created_by_name
                  FROM cash_documents cd
                  JOIN cash_registers r ON r.id = cd.register_id
             LEFT JOIN invoices inv ON inv.id = cd.invoice_id AND inv.supplier_id = cd.supplier_id
             LEFT JOIN purchase_invoices pin ON pin.id = cd.purchase_invoice_id AND pin.supplier_id = cd.supplier_id
             LEFT JOIN users u ON u.id = cd.created_by
                 WHERE ' . $whereSql . '
                 ORDER BY cd.issue_date DESC, cd.id DESC
                 LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = $rows !== [] ? (int) $rows[0]['total_rows'] : 0;
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $vatMap = $this->vatLinesMap($ids);

        $items = [];
        foreach ($rows as $row) {
            unset($row['total_rows']);
            $registerName = $row['register_name'] ?? null;
            $registerAccount = $row['register_account_code'] ?? null;
            $invoiceNumber = $row['invoice_number'] ?? null;
            $purchaseNumber = $row['purchase_invoice_number'] ?? null;
            $createdByName = $row['created_by_name'] ?? null;
            unset($row['register_name'], $row['register_account_code'], $row['invoice_number'], $row['purchase_invoice_number'], $row['created_by_name']);
            $doc = self::cast($row);
            $doc['vat_lines'] = $vatMap[$doc['id']] ?? [];
            $doc['register'] = [
                'id'           => $doc['register_id'],
                'name'         => $registerName !== null ? (string) $registerName : null,
                'account_code' => $registerAccount !== null ? (string) $registerAccount : null,
            ];
            $doc['invoice_number'] = $invoiceNumber !== null ? (string) $invoiceNumber : null;
            $doc['purchase_invoice_number'] = $purchaseNumber !== null ? (string) $purchaseNumber : null;
            $doc['created_by_name'] = $createdByName !== null ? (string) $createdByName : null;
            $items[] = $doc;
        }
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<int> $cashDocumentIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function vatLinesMap(array $cashDocumentIds): array
    {
        if ($cashDocumentIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($cashDocumentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT cash_document_id, vat_rate, base_amount, vat_amount, vat_classification_code
               FROM cash_document_vat_lines WHERE cash_document_id IN ($place) ORDER BY id"
        );
        $stmt->execute(array_map('intval', $cashDocumentIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['cash_document_id']][] = [
                'vat_rate'                => (float) $r['vat_rate'],
                'base_amount'             => (float) $r['base_amount'],
                'vat_amount'              => (float) $r['vat_amount'],
                'vat_classification_code' => $r['vat_classification_code'] !== null ? (string) $r['vat_classification_code'] : null,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['register_id'] = (int) $r['register_id'];
        $r['total_amount'] = (float) $r['total_amount'];
        $r['fx_rate'] = (float) $r['fx_rate'];
        $r['amount_foreign'] = $r['amount_foreign'] !== null ? (float) $r['amount_foreign'] : null;
        $r['project_id'] = ($r['project_id'] ?? null) !== null ? (int) $r['project_id'] : null;
        $r['invoice_id'] = $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null;
        $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
        $r['invoice_payment_id'] = $r['invoice_payment_id'] !== null ? (int) $r['invoice_payment_id'] : null;
        $r['journal_entry_id'] = $r['journal_entry_id'] !== null ? (int) $r['journal_entry_id'] : null;
        $r['reversal_entry_id'] = $r['reversal_entry_id'] !== null ? (int) $r['reversal_entry_id'] : null;
        $r['created_by'] = $r['created_by'] !== null ? (int) $r['created_by'] : null;
        return $r;
    }
}
