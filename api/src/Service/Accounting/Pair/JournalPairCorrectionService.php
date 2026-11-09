<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TaxNeutralReclassification;
use MyInvoice\Service\ActivityLogger;

/**
 * Storno a přeúčtování vybraných dvojic jednoduchého zápisu (kritérium f).
 *
 *  - {@see reversePairs()} založí opravný zápis se zrcadlem vybraných dvojic
 *    (JournalLineAmount::mirrorLines(), tentýž kód jako storno celého zápisu; dimenze,
 *    rozpady a zakázka se přenášejí), dvojice opravného zápisu číslované 1..k, vazby
 *    do `journal_pair_reversals`. Originál zůstává jako celek nestornovaný
 *    (`reversed_by` NULL). Datum a zámky jako storno celého zápisu. Čisté storno dvojice
 *    jen u ručních a interních zápisů (`manual` bez zdrojového dokladu); u dokladových
 *    zápisů jen přeúčtování, jinak by zápis přestal sedět na doklad
 *    (409 `pair_reversal_document_forbidden`).
 *  - {@see repostPair()} přeúčtuje jednu dvojici na jiné účty (částka zůstává):
 *    v otevřeném nezamčeném období výměnou dvojice na místě (stejné `pair_no`, původní
 *    obsah drží systémové verzování řádků a audit), při zámku k datu s daňově neutrální
 *    změnou ({@see TaxNeutralReclassification}) také na místě, jinak opravným zápisem
 *    = storno dvojice + nová dvojice (`replacement_pair_no`).
 *  - Mzdové zápisy: 409 `payroll_reversal_forbidden` jako u storna celého zápisu.
 *
 * Chyby hlásí přes {@see JournalPairException} (kód + HTTP status), Action je jen
 * překládá na odpověď.
 */
