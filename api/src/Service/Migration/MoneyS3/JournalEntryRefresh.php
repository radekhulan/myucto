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
 * Zápis převedený před zavedením otisků, který se od Money liší, převod přepíše jen tehdy,
 * když se jeho řádky od převodu nezměnily ({@see pristineEntries()}); jinak nejde poznat,
 * zda rozdíl způsobilo Money, nebo oprava v MyÚčtu, a převod ho jen ohlásí.
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
     * pracovalo ({@see DocumentRefresh::touchedSql()}) - jinak by se rozešel doklad s deníkem.
     *
     * @return array<int,true>
     */
    public function touchedEntries(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(self::touchedEntriesSql());
        $stmt->execute([$supplierId, $periodId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Dotaz {@see touchedEntries()}; parametry firma a období. */
    public static function touchedEntriesSql(): string
    {
        $managed = implode(', ', array_map(static fn (string $c): string => "'{$c}'", DimensionImporter::MANAGED_TYPE_CODES));
        return "SELECT e.id FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND (e.reversed_by IS NOT NULL
                  OR EXISTS (SELECT 1 FROM journal_entries r WHERE r.supplier_id = e.supplier_id AND r.reversed_by = e.id)
                  OR EXISTS (SELECT 1 FROM journal_line_pairing x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_line_pairing_items x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_settlement_allocations x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_notes x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_attachments x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_pair_reversals x WHERE x.supplier_id = e.supplier_id AND x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_pair_reversals x WHERE x.supplier_id = e.supplier_id AND x.reversal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM accrual_entry_links x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM internal_documents x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM invoice_settlements x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM offset_agreements x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM other_items x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM payroll_posting_batches x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM stock_documents x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM assets x WHERE x.supplier_id = e.supplier_id AND x.disposal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM fuelings x WHERE x.supplier_id = e.supplier_id AND x.source_journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM bank_posting_suggestions x WHERE x.supplier_id = e.supplier_id AND x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM document_links x WHERE x.supplier_id = e.supplier_id AND x.entity_type = 'journal_entry' AND x.entity_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_document_links k
                              WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND (k.note IS NULL OR k.note NOT LIKE '%Money S3%'))
                  OR EXISTS (SELECT 1 FROM journal_entry_lines l
                               JOIN journal_entry_line_dimension_splits d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
                               JOIN dimension_types t ON t.id = d.dimension_type_id
                              WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id AND t.code NOT IN ({$managed}))
                  OR EXISTS (SELECT 1 FROM journal_entry_document_links k
                               JOIN invoices d ON d.id = k.doc_id AND d.supplier_id = k.supplier_id
                              WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = 'invoice' AND " . DocumentRefresh::touchedSql(true, 'd') . ")
                  OR EXISTS (SELECT 1 FROM journal_entry_document_links k
                               JOIN purchase_invoices d ON d.id = k.doc_id AND d.supplier_id = k.supplier_id
                              WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = 'purchase_invoice' AND " . DocumentRefresh::touchedSql(false, 'd') . '))';
    }

    /**
     * Zápisy, jejichž řádky se od převodu nezměnily: tabulka řádků deníku je systémově
     * verzovaná a změna řádku (úprava, přeúčtování, smazání) po sobě nechá historickou verzi.
     * Rozhoduje u zápisů převedených před zavedením otisků - jen u nich převod pozná, že
     * rozdíl proti Money nezpůsobila úprava v MyÚčtu.
     *
     * @param list<int> $entryIds
     * @return array<int,true>
     */
    public function pristineEntries(int $supplierId, array $entryIds): array
    {
        $out = [];
        foreach (array_chunk($entryIds, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.entry_id, COUNT(*) AS all_rows, SUM(l.ROW_END > NOW(6)) AS current_rows
                   FROM journal_entry_lines FOR SYSTEM_TIME ALL l
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$in})
                  GROUP BY l.entry_id"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ((int) $r['all_rows'] === (int) $r['current_rows']) {
                    $out[(int) $r['entry_id']] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Dimenze typů, které převod nespravuje, přiřazené v MyÚčtu řádkům zápisu - přepis
     * řádků je přenese na řádky se stejným pořadím ({@see restoreUserDimensions()}).
     *
     * @return list<array{line_no:int,type_id:int,value_id:int,origin:string,origin_ref:?string}>
     */
    public function userDimensions(int $supplierId, int $entryId): array
    {
        $managed = implode(', ', array_map(static fn (string $c): string => "'{$c}'", DimensionImporter::MANAGED_TYPE_CODES));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.line_no, d.dimension_type_id, d.dimension_value_id, d.assignment_origin, d.assignment_origin_ref
               FROM journal_entry_lines l
               JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
               JOIN dimension_types t ON t.id = d.dimension_type_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND t.code NOT IN ({$managed})"
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_map(static fn (array $r): array => [
            'line_no' => (int) $r['line_no'], 'type_id' => (int) $r['dimension_type_id'], 'value_id' => (int) $r['dimension_value_id'],
            'origin' => (string) $r['assignment_origin'], 'origin_ref' => $r['assignment_origin_ref'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array{line_no:int,type_id:int,value_id:int,origin:string,origin_ref:?string}> $dimensions */
    public function restoreUserDimensions(int $supplierId, int $entryId, array $dimensions): void
    {
        if ($dimensions === []) {
            return;
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
             SELECT l.id, ?, l.supplier_id, ?, ?, ? FROM journal_entry_lines l WHERE l.supplier_id = ? AND l.entry_id = ? AND l.line_no = ?'
        );
        foreach ($dimensions as $d) {
            $insert->execute([$d['type_id'], $d['value_id'], $d['origin'], $d['origin_ref'], $supplierId, $entryId, $d['line_no']]);
        }
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
