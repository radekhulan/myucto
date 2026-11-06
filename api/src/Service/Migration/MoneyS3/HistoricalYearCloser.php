<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;

/**
 * Uzávěrka historických let po převodu.
 *
 * Money roky uzavřelo, převod je naveze otevřené (deník se nepřepočítává). Tady je
 * MyÚčto uzavře vlastním průvodcem ({@see ClosingService}) od nejstaršího, a JEN když
 * konečné stavy roku N sedí účet po účtu na počáteční stavy roku N+1, které přišly
 * z Money — včetně výsledku hospodaření na 431. Při rozdílu se rok neuzavře a pozdější
 * roky také ne (stojí na něm). Poslední rok zůstává otevřený.
 *
 * Uzávěrkové zápisy Money (`XZ`) podmínkou NEJSOU: Money otevře další rok s počátečními
 * stavy i bez uzávěrky a na reálných agendách chybí XZ u většiny let, která byla podaná.
 * Rok uzavřený bez nich převod jen ohlásí (`closed_without_money_closing`).
 *
 * Porovnání NEPOČÍTÁ samo: bere ho z průvodce uzávěrkou (`opening_takeover` ve
 * {@see ClosingService::state()}), tedy z téhož výpočtu, podle kterého otevření roku
 * převzaté počáteční stavy přijme nebo odmítne. Vlastní kopie by se s průvodcem
 * dřív nebo později rozešla — převod by rok uzavřel a otevření by ho pak odmítlo.
 *
 * Kroky, které proběhly v Money (odpisy, dohadné položky, časové rozlišení, rezervy,
 * daň z příjmů), se přeskočí s poznámkou — jejich zápisy jsou v převedeném deníku.
 * Kurzové přecenění a zásoby se nepřeskakují: je-li co přeceňovat, rok se uzavírá ručně.
 * Otevření dalšího roku jde standardním {@see ClosingService::openNext()}, které
 * převzatý otevírací zápis ponechá a nic neúčtuje.
 */
final class HistoricalYearCloser
{
    public const STEP = 'closing';

    private const SKIP_NOTE = 'Převod z Money S3: krok proběhl v Money, zápisy jsou v převzatém deníku.';
    private const SKIPPED_STEPS = ['estimates', 'deferrals', 'provisions', 'income_tax'];

    public function __construct(
        private readonly Connection $db,
        private readonly ClosingService $closing,
        private readonly ClosingRepository $repo,
    ) {}