final class JournalPairCorrectionService
{
    private const LINE_COLUMNS = 'id, line_no, account_id, side, amount, is_red_storno, currency_code, fx_rate,
        amount_foreign, cost_center, project_id, pair_no, line_text';

    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly PostingService $posting,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Storno vybraných dvojic opravným zápisem.
     *
     * @param list<int> $pairNos čísla dvojic originálu
     * @param array{entry_date?:?string, description?:?string, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return int id opravného zápisu
     * @throws JournalPairException
     */
    public function reversePairs(int $supplierId, int $entryId, array $pairNos, array $meta): int
    {
        return TransactionFailure::retrying(
            $this->db->pdo(),
            fn (): int => $this->inTransaction(fn (): int => $this->reversePairsLocked($supplierId, $entryId, $pairNos, $meta)),
        );
    }

    /**
     * Přeúčtování jedné dvojice na nové účty (částka zůstává).
     *
     * @param array{debit_account_code:string, credit_account_code:string, line_text?:?string} $newPair
     * @param array{entry_date?:?string, description?:?string, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, mode:'in_place'|'tax_neutral'|'correction_entry', pair_no:int}
     *         zápis, ve kterém je nová dvojice, a způsob přeúčtování
     * @throws JournalPairException
     */
    public function repostPair(int $supplierId, int $entryId, int $pairNo, array $newPair, array $meta): array
    {
        return TransactionFailure::retrying(
            $this->db->pdo(),
            fn (): array => $this->inTransaction(fn (): array => $this->repostPairLocked($supplierId, $entryId, $pairNo, $newPair, $meta)),
        );
    }

    /**
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    private function inTransaction(\Closure $work): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work();
            if ($ownTx) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<int> $pairNos @param array<string,mixed> $meta */
    private function reversePairsLocked(int $supplierId, int $entryId, array $pairNos, array $meta): int
    {
        [$entry, $lines] = $this->lockEntry($supplierId, $entryId);
        if ((string) $entry['source_type'] !== 'manual' || $entry['source_id'] !== null) {
            throw new JournalPairException(
                'pair_reversal_document_forbidden',
                'Dvojici zápisu z dokladu nelze jen stornovat, zápis by přestal odpovídat dokladu. Dvojici přeúčtujte.',
                409,
            );
        }
        $pairNos = array_values(array_unique(array_map('intval', $pairNos)));
        sort($pairNos);
        if ($pairNos === []) {
            throw new JournalPairException('validation_failed', 'Vyberte dvojice ke stornu.', 422);
        }
        $pairs = self::pairsOf($lines);
        $reversed = $this->reversedPairs($supplierId, $entryId);
        $selected = [];
        foreach ($pairNos as $pairNo) {
            if (!isset($pairs[$pairNo])) {
                throw new JournalPairException('pair_not_found', 'Zápis #' . $entryId . ' nemá dvojici ' . $pairNo . '.', 404, ['pair_no' => $pairNo]);
            }
            if (isset($reversed[$pairNo])) {
                throw new JournalPairException('pair_already_reversed', 'Dvojice ' . $pairNo . ' už je stornovaná.', 409, ['pair_no' => $pairNo]);
            }
            array_push($selected, ...$pairs[$pairNo]);
        }
        if (count($pairNos) === count($pairs) - count($reversed)) {
            throw new JournalPairException(
                'pair_reversal_whole_entry',
                'Vybrané jsou všechny zbývající dvojice zápisu, stornujte zápis jako celek.',
                409,
            );
        }

        [$date, $period] = $this->correctionDate($supplierId, $entry, $meta);
        $mirror = $this->mirror($supplierId, $selected);
        $renumber = array_flip($pairNos);
        foreach ($mirror as $i => $line) {
            $mirror[$i]['pair_no'] = $renumber[(int) $line['pair_no']] + 1;
        }
        $reversalId = $this->journal->insert(
            $this->correctionHeader($supplierId, $entry, $period, $date, $meta['description'] ?? null, 'Storno dvojic ' . implode(', ', $pairNos) . ' zápisu #' . $entryId, $meta),
            $mirror,
        );
        foreach ($pairNos as $k => $pairNo) {
            $this->recordReversal($supplierId, $entryId, $pairNo, $reversalId, $k + 1, null, $meta);
        }
        $this->activity->log(
            'accounting.pairs_reversed',
            $meta['user_id'] ?? null,
            'journal_entry',
            $reversalId,
            ['original_entry_id' => $entryId, 'reversal_entry_id' => $reversalId, 'pair_nos' => $pairNos, 'lines' => $mirror],
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
            $supplierId,
        );
        return $reversalId;
    }

    /**
     * @param array<string,mixed> $newPair
     * @param array<string,mixed> $meta
     * @return array{entry_id:int, mode:'in_place'|'tax_neutral'|'correction_entry', pair_no:int}
     */
    private function repostPairLocked(int $supplierId, int $entryId, int $pairNo, array $newPair, array $meta): array
    {
        [$entry, $lines] = $this->lockEntry($supplierId, $entryId);
        $pairs = self::pairsOf($lines);
        if (!isset($pairs[$pairNo])) {
            throw new JournalPairException('pair_not_found', 'Zápis #' . $entryId . ' nemá dvojici ' . $pairNo . '.', 404, ['pair_no' => $pairNo]);
        }
        if (isset($this->reversedPairs($supplierId, $entryId)[$pairNo])) {
            throw new JournalPairException('pair_already_reversed', 'Dvojice ' . $pairNo . ' už je stornovaná.', 409, ['pair_no' => $pairNo]);
        }
        $old = $pairs[$pairNo];
        $entryDate = (string) $entry['entry_date'];
        $replacement = $this->replacementPair($supplierId, $old, $newPair, $entryDate);

        $periodStatus = $this->periodStatus($supplierId, (int) $entry['period_id']);
        $lockedUntil = $this->lockedUntilForUpdate($supplierId);
        $dateLocked = $lockedUntil !== null && $entryDate <= $lockedUntil;
        $mode = null;
        if ($periodStatus === 'open' && !$dateLocked) {
            $mode = 'in_place';
        } elseif ($periodStatus === 'open' && $this->taxNeutral($supplierId, $old, $replacement, $entryDate)) {
            $mode = 'tax_neutral';
        }

        if ($mode !== null) {
            $this->replaceInPlace($supplierId, $entry, $lines, $pairNo, $replacement);
            $this->activity->log(
                'accounting.pair_reposted',
                $meta['user_id'] ?? null,
                'journal_entry',
                $entryId,
                ['entry_id' => $entryId, 'pair_no' => $pairNo, 'mode' => $mode, 'before' => $old, 'after' => $replacement],
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );
            return ['entry_id' => $entryId, 'mode' => $mode, 'pair_no' => $pairNo];
        }

        if ((string) $entry['source_type'] === 'payroll') {
            throw self::payrollForbidden();
        }
        [$date, $period] = $this->correctionDate($supplierId, $entry, $meta);
        $correctionLines = [];
        foreach ($this->mirror($supplierId, $old) as $line) {
            $correctionLines[] = ['pair_no' => 1] + $line;
        }
        foreach ($replacement as $line) {
            unset($line['id'], $line['line_no']);
            $line['pair_no'] = 2;
            $correctionLines[] = $line;
        }
        $correctionId = $this->journal->insert(
            $this->correctionHeader($supplierId, $entry, $period, $date, $meta['description'] ?? null, 'Přeúčtování dvojice ' . $pairNo . ' zápisu #' . $entryId, $meta),
            $correctionLines,
        );
        $this->recordReversal($supplierId, $entryId, $pairNo, $correctionId, 1, 2, $meta);
        $this->activity->log(
            'accounting.pair_reposted',
            $meta['user_id'] ?? null,
            'journal_entry',
            $correctionId,
            ['entry_id' => $entryId, 'pair_no' => $pairNo, 'mode' => 'correction_entry', 'correction_entry_id' => $correctionId, 'lines' => $correctionLines],
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
            $supplierId,
        );
        return ['entry_id' => $correctionId, 'mode' => 'correction_entry', 'pair_no' => 2];
    }

    /**
     * Zámky ve stejném pořadí jako zaúčtování (období, systémové dimenze, zápis) a kontrola,
     * že zápis je živý, zaúčtovaný, nemzdový a rozdělený na dvojice.
     *
     * @return array{0:array<string,mixed>, 1:list<array<string,mixed>>}
     */
    private function lockEntry(int $supplierId, int $entryId): array
    {
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $entry = $this->journal->findForUpdate($entryId, $supplierId);
        if ($entry === null) {
            throw new JournalPairException('entry_not_found', 'Účetní zápis #' . $entryId . ' neexistuje.', 404);
        }
        if ((string) $entry['source_type'] === 'payroll') {
            throw self::payrollForbidden();
        }
        if ($entry['reversed_by'] !== null) {
            throw new JournalPairException('already_reversed', 'Zápis #' . $entryId . ' už byl stornován.', 409);
        }
        if (($entry['posted_at'] ?? null) === null) {
            throw new JournalPairException('entry_not_posted', 'Zápis #' . $entryId . ' je koncept, dvojice se opravují jen u zaúčtovaného zápisu.', 409);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::LINE_COLUMNS . ' FROM journal_entry_lines
              WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id FOR UPDATE'
        );
        $stmt->execute([$supplierId, $entryId]);
        $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($lines === [] || array_filter($lines, static fn (array $l): bool => $l['pair_no'] === null) !== []) {
            throw new JournalPairException(
                'entry_not_paired',
                'Zápis #' . $entryId . ' není rozdělený na dvojice, storno a přeúčtování dvojic u něj nejde.',
                409,
            );
        }
        return [$entry, $lines];
    }

    private static function payrollForbidden(): JournalPairException
    {
        return new JournalPairException(
            'payroll_reversal_forbidden',
            'Mzdový předpis se opravuje výhradně novou mzdovou revizí a rozdílovým zápisem.',
            409,
        );
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<int, list<array<string,mixed>>> pair_no => [řádek MD, řádek Dal] (účinná strana)
     */
    private static function pairsOf(array $lines): array
    {
        $pairs = [];
        foreach ($lines as $line) {
            $pairs[(int) $line['pair_no']][] = $line;
        }
        foreach ($pairs as $pairNo => $pair) {
            if (count($pair) !== 2) {
                throw new JournalPairException(JournalPairInvariant::INCOMPLETE, JournalPairInvariant::message(JournalPairInvariant::INCOMPLETE), 409, ['pair_no' => $pairNo]);
            }
            if (JournalPairInvariant::effectiveSide($pair[0]) !== 'debit') {
                $pairs[$pairNo] = [$pair[1], $pair[0]];
            }
        }
        ksort($pairs);
        return $pairs;
    }

    /** @return array<int,true> */
    private function reversedPairs(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pair_no FROM journal_pair_reversals WHERE supplier_id = ? AND entry_id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $entryId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /**
     * Nová dvojice: tytéž řádky (částka, strana, červené storno, měna, středisko,
     * zakázka, dimenze) jen s jinými účty; MD účet patří řádku s účinnou stranou MD.
     *
     * @param list<array<string,mixed>> $old [MD, Dal]
     * @param array<string,mixed> $newPair
     * @return list<array<string,mixed>>
     */
    private function replacementPair(int $supplierId, array $old, array $newPair, string $entryDate): array
    {
        $codeMap = $this->accounts->codeToIdMap($supplierId, $entryDate, true);
        $resolve = static function (string $code) use ($codeMap): int {
            $account = $codeMap[$code] ?? null;
            if ($account === null) {
                throw new JournalPairException('unknown_account', 'Účet ' . $code . ' není v účtové osnově.', 422);
            }
            if (!$account['is_active']) {
                throw new JournalPairException('account_inactive', 'Účet ' . $code . ' je v osnově deaktivovaný.', 422);
            }
            if (in_array($account['account_type'] ?? null, ['closing', 'offbalance'], true)) {
                throw new JournalPairException('account_not_allowed', 'Na účet ' . $code . ' nelze dvojici přeúčtovat.', 422);
            }
            return (int) $account['id'];
        };
        $debitId = $resolve(trim((string) ($newPair['debit_account_code'] ?? '')));
        $creditId = $resolve(trim((string) ($newPair['credit_account_code'] ?? '')));
        if ($debitId === $creditId) {
            throw new JournalPairException(JournalPairInvariant::SAME_ACCOUNT, JournalPairInvariant::message(JournalPairInvariant::SAME_ACCOUNT), 422);
        }
        if ($debitId === (int) $old[0]['account_id'] && $creditId === (int) $old[1]['account_id']) {
            throw new JournalPairException('pair_unchanged', 'Dvojice už je zaúčtovaná na zadaných účtech.', 422);
        }
        $text = isset($newPair['line_text']) && trim((string) $newPair['line_text']) !== ''
            ? trim((string) $newPair['line_text'])
            : null;
        $dims = $this->dimensionMaps($supplierId, $old);
        $out = [];
        foreach ([[$old[0], $debitId], [$old[1], $creditId]] as [$line, $accountId]) {
            $id = (int) $line['id'];
            $copy = [
                'id'             => $id,
                'line_no'        => (int) $line['line_no'],
                'account_id'     => $accountId,
                'side'           => (string) $line['side'],
                'amount'         => (string) $line['amount'],
                'is_red_storno'  => (bool) $line['is_red_storno'],
                'currency_code'  => $line['currency_code'],
                'fx_rate'        => $line['fx_rate'],
                'amount_foreign' => $line['amount_foreign'],
                'cost_center'    => $line['cost_center'],
                'project_id'     => $line['project_id'] !== null ? (int) $line['project_id'] : null,
                'dimensions'     => $dims[0][$id] ?? [],
                'dimension_splits' => $dims[1][$id] ?? [],
                'dimension_origins' => $dims[2][$id] ?? [],
                'dimension_split_origins' => $dims[3][$id] ?? [],
                'pair_no'        => (int) $line['pair_no'],
            ];
            $lineText = $text ?? ($line['line_text'] !== null && $line['line_text'] !== '' ? (string) $line['line_text'] : null);
            if ($lineText !== null) {
                $copy['line_text'] = $lineText;
            }
            $out[] = $copy;
        }
        return $out;
    }

    /**
     * Výměna dvojice na místě. Řádky dvojice a všech dvojic za ní se odeberou a vloží
     * znovu ve stejném pořadí (nové řádky zapisovač řadí za poslední ponechaný), takže
     * čísla dvojic dál jdou v pořadí řádků (invariant I5). Dimenze, rozpady, zakázka
     * i texty ostatních dvojic se přenesou beze změny.
     *
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $lines
     * @param list<array<string,mixed>> $replacement
     */
    private function replaceInPlace(int $supplierId, array $entry, array $lines, int $pairNo, array $replacement): void
    {
        $moved = array_values(array_filter($lines, static fn (array $l): bool => (int) $l['pair_no'] >= $pairNo));
        $dims = $this->dimensionMaps($supplierId, $moved);
        $byPair = [];
        foreach (self::pairsOf($moved) as $no => $pair) {
            if ($no === $pairNo) {
                $byPair[$no] = $replacement;
                continue;
            }
            foreach ($pair as $line) {
                $id = (int) $line['id'];
                $byPair[$no][] = [
                    'account_id'     => (int) $line['account_id'],
                    'side'           => (string) $line['side'],
                    'amount'         => (string) $line['amount'],
                    'is_red_storno'  => (bool) $line['is_red_storno'],
                    'currency_code'  => $line['currency_code'],
                    'fx_rate'        => $line['fx_rate'],
                    'amount_foreign' => $line['amount_foreign'],
                    'cost_center'    => $line['cost_center'],
                    'project_id'     => $line['project_id'] !== null ? (int) $line['project_id'] : null,
                    'dimensions'     => $dims[0][$id] ?? [],
                    'dimension_splits' => $dims[1][$id] ?? [],
                    'dimension_origins' => $dims[2][$id] ?? [],
                    'dimension_split_origins' => $dims[3][$id] ?? [],
                    'pair_no'        => $no,
                ] + ($line['line_text'] !== null && $line['line_text'] !== '' ? ['line_text' => (string) $line['line_text']] : []);
            }
        }
        $add = [];
        foreach ($byPair as $pair) {
            foreach ($pair as $line) {
                unset($line['id'], $line['line_no']);
                $add[] = $line;
            }
        }
        $this->journal->replaceSomeLines(
            (int) $entry['id'],
            $supplierId,
            (int) $entry['row_version'],
            array_map(static fn (array $l): int => (int) $l['id'], $moved),
            $add,
        );
    }

    /**
     * Zrcadlo řádků (storno) se stejnými čísly dvojic, dimenzemi a zakázkou.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function mirror(int $supplierId, array $lines): array
    {
        [$dims, $splits, $origins, $splitOrigins] = $this->dimensionMaps($supplierId, $lines);
        $normalized = array_map(static fn (array $l): array => ['is_red_storno' => (bool) $l['is_red_storno']] + $l, $lines);
        $mirror = JournalLineAmount::mirrorLines($normalized, $dims, $splits, $origins, $splitOrigins);
        // Zrcadlo obrací strany: v každé dvojici zase nejdřív účinná strana MD.
        for ($i = 0; $i + 1 < count($mirror); $i += 2) {
            if (JournalPairInvariant::effectiveSide($mirror[$i]) !== 'debit') {
                [$mirror[$i], $mirror[$i + 1]] = [$mirror[$i + 1], $mirror[$i]];
            }
        }
        return $mirror;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array{0:array<int,array<mixed>>,1:array<int,array<mixed>>,2:array<int,array<mixed>>,3:array<int,array<mixed>>}
     */
    private function dimensionMaps(int $supplierId, array $lines): array
    {
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $lines);
        $assignments = new DimensionAssignmentRepository($this->db);
        return [
            $assignments->lineDimensions($supplierId, $ids, true),
            $assignments->lineSplits($supplierId, $ids, true),
            $assignments->lineOrigins($supplierId, $ids, true),
            $assignments->lineSplitOrigins($supplierId, $ids, true),
        ];
    }

    /**
     * Datum a období opravného zápisu, pravidla jako storno celého zápisu: zadané datum,
     * jinak datum originálu; zamčené nebo neotevřené datum originálu se bez zadaného
     * data posune na dnešek. Zadané zamčené nebo neotevřené datum se odmítne.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $meta
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function correctionDate(int $supplierId, array $entry, array $meta): array
    {
        $explicit = isset($meta['entry_date']) && (string) $meta['entry_date'] !== '' ? (string) $meta['entry_date'] : null;
        $lockedUntil = $this->lockedUntilForUpdate($supplierId);
        $usable = function (string $date) use ($supplierId, $lockedUntil): array {
            $period = $this->periods->findForDateForUpdate($supplierId, $date);
            if ($period === null) {
                return [null, 'no_accounting_period'];
            }
            if ((string) $period['status'] !== 'open') {
                return [$period, 'period_not_open'];
            }
            if ($lockedUntil !== null && $date <= $lockedUntil) {
                return [$period, 'date_locked'];
            }
            return [$period, null];
        };

        $date = $explicit ?? (string) $entry['entry_date'];
        [$period, $problem] = $usable($date);
        if ($problem !== null && $explicit === null) {
            $date = date('Y-m-d');
            [$period, $problem] = $usable($date);
        }
        if ($problem !== null || $period === null) {
            $messages = [
                'no_accounting_period' => 'Pro datum ' . $date . ' neexistuje účetní období.',
                'period_not_open'      => 'Účetní období pro datum ' . $date . ' není otevřené (§35 ZoÚ).',
                'date_locked'          => 'Datum ' . $date . ' je uzamčené k ' . $lockedUntil . ', opravný zápis musí mít otevřené datum.',
            ];
            $code = $problem ?? 'no_accounting_period';
            throw new JournalPairException($code, $messages[$code], 422, ['entry_date' => $date]);
        }
        return [$date, $period];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $period
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function correctionHeader(int $supplierId, array $entry, array $period, string $date, ?string $description, string $fallback, array $meta): array
    {
        return [
            'supplier_id'   => $supplierId,
            'period_id'     => (int) $period['id'],
            'entry_date'    => $date,
            'document_date' => $entry['document_date'] ?? null,
            'document_no'   => $entry['document_no'] !== null ? mb_substr('OPRAVA ' . $entry['document_no'], 0, 50) : null,
            'description'   => $description !== null && trim($description) !== '' ? $description : $fallback,
            'source_type'   => (string) $entry['source_type'],
            // Jako storno celého zápisu: opravný zápis není zdrojový doklad, jinak by ho
            // idempotence dokladu našla místo originálu.
            'source_id'     => null,
            'posted_at'     => date('Y-m-d H:i:s'),
            'posted_by'     => $meta['user_id'] ?? null,
            // Opravný zápis nese dvojice originálu i do období se složenou formou (I6).
            'pairs_inherited' => true,
        ];
    }

    /** @param array<string,mixed> $meta */
    private function recordReversal(int $supplierId, int $entryId, int $pairNo, int $reversalId, int $reversalPairNo, ?int $replacementPairNo, array $meta): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_pair_reversals
                (supplier_id, entry_id, pair_no, reversal_entry_id, reversal_pair_no, replacement_pair_no, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $entryId, $pairNo, $reversalId, $reversalPairNo, $replacementPairNo, $meta['user_id'] ?? null]);
    }

    private function periodStatus(int $supplierId, int $periodId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $periodId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    private function lockedUntilForUpdate(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Smí se dvojice v zamčeném datu přeúčtovat na místě? Stejné pravidlo jako
     * přeúčtování dokladu v zamčeném datu ({@see TaxNeutralReclassification}).
     *
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     */
    private function taxNeutral(int $supplierId, array $before, array $after, string $entryDate): bool
    {
        $ids = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['account_id'], [...$before, ...$after])));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code AS code, account_type, tax_deductibility
               FROM chart_of_accounts WHERE supplier_id = ? AND id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $accounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $accounts[(int) $row['id']] = $row;
        }
        $normalize = static fn (array $l): array => [
            'account_id' => (int) $l['account_id'],
            'side' => (string) $l['side'],
            'amount' => $l['amount'],
            'is_red_storno' => (bool) $l['is_red_storno'],
        ];
        return TaxNeutralReclassification::violation(
            array_map($normalize, $before),
            array_map($normalize, $after),
            $accounts,
            $this->posting->incomeTaxFiledForYear($supplierId, (int) substr($entryDate, 0, 4)),
        ) === null;
    }
}
