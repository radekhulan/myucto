<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Pouze čtení nejednoznačných vlastnictví vynechaných migrací 2074. */
final class ImportedPostingDiagnostics
{
    public const PAGE_SIZE = 20;
    public const CANDIDATE_LIMIT = 20;

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function report(int $supplierId, int $after = 0): array
    {
        $result = self::unavailable($supplierId);
        if ($supplierId <= 0) return $result;
        // Diagnostika musí zůstat dostupná i před dokončením migrací.
        foreach (['stereo_nx_import_map', 'premier_import_map', 'money_s3_import_map', 'pohoda_import_map',
            'abra_flexi_import_map', 'journal_entry_document_links', 'journal_entries', 'invoices',
            'purchase_invoices', 'journal_document_postings'] as $table) {
            if (!$this->db->hasTable($table)) return $result;
        }
        $result['available'] = true;
        $result['total'] = 0;
        $cte = self::candidatesSql();
        $params = array_fill(0, 5, $supplierId);
        $count = $this->db->pdo()->prepare($cte . ' SELECT COUNT(*) FROM ambiguous');
        $count->execute($params);
        $result['total'] = (int) $count->fetchColumn();
        if ($result['total'] === 0) return $result;

        // Kurzorem se stránkují zápisy, ne jednotlivé vazby; oba náhledy jsou omezené
        // už v SQL. Úplný počet vzniká nad importními mapami firmy, nikoli skenem deníku.
        $limit = self::PAGE_SIZE + 1;
        $candidates = self::CANDIDATE_LIMIT;
        $stmt = $this->db->pdo()->prepare($cte . ", page AS (
            SELECT entry_id, owners FROM ambiguous WHERE entry_id > ? ORDER BY entry_id LIMIT {$limit}
        ), ranked AS (
            SELECT c.*, p.owners, ROW_NUMBER() OVER (PARTITION BY c.entry_id ORDER BY c.doc_type, c.doc_id) AS candidate_no
              FROM page p JOIN candidates c ON c.entry_id = p.entry_id
        )
        SELECT r.entry_id, r.owners, r.doc_type, r.doc_id, r.document_number, e.entry_date, e.description
          FROM ranked r
          STRAIGHT_JOIN journal_entries e FORCE INDEX (PRIMARY) ON e.supplier_id = ? AND e.id = r.entry_id
         WHERE r.candidate_no <= {$candidates} ORDER BY r.entry_id, r.candidate_no");
        $stmt->execute([...$params, max(0, $after), $supplierId]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['entry_id'];
            $entries[$id] ??= ['entry_id' => $id, 'entry_date' => $row['entry_date'], 'description' => (string) ($row['description'] ?? ''),
                'owner_count' => (int) $row['owners'], 'candidates' => []];
            $entries[$id]['candidates'][] = ['doc_type' => $row['doc_type'], 'doc_id' => (int) $row['doc_id'],
                'document_number' => $row['document_number']];
        }
        if (count($entries) > self::PAGE_SIZE) {
            array_pop($entries);
            $result['next_after'] = array_key_last($entries);
        }
        $result['entries'] = array_values($entries);
        return $result;
    }

    /** Nedostupná databáze či schéma není úspěšná kontrola s nulovým nálezem. */
    public static function unavailable(int $supplierId): array
    {
        return ['available' => false, 'supplier_id' => $supplierId, 'total' => null, 'entries' => [], 'next_after' => null,
            'per_page' => self::PAGE_SIZE, 'candidate_limit' => self::CANDIDATE_LIMIT];
    }

    /**
     * Záměrně stejné kandidáty jako nasazená migrace 2074: mapa konkrétního
     * převodu + jeho poznámka + existující hlavička firmy + způsobilý zdroj.
     * Oproti backfillu vracíme pouze více různých vlastníků a vyřadíme již
     * ručně vyřešené vlastnictví. Informativní „Souvisí“ není vlastník.
     */
    public static function candidatesSql(): string
    {
        return "WITH imported AS (
            SELECT supplier_id, target_id, 'Převzato z Stereo NX' AS note FROM stereo_nx_import_map FORCE INDEX (ix_stereo_nx_import_map_target)
             WHERE supplier_id = ? AND kind = 'accounting_journal'
            UNION
            SELECT supplier_id, target_id, 'Převzato z PREMIER' FROM premier_import_map FORCE INDEX (ix_premier_import_map_target)
             WHERE supplier_id = ? AND kind = 'journal_entry'
            UNION
            SELECT supplier_id, target_id, 'Převzato z Money S3' FROM money_s3_import_map FORCE INDEX (ix_money_s3_import_map_target)
             WHERE supplier_id = ? AND kind = 'journal_entry'
            UNION
            SELECT supplier_id, target_id, 'Převzato z Pohody' FROM pohoda_import_map FORCE INDEX (ix_pohoda_import_map_target)
             WHERE supplier_id = ? AND kind = 'journal_entry'
            UNION
            SELECT supplier_id, target_id, 'Převzato z ABRA Flexi' FROM abra_flexi_import_map FORCE INDEX (PRIMARY)
             WHERE supplier_id = ? AND kind = 'ucetni-denik'
        ), candidates AS (
            SELECT DISTINCT dl.entry_id, dl.doc_type, dl.doc_id,
                   CASE WHEN dl.doc_type = 'invoice' THEN i.varsymbol
                        ELSE pi.vendor_invoice_number END AS document_number
              FROM imported im
              STRAIGHT_JOIN journal_entry_document_links dl FORCE INDEX (uq_jedl_entry_doc)
                ON dl.supplier_id = im.supplier_id AND dl.entry_id = im.target_id AND dl.note = im.note
              STRAIGHT_JOIN journal_entries e ON e.supplier_id = dl.supplier_id AND e.id = dl.entry_id
         LEFT JOIN invoices i ON dl.doc_type = 'invoice' AND i.supplier_id = dl.supplier_id AND i.id = dl.doc_id
         LEFT JOIN purchase_invoices pi ON dl.doc_type = 'purchase_invoice' AND pi.supplier_id = dl.supplier_id AND pi.id = dl.doc_id
         LEFT JOIN journal_document_postings owned ON owned.supplier_id = dl.supplier_id AND owned.entry_id = dl.entry_id
             WHERE dl.doc_type IN ('invoice', 'purchase_invoice') AND owned.id IS NULL
               AND (i.id IS NOT NULL OR pi.id IS NOT NULL)
               AND ((e.source_type IN ('manual', dl.doc_type) AND e.source_id IS NULL)
                    OR (e.source_type = dl.doc_type AND e.source_id = dl.doc_id))
        ), ambiguous AS (
            SELECT entry_id, COUNT(*) AS owners FROM candidates GROUP BY entry_id HAVING COUNT(*) > 1
        )";
    }
}
