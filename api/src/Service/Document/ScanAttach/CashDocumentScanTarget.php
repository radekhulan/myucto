<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentLinkRepository;
use PDO;

/**
 * Pokladní doklad: výdajový (VPD) je přijatý doklad — firma platí, protistrana
 * je prodávající; příjmový (PPD) je vydaný. Sken jde do sekce Dokumenty s vazbou
 * `cash_document`. Dostupnost se odvozuje od toho, zda sekce Dokumenty tuto vazbu
 * zná — bez ní se pokladní doklady do párování vůbec nezařadí.
 */
final class CashDocumentScanTarget implements ScanTargetInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLinkRepository $links,
    ) {}

    public function type(): string
    {
        return 'cash_document';
    }

    public function isAvailable(): bool
    {
        return in_array($this->type(), DocumentLinkRepository::ENTITY_TYPES, true);
    }

    public function permission(): string
    {
        return 'cash.document.write';
    }

    public function candidates(int $supplierId, ?string $from, ?string $to): array
    {
        [$range, $params] = self::range($from, $to);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, doc_type, doc_number, partner_ic, total_amount, issue_date, tax_date, external_barcode
               FROM cash_documents
              WHERE supplier_id = ? AND status <> 'reversed'" . $range
        );
        $stmt->execute([$supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'direction' => $r['doc_type'] === 'in' ? ScanMatcher::DIRECTION_ISSUED : ScanMatcher::DIRECTION_RECEIVED,
                'doc_numbers' => array_values(array_filter([(string) ($r['doc_number'] ?? '')])),
                'barcode' => $r['external_barcode'] !== null ? (string) $r['external_barcode'] : null,
                'counterparty_ico' => $r['partner_ic'] !== null ? (string) $r['partner_ic'] : null,
                'counterparty_doc_no' => null,
                'vs' => null,
                'total' => (float) $r['total_amount'],
                'date' => (string) $r['issue_date'],
                'tax_date' => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
            ];
        }
        return $out;
    }

    public function describe(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, doc_number, partner_name, description, issue_date, total_amount, currency_code
               FROM cash_documents
              WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = self::row($r);
        }
        return $out;
    }

    public function withoutScan(int $supplierId, ?string $from, ?string $to, int $limit): array
    {
        [$range, $params] = self::range($from, $to);
        $stmt = $this->db->pdo()->prepare(
            "SELECT cd.id, cd.doc_number, cd.partner_name, cd.description, cd.issue_date, cd.total_amount,
                    cd.currency_code, COUNT(*) OVER () AS total_rows
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.status <> 'reversed'
                AND NOT EXISTS (
                    SELECT 1 FROM document_links dl
                     WHERE dl.supplier_id = cd.supplier_id AND dl.entity_type = 'cash_document' AND dl.entity_id = cd.id
                )" . str_replace('issue_date', 'cd.issue_date', $range) . '
           ORDER BY cd.issue_date DESC, cd.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$supplierId, ...$params]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [
            'total' => $rows === [] ? 0 : (int) $rows[0]['total_rows'],
            'rows' => array_map(static fn (array $r): array => ['id' => (int) $r['id']] + self::row($r), $rows),
        ];
    }

    public function attach(int $supplierId, int $targetId, int $documentId, string $absPath, string $fileName): void
    {
        $this->links->attach($supplierId, $documentId, $this->type(), $targetId);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{label:string,counterparty:?string,date:?string,total:?float,currency:?string}
     */
    private static function row(array $r): array
    {
        $who = trim((string) ($r['partner_name'] ?? '')) ?: trim((string) ($r['description'] ?? ''));
        return [
            'label' => (string) ($r['doc_number'] ?: '#' . $r['id']),
            'counterparty' => $who !== '' ? $who : null,
            'date' => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total' => $r['total_amount'] !== null ? (float) $r['total_amount'] : null,
            'currency' => (string) ($r['currency_code'] ?? 'CZK'),
        ];
    }

    /** @return array{0:string,1:list<string>} */
    private static function range(?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND issue_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND issue_date <= ?';
            $params[] = $to;
        }
        return [$sql, $params];
    }
}
