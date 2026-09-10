<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentLinkRepository;
use PDO;

/**
 * Vydaná faktura: firma je dodavatel, protistrana odběratel. Číslo faktury je
 * její variabilní symbol. PDF si vydaná faktura generuje sama, sken (podepsaná
 * kopie, převzetí) jde jen do sekce Dokumenty s vazbou na fakturu.
 */
final class IssuedInvoiceScanTarget implements ScanTargetInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLinkRepository $links,
    ) {}

    public function type(): string
    {
        return 'invoice';
    }

    public function isAvailable(): bool
    {
        return in_array($this->type(), DocumentLinkRepository::ENTITY_TYPES, true);
    }

    public function permission(): string
    {
        return 'invoices';
    }

    public function candidates(int $supplierId, ?string $from, ?string $to): array
    {
        [$range, $params] = self::range($from, $to);
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.total_with_vat, i.issue_date, i.tax_date, c.ic AS counterparty_ico
               FROM invoices i
          LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ? AND i.status NOT IN ('draft', 'cancelled')" . $range
        );
        $stmt->execute([$supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vs = $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null;
            $out[] = [
                'id' => (int) $r['id'],
                'direction' => ScanMatcher::DIRECTION_ISSUED,
                'doc_numbers' => $vs !== null ? [$vs] : [],
                'barcode' => null,
                'counterparty_ico' => $r['counterparty_ico'] !== null ? (string) $r['counterparty_ico'] : null,
                'counterparty_doc_no' => $vs,
                'vs' => $vs,
                'total' => (float) $r['total_with_vat'],
                'date' => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
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
            "SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat, c.company_name, COALESCE(cur.code, 'CZK') AS currency
               FROM invoices i
          LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.id IN ($place)"
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
            "SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat, c.company_name,
                    COALESCE(cur.code, 'CZK') AS currency, COUNT(*) OVER () AS total_rows
               FROM invoices i
          LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.status NOT IN ('draft', 'cancelled')
                AND NOT EXISTS (
                    SELECT 1 FROM document_links dl
                     WHERE dl.supplier_id = i.supplier_id AND dl.entity_type = 'invoice' AND dl.entity_id = i.id
                )" . $range . '
           ORDER BY i.issue_date DESC, i.id DESC
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
        return [
            'label' => (string) ($r['varsymbol'] ?: '#' . $r['id']),
            'counterparty' => $r['company_name'] !== null ? (string) $r['company_name'] : null,
            'date' => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total' => $r['total_with_vat'] !== null ? (float) $r['total_with_vat'] : null,
            'currency' => (string) $r['currency'],
        ];
    }

    /** @return array{0:string,1:list<string>} */
    private static function range(?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND i.issue_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND i.issue_date <= ?';
            $params[] = $to;
        }
        return [$sql, $params];
    }
}
