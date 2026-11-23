<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Aktualizace otevřeného období při opakovaném převodu: zápis převzatý z Money, který Money
 * mezitím změnilo nebo smazalo, se přepíše (smaže) podle Money - jen když s ním uživatel
 * v MyÚčtu nepracoval.
 *
 * Změnu pozná otisk skupiny řádků deníku ({@see fingerprint()}): součet MD − D po účtech,
 * uložený v mapě převodu při zápisu. Otisk zdroje jiný než uložený = Money zápis změnilo;
 * otisk zápisu v MyÚčtu jiný než uložený = zápis upravil uživatel (ten se nepřepisuje).
 *
 * Zásah uživatele, kvůli kterému převod zápis nemění, jen ohlásí ({@see touchedEntries()}).
 * Zápis převedený před zavedením otisků, který se od Money liší, převod nikdy sám
 * nepřepíše ani nesmaže: nejde poznat, zda rozdíl způsobilo Money, nebo oprava v MyÚčtu.
 * Ohlásí ho účetní k porovnání a otisk mu doplní, až bude s Money shodný.
 */
final class JournalEntryRefresh
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Otisk skupiny řádků deníku: součet MD − D po účtech na haléře, bez nulových účtů.
     * Párování řádků do dvojic (jednoduchá forma zápisu) ani pořadí řádků ho nemění.
     *
     * @param array<int,float> $net účet => MD − D
     */
    public static function fingerprint(array $net): string
    {
        $out = [];
        foreach ($net as $accountId => $amount) {
            $rounded = round($amount, 2);
            if (abs($rounded) >= 0.005) {
                $out[(int) $accountId] = number_format($rounded, 2, '.', '');
            }
        }
        ksort($out);
        return hash('sha256', (string) json_encode($out));
    }

    /**
     * @param list<array{account_id:int,side:string,amount:string|float}> $lines
     * @return array<int,float>
     */
    public static function net(array $lines): array
    {
        $net = [];
        foreach ($lines as $l) {
            $net[(int) $l['account_id']] = ($net[(int) $l['account_id']] ?? 0.0) + ($l['side'] === 'debit' ? 1 : -1) * (float) $l['amount'];
        }
        return $net;
    }

    /**
     * Otisky zápisů období, jak jsou dnes v MyÚčtu (jeden dotaz na období).
     *
     * @param list<int> $entryIds
     * @return array<int,string> zápis => otisk
     */
    public function entryFingerprints(int $supplierId, array $entryIds): array
    {
        $nets = [];
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.entry_id, l.account_id, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END)
                   FROM journal_entry_lines l
                  WHERE l.supplier_id = ? AND l.entry_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                  GROUP BY l.entry_id, l.account_id'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $nets[(int) $r[0]][(int) $r[1]] = (float) $r[2];
            }
        }
        return array_map(self::fingerprint(...), $nets);
    }

    /**
     * Zápisy období, se kterými uživatel v MyÚčtu pracoval (viz popis třídy): storno,
     * párování a rozpad saldokonta, poznámka, příloha nebo dokument, vazba na doklad zadaná
     * v MyÚčtu, rozpad řádku do dimenze typu, který převod nespravuje
     * ({@see DimensionImporter::MANAGED_TYPE_CODES}; přiřazenou dimenzi takového typu přepis
     * zachová, {@see userDimensions()}),
     * vazba z jiné evidence (interní doklad, zápočet, ostatní položka, mzdy, sklad, majetek,
     * časové rozlišení, tankování, návrh zaúčtování) a navázaný doklad, se kterým se
     * pracovalo ({@see DocumentRefresh::touched()}) - jinak by se rozešel doklad s deníkem.
     *
     * @return array<int,true>
     */
    public function touchedEntries(int $supplierId, int $periodId): array
    {
        $parts = self::touchedEntriesQueries();
        $stmt = $this->db->pdo()->prepare(implode("\nUNION\n", $parts));
        $stmt->execute(array_merge(...array_fill(0, count($parts), [$supplierId, $periodId])));
        $out = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        // Zápis dokladu, se kterým se v MyÚčtu pracovalo: doklad se nepřepíše, zápis také ne.
        $links = $this->db->pdo()->prepare(
            "SELECT k.entry_id, k.doc_type, k.doc_id FROM journal_entries e
               JOIN journal_entry_document_links k ON k.supplier_id = e.supplier_id AND k.entry_id = e.id
              WHERE e.supplier_id = ? AND e.period_id = ? AND k.doc_type IN ('invoice', 'purchase_invoice')"
        );
        $links->execute([$supplierId, $periodId]);
        $byDoc = ['invoice' => [], 'purchase_invoice' => []];
        foreach ($links->fetchAll(PDO::FETCH_NUM) as [$entryId, $docType, $docId]) {
            $byDoc[(string) $docType][(int) $docId][] = (int) $entryId;
        }
        $documents = new DocumentRefresh($this->db);
        foreach ($byDoc as $docType => $docs) {
            if ($docs === []) {
                continue;
            }
            foreach (array_keys($documents->touched($supplierId, $docType === 'invoice', array_keys($docs))) as $docId) {
                foreach ($docs[$docId] as $entryId) {
                    $out[$entryId] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Dotazy {@see touchedEntries()}, každý s parametry firma a období. Spojení jdou od
     * zápisů období (index firma, období) po indexu odkazující tabulky - žádný průchod
     * celou tabulkou.
     *
     * @return list<string>
     */
    public static function touchedEntriesQueries(): array
    {
        $managed = implode(', ', array_map(static fn (string $c): string => "'{$c}'", DimensionImporter::MANAGED_TYPE_CODES));
        $base = 'SELECT e.id FROM journal_entries e %s WHERE e.supplier_id = ? AND e.period_id = ?%s';
        $queries = [
            sprintf($base, '', ' AND e.reversed_by IS NOT NULL'),
            // Zápis, který stornoval jiný zápis: od storen firmy (index firma, storno) k zápisu období.
            'SELECT r.reversed_by FROM journal_entries r JOIN journal_entries e ON e.id = r.reversed_by AND e.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? AND r.reversed_by IS NOT NULL AND e.period_id = ?',
            sprintf($base, 'JOIN document_links x ON x.entity_type = \'journal_entry\' AND x.entity_id = e.id AND x.supplier_id = e.supplier_id', ''),
            sprintf($base, 'JOIN journal_entry_document_links k ON k.supplier_id = e.supplier_id AND k.entry_id = e.id', " AND (k.note IS NULL OR k.note NOT LIKE '%Money S3%')"),
            sprintf($base, "JOIN journal_entry_lines l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id
                JOIN journal_entry_line_dimension_splits d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
                JOIN dimension_types t ON t.id = d.dimension_type_id", " AND t.code NOT IN ({$managed})"),
        ];
        foreach ([
            ['journal_line_pairing', 'entry_id'], ['journal_line_pairing_items', 'entry_id'], ['journal_settlement_allocations', 'entry_id'],
            ['journal_entry_notes', 'entry_id'], ['journal_entry_attachments', 'entry_id'], ['journal_pair_reversals', 'entry_id'],
            ['journal_pair_reversals', 'reversal_entry_id'], ['accrual_entry_links', 'journal_entry_id'], ['internal_documents', 'journal_entry_id'],
            ['invoice_settlements', 'journal_entry_id'], ['offset_agreements', 'journal_entry_id'], ['other_items', 'journal_entry_id'],
            ['payroll_posting_batches', 'journal_entry_id'], ['stock_documents', 'journal_entry_id'], ['assets', 'disposal_entry_id'],
            ['fuelings', 'source_journal_entry_id'], ['bank_posting_suggestions', 'journal_entry_id'],
        ] as [$table, $column]) {
            $queries[] = sprintf($base, "JOIN {$table} x ON x.{$column} = e.id AND x.supplier_id = e.supplier_id", '');
        }
        return $queries;
    }

    /**
     * Dimenze typů, které převod nespravuje, přiřazené v MyÚčtu řádkům zápisu - přepis
     * řádků je přenese na řádek se stejným pořadím, účtem a stranou ({@see restoreUserDimensions()}).
     *
     * @return list<array{line_no:int,account_id:int,side:string,type_id:int,value_id:int,origin:string,origin_ref:?string}>
     */
    public function userDimensions(int $supplierId, int $entryId): array
    {
        $managed = implode(', ', array_map(static fn (string $c): string => "'{$c}'", DimensionImporter::MANAGED_TYPE_CODES));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.line_no, l.account_id, l.side, d.dimension_type_id, d.dimension_value_id, d.assignment_origin, d.assignment_origin_ref
               FROM journal_entry_lines l
               JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
               JOIN dimension_types t ON t.id = d.dimension_type_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND t.code NOT IN ({$managed})"
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_map(static fn (array $r): array => [
            'line_no' => (int) $r['line_no'], 'account_id' => (int) $r['account_id'], 'side' => (string) $r['side'],
            'type_id' => (int) $r['dimension_type_id'], 'value_id' => (int) $r['dimension_value_id'],
            'origin' => (string) $r['assignment_origin'], 'origin_ref' => $r['assignment_origin_ref'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Přenese dimenze z MyÚčta na přepsané řádky zápisu. False = některý řádek se stejným
     * pořadím, účtem a stranou v zápisu už není (volající přepis vrátí a zápis ponechá).
     *
     * @param list<array{line_no:int,account_id:int,side:string,type_id:int,value_id:int,origin:string,origin_ref:?string}> $dimensions
     */
    public function restoreUserDimensions(int $supplierId, int $entryId, array $dimensions): bool
    {
        if ($dimensions === []) {
            return true;
        }
        $line = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? AND line_no = ? AND account_id = ? AND side = ?');
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($dimensions as $d) {
            $line->execute([$supplierId, $entryId, $d['line_no'], $d['account_id'], $d['side']]);
            $lineId = $line->fetchColumn();
            if ($lineId === false) {
                return false;
            }
            $insert->execute([(int) $lineId, $d['type_id'], $supplierId, $d['value_id'], $d['origin'], $d['origin_ref']]);
        }
        return true;
    }

    /**
     * Doklady zápisů (zdroj zápisu i vazba zápisu na vydaný či přijatý doklad), jedním
     * dotazem na dávku: zápis => list „druh|id".
     *
     * @param list<int> $entryIds
     * @return array<int,list<string>>
     */
    public function entryDocuments(int $supplierId, array $entryIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($entryIds)), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT e.id, e.source_type, e.source_id FROM journal_entries e
                  WHERE e.supplier_id = ? AND e.id IN ({$in}) AND e.source_type IN ('invoice', 'purchase_invoice') AND e.source_id IS NOT NULL
                 UNION
                 SELECT k.entry_id, k.doc_type, k.doc_id FROM journal_entry_document_links k
                  WHERE k.supplier_id = ? AND k.entry_id IN ({$in}) AND k.doc_type IN ('invoice', 'purchase_invoice')"
            );
            $stmt->execute([$supplierId, ...$chunk, $supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$entryId, $type, $docId]) {
                $out[(int) $entryId][] = $type . '|' . $docId;
            }
        }
        return $out;
    }

    /**
     * Doklad, ke kterému zápis patří (zdroj zápisu), leží v období s podaným přiznáním DPH,
     * kontrolním nebo OSS hlášením: zápis se nepřepisuje, stejně jako doklad
     * ({@see DocumentRefresh::filedPeriod()}), aby se doklad a deník nerozešly.
     *
     * @param array<string,mixed> $header {@see header()}
     */
    public function documentInFiledPeriod(int $supplierId, array $header, DocumentRefresh $documents): bool
    {
        $type = (string) ($header['source_type'] ?? '');
        if ($header['source_id'] === null || !in_array($type, ['invoice', 'purchase_invoice'], true)) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare($type === 'invoice'
            ? 'SELECT issue_date, tax_date, NULL FROM invoices WHERE id = ? AND supplier_id = ?'
            : 'SELECT issue_date, tax_date, received_at FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([(int) $header['source_id'], $supplierId]);
        $dates = $stmt->fetch(PDO::FETCH_NUM);
        return $dates !== false && $documents->filedPeriod($supplierId, [$dates[1] ?? $dates[0], $dates[2], (string) $header['entry_date']]) !== null;
    }

    /**
     * Hlavička zápisu pro přepis řádků ({@see \MyInvoice\Repository\JournalEntryRepository::replace()}):
     * zdroj, vazba na doklad, datum i popis zůstávají, mění se jen řádky.
     *
     * @return array<string,mixed>|null
     */
    public function header(int $supplierId, int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, period_id, entry_date, document_date, document_no, description, source_type, source_id, posted_at, posted_by
               FROM journal_entries WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([$entryId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['period_id'] = (int) $row['period_id'];
        $row['source_id'] = $row['source_id'] === null ? null : (int) $row['source_id'];
        $row['posted_by'] = $row['posted_by'] === null ? null : (int) $row['posted_by'];
        return $row;
    }

    /**
     * Smaže zápis, který Money smazalo. Na zápis smí ukazovat jen vazby, které zmizí s ním
     * (řádky, dimenze, vazby převodu na doklad); odkaz z jiné evidence (cizí klíč bez
     * kaskády) smazání vrátí a zápis zůstane - volající ho ohlásí.
     *
     * @return bool true = smazáno
     */
    public function delete(int $supplierId, int $entryId): bool
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT money_s3_entry_delete');
        try {
            $stmt = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL');
            $stmt->execute([$entryId, $supplierId]);
            $pdo->exec('RELEASE SAVEPOINT money_s3_entry_delete');
            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            if (TransactionFailure::aborted($e) || ($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_entry_delete');
            return false;
        }
    }
}
