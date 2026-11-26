<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Closing\OpeningTakeoverComparison;

/**
 * Uzávěrka historických let po převodu.
 *
 * Money roky uzavřelo, převod je naveze otevřené (deník se nepřepočítává). Tady je
 * MyÚčto uzavře vlastním průvodcem ({@see ClosingService}) od nejstaršího, a JEN když
 * konečné stavy roku N sedí účet po účtu na počáteční stavy roku N+1, které přišly
 * z Money, včetně výsledku hospodaření na 431 nebo 931. Při rozdílu se rok neuzavře a pozdější
 * roky také ne (stojí na něm). Poslední rok zůstává otevřený.
 *
 * U rámce 504 je XZ podkladem pro převzetí historické uzávěrky bez nového daňového
 * posouzení. U rámce 500 lze uzavřít i rok bez XZ s upozorněním
 * (`closed_without_money_closing`).
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
 *
 * S rokem po posledním převáděném jen s počátečními stavy ({@see ImportOptions::$openingOnlyNext})
 * se stejně ověří a uzavře i poslední převáděný rok. Rozdíly počátečních stavů Money proti
 * konečným stavům roku v MyÚčtu protokol vyjmenuje. Otevírací zápis dalšího roku, který převod
 * ponechal (převzatý dřív, nebo založený v MyÚčtu), se navíc porovná s počátečními stavy
 * z aktuální zálohy: při rozdílu zůstane rok otevřený a zápis se bez rozhodnutí uživatele nemění.
 * Nedovolí-li uzavření kurzové přecenění nebo zásoby, uzávěrka roku zůstane rozpracovaná
 * a protokol uvede skutečný stav jejích kroků (přeskočené převodem, hotové, čekající);
 * zbytek dokončí uživatel.
 *
 * Na rozpracovanou uzávěrku (i na uzavřené knihy s nedokončeným otevřením dalšího roku)
 * převod navazuje jen tehdy, když ji zahájil sám a od jeho posledního kroku se v ní
 * nepracovalo ({@see MARKER}). Uzávěrku rozpracovanou účetní nechá beze změny a ohlásí ji.
 */
final class HistoricalYearCloser
{
    public const STEP = 'closing';

    private const SKIP_NOTE = 'Převod z Money S3: krok proběhl v Money, zápisy jsou v převzatém deníku.';
    private const SKIPPED_STEPS = ['estimates', 'deferrals', 'provisions', 'income_tax'];

    /** Kroky uzávěrky, které převod nedělá ani nepřeskakuje (rok pak uzavírá uživatel). */
    private const MANUAL_STEPS = ['fx_required', 'stock_step_required'];

    /**
     * Příznak uzávěrky zahájené převodem v payloadu kroku Kontroly: verze období po posledním
     * kroku, který v uzávěrce udělal převod. Na uzávěrku navazuje převod jen s příznakem
     * a beze změny od té doby; uzávěrku zahájenou v MyÚčtu (nebo tu, ve které se od převodu
     * pracovalo, i znovu spuštěné kontroly příznak smažou) nechá beze změny.
     */
    private const MARKER = 'money_s3_conversion';

    /** Názvy kroků uzávěrky do protokolu (shodně s průvodcem). */
    private const STEP_LABELS = [
        'precheck' => 'Kontroly', 'depreciation' => 'Odpisy majetku', 'fx_revaluation' => 'Kurzové rozdíly',
        'estimates' => 'Dohadné položky', 'deferrals' => 'Časové rozlišení', 'provisions' => 'Opravné položky',
        'income_tax' => 'Daň z příjmů', 'deferred_tax' => 'Odložená daň', 'stock' => 'Zásoby',
        'close_books' => 'Uzavření knih', 'open_next' => 'Otevření dalšího roku',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ClosingService $closing,
        private readonly ClosingRepository $repo,
    ) {}

