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
 * Zápis převedený před zavedením otisků (otisk v mapě chybí) se porovná přímo se zdrojem.
 *
 * Zásah uživatele, kvůli kterému převod zápis nemění, jen ohlásí: storno, párování
 * saldokonta, rozpad úhrad, poznámka nebo příloha, vazba na doklad zadaná v MyÚčtu a vazba
 * z jiné evidence (interní doklad, zápočet, ostatní položka, mzdy, sklad, majetek, časové
 * rozlišení).
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
                "SELECT l.entry_id, l.account_id, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
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
     * Zápisy období, se kterými uživatel v MyÚčtu pracoval (viz popis třídy).
     *
     * @return array<int,true>
     */
    public function touchedEntries(int $supplierId, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND (e.reversed_by IS NOT NULL
                  OR EXISTS (SELECT 1 FROM journal_entries r WHERE r.supplier_id = e.supplier_id AND r.reversed_by = e.id)
                  OR EXISTS (SELECT 1 FROM journal_line_pairing x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_line_pairing_items x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_settlement_allocations x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_notes x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_attachments x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_pair_reversals x WHERE x.entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_pair_reversals x WHERE x.reversal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM accrual_entry_links x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM internal_documents x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM invoice_settlements x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM offset_agreements x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM other_items x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM payroll_posting_batches x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM stock_documents x WHERE x.journal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM assets x WHERE x.disposal_entry_id = e.id)
                  OR EXISTS (SELECT 1 FROM journal_entry_document_links k
                              WHERE k.entry_id = e.id AND (k.note IS NULL OR k.note NOT LIKE '%Money S3%')))"
        );
        $stmt->execute([$supplierId, $periodId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
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
