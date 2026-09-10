<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;

/**
 * Uzávěrka historických let po převodu.
 *
 * Money roky uzavřelo, převod je naveze otevřené (deník se nepřepočítává). Tady je
 * MyÚčto uzavře vlastním průvodcem ({@see ClosingService}) od nejstaršího, a JEN když
 * konečné stavy roku N sedí účet po účtu na počáteční stavy roku N+1, které přišly
 * z Money — včetně výsledku hospodaření na 431. Při rozdílu se rok neuzavře a pozdější
 * roky také ne (stojí na něm). Poslední rok zůstává otevřený.
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
        $p = $ctx->protocol;
        $years = array_keys($ctx->periods);
        sort($years);
        $results = [];
        $blocked = false;
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
                $p->error(self::STEP, 'closing_mismatch', "Konečné stavy roku {$year} nesedí na počáteční stavy roku {$next} z Money — rok zůstává otevřený.", ['year' => $year]);
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
            } catch (\Throwable $e) {
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
            $this->closing->openNext($ctx->supplierId, $periodId, $rowVersion, $meta);
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
            $this->closing->start($supplierId, $periodId, $rv(), $meta);
        }
        $this->closing->runPrecheck($supplierId, $periodId, $rv(), $meta);

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
                $this->closing->confirmStep($supplierId, $periodId, $step, 'skipped', self::SKIP_NOTE, $rv(), $meta);
            }
        }
        if (!$isDone('fx_revaluation')) {
            if (self::hasFxItems($this->closing->fxPreview($supplierId, $periodId, []))) {
                throw new MoneyS3Exception('fx_required', 'rok má co přeceňovat kurzem, jinak by se deník rozešel s Money.');
            }
            $this->closing->runFxRevaluation($supplierId, $periodId, [], $rv(), $meta);
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

        $result = $this->closing->closeBooks($supplierId, $periodId, $rv(), $meta);
        $this->closing->openNext($supplierId, $periodId, $rv(), $meta);
        return $result;
    }

    private function abortIfClosing(ImportContext $ctx, int $periodId): void
    {
        try {
            $state = $this->closing->state($ctx->supplierId, $periodId);
            if (($state['period']['status'] ?? null) === 'closing' && !empty($state['can_abort'])) {
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
