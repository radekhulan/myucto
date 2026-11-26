<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;

/**
 * Jednorázové doplnění mzdových závazků (331, 336, 342) do agendy Ostatní pohledávky
 * a závazky u firem převedených z POHODY dřív, než je převod zakládal
 * (`api/bin/pohoda-backfill-payroll-items.php`).
 *
 * Převod je tehdy vynechal jako předpisy mzdového modulu (protokol kroku `other_items`,
 * čítač `payroll_or_tax`) a nechal jen v deníku, takže úhrady z banky nešly spárovat.
 * Firma už účtuje v MyÚčtu a celý převod znovu pustit nejde, opravný převod vazeb nové
 * doklady záměrně nezakládá. Doplnění proto čte původní export POHODY (MyÚčto ho po
 * převodu nedrží) a založí jen chybějící mzdové doklady pravidly převodu
 * ({@see OtherItemImporter::backfillPayroll()}):
 *   - závazek s vlastním zápisem ho převezme bez nového účtování,
 *   - mzda zaměstnance (předpis nese interní doklad mezd) a závazek z počátečního stavu se
 *     založí jako potvrzené bez zápisu,
 *   - úhrady z exportu se přiřadí k převzatým bankovním a pokladním dokladům
 *     ({@see DocumentLinker::matchOtherItemPayments()}), uhrazená část bez pohybu v roce
 *     agendy jde podle úhrad POHODY do `settled_outside_amount`. Jiné úhrady nevznikají.
 *     Pohyb úhrady, který převod bez položky v agendě označil jako vyřízený bez dokladu,
 *     se k přiřazení uvolní ({@see self::releaseBookedPayments()}).
 *
 * Deník se nemění: součty zápisů a řádků se porovnají před a po a rozdíl celé doplnění
 * agendy vrátí. Roky, které původní převod vynechal, a hranici vlastního deníku převezme
 * z jeho protokolu. Výchozí je zkouška nanečisto, zápis jen s `$commit`. Opakované
 * doplnění nic nezdvojí (mapa převodu, stejný klíč jako převod).
 *
 * Běh se zapíše do historie převodů jako převod účetnictví (`mode` import/dry_run, protokol
 * s `payroll_backfill`). Čtenáři historie, kteří hledají původní převod agendy (rok, vynechané
 * roky, {@see self::affected()}), běhy s `payroll_backfill` vynechávají; ostatní čtou jen
 * dokončený převod firmy nebo rok agendy, který doplnění nemění.
 *
 * Ke každé agendě vypíše založené položky (s převzetím zápisu / bez zápisu), vynechané
 * s důvodem a odsouhlasení mzdových účtů: zůstatek účtu v deníku proti otevřeným ostatním
 * položkám na něm ke konci období agendy a k dnešku.
 */
final class PohodaPayrollItemBackfill
{
    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_INVALID = 2;

    /** Syntetické mzdové účty ({@see \MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy::payroll()}). */
    private const ACCOUNTS = ['331', '336', '342'];

    private const REASONS = [
        'item_deleted' => 'položka byla v MyÚčtu smazaná',
        'converted_as_invoice' => 'převod doklad převzal jako fakturu',
        'in_vat_return' => 'doklad je v přiznání DPH, převádí ho krok faktur',
        'amounts' => 'částky, měna nebo záloha, které agenda nepřevezme',
        'entries' => 'doklad má v deníku víc zápisů',
        'posting' => 'zápis neodpovídá jednomu saldokontnímu účtu proti protiúčtům',
        'entry_missing' => 'zápis dokladu v deníku chybí (smazaný)',
        'entry_reversed' => 'zápis dokladu je stornovaný',
        'entry_owned' => 'zápis dokladu už vlastní jiný doklad',
        'entry_linked' => 'zápis dokladu je navázaný na jiný doklad',
        'entry_taken' => 'zápis dokladu už převzala jiná ostatní položka',
        'number_taken' => 'číslo dokladu už má jiná ostatní položka',
        'entry_origin' => 'na řádku zápisu je položka ze zůstatku bez dokladu zadaná v MyÚčtu',
        'possible_manual_duplicate' => 'v agendě je položka se stejným účtem, částkou a měsícem, kterou převod nezaložil (MyÚčto, PAMICA)',
        'payroll_imported' => 'zaúčtování mezd měsíce je načtené z PAMICY i s jejími závazky',
        'predpis_exceeded' => 'předpis mezd měsíce v deníku už pokrývají jiné závazky (doklad v POHODĚ dvakrát?)',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $runs,
        private readonly AccountingPeriodRepository $periods,
        private readonly OtherItemImporter $otherItems,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly ClosingRepository $closing,
    ) {}

