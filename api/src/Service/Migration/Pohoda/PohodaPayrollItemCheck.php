<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

/**
 * Kontrola mzdových závazků (331, 336, 342) firmy převedené z POHODY a jejich opravy
 * (`api/bin/pohoda-backfill-payroll-items.php --check`). Čte jen databázi, export POHODY
 * nepotřebuje.
 *
 * Kontrola hlásí:
 *   - `duplicates`: stejný mzdový závazek víckrát (strana, syntetický účet, částka, partner
 *     a měsíc dokladu), s původem položky (převod POHODY, PAMICA, jiný),
 *   - `uncovered`: mzdové závazky bez vlastního zápisu, jejichž předpis deník měsíce dokladu
 *     a měsíce před ním nenese (předpis = Dal účtu zápisů mimo banku, pokladnu a ostatní
 *     položky; závazek z počátečního stavu se nehlídá, {@see OtherItemImporter} hlídá totéž
 *     při převodu),
 *   - `double_months`: měsíce, kdy předpis mezd na 331 nese převzatý deník POHODY i zápis
 *     zadaný v MyÚčtu (typicky zaúčtování mezd z PAMICY načtené po převodu, který měsíc
 *     už obsahoval) - deník má mzdy dvakrát,
 *   - `item_debits`: zápisy ostatních položek, které účtují na vrub 331 (srážky ze mzdy
 *     v závazku k instituci), po měsících, odděleně převzaté z POHODY a zaúčtované v MyÚčtu,
 *     vedle srážek interních dokladů téhož měsíce - stejný součet znamená srážky dvakrát.
 *
 * Opravy jdou jen výslovně a s `$commit`, jinak nanečisto v transakci, která se vrátí:
 *   - `cancel_duplicates` (deník nemění): zruší duplicitní potvrzené závazky bez zápisu,
 *     bez úhrad, které založil převod POHODY a jejichž předpis deník nenese,
 *   - `reverse_pamica` (MĚNÍ DENÍK, jen se souhlasem vlastníka): stornuje doklady zaúčtování
 *     mezd z PAMICY za měsíce, které převzatý deník POHODY už nese. Měsíc s uhrazeným
 *     závazkem z PAMICY se nestornuje. Mzdové závazky POHODY za takový měsíc pak doplní
 *     doplnění z exportu ({@see PohodaPayrollItemBackfill}).
 */
final class PohodaPayrollItemCheck
{
    /** Rozdíl předpisu 331 načtení z PAMICY a převzatého deníku POHODY, který se ještě bere jako shoda (Kč). */
    public const PAMICA_TOLERANCE = 5.0;

    private const PAYROLL_SQL = "(a.account_code LIKE '331%' OR a.account_code LIKE '336%' OR a.account_code LIKE '342%')";