    public function run(ImportContext $ctx): void
    {
        $periods = self::periods($ctx);
        (new AccountingFrameworkImportGuard($this->db, $ctx->nonprofit(), 'Money S3'))->assertCz500Periods($ctx->supplierId, array_values($periods));
        $p = $ctx->protocol;
        $years = array_keys($periods);
        sort($years);
        $results = [];
        $blocked = false;
        $withoutMoneyClosing = [];
        $openingOnlyYear = $ctx->openingOnlyPeriod !== null ? (int) ($ctx->openingOnly['year'] ?? 0) : null;
        foreach ($years as $i => $year) {
            $next = $years[$i + 1] ?? null;
            $periodId = $periods[$year]['id'];
            if ($next === null) {
                $results[] = ['year' => $year, 'status' => $year === $openingOnlyYear ? 'opening_only' : 'open'];
                continue;
            }
            // Poslední převáděný rok před rokem jen s počátečními stavy.
            $last = $next === $openingOnlyYear;
            $state = $this->closing->state($ctx->supplierId, $periodId, $ctx->nonprofit());
            if (in_array((string) $state['period']['status'], ['closed', 'approved', 'reviewed'], true)) {
                if (empty($state['can_open_next'])) {
                    $results[] = ['year' => $year, 'status' => 'already_closed'];
                    continue;
                }
                if (!self::startedByConversion($state)) {
                    // Knihy uzavřela účetní v MyÚčtu: otevření dalšího roku je její krok.
                    $results[] = ['year' => $year, 'status' => 'open_next_pending'];
                    $p->warn(self::STEP, 'open_next_left_to_user', "Rok {$year} má v MyÚčtu uzavřené knihy, ale rok {$next} z něj není otevřený. "
                        . 'Uzávěrku nedokončoval převod (nebo se v ní od převodu pracovalo), převod ji proto nemění. '
                        . "Otevření roku {$next} dokončete v Uzávěrce roku {$year}.", ['year' => $year, 'steps' => self::stepStates($state)]);
                    $blocked = true;
                    continue;
                }
                $moneyDiffs = $last ? $this->moneyOpeningDiffs($ctx, $periods[$next]['id']) : [];
                if ($moneyDiffs !== []) {
                    $results[] = ['year' => $year, 'status' => 'opening_differs', 'diffs' => $moneyDiffs];
                    $p->warn(self::STEP, 'opening_differs_from_money', self::moneyDiffMessage($year, $next, $moneyDiffs),
                        ['year' => $year, 'next' => $next, 'diffs' => array_slice($moneyDiffs, 0, 50)]);
                    $blocked = true;
                    continue;
                }
                // Knihy se uzavřely, ale otevření dalšího roku selhalo (nebo neproběhlo) —
                // bez dotažení by se další rok nikdy neotevřel.
                $row = $this->finishOpenNext($ctx, $year, $periodId, $periods[$next]['id'], (int) $state['row_version']);
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
            // Otevírací zápis roku jen s počátečními stavy převod ponechal (převzatý dřív, nebo
            // založený v MyÚčtu): musí sedět na počáteční stavy z aktuální zálohy, jinak by se
            // rok uzavřel proti zastaralým stavům.
            $moneyDiffs = $last ? $this->moneyOpeningDiffs($ctx, $periods[$next]['id']) : [];
            if ($moneyDiffs !== []) {
                $p->warn(self::STEP, 'opening_differs_from_money', self::moneyDiffMessage($year, $next, $moneyDiffs),
                    ['year' => $year, 'next' => $next, 'diffs' => array_slice($moneyDiffs, 0, 50)]);
            }
            if ($status !== 'match') {
                $results[] = $row + ['status' => 'mismatch', 'message' => $takeover['message'] ?? null];
                $p->warn(self::STEP, 'closing_mismatch', "Konečné stavy roku {$year} nesedí na počáteční stavy roku {$next} z Money — rok zůstává otevřený."
                    . ($last ? self::diffText($row['diffs']) : ''), ['year' => $year] + ($last ? ['next' => $next, 'diffs' => array_slice($row['diffs'], 0, 50)] : []));
                $blocked = true;
                continue;
            }
            if ($moneyDiffs !== []) {
                $results[] = ['year' => $year, 'status' => 'opening_differs', 'diffs' => $moneyDiffs] + $row;
                $blocked = true;
                continue;
            }
            if ($ctx->nonprofit() && $ctx->options->closeHistory && !$blocked && ($ctx->yearEndClosingRows[$year] ?? 0) === 0) {
                $p->warn(self::STEP, 'nonprofit_no_money_closing', "Rok {$year} navazuje na rok {$next}, ale v Money S3 nemá uzávěrkové zápisy (XZ). Uzávěrku dokončete v MyÚčtu.", ['year' => $year]);
                $results[] = $row + ['status' => 'verified'];
                $blocked = true;
                continue;
            }
            if (!$ctx->options->closeHistory || $blocked) {
                $results[] = $row + ['status' => $blocked ? 'blocked' : 'verified'];
                continue;
            }
            if ((string) $state['period']['status'] === 'closing' && !self::startedByConversion($state)) {
                // Uzávěrku rozpracovala účetní v MyÚčtu: její posouzení (dohady, daň, zásoby)
                // převod nepřeskakuje ani neuzavírá.
                $steps = self::stepStates($state);
                $results[] = $row + ['status' => 'closing_in_progress', 'steps' => $steps];
                $p->warn(self::STEP, 'closing_in_progress', "Rok {$year} sedí na počáteční stavy roku {$next} z Money, uzávěrku roku ale v MyÚčtu rozpracovala účetní "
                    . '(nezahájil ji převod, nebo se v ní od převodu pracovalo). Převod ji nechává beze změny. ' . self::stepsText($steps)
                    . ' Dokončete ji v Uzávěrce.', ['year' => $year, 'steps' => $steps]);
                $blocked = true;
                continue;
            }

            $nextPeriodId = $periods[$next]['id'];
            $before = $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId);
            $savepoint = $this->db->pdo()->inTransaction();
            if ($savepoint) {
                // Uvnitř zkoušky nanečisto: selhaný krok průvodce nesmí nechat v transakci
                // rozdělanou uzávěrku, podle které by pak rekonciliace hlásila nesmysly.
                $this->db->pdo()->exec('SAVEPOINT money_s3_close_year');
            }
            try {
                $closed = $this->closeYear($ctx, $periodId);
                $this->markStartedByConversion($ctx, $periodId);
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
                if (isset($closed['nonprofit_tax_assessment_skipped'])) {
                    $p->info(self::STEP, 'nonprofit_historical_tax_assessment_skipped',
                        "Rok {$year}: uzávěrka navazuje na převzatý deník a ověřené počáteční stavy. Převod daňové posouzení činností k této historické uzávěrce v MyÚčtu neprovádí.", ['year' => $year]);
                }
                if (($ctx->yearEndClosingRows[$year] ?? null) === 0) {
                    $withoutMoneyClosing[] = $year;
                }
            } catch (\Throwable $e) {
                if (TransactionFailure::rollbackIfAborted($this->db->pdo(), $e)) {
                    throw $e;
                }
                if ($last && $e instanceof MoneyS3Exception && in_array($e->errorCode, self::MANUAL_STEPS, true)) {
                    // Poslední převáděný rok: uzávěrka zůstane rozpracovaná s přeskočenými kroky
                    // z Money, kurzové přecenění nebo zásoby dokončí uživatel v Uzávěrce.
                    if ($savepoint) {
                        $this->db->pdo()->exec('RELEASE SAVEPOINT money_s3_close_year');
                    }
                    $this->markStartedByConversion($ctx, $periodId);
                    $steps = self::stepStates($this->closing->state($ctx->supplierId, $periodId));
                    $results[] = $row + ['status' => 'closing_started', 'error' => $e->getMessage(), 'steps' => $steps];
                    $p->warn(self::STEP, 'closing_continues_manually', "Rok {$year} sedí na počáteční stavy roku {$next} z Money, uzavřít ho ale převod nemohl: " . $e->getMessage()
                        . ' Uzávěrka zůstává rozpracovaná. ' . self::stepsText($steps) . ' Dokončete ji v Uzávěrce.',
                        ['year' => $year, 'step' => $e->errorCode, 'steps' => $steps]);
                    $blocked = true;
                    continue;
                }
                if ($savepoint) {
                    $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT money_s3_close_year');
                } else {
                    $this->abortIfClosing($ctx, $periodId);
                    $this->markStartedByConversion($ctx, $periodId);
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
            $this->frameworkMutation($ctx, fn () => $this->closing->openNext($ctx->supplierId, $periodId, $rowVersion, $meta, moneyS3History: $ctx->nonprofit()));
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

    /**
     * Uzávěrka roku, kterou zahájil převod a od jeho posledního kroku se v ní nepracovalo
     * ({@see MARKER}).
     *
     * @param array<string,mixed> $state {@see ClosingService::state()}
     */
    private static function startedByConversion(array $state): bool
    {
        foreach ((array) ($state['steps'] ?? []) as $s) {
            if (($s['step_key'] ?? null) === 'precheck') {
                $marker = is_array($s['payload'] ?? null) ? ($s['payload'][self::MARKER] ?? null) : null;
                return is_array($marker) && (int) ($marker['row_version'] ?? -1) === (int) $state['row_version'];
            }
        }
        return false;
    }

    /**
     * Zapíše příznak uzávěrky zahájené převodem s verzí období po posledním kroku převodu.
     * Rozpracovaná ani uzavřená uzávěrka bez uložených kontrol příznak nedostane.
     */
    private function markStartedByConversion(ImportContext $ctx, int $periodId): void
    {
        $state = $this->closing->state($ctx->supplierId, $periodId);
        if ((string) $state['period']['status'] === 'open') {
            return;
        }
        foreach ((array) $state['steps'] as $s) {
            if (($s['step_key'] ?? null) === 'precheck' && is_array($s['payload'] ?? null)) {
                $payload = $s['payload'];
                $payload[self::MARKER] = ['run_id' => $ctx->runId, 'row_version' => (int) $state['row_version']];
                $this->repo->updateStepPayload($ctx->supplierId, $periodId, 'precheck', $payload);
            }
        }
    }

    /**
     * Skutečný stav kroků uzávěrky: přeskočené převodem (poznámka převodu), hotové a čekající
     * kroky před uzavřením knih (Zásoby a Odpisy jen tam, kde je rok vyžaduje).
     *
     * @param array<string,mixed> $state {@see ClosingService::state()}
     * @return array{skipped_by_conversion:list<string>,done:list<string>,pending:list<string>}
     */
    private static function stepStates(array $state): array
    {
        $out = ['skipped_by_conversion' => [], 'done' => [], 'pending' => []];
        foreach ((array) ($state['steps'] ?? []) as $s) {
            $key = (string) ($s['step_key'] ?? '');
            $status = (string) ($s['status'] ?? 'pending');
            if ($status === 'skipped' && ($s['note'] ?? null) === self::SKIP_NOTE) {
                $out['skipped_by_conversion'][] = $key;
            } elseif ($status === 'done' || $status === 'skipped') {
                $out['done'][] = $key;
            } elseif (!in_array($key, ['close_books', 'open_next', 'deferred_tax', 'stock', 'depreciation'], true)
                || ($key === 'stock' && !empty($state['stock_step_required']))
                || ($key === 'depreciation' && !empty($state['depreciation_step_required']))) {
                $out['pending'][] = $key;
            }
        }
        return $out;
    }

    /** @param array{skipped_by_conversion:list<string>,done:list<string>,pending:list<string>} $steps */
    private static function stepsText(array $steps): string
    {
        $names = static fn (array $keys): string => implode(', ', array_map(static fn (string $k): string => self::STEP_LABELS[$k] ?? $k, $keys));
        $parts = [];
        $parts[] = $steps['skipped_by_conversion'] !== []
            ? 'Převod přeskočil kroky, které proběhly v Money: ' . $names($steps['skipped_by_conversion']) . '.'
            : 'Převod v uzávěrce žádný krok nepřeskočil.';
        if ($steps['done'] !== []) {
            $parts[] = 'Hotové kroky: ' . $names($steps['done']) . '.';
        }
        if ($steps['pending'] !== []) {
            $parts[] = 'Čekají kroky: ' . $names($steps['pending']) . '.';
        }
        return implode(' ', $parts);
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
            $preview = $this->closing->fxPreview($supplierId, $periodId, []);
            $decision = array_intersect_key($preview, ['snapshot_hash' => true]);
            if (self::hasFxItems($preview) || ($decision !== [] && (!$preview['complete'] || $preview['positions'] !== []))) {
                throw new MoneyS3Exception('fx_required', 'rok má co přeceňovat kurzem, jinak by se deník rozešel s Money.');
            }
            $this->frameworkMutation($ctx, fn () => $this->closing->runFxRevaluation($supplierId, $periodId, [], $rv(), $meta, $decision));
        }

        $state = $this->closing->state($supplierId, $periodId);
        $assessmentOnly = $ctx->nonprofit() && ($state['close_blockers'] ?? []) === ['nonprofit_tax_assessment'];
        if (empty($state['can_close']) && !$assessmentOnly) {
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
        $close = fn (): array => $this->closing->closeBooks($supplierId, $periodId, $rv(), $meta, $reason !== null, $reason, moneyS3History: $ctx->nonprofit());
        $open = fn (): array => $this->closing->openNext($supplierId, $periodId, $rv(), $meta, moneyS3History: $ctx->nonprofit());
        if ($ctx->nonprofit()) {
            return $this->frameworkMutation($ctx, static function () use ($close, $open): array {
                $result = $close();
                $open();
                return $result;
            });
        }
        $result = $this->frameworkMutation($ctx, $close);
        $this->frameworkMutation($ctx, $open);
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
        return (new AccountingFrameworkImportGuard($this->db, $ctx->nonprofit(), 'Money S3'))->writeCz500Periods($ctx->supplierId, array_values(self::periods($ctx)), $write);
    }

    /**
     * Převáděná období a rok jen s počátečními stavy ({@see ImportContext::$openingOnlyPeriod}):
     * k němu se ověřuje a uzavírá poslední převáděný rok.
     *
     * @return array<int,array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}>
     */
    private static function periods(ImportContext $ctx): array
    {
        $periods = $ctx->periods;
        if ($ctx->openingOnlyPeriod !== null && $ctx->openingOnly !== null) {
            $periods[(int) $ctx->openingOnly['year']] = $ctx->openingOnlyPeriod;
        }
        return $periods;
    }

    /**
     * Počáteční stavy z aktuální zálohy ({@see ImportContext::$openingOnlyBalances}) proti
     * otevíracímu zápisu dalšího roku v MyÚčtu po účtech, bez účtů třídy 7.
     *
     * @return list<array{account_code:string,existing:float,money:float,difference:float}>
     */
    private function moneyOpeningDiffs(ImportContext $ctx, int $nextPeriodId): array
    {
        if ($ctx->openingOnlyBalances === null) {
            return [];
        }
        if ($ctx->nonprofit()) {
            $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
            $moneyLines = [];
            foreach ($ctx->openingOnlyBalances as $code => $balance) {
                $moneyLines[] = ['account_code' => (string) $code, 'side' => $balance < 0 ? 'credit' : 'debit', 'amount' => abs($balance)];
            }
            $comparison = OpeningTakeoverComparison::compare(
                $moneyLines, $this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId), $profile, moneyS3: true,
            );
            return array_map(static fn (array $diff): array => [
                'account_code' => $diff['account_code'], 'existing' => $diff['existing'],
                'money' => $diff['expected'], 'difference' => $diff['difference'],
            ], $comparison['diff']);
        }
        $existing = [];
        foreach ($this->repo->openingBalancesInPeriod($ctx->supplierId, $nextPeriodId) as $code => $balance) {
            if (!str_starts_with((string) $code, '7')) {
                $existing[(string) $code] = $balance;
            }
        }
        $money = [];
        foreach ($ctx->openingOnlyBalances as $code => $balance) {
            $money[(string) $code] = $balance;
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($existing), array_keys($money))) as $code) {
            $code = (string) $code;
            $diff = round(($money[$code] ?? 0.0) - ($existing[$code] ?? 0.0), 2);
            if ((int) round($diff * 100) !== 0) {
                $out[] = ['account_code' => $code, 'existing' => round($existing[$code] ?? 0.0, 2), 'money' => round($money[$code] ?? 0.0, 2), 'difference' => $diff];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['account_code'] <=> $b['account_code']);
        return $out;
    }

    /** @param list<array{account_code:string,existing:float,money:float,difference:float}> $diffs */
    private static function moneyDiffMessage(int $year, int $next, array $diffs): string
    {
        $fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
        $rows = array_map(static fn (array $d): string => sprintf('%s: otevírací zápis v MyÚčtu %s, počáteční stav z Money %s, rozdíl %s',
            $d['account_code'], $fmt($d['existing']), $fmt($d['money']), $fmt($d['difference'])), array_slice($diffs, 0, 10));
        return "Otevírací zápis roku {$next} v MyÚčtu nesedí na počáteční stavy z aktuální zálohy Money. Převod ho bez vašeho rozhodnutí nemění, "
            . "rok {$year} proto zůstává otevřený. Rozdíly (" . count($diffs) . ' účtů): ' . implode('; ', $rows) . (count($diffs) > 10 ? '; …' : '')
            . ". Otevírací zápis opravte podle Money (nebo zálohu Money podle MyÚčta) a převod spusťte znovu.";
    }

    /**
     * Rozdíly počátečních stavů z Money proti konečným stavům roku v MyÚčtu do textu protokolu.
     *
     * @param list<array<string,mixed>> $diffs
     */
    private static function diffText(array $diffs): string
    {
        if ($diffs === []) {
            return '';
        }
        $fmt = static fn (mixed $v): string => number_format((float) $v, 2, ',', ' ');
        $rows = array_map(static fn (array $d): string => sprintf('%s: konečný stav v MyÚčtu %s, počáteční stav z Money %s, rozdíl %s',
            (string) $d['account_code'], $fmt($d['expected'] ?? 0), $fmt($d['existing'] ?? 0), $fmt($d['difference'] ?? 0)), array_slice($diffs, 0, 10));
        return ' Rozdíly (' . count($diffs) . ' účtů): ' . implode('; ', $rows) . (count($diffs) > 10 ? '; …' : '') . '.';
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