    /**
     * @param int|null $supplierId firma, `null` = všechny firmy, jejichž převod mzdové závazky vynechal
     * @param string|null $source ZIP exportu, složka agendy (`IČO_rok`) nebo složka s agendami
     *        (u všech firem složka s agendami víc firem); `null` = jen přehled dotčených firem
     * @param (callable(string):void) $print
     */
    public function run(?int $supplierId, ?string $source, bool $commit, ?int $year, ?int $userId, callable $print, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $firms = $this->affected($supplierId, $year);
        if ($supplierId !== null && $firms === []) {
            $print("Firma {$supplierId} nemá dokončený převod účetnictví z POHODY" . ($year !== null ? " za rok {$year}" : '') . '.');
            return self::EXIT_INVALID;
        }
        if ($source === null) {
            $print('Firmy, kterým převod z POHODY vynechal mzdové závazky (čítač payroll_or_tax kroku other_items, horní odhad - zahrnuje i doplatky daně z příjmů a DPH):');
            foreach ($firms as $firm) {
                $print(sprintf('  firma %d  IČO %s  %s', $firm['id'], $firm['ico'], $firm['name']));
                foreach ($firm['agendas'] as $agendaYear => $agenda) {
                    $print(sprintf('    agenda %d: převod #%d z %s, vynecháno %d', $agendaYear, $agenda['run_id'], $agenda['finished_at'], $agenda['skipped']));
                }
            }
            $print($firms === [] ? '  žádná' : 'Doplnění potřebuje původní export POHODY použitý k převodu (--export=...). MyÚčto ho po převodu nedrží.');
            return self::EXIT_OK;
        }

        $tmp = null;
        try {
            if (is_file($source)) {
                $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_' . bin2hex(random_bytes(6));
                PohodaExport::extractArchive($source, $tmp);
                $root = $tmp;
            } elseif (is_dir($source)) {
                $root = rtrim($source, '/\\');
            } else {
                $print("Export {$source} neexistuje.");
                return self::EXIT_INVALID;
            }
            $exit = self::EXIT_OK;
            foreach ($firms as $firm) {
                $dirs = preg_match('/^(\d{6,8})_(\d{4})$/', basename($root), $m) === 1
                    ? ($m[1] === $firm['ico'] ? [(int) $m[2] => $root] : [])
                    : PohodaExport::agendaDirs($root, $firm['ico']);
                $dirs = array_intersect_key($dirs, $firm['agendas']);
                ksort($dirs);
                $print('');
                $print(($commit ? '' : '[NANEČISTO] ') . "Firma {$firm['id']} (IČO {$firm['ico']}) {$firm['name']}");
                if ($dirs === []) {
                    $print('  Export neobsahuje převedenou agendu firmy (složka IČO_rok), firma vynechána.');
                    continue;
                }
                if ($this->firm($firm, $dirs, $commit, $userId, $print, $today) !== self::EXIT_OK) {
                    $exit = self::EXIT_FAILED;
                }
            }
            if (!$commit) {
                $print('');
                $print('Nic nezměněno. Zápis provede spuštění s --commit.');
            }
            return $exit;
        } catch (PohodaException $e) {
            $print('Chyba: ' . $e->getMessage());
            return self::EXIT_FAILED;
        } finally {
            if ($tmp !== null) {
                self::removeDir($tmp);
            }
        }
    }