    /** Živý zápis: není stornovaný a sám není protizápisem storna. */
    private const LIVE_ENTRY_SQL = 'e.reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)';

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemService $otherItems,
        private readonly InternalDocumentService $internalDocuments,
    ) {}

    /**
     * @param array{cancel_duplicates?:bool,reverse_pamica?:bool} $fix
     * @return array<string,mixed>
     */
    public function run(int $supplierId, array $fix, bool $commit, ?int $userId): array
    {
        $report = $this->check($supplierId);
        if (($fix['cancel_duplicates'] ?? false) === false && ($fix['reverse_pamica'] ?? false) === false) {
            return $report;
        }
        if (($fix['reverse_pamica'] ?? false) && $commit && $userId === null) {
            // Storno mění deník: zápis musí mít autora.
            throw new \InvalidArgumentException('Storno zaúčtování mezd z PAMICY zapisuje do deníku, zadejte autora (--user=ID).');
        }
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec('SAVEPOINT pohoda_payroll_check') : $pdo->beginTransaction();
        try {
            $before = $this->journalCount($supplierId);
            $report['fixes'] = [
                'cancelled' => ($fix['cancel_duplicates'] ?? false) ? $this->cancelDuplicates($supplierId, $report, $userId) : [],
                'reversed' => ($fix['reverse_pamica'] ?? false) ? $this->reversePamica($supplierId, $report, $userId) : [],
            ];
            $report['fixes']['journal_entries_added'] = $this->journalCount($supplierId) - $before;
            $report['after'] = $this->check($supplierId);
            if ($commit) {
                $nested ? $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_check') : $pdo->commit();
            } elseif ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_check');
                $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_check');
            } else {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            TransactionFailure::rollbackIfAborted($pdo, $e);
            if ($pdo->inTransaction()) {
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_check');
                    $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_check');
                } else {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
        return $report;
    }

    /** @return array<string,mixed> */
    public function check(int $supplierId): array
    {
        $items = $this->items($supplierId);
        $double = $this->doubleMonths($supplierId);
        return [
            'duplicates' => self::duplicates($items),
            'uncovered' => $this->uncovered($supplierId, $items),
            'double_months' => $double['months'],
            'pamica_overlaps' => $double['pamica'],
            'item_debits' => $this->itemDebits($supplierId),
        ];
    }

    /** @return list<array<string,mixed>> mzdové ostatní položky firmy s původem a úhradou */
    private function items(int $supplierId): array
    {
        $pamica = [];
        $stmt = $this->db->pdo()->prepare('SELECT period, other_item_ids FROM payroll_posting_imports WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (json_decode((string) $row['other_item_ids'], true) ?: [] as $id) {
                $pamica[(int) $id] = (string) $row['period'];
            }
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.document_no, oi.side, oi.account_code, oi.amount, oi.partner_name, oi.title, oi.issued_on,
                    oi.status, oi.journal_entry_id, oi.settled_outside_amount, COALESCE(p.paid, 0) AS allocated,
                    EXISTS (SELECT 1 FROM pohoda_import_map m
                             WHERE m.supplier_id = oi.supplier_id AND m.kind = 'other_item' AND m.target_id = oi.id) AS from_pohoda
               FROM other_items oi
          LEFT JOIN (SELECT other_item_id, SUM(amount) AS paid FROM other_item_allocations
                      WHERE supplier_id = ? AND reversed_on IS NULL GROUP BY other_item_id) p ON p.other_item_id = oi.id
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status IN ('confirmed', 'posted')
                AND (oi.account_code LIKE '331%' OR oi.account_code LIKE '336%' OR oi.account_code LIKE '342%')
              ORDER BY oi.issued_on, oi.id"
        );
        $stmt->execute([$supplierId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $out[] = [
                'id' => $id, 'document_no' => (string) $row['document_no'], 'side' => (string) $row['side'],
                'account' => self::synthetic((string) $row['account_code']), 'amount' => round((float) $row['amount'], 2),
                'partner' => (string) ($row['partner_name'] ?? ''), 'title' => (string) $row['title'], 'issued_on' => (string) $row['issued_on'],
                'status' => (string) $row['status'], 'entry_id' => $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : null,
                'paid' => round((float) $row['allocated'] + (float) $row['settled_outside_amount'], 2),
                'origin' => (int) $row['from_pohoda'] === 1 ? 'pohoda' : (isset($pamica[$id]) ? 'pamica' : 'other'),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{key:string,items:list<array<string,mixed>>}>
     */
    private static function duplicates(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = implode('|', [$item['side'], $item['account'], number_format($item['amount'], 2, '.', ''), mb_strtolower($item['partner']), substr($item['issued_on'], 0, 7)]);
            $groups[$key][] = $item;
        }
        $out = [];
        foreach ($groups as $key => $group) {
            if (count($group) > 1) {
                $out[] = ['key' => $key, 'items' => $group];
            }
        }
        return $out;
    }

    /**
     * Mzdové závazky bez vlastního zápisu nad předpis deníku (Dal účtu mimo banku, pokladnu,
     * ostatní položky, počáteční stav a uzávěrku) měsíce dokladu a měsíce před ním.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function uncovered(int $supplierId, array $items): array
    {
        // Stejně jako převod ({@see OtherItemImporter}): předpis po zápisech, jen Dal účtu.
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.entry_date, SUBSTRING(REGEXP_REPLACE(a.account_code, '[^0-9]', ''), 1, 3) AS synthetic, SUM(l.signed_amount) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.side = 'credit' AND " . self::PAYROLL_SQL . "
                AND e.source_type NOT IN ('bank', 'cash', 'other_item', 'opening', 'closing')
                AND " . self::LIVE_ENTRY_SQL . "
              GROUP BY e.id, e.entry_date, synthetic"
        );
        $stmt->execute([$supplierId]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = ['key' => PohodaPayrollPredpis::key((string) $row['synthetic'], 'commitment'),
                'date' => (string) $row['entry_date'], 'cents' => (int) round((float) $row['amount'] * 100)];
        }
        $bounds = $this->db->pdo()->prepare('SELECT MIN(entry_date) FROM journal_entries WHERE supplier_id = ?');
        $bounds->execute([$supplierId]);
        $firstEntry = $bounds->fetchColumn();
        $predpis = PohodaPayrollPredpis::fromEntries($entries, [], $firstEntry ? substr((string) $firstEntry, 0, 7) : null);
        // Závazek z počátečního stavu (před prvním účetním obdobím firmy) předpis v deníku nemá.
        $first = $this->db->pdo()->prepare('SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ?');
        $first->execute([$supplierId]);
        $start = (string) ($first->fetchColumn() ?: '0000-00-00');
        $out = [];
        // Položky jdou podle data a id ({@see items()}): přednost má starší, výsledek nezávisí na pořadí zadání.
        foreach ($items as $item) {
            if ($item['side'] !== 'payable' || $item['entry_id'] !== null || $item['issued_on'] < $start) {
                continue;
            }
            $key = PohodaPayrollPredpis::key($item['account'], 'commitment');
            $cents = (int) round($item['amount'] * 100);
            $remaining = $predpis->remaining($key, $item['issued_on']);
            if ($remaining !== null && !$predpis->covers($key, $item['issued_on'], $cents)) {
                $out[] = $item + ['uncovered' => round(($cents - max(0, $remaining)) / 100, 2), 'predpis_month' => $predpis->month($key, $item['issued_on'])];
            }
            $predpis->consume($key, $item['issued_on'], $cents);
        }
        return $out;
    }

    /**
     * Měsíce s předpisem mezd na 331 (Dal) z převzatého deníku POHODY i ze zápisu zadaného
     * v MyÚčtu (`months`) a načtení z PAMICY, která takový měsíc zdvojují (`pamica`).
     * Stornované zápisy a protizápisy storen se nepočítají.
     *
     * @return array{months:list<array{month:string,pohoda:float,myucto:float}>,pamica:list<array<string,mixed>>}
     */
    private function doubleMonths(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(e.entry_date, '%Y-%m') AS month,
                    EXISTS (SELECT 1 FROM pohoda_import_map m
                             WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id) AS from_pohoda,
                    SUM(l.signed_amount) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.side = 'credit' AND a.account_code LIKE '331%'
                AND e.source_type NOT IN ('bank', 'cash', 'opening', 'closing') AND " . self::LIVE_ENTRY_SQL . "
              GROUP BY month, from_pohoda"
        );
        $stmt->execute([$supplierId]);
        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $months[(string) $row['month']][(int) $row['from_pohoda'] === 1 ? 'pohoda' : 'myucto'] = round((float) $row['amount'], 2);
        }
        $out = [];
        ksort($months);
        foreach ($months as $month => $sides) {
            if (($sides['pohoda'] ?? 0.0) > 0.005 && ($sides['myucto'] ?? 0.0) > 0.005) {
                $out[] = ['month' => (string) $month, 'pohoda' => $sides['pohoda'], 'myucto' => $sides['myucto']];
            }
        }
        return ['months' => $out, 'pamica' => $this->pamicaOverlaps($supplierId, $months)];
    }

    /**
     * Načtená zaúčtování mezd z PAMICY (klíčem je párovací symbol, jeden měsíc může mít víc
     * načtení, třeba opravné), jejichž interní doklady leží v měsících s předpisem 331
     * z převzatého deníku POHODY. Načtení, která sdílejí měsíc, tvoří skupinu: `amount` =
     * Dal 331 živých zápisů interních dokladů všech načtení skupiny, `pohoda` = Dal 331
     * převzatého deníku v měsících skupiny. Skupina se porovnává a stornuje celá.
     *
     * @param array<string,array{pohoda?:float,myucto?:float}> $months
     * @return list<array{import_ids:list<int>,sym_pars:list<string>,months:list<string>,amount:float,pohoda:float,documents:list<int>,items:list<int>}>
     */
    private function pamicaOverlaps(int $supplierId, array $months): array
    {
        $pdo = $this->db->pdo();
        $imports = $pdo->prepare('SELECT id, sym_par, period, internal_document_ids, other_item_ids FROM payroll_posting_imports WHERE supplier_id = ? ORDER BY period, id');
        $imports->execute([$supplierId]);
        $imported = [];
        foreach ($imports->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documents = array_values(array_map('intval', json_decode((string) $row['internal_document_ids'], true) ?: []));
            $items = array_values(array_map('intval', json_decode((string) $row['other_item_ids'], true) ?: []));
            $amount = 0.0;
            $importMonths = [];
            if ($documents !== []) {
                $stmt = $pdo->prepare(
                    "SELECT DATE_FORMAT(e.entry_date, '%Y-%m') AS month,
                            SUM(CASE WHEN l.side = 'credit' AND a.account_code LIKE '331%' THEN l.signed_amount ELSE 0 END) AS amount
                       FROM internal_documents d
                       JOIN journal_entries e ON e.id = d.journal_entry_id AND e.supplier_id = d.supplier_id
                       JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                       JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                      WHERE d.supplier_id = ? AND d.id IN (" . implode(',', array_fill(0, count($documents), '?')) . ")
                        AND " . self::LIVE_ENTRY_SQL . "
                      GROUP BY month"
                );
                $stmt->execute([$supplierId, ...$documents]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $importMonths[] = (string) $m['month'];
                    $amount += (float) $m['amount'];
                }
            }
            if ($amount > 0.005) {
                $imported[] = ['import_ids' => [(int) $row['id']], 'sym_pars' => [(string) $row['sym_par']], 'months' => $importMonths,
                    'amount' => $amount, 'documents' => $documents, 'items' => $items];
            }
        }
        // Načtení se společným měsícem do jedné skupiny.
        $groups = [];
        foreach ($imported as $import) {
            foreach ($groups as $i => $group) {
                if (array_intersect($group['months'], $import['months']) !== []) {
                    $import = [
                        'import_ids' => [...$group['import_ids'], ...$import['import_ids']], 'sym_pars' => [...$group['sym_pars'], ...$import['sym_pars']],
                        'months' => array_values(array_unique([...$group['months'], ...$import['months']])), 'amount' => $group['amount'] + $import['amount'],
                        'documents' => [...$group['documents'], ...$import['documents']], 'items' => [...$group['items'], ...$import['items']],
                    ];
                    unset($groups[$i]);
                }
            }
            $groups[] = $import;
        }
        $out = [];
        foreach ($groups as $group) {
            sort($group['months']);
            $pohoda = 0.0;
            foreach ($group['months'] as $month) {
                $pohoda += $months[$month]['pohoda'] ?? 0.0;
            }
            if ($pohoda > 0.005) {
                $out[] = ['amount' => round($group['amount'], 2), 'pohoda' => round($pohoda, 2)] + $group;
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['months'], $a['import_ids']] <=> [$b['months'], $b['import_ids']]);
        return $out;
    }

    /**
     * Na vrub 331 po měsících: zápisy ostatních položek (převzaté z POHODY / zaúčtované
     * v MyÚčtu) a interní doklady (srážky ze mzdy v předpisu).
     *
     * @return list<array{month:string,item_pohoda:float,item_myucto:float,internal:float}>
     */
    private function itemDebits(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(e.entry_date, '%Y-%m') AS month, e.source_type,
                    EXISTS (SELECT 1 FROM pohoda_import_map m
                             WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id) AS from_pohoda,
                    SUM(l.signed_amount) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.side = 'debit' AND a.account_code LIKE '331%'
                AND e.source_type NOT IN ('bank', 'cash', 'opening', 'closing') AND " . self::LIVE_ENTRY_SQL . "
              GROUP BY month, e.source_type, from_pohoda"
        );
        $stmt->execute([$supplierId]);
        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $month = (string) $row['month'];
            $months[$month] ??= ['month' => $month, 'item_pohoda' => 0.0, 'item_myucto' => 0.0, 'internal' => 0.0];
            $key = $row['source_type'] === 'other_item' ? ((int) $row['from_pohoda'] === 1 ? 'item_pohoda' : 'item_myucto') : 'internal';
            $months[$month][$key] = round($months[$month][$key] + (float) $row['amount'], 2);
        }
        ksort($months);
        return array_values(array_filter($months, static fn (array $m): bool => $m['item_pohoda'] + $m['item_myucto'] > 0.005));
    }

    /**
     * @param array<string,mixed> $report
     * @return list<array{id:int,document_no:string,result:string}>
     */
    private function cancelDuplicates(int $supplierId, array $report, ?int $userId): array
    {
        $uncovered = [];
        foreach ($report['uncovered'] as $item) {
            $uncovered[(int) $item['id']] = true;
        }
        $out = [];
        foreach ($report['duplicates'] as $group) {
            // Ponechá se položka s úhradou, jinak zaúčtovaná, jinak nejstarší.
            $keep = $group['items'];
            usort($keep, static fn (array $a, array $b): int => [$b['paid'] > 0, $b['entry_id'] !== null, -$b['id']] <=> [$a['paid'] > 0, $a['entry_id'] !== null, -$a['id']]);
            foreach (array_slice($keep, 1) as $item) {
                if ($item['origin'] !== 'pohoda' || $item['status'] !== 'confirmed' || $item['entry_id'] !== null
                    || $item['paid'] > 0.005 || !isset($uncovered[$item['id']])) {
                    $out[] = ['id' => $item['id'], 'document_no' => $item['document_no'], 'result' => 'kept'];
                    continue;
                }
                try {
                    $this->otherItems->reverse($supplierId, $item['id'], 'Duplicitní mzdový závazek z převodu POHODY bez předpisu v deníku', $userId);
                    $out[] = ['id' => $item['id'], 'document_no' => $item['document_no'], 'result' => 'cancelled'];
                } catch (OtherItemException $e) {
                    $out[] = ['id' => $item['id'], 'document_no' => $item['document_no'], 'result' => 'refused: ' . $e->getMessage()];
                }
            }
        }
        return $out;
    }

    /**
     * Storno načtení z PAMICY, která zdvojují měsíce převzaté z POHODY, po skupinách
     * ({@see pamicaOverlaps()}): celá skupina, nebo nic. Jen když součet předpisu 331 načtení
     * skupiny odpovídá předpisu POHODY v jejích měsících (do {@see PAMICA_TOLERANCE} Kč), jinak
     * `refused: amounts_differ` - nejde o tytéž mzdy. Uhrazený závazek storno celé skupiny
     * zastaví (`refused: ...`), skupina bez platného dokladu je `nothing_to_reverse`.
     *
     * @param array<string,mixed> $report
     * @return list<array{import_ids:list<int>,sym_pars:list<string>,result:string,documents:list<int>,items:list<int>}>
     */
    private function reversePamica(int $supplierId, array $report, ?int $userId): array
    {
        $out = [];
        $audit = ['user_id' => $userId, 'posted_by' => $userId, 'ip' => null, 'user_agent' => 'pohoda-backfill-payroll-items'];
        $reason = 'Zaúčtování mezd z PAMICY zdvojuje měsíc převzatý z POHODY';
        $pdo = $this->db->pdo();
        foreach ($report['pamica_overlaps'] as $import) {
            $base = ['import_ids' => $import['import_ids'], 'sym_pars' => $import['sym_pars'], 'documents' => $import['documents'], 'items' => $import['items']];
            if (abs($import['amount'] - $import['pohoda']) > self::PAMICA_TOLERANCE) {
                $out[] = ['result' => sprintf('refused: amounts_differ (PAMICA %.2f, POHODA %.2f)', $import['amount'], $import['pohoda'])] + $base;
                continue;
            }
            $pdo->exec('SAVEPOINT pohoda_payroll_pamica');
            try {
                $changed = 0;
                $status = $pdo->prepare("SELECT status FROM other_items WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL");
                foreach ($import['items'] as $id) {
                    $status->execute([$supplierId, $id]);
                    if (in_array($status->fetchColumn(), ['confirmed', 'posted'], true)) {
                        $this->otherItems->reverse($supplierId, $id, $reason, $userId);
                        $changed++;
                    }
                }
                $docStatus = $pdo->prepare("SELECT status FROM internal_documents WHERE supplier_id = ? AND id = ?");
                foreach ($import['documents'] as $id) {
                    $docStatus->execute([$supplierId, $id]);
                    if ($docStatus->fetchColumn() === 'posted') {
                        $this->internalDocuments->reverse($supplierId, $id, $reason, null, $audit);
                        $changed++;
                    }
                }
                $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_pamica');
                $out[] = ['result' => $changed > 0 ? 'reversed' : 'nothing_to_reverse'] + $base;
            } catch (OtherItemException | InternalDocumentException | PostingException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_pamica');
                $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_pamica');
                $out[] = ['result' => 'refused: ' . $e->getMessage()] + $base;
            }
        }
        return $out;
    }

    private function journalCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private static function synthetic(string $account): string
    {
        return substr((string) preg_replace('/\D+/', '', $account), 0, 3);
    }

    /**
     * @param array<string,mixed> $report
     * @param (callable(string):void) $print
     */
    public static function print(array $report, callable $print): void
    {
        $money = static fn (float $v): string => number_format($v, 2, ',', ' ');
        $print('    Duplicitní mzdové závazky: ' . count($report['duplicates']) . ' skupin');
        foreach ($report['duplicates'] as $group) {
            $print('      ' . $group['key'] . ': ' . implode('; ', array_map(static fn (array $i): string => sprintf('#%d %s %s %s zaplaceno %s',
                $i['id'], $i['document_no'], $i['status'], $i['origin'], $money($i['paid'])), $group['items'])));
        }
        $print('    Mzdové závazky bez předpisu v deníku: ' . count($report['uncovered']));
        foreach ($report['uncovered'] as $i) {
            $print(sprintf('      #%d %s %s %s %s, bez předpisu %s, původ %s', $i['id'], $i['document_no'], $i['account'], $i['issued_on'], $money($i['amount']), $money($i['uncovered']), $i['origin']));
        }
        $print('    Měsíce s předpisem mezd 331 z POHODY i z MyÚčta (deník má mzdy dvakrát): ' . count($report['double_months']));
        foreach ($report['double_months'] as $m) {
            $print(sprintf('      %s  POHODA %s, MyÚčto %s', $m['month'], $money($m['pohoda']), $money($m['myucto'])));
        }
        foreach ($report['pamica_overlaps'] as $i) {
            $print(sprintf('      načtení z PAMICY #%s (%s): předpis 331 %s proti POHODĚ %s v měsících %s', implode(', #', $i['import_ids']), implode(', ', $i['sym_pars']),
                $money($i['amount']), $money($i['pohoda']), implode(', ', $i['months'])));
        }
        $print('    Na vrub 331 v zápisech ostatních položek (převzaté / z MyÚčta) proti srážkám interních dokladů:');
        foreach ($report['item_debits'] as $m) {
            $flag = abs($m['item_pohoda'] + $m['item_myucto'] - $m['internal']) < 0.005 && $m['internal'] > 0 ? '  <- srážky dvakrát?' : '';
            $print(sprintf('      %s  položky %s / %s, interní doklady %s%s', $m['month'], $money($m['item_pohoda']), $money($m['item_myucto']), $money($m['internal']), $flag));
        }
        if (isset($report['fixes'])) {
            foreach ($report['fixes']['cancelled'] as $c) {
                $print("    Zrušení duplicity #{$c['id']} {$c['document_no']}: {$c['result']}");
            }
            foreach ($report['fixes']['reversed'] as $r) {
                $print('    Storno PAMICY #' . implode(', #', $r['import_ids']) . ' (' . implode(', ', $r['sym_pars']) . "): {$r['result']} (interní doklady " . implode(',', $r['documents']) . ', závazky ' . implode(',', $r['items']) . ')');
            }
            $print('    Nových zápisů deníku (storna): ' . $report['fixes']['journal_entries_added']);
        }
    }
}