    public function run(ImportContext $ctx): void
    {
        (new AccountingFrameworkImportGuard($this->db))->assertCz500Periods($ctx->supplierId, array_values($ctx->periods));
        $p = $ctx->protocol;
        $years = array_keys($ctx->periods);
        sort($years);
        $results = [];
        $blocked = false;
        $withoutMoneyClosing = [];
        foreach ($years as $i => $year) {
            $next = $years[$i + 1] ?? null;
            $periodId = $ctx->periods[$year]['id'];
            if ($next === null) {
                $results[] = ['year' => $year, 'status' => 'open'];
                continue;
            }
            $state = $this->closing->state($ctx->supplierId, $periodId);
            if (in_array((string) $state['period']['status'], ['closed', 'approved', 'reviewed'], true)) {
                if (empty($state['can_open_next'])) {
                    $results[] = ['year' => $year, 'status' => 'already_closed'];
                    continue;
                }
                // Knihy se uzavřely, ale otevření dalšího roku selhalo (nebo neproběhlo) —
                // bez dotažení by se další rok nikdy neotevřel.
                $row = $this->finishOpenNext($ctx, $year, $periodId, $ctx->periods[$next]['id'], (int) $state['row_version']);
                $results[] = $row;
                $blocked = $blocked || $row['status'] !== 'next_opened';
                continue;
            }
            $takeover = (array) ($state['opening_takeover'] ?? []);
            $row = ['year' => $year, 'accounts' => (int) ($takeover['accounts'] ?? 0), 'diffs' => (array) ($takeover['diff'] ?? [])];
            $status = (string) ($takeover['status'] ?? 'unavailable');
            if ($status === 'to_create') {
                $results[] = $row + ['status' => 'no_opening'];
                $p->warn(self::STEP, 'no_opening', "Rok {$next} nemá v Money počáteční stavy, rok {$year} proto nelze ověřit ani uzavřít.", ['year' => $year]);
                $blocked = true;
                continue;
            }
            if ($status !== 'match') {
                $results[] = $row + ['status' => 'mismatch', 'message' => $takeover['message'] ?? null];
                $p->warn(self::STEP, 'closing_mismatch', "Konečné stavy roku {$year} nesedí na počáteční stavy roku {$next} z Money — rok zůstává otevřený.", ['year' => $year]);
                $blocked = true;
                continue;
            }
            if (!$ctx->options->closeHistory || $blocked) {
                $results[] = $row + ['status' => $blocked ? 'blocked' : 'verified'];
                continue;
            }

            $nextPeriodId = $ctx->periods[$next]['id'];
            $before = $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId);
            $savepoint = $this->db->pdo()->inTransaction();
            if ($savepoint) {
                // Uvnitř zkoušky nanečisto: selhaný krok průvodce nesmí nechat v transakci
                // rozdělanou uzávěrku, podle které by pak rekonciliace hlásila nesmysly.
                $this->db->pdo()->exec('SAVEPOINT money_s3_close_year');
            }
            try {
                $closed = $this->closeYear($ctx, $periodId);
                $after = $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId);
                $entries = count($this->repo->openingEntriesInPeriod($ctx->supplierId, $nextPeriodId));
                if ($entries !== 1 || self::differs($before, $after)) {
                    throw new MoneyS3Exception('opening_changed', "po otevření roku {$next} se počáteční stavy liší od převzatých z Money.");
                }
                if ($savepoint) {
                    $this->db->pdo()->exec('RELEASE SAVEPOINT money_s3_close_year');
                }
                $results[] = $row + ['status' => 'closed', 'profit' => $closed['profit'] ?? null];
                $p->count(self::STEP, 'closed');
                if (($ctx->yearEndClosingRows[$year] ?? null) === 0) {
                    $withoutMoneyClosing[] = $year;
                }
            } catch (\Throwable $e) {
                if (TransactionFailure::rollbackIfAborted($this->db->pdo(), $e)) {
                    throw $e;
                }
                if ($savepoint) {
                    $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT money_s3_close_year');
                } else {
                    $this->abortIfClosing($ctx, $periodId);
                }
                $results[] = $row + ['status' => 'failed', 'error' => $e->getMessage()];
                $p->warn(self::STEP, 'closing_failed', "Rok {$year} se nepodařilo uzavřít: " . $e->getMessage() . ' Uzavřete ho v Uzávěrce ručně.', ['year' => $year]);
                $blocked = true;
            }
        }
        if ($withoutMoneyClosing !== []) {
            $p->warn(self::STEP, 'closed_without_money_closing', sprintf(
                'Roky %s se uzavřely podle navazujících počátečních stavů, deník Money v nich ale nemá uzávěrkové zápisy (XZ). '
                . 'Money dovolí otevřít další rok i bez uzávěrky; ověřte, že jsou roky opravdu uzavřené (podané přiznání), jinak kroky Otevření dalšího roku a Uzavření knih v Uzávěrce vraťte.',
                implode(', ', $withoutMoneyClosing)
            ), ['years' => $withoutMoneyClosing]);
        }
        $p->set('closing', $results);
        $p->finish(self::STEP);
    }

    /**
     * Dotažení otevření dalšího roku u roku, jehož knihy už jsou uzavřené. Stejná
     * kontrola jako po uzávěrce: převzatý otevírací zápis zůstane jediný a počáteční
     * stavy se nezmění.
     *
     * @return array<string,mixed>
     */
    private function finishOpenNext(ImportContext $ctx, int $year, int $periodId, int $nextPeriodId, int $rowVersion): array
    {
        $pdo = $this->db->pdo();
        $before = $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId);
        $savepoint = $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT money_s3_open_next');
        }
        try {
            $meta = ['user_id' => $ctx->userId > 0 ? $ctx->userId : null, 'posted_by' => $ctx->userId > 0 ? $ctx->userId : null];
            $this->frameworkMutation($ctx, fn () => $this->closing->openNext($ctx->supplierId, $periodId, $rowVersion, $meta));
            $after = $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId);
            if (count($this->repo->openingEntriesInPeriod($ctx->supplierId, $nextPeriodId)) !== 1 || self::differs($before, $after)) {
                throw new MoneyS3Exception('opening_changed', 'po otevření dalšího roku se počáteční stavy liší od převzatých z Money.');
            }
            if ($savepoint) {
                $pdo->exec('RELEASE SAVEPOINT money_s3_open_next');
            }
            $ctx->protocol->count(self::STEP, 'closed');
            return ['year' => $year, 'status' => 'next_opened'];
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_open_next');
            }
            $ctx->protocol->warn(self::STEP, 'open_next_failed', "Rok {$year} je uzavřený, ale další rok se nepodařilo otevřít: " . $e->getMessage() . ' Otevřete ho v Uzávěrce ručně.', ['year' => $year]);
            return ['year' => $year, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> výsledek closeBooks */
    private function closeYear(ImportContext $ctx, int $periodId): array
    {
        $supplierId = $ctx->supplierId;
        $meta = ['user_id' => $ctx->userId > 0 ? $ctx->userId : null, 'posted_by' => $ctx->userId > 0 ? $ctx->userId : null];
        $rv = fn (): int => (int) $this->closing->state($supplierId, $periodId)['row_version'];
        $isDone = function (string $key) use ($supplierId, $periodId): bool {
            foreach ($this->closing->state($supplierId, $periodId)['steps'] as $s) {
                if (($s['step_key'] ?? null) === $key) {
                    return in_array((string) $s['status'], ['done', 'skipped'], true);
                }
            }
            return false;
        };

        if ($this->closing->state($supplierId, $periodId)['period']['status'] === 'open') {
            $this->frameworkMutation($ctx, fn () => $this->closing->start($supplierId, $periodId, $rv(), $meta));
        }
        $this->frameworkMutation($ctx, fn () => $this->closing->runPrecheck($supplierId, $periodId, $rv(), $meta));

        $state = $this->closing->state($supplierId, $periodId);
        if (!empty($state['stock_step_required'])) {
            throw new MoneyS3Exception('stock_step_required', 'rok vyžaduje krok Zásoby.');
        }
        $skip = self::SKIPPED_STEPS;
        if (!empty($state['depreciation_step_required'])) {
            array_unshift($skip, 'depreciation');
        }
        foreach ($skip as $step) {
            if (!$isDone($step)) {
                $this->frameworkMutation($ctx, fn () => $this->closing->confirmStep($supplierId, $periodId, $step, 'skipped', self::SKIP_NOTE, $rv(), $meta));
            }
        }
        if (!$isDone('fx_revaluation')) {
            if (self::hasFxItems($this->closing->fxPreview($supplierId, $periodId, []))) {
                throw new MoneyS3Exception('fx_required', 'rok má co přeceňovat kurzem, jinak by se deník rozešel s Money.');
            }
            $this->frameworkMutation($ctx, fn () => $this->closing->runFxRevaluation($supplierId, $periodId, [], $rv(), $meta));
        }

        $state = $this->closing->state($supplierId, $periodId);
        if (empty($state['can_close'])) {
            $pending = [];
            foreach ($state['steps'] as $s) {
                if (!in_array($s['status'], ['done', 'skipped'], true)
                    && !in_array($s['step_key'], ['close_books', 'open_next', 'deferred_tax', 'stock'], true)) {
                    $pending[] = $s['step_key'];
                }
            }
            throw new MoneyS3Exception('closing_steps_incomplete', 'průvodce uzávěrkou nedovolí uzavřít' . ($pending !== [] ? ' (nehotové kroky: ' . implode(', ', $pending) . ')' : '') . '.');
        }

        $reason = $this->unpostedFromMoney($ctx, $periodId);
        $result = $this->frameworkMutation($ctx, fn () => $this->closing->closeBooks($supplierId, $periodId, $rv(), $meta, $reason !== null, $reason));
        $this->frameworkMutation($ctx, fn () => $this->closing->openNext($supplierId, $periodId, $rv(), $meta));
        return $result;
    }

    /**
     * Důvod pro uzavření knih s nezaúčtovanými doklady, nebo null. Money rok uzavřelo i
     * s doklady, které v deníku nemají zápis (převod je hlásí jako doklady bez zápisu);
     * MyÚčto by kvůli nim historický rok neuzavřelo nikdy. Výjimka platí JEN tehdy, když
     * jsou všechny nezaúčtované doklady převzaté z Money — doklad založený v MyÚčtu dál
     * blokuje. Průvodce ji zaznamená do auditu i do závěrkového balíčku.
     */
    private function unpostedFromMoney(ImportContext $ctx, int $periodId): ?string
    {
        $period = null;
        foreach ($ctx->periods as $p) {
            if ((int) $p['id'] === $periodId) {
                $period = $p;
            }
        }
        if ($period === null) {
            return null;
        }
        $ids = [
            MoneyS3ImportRepository::KIND_INVOICE => array_column($this->repo->unpostedInvoices($ctx->supplierId, $period['starts_on'], $period['ends_on']), 'id'),
            MoneyS3ImportRepository::KIND_PURCHASE_INVOICE => array_column($this->repo->unpostedPurchases($ctx->supplierId, $period['starts_on'], $period['ends_on']), 'id'),
        ];
        $total = count($ids[MoneyS3ImportRepository::KIND_INVOICE]) + count($ids[MoneyS3ImportRepository::KIND_PURCHASE_INVOICE]);
        if ($total === 0) {
            return null;
        }
        $mapped = 0;
        foreach ($ids as $kind => $list) {
            if ($list === []) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(DISTINCT target_id) FROM money_s3_import_map
                  WHERE supplier_id = ? AND kind = ? AND target_id IN (' . implode(',', array_fill(0, count($list), '?')) . ')'
            );
            $stmt->execute(array_merge([$ctx->supplierId, $kind], array_map('intval', $list)));
            $mapped += (int) $stmt->fetchColumn();
        }
        if ($mapped !== $total) {
            return null;
        }
        return "Převod z Money S3: {$total} dokladů převzatých z Money nemá v deníku Money zápis, Money rok uzavřelo bez nich.";
    }

    private function frameworkMutation(ImportContext $ctx, callable $write): mixed
    {
        return (new AccountingFrameworkImportGuard($this->db))->writeCz500Periods($ctx->supplierId, array_values($ctx->periods), $write);
    }

    private function abortIfClosing(ImportContext $ctx, int $periodId): void
    {
        try {
            $state = $this->closing->state($ctx->supplierId, $periodId);
            if (($state['period']['status'] ?? null) === 'closing' && !empty($state['can_abort'])) {
                // Abort only restores closing status and refuses posted closing entries; cleanup must preserve the original import error.
                $this->closing->abort($ctx->supplierId, $periodId, (int) $state['row_version'], ['user_id' => $ctx->userId > 0 ? $ctx->userId : null]);
            }
        } catch (\Throwable) {
            // Rok zůstane ve stavu, ve kterém ho průvodce nechal; protokol hlásí selhání.
        }
    }

    /** Náhled přecenění má co zaúčtovat: řádky saldokonta nebo bank/pokladen v cizí měně. */
    private static function hasFxItems(array $preview): bool
    {
        foreach (['saldo', 'bank'] as $slot) {
            $lines = $preview[$slot]['lines'] ?? [];
            if (is_array($lines) && $lines !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,float> $a
     * @param array<string,float> $b
     */
    private static function differs(array $a, array $b): bool
    {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $code) {
            if ((int) round(($a[$code] ?? 0.0) * 100) !== (int) round(($b[$code] ?? 0.0) * 100)) {
                return true;
            }
        }
        return false;
    }
}
