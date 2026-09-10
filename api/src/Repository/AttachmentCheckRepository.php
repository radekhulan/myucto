<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Data pro kontrolu zaúčtovaných dokladů proti vytěžení příloh (`attachment_checks`).
 *
 * Příloha dokladu je buď dokument v sekci Dokumenty s vazbou na doklad
 * (`document_links`), nebo PDF slot dokladu (`purchase_invoices.pdf_hash`,
 * `invoices.imported_pdf_hash`) — PDF, ze kterého AI import doklad založil, v sekci
 * Dokumenty být nemusí. Obojí se páruje s `document_extractions` přes obsah (sha256).
 */
final class AttachmentCheckRepository
{
    public const ENTITY_TYPES = ['purchase_invoice', 'invoice', 'cash_document'];

    private const EXT_COLUMNS = 'e.id AS extraction_id, e.schema_version, e.company_role, e.document_kind AS ext_kind,
        e.vendor_ico AS ext_vendor_ico, e.buyer_ico AS ext_buyer_ico, e.variable_symbol AS ext_vs,
        e.tax_date AS ext_tax_date, e.total_with_vat AS ext_total, e.amount_due AS ext_amount_due,
        e.currency AS ext_currency';

    public function __construct(private readonly Connection $db) {}

    /**
     * Dvojice doklad × vytěžení přílohy. Bez `$entityId` celá firma, s rozsahem jen
     * doklady, jejichž DUZP (bez DUZP datum vystavení) NEBO DUZP podle přílohy padá
     * do rozsahu — rozdíl přes hranici měsíce se tak ukáže v obou dotčených měsících.
     *
     * @return list<array<string,mixed>> řádky {entity_type, entity_id, sha256, document_id, extraction_id, schema_version, doc, ext, label}
     */
    public function loadPairs(int $supplierId, ?string $entityType = null, ?int $entityId = null, ?string $from = null, ?string $to = null): array
    {
        $types = $entityType !== null ? [$entityType] : self::ENTITY_TYPES;
        $out = [];
        foreach ($types as $type) {
            $rows = match ($type) {
                'purchase_invoice' => $this->purchasePairs($supplierId, $entityId, $from, $to),
                'invoice' => $this->invoicePairs($supplierId, $entityId, $from, $to),
                'cash_document' => $this->cashPairs($supplierId, $entityId, $from, $to),
                default => [],
            };
            array_push($out, ...$rows);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function purchasePairs(int $supplierId, ?int $id, ?string $from, ?string $to): array
    {
        [$scope, $params] = self::scope('pi.id', $id, 'COALESCE(pi.tax_date, pi.issue_date)', $from, $to);
        $payer = VatStatusService::payerAtExpr('pi.supplier_id', 'COALESCE(pi.tax_date, pi.issue_date)', '(SELECT s.is_vat_payer FROM supplier s WHERE s.id = pi.supplier_id)');
        $stmt = $this->db->pdo()->prepare(
            "WITH att AS (
                SELECT entity_id, sha256, MAX(document_id) AS document_id FROM (
                    SELECT dl.entity_id, d.sha256, d.id AS document_id
                      FROM document_links dl
                      JOIN documents d ON d.id = dl.document_id AND d.supplier_id = dl.supplier_id AND d.deleted_at IS NULL
                     WHERE dl.supplier_id = ? AND dl.entity_type = 'purchase_invoice'
                    UNION ALL
                    SELECT p.id, p.pdf_hash, NULL FROM purchase_invoices p WHERE p.supplier_id = ? AND p.pdf_hash IS NOT NULL
                ) u GROUP BY entity_id, sha256
             )
             SELECT pi.id, pi.document_kind, pi.vendor_invoice_number, pi.payment_variable_symbol,
                    pi.issue_date, pi.tax_date, pi.total_with_vat, pi.total_vat, pi.rounding, pi.amount_to_pay,
                    pi.advance_paid_amount, pi.reverse_charge, pi.vat_deduction,
                    COALESCE(cur.code, 'CZK') AS currency, c.ic AS counterparty_ico, c.company_name AS partner_name,
                    (SELECT s.ic FROM supplier s WHERE s.id = pi.supplier_id) AS own_ico,
                    ({$payer}) AS own_payer,
                    att.sha256, att.document_id, " . self::EXT_COLUMNS . "
               FROM purchase_invoices pi
               JOIN att ON att.entity_id = pi.id
               JOIN document_extractions e ON e.supplier_id = pi.supplier_id AND e.sha256 = att.sha256 AND e.status = 'ok'
          LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.status <> 'cancelled'" . $scope
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vatRelevant = (bool) $r['own_payer']
                && ((float) $r['total_vat'] !== 0.0 || (int) $r['reverse_charge'] === 1)
                && $r['vat_deduction'] !== 'none';
            $out[] = self::pair('purchase_invoice', $r, [
                'direction' => 'received',
                'kind' => (string) $r['document_kind'],
                'total' => (float) $r['total_with_vat'],
                'rounding' => (float) $r['rounding'],
                'amount_to_pay' => $r['amount_to_pay'] !== null ? (float) $r['amount_to_pay'] : null,
                'advance_paid' => $r['advance_paid_amount'] !== null ? (float) $r['advance_paid_amount'] : null,
                'currency' => (string) $r['currency'],
                'tax_date' => $r['tax_date'],
                'counterparty_ico' => $r['counterparty_ico'],
                'own_ico' => $r['own_ico'],
                'vs' => $r['payment_variable_symbol'],
                'vat_relevant' => $vatRelevant,
            ], (string) ($r['vendor_invoice_number'] ?: '#' . $r['id']));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function invoicePairs(int $supplierId, ?int $id, ?string $from, ?string $to): array
    {
        [$scope, $params] = self::scope('i.id', $id, 'COALESCE(i.tax_date, i.issue_date)', $from, $to);
        $payer = VatStatusService::payerAtExpr('i.supplier_id', 'COALESCE(i.tax_date, i.issue_date)', '(SELECT s.is_vat_payer FROM supplier s WHERE s.id = i.supplier_id)');
        $stmt = $this->db->pdo()->prepare(
            "WITH att AS (
                SELECT entity_id, sha256, MAX(document_id) AS document_id FROM (
                    SELECT dl.entity_id, d.sha256, d.id AS document_id
                      FROM document_links dl
                      JOIN documents d ON d.id = dl.document_id AND d.supplier_id = dl.supplier_id AND d.deleted_at IS NULL
                     WHERE dl.supplier_id = ? AND dl.entity_type = 'invoice'
                    UNION ALL
                    SELECT x.id, x.imported_pdf_hash, NULL FROM invoices x WHERE x.supplier_id = ? AND x.imported_pdf_hash IS NOT NULL
                ) u GROUP BY entity_id, sha256
             )
             SELECT i.id, i.invoice_type, i.varsymbol, i.issue_date, i.tax_date, i.total_with_vat, i.total_vat,
                    i.rounding, i.amount_to_pay, i.advance_paid_amount, i.reverse_charge,
                    COALESCE(cur.code, 'CZK') AS currency, c.ic AS counterparty_ico, c.company_name AS partner_name,
                    (SELECT s.ic FROM supplier s WHERE s.id = i.supplier_id) AS own_ico,
                    ({$payer}) AS own_payer,
                    att.sha256, att.document_id, " . self::EXT_COLUMNS . "
               FROM invoices i
               JOIN att ON att.entity_id = i.id
               JOIN document_extractions e ON e.supplier_id = i.supplier_id AND e.sha256 = att.sha256 AND e.status = 'ok'
          LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.status <> 'cancelled'" . $scope
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::pair('invoice', $r, [
                'direction' => 'issued',
                'kind' => self::issuedKind((string) $r['invoice_type']),
                'total' => (float) $r['total_with_vat'],
                'rounding' => (float) $r['rounding'],
                'amount_to_pay' => $r['amount_to_pay'] !== null ? (float) $r['amount_to_pay'] : null,
                'advance_paid' => $r['advance_paid_amount'] !== null ? (float) $r['advance_paid_amount'] : null,
                'currency' => (string) $r['currency'],
                'tax_date' => $r['tax_date'],
                'counterparty_ico' => $r['counterparty_ico'],
                'own_ico' => $r['own_ico'],
                'vs' => $r['varsymbol'],
                'vat_relevant' => (bool) $r['own_payer'] && ((float) $r['total_vat'] !== 0.0 || (int) $r['reverse_charge'] === 1),
            ], (string) ($r['varsymbol'] ?: '#' . $r['id']));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function cashPairs(int $supplierId, ?int $id, ?string $from, ?string $to): array
    {
        [$scope, $params] = self::scope('cd.id', $id, 'COALESCE(cd.tax_date, cd.issue_date)', $from, $to);
        $payer = VatStatusService::payerAtExpr('cd.supplier_id', 'COALESCE(cd.tax_date, cd.issue_date)', '(SELECT s.is_vat_payer FROM supplier s WHERE s.id = cd.supplier_id)');
        $stmt = $this->db->pdo()->prepare(
            "WITH att AS (
                SELECT dl.entity_id, d.sha256, MAX(d.id) AS document_id
                  FROM document_links dl
                  JOIN documents d ON d.id = dl.document_id AND d.supplier_id = dl.supplier_id AND d.deleted_at IS NULL
                 WHERE dl.supplier_id = ? AND dl.entity_type = 'cash_document'
                 GROUP BY dl.entity_id, d.sha256
             )
             SELECT cd.id, cd.doc_type, cd.doc_number, cd.issue_date, cd.tax_date, cd.total_amount, cd.amount_foreign,
                    cd.currency_code, cd.vat_mode, cd.partner_ic AS counterparty_ico, cd.partner_name,
                    (SELECT s.ic FROM supplier s WHERE s.id = cd.supplier_id) AS own_ico,
                    ({$payer}) AS own_payer,
                    att.sha256, att.document_id, " . self::EXT_COLUMNS . "
               FROM cash_documents cd
               JOIN att ON att.entity_id = cd.id
               JOIN document_extractions e ON e.supplier_id = cd.supplier_id AND e.sha256 = att.sha256 AND e.status = 'ok'
              WHERE cd.supplier_id = ? AND cd.status <> 'reversed'" . $scope
        );
        $stmt->execute([$supplierId, $supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $currency = strtoupper((string) ($r['currency_code'] ?: 'CZK'));
            // Valutová pokladna: částka dokladu v měně je amount_foreign, total_amount je CZK ekvivalent.
            $total = $currency !== 'CZK' && $r['amount_foreign'] !== null ? (float) $r['amount_foreign'] : (float) $r['total_amount'];
            $out[] = self::pair('cash_document', $r, [
                'direction' => $r['doc_type'] === 'in' ? 'issued' : 'received',
                'kind' => 'receipt',
                'total' => $total,
                'rounding' => 0.0,
                'amount_to_pay' => null,
                'advance_paid' => null,
                'currency' => $currency,
                'tax_date' => $r['tax_date'],
                'counterparty_ico' => $r['counterparty_ico'],
                'own_ico' => $r['own_ico'],
                'vs' => null,
                'vat_relevant' => (bool) $r['own_payer'] && $r['vat_mode'] === 'vat',
            ], (string) ($r['doc_number'] ?: '#' . $r['id']), $currency, $total);
        }
        return $out;
    }

    /**
     * Uložené potvrzení „v pořádku" (klíč `typ:id:sha256`).
     *
     * @return array<string, array{ack_fingerprint:string, ack_reason:?string, ack_by:?int, ack_at:?string}>
     */
    public function acks(int $supplierId, ?string $entityType = null, ?int $entityId = null): array
    {
        $sql = 'SELECT entity_type, entity_id, sha256, ack_fingerprint, ack_reason, ack_by, ack_at
                  FROM attachment_checks
                 WHERE supplier_id = ? AND ack_fingerprint IS NOT NULL';
        $params = [$supplierId];
        if ($entityType !== null && $entityId !== null) {
            $sql .= ' AND entity_type = ? AND entity_id = ?';
            $params[] = $entityType;
            $params[] = $entityId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['entity_type'] . ':' . $r['entity_id'] . ':' . $r['sha256']] = [
                'ack_fingerprint' => (string) $r['ack_fingerprint'],
                'ack_reason' => $r['ack_reason'] !== null ? (string) $r['ack_reason'] : null,
                'ack_by' => $r['ack_by'] !== null ? (int) $r['ack_by'] : null,
                'ack_at' => $r['ack_at'] !== null ? (string) $r['ack_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * Uloží výsledek porovnání. Potvrzení se tu nepřepisuje — nese ho {@see acknowledge()}
     * a platí dál, dokud otisk sedí.
     *
     * @param array<string,mixed> $r výsledek {@see \MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService::evaluate()}
     */
    public function upsert(int $supplierId, array $r): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO attachment_checks
                (supplier_id, entity_type, entity_id, sha256, document_id, extraction_id, status, severity, findings,
                 fingerprint, doc_tax_date, attachment_tax_date, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), extraction_id = VALUES(extraction_id),
                status = VALUES(status), severity = VALUES(severity), findings = VALUES(findings),
                fingerprint = VALUES(fingerprint), doc_tax_date = VALUES(doc_tax_date),
                attachment_tax_date = VALUES(attachment_tax_date), checked_at = VALUES(checked_at)'
        );
        $stmt->execute([
            $supplierId, $r['entity_type'], $r['entity_id'], $r['sha256'], $r['document_id'], $r['extraction_id'],
            $r['status'], $r['severity'],
            json_encode($r['findings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $r['fingerprint'], $r['doc_tax_date'], $r['attachment_tax_date'], date('Y-m-d H:i:s'),
        ]);
    }

    public function acknowledge(int $supplierId, string $entityType, int $entityId, string $sha256, string $fingerprint, string $reason, ?int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE attachment_checks
                SET ack_fingerprint = ?, ack_reason = ?, ack_by = ?, ack_at = ?
              WHERE supplier_id = ? AND entity_type = ? AND entity_id = ? AND sha256 = ?'
        );
        $stmt->execute([$fingerprint, mb_substr($reason, 0, 500), $userId, date('Y-m-d H:i:s'), $supplierId, $entityType, $entityId, $sha256]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Smaže uložené výsledky dokladu pro přílohy, které už k němu nepatří.
     *
     * @param list<string> $keepShas
     */
    public function deleteStale(int $supplierId, string $entityType, int $entityId, array $keepShas): void
    {
        $sql = 'DELETE FROM attachment_checks WHERE supplier_id = ? AND entity_type = ? AND entity_id = ?';
        $params = [$supplierId, $entityType, $entityId];
        if ($keepShas !== []) {
            $sql .= ' AND sha256 NOT IN (' . implode(',', array_fill(0, count($keepShas), '?')) . ')';
            array_push($params, ...$keepShas);
        }
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** Uložené výsledky firmy mimo zadané klíče (po celofiremním přepočtu už neplatí). @param list<string> $keepKeys `typ:id:sha256` */
    public function deleteAllExcept(int $supplierId, array $keepKeys): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, entity_type, entity_id, sha256 FROM attachment_checks WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $keep = array_fill_keys($keepKeys, true);
        $drop = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($keep[$r['entity_type'] . ':' . $r['entity_id'] . ':' . $r['sha256']])) {
                $drop[] = (int) $r['id'];
            }
        }
        foreach (array_chunk($drop, 500) as $chunk) {
            $this->db->pdo()->prepare(
                'DELETE FROM attachment_checks WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            )->execute([$supplierId, ...$chunk]);
        }
        return count($drop);
    }

    /**
     * Uložené rozdíly pro přehled. `open` = rozdíl bez platného potvrzení,
     * `acknowledged` = potvrzený k aktuálnímu otisku, `all` = obojí.
     *
     * @param list<array{0:string,1:int}>|null $entities omezení na doklady (přehled dávky skenů)
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function listMismatches(int $supplierId, string $state, ?array $entities, int $limit): array
    {
        $where = "ac.supplier_id = ? AND ac.status = 'mismatch'";
        $params = [$supplierId];
        if ($state === 'open') {
            $where .= ' AND (ac.ack_fingerprint IS NULL OR ac.ack_fingerprint <> ac.fingerprint)';
        } elseif ($state === 'acknowledged') {
            $where .= ' AND ac.ack_fingerprint = ac.fingerprint';
        }
        if ($entities !== null) {
            if ($entities === []) {
                return ['rows' => [], 'total' => 0];
            }
            $pairs = [];
            foreach ($entities as [$type, $id]) {
                $pairs[] = '(ac.entity_type = ? AND ac.entity_id = ?)';
                $params[] = $type;
                $params[] = $id;
            }
            $where .= ' AND (' . implode(' OR ', $pairs) . ')';
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT ac.*, COUNT(*) OVER () AS total_rows,
                    CASE ac.entity_type WHEN 'purchase_invoice' THEN pi.vendor_invoice_number
                                        WHEN 'invoice' THEN i.varsymbol ELSE cd.doc_number END AS doc_no,
                    CASE ac.entity_type WHEN 'purchase_invoice' THEN pc.company_name
                                        WHEN 'invoice' THEN ic.company_name ELSE cd.partner_name END AS partner_name,
                    CASE ac.entity_type WHEN 'purchase_invoice' THEN pi.total_with_vat
                                        WHEN 'invoice' THEN i.total_with_vat ELSE cd.total_amount END AS amount,
                    CASE ac.entity_type WHEN 'purchase_invoice' THEN pcur.code
                                        WHEN 'invoice' THEN icur.code ELSE cd.currency_code END AS currency,
                    d.original_name AS document_name
               FROM attachment_checks ac
          LEFT JOIN purchase_invoices pi ON ac.entity_type = 'purchase_invoice' AND pi.id = ac.entity_id AND pi.supplier_id = ac.supplier_id
          LEFT JOIN clients pc ON pc.id = pi.vendor_id AND pc.supplier_id = ac.supplier_id
          LEFT JOIN currencies pcur ON pcur.id = pi.currency_id
          LEFT JOIN invoices i ON ac.entity_type = 'invoice' AND i.id = ac.entity_id AND i.supplier_id = ac.supplier_id
          LEFT JOIN clients ic ON ic.id = i.client_id AND ic.supplier_id = ac.supplier_id
          LEFT JOIN currencies icur ON icur.id = i.currency_id
          LEFT JOIN cash_documents cd ON ac.entity_type = 'cash_document' AND cd.id = ac.entity_id AND cd.supplier_id = ac.supplier_id
          LEFT JOIN documents d ON d.id = ac.document_id AND d.supplier_id = ac.supplier_id
              WHERE {$where}
           ORDER BY (ac.severity = 'warning') DESC, COALESCE(ac.doc_tax_date, ac.attachment_tax_date) DESC, ac.id DESC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $findings = json_decode((string) ($r['findings'] ?? ''), true);
            $out[] = [
                'entity_type' => (string) $r['entity_type'],
                'entity_id' => (int) $r['entity_id'],
                'sha256' => (string) $r['sha256'],
                'document_id' => $r['document_id'] !== null ? (int) $r['document_id'] : null,
                'document_name' => $r['document_name'] !== null ? (string) $r['document_name'] : null,
                'doc_no' => $r['doc_no'] !== null ? (string) $r['doc_no'] : '#' . $r['entity_id'],
                'partner_name' => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
                'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
                'currency' => (string) ($r['currency'] ?: 'CZK'),
                'status' => (string) $r['status'],
                'severity' => $r['severity'] !== null ? (string) $r['severity'] : null,
                'findings' => is_array($findings) ? $findings : [],
                'doc_tax_date' => $r['doc_tax_date'],
                'attachment_tax_date' => $r['attachment_tax_date'],
                'checked_at' => (string) $r['checked_at'],
                'acknowledged' => $r['ack_fingerprint'] !== null && $r['ack_fingerprint'] === $r['fingerprint'],
                'ack_reason' => $r['ack_reason'] !== null ? (string) $r['ack_reason'] : null,
                'ack_at' => $r['ack_at'] !== null ? (string) $r['ack_at'] : null,
            ];
        }
        return ['rows' => $out, 'total' => $rows === [] ? 0 : (int) $rows[0]['total_rows']];
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function pair(string $type, array $r, array $doc, string $docNo, ?string $currency = null, ?float $amount = null): array
    {
        return [
            'entity_type' => $type,
            'entity_id' => (int) $r['id'],
            'sha256' => (string) $r['sha256'],
            'document_id' => $r['document_id'] !== null ? (int) $r['document_id'] : null,
            'extraction_id' => (int) $r['extraction_id'],
            'schema_version' => (int) $r['schema_version'],
            'doc' => $doc,
            'ext' => [
                'company_role' => $r['company_role'],
                'document_kind' => $r['ext_kind'],
                'vendor_ico' => $r['ext_vendor_ico'],
                'buyer_ico' => $r['ext_buyer_ico'],
                'variable_symbol' => $r['ext_vs'],
                'tax_date' => $r['ext_tax_date'],
                'total_with_vat' => $r['ext_total'] !== null ? (float) $r['ext_total'] : null,
                'amount_due' => $r['ext_amount_due'] !== null ? (float) $r['ext_amount_due'] : null,
                'currency' => $r['ext_currency'],
            ],
            'label' => [
                'doc_no' => $docNo,
                'partner_name' => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
                'issue_date' => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                'amount' => $amount ?? (float) ($r['total_with_vat'] ?? 0),
                'currency' => $currency ?? (string) ($r['currency'] ?? 'CZK'),
            ],
        ];
    }

    private static function issuedKind(string $invoiceType): string
    {
        return match ($invoiceType) {
            'proforma', 'payment_calendar' => 'advance',
            'credit_note', 'cancellation' => 'credit_note',
            'tax_document' => 'tax_document',
            default => 'invoice',
        };
    }

    /** @return array{0:string,1:list<int|string>} */
    private static function scope(string $idColumn, ?int $id, string $dateExpr, ?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($id !== null) {
            $sql .= " AND {$idColumn} = ?";
            $params[] = $id;
        }
        if ($from !== null || $to !== null) {
            $from ??= '0001-01-01';
            $to ??= '9999-12-31';
            $sql .= " AND (({$dateExpr}) BETWEEN ? AND ? OR e.tax_date BETWEEN ? AND ?)";
            array_push($params, $from, $to, $from, $to);
        }
        return [$sql, $params];
    }
}
