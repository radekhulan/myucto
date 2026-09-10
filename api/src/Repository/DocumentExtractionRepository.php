<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use PDO;

/**
 * Uložená AI vytěžení obsahu dokumentů (`document_extractions`).
 *
 * Klíčem je obsah (sha256) v rámci firmy: stejný soubor se nevytěžuje podruhé,
 * ani když leží v sekci Dokumenty dvakrát. Dotaz podle dokumentu jde přes jeho
 * sha256, dotaz podle dokladu přes `document_links` → `documents`.
 */
final class DocumentExtractionRepository
{
    /** Sloupce s normalizovanými údaji (výstup ScanExtractionNormalizer). */
    private const FIELDS = [
        'company_role', 'document_kind', 'barcode', 'vendor_name', 'vendor_ico', 'vendor_dic',
        'buyer_name', 'buyer_ico', 'buyer_dic', 'document_number', 'variable_symbol',
        'issue_date', 'tax_date', 'total_with_vat', 'amount_due', 'currency',
        'license_plate', 'card_last4',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function findOkBySha(int $supplierId, string $sha256, int $schemaVersion): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM document_extractions
              WHERE supplier_id = ? AND sha256 = ? AND schema_version = ? AND status = 'ok'
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $sha256, $schemaVersion]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Úspěšná vytěžení pro víc obsahů naráz (párování dávky).
     *
     * @param list<string> $shas
     * @return array<string, array<string,mixed>> sha256 → řádek
     */
    public function mapOkBySha(int $supplierId, array $shas, int $schemaVersion): array
    {
        $shas = array_values(array_unique(array_filter($shas)));
        $out = [];
        foreach (array_chunk($shas, 500) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT * FROM document_extractions
                  WHERE supplier_id = ? AND schema_version = ? AND status = 'ok' AND sha256 IN ($place)"
            );
            $stmt->execute([$supplierId, $schemaVersion, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string) $row['sha256']] = self::cast($row);
            }
        }
        return $out;
    }

    /**
     * Uloží (nebo přepíše) vytěžení obsahu. Neúspěch se ukládá taky — s chybou,
     * ať je v přehledu vidět proč — a další běh ho zkusí znovu.
     *
     * @param array<string,mixed> $fields normalizované údaje
     * @param array<string,mixed>|null $payload úplná odpověď modelu; celé číslo
     *        platební karty se z ní před uložením zamaskuje (ukládá se jen koncovka)
     */
    public function save(
        int $supplierId,
        ?int $documentId,
        string $sha256,
        int $schemaVersion,
        string $status,
        array $fields,
        ?array $payload,
        ?string $provider,
        ?string $model,
        ?string $error,
    ): int {
        $cols = ['supplier_id', 'document_id', 'sha256', 'schema_version', 'status', 'provider', 'model', 'payload', 'error', 'extracted_at'];
        $values = [
            $supplierId, $documentId, $sha256, $schemaVersion, $status,
            $provider !== null ? mb_substr($provider, 0, 32) : null,
            $model !== null ? mb_substr($model, 0, 100) : null,
            $payload !== null ? json_encode(CardNumberMask::scrubPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            $error !== null ? mb_substr($error, 0, 500) : null,
            date('Y-m-d H:i:s'),
        ];
        foreach (self::FIELDS as $f) {
            $cols[] = $f;
            $values[] = $fields[$f] ?? null;
        }
        $updates = [];
        foreach ($cols as $c) {
            if (!in_array($c, ['supplier_id', 'sha256', 'schema_version'], true)) {
                $updates[] = "$c = VALUES($c)";
            }
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_extractions (' . implode(', ', $cols) . ')
             VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), ' . implode(', ', $updates)
        );
        $stmt->execute($values);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Vytěžení pro dokument — přes jeho obsah, takže platí i pro kopii téhož souboru.
     *
     * @return array<string,mixed>|null
     */
    public function findForDocument(int $supplierId, int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.*, d.id AS document_id
               FROM documents d
               JOIN document_extractions e ON e.supplier_id = d.supplier_id AND e.sha256 = d.sha256 AND e.status = 'ok'
              WHERE d.supplier_id = ? AND d.id = ?
              ORDER BY e.schema_version DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Vytěžení všech dokumentů připojených k dokladu (přijatá / vydaná faktura,
     * pokladní doklad …), u každého dokumentu nejnovější verze schématu.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEntity(int $supplierId, string $entityType, int $entityId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH linked AS (
                SELECT e.*, d.id AS linked_document_id, d.original_name AS document_name,
                       ROW_NUMBER() OVER (PARTITION BY d.id ORDER BY e.schema_version DESC) AS rn
                  FROM document_links dl
                  JOIN documents d ON d.id = dl.document_id AND d.supplier_id = dl.supplier_id AND d.deleted_at IS NULL
                  JOIN document_extractions e ON e.supplier_id = d.supplier_id AND e.sha256 = d.sha256 AND e.status = 'ok'
                 WHERE dl.supplier_id = ? AND dl.entity_type = ? AND dl.entity_id = ?
             )
             SELECT * FROM linked WHERE rn = 1 ORDER BY linked_document_id"
        );
        $stmt->execute([$supplierId, $entityType, $entityId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['document_id'] = (int) $row['linked_document_id'];
            unset($row['linked_document_id'], $row['rn']);
            $out[] = self::cast($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'schema_version'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = (int) $row[$k];
            }
        }
        $row['document_id'] = isset($row['document_id']) ? (int) $row['document_id'] : null;
        foreach (['total_with_vat', 'amount_due'] as $k) {
            $row[$k] = isset($row[$k]) ? (float) $row[$k] : null;
        }
        if (isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }
        return $row;
    }
}
