<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use PDO;

/**
 * Přijatá faktura: firma je odběratel, protistrana dodavatel. Sken jde do sekce
 * Dokumenty s vazbou na fakturu a první z nich i do PDF slotu faktury, takže je
 * vidět přímo v detailu. Obrázek se do slotu převede na PDF stejně jako při
 * ručním nahrání.
 */
final class PurchaseInvoiceScanTarget implements ScanTargetInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLinkRepository $links,
        private readonly PurchaseInvoicePdfArchiver $archiver,
        private readonly ImageToPdfConverter $images,
    ) {}

    public function type(): string
    {
        return 'purchase_invoice';
    }

    public function isAvailable(): bool
    {
        return in_array($this->type(), DocumentLinkRepository::ENTITY_TYPES, true);
    }

    public function permission(): string
    {
        return 'purchase_invoices';
    }

    public function candidates(int $supplierId, ?string $from, ?string $to): array
    {
        [$range, $params] = self::range('pi.issue_date', $from, $to);
        // Číslo dokladu v názvu skenu bývá vlastní (vnitřní) číslo z deníku, ne číslo
        // dodavatele — proto se bere i `document_no` zápisu, který fakturu zaúčtoval.
        $stmt = $this->db->pdo()->prepare(
            "WITH je AS (
                SELECT source_id, MIN(document_no) AS document_no
                  FROM journal_entries
                 WHERE supplier_id = ? AND source_type = 'purchase_invoice'
                   AND document_no IS NOT NULL AND document_no <> ''
                 GROUP BY source_id
             )
             SELECT pi.id, pi.vendor_invoice_number, pi.varsymbol, pi.external_barcode,
                    pi.total_with_vat, pi.issue_date, pi.tax_date, c.ic AS counterparty_ico,
                    je.document_no
               FROM purchase_invoices pi
          LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
          LEFT JOIN je ON je.source_id = pi.id
              WHERE pi.supplier_id = ? AND pi.status <> 'cancelled'" . $range
        );
        $stmt->execute([$supplierId, $supplierId, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'direction' => ScanMatcher::DIRECTION_RECEIVED,
                'doc_numbers' => array_values(array_filter([(string) ($r['document_no'] ?? '')])),
                'barcode' => $r['external_barcode'] !== null ? (string) $r['external_barcode'] : null,
                'counterparty_ico' => $r['counterparty_ico'] !== null ? (string) $r['counterparty_ico'] : null,
                'counterparty_doc_no' => (string) $r['vendor_invoice_number'],
                'vs' => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
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
            "SELECT pi.id, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat,
                    c.company_name, COALESCE(cur.code, 'CZK') AS currency
               FROM purchase_invoices pi
          LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.id IN ($place)"
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
        [$range, $params] = self::range('pi.issue_date', $from, $to);
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat,
                    c.company_name, COALESCE(cur.code, 'CZK') AS currency,
                    COUNT(*) OVER () AS total_rows
               FROM purchase_invoices pi
          LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.status <> 'cancelled'
                AND (pi.pdf_path IS NULL OR pi.pdf_path = '')
                AND NOT EXISTS (
                    SELECT 1 FROM document_links dl
                     WHERE dl.supplier_id = pi.supplier_id AND dl.entity_type = 'purchase_invoice' AND dl.entity_id = pi.id
                )" . $range . '
           ORDER BY pi.issue_date DESC, pi.id DESC
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

        $has = $this->db->pdo()->prepare('SELECT pdf_path FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $has->execute([$targetId, $supplierId]);
        if (trim((string) $has->fetchColumn()) !== '' || !is_file($absPath)) {
            return;
        }
        $bytes = (string) file_get_contents($absPath);
        if (str_starts_with($bytes, '%PDF')) {
            $this->archiver->archiveFile($targetId, $supplierId, $absPath, $fileName, hash('sha256', $bytes), strlen($bytes));
            return;
        }
        $mime = $this->images->detectImageMime($bytes);
        if ($mime !== null && $this->images->isSupportedImage($mime)) {
            $this->archiver->archiveBytes($targetId, $supplierId, $this->images->convert($bytes, $mime), pathinfo($fileName, PATHINFO_FILENAME) . '.pdf');
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array{label:string,counterparty:?string,date:?string,total:?float,currency:?string}
     */
    private static function row(array $r): array
    {
        return [
            'label' => (string) ($r['vendor_invoice_number'] ?: '#' . $r['id']),
            'counterparty' => $r['company_name'] !== null ? (string) $r['company_name'] : null,
            'date' => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total' => $r['total_with_vat'] !== null ? (float) $r['total_with_vat'] : null,
            'currency' => (string) $r['currency'],
        ];
    }

    /** @return array{0:string,1:list<string>} */
    private static function range(string $column, ?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from !== null) {
            $sql .= " AND $column >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= " AND $column <= ?";
            $params[] = $to;
        }
        return [$sql, $params];
    }
}