    /**
     * @param array{id:int,ico:string,name:string,agendas:array<int,array{run_id:int,finished_at:string,skipped:int,skipped_years:list<int>,own_journal_from:?string}>} $firm
     * @param array<int,string> $dirs
     * @param (callable(string):void) $print
     */
    private function firm(array $firm, array $dirs, bool $commit, ?int $userId, callable $print, string $today): int
    {
        if (!$this->runs->acquireLock($firm['id'])) {
            $print('  Převod této firmy právě běží, doplnění spusťte po jeho dokončení.');
            return self::EXIT_FAILED;
        }
        try {
            foreach ($dirs as $agendaYear => $dir) {
                $export = PohodaExport::open($dir);
                if ($export->ico !== $firm['ico']) {
                    $print("  Agenda {$agendaYear}: export patří IČO {$export->ico}, vynechána.");
                    continue;
                }
                if (PohodaImporter::isTaxEvidenceAgenda($export)) {
                    $print("  Agenda {$agendaYear}: daňová evidence, mzdové závazky převod nevynechával.");
                    continue;
                }
                $mode = $commit ? 'import' : 'dry_run';
                $runId = $this->runs->startRun($firm['id'], null, $mode, [
                    'ico' => $export->ico, 'year' => $export->year, 'program' => $export->info['program'],
                ], $userId);
                try {
                    $result = $this->agenda($firm['id'], $export, $firm['agendas'][$agendaYear], $commit, $runId, $userId, $today);
                } catch (\Throwable $e) {
                    $this->runs->finishRun($runId, $firm['id'], 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected',
                        'error' => $e->getMessage(), 'steps' => [], 'kind' => 'accounting', 'payroll_backfill' => true]);
                    $print("  Agenda {$agendaYear} (běh #{$runId}): selhalo, nic nezapsáno - " . $e->getMessage());
                    return self::EXIT_FAILED;
                }
                $protocol = $result['protocol'];
                $this->runs->finishRun($runId, $firm['id'], $protocol->status(), $protocol->toArray() + ['kind' => 'accounting', 'payroll_backfill' => true]);
                self::printAgenda($agendaYear, $runId, $result, $print);
            }
            return self::EXIT_OK;
        } finally {
            $this->runs->releaseLock($firm['id']);
        }
    }

    /**
     * Doplnění jedné agendy v transakci: nanečisto ji vrátí, ostře potvrdí. Uvnitř cizí
     * transakce (testy) jede přes savepoint.
     *
     * @param array{run_id:int,skipped_years:list<int>,own_journal_from:?string} $conversion
     * @return array{protocol:ImportProtocol,rows:list<array<string,mixed>>,reconciliation:list<array<string,mixed>>}
     */
    public function agenda(int $supplierId, PohodaExport $export, array $conversion, bool $commit, ?int $runId, ?int $userId, string $today): array
    {
        $agendaYear = PohodaAgendaYear::of($export, $this->periods->fiscalCalendar($supplierId));
        $export = $export->withPostingYears($agendaYear->calendar, $agendaYear->label, $conversion['skipped_years']);
        $protocol = new ImportProtocol($commit ? 'import' : 'dry_run');
        $protocol->set('agenda', [
            'ico' => $export->ico, 'year' => $export->year, 'program' => $export->info['program'],
            'exported_at' => $export->info['timestamp'], 'dir' => basename($export->dir),
            'skipped_years' => $conversion['skipped_years'], 'relink_only' => false, 'conversion_run' => $conversion['run_id'],
        ]);
        $ctx = new PohodaContext($supplierId, $userId ?? 0, $export, PohodaVat::fromExport($export), !$commit, $protocol);
        $ctx->useAgendaYear($agendaYear);
        $ctx->runId = $runId;
        $ctx->skippedYears = array_fill_keys($conversion['skipped_years'], true);
        $ctx->ownJournalFrom = $conversion['own_journal_from'];

        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec('SAVEPOINT pohoda_payroll_backfill') : $pdo->beginTransaction();
        try {
            $journal = $this->journalTotals($supplierId);
            $closed = $pdo->prepare("SELECT fiscal_year, status FROM accounting_periods
                WHERE supplier_id = ? AND status <> 'open' AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on");
            $closed->execute([$supplierId, $ctx->periodEnd(), $ctx->periodStart()]);
            foreach ($closed->fetchAll(PDO::FETCH_ASSOC) as $period) {
                // Doplnění zápisy nemění, jen je přiřadí položkám: uzavřené období ho nezastaví.
                $protocol->info(OtherItemImporter::STEP, 'period_not_open', "Účetní období {$period['fiscal_year']} je v MyÚčtu ve stavu {$period['status']}. "
                    . 'Položky se v něm založí bez nového účtování, převzaté zápisy se nemění.');
            }
            $this->cashBank->rememberConverted($ctx);
            $protocol->begin(OtherItemImporter::STEP);
            $rows = $this->otherItems->backfillPayroll($ctx);
            $protocol->finish(OtherItemImporter::STEP);
            $protocol->begin(DocumentLinker::STEP_PAYMENTS);
            $released = $this->releaseBookedPayments($ctx);
            $this->linker->matchOtherItemPayments($ctx);
            $this->restoreUnallocated($supplierId, $released, $protocol);
            $protocol->finish(DocumentLinker::STEP_PAYMENTS);
            if ($this->journalTotals($supplierId) !== $journal) {
                throw new PohodaException('payroll_backfill_journal_changed', 'Doplnění by změnilo účetní deník, agenda se nedoplnila.');
            }
            $rows = $this->settlements($supplierId, $rows);
            $reconciliation = [];
            foreach (array_unique([$ctx->periodEnd(), $today]) as $asOf) {
                $reconciliation[] = ['as_of' => $asOf, 'accounts' => $this->reconcile($supplierId, $asOf)];
            }
            $protocol->set('payroll_items', ['items' => $rows, 'reconciliation' => $reconciliation]);
            if ($commit) {
                $nested ? $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_backfill') : $pdo->commit();
            } else {
                self::rollback($pdo, $nested);
            }
        } catch (\Throwable $e) {
            TransactionFailure::rollbackIfAborted($pdo, $e);
            if ($pdo->inTransaction()) {
                self::rollback($pdo, $nested);
            }
            throw $e;
        }
        return ['protocol' => $protocol, 'rows' => $rows, 'reconciliation' => $reconciliation];
    }

    /**
     * Pohyby, kterými POHODA mzdový závazek uhradila, převod bez položky v agendě označil
     * jako vyřízené bez dokladu (`ignored` / `pohoda_booked`, {@see DocumentLinker::matchPayments()}):
     * k přiřazení úhrady se vrátí mezi nespárované, stejně jako je převod nechává u pohybu,
     * který úhradu ostatní položky nese. Pohyb spárovaný s fakturou nebo jinou položkou se
     * nemění. Vrací původní stav uvolněných pohybů pro {@see restoreUnallocated()}.
     *
     * @return array<int,array<string,mixed>>
     */
    private function releaseBookedPayments(PohodaContext $ctx): array
    {
        $ids = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['doc'] !== 'other_item' || $l['agenda'] !== 'bank') {
                continue;
            }
            $byId = $l['source_id'] !== '' ? ($ctx->bankByPohodaId[$l['source_id']] ?? null) : null;
            foreach ($byId !== null ? [$byId] : array_column($ctx->bankTransactions[$l['source']] ?? [], 'id') as $id) {
                $ids[(int) $id] = true;
            }
        }
        $released = [];
        $pdo = $this->db->pdo();
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT t.id, t.match_reason, t.matched_at, t.matched_by, t.ignore_note
                   FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.id IN ({$in}) AND t.match_status = 'ignored' AND t.match_reason = 'pohoda_booked'
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = t.id)
                    AND NOT EXISTS (SELECT 1 FROM other_item_allocations a WHERE a.bank_transaction_id = t.id)
                  FOR UPDATE"
            );
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $released[(int) $row['id']] = $row;
            }
        }
        foreach (array_chunk(array_keys($released), 500) as $chunk) {
            $pdo->prepare(
                "UPDATE bank_transactions SET match_status = 'unmatched', match_reason = NULL, matched_at = NULL, matched_by = NULL, ignore_note = NULL
                  WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            )->execute($chunk);
        }
        return $released;
    }

    /**
     * Uvolněný pohyb, ke kterému se úhrada nepřiřadila, dostane zpět původní stav. Pohyb, ze
     * kterého úhrady pokryly jen část (hromadná výplata mezd, jejíž ostatní závazky doplnění
     * nezaložilo), zůstává nespárovaný se zbytkem a protokol ho uvede k ručnímu dopárování.
     *
     * @param array<int,array<string,mixed>> $released
     */
    private function restoreUnallocated(int $supplierId, array $released, ImportProtocol $protocol): void
    {
        foreach (array_chunk(array_keys($released), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT t.id, ABS(t.amount) AS amount, SUM(a.amount + a.interest_amount + a.fee_amount) AS allocated
                   FROM bank_transactions t JOIN other_item_allocations a ON a.bank_transaction_id = t.id AND a.reversed_on IS NULL
                  WHERE t.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                  GROUP BY t.id, t.amount'
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rest = round((float) $row['amount'] - (float) $row['allocated'], 2);
                if ($rest >= 0.005) {
                    $protocol->count(DocumentLinker::STEP_PAYMENTS, 'bank_partially_allocated');
                    $protocol->warn(DocumentLinker::STEP_PAYMENTS, 'bank_partially_allocated', sprintf(
                        'Pohyb #%d (%s Kč) byl v převodu vyřízený bez dokladu, mzdové závazky z něj pokryly jen část. Zbytek %s Kč zůstává nespárovaný, dopárujte ho ručně.',
                        (int) $row['id'], number_format((float) $row['amount'], 2, ',', ' '), number_format($rest, 2, ',', ' ')), ['bank_transaction_id' => (int) $row['id']]);
                }
            }
        }
        $restore = $this->db->pdo()->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'ignored', t.match_reason = ?, t.matched_at = ?, t.matched_by = ?, t.ignore_note = ?
              WHERE s.supplier_id = ? AND t.id = ? AND t.match_status = 'unmatched'
                AND NOT EXISTS (SELECT 1 FROM other_item_allocations a WHERE a.bank_transaction_id = t.id)"
        );
        foreach ($released as $id => $row) {
            $restore->execute([$row['match_reason'], $row['matched_at'], $row['matched_by'], $row['ignore_note'], $supplierId, $id]);
        }
    }

    /**
     * Firmy s dokončeným převodem účetnictví z POHODY a agendy, jejichž poslední převod
     * (ne opravný převod vazeb ani doplnění) vynechal předpisy mezd nebo daní. Pro zadanou
     * firmu všechny její převedené agendy.
     *
     * @return list<array{id:int,ico:string,name:string,agendas:array<int,array{run_id:int,finished_at:string,skipped:int,skipped_years:list<int>,own_journal_from:?string}>}>
     */
    private function affected(?int $supplierId, ?int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.id, r.supplier_id, r.agenda_year, r.finished_at, s.ic, s.company_name,
                    JSON_EXTRACT(r.protocol, '$.steps') AS steps,
                    JSON_EXTRACT(r.protocol, '$.agenda.skipped_years') AS skipped_years
               FROM pohoda_imports r
               JOIN supplier s ON s.id = r.supplier_id
              WHERE r.mode = 'import' AND r.status IN ('completed', 'completed_with_warnings')
                AND (? IS NULL OR r.supplier_id = ?) AND (? IS NULL OR r.agenda_year = ?)
                AND r.agenda_year IS NOT NULL
                AND COALESCE(JSON_VALUE(r.protocol, '$.kind'), 'accounting') = 'accounting'
                AND COALESCE(JSON_EXTRACT(r.protocol, '$.agenda.relink_only'), 'false') <> 'true'
                AND JSON_EXTRACT(r.protocol, '$.payroll_backfill') IS NULL
              ORDER BY r.supplier_id, r.agenda_year, r.id"
        );
        $stmt->execute([$supplierId, $supplierId, $year, $year]);
        $firms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['supplier_id'];
            $skipped = 0;
            $ownFrom = null;
            foreach (json_decode((string) $row['steps'], true) ?: [] as $step) {
                if (($step['key'] ?? null) === OtherItemImporter::STEP) {
                    $skipped = (int) ($step['counts']['payroll_or_tax'] ?? 0);
                }
                foreach ($step['messages'] ?? [] as $message) {
                    if (($message['code'] ?? null) === 'own_journal_cutoff' && is_string($message['context']['from'] ?? null)) {
                        $ownFrom = $message['context']['from'];
                    }
                }
            }
            $firms[$id] ??= ['id' => $id, 'ico' => PartnerImporter::ico((string) $row['ic']), 'name' => (string) $row['company_name'], 'agendas' => []];
            // Pozdější běh téže agendy nahradí dřívější (opakovaný převod).
            $firms[$id]['agendas'][(int) $row['agenda_year']] = [
                'run_id' => (int) $row['id'], 'finished_at' => (string) $row['finished_at'], 'skipped' => $skipped,
                'skipped_years' => array_values(array_map('intval', json_decode((string) $row['skipped_years'], true) ?: [])),
                'own_journal_from' => $ownFrom,
            ];
        }
        $out = [];
        foreach ($firms as $firm) {
            if ($supplierId === null) {
                $firm['agendas'] = array_filter($firm['agendas'], static fn (array $a): bool => $a['skipped'] > 0);
            }
            if ($firm['agendas'] !== [] && $firm['ico'] !== '') {
                $out[] = $firm;
            }
        }
        return $out;
    }

    /**
     * Úhrady založených a už založených mzdových položek po párování.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function settlements(int $supplierId, array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, 'id')));
        $state = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT oi.id, oi.status, oi.amount, oi.settled_outside_amount, oi.variable_symbol, oi.partner_name,
                        oi.counterparty_account, oi.counterparty_bank_code, oi.accounting_on, oi.issued_on,
                        COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                                   WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id AND a.reversed_on IS NULL), 0) AS allocated
                   FROM other_items oi WHERE oi.supplier_id = ? AND oi.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $state[(int) $row['id']] = $row;
            }
        }
        $periods = $this->db->pdo()->prepare("SELECT fiscal_year, status, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND status <> 'open'");
        $periods->execute([$supplierId]);
        $closed = $periods->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $s = $row['id'] !== null ? ($state[$row['id']] ?? null) : null;
            if ($s === null) {
                continue;
            }
            $date = (string) ($s['accounting_on'] ?? $s['issued_on']);
            $row['closed_period'] = null;
            foreach ($closed as $period) {
                if ($date >= $period['starts_on'] && $date <= $period['ends_on']) {
                    $row['closed_period'] = $period['fiscal_year'] . ' (' . $period['status'] . ')';
                }
            }
            $row['allocated'] = round((float) $s['allocated'], 2);
            $row['settled_outside'] = round((float) $s['settled_outside_amount'], 2);
            $row['open'] = round(max(0.0, (float) $s['amount'] - (float) $s['allocated'] - (float) $s['settled_outside_amount']), 2);
            $row['variable_symbol'] = $s['variable_symbol'];
            $row['partner'] = $s['partner_name'];
            $row['counterparty'] = $s['counterparty_account'] !== null
                ? $s['counterparty_account'] . ($s['counterparty_bank_code'] !== null ? '/' . $s['counterparty_bank_code'] : '') : null;
        }
        unset($row);
        return $rows;
    }

    /**
     * Zůstatek mzdových účtů v deníku ({@see ClosingRepository::accountBalances()}) proti
     * otevřeným potvrzeným a zaúčtovaným ostatním položkám na nich k datu. Kladná částka
     * = závazek. Úhrada se počítá k datu platby, úhrada mimo banku a pokladnu
     * (`settled_outside_amount`) celá, stejně jako v měsíční kontrole
     * ({@see \MyInvoice\Service\Accounting\OtherItemMonthlyCheck::accountBalanceDifference()}),
     * jen včetně potvrzených položek bez zápisu: jejich předpis je v deníku jiným dokladem.
     *
     * @return list<array{account:string,ledger:float,open_items:float,difference:float}>
     */
    public function reconcile(int $supplierId, string $asOf): array
    {
        $balances = $this->closing->accountBalances($supplierId, self::ACCOUNTS, $asOf);
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.side, oi.account_code, oi.amount, oi.settled_outside_amount,
                    COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                               WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
                                 AND a.payment_on <= ? AND (a.reversed_on IS NULL OR a.reversed_on > ?)), 0) AS paid
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status IN ('confirmed', 'posted') AND oi.currency = 'CZK'
                AND (oi.account_code LIKE '331%' OR oi.account_code LIKE '336%' OR oi.account_code LIKE '342%')
                AND COALESCE(oi.accounting_on, oi.issued_on) <= ?"
        );
        $stmt->execute([$asOf, $asOf, $supplierId, $asOf]);
        $open = array_fill_keys(self::ACCOUNTS, 0.0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $remaining = max((float) $row['amount'] - (float) $row['settled_outside_amount'] - (float) $row['paid'], 0.0);
            $account = substr((string) preg_replace('/\D+/', '', (string) $row['account_code']), 0, 3);
            $open[$account] += $row['side'] === 'payable' ? $remaining : -$remaining;
        }
        $out = [];
        foreach (self::ACCOUNTS as $account) {
            $ledger = round(-(float) ($balances[$account] ?? 0.0), 2);
            $items = round($open[$account], 2);
            $out[] = ['account' => $account, 'ledger' => $ledger, 'open_items' => $items, 'difference' => round($ledger - $items, 2)];
        }
        return $out;
    }

    /** @return array{entries:int,last:int,posted:int,lines:int,debit:string,credit:string} */
    private function journalTotals(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $entries = $pdo->prepare('SELECT COUNT(*) AS entries, COALESCE(MAX(id), 0) AS last, COUNT(posted_at) AS posted FROM journal_entries WHERE supplier_id = ?');
        $entries->execute([$supplierId]);
        $e = $entries->fetch(PDO::FETCH_ASSOC) ?: [];
        $lines = $pdo->prepare(
            "SELECT COUNT(*) AS line_count, COALESCE(SUM(CASE WHEN side = 'debit' THEN signed_amount END), 0) AS debit,
                    COALESCE(SUM(CASE WHEN side = 'credit' THEN signed_amount END), 0) AS credit
               FROM journal_entry_lines WHERE supplier_id = ?"
        );
        $lines->execute([$supplierId]);
        $l = $lines->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'entries' => (int) ($e['entries'] ?? 0), 'last' => (int) ($e['last'] ?? 0), 'posted' => (int) ($e['posted'] ?? 0),
            'lines' => (int) ($l['line_count'] ?? 0), 'debit' => (string) ($l['debit'] ?? '0'), 'credit' => (string) ($l['credit'] ?? '0'),
        ];
    }

    /**
     * @param array{protocol:ImportProtocol,rows:list<array<string,mixed>>,reconciliation:list<array<string,mixed>>} $result
     * @param (callable(string):void) $print
     */
    private static function printAgenda(int $year, int $runId, array $result, callable $print): void
    {
        $rows = $result['rows'];
        $groups = [
            'created_posted' => 'Založeno s převzetím zápisu (bez nového účtování)',
            'created_unposted' => 'Založeno jako potvrzené bez zápisu',
            'existing' => 'Už v agendě (dřívější převod nebo doplnění)',
            'skipped' => 'Vynecháno',
        ];
        $print("  Agenda {$year} (běh #{$runId}): " . $result['protocol']->status());
        foreach ($groups as $status => $label) {
            $group = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $status));
            $print(sprintf('    %s: %d', $label, count($group)));
            foreach ($group as $r) {
                $parts = [sprintf('%-14s %-10s %-9s %12s', $r['number'], $r['issued'], (string) ($r['account'] ?? '-'), self::money($r['amount']))];
                if ($r['entry_id'] !== null && $status !== 'skipped') $parts[] = "zápis #{$r['entry_id']}";
                if (($r['source_document'] ?? null) !== null) $parts[] = "předpis v interním dokladu {$r['source_document']}";
                if (($r['variable_symbol'] ?? null) !== null) $parts[] = "VS {$r['variable_symbol']}";
                if (($r['counterparty'] ?? null) !== null) $parts[] = "účet {$r['counterparty']}";
                if (($r['closed_period'] ?? null) !== null) $parts[] = "UZAVŘENÉ OBDOBÍ {$r['closed_period']}";
                if (isset($r['open'])) {
                    $parts[] = sprintf('spárováno %s, uhrazeno mimo banku %s, zbývá %s', self::money($r['allocated']), self::money($r['settled_outside']), self::money($r['open']));
                }
                if ($status === 'skipped') $parts[] = self::REASONS[$r['reason']] ?? (string) $r['reason'];
                $print('      ' . implode(', ', $parts));
            }
        }
        $inClosed = array_values(array_filter($rows, static fn (array $r): bool => ($r['closed_period'] ?? null) !== null));
        if ($inClosed !== []) {
            $print('    Založeno v neotevřeném účetním období (bez nového účtování): ' . count($inClosed) . ' - '
                . implode(', ', array_map(static fn (array $r): string => $r['number'] . ' ' . $r['closed_period'], $inClosed)));
        }
        foreach ($result['protocol']->toArray()['steps'] as $step) {
            if ($step['key'] === DocumentLinker::STEP_PAYMENTS && $step['counts'] !== []) {
                $counts = [];
                foreach ($step['counts'] as $name => $value) {
                    $counts[] = "{$name}={$value}";
                }
                $print('    Úhrady z exportu: ' . implode(', ', $counts));
            }
            foreach ($step['messages'] as $m) {
                $print(sprintf('    [%s] %s', $m['level'], $m['text']));
            }
        }
        foreach ($result['reconciliation'] as $r) {
            $print("    Odsouhlasení mzdových účtů k {$r['as_of']} (závazek kladně):");
            foreach ($r['accounts'] as $a) {
                $print(sprintf('      %s  deník %12s  otevřené položky %12s  rozdíl %12s%s', $a['account'], self::money($a['ledger']),
                    self::money($a['open_items']), self::money($a['difference']), abs($a['difference']) >= 0.005 ? '  <- ROZDÍL' : ''));
            }
        }
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 2, ',', ' ');
    }

    private static function rollback(PDO $pdo, bool $nested): void
    {
        if ($nested) {
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_backfill');
            $pdo->exec('RELEASE SAVEPOINT pohoda_payroll_backfill');
        } else {
            $pdo->rollBack();
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
