<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Polymorfní vazba dokument ↔ entita (client/invoice/purchase_invoice/project/journal_entry/bank_transaction). */
final class DocumentLinkRepository
{
    public const ENTITY_TYPES = ['client', 'invoice', 'purchase_invoice', 'project', 'journal_entry', 'bank_transaction'];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array{entity_type:string,entity_id:int,label:string}> Vazby dokumentu s popiskem entity. */
    public function linksForDocument(int $documentId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entity_type, entity_id FROM document_links
              WHERE supplier_id = ? AND document_id = ? ORDER BY entity_type, entity_id'
        );
        $stmt->execute([$supplierId, $documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Popisky dávkově — jeden dotaz na typ entity místo N+1 (labelFor per řádek).
        $labels = $this->labelsForBatch($rows, $supplierId);

        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r['entity_type'];
            $eid = (int) $r['entity_id'];
            $out[] = [
                'entity_type' => $type,
                'entity_id'   => $eid,
                'label'       => $labels[$type . ':' . $eid] ?? ('#' . $eid),
            ];
        }
        return $out;
    }

    /**
     * Ověří, že cílová entita existuje a patří danému dodavateli (scope guard pro
     * zakládání vazby). Zrcadlí WHERE klauzule z labelFor(). Projects nemají
     * supplier_id → scope přes klienta.
     */
    public function entityBelongsToSupplier(string $type, int $id, int $supplierId): bool
    {
        if (!in_array($type, self::ENTITY_TYPES, true) || $id <= 0) {
            return false;
        }
        $sql = match ($type) {
            'client'           => 'SELECT 1 FROM clients WHERE id = ? AND supplier_id = ? LIMIT 1',
            'invoice'          => 'SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ? LIMIT 1',
            'purchase_invoice' => 'SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ? LIMIT 1',
            'project'          => 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ? LIMIT 1',
            'journal_entry'    => 'SELECT 1 FROM journal_entries WHERE id = ? AND supplier_id = ? LIMIT 1',
            // bank_transactions nemá supplier_id přímo — scope jde přes bank_statements (viz BankPostingSuggestionRepository).
            'bank_transaction' => 'SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ? AND bs.supplier_id = ? LIMIT 1',
        };
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function attach(int $supplierId, int $documentId, string $entityType, int $entityId): void
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) return;
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$documentId, $supplierId, $entityType, $entityId]);
    }

    public function detach(int $supplierId, int $documentId, string $entityType, int $entityId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM document_links
              WHERE supplier_id = ? AND document_id = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$supplierId, $documentId, $entityType, $entityId]);
    }

    /**
     * Dávkově složí lidsky čitelné popisky entit (per-supplier ověřeno joinem) —
     * jeden dotaz na typ entity místo jednoho na řádek (odstranění N+1 z labelFor).
     * Zrcadlí přesně logiku formátu původního labelFor(); chybějící/erroru → bez klíče
     * (volající pak dá fallback '#id').
     *
     * @param list<array{entity_type?:mixed,entity_id?:mixed}> $rows
     * @return array<string,string> klíč "type:id" → popisek
     */
    private function labelsForBatch(array $rows, int $supplierId): array
    {
        // ID seskupené po typu entity.
        $byType = [];
        foreach ($rows as $r) {
            $type = (string) $r['entity_type'];
            $eid  = (int) $r['entity_id'];
            if (!in_array($type, self::ENTITY_TYPES, true) || $eid <= 0) {
                continue;
            }
            $byType[$type][$eid] = $eid;
        }

        $pdo = $this->db->pdo();
        $labels = [];
        foreach ($byType as $type => $ids) {
            $ids = array_values($ids);
            $place = implode(',', array_fill(0, count($ids), '?'));
            try {
                switch ($type) {
                    case 'client':
                        $stmt = $pdo->prepare("SELECT id, company_name, ic FROM clients WHERE supplier_id = ? AND id IN ($place)");
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $labels['client:' . (int) $r['id']] = trim((string) $r['company_name'] . ($r['ic'] ? ' · IČ ' . $r['ic'] : ''));
                        }
                        break;
                    case 'invoice':
                        $stmt = $pdo->prepare(
                            "SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat, c.company_name,
                                    COALESCE(cur.code, 'CZK') AS currency
                               FROM invoices i
                               JOIN clients c ON c.id = i.client_id
                          LEFT JOIN currencies cur ON cur.id = i.currency_id
                              WHERE i.supplier_id = ? AND i.id IN ($place)"
                        );
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $id = (int) $r['id'];
                            $labels['invoice:' . $id] = $this->invoiceLabel((string) ($r['varsymbol'] ?? ''), (string) $r['company_name'], $r['issue_date'], $r['total_with_vat'], (string) $r['currency'], $id);
                        }
                        break;
                    case 'purchase_invoice':
                        $stmt = $pdo->prepare(
                            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat,
                                    c.company_name, COALESCE(cur.code, 'CZK') AS currency
                               FROM purchase_invoices pi
                               JOIN clients c ON c.id = pi.vendor_id
                          LEFT JOIN currencies cur ON cur.id = pi.currency_id
                              WHERE pi.supplier_id = ? AND pi.id IN ($place)"
                        );
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $id = (int) $r['id'];
                            $num = (string) ($r['varsymbol'] ?: $r['vendor_invoice_number'] ?: '');
                            $labels['purchase_invoice:' . $id] = $this->invoiceLabel($num, (string) $r['company_name'], $r['issue_date'], $r['total_with_vat'], (string) $r['currency'], $id);
                        }
                        break;
                    case 'project':
                        // projects nemá supplier_id — scope přes klienta.
                        $stmt = $pdo->prepare(
                            "SELECT p.id, p.name, p.project_number, c.company_name
                               FROM projects p JOIN clients c ON c.id = p.client_id
                              WHERE c.supplier_id = ? AND p.id IN ($place)"
                        );
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $labels['project:' . (int) $r['id']] = trim((string) $r['name'] . ($r['company_name'] ? ' · ' . $r['company_name'] : ''));
                        }
                        break;
                    case 'journal_entry':
                        $stmt = $pdo->prepare(
                            "SELECT id, entry_date, document_no, description FROM journal_entries WHERE supplier_id = ? AND id IN ($place)"
                        );
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $id = (int) $r['id'];
                            $parts = [];
                            $parts[] = $r['document_no'] ? (string) $r['document_no'] : ('#' . $id);
                            if ($r['description']) $parts[] = (string) $r['description'];
                            if ($r['entry_date']) $parts[] = (string) $r['entry_date'];
                            $labels['journal_entry:' . $id] = implode(' · ', $parts);
                        }
                        break;
                    case 'bank_transaction':
                        // bank_transactions nemá supplier_id — scope přes bank_statements.
                        $stmt = $pdo->prepare(
                            "SELECT bt.id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency, 'CZK') AS currency,
                                    bt.counterparty_name
                               FROM bank_transactions bt
                               JOIN bank_statements bs ON bs.id = bt.statement_id
                              WHERE bs.supplier_id = ? AND bt.id IN ($place)"
                        );
                        $stmt->execute([$supplierId, ...$ids]);
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $id = (int) $r['id'];
                            $parts = [];
                            $parts[] = (string) $r['posted_at'];
                            $parts[] = number_format((float) $r['amount'], 2, ',', ' ') . ' ' . (string) $r['currency'];
                            if ($r['counterparty_name']) $parts[] = (string) $r['counterparty_name'];
                            $labels['bank_transaction:' . $id] = implode(' · ', $parts);
                        }
                        break;
                }
            } catch (\Throwable) {
                // snášíme — sloupec/tabulka se může lišit; volající dá fallback '#id'
            }
        }
        return $labels;
    }

    private function invoiceLabel(string $num, string $company, mixed $date, mixed $total, string $currency, int $id): string
    {
        $parts = [];
        $parts[] = $num !== '' ? $num : ('#' . $id);
        if ($company !== '') $parts[] = $company;
        if ($total !== null) $parts[] = number_format((float) $total, 0, ',', ' ') . ' ' . $currency;
        if ($date) $parts[] = (string) $date;
        return implode(' · ', $parts);
    }
}
