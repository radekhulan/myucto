<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\BalanceInventoryService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Accounting\Reports\SmallAssetReportService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * ClosingService — orchestrace uzávěrkového průvodce per období (Epic F4, §3.4).
 *
 * Kroky (pořadí závazné, `done`/`skipped` = splněno):
 *   1 precheck        (open|closing)  — kontroly, payload s výsledky
 *   2 depreciation    (open|closing)  — checklist (vazba na F3 book), confirm/skip
 *   3 fx_revaluation  (closing)       — zápisy slotů 1+2 (R10), re-run = rewrite
 *   4 estimates       (closing)       — asistent dohadných položek 388/389 (R22)
 *   5 deferrals       (closing)       — asistent časového rozlišení 381–385 (R22)
 *   6 stock           (closing)       — uzávěrka zásob způsobem B (SKLAD §3.4); povinný
 *                                       jen pro stock_enabled + double_entry, jinak skipped
 *   7 close_books     (closing, 1–6)  — closing zápis (R8) + status closed
 *   8 open_next       (closed|approved, 7) — opening zápis + FX storno (R11) + počáteční
 *                                       stav zásob do spotřeby (SKLAD §3.4 krok 5)
 *
 * Konkurence (R4): každá mutace běží v transakci, drží SELECT ... FOR UPDATE na
 * řádku období (findForUpdate) a provádí compare-and-swap na row_version — i kroky,
 * které stav nemění, verzi bumpují jako zámek. Nesoulad verze → 409 version_conflict.
 *
 * Idempotence (R6): jeden zápis per source klíč; re-run kroku = in-place rewrite
 * přes PostingService (flag allow_closing_period, R7 — nastavuje VÝHRADNĚ tato třída).
 * Revert kroků (R12) = HARD DELETE závěrkových zápisů (closing/opening/fx_revaluation)
 * s dumpem pro audit; dohady/čas. rozlišení (source manual) se REVERZUJÍ stornem.
 *
 * Audit: PostingService loguje accounting.posted/reversed sám; workflow eventy
 * (closing_started, books_closed, closing_step_reverted s dumpem…) loguje volající
 * akce (A4) z návratových hodnot metod — proto metody vracejí dumps/payloady.
 */
final class ClosingService
{
    // `deferred_tax` následuje AŽ po `income_tax`: odložená daň stojí na rozdílech, které
    // jsou známé teprve po zaúčtování odpisů a po vyčíslení splatné daně.
    public const STEP_KEYS = ['precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'deferred_tax', 'stock', 'close_books', 'open_next'];

    /**
     * Povolené kontace asistenta (R22) per krok.
     *
     * Krok `provisions` nabízí REZERVY. Opravné položky k pohledávkám (§ 8a/§ 8c ZoR)
     * mají v témž kroku vlastní návrhovou cestu ({@see suggestLegalProvision}), protože
     * je systém umí spočítat z dat; rezervy spočítat neumí — jejich výši určuje plán
     * oprav, který v systému není — a proto se zadávají asistovaným zápisem.
     *
     * Rozlišení zákonné × ostatní rezervy je věcné, ne kosmetické:
     *   `reserve.repairs.*` = 552/451, ZoR § 7, daňově UZNATELNÝ náklad
     *   `reserve.other.*`   = 554/459, účetní rezerva, daňově NEUZNATELNÁ (§ 25 ZDP)
     * Do fáze doplnění § 7 byla dostupná jen ta druhá, takže daňovou rezervu nešlo
     * uplatnit vůbec — kontace 552/451 v číselníku ani neexistovala (migrace 1147).
     */
    private const ASSIST_RULES = [
        'estimates'  => ['estimate.asset', 'estimate.liability'],
        'deferrals'  => ['accrual.prepaid.expense', 'accrual.accrued.expense', 'accrual.deferred.revenue', 'accrual.accrued.revenue', 'accrual.small_asset.defer',
            'accrual.complex.defer', 'accrual.complex.release'],
        'provisions' => ['reserve.repairs.create', 'reserve.repairs.release', 'reserve.other.create', 'reserve.other.release',
            'reserve.income_tax.create', 'reserve.income_tax.release'],
    ];

    /**
     * Prahy návrhu zákonné opravné položky k pohledávkám (D9, §8a/§8c ZoR) — v měsících
     * po splatnosti. Zjednodušený návrh (auditor § plánu): §8a 18 měsíců → 50 %, 30 měsíců
     * → 100 %; §8c pohledávky do 30 000 Kč nad 12 měsíců → 100 %. Systém návrh jen NABÍZÍ —
     * účetní ho per pohledávka potvrdí/upraví/zamítne (nikdy automatické zaúčtování).
     */
    /**
     * Fallback defaulty, když by daný rok neměl v TaxConstants klíč (nemělo by nastat —
     * primární zdroj je vždy {@see TaxConstantsRepository::forYear()} pro fiskální rok
     * období). Hodnoty se v čase neměnily, viz `TaxConstants::TABLE`.
     */
    private const PROVISION_8A_50_MONTHS = 18;
    private const PROVISION_8A_100_MONTHS = 30;
    private const PROVISION_8C_MONTHS = 12;
    private const PROVISION_8C_LIMIT = 30000.0;
    private const PROVISION_LIMITATION_MONTHS = 36;

    /** Srážková daň z podílu na zisku (§36 ZDP) — fallback default 15 % (D10);
     *  primárně se čte z dobových konstant {@see TaxConstantsRepository::forYear}. */
    private const WITHHOLDING_RATE = 0.15;

    /** Sazba DPPO (§21 ZDP) — fallback default 21 %; primárně z dobových konstant. */
    private const CORPORATE_TAX_RATE = 0.21;

    /**
     * Heuristika návrhu dohadných položek pasivních (389) — krok estimates. Min. počet
     * měsíců roku s fakturou od dodavatele, aby se náklad považoval za „opakující se"
     * (a chybějící poslední měsíc dával smysl navrhnout), a velikost vzorku posledních
     * faktur pro průměr částky. Konzervativně vyšší práh = méně falešných návrhů (šumu).
     */
    private const ESTIMATE_MIN_RECURRING_MONTHS = 3;
    private const ESTIMATE_SAMPLE_SIZE = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly ClosingRepository $closing,
        private readonly ClosingEntryBuilder $builder,
        private readonly FxRevaluationService $fx,
        private readonly DocumentSeriesService $series,
        private readonly PostingService $posting,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly LoggerInterface $logger,
        private readonly PostingRuleRepository $rules,
        private readonly JournalEntryRepository $journal,
        private readonly StockClosingValuation $stockValuation,
        private readonly DepreciationPostingService $depreciation,
        private readonly EntityCategoryService $categories,
        private readonly AssetRepository $assets,
        private readonly VatCrossCheckService $vatCrossCheck,
        private readonly SaldoService $saldo,
        private readonly TaxReturnRepository $taxReturns,
        private readonly SmallAssetRepository $smallAssets,
        private readonly SmallAssetReportService $smallAssetReport,
        private readonly CnbRateDeviationChecker $cnbRateChecker,
        private readonly PaymentMatchAuditChecker $paymentMatchAudit,
        // Dopočet ř.340 DPPO z aktuálního účetnictví pro incomeTaxPreview(), když ještě
        // neexistuje finalizované přiznání (běžný stav během uzávěrky). DppoReturnDataProvider
        // se NEinjektuje přímo (jeho produkční binding v Bootstrapu sám závisí na ClosingService
        // — cyklus), proto jen jeho čisté stavební kameny; instance se skládá lokálně bez
        // ClosingService (closing_projection Feature 1 se přeskočí — tady chceme jen posted stav).
        private readonly TaxConstantsRepository $taxConstants,
        // § 99a — posouzení nároku na čtvrtletní zdaňovací období z obratu.
        private readonly \MyInvoice\Service\Report\VatPeriodEntitlementService $vatPeriodEntitlement,
        // § 6 ZDPH — vznik plátcovství z obratu (datum, ne jen číslo vedle limitu).
        private readonly \MyInvoice\Service\Report\VatRegistrationService $vatRegistration,
        // § 18 odst. 2 ZoÚ — povinné části závěrky u velké a střední ÚJ.
        private readonly \MyInvoice\Service\Accounting\Reports\CashFlowStatementService $cashFlowStatement,
        private readonly \MyInvoice\Service\Accounting\Reports\EquityChangesStatementService $equityStatement,
        // § 18/1/c — příloha k závěrce. Automaticky předvyplněné sekce se při uzavření
        // knih ZMRAZÍ, jinak by se příloha schváleného roku měnila spolu s firmou.
        private readonly \MyInvoice\Service\Accounting\Reports\StatementNotesService $statementNotes,
        private readonly NonDeductibleCostsService $nonDeductibleCosts,
        private readonly DppoReturnCalculator $dppoCalc,
        // ČÚS 003: výpočet přechodných rozdílů je read-only a stojí mimo ClosingService,
        // aby šel testovat bez celého uzávěrkového aparátu.
        private readonly DeferredTaxService $deferredTax,
        // EP-4: auditní workflow události (closing_started, books_closed…) se zapisují
        // ve STEJNÉ transakci jako účetní mutace. ActivityLogger sdílí tutéž Connection/PDO
        // (PHP-DI singleton), takže INSERT do activity_log je součástí otevřené tx — selhání
        // auditu → rollback mutace; atomicita zároveň dává idempotenci (retry po rollbacku
        // spustí celou tx znovu, bez duplicitní události).
        private readonly ActivityLogger $activity,
        // EP-6: perzistence inventarizace rozvahových účtů (skutečný stav, rozdíly,
        // odpovědná osoba, protokol) + gating uzavření knih na vyřešené rozdíly.
        private readonly BalanceInventoryService $balanceInventory,
        // § 36a ZDPH / § 23/7 ZDP — transakce se spojenými osobami a měřitelné odchylky
        // od cen účtovaných nespojeným. Read-only, nic neúčtuje.
        private readonly \MyInvoice\Service\Tax\RelatedPartyService $relatedParties,
        // Migrace 1332 — aktuálnost interního dokladu zúčtování DPH. Read-only: kontrola
        // nikdy sama nepřeúčtovává (do zavřeného ani zamčeného období se nesahá), jen hlásí.
        private readonly \MyInvoice\Service\Accounting\Vat\VatClearingService $vatClearing,
    ) {}

    // ── stav pro FE ───────────────────────────────────────────────────────────

    /**
     * Období + kroky + odvozené flagy pro wizard (bez zámků, jen čtení).
     *
     * @return array<string,mixed>
     */
    public function state(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $steps = $this->stepsMap($supplierId, $periodId);
        $prev = $this->previousPeriod($supplierId, (string) $period['starts_on']);
        $next = $this->periods->nextPeriod($supplierId, (string) $period['ends_on']);

        $prevOk = $prev === null || in_array($prev['status'], ['closed', 'approved'], true);
        $requiredPreClose = $this->preCloseStepKeys($supplierId, $period);
        $preCloseDone = $this->stepsComplete($steps, $requiredPreClose);
        $hasClosingEntries = $this->closing->hasClosingEntries($supplierId, $periodId);
        $nextNotApproved = $next === null || $next['status'] !== 'approved';

        $stepList = [];
        foreach (self::STEP_KEYS as $key) {
            $stepList[] = $steps[$key];
        }

        return [
            'period' => $period,
            'row_version' => (int) $period['row_version'],
            'steps' => $stepList,
            'previous_period' => $prev,
            'next_period' => $next,
            'can_start' => $period['status'] === 'open' && $prevOk,
            'precheck_stale' => $this->precheckStale($supplierId, $period, $steps),
            'can_abort' => $period['status'] === 'closing' && !$hasClosingEntries,
            'can_close' => $period['status'] === 'closing' && $preCloseDone,
            'stock_step_required' => in_array('stock', $requiredPreClose, true),
            'depreciation_step_required' => in_array('depreciation', $requiredPreClose, true),
            'can_open_next' => in_array($period['status'], ['closed', 'approved'], true)
                && $steps['close_books']['status'] === 'done'
                && $steps['open_next']['status'] !== 'done',
            'can_revert_open_next' => $steps['open_next']['status'] === 'done'
                && $period['status'] === 'closed' && $nextNotApproved,
            'can_revert_close_books' => $period['status'] === 'closed'
                && $steps['close_books']['status'] === 'done'
                && $steps['open_next']['status'] !== 'done',
            'can_revert_fx_revaluation' => $period['status'] === 'closing'
                && $steps['fx_revaluation']['status'] === 'done',
            'can_revert_stock' => $period['status'] === 'closing'
                && $steps['stock']['status'] === 'done',
        ];
    }

    // ── start / abort ─────────────────────────────────────────────────────────

    /**
     * open→closing (CAS). Podmínka R5: předchůdce closed/approved nebo neexistuje.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function start(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            if ($period['status'] !== 'open') {
                throw new ClosingException(
                    'invalid_status_transition',
                    'Uzávěrku lze zahájit jen nad otevřeným obdobím (stav: ' . $period['status'] . ').',
                );
            }
            $prev = $this->previousPeriod($supplierId, (string) $period['starts_on']);
            if ($prev !== null && !in_array($prev['status'], ['closed', 'approved'], true)) {
                throw new ClosingException(
                    'previous_period_open',
                    'Předchozí období ' . $prev['fiscal_year'] . ' není uzavřené — uzavírej chronologicky (R5).',
                );
            }
            // Nerozdělený výsledek na 431 blokuje uzavření knih (precheck vh_431_undistributed),
            // ale rozdělení se účtuje do TOHOTO období — a jakmile je ve stavu 'closing',
            // uživatel se k němu dostane hůř. Řekneme to rovnou na vstupu, ať to nezjistí
            // až u kroku 9 s hromadou uzávěrkových zápisů za sebou.
            $balance431 = round($this->closing->accountBalance($supplierId, '431', (string) $period['ends_on']), 2);
            if (abs($balance431) >= 0.005) {
                throw new ClosingException(
                    'profit_not_distributed',
                    'Na účtu 431 je nerozdělený výsledek hospodaření ' . number_format($balance431, 2, ',', ' ')
                    . ' Kč. Rozdělte ho (431 → 428/429) ještě před zahájením uzávěrky — po zahájení '
                    . 'se do období účtuje hůř a uzavření knih by stejně neprošlo.',
                    422,
                );
            }
            $this->casStatus($supplierId, $periodId, 'closing', $rowVersion, $meta);
            $this->audit($supplierId, 'accounting.closing_started', $periodId, [], $meta);
            return ['period_id' => $periodId, 'status' => 'closing', 'row_version' => $rowVersion + 1];
        });
    }

    /**
     * closing→open (přerušení uzávěrky). Blokováno, existují-li posted
     * closing/fx zápisy (422 closing_entries_exist) — nejprve revert kroků.
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function abort(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            if ($period['status'] !== 'closing') {
                throw new ClosingException(
                    'invalid_status_transition',
                    'Přerušit lze jen probíhající uzávěrku (stav: ' . $period['status'] . ').',
                );
            }
            if ($this->closing->hasClosingEntries($supplierId, $periodId)) {
                throw new ClosingException(
                    'closing_entries_exist',
                    'Existují zaúčtované závěrkové zápisy — nejprve proveď revert kroků (R12).',
                );
            }
            $this->casStatus($supplierId, $periodId, 'open', $rowVersion, $meta);
            $this->audit($supplierId, 'accounting.closing_aborted', $periodId, [], $meta);
            return ['period_id' => $periodId, 'status' => 'open', 'row_version' => $rowVersion + 1];
        });
    }

    // ── krok 1: precheck ──────────────────────────────────────────────────────

    /**
     * Spustí kontroly, uloží je do payload kroku a označí krok done. Errors
     * uložení NEbrání — brání až close_books (re-check inline).
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{checks: list<array<string,mixed>>, ran_at: string}
     */
    public function runPrecheck(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['open', 'closing']);
            // Do payloadu jde jen SNÍMEK (max 10 nálezů na kontrolu). Payload je auditní
            // stopa, ne datový sklad: u firmy s 326 fakturami měl 20 kB, u velké by
            // vycházel na jednotky MB — a `state()` ho posílá při každém načtení stránky.
            // Počet a součet zůstávají plné, takže protokol o uzávěrce nic neztrácí.
            $full = $this->buildChecks($supplierId, $period);
            $payload = [
                'checks' => (new CheckFindingNormalizer())->recap($full, CheckFindingNormalizer::SNAPSHOT_CAP),
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'precheck', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'precheck', 'status' => 'done'], $meta);

            // Volajícímu se vrací plná (capnutá na CAP) sada, ať průvodce hned ukáže
            // víc než uložený snímek.
            return ['checks' => $full, 'ran_at' => $payload['ran_at']];
        });
    }

    /**
     * Nálezy VŠECH kontrol bez stropu — výhradně pro CSV export. Read-only.
     *
     * Náhled na stránce je capnutý, aby se u velké firmy neposílaly megabajty; export
     * capnutý být NESMÍ, jinak by si uživatel stáhl useknutý seznam a pracoval s ním
     * jako s úplným.
     *
     * @return list<array<string,mixed>>
     */
    public function checkFindingsForExport(int $supplierId, int $periodId, ?string $rangeFrom, ?string $rangeTo): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }

        return $this->buildChecks($supplierId, $period, $rangeFrom, $rangeTo, 0);
    }

    /**
     * Účetní období, nebo 404. Read-only.
     *
     * @return array<string,mixed>
     */
    public function periodOrFail(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }

        return $period;
    }

    /**
     * Nálezy JEDNÉ kontroly načtené ŽIVĚ — podklad pro detail v UI. Read-only.
     *
     * Detail se dřív bral z payloadu kroku `precheck`, což je auditní snímek useknutý na
     * {@see CheckFindingNormalizer::SNAPSHOT_CAP}. Kontrola s 21 nálezy proto hlásila 21
     * a v tabulce ukázala 10 — a co hůř, snímek je z okamžiku spuštění prechecku, takže
     * po opravě dokladu ukazoval nálezy, které už neplatí. Živý dotaz řeší obojí naráz:
     * počet i řádky pocházejí z jednoho běhu nad aktuálními daty.
     *
     * @return array{key:string, severity:string, ok:bool, value:mixed}
     */
    public function checkFindings(
        int $supplierId,
        int $periodId,
        string $key,
        ?string $rangeFrom = null,
        ?string $rangeTo = null,
    ): array {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }

        $checks = $this->buildChecks(
            $supplierId,
            $period,
            $rangeFrom,
            $rangeTo,
            CheckFindingNormalizer::DETAIL_CAP,
        );

        foreach ($checks as $c) {
            if (($c['key'] ?? null) === $key) {
                return $c;
            }
        }

        throw new ClosingException('not_found', 'Kontrola „' . $key . '" neexistuje.', 404);
    }

    /**
     * Měsíční kontrola (audit 2026-07, D8) — buildChecks nad LIBOVOLNÝM rozsahem
     * uvnitř období, kdykoli, BEZ zahájení uzávěrky (status se nemění, nezapisuje
     * se žádný krok). Read-only — bez CAS/lockPeriod, jen konzistentní čtení.
     *
     * @return array{period: array<string,mixed>, range_from: string, range_to: string,
     *               checks: list<array<string,mixed>>, ran_at: string}
     */
    public function monthlyCheck(int $supplierId, int $periodId, ?string $rangeFrom, ?string $rangeTo): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        // Bez kontroly stavu ZÁMĚRNĚ. Tohle je čtená sestava — nemění status, nezapisuje
        // krok, nebere zámek. Původní `assertStatus(['open','closing'])` byla zkopírovaná
        // z operací, které do období opravdu zapisují, a v důsledku znemožnila přesně ten
        // případ, kvůli kterému kontrola vznikla: prohlédnout si po uzavření roku, co se
        // v jednotlivých měsících našlo. Uzavřené období je pro čtení tím spíš v pořádku —
        // jeho čísla se už nemění.
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];
        $rangeFrom ??= $startsOn;
        $rangeTo ??= $endsOn;

        if (!self::isIsoDate($rangeFrom) || !self::isIsoDate($rangeTo)) {
            throw new ClosingException('validation_failed', 'date_from/date_to musí být datum ve formátu YYYY-MM-DD.', 422);
        }
        if ($rangeFrom > $rangeTo) {
            throw new ClosingException('validation_failed', 'date_from musí být ≤ date_to.', 422);
        }
        if ($rangeFrom < $startsOn || $rangeTo > $endsOn) {
            throw new ClosingException(
                'validation_failed',
                'Rozsah ' . $rangeFrom . '–' . $rangeTo . ' musí ležet uvnitř období ' . $startsOn . '–' . $endsOn . '.',
                422,
            );
        }

        return [
            'period' => $period,
            'range_from' => $rangeFrom,
            'range_to' => $rangeTo,
            'checks' => $this->buildChecks($supplierId, $period, $rangeFrom, $rangeTo),
            'ran_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function isIsoDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    // ── povinná inventarizace rozvahových účtů (EP-6, §29–30 ZoÚ) ─────────────

    /**
     * Náhled inventarizace (GET) — soupis rozvahových účtů s účetním (book) a uloženým
     * skutečným (counted) stavem, rozdílem, stavem vyřešení + hlavička (odpovědná osoba,
     * datum, protokol, stav dokončení). Read-only, bez zámku.
     *
     * @return array<string,mixed>
     */
    public function inventoryPreview(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $data = $this->balanceInventory->buildWithSaved($supplierId, $periodId);
        $data['row_version'] = (int) $period['row_version'];
        return $data;
    }

    /**
     * Uloží inventarizaci rozvahových účtů (skutečný stav / rozdíly / odpovědnou osobu /
     * protokol) — EP-3/EP-4 vzor: vlastní/vnořená transakce přes {@see tx}, zámek období
     * (lockPeriod + CAS na row_version), auditní událost ve STEJNÉ transakci. Nemění stav
     * období (jen bumpVersion). Dokončení (`complete`) je povolené jen bez nevyřešených
     * rozdílů — jinak zůstane in_progress a uzavření knih zůstává blokované.
     *
     * @param array{responsible_person?:?string, inventory_date?:?string, protocol_ref?:?string, note?:?string, complete?:bool} $header
     * @param array<int, array{counted_balance?:float|int|string|null, resolution?:string, note?:?string}> $itemsByAccount
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function saveInventory(int $supplierId, int $periodId, int $rowVersion, array $header, array $itemsByAccount, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $header, $itemsByAccount, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['open', 'closing']);

            $result = $this->balanceInventory->saveInventory($supplierId, $periodId, $header, $itemsByAccount, $meta['user_id'] ?? null);

            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.inventory_saved', $periodId, [
                'status' => $result['status'],
                'item_count' => $result['item_count'],
                'unresolved_count' => $result['unresolved_count'],
                'completed' => $result['completed'],
            ], $meta);

            return $result + ['row_version' => $rowVersion + 1];
        });
    }

    // ── kroky 2/4/5: checklist confirm/skip ───────────────────────────────────

    /**
     * Potvrzení/přeskočení checklist kroku (depreciation/estimates/deferrals).
     * Payload kroku se zachovává (evidence asistovaných zápisů).
     *
     * @param 'done'|'skipped' $status
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function confirmStep(int $supplierId, int $periodId, string $stepKey, string $status, ?string $note, int $rowVersion, array $meta = []): array
    {
        if (!in_array($stepKey, ['depreciation', 'estimates', 'deferrals', 'provisions', 'income_tax'], true)) {
            throw new ClosingException('unknown_step', 'Krok "' . $stepKey . '" nelze potvrdit tímto způsobem.');
        }
        if (!in_array($status, ['done', 'skipped'], true)) {
            throw new ClosingException('invalid_step_status', 'Stav kroku musí být done|skipped.');
        }
        return $this->tx(function () use ($supplierId, $periodId, $stepKey, $status, $note, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, $stepKey === 'depreciation' ? ['open', 'closing'] : ['closing']);
            $existing = $this->stepsMap($supplierId, $periodId)[$stepKey];
            $this->closing->upsertStep(
                $supplierId,
                $periodId,
                $stepKey,
                $status,
                $existing['payload'],
                $note,
                $meta['user_id'] ?? null,
            );
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => $stepKey, 'status' => $status], $meta);
            return ['step' => $stepKey, 'status' => $status];
        });
    }

    /**
     * Zaúčtování odpisů majetku jako součást uzávěrkového kroku 2 (audit 2026-07 B10).
     * Jediné místo, které smí zaúčtovat odpisy do období ve stavu 'closing' — R7 flag
     * allow_closing_period nastaví VÝHRADNĚ tady, a to jen když je období skutečně
     * 'closing' (v 'open' se účtuje běžně, flag=false). Přímý DepreciationAction::bookYear
     * flag nikdy nenastaví, takže mimo průvodce zůstává účtování striktně 'open'.
     *
     * Booking běží ve vlastních per-majetek transakcích uvnitř bookYear (ownTx) — proto
     * NEobalujeme do lockPeriod/CAS: je to idempotentní účtování, ne stavový přechod
     * období (status se nemění). Chyby jednotlivých majetků se vrací v result['errors'].
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{booked:int, skipped:int, total_accounting:float, total_tax:float,
     *     errors: list<array{asset_id:int, code:string}>}
     */
    public function bookDepreciation(int $supplierId, int $periodId, array $meta = []): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if (!in_array($period['status'], ['open', 'closing'], true)) {
            throw new ClosingException(
                'invalid_status',
                'Odpisy lze zaúčtovat jen v otevřeném (open) nebo uzavíraném (closing) období — '
                    . 'toto období je ve stavu "' . $period['status'] . '".',
            );
        }
        $allowClosing = $period['status'] === 'closing';
        return $this->depreciation->bookYear($supplierId, (int) $period['fiscal_year'], $meta, $allowClosing);
    }

    // ── krok 3: kurzové rozdíly (R10) ─────────────────────────────────────────

    /**
     * Náhled přecenění bez zápisu (GET fx-preview). K výstupu preview přidává
     * `proposals` — návrhy devizových zůstatků bank/pokladen z DENÍKU k D (R10b).
     *
     * @param list<array{account_code:string, currency_code:string, foreign_balance:float|int|string}> $bankRows
     * @return array<string,mixed>
     */
    public function fxPreview(int $supplierId, int $periodId, array $bankRows = []): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $preview = $this->fx->preview($supplierId, $period, $bankRows);
        $preview['proposals'] = $this->closing->bankProposals($supplierId, (string) $period['ends_on']);
        return $preview;
    }

    /**
     * Zaúčtuje kurzové rozdíly k rozvahovému dni: slot 1 (saldokonto) a slot 2
     * (banka/pokladna) — per slot jeden zápis, re-run = in-place rewrite; prázdný
     * slot maže případný existující zápis. Payload kroku: rozpad per doklad + kurzy.
     *
     * @param list<array{account_code:string, currency_code:string, foreign_balance:float|int|string}> $bankRows
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runFxRevaluation(int $supplierId, int $periodId, array $bankRows, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $bankRows, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            $preview = $this->fx->preview($supplierId, $period, $bankRows);
            $entries = $this->fx->buildEntries($supplierId, $preview);

            $slots = [
                'saldo' => ClosingSourceId::fxSaldo($periodId),
                'bank' => ClosingSourceId::fxBank($periodId),
            ];
            $entryIds = [];
            $deleted = [];
            foreach ($slots as $slot => $sourceId) {
                $lines = $entries[$slot] ?? [];
                if ($lines !== []) {
                    $this->assertKnownCodes($supplierId, $lines);
                    $existing = $this->journal->findBySource($supplierId, 'fx_revaluation', $sourceId);
                    $docNo = $existing !== null && $existing['document_no'] !== null
                        ? (string) $existing['document_no']
                        : $this->series->next($supplierId, 'fx', $fiscalYear);
                    $entryIds[$slot] = $this->posting->postDocument($supplierId, 'fx_revaluation', $sourceId, $lines, [
                        'entry_date' => $endsOn,
                        'document_no' => $docNo,
                        'description' => 'Kurzové rozdíly k rozvahovému dni',
                        'posted' => true,
                        'posted_by' => $meta['posted_by'] ?? null,
                        'user_id' => $meta['user_id'] ?? null,
                        'ip' => $meta['ip'] ?? null,
                        'user_agent' => $meta['user_agent'] ?? null,
                        'allow_closing_period' => true,
                    ]);
                } else {
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'fx_revaluation', $sourceId);
                    if ($dump !== null) {
                        $deleted[$slot] = $dump;
                    }
                }
            }

            $payload = [
                'rate_info' => $preview['rate_info'] ?? [],
                'detail' => $preview['saldo']['detail'] ?? [],
                'saldo_lines' => $preview['saldo']['lines'] ?? [],
                'bank_rows' => $bankRows,
                'bank_lines' => $preview['bank']['lines'] ?? [],
                'totals' => $preview['totals'] ?? [],
                'entry_ids' => $entryIds,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'fx_revaluation', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.fx_revalued', $periodId, ['entry_ids' => $entryIds, 'totals' => $preview['totals'] ?? []], $meta);
            return ['entry_ids' => $entryIds, 'deleted' => $deleted, 'totals' => $preview['totals'] ?? []];
        });
    }

    // ── krok 6: zásoby — uzávěrka způsobem B (SKLAD §3.4) ─────────────────────

    /**
     * Uzávěrkový krok „Zásoby" — JEDINÉ automatické účtování skladu pod způsobem B
     * (ČÚS 015): sloty closing/shortage/surplus se source_type 'closing' a
     * slotovaným source_id (ClosingSourceId::stock*), entry_date = ends_on,
     * číslo z řady 'closing' (UZ), R7 flag. Per slot jeden zápis, re-run =
     * in-place rewrite; prázdný slot maže případný stale zápis (vzor FX kroku).
     *
     *  - closing:  konečný stav dle skladové evidence k rozvahovému dni —
     *              MD 112 / D 501 (materiál), MD 132 / D 504 (zboží).
     *  - shortage: inventurní manka období — reklasifikace MD 549 / D 501|504.
     *  - surplus:  inventurní přebytky — MD 501|504 / D 648.
     *  - product:  MD 123 / D 583; otevření roku se zrcadlí stejně jako ostatní zásoby.
     *
     * Otevření roku (MD 501/504 / D 112/132) účtuje openNext() do N+1 zrcadlem
     * closing slotu — bilanční kontinuita po haléřích. Firmy bez skladu /
     * bez podvojného účetnictví → krok se označí skipped (blokoval by close_books).
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runStockValuation(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);

            if (!$this->stockStepRequired($supplierId)) {
                $payload = ['reason' => 'stock_not_applicable', 'ran_at' => date('Y-m-d H:i:s')];
                $this->closing->upsertStep($supplierId, $periodId, 'stock', 'skipped', $payload, null, $meta['user_id'] ?? null);
                $this->bumpVersion($supplierId, $periodId, $rowVersion);
                $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'stock', 'status' => 'skipped'], $meta);
                return ['status' => 'skipped'] + $payload;
            }

            $startsOn = (string) $period['starts_on'];
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            $totals = $this->stockValuation->totals($supplierId, $startsOn, $endsOn);

            $slots = [
                'closing' => [
                    'source_id' => ClosingSourceId::stockClosing($periodId),
                    'description' => 'Uzávěrka zásob (způsob B) — konečný stav k rozvahovému dni',
                    'lines' => array_merge(
                        $this->stockRuleLines($supplierId, 'stock.closing.material', $totals['closing']['material']),
                        $this->stockRuleLines($supplierId, 'stock.closing.goods', $totals['closing']['goods']),
                        $this->stockRuleLines($supplierId, 'stock.closing.product', $totals['closing']['product']),
                    ),
                ],
                'shortage' => [
                    'source_id' => ClosingSourceId::stockShortage($periodId),
                    'description' => 'Uzávěrka zásob (způsob B) — reklasifikace inventurních mank',
                    'lines' => array_merge(
                        $this->stockRuleLines($supplierId, 'stock.shortage.reclass.material', $totals['shortage']['material']),
                        $this->stockRuleLines($supplierId, 'stock.shortage.reclass.goods', $totals['shortage']['goods']),
                    ),
                ],
                'surplus' => [
                    'source_id' => ClosingSourceId::stockSurplus($periodId),
                    'description' => 'Uzávěrka zásob (způsob B) — inventurní přebytky',
                    'lines' => array_merge(
                        $this->stockRuleLines($supplierId, 'stock.surplus.material', $totals['surplus']['material']),
                        $this->stockRuleLines($supplierId, 'stock.surplus.goods', $totals['surplus']['goods']),
                    ),
                ],
            ];

            $entryIds = [];
            $deleted = [];
            foreach ($slots as $slot => $def) {
                $sourceId = (int) $def['source_id'];
                if ($def['lines'] !== []) {
                    $this->assertKnownCodes($supplierId, $def['lines']);
                    $existing = $this->journal->findBySource($supplierId, 'closing', $sourceId);
                    $docNo = $existing !== null && $existing['document_no'] !== null
                        ? (string) $existing['document_no']
                        : $this->series->next($supplierId, 'closing', $fiscalYear);
                    $entryIds[$slot] = $this->posting->postDocument($supplierId, 'closing', $sourceId, $def['lines'], [
                        'entry_date' => $endsOn,
                        'document_no' => $docNo,
                        'description' => (string) $def['description'],
                        'posted' => true,
                        'posted_by' => $meta['posted_by'] ?? null,
                        'user_id' => $meta['user_id'] ?? null,
                        'ip' => $meta['ip'] ?? null,
                        'user_agent' => $meta['user_agent'] ?? null,
                        'allow_closing_period' => true,
                    ]);
                } else {
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'closing', $sourceId);
                    if ($dump !== null) {
                        $deleted[$slot] = $dump;
                    }
                }
            }

            $warnings = $this->stockValuation->unmatchedDocuments($supplierId, $endsOn);

            $payload = [
                'totals' => $totals,
                'entry_ids' => $entryIds,
                'warnings' => $warnings,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'stock', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'stock', 'status' => 'done'], $meta);
            return ['entry_ids' => $entryIds, 'deleted' => $deleted, 'totals' => $totals, 'warnings' => $warnings];
        });
    }

    // ── kroky 4/5: asistent dohadů a časového rozlišení (R22) ─────────────────

    /**
     * Asistovaný ruční zápis (source manual, document_no z řady manual VŽDY,
     * entry_date = ends_on, R7 flag). Id zápisu se eviduje v payload.entries[] kroku.
     *
     * @param array{row_version:int, rule_key:string, amount:float|int|string, description:string, counter_account?:?string} $body
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, document_no:string}
     */
    public function createAssistedEntry(int $supplierId, int $periodId, string $stepKey, array $body, array $meta = []): array
    {
        if (!isset(self::ASSIST_RULES[$stepKey])) {
            // Seznam se odvozuje z konstanty, ne z pevného textu — jinak hláška lže,
            // jakmile krok přibude (což se u `provisions` stalo).
            throw new ClosingException('unknown_step', sprintf(
                'Asistované zápisy podporují jen kroky %s.',
                implode('/', array_keys(self::ASSIST_RULES)),
            ));
        }
        $ruleKey = (string) ($body['rule_key'] ?? '');
        if (!in_array($ruleKey, self::ASSIST_RULES[$stepKey], true)) {
            throw new ClosingException('validation_failed', 'Nepovolená kontace "' . $ruleKey . '" pro krok ' . $stepKey . '.');
        }
        $amount = round((float) ($body['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new ClosingException('validation_failed', 'Částka musí být kladná.');
        }
        $description = trim((string) ($body['description'] ?? ''));
        if ($description === '') {
            throw new ClosingException('validation_failed', 'Popis zápisu je povinný.');
        }
        $rowVersion = (int) ($body['row_version'] ?? 0);

        return $this->tx(function () use ($supplierId, $periodId, $stepKey, $ruleKey, $amount, $description, $body, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);

            $rule = $this->rules->resolve($supplierId, $ruleKey);
            if ($rule === null) {
                throw new ClosingException('posting_rule_missing', 'Kontace "' . $ruleKey . '" není naseedovaná v posting_rules.');
            }
            $counter = isset($body['counter_account']) && $body['counter_account'] !== null && $body['counter_account'] !== ''
                ? (string) $body['counter_account']
                : null;
            $debit = $rule['debit_account_code'] ?? null;
            $credit = $rule['credit_account_code'] ?? null;
            if ($debit === null) {
                $debit = $counter;
            }
            if ($credit === null) {
                $credit = $counter;
            }
            if ($debit === null || $credit === null) {
                throw new ClosingException('counter_account_required', 'Kontace "' . $ruleKey . '" nemá pevnou stranu — zadej protiúčet.');
            }
            $lines = [
                ['account_code' => (string) $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => (string) $credit, 'side' => 'credit', 'amount' => $amount],
            ];
            $this->assertKnownCodes($supplierId, $lines);

            $docNo = $this->series->next($supplierId, 'manual', (int) $period['fiscal_year']);
            $entryId = $this->posting->postDocument($supplierId, 'manual', null, $lines, [
                'entry_date' => (string) $period['ends_on'],
                'document_no' => $docNo,
                'description' => $description,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $step = $this->stepsMap($supplierId, $periodId)[$stepKey];
            $payload = $step['payload'] ?? [];
            $payload['entries'][] = [
                'entry_id' => $entryId,
                'document_no' => $docNo,
                'rule_key' => $ruleKey,
                'amount' => $amount,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, $stepKey, $step['status'], $payload, $step['note'], $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            return ['entry_id' => $entryId, 'document_no' => $docNo];
        });
    }

    /**
     * Storno asistovaného zápisu (R12 výjimka: manual zápisy se NIKDY nemažou,
     * reverzují se standardním stornem s R7 flagem). Id se v payloadu přesune
     * z entries[] do reversed[].
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, reversal_entry_id:int, step:string}
     */
    public function reverseAssistedEntry(int $supplierId, int $periodId, int $entryId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $entryId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);

            $steps = $this->stepsMap($supplierId, $periodId);
            $stepKey = null;
            $entryIdx = null;
            foreach (array_keys(self::ASSIST_RULES) as $key) {
                foreach (($steps[$key]['payload']['entries'] ?? []) as $i => $e) {
                    if ((int) ($e['entry_id'] ?? 0) === $entryId) {
                        $stepKey = $key;
                        $entryIdx = $i;
                        break 2;
                    }
                }
            }
            if ($stepKey === null || $entryIdx === null) {
                throw new ClosingException('entry_not_in_step', 'Zápis #' . $entryId . ' není evidovaný v asistentovi tohoto období.', 404);
            }

            $reversalId = $this->posting->reverse($supplierId, $entryId, [
                'entry_date' => (string) $period['ends_on'],
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $step = $steps[$stepKey];
            $payload = $step['payload'] ?? [];
            $record = $payload['entries'][$entryIdx];
            $record['reversal_entry_id'] = $reversalId;
            $record['reversed_at'] = date('Y-m-d H:i:s');
            array_splice($payload['entries'], $entryIdx, 1);
            $payload['entries'] = array_values($payload['entries']);
            $payload['reversed'][] = $record;
            $this->closing->upsertStep($supplierId, $periodId, $stepKey, $step['status'], $payload, $step['note'], $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            return ['entry_id' => $entryId, 'reversal_entry_id' => $reversalId, 'step' => $stepKey];
        });
    }

    // ── časové rozlišení drobného majetku (§DM / Task 11) — 381 jako VOLITELNÁ politika ──

    /**
     * Náhled časového rozlišení drobného majetku k rozvahovému dni (GET). Drobný majetek
     * se NEODPISUJE — jde celý do 501 v roce pořízení (§26/2 ZDP); jeho rozprostření na
     * 381 je VOLITELNÁ účetní politika (§7 ZoÚ, věrný obraz), NE zákon, proto nikdy natvrdo
     * 50 %. Režim per karta pořízená v období:
     *   none      → 0 (nic se neodkládá),
     *   pro_rata  → cena × BUDOUCÍ (zbývající) část intervalu užitku (délky = počet dnů období,
     *               počínající dnem pořízení) ležící ZA rozvahovým dnem; uplynulá část je náklad období,
     *   flat_pct  → cena × pct/100.
     * Systém jen NABÍZÍ — účetní režim potvrdí/upraví ve {@see runSmallAssetAccrual}.
     *
     * Kontrolní součet: vedle návrhu z KARET (small_assets) vrací i total 501 „drobný
     * majetek" z {@see SmallAssetReportService::expenseBreakdown} (ŘÁDKY faktur) — účetní
     * tak vidí rozdíl (karta může chybět nebo být ruční) TRANSPARENTNĚ, ne tiše.
     *
     * @param 'none'|'pro_rata'|'flat_pct'|null $mode null = režim z nastavení firmy
     * @param float|null $materialityLimit limit z formuláře (ještě neuložený); null = vzít
     *                                     uložený limit období. Umožňuje účetní ověřit
     *                                     přiměřenost DŘÍV, než politiku zapíše.
     * @return array<string,mixed>
     */
    public function smallAssetAccrualPreview(int $supplierId, int $periodId, ?string $mode = null, ?float $pct = null, ?float $materialityLimit = null): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];

        // §DM politika je PER OBDOBÍ (ne per firma). Když FE režim nepošle (pre-fill
        // z uloženého), dosaď politiku TOHOTO období — seed z firemního defaultu proběhl
        // při AccountingPeriodRepository::create, takže tady je období zdrojem pravdy.
        if ($mode === null) {
            $policy = $this->periods->getAccrualPolicy($supplierId, $periodId);
            $mode = $policy['small_asset_accrual_mode'];
            if ($pct === null) {
                $pct = $policy['small_asset_accrual_pct'];
            }
        }
        if (!in_array($mode, ['none', 'pro_rata', 'flat_pct'], true)) {
            throw new ClosingException('validation_failed', 'Neznámý režim rozlišení drobného majetku "' . $mode . '".');
        }
        if ($mode === 'flat_pct' && ($pct === null || $pct < 0 || $pct > 100)) {
            throw new ClosingException('validation_failed', 'Paušální procento musí být v rozsahu 0–100 %.');
        }

        $periodDays = self::daysInclusive($startsOn, $endsOn);
        $items = [];
        $total = 0.0;
        $cardsTotal = 0.0;
        foreach ($this->smallAssets->additionsBetween($supplierId, $startsOn, $endsOn) as $card) {
            $price = round((float) ($card['price'] ?? 0), 2);
            $cardsTotal = round($cardsTotal + $price, 2);
            // EP-15: kotva = datum uvedení do užívání (fallback pořízení); doba dle doložených měsíců.
            $inUseDate = isset($card['put_into_use_date']) && $card['put_into_use_date'] !== null && (string) $card['put_into_use_date'] !== ''
                ? (string) $card['put_into_use_date']
                : (string) $card['acquisition_date'];
            $usefulMonths = isset($card['useful_months']) && $card['useful_months'] !== null ? (int) $card['useful_months'] : null;
            [$defer, $fraction] = $this->smallAssetDefer($mode, $price, (float) $pct, $inUseDate, $usefulMonths, $startsOn, $endsOn, $periodDays);
            $total = round($total + $defer, 2);
            $items[] = [
                'small_asset_id' => (int) $card['id'],
                'name' => (string) $card['name'],
                'document_ref' => $card['document_ref'] !== null ? (string) $card['document_ref'] : null,
                'acquisition_date' => (string) $card['acquisition_date'],
                'in_use_date' => $inUseDate,
                'useful_months' => $usefulMonths,
                'price' => $price,
                'status' => (string) $card['status'],
                'fraction' => $mode === 'pro_rata' ? round($fraction, 6) : null,
                'deferred_amount' => $defer,
            ];
        }

        // Čistý obrat 501 „drobný majetek" z řádků faktur (net dobropisů). U pro_rata je to
        // jen kontrola proti kartám; u flat_pct je to PŘÍMO BÁZE odkladu (viz níže).
        $breakdown501 = 0.0;
        foreach (($this->smallAssetReport->expenseBreakdown($supplierId, $startsOn, $endsOn)['groups'] ?? []) as $g) {
            if (($g['expense_kind'] ?? null) === 'small_asset') {
                $breakdown501 = round((float) $g['total'], 2);
            }
        }

        // §DM báze pro flat_pct = ČISTÝ OBRAT 501.200 (net dobropisů), NE báze karet.
        // Účetní odkládá % ze skutečného nákladu drobného majetku (§7 ZoÚ / ČÚS), ne z
        // evidence karet: ta bývá neúplná (řádek bez karty) a naopak by započítala i majetek
        // pořízený a VRÁCENÝ v témže období (karta zůstává jako `disposed`, ale additionsBetween
        // ji dá do báze) — net obrat 501 ho správně vynuluje. Karty zůstávají evidencí §28/5;
        // per-karta `deferred_amount` u flat_pct proto nedává smysl (null), rozhoduje `total`.
        $materiality = null;
        if ($mode === 'flat_pct') {
            // Preferuj skutečný náklad (obrat 501.200); fallback na bázi karet jen když
            // není zaúčtovaný žádný 501 „drobný majetek" (degenerativní stav / evidence
            // bez faktury) — ať odklad „nezmizí" na 0.
            $base = $breakdown501 > 0.005 ? $breakdown501 : $cardsTotal;
            $total = round($base * (float) $pct / 100.0, 2);
            foreach ($items as $i => $_) {
                $items[$i]['deferred_amount'] = null;
            }
            // EP-15: paušál je politika jen pro NEVÝZNAMNÝ homogenní soubor. Test přiměřenosti:
            // báze 501.200 nesmí přesáhnout zdokumentovaný limit významnosti období; bez limitu
            // (NULL) paušál není povolen (nutí účetní politiku doložit). Náhled jen informuje,
            // zápis vynucuje {@see runSmallAssetAccrual}.
            // Přednost má limit z formuláře; bez něj uložená politika období. Jinak by
            // účetní neměla jak si přiměřenost ověřit před zápisem — musela by krok
            // spustit „naslepo" a nechat se odmítnout.
            $limit = $materialityLimit
                ?? $this->periods->getAccrualPolicy($supplierId, $periodId)['small_asset_flat_pct_materiality_limit']
                ?? null;
            $materiality = [
                'base' => $base,
                'limit' => $limit,
                'passes' => $limit !== null && $base <= round($limit + 0.005, 2),
            ];
        }

        $existingEntry = $this->journal->findBySource($supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($periodId));
        $existingPayload = $this->stepsMap($supplierId, $periodId)['deferrals']['payload']['small_asset_accrual'] ?? null;

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year'], 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            'mode' => $mode,
            'pct' => $mode === 'flat_pct' ? round((float) $pct, 2) : null,
            'period_days' => $periodDays,
            'items' => $items,
            'total' => $total,
            'materiality' => $materiality,
            'cards_total' => $cardsTotal,
            'breakdown_501_small_asset' => $breakdown501,
            'cards_vs_501_diff' => round($cardsTotal - $breakdown501, 2),
            'existing' => $existingEntry === null ? null : [
                'entry_id' => (int) $existingEntry['id'],
                'mode' => is_array($existingPayload) ? ($existingPayload['mode'] ?? null) : null,
                'pct' => is_array($existingPayload) ? ($existingPayload['pct'] ?? null) : null,
                'amount' => is_array($existingPayload) ? round((float) ($existingPayload['total'] ?? 0), 2) : null,
            ],
        ];
    }

    /**
     * Zaúčtování časového rozlišení drobného majetku (§DM / Task 11) — jeden agregátní
     * idempotentní zápis MD 381 / D 501 (source ('small_asset_accrual', period_id),
     * entry_date = ends_on, R7 flag). Re-run = in-place rewrite; nulový návrh (režim none
     * nebo žádné karty) maže stale zápis, ať odklad „nevisí" (vzor OP/zásoby). Zvolený
     * režim se uloží na TOTO období (per období, ne firmě). Rozpuštění v N+1 (MD 501 / D 381)
     * řeší {@see openNext}.
     *
     * @param 'none'|'pro_rata'|'flat_pct' $mode
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runSmallAssetAccrual(int $supplierId, int $periodId, string $mode, ?float $pct, int $rowVersion, array $meta = [], ?float $materialityLimit = null): array
    {
        if (!in_array($mode, ['none', 'pro_rata', 'flat_pct'], true)) {
            throw new ClosingException('validation_failed', 'Neznámý režim rozlišení drobného majetku "' . $mode . '".');
        }
        if ($mode === 'flat_pct' && ($pct === null || $pct < 0 || $pct > 100)) {
            throw new ClosingException('validation_failed', 'Paušální procento musí být v rozsahu 0–100 %.');
        }
        return $this->tx(function () use ($supplierId, $periodId, $mode, $pct, $rowVersion, $meta, $materialityLimit): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            // §DM politika je PER OBDOBÍ — ulož zvolený režim (+ limit významnosti u paušálu)
            // na TOTO období (ne firmě), aby uzávěrka jednoho období neměnila politiku jiného.
            // Persist i pro 'none', ať zvolené „nic neodkládat" na období drží.
            $this->periods->setAccrualPolicy($supplierId, $periodId, $mode, $mode === 'flat_pct' ? $pct : null, $materialityLimit);

            // Návrh počítej stejně jako preview (zdroj pravdy = karty + režim).
            $preview = $this->smallAssetAccrualPreview($supplierId, $periodId, $mode, $pct);
            $total = round((float) $preview['total'], 2);

            // EP-15: paušál jen pro nevýznamný homogenní soubor s testem přiměřenosti.
            if ($mode === 'flat_pct') {
                $mat = $preview['materiality'] ?? null;
                if ($mat === null || ($mat['limit'] ?? null) === null) {
                    throw new ClosingException(
                        'flat_pct_not_documented',
                        'Paušální rozlišení drobného majetku vyžaduje zdokumentovaný limit významnosti (účetní politika období). '
                        . 'Doplň limit, nebo použij pro_rata.',
                        422,
                    );
                }
                if (!($mat['passes'] ?? false)) {
                    throw new ClosingException(
                        'flat_pct_not_material',
                        'Paušál lze použít jen pro nevýznamný soubor: báze 501 (' . number_format((float) ($mat['base'] ?? 0), 2, ',', ' ')
                        . ') přesahuje limit významnosti (' . number_format((float) ($mat['limit'] ?? 0), 2, ',', ' ') . '). Použij pro_rata.',
                        422,
                    );
                }
            }
            $sourceId = ClosingSourceId::smallAssetAccrual($periodId);

            $step = $this->stepsMap($supplierId, $periodId)['deferrals'];
            $payload = $step['payload'] ?? [];
            $stepStatus = (string) $step['status'];

            // Nulový návrh → smaž stale zápis.
            if ((int) round($total * 100) === 0) {
                $this->closing->deleteClosingEntry($supplierId, 'small_asset_accrual', $sourceId);
                unset($payload['small_asset_accrual']);
                $this->closing->upsertStep($supplierId, $periodId, 'deferrals', $stepStatus, $payload, $step['note'], $meta['user_id'] ?? null);
                $this->bumpVersion($supplierId, $periodId, $rowVersion);
                $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'deferrals', 'status' => 'done'], $meta);
                return ['entry_id' => null, 'mode' => $mode, 'pct' => $mode === 'flat_pct' ? round((float) $pct, 2) : null, 'total' => 0.0, 'items' => $preview['items'], 'removed' => true];
            }

            $rule = $this->rules->resolve($supplierId, 'accrual.small_asset.defer');
            if ($rule === null) {
                throw new ClosingException('posting_rule_missing', 'Kontace časového rozlišení drobného majetku (381/501) není naseedovaná — spusť migrace, případně doplň pravidlo accrual.small_asset.defer.');
            }
            $debit = (string) ($rule['debit_account_code'] ?? '381');
            $credit = (string) ($rule['credit_account_code'] ?? '501');
            $lines = [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $total],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $total],
            ];
            $this->assertKnownCodes($supplierId, $lines);

            $existing = $this->journal->findBySource($supplierId, 'small_asset_accrual', $sourceId);
            $docNo = $existing !== null && $existing['document_no'] !== null
                ? (string) $existing['document_no']
                : $this->series->next($supplierId, 'manual', $fiscalYear);

            $entryId = $this->posting->postDocument($supplierId, 'small_asset_accrual', $sourceId, $lines, [
                'entry_date' => $endsOn,
                'document_no' => $docNo,
                'description' => 'Časové rozlišení drobného majetku ' . $fiscalYear,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $payload['small_asset_accrual'] = [
                'entry_id' => $entryId,
                'document_no' => $docNo,
                'mode' => $mode,
                'pct' => $mode === 'flat_pct' ? round((float) $pct, 2) : null,
                'total' => $total,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'deferrals', $stepStatus, $payload, $step['note'], $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'deferrals', 'status' => 'done'], $meta);
            return ['entry_id' => $entryId, 'document_no' => $docNo, 'mode' => $mode, 'pct' => $mode === 'flat_pct' ? round((float) $pct, 2) : null, 'total' => $total, 'items' => $preview['items']];
        });
    }

    // ── časové rozlišení nákladů příštích období (§DČR / Task 12) — 381 z označených faktur ──

    /**
     * Náhled časového rozlišení nákladů příštích období (381) k rozvahovému dni (GET).
     * Na rozdíl od drobného majetku (volitelná politika) jsou zde ČÁSTKY dané: účetní u
     * ŘÁDKU přijaté faktury označil období od–do (accrual_from/accrual_to), do kterého náklad
     * patří (pojistné, parkovné, předplatné). Systém spočítá pro-rata dle DNŮ, jaká část nákladu
     * PŘESAHUJE konec uzavíraného období → odloží se na 381 (náklady příštích období).
     *
     * Formule: total_without_vat × (dny od max(accrual_from, 1. den N+1) do accrual_to
     *          / celkový počet dnů rozlišení). EUR/cizí měna se přepočte kurzem dokladu na CZK.
     *
     * Bere jen řádky faktur zaúčtovaných DO tohoto období (effective_cost_date v období, status
     * != draft/cancelled) — jinak by se odložil náklad, který v VH tohoto roku vůbec není.
     * Vícleté rozlišení (přesah přes víc než 1 hranici): odloží se vše za koncem období, v N+1 se
     * rozpustí a re-účtuje celé do N+1 — u typického ročního pojistného (1 hranice) je to přesné.
     *
     * @return array<string,mixed>
     */
    public function prepaidExpenseAccrualPreview(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];
        $nextStart = (new \DateTimeImmutable($endsOn))->modify('+1 day')->format('Y-m-d');

        $items = [];
        $documents = [];
        $byAccount = [];
        $total = 0.0;
        foreach ($this->prepaidExpenseAccrualRows($supplierId, $startsOn, $endsOn) as $row) {
            $from = substr((string) $row['accrual_from'], 0, 10);
            $to = substr((string) $row['accrual_to'], 0, 10);
            $totalDays = self::daysInclusive($from, $to);
            if ($totalDays <= 0) {
                continue;
            }
            $deferFrom = $from < $nextStart ? $nextStart : $from;
            $deferDays = self::daysInclusive($deferFrom, $to);
            $fraction = min(1.0, $deferDays / $totalDays);

            $rate = $this->prepaidExpenseRate($row);
            $netCzk = round((float) $row['total_without_vat'] * $rate, 2);
            // EP-15: odloží se net − kumulativně uznaný náklad do konce období (kotva pro
            // přesné víceleté rozpouštění; pro jednoleté rozlišení = původní chování).
            $deferred = round($netCzk - self::prepaidCumRecognized($netCzk, $from, $to, $endsOn), 2);
            if ((int) round($deferred * 100) === 0) {
                continue;
            }
            // EP-15: harmonogram rozpouštění po (kalendářních) obdobích — kolik odloženého
            // nákladu se uvolní do 5xx v každém následujícím roce. Σ = deferred (telescopuje).
            $schedule = [];
            $toYear = (int) substr($to, 0, 4);
            for ($fy = (int) substr($nextStart, 0, 4); $fy <= $toYear; $fy++) {
                $rel = round(
                    self::prepaidCumRecognized($netCzk, $from, $to, $fy . '-12-31')
                    - self::prepaidCumRecognized($netCzk, $from, $to, ($fy - 1) . '-12-31'),
                    2,
                );
                if ((int) round($rel * 100) !== 0) {
                    $schedule[] = ['fiscal_year' => $fy, 'amount' => $rel];
                }
            }
            $account = $this->prepaidExpenseCreditAccount(
                $supplierId,
                $row['expense_account_code'] !== null ? (string) $row['expense_account_code'] : null,
                $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null,
            );

            $total = round($total + $deferred, 2);
            $byAccount[$account] = round(($byAccount[$account] ?? 0.0) + $deferred, 2);
            $piId = (int) $row['purchase_invoice_id'];
            $documents[$piId] = [
                'purchase_invoice_id' => $piId,
                'vendor_invoice_number' => (string) $row['vendor_invoice_number'],
                'deferred_amount' => round(($documents[$piId]['deferred_amount'] ?? 0.0) + $deferred, 2),
            ];
            $items[] = [
                'item_id' => (int) $row['item_id'],
                'purchase_invoice_id' => $piId,
                'vendor_invoice_number' => (string) $row['vendor_invoice_number'],
                'description' => (string) $row['description'],
                'currency_code' => (string) $row['currency_code'],
                'total_without_vat' => round((float) $row['total_without_vat'], 2),
                'total_czk' => $netCzk,
                'credit_account' => $account,
                'accrual_from' => $from,
                'accrual_to' => $to,
                'total_days' => $totalDays,
                'deferred_days' => $deferDays,
                'fraction' => round($fraction, 6),
                'deferred_amount' => $deferred,
                'release_schedule' => $schedule,
            ];
        }

        $existingEntry = $this->journal->findBySource($supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($periodId));
        $existingPayload = $this->stepsMap($supplierId, $periodId)['deferrals']['payload']['prepaid_expense_accrual'] ?? null;

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year'], 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            'next_period_start' => $nextStart,
            'items' => $items,
            'documents' => array_values($documents),
            'by_account' => $byAccount,
            'total' => $total,
            'existing' => $existingEntry === null ? null : [
                'entry_id' => (int) $existingEntry['id'],
                'amount' => is_array($existingPayload) ? round((float) ($existingPayload['total'] ?? 0), 2) : null,
            ],
        ];
    }

    /**
     * Zaúčtování časového rozlišení nákladů příštích období (§DČR / Task 12) — jeden agregátní
     * idempotentní zápis MD 381 / D 5xx (source ('prepaid_expense_accrual', period_id), entry_date
     * = ends_on, R7 flag). Nákladová strana rozpadnuta na účty dle řádků (expense_account_code,
     * jinak expense_kind). Re-run = in-place rewrite; nulový návrh (žádné označené řádky) maže
     * stale zápis, ať odklad „nevisí" (vzor OP/zásoby/DM). Rozpuštění v N+1 (MD 5xx / D 381)
     * řeší {@see openNext}. Částky jsou dané z faktur (accrual_from/to), účetní je jen potvrzuje.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runPrepaidExpenseAccrual(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            $preview = $this->prepaidExpenseAccrualPreview($supplierId, $periodId);
            $total = round((float) $preview['total'], 2);
            $sourceId = ClosingSourceId::prepaidExpenseAccrual($periodId);

            $step = $this->stepsMap($supplierId, $periodId)['deferrals'];
            $payload = $step['payload'] ?? [];
            $stepStatus = (string) $step['status'];

            // Nulový návrh → smaž stale zápis.
            if ((int) round($total * 100) === 0) {
                $this->closing->deleteClosingEntry($supplierId, 'prepaid_expense_accrual', $sourceId);
                unset($payload['prepaid_expense_accrual']);
                $this->closing->upsertStep($supplierId, $periodId, 'deferrals', $stepStatus, $payload, $step['note'], $meta['user_id'] ?? null);
                $this->bumpVersion($supplierId, $periodId, $rowVersion);
                $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'deferrals', 'status' => 'done'], $meta);
                return ['entry_id' => null, 'total' => 0.0, 'items' => $preview['items'], 'removed' => true];
            }

            // MD 381 z pravidla accrual.prepaid.expense (tenant si účet 381 může přesměrovat);
            // D strana = nákladové účty z řádků (preview by_account), NE credit z pravidla (221).
            $rule = $this->rules->resolve($supplierId, 'accrual.prepaid.expense');
            $deferAccount = (string) ($rule['debit_account_code'] ?? '381');
            $lines = [['account_code' => $deferAccount, 'side' => 'debit', 'amount' => $total]];
            foreach ($preview['by_account'] as $account => $amount) {
                $lines[] = ['account_code' => (string) $account, 'side' => 'credit', 'amount' => round((float) $amount, 2)];
            }
            $this->assertKnownCodes($supplierId, $lines);

            $existing = $this->journal->findBySource($supplierId, 'prepaid_expense_accrual', $sourceId);
            $docNo = $existing !== null && $existing['document_no'] !== null
                ? (string) $existing['document_no']
                : $this->series->next($supplierId, 'manual', $fiscalYear);

            $entryId = $this->posting->postDocument($supplierId, 'prepaid_expense_accrual', $sourceId, $lines, [
                'entry_date' => $endsOn,
                'document_no' => $docNo,
                'description' => 'Časové rozlišení nákladů příštích období ' . $fiscalYear,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $payload['prepaid_expense_accrual'] = [
                'entry_id' => $entryId,
                'document_no' => $docNo,
                'total' => $total,
                'by_account' => $preview['by_account'],
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'deferrals', $stepStatus, $payload, $step['note'], $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'deferrals', 'status' => 'done'], $meta);
            return ['entry_id' => $entryId, 'document_no' => $docNo, 'total' => $total, 'items' => $preview['items']];
        });
    }

    /**
     * Řádky přijatých faktur označené k časovému rozlišení, jejichž náklad přesahuje konec
     * uzavíraného období (accrual_to > ends_on). Restrikce na doklady zaúčtované DO období
     * (effective_cost_date v období, status != draft/cancelled) — odkládá se jen náklad z VH.
     *
     * @return list<array<string,mixed>>
     */
    private function prepaidExpenseAccrualRows(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id AS item_id, pii.purchase_invoice_id, pii.description,
                    pii.total_without_vat, pii.expense_kind, pii.expense_account_code,
                    pii.accrual_from, pii.accrual_to,
                    pi.vendor_invoice_number, pi.exchange_rate, cur.code AS currency_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               JOIN currencies cur       ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN (\'draft\', \'cancelled\')
                AND pii.accrual_from IS NOT NULL
                AND pii.accrual_to IS NOT NULL
                AND pii.accrual_to > ?
                AND pi.effective_cost_date BETWEEN ? AND ?
              ORDER BY pi.id, pii.order_index, pii.id'
        );
        $stmt->execute([$supplierId, $endsOn, $startsOn, $endsOn]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * EP-15: řádky pro ROZPOUŠTĚNÍ odloženého nákladu do cílového období — narozdíl od
     * {@see prepaidExpenseAccrualRows} BEZ omezení na effective_cost_date, aby se v N+1
     * (openNext) našel i víceletý odklad pořízený v N (a rozpustil jen tranši N+1, ne celý
     * zbytek). `originEndsOn` = konec období, ze kterého openNext otevírá (deferováno za ním);
     * `targetEnd` = konec cílového období (rozlišení se týká jen položek začínajících do něj).
     *
     * @return list<array<string,mixed>>
     */
    private function prepaidExpenseReleaseRows(int $supplierId, string $originEndsOn, string $targetEnd): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.total_without_vat, pii.expense_kind, pii.expense_account_code,
                    pii.accrual_from, pii.accrual_to, pii.purchase_invoice_id,
                    pi.exchange_rate, cur.code AS currency_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               JOIN currencies cur       ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN (\'draft\', \'cancelled\')
                AND pii.accrual_from IS NOT NULL
                AND pii.accrual_to IS NOT NULL
                AND pii.accrual_to > ?
                AND pii.accrual_from <= ?
              ORDER BY pi.id, pii.order_index, pii.id'
        );
        $stmt->execute([$supplierId, $originEndsOn, $targetEnd]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * EP-15: kolik odloženého nákladu (381) se rozpustí do 5xx v cílovém období
     * [targetStart..targetEnd] při otevření knih z období končícího `originEndsOn`. Pro každou
     * položku = kumulativně uznaný náklad k targetEnd − k dni před targetStart (= originEndsOn),
     * agregováno na nákladový účet. Telescopuje: součet tranší přes N+1, N+2, … = odložená
     * částka, takže 381 dojde na nulu bez zbytku. Jen tranše cílového období — víceletý zbytek
     * zůstává na 381 a rozpustí ho openNext dalšího roku.
     *
     * @return array{by_account: array<string,float>, total: float}
     */
    private function prepaidExpenseReleaseForPeriod(int $supplierId, string $originEndsOn, string $targetStart, string $targetEnd): array
    {
        $byAccount = [];
        $total = 0.0;
        foreach ($this->prepaidExpenseReleaseRows($supplierId, $originEndsOn, $targetEnd) as $row) {
            $from = substr((string) $row['accrual_from'], 0, 10);
            $to = substr((string) $row['accrual_to'], 0, 10);
            $rate = $this->prepaidExpenseRate($row);
            $netCzk = round((float) $row['total_without_vat'] * $rate, 2);
            // Tranše cílového období = kumul. uznáno k min(to, targetEnd) − kumul. uznáno
            // k originEndsOn (= den před targetStart). Dolní hranici kotvíme na originEndsOn,
            // aby první rozpouštěné období navazovalo přesně na odloženou částku.
            $upper = self::prepaidCumRecognized($netCzk, $from, $to, $targetEnd < $to ? $targetEnd : $to);
            $lower = self::prepaidCumRecognized($netCzk, $from, $to, $originEndsOn);
            $release = round($upper - $lower, 2);
            if ((int) round($release * 100) === 0) {
                continue;
            }
            $account = $this->prepaidExpenseCreditAccount(
                $supplierId,
                $row['expense_account_code'] !== null ? (string) $row['expense_account_code'] : null,
                $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null,
            );
            $byAccount[$account] = round(($byAccount[$account] ?? 0.0) + $release, 2);
            $total = round($total + $release, 2);
        }
        return ['by_account' => $byAccount, 'total' => $total];
    }

    /** Kurz dokladu na CZK (§4/12 ZoÚ): CZK → 1.0, cizí měna → exchange_rate (>0 nutný). */
    private function prepaidExpenseRate(array $row): float
    {
        if ((string) ($row['currency_code'] ?? 'CZK') === 'CZK') {
            return 1.0;
        }
        $rate = $row['exchange_rate'] ?? null;
        if ($rate === null || (float) $rate <= 0) {
            throw new ClosingException(
                'missing_exchange_rate',
                'Přijatá faktura #' . (int) $row['purchase_invoice_id'] . ' v cizí měně (' . (string) $row['currency_code']
                    . ') nemá kurz — bez něj nelze časové rozlišení ocenit v Kč (§4/12 ZoÚ).',
            );
        }
        return (float) $rate;
    }

    /**
     * Nákladový účet (5xx) pro odloženou stranu řádku: expense_account_code (adresný účet) přebíjí
     * odvození z expense_kind (přes posting_rules, tenant si ho může přesměrovat); bez obojího
     * default 518 (dosavadní chování PostingService pro neklasifikovaný náklad).
     */
    private function prepaidExpenseCreditAccount(int $supplierId, ?string $override, ?string $kind): string
    {
        $override = $override !== null ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }
        $ek = ExpenseKind::tryFromNullable($kind);
        if ($ek !== null) {
            $rule = $this->rules->resolve($supplierId, $ek->ruleKey());
            return (string) ($rule['debit_account_code'] ?? $ek->fallbackAccount());
        }
        return '518';
    }

    /**
     * Odložená částka + odložený podíl (pro_rata) pro jednu kartu. Formule pro_rata dle
     * zadání §DM při rovnoměrném užitku: náklad se vztahuje k intervalu délky `periodDays`
     * počínajícímu dnem pořízení; ODLOŽÍ se jen BUDOUCÍ (zbývající) část intervalu ležící ZA
     * rozvahovým dnem — karta pořízená na začátku období odloží ≈ 0, karta z konce roku téměř
     * celou cenu. Uplynulá (spotřebovaná) část zůstává nákladem období na 501.
     *
     * @return array{0:float,1:float}
     */
    private function smallAssetDefer(string $mode, float $price, float $pct, string $inUseDate, ?int $usefulMonths, string $startsOn, string $endsOn, int $periodDays): array
    {
        if ($mode === 'none' || $price <= 0.0) {
            return [0.0, 0.0];
        }
        if ($mode === 'flat_pct') {
            return [round($price * $pct / 100.0, 2), 0.0];
        }
        // pro_rata — kotva = datum uvedení do užívání (ne pořízení).
        $inUse = substr($inUseDate, 0, 10);
        if ($usefulMonths !== null && $usefulMonths > 0) {
            // EP-15: doložená doba použitelnosti — odloží se BUDOUCÍ část intervalu
            // [inUse .. inUse+usefulMonths) za rozvahovým dnem, poměrem dnů k celé době.
            $intervalEnd = (new \DateTimeImmutable($inUse))->modify('+' . $usefulMonths . ' months')->modify('-1 day')->format('Y-m-d');
            $durationDays = self::daysInclusive($inUse, $intervalEnd);
            if ($durationDays <= 0 || $intervalEnd <= $endsOn) {
                return [0.0, 0.0];
            }
            $deferStart = (new \DateTimeImmutable($endsOn))->modify('+1 day')->format('Y-m-d');
            if ($deferStart < $inUse) {
                $deferStart = $inUse;
            }
            $deferDays = self::daysInclusive($deferStart, $intervalEnd);
            $fraction = min(1.0, max(0.0, $deferDays / $durationDays));
            return [round($price * $fraction, 2), $fraction];
        }
        // Bez doložené doby: proxy délkou období (dosavadní chování), kotva = datum užívání.
        $from = $inUse < $startsOn ? $startsOn : $inUse;
        if ($from > $endsOn || $periodDays <= 0) {
            return [0.0, 0.0];
        }
        // Uplynulá (spotřebovaná) část intervalu do rozvahového dne včetně; odloží se ZBYTEK za ním.
        $usedDays = min(self::daysInclusive($from, $endsOn), $periodDays);
        $fraction = ($periodDays - $usedDays) / $periodDays;
        return [round($price * $fraction, 2), $fraction];
    }

    /**
     * EP-15: kumulativně uznaný náklad z rovnoměrného rozlišení [from..to] DO dne $through
     * včetně (na haléře). $through < from → 0; $through >= to → celý $netCzk. Kotva pro
     * konzistentní defer (net − cumRecognized(konec N)) i víceleté rozpouštění: rozdíl dvou
     * kumulativních hodnot na hranicích období telescopuje přesně na odloženou částku, takže
     * 381 se rozpustí do haléře bez zbytku napříč N+1, N+2, …
     */
    private static function prepaidCumRecognized(float $netCzk, string $from, string $to, string $through): float
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        $through = substr($through, 0, 10);
        if ($through < $from) {
            return 0.0;
        }
        $totalDays = self::daysInclusive($from, $to);
        if ($totalDays <= 0) {
            return 0.0;
        }
        $end = $through >= $to ? $to : $through;
        $days = self::daysInclusive($from, $end);
        return round($netCzk * min(1.0, $days / $totalDays), 2);
    }

    /** Počet dnů intervalu VČETNĚ obou krajů (rozvahový den se počítá). */
    private static function daysInclusive(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10));
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return (int) $a->diff($b)->days + 1;
    }

    /**
     * Heuristický návrh dohadných položek pasivních (389) k rozvahovému dni — READ-ONLY
     * náhled (GET), NIKDY neúčtuje. Cílí na jediný spolehlivý vzor, ať návrh není šum:
     * OPAKUJÍCÍ SE MĚSÍČNÍ NÁKLAD, jehož faktura za POSLEDNÍ měsíc období k rozvahovému dni
     * ještě nedorazila (typicky energie, nájem, telco, cloud, hosting).
     *
     * Vzor „recurring" per dodavatel (přijaté faktury document_kind='invoice',
     * status ∉ draft/cancelled, dle effective_cost_date v období):
     *   • faktury alespoň v {@see ESTIMATE_MIN_RECURRING_MONTHS} různých měsících roku,
     *   • faktura v měsíci BEZPROSTŘEDNĚ PŘED posledním měsícem existuje (kadence stále běží),
     *   • v POSLEDNÍM měsíci období faktura CHYBÍ.
     * Návrh částky = průměr netto (bez DPH, přepočet na base ccy) posledních
     * {@see ESTIMATE_SAMPLE_SIZE} faktur; DPH se do dohadu nedává (nárok na odpočet vzniká
     * až daňovým dokladem). Protiúčet nákladu = nejčastější 5xx MD účet z dosud zaúčtovaných
     * faktur dodavatele (jen nápověda; NULL = nedohledáno — účetní doplní ručně).
     *
     * Účetní každý návrh per dodavatel POTVRDÍ/UPRAVÍ/ZAMÍTNE a zaúčtuje běžnou cestou
     * {@see createAssistedEntry} (estimate.liability → MD 5xx / D 389); rozpuštění v N+1 při
     * doručení faktury je pak na obratu vzoru. Systém nikdy neúčtuje automaticky.
     *
     * @return array<string,mixed>
     */
    public function estimatesSuggest(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];
        $targetMonth = substr($endsOn, 0, 7);
        $prevMonth = (new \DateTimeImmutable($targetMonth . '-01'))->modify('-1 month')->format('Y-m');

        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.vendor_id,
                    COALESCE(NULLIF(c.company_name, ''), JSON_UNQUOTE(JSON_EXTRACT(pi.vendor_snapshot, '$.name'))) AS vendor_name,
                    pi.effective_cost_date,
                    -- CZK pojistka jako ve zbytku kódu: bez ní by se korunový doklad
                    -- se zbloudilým `exchange_rate` vynásobil kurzem.
                    ROUND(pi.total_without_vat * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1), 2) AS net_base
               FROM purchase_invoices pi
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
               LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN ('draft','cancelled')
                AND pi.document_kind = 'invoice'
                AND pi.effective_cost_date BETWEEN ? AND ?
              ORDER BY pi.vendor_id, pi.effective_cost_date, pi.id"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);

        /** @var array<int,array{name:string, months:array<string,bool>, invoices:list<array{date:string, net:float}>}> $byVendor */
        $byVendor = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vid = (int) $r['vendor_id'];
            $date = (string) $r['effective_cost_date'];
            $byVendor[$vid] ??= ['name' => (string) ($r['vendor_name'] ?? ''), 'months' => [], 'invoices' => []];
            $byVendor[$vid]['months'][substr($date, 0, 7)] = true;
            $byVendor[$vid]['invoices'][] = ['date' => $date, 'net' => round((float) $r['net_base'], 2)];
        }

        $items = [];
        $total = 0.0;
        foreach ($byVendor as $vid => $v) {
            $monthCount = count($v['months']);
            if ($monthCount < self::ESTIMATE_MIN_RECURRING_MONTHS) {
                continue; // příliš nepravidelný na spolehlivý dohad
            }
            if (isset($v['months'][$targetMonth])) {
                continue; // faktura za poslední měsíc už dorazila → žádný dohad
            }
            if (!isset($v['months'][$prevMonth])) {
                continue; // kadence už neběží (poslední faktura je starší) → nejde o „chybějící poslední měsíc"
            }

            // Průměr netto z posledních N faktur (invoices vzestupně dle data → slice od konce).
            $recent = array_slice($v['invoices'], -self::ESTIMATE_SAMPLE_SIZE);
            $sum = 0.0;
            foreach ($recent as $inv) {
                $sum += $inv['net'];
            }
            $suggested = round($sum / max(1, count($recent)), 2);
            if ((int) round($suggested * 100) <= 0) {
                continue;
            }

            $last = end($v['invoices']);
            $counter = $this->frequentExpenseAccount($supplierId, $vid, $endsOn);
            $vendorName = $v['name'] !== '' ? $v['name'] : ('#' . $vid);

            $items[] = [
                'vendor_id' => $vid,
                'vendor_name' => $vendorName,
                'rule_key' => 'estimate.liability',
                'months_present' => $monthCount,
                'sample_count' => count($recent),
                'last_invoice_date' => (string) ($last['date'] ?? ''),
                'suggested_amount' => $suggested,
                'counter_account' => $counter,
                'currency_code' => 'CZK',
                'reason' => 'recurring_missing_last_month',
                'description' => 'Dohadná položka pasivní — ' . $vendorName . ' ' . $targetMonth
                    . ' (opakující se náklad, faktura k rozvahovému dni nedorazila)',
            ];
            $total = round($total + $suggested, 2);
        }

        usort($items, static fn (array $a, array $b): int => $b['suggested_amount'] <=> $a['suggested_amount']);

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year']],
            'items' => $items,
            'totals' => ['suggested_amount' => $total, 'count' => count($items)],
            'rules' => [
                'target_month' => $targetMonth,
                'min_recurring_months' => self::ESTIMATE_MIN_RECURRING_MONTHS,
                'sample_size' => self::ESTIMATE_SAMPLE_SIZE,
            ],
        ];
    }

    /**
     * Projekce ROZPUŠTĚNÍ časového rozlišení 381 z PŘEDCHOZÍHO období do tohoto (−VH pro
     * náhled DPPO). Předchozí období uzavřené s časovým rozlišením drobného majetku / nákladů
     * příštích období (MD 381 / D 5xx) se v tomto období rozpouští zpět do nákladu (MD 5xx / D 381,
     * viz {@see openNext}) — dokud open_next neproběhl, není rozpuštění zaúčtované a náhled by tento
     * náklad podhodnotil. Vrací JEN dosud NErozpuštěnou (pending) část; jakmile je release
     * zaúčtovaný, je už ve VH a vrací 0. Read-only.
     *
     * @return array{applicable:bool, prior_period_id:?int, small_asset:float, prepaid:float, total:float}
     */
    public function priorDeferralReleaseProjection(int $supplierId, int $periodId): array
    {
        $empty = ['applicable' => false, 'prior_period_id' => null, 'small_asset' => 0.0, 'prepaid' => 0.0, 'total' => 0.0];
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            return $empty;
        }
        // Bezprostředně předcházející období (končí před začátkem tohoto).
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM accounting_periods
              WHERE supplier_id = ? AND ends_on < ?
              ORDER BY ends_on DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, (string) $period['starts_on']]);
        $priorId = $stmt->fetchColumn();
        if ($priorId === false) {
            return $empty;
        }
        $priorId = (int) $priorId;

        $small = $this->pendingDeferralRelease(
            $supplierId,
            'small_asset_accrual',
            ClosingSourceId::smallAssetAccrual($priorId),
            ClosingSourceId::smallAssetAccrualRelease($priorId),
        );
        $prepaid = $this->pendingDeferralRelease(
            $supplierId,
            'prepaid_expense_accrual',
            ClosingSourceId::prepaidExpenseAccrual($priorId),
            ClosingSourceId::prepaidExpenseAccrualRelease($priorId),
        );

        $total = round($small + $prepaid, 2);
        return [
            'applicable' => (int) round($total * 100) !== 0,
            'prior_period_id' => $priorId,
            'small_asset' => $small,
            'prepaid' => $prepaid,
            'total' => $total,
        ];
    }

    /**
     * Pending částka rozpuštění 381: defer zápis minulého období je zaúčtovaný, ale jeho
     * rozpuštění (release) do tohoto období ještě NE → vrátí odloženou částku (debet 381),
     * jinak 0. Read-only.
     */
    private function pendingDeferralRelease(int $supplierId, string $sourceType, int $deferSourceId, int $releaseSourceId): float
    {
        $defer = $this->findEntryWithLines($supplierId, $sourceType, $deferSourceId);
        if ($defer === null || $defer['posted_at'] === null || ($defer['lines'] ?? []) === []) {
            return 0.0;
        }
        $release = $this->journal->findBySource($supplierId, $sourceType, $releaseSourceId);
        if ($release !== null && $release['posted_at'] !== null) {
            return 0.0; // rozpuštění už zaúčtováno → náklad je ve VH
        }
        // Odložená částka = debetní strana 381 defer zápisu (MD 381 / D 5xx).
        $amount = 0.0;
        foreach ($defer['lines'] as $l) {
            if ((string) $l['side'] === 'debit' && str_starts_with((string) $l['account_code'], '381')) {
                $amount += (float) $l['amount'];
            }
        }
        return round($amount, 2);
    }

    /**
     * Nejčastější nákladový 5xx MD účet z dosud zaúčtovaných přijatých faktur dodavatele
     * (nápověda protiúčtu k dohadu; NULL = nedohledáno). Read-only, k rozvahovému dni.
     */
    private function frequentExpenseAccount(int $supplierId, int $vendorId, string $asOf): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ca.account_code
               FROM journal_entry_lines l
               JOIN journal_entries e     ON e.id = l.entry_id
                    AND e.source_type = 'purchase_invoice' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
               JOIN purchase_invoices pi  ON pi.id = e.source_id AND pi.supplier_id = l.supplier_id
               JOIN chart_of_accounts ca  ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND pi.vendor_id = ?
                AND l.side = 'debit' AND ca.account_code LIKE '5%'
                AND e.entry_date <= ?
              GROUP BY ca.account_code
              ORDER BY COUNT(*) DESC, SUM(l.amount) DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vendorId, $asOf]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : (string) $code;
    }

    // ── krok „opravné položky" (D9, §8a/§8c ZoR, §25/3 ZoÚ) ───────────────────

    /**
     * Náhled OP k pohledávkám k rozvahovému dni (GET). Reuse D6 saldokonta (aging
     * účtu 311) — per pohledávka dny/měsíce po splatnosti, návrh zákonné OP dle §8a/§8c
     * a případná už zaúčtovaná OP z payloadu kroku. Systém NIKDY needituje automaticky:
     * návrh se jen zobrazí, účetní ho per pohledávka potvrdí/upraví/zamítne v {@see runProvisions}.
     *
     * @return array<string,mixed>
     */
    public function provisionsPreview(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $endsOn = (string) $period['ends_on'];
        $c = $this->taxConstants->forYear((int) $period['fiscal_year']);
        $months8a50 = (int) ($c['bad_debt_provision_8a_50pct_months'] ?? self::PROVISION_8A_50_MONTHS);
        $months8a100 = (int) ($c['bad_debt_provision_8a_100pct_months'] ?? self::PROVISION_8A_100_MONTHS);
        $months8c = (int) ($c['bad_debt_provision_8c_months'] ?? self::PROVISION_8C_MONTHS);
        $limit8c = (float) ($c['bad_debt_provision_8c_limit'] ?? self::PROVISION_8C_LIMIT);
        $limitationMonths = (int) ($c['receivable_limitation_warning_months'] ?? self::PROVISION_LIMITATION_MONTHS);

        $existingByInvoice = [];
        foreach (($this->stepsMap($supplierId, $periodId)['provisions']['payload']['entries'] ?? []) as $e) {
            $existingByInvoice[(int) ($e['invoice_id'] ?? 0)] = $e;
        }

        $receivables = $this->openReceivables($supplierId, $periodId, $endsOn);

        // §8c limit 30 000 Kč se posuzuje za AGREGÁT pohledávek za týmž dlužníkem, ne per
        // doklad — sečti zbývající hodnoty na partnera dřív, než navrhneš pásmo OP.
        $remainingByPartner = [];
        foreach ($receivables as $r) {
            $pid = $r['partner_id'] !== null ? (int) $r['partner_id'] : 0;
            $remainingByPartner[$pid] = ($remainingByPartner[$pid] ?? 0.0) + $r['remaining'];
        }

        $items = [];
        $totals = ['remaining' => 0.0, 'suggested_legal' => 0.0, 'existing_legal' => 0.0, 'existing_acct' => 0.0];
        foreach ($receivables as $r) {
            $dueDate = $r['due_date'];
            $months = $dueDate !== '' ? self::monthsOverdue($dueDate, $endsOn) : 0;
            $potentiallyTimeBarred = $months >= $limitationMonths;
            $pid = $r['partner_id'] !== null ? (int) $r['partner_id'] : 0;
            $debtorTotal = round($remainingByPartner[$pid] ?? $r['remaining'], 2);
            [$section, $pct] = $potentiallyTimeBarred
                ? [null, 0.0]
                : self::suggestLegalProvision(
                    $r['remaining'], $debtorTotal, $months, $months8a50, $months8a100, $months8c, $limit8c,
                );
            $suggestedLegal = round($r['remaining'] * $pct, 2);
            $invoiceId = $r['invoice_id'];
            $existing = $existingByInvoice[$invoiceId] ?? null;

            $totals['remaining'] += $r['remaining'];
            $totals['suggested_legal'] += $suggestedLegal;
            if ($existing !== null) {
                $totals['existing_legal'] += (float) ($existing['legal_amount'] ?? 0);
                $totals['existing_acct'] += (float) ($existing['acct_amount'] ?? 0);
            }

            $items[] = [
                'invoice_id' => $invoiceId,
                'document_no' => $r['document_no'],
                'partner_id' => $r['partner_id'],
                'partner_name' => $r['partner_name'],
                'issue_date' => $r['issue_date'],
                'due_date' => $dueDate,
                'days_overdue' => $r['days_overdue'],
                'months_overdue' => $months,
                'remaining' => $r['remaining'],
                'debtor_total_remaining' => $debtorTotal,
                'currency_code' => $r['currency_code'],
                'legal_section' => $section,
                'suggested_legal_pct' => $pct,
                'suggested_legal_amount' => $suggestedLegal,
                'suggested_acct_amount' => 0.0,
                // Návrh míří na 558 (daňově účinná OP §8a/§8c ZoR) jen když jsou splněné
                // VŠECHNY zákonné podmínky (checklist) — jinak částku zaúčtuj jako 559
                // (účetní, daňově neúčinná OP). Bez potvrzeného checklistu je 558 nepodložené.
                'legal_deductible_requires_checklist' => $section !== null,
                'suggested_account_default' => $section !== null ? '559' : null,
                'potentially_time_barred' => $potentiallyTimeBarred,
                'warning' => $potentiallyTimeBarred ? 'receivable_may_be_time_barred' : null,
                'existing' => $existing === null ? null : [
                    'entry_id' => (int) ($existing['entry_id'] ?? 0),
                    'legal_amount' => round((float) ($existing['legal_amount'] ?? 0), 2),
                    'acct_amount' => round((float) ($existing['acct_amount'] ?? 0), 2),
                ],
            ];
        }

        $totals = array_map(static fn ($v) => round((float) $v, 2), $totals);

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year']],
            'items' => $items,
            'totals' => $totals,
            // Návrh OP je POUZE orientační — systém nic neúčtuje automaticky. Zákonnou OP
            // (558) lze uplatnit až po ověření úplného checklistu podmínek §8a/§8c ZoR;
            // do té doby patří částka na 559 (účetní, daňově neúčinná OP).
            'advisory' => true,
            'disclaimer' => 'Návrh zákonné opravné položky je pouze orientační. Daňově účinnou OP '
                . '(558) lze uplatnit jen při splnění všech zákonných podmínek §8a/§8c ZoR; jinak '
                . 'zaúčtuj částku jako účetní (daňově neúčinnou) OP na 559. Účetní každou položku '
                . 'potvrzuje/upravuje ručně.',
            'rules' => [
                'legal_8a_50_months' => $months8a50,
                'legal_8a_100_months' => $months8a100,
                'legal_8c_months' => $months8c,
                'legal_8c_limit' => $limit8c,
                'legal_8c_limit_aggregated_per_debtor' => true,
                'months_threshold_strict' => true,
                'limitation_warning_months' => $limitationMonths,
            ],
        ];
    }

    /**
     * Deklarativní zaúčtování OP k pohledávkám (POST steps/provisions/run). `$items`
     * je úplný účetní-potvrzený seznam OP; per pohledávka jeden idempotentní zápis
     * source ('provision', invoice_id) — MD 558 (zákonná/daňová) a/nebo MD 559 (účetní,
     * neuznatelná) / D 391 (R7 flag, entry_date = ends_on). Re-run = in-place rewrite;
     * pohledávka s nulovou (nebo chybějící) OP maže případný stale zápis (vzor FX/zásoby).
     * Vazba source_id=invoice_id umožňuje pozdější rozpuštění OP (391/558|559) při úhradě.
     *
     * Server-side strop (audit 2026-07 #1): per pohledávka se vždy znovu dotáhne AKTUÁLNÍ
     * zbývající hodnota (D6 saldokonto, ne klientem poslaná hodnota) a Σ(legal+acct) nesmí
     * přesáhnout `remaining` — jinak 422 `provision_exceeds_receivable`. Zároveň to funguje
     * jako whitelist: invoice_id musí být otevřená pohledávka TOHOTO supplieru na účtu 311,
     * jinak 422 `invoice_not_open_receivable` (klient nemůže „propašovat" cizí/uzavřenou fakturu).
     *
     * @param list<array{invoice_id:int, legal_amount?:float|int|string, acct_amount?:float|int|string, note?:?string, document_no?:?string}> $items
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runProvisions(int $supplierId, int $periodId, array $items, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $items, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            $remainingByInvoice = [];
            foreach ($this->openReceivables($supplierId, $periodId, $endsOn) as $r) {
                $remainingByInvoice[$r['invoice_id']] = $r['remaining'];
            }

            $legalRule = $this->rules->resolve($supplierId, 'allowance.receivable.legal.create');
            $acctRule = $this->rules->resolve($supplierId, 'allowance.receivable.acct.create');
            if ($legalRule === null || $acctRule === null) {
                throw new ClosingException('posting_rule_missing', 'Kontace opravných položek (558/559 → 391) nejsou naseedované.');
            }
            $acc558 = (string) ($legalRule['debit_account_code'] ?? '558');
            $acc559 = (string) ($acctRule['debit_account_code'] ?? '559');
            $acc391 = (string) ($legalRule['credit_account_code'] ?? '391');

            $prevByInvoice = [];
            foreach (($this->stepsMap($supplierId, $periodId)['provisions']['payload']['entries'] ?? []) as $e) {
                $prevByInvoice[(int) ($e['invoice_id'] ?? 0)] = $e;
            }

            $entries = [];
            $removed = [];
            $seen = [];
            foreach ($items as $raw) {
                $invoiceId = (int) ($raw['invoice_id'] ?? 0);
                if ($invoiceId <= 0) {
                    throw new ClosingException('validation_failed', 'Každá položka OP musí mít invoice_id.');
                }
                if (isset($seen[$invoiceId])) {
                    throw new ClosingException('validation_failed', 'Pohledávka #' . $invoiceId . ' je v seznamu vícekrát.');
                }
                $seen[$invoiceId] = true;
                $legal = round(max(0.0, (float) ($raw['legal_amount'] ?? 0)), 2);
                $acct = round(max(0.0, (float) ($raw['acct_amount'] ?? 0)), 2);
                $total = round($legal + $acct, 2);

                if ((int) round($total * 100) === 0) {
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'provision', $invoiceId);
                    if ($dump !== null) {
                        $removed[] = $invoiceId;
                    }
                    continue;
                }

                if (!array_key_exists($invoiceId, $remainingByInvoice)) {
                    throw new ClosingException(
                        'invoice_not_open_receivable',
                        'Pohledávka #' . $invoiceId . ' není otevřená pohledávka na účtu 311 této firmy.',
                        422,
                    );
                }
                $remaining = $remainingByInvoice[$invoiceId];
                if ((int) round($total * 100) > (int) round($remaining * 100)) {
                    throw new ClosingException(
                        'provision_exceeds_receivable',
                        'Součet opravných položek (' . number_format($total, 2, '.', '') . ' Kč) přesahuje aktuální '
                            . 'zbývající hodnotu pohledávky #' . $invoiceId . ' (' . number_format($remaining, 2, '.', '') . ' Kč) — '
                            . 'dlužník mezitím pravděpodobně uhradil část/vše, obnov náhled.',
                        422,
                    );
                }

                $lines = [];
                if ((int) round($legal * 100) > 0) {
                    $lines[] = ['account_code' => $acc558, 'side' => 'debit', 'amount' => $legal];
                }
                if ((int) round($acct * 100) > 0) {
                    $lines[] = ['account_code' => $acc559, 'side' => 'debit', 'amount' => $acct];
                }
                $lines[] = ['account_code' => $acc391, 'side' => 'credit', 'amount' => $total];
                $this->assertKnownCodes($supplierId, $lines);

                $existing = $this->journal->findBySource($supplierId, 'provision', $invoiceId);
                $docNo = $existing !== null && $existing['document_no'] !== null
                    ? (string) $existing['document_no']
                    : $this->series->next($supplierId, 'manual', $fiscalYear);

                $note = trim((string) ($raw['note'] ?? ''));
                $receivableNo = trim((string) ($raw['document_no'] ?? ''));
                $entryId = $this->posting->postDocument($supplierId, 'provision', $invoiceId, $lines, [
                    'entry_date' => $endsOn,
                    'document_no' => $docNo,
                    'description' => 'Opravná položka k pohledávce ' . ($receivableNo !== '' ? $receivableNo : '#' . $invoiceId),
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                    'allow_closing_period' => true,
                ]);

                $entries[] = [
                    'invoice_id' => $invoiceId,
                    'entry_id' => $entryId,
                    'document_no' => $docNo,
                    'receivable_no' => $receivableNo,
                    'legal_amount' => $legal,
                    'acct_amount' => $acct,
                    'total' => $total,
                    'note' => $note !== '' ? $note : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }

            // Pohledávky z předchozího běhu, které v novém návrhu chybí → smaž jejich OP.
            foreach (array_keys($prevByInvoice) as $invoiceId) {
                if (isset($seen[$invoiceId])) {
                    continue;
                }
                $dump = $this->closing->deleteClosingEntry($supplierId, 'provision', (int) $invoiceId);
                if ($dump !== null) {
                    $removed[] = (int) $invoiceId;
                }
            }

            $payload = ['entries' => $entries, 'ran_at' => date('Y-m-d H:i:s')];
            $this->closing->upsertStep($supplierId, $periodId, 'provisions', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, ['step' => 'provisions', 'status' => 'done'], $meta);
            return ['entries' => $entries, 'removed' => $removed, 'count' => count($entries)];
        });
    }

    /**
     * Otevřené pohledávky účtu 311 k datu (D9, D6 reuse) — plochý seznam napříč partnery.
     * Tenant-scoped ({@see SaldoService::build} filtruje na $supplierId), proto slouží i
     * jako whitelist v {@see runProvisions}: invoice_id, který tu není, buď firmě nepatří,
     * nebo už není otevřenou pohledávkou na 311 (audit 2026-07 #1).
     *
     * @return list<array{invoice_id:int, document_no:string, partner_id:?int,
     *     partner_name:string, issue_date:?string, due_date:string, days_overdue:int,
     *     remaining:float, currency_code:?string}>
     */
    private function openReceivables(int $supplierId, int $periodId, string $asOf): array
    {
        $saldo = $this->saldo->build($supplierId, $periodId, $asOf, '311', null);
        $out = [];
        foreach (($saldo['accounts'] ?? []) as $account) {
            foreach (($account['partners'] ?? []) as $partner) {
                foreach (($partner['items'] ?? []) as $it) {
                    if ((string) ($it['doc_type'] ?? '') !== 'invoice') {
                        continue; // OP tvoříme jen k pohledávkám z vydaných faktur (311)
                    }
                    $remaining = round((float) ($it['remaining_czk'] ?? 0), 2);
                    if ($remaining <= 0) {
                        continue;
                    }
                    $out[] = [
                        'invoice_id' => (int) ($it['doc_id'] ?? 0),
                        'document_no' => (string) ($it['doc_no'] ?? ''),
                        'partner_id' => $partner['partner_id'] ?? null,
                        'partner_name' => (string) ($partner['partner_name'] ?? ''),
                        'issue_date' => $it['issue_date'] ?? null,
                        'due_date' => (string) ($it['due_date'] ?? ''),
                        'days_overdue' => (int) ($it['days_overdue'] ?? 0),
                        'remaining' => $remaining,
                        'currency_code' => $it['currency_code'] ?? null,
                    ];
                }
            }
        }
        return $out;
    }

    /** Počet celých měsíců mezi splatností a rozvahovým dnem (§8a/§8c pásma). */
    private static function monthsOverdue(string $dueDate, string $asOf): int
    {
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $as = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
        if ($due === false || $as === false || $as <= $due) {
            return 0;
        }
        $diff = $due->diff($as);
        return $diff->y * 12 + $diff->m;
    }

    /**
     * Orientační návrh zákonné OP (NE závazný výpočet — účetní vždy potvrzuje/upravuje
     * per pohledávka): §8c (drobná pohledávka do limitu, „více než 12 měsíců" → 100 %) má
     * přednost, jinak §8a („více než 30 měsíců" → 100 %, „více než 18 měsíců" → 50 %).
     *
     * Hranice měsíců jsou striktní `>` (zákonné „více než X měsíců"), ne `>=` — hraniční
     * měsíc tak nespadne do vyššího pásma. §8c limit 30 000 Kč se posuzuje za AGREGÁT
     * všech neuhrazených pohledávek vůči témuž dlužníkovi (`$debtorTotal`), ne per doklad
     * (§8c ZoR) — dlužník s víc drobnými fakturami dohromady nad limitem už §8c nedostane.
     *
     * @param float $remaining   zbývající hodnota TÉTO pohledávky (základ % OP)
     * @param float $debtorTotal Σ zbývajících pohledávek za týmž dlužníkem (limit §8c)
     * @return array{0: ?string, 1: float}
     */
    private static function suggestLegalProvision(
        float $remaining,
        float $debtorTotal,
        int $months,
        int $months8a50,
        int $months8a100,
        int $months8c,
        float $limit8c,
    ): array {
        if ($debtorTotal <= $limit8c && $months > $months8c) {
            return ['8c', 1.0];
        }
        if ($months > $months8a100) {
            return ['8a', 1.0];
        }
        if ($months > $months8a50) {
            return ['8a', 0.5];
        }
        return [null, 0.0];
    }

    // ── krok „daň z příjmů" (D11, 591/341) ────────────────────────────────────

    /**
     * Náhled podkladu splatné daně (GET). Přednabídne částku z finalizovaného DPPO
     * přiznání téhož roku (řádné/opravné); pokud takové přiznání ještě neexistuje
     * (běžný stav během uzávěrky), dopočítá ji z AKTUÁLNÍHO účetnictví ({@see
     * computeIncomeTaxFromLedger}). `suggested_source` rozlišuje zdroj částky —
     * dopočtenou si účetní musí před zaúčtováním ověřit. Nezaúčtovává nic — účetní
     * částku potvrdí/upraví v {@see runIncomeTax}.
     *
     * @return array<string,mixed>
     */
    public function incomeTaxPreview(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $endsOn = (string) $period['ends_on'];
        $fiscalYear = (int) $period['fiscal_year'];
        $constants = $this->taxConstants->forYear($fiscalYear);

        $taxpayerType = $this->supplierTaxpayerType($supplierId);
        $applicable = $taxpayerType !== 'fo';
        $fromReturn = $applicable ? $this->taxReturns->findLastKnownTax($supplierId, $fiscalYear, 'po') : null;
        $computed = $fromReturn === null && $applicable
            ? $this->computeIncomeTaxFromLedger($supplierId, $fiscalYear)
            : null;
        $suggested = $fromReturn ?? $computed;
        $existing = $this->journal->findBySource($supplierId, 'income_tax', $periodId);
        $step = $this->stepsMap($supplierId, $periodId)['income_tax'];

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => $fiscalYear],
            'suggested_amount' => $suggested,
            'suggested_source' => $fromReturn !== null ? 'finalized_return' : ($computed !== null ? 'computed_from_ledger' : null),
            'has_finalized_return' => $fromReturn !== null,
            'taxpayer_type' => $taxpayerType,
            'applicable' => $applicable,
            'rate_hint' => (float) ($constants['corporate_tax_rate'] ?? self::CORPORATE_TAX_RATE),
            'balance_341' => round($this->closing->accountBalance($supplierId, '341', $endsOn), 2),
            'balance_591' => round($this->closing->accountBalance($supplierId, '591', $endsOn), 2),
            'existing_entry_id' => $existing !== null ? (int) $existing['id'] : null,
            'existing_amount' => $step['payload']['amount'] ?? null,
        ];
    }

    /**
     * Dopočet splatné daně (ř.340 DPPO) z AKTUÁLNÍHO účetnictví, když ještě neexistuje
     * finalizované přiznání za rok. Sestavuje podklady stejným VH/nedaňové náklady/odpisy/
     * vyřazení jako náhled DPPO ({@see DppoReturnDataProvider::gather}), bez ručních vstupů
     * poplatníka (dary, ztráta, slevy §35 — ty zná jen rozpracované přiznání, ne uzávěrka) —
     * proto je výsledek jen orientační odhad, ne závazný základ. DppoReturnDataProvider se
     * VŽDY konstruuje s $closing = null: Feature 1 (projekce nezaúčtovaných kroků) by tu byla
     * jednak zbytečná (VH už zahrnuje předchozí posted kroky uzávěrky — FX/dohady/rozlišení
     * mají vlastní source_type, ne 'closing'), jednak by způsobila cyklus (ClosingService by
     * si volal sám sebe). Read-only, nic neúčtuje. Chyba v podkladech (chybějící období apod.)
     * dopočet jen tiše přeskočí — nesmí shodit celý náhled kroku.
     */
    private function computeIncomeTaxFromLedger(int $supplierId, int $fiscalYear): ?float
    {
        try {
            $provider = new DppoReturnDataProvider($this->db, $this->periods, $this->nonDeductibleCosts, null);
            $data = $provider->gather($supplierId, $fiscalYear);
            if ($data['period'] === null) {
                return null;
            }
            $constants = $this->taxConstants->forYear($fiscalYear);
            $result = $this->dppoCalc->compute($data, [], $constants);
            return round((float) ($result['tax'] ?? 0), 2);
        } catch (\Throwable $e) {
            $this->logger->warning('Dopočet daně z příjmů z knih se nezdařil: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * Zaúčtuje předpis splatné daně z příjmů MD 591 / D 341 do posledního dne období
     * (R7 flag, řada UZ). Idempotence přes source ('income_tax', period_id) — re-run
     * = in-place rewrite. Nikdy neúčtuje automaticky: částku vždy potvrdí účetní.
     *
     * Evidence odchylky (EP-16): ruční částka se NEblokuje, ale spolu s ní se do payloadu
     * kroku i do auditu ukládá ZDROJ návrhu (finalizované přiznání / dopočet z knih),
     * NAVRŽENÁ částka, SKUTEČNĚ zadaná částka, jejich ROZDÍL a DŮVOD (`$reason`), aby byla
     * odchylka proti vypočtenému DPPO kdykoli dohledatelná.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, amount:float, document_no:string, suggested_amount:?float, suggested_source:?string, difference:?float, reason:?string}
     */
    public function runIncomeTax(int $supplierId, int $periodId, float $amount, int $rowVersion, array $meta = [], ?string $reason = null): array
    {
        if ($this->supplierTaxpayerType($supplierId) === 'fo') {
            throw new ClosingException(
                'income_tax_not_applicable',
                'Daň z příjmů fyzické osoby se neúčtuje na 591/341 — tento krok přeskoč.',
                422,
            );
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ClosingException('validation_failed', 'Částka splatné daně musí být kladná (nulovou daň krok přeskoč).');
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        return $this->tx(function () use ($supplierId, $periodId, $amount, $rowVersion, $meta, $reason): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            // Zdroj a hodnota návrhu (stejná logika jako incomeTaxPreview): přednost má
            // finalizované DPPO přiznání roku, jinak orientační dopočet z aktuálních knih.
            $fromReturn = $this->taxReturns->findLastKnownTax($supplierId, $fiscalYear, 'po');
            $computed = $fromReturn === null ? $this->computeIncomeTaxFromLedger($supplierId, $fiscalYear) : null;
            $suggested = $fromReturn ?? $computed;
            $suggestedSource = $fromReturn !== null ? 'finalized_return' : ($computed !== null ? 'computed_from_ledger' : null);
            $difference = $suggested !== null ? round($amount - (float) $suggested, 2) : null;

            $rule = $this->rules->resolve($supplierId, 'income_tax.payable');
            $debit = $rule !== null && $rule['debit_account_code'] !== null ? (string) $rule['debit_account_code'] : '591';
            $credit = $rule !== null && $rule['credit_account_code'] !== null ? (string) $rule['credit_account_code'] : '341';
            $lines = [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ];
            $this->assertKnownCodes($supplierId, $lines);

            $existing = $this->journal->findBySource($supplierId, 'income_tax', $periodId);
            $docNo = $existing !== null && $existing['document_no'] !== null
                ? (string) $existing['document_no']
                : $this->series->next($supplierId, 'closing', $fiscalYear);

            $entryId = $this->posting->postDocument($supplierId, 'income_tax', $periodId, $lines, [
                'entry_date' => $endsOn,
                'document_no' => $docNo,
                'description' => 'Předpis splatné daně z příjmů ' . $fiscalYear,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $payload = [
                'entry_id' => $entryId,
                'amount' => $amount,
                'document_no' => $docNo,
                'suggested_amount' => $suggested !== null ? round((float) $suggested, 2) : null,
                'suggested_source' => $suggestedSource,
                'difference' => $difference,
                'reason' => $reason,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'income_tax', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, [
                'step' => 'income_tax',
                'status' => 'done',
                'amount' => $amount,
                'suggested_amount' => $suggested !== null ? round((float) $suggested, 2) : null,
                'suggested_source' => $suggestedSource,
                'difference' => $difference,
                'reason' => $reason,
            ], $meta);
            return [
                'entry_id' => $entryId,
                'amount' => $amount,
                'document_no' => $docNo,
                'suggested_amount' => $suggested !== null ? round((float) $suggested, 2) : null,
                'suggested_source' => $suggestedSource,
                'difference' => $difference,
                'reason' => $reason,
            ];
        });
    }

    // ── krok „odložená daň" (ČÚS 003, § 59 vyhl. 500/2002, 592/481) ───────────

    /**
     * Náhled odložené daně (GET). Vypočte přechodné rozdíly k rozvahovému dni a navrhne
     * ČISTOU hodnotu odložené daně; zápis pak jen dorovná zůstatek 481 na tuto hodnotu.
     *
     * Fyzické osoby vedoucí účetnictví o odložené dani neúčtují (§ 59 vyhl. se týká
     * účetních jednotek, které sestavují závěrku v plném rozsahu a tvoří konsolidační
     * celek nebo mají povinný audit), proto stejný `applicable` guard jako u splatné daně.
     * Nezaúčtovává nic.
     *
     * @param array<string,float> $manual ruční tituly (popis => přechodný rozdíl)
     * @return array<string,mixed>
     */
    public function deferredTaxPreview(int $supplierId, int $periodId, array $manual = []): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $endsOn = (string) $period['ends_on'];
        $fiscalYear = (int) $period['fiscal_year'];

        $taxpayerType = $this->supplierTaxpayerType($supplierId);
        $applicable = $taxpayerType !== 'fo';
        $computed = $applicable
            ? $this->deferredTax->compute($supplierId, $fiscalYear, $endsOn, $manual)
            : null;

        // Zůstatek 481 PŘED zápisem. Odložená daň se nekumuluje po letech — zápis vždy
        // dorovnává účet na aktuálně vypočtenou hodnotu, takže návrh je rozdíl proti němu.
        $balance481 = round($this->closing->accountBalance($supplierId, '481', $endsOn), 2);
        $target = $computed !== null ? (float) $computed['deferred_tax'] : 0.0;

        $existing = $this->journal->findBySource($supplierId, 'deferred_tax', $periodId);
        $step = $this->stepsMap($supplierId, $periodId)['deferred_tax'];

        return [
            'as_of' => $endsOn,
            'period' => ['id' => (int) $period['id'], 'fiscal_year' => $fiscalYear],
            'taxpayer_type' => $taxpayerType,
            'applicable' => $applicable,
            'computation' => $computed,
            'balance_481' => $balance481,
            'balance_592' => round($this->closing->accountBalance($supplierId, '592', $endsOn), 2),
            // Kladný `movement` = dozúčtovat závazek, záporný = snížit ho / zaúčtovat pohledávku.
            'movement' => round($target + $balance481, 2),
            'existing_entry_id' => $existing !== null ? (int) $existing['id'] : null,
            'existing_amount' => $step['payload']['amount'] ?? null,
        ];
    }

    /**
     * Zaúčtuje odloženou daň (592/481 pro závazek, 481/592 pro pohledávku) k poslednímu dni
     * období. Idempotence přes source ('deferred_tax', period_id) — re-run přepíše zápis.
     *
     * Účetní vždy potvrzuje ČÁSTKU, ne jen tlačítko: u odložené daňové POHLEDÁVKY navíc
     * musí podle § 59 odst. 4 vyhlášky posoudit, jestli je pravděpodobné dosažení základu
     * daně, o který ji lze uplatnit. Tuhle pravděpodobnost systém posoudit nedokáže, proto
     * pohledávku bez výslovného potvrzení (`$prudenceConfirmed`) odmítne zaúčtovat —
     * automatické zaúčtování by porušilo zásadu opatrnosti a nadhodnotilo aktiva.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, amount:float, kind:string, document_no:string, computed_amount:?float, difference:?float, reason:?string}
     */
    public function runDeferredTax(
        int $supplierId,
        int $periodId,
        float $amount,
        int $rowVersion,
        array $meta = [],
        ?string $reason = null,
        bool $prudenceConfirmed = false,
        array $manual = [],
    ): array {
        if ($this->supplierTaxpayerType($supplierId) === 'fo') {
            throw new ClosingException(
                'deferred_tax_not_applicable',
                'O odložené dani účtují účetní jednotky podle § 59 vyhlášky — tento krok přeskoč.',
                422,
            );
        }
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            throw new ClosingException('validation_failed', 'Nulovou odloženou daň neúčtuj — krok přeskoč.');
        }
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return $this->tx(function () use ($supplierId, $periodId, $amount, $rowVersion, $meta, $reason, $prudenceConfirmed, $manual): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            $computed = $this->deferredTax->compute($supplierId, $fiscalYear, $endsOn, $manual);
            $computedAmount = (float) $computed['deferred_tax'];
            $difference = round($amount - $computedAmount, 2);

            // Kladná částka = závazek (592/481), záporná = pohledávka (481/592).
            $kind = $amount > 0.0 ? 'liability' : 'asset';
            if ($kind === 'asset' && !$prudenceConfirmed) {
                throw new ClosingException(
                    'prudence_check_required',
                    'Odloženou daňovou pohledávku lze podle § 59 odst. 4 vyhlášky zaúčtovat jen tehdy, '
                        . 'je-li pravděpodobné, že bude dosaženo základu daně, o který ji lze uplatnit. '
                        . 'Posuďte to a potvrďte.',
                    422,
                );
            }

            $rule = $this->rules->resolve($supplierId, 'deferred_tax.' . $kind);
            [$fallbackDebit, $fallbackCredit] = $kind === 'liability' ? ['592', '481'] : ['481', '592'];
            $debit = $rule !== null && $rule['debit_account_code'] !== null ? (string) $rule['debit_account_code'] : $fallbackDebit;
            $credit = $rule !== null && $rule['credit_account_code'] !== null ? (string) $rule['credit_account_code'] : $fallbackCredit;

            $abs = abs($amount);
            $lines = [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $abs],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $abs],
            ];
            $this->assertKnownCodes($supplierId, $lines);

            $existing = $this->journal->findBySource($supplierId, 'deferred_tax', $periodId);
            $docNo = $existing !== null && $existing['document_no'] !== null
                ? (string) $existing['document_no']
                : $this->series->next($supplierId, 'closing', $fiscalYear);

            $entryId = $this->posting->postDocument($supplierId, 'deferred_tax', $periodId, $lines, [
                'entry_date' => $endsOn,
                'document_no' => $docNo,
                'description' => ($kind === 'liability' ? 'Odložený daňový závazek ' : 'Odložená daňová pohledávka ') . $fiscalYear,
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'allow_closing_period' => true,
            ]);

            $payload = [
                'entry_id' => $entryId,
                'amount' => $amount,
                'kind' => $kind,
                'document_no' => $docNo,
                'computed_amount' => $computedAmount,
                'difference' => $difference,
                'rate' => $computed['rate'],
                'rate_year' => $computed['rate_year'],
                'titles' => $computed['titles'],
                'prudence_confirmed' => $kind === 'asset' ? true : null,
                'reason' => $reason,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'deferred_tax', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.closing_step_done', $periodId, [
                'step' => 'deferred_tax',
                'status' => 'done',
                'amount' => $amount,
                'kind' => $kind,
                'computed_amount' => $computedAmount,
                'difference' => $difference,
                'prudence_confirmed' => $kind === 'asset' ? true : null,
                'reason' => $reason,
            ], $meta);

            return [
                'entry_id' => $entryId,
                'amount' => $amount,
                'kind' => $kind,
                'document_no' => $docNo,
                'computed_amount' => $computedAmount,
                'difference' => $difference,
                'reason' => $reason,
            ];
        });
    }

    // ── rozdělení výsledku hospodaření (D10, 431 → 428/429/364…) ───────────────

    /**
     * Náhled rozdělení VH (GET) — schválené období + cílové otevřené období, disponibilní
     * zůstatek 431 a sazba srážkové daně. Zápis se účtuje do OTEVŘENÉHO období (431 se do
     * nového roku převedl otevíracím zápisem), ne do uzavřeného.
     *
     * @return array<string,mixed>
     */
    public function profitDistributionPreview(int $supplierId, int $periodId): array
    {
        $requested = $this->periods->findById($supplierId, $periodId);
        if ($requested === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $context = $this->profitDistributionContext($supplierId, $requested);
        $period = $context['source'];
        $target = $context['target'];
        $sourceId = (int) $period['id'];
        $asOf = (string) $target['ends_on'];
        $balance431 = round($this->closing->accountBalance($supplierId, '431', $asOf), 2);
        $available = round(-$balance431, 2); // kladné = zisk k rozdělení, záporné = ztráta k úhradě
        $existing = $this->journal->findBySource($supplierId, 'profit_distribution', $sourceId);
        $resources = $this->distributableResources($supplierId, $asOf, $available, $sourceId);

        return [
            'approved_period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year'], 'status' => (string) $period['status']],
            'target_period' => [
                'id' => (int) $target['id'],
                'fiscal_year' => (int) $target['fiscal_year'],
                'row_version' => (int) $target['row_version'],
                'starts_on' => (string) $target['starts_on'],
                'ends_on' => (string) $target['ends_on'],
            ],
            'balance_431' => $balance431,
            'available_profit' => $available,
            'retained_profit' => $resources['retained_profit'],
            'uncovered_loss' => $resources['uncovered_loss'],
            'distributable_resources' => $resources['limit'],
            'is_loss' => $available < 0,
            'withholding_rate' => $this->withholdingRateForYear((int) $target['fiscal_year']),
            'existing_entry_id' => $existing !== null ? (int) $existing['id'] : null,
        ];
    }

    /**
     * Zaúčtuje rozdělení VH do OTEVŘENÉHO období (source ('profit_distribution',
     * approved_period_id) — idempotentní rewrite). Zisk: MD 431 / D {428,429,fondy,364};
     * ztráta: MD {429,428,…} / D 431. Každý shares řádek představuje jednoho
     * poplatníka; §36 daň se počítá a zaokrouhluje dolů samostatně (MD účet podílů /
     * D 342). Tvrdá kontrola Σ přídělů = |zůstatek 431| a limitu rozdělitelných zdrojů.
     *
     * Idempotence (audit 2026-07 #3): zůstatek 431 se počítá s VYLOUČENÍM vlastního
     * předchozího zápisu téhož source_id (accountBalance exclude*, vzor FX slotu 2) —
     * jinak by po prvním běhu 431 už nettoval na 0 a Σ přídělů by při re-runu vždy
     * selhala na distribution_mismatch (rewrite větev by byla nedosažitelná). Re-run
     * tak skutečně přepočítá „jako by se distribuce ještě nestala" a postDocument
     * zápis přepíše in-place. `profitDistributionPreview` naopak SKUTEČNÝ (vč. vlastního
     * zápisu) zůstatek — pro zobrazení „431 je po rozdělení vynulovaný".
     *
     * @param array{decision_date:string, target_row_version:int,
     *     allocations: list<array{account_code:string, amount:float|int|string, kind?:string}>,
     *     withholding_rate?:float|int|string} $body
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function runProfitDistribution(int $supplierId, int $periodId, array $body, array $meta = []): array
    {
        $decisionDate = trim((string) ($body['decision_date'] ?? ''));
        if (!self::isIsoDate($decisionDate)) {
            throw new ClosingException('validation_failed', 'Datum rozhodnutí valné hromady musí být YYYY-MM-DD.');
        }
        $allocations = is_array($body['allocations'] ?? null) ? $body['allocations'] : [];
        if ($allocations === []) {
            throw new ClosingException('validation_failed', 'Zadej alespoň jeden příděl rozdělení VH.');
        }
        $withholdingRate = isset($body['withholding_rate']) ? (float) $body['withholding_rate'] : null;
        if ($withholdingRate !== null && ($withholdingRate < 0 || $withholdingRate > 1)) {
            throw new ClosingException('validation_failed', 'Sazba srážkové daně musí být v rozsahu 0–1.');
        }
        $targetRowVersion = (int) ($body['target_row_version'] ?? 0);

        return $this->tx(function () use ($supplierId, $periodId, $decisionDate, $allocations, $withholdingRate, $targetRowVersion, $meta): array {
            $requested = $this->periods->findById($supplierId, $periodId);
            if ($requested === null) {
                throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
            }
            $context = $this->profitDistributionContext($supplierId, $requested);
            $period = $context['source'];
            $target = $context['target'];
            $sourceId = (int) $period['id'];
            $targetId = (int) $target['id'];
            $lockedTarget = $this->lockPeriod($supplierId, $targetId, $targetRowVersion);
            $this->assertStatus($lockedTarget, self::PROFIT_DISTRIBUTION_TARGET_STATUSES);
            $withholdingRate ??= $this->withholdingRateForYear((int) $lockedTarget['fiscal_year']);
            if ($decisionDate < (string) $lockedTarget['starts_on'] || $decisionDate > (string) $lockedTarget['ends_on']) {
                throw new ClosingException(
                    'validation_failed',
                    'Datum rozhodnutí VH musí ležet uvnitř období ' . $lockedTarget['starts_on'] . '–' . $lockedTarget['ends_on'] . '.',
                );
            }

            $asOf = (string) $lockedTarget['ends_on'];
            // Vyloučit vlastní předchozí zápis (audit #3) — bez toho by re-run po prvním
            // zaúčtování viděl 431 už vynulovaný a distribution_mismatch by byla nedosažitelná.
            $balance431 = round($this->closing->accountBalance($supplierId, '431', $asOf, 'profit_distribution', $sourceId), 2);
            $available = round(-$balance431, 2);
            $isLoss = $available < 0;
            $resources = $this->distributableResources($supplierId, $asOf, $available, $sourceId);

            $sum = 0.0;
            $sharesGross = 0.0;
            /** @var list<array{code:string, amt:float}> $sharesLines */
            $sharesLines = [];
            $lines = [];
            foreach ($allocations as $a) {
                $code = trim((string) ($a['account_code'] ?? ''));
                $amt = round((float) ($a['amount'] ?? 0), 2);
                $kind = (string) ($a['kind'] ?? 'retained');
                if ($code === '' || $code === '431') {
                    throw new ClosingException('validation_failed', 'Neplatný cílový účet přídělu (nesmí být 431).');
                }
                if ($amt <= 0) {
                    throw new ClosingException('validation_failed', 'Částka přídělu musí být kladná.');
                }
                $sum = round($sum + $amt, 2);
                if ($isLoss) {
                    $lines[] = ['account_code' => $code, 'side' => 'debit', 'amount' => $amt];
                } else {
                    $lines[] = ['account_code' => $code, 'side' => 'credit', 'amount' => $amt];
                    if ($kind === 'shares') {
                        $sharesGross = round($sharesGross + $amt, 2);
                        $sharesLines[] = ['code' => $code, 'amt' => $amt];
                    }
                }
            }

            if ((int) round($sum * 100) !== (int) round(abs($available) * 100)) {
                throw new ClosingException(
                    'distribution_mismatch',
                    'Součet přídělů (' . number_format($sum, 2, '.', '') . ') se neshoduje s disponibilním zůstatkem účtu 431 ('
                        . number_format(abs($available), 2, '.', '') . ').',
                    422,
                );
            }
            if (!$isLoss && (int) round($sharesGross * 100) > (int) round($resources['limit'] * 100)) {
                throw new ClosingException(
                    'insufficient_distributable_resources',
                    'Podíly na zisku (' . number_format($sharesGross, 2, '.', '')
                        . ' Kč) přesahují rozdělitelné zdroje (' . number_format($resources['limit'], 2, '.', '')
                        . ' Kč) po zohlednění nerozděleného zisku a neuhrazené ztráty.',
                    422,
                );
            }

            $lines[] = ['account_code' => '431', 'side' => $isLoss ? 'credit' : 'debit', 'amount' => $sum];

            // Srážková daň §36 odst. 3 ZDP: každý shares řádek představuje jednoho
            // poplatníka; základ i daň se zaokrouhlí na celé Kč dolů per poplatník.
            $withholding = 0.0;
            if (!$isLoss && $sharesGross > 0 && $withholdingRate > 0) {
                foreach ($sharesLines as $sl) {
                    $tax = (float) floor(floor($sl['amt']) * $withholdingRate);
                    if ($tax > 0) {
                        $withholding += $tax;
                        $lines[] = ['account_code' => $sl['code'], 'side' => 'debit', 'amount' => $tax];
                    }
                }
                $withholding = round($withholding, 2);
                if ($withholding > 0) {
                    $lines[] = ['account_code' => '342', 'side' => 'credit', 'amount' => $withholding];
                }
            }

            $this->assertKnownCodes($supplierId, $lines);

            $existing = $this->journal->findBySource($supplierId, 'profit_distribution', $sourceId);
            $docNo = $existing !== null && $existing['document_no'] !== null
                ? (string) $existing['document_no']
                : $this->series->next($supplierId, 'manual', (int) $lockedTarget['fiscal_year']);

            $entryId = $this->posting->postDocument($supplierId, 'profit_distribution', $sourceId, $lines, [
                'entry_date' => $decisionDate,
                'document_no' => $docNo,
                'description' => 'Rozdělení výsledku hospodaření ' . (int) $period['fiscal_year'] . ' (rozhodnutí VH ' . $decisionDate . ')',
                'posted' => true,
                'posted_by' => $meta['posted_by'] ?? null,
                'user_id' => $meta['user_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                // Cíl smí být i 'closing' (zpětné rozdělení u rozdělané uzávěrky) — R7 flag
                // nastavuje výhradně tato třída a jen když je období opravdu 'closing'.
                'allow_closing_period' => (string) $lockedTarget['status'] === 'closing',
            ]);

            $this->bumpVersion($supplierId, $targetId, $targetRowVersion);

            $this->audit($supplierId, 'accounting.profit_distributed', $periodId, [
                'entry_id' => $entryId,
                'document_no' => $docNo,
                'target_period_id' => $targetId,
                'distributed' => $sum,
                'withholding' => $withholding,
                'decision_date' => $decisionDate,
            ], $meta);

            return [
                'entry_id' => $entryId,
                'document_no' => $docNo,
                'target_period_id' => $targetId,
                'available_profit' => $available,
                'distributed' => $sum,
                'withholding' => $withholding,
                'is_loss' => $isLoss,
                'decision_date' => $decisionDate,
            ];
        });
    }

    /**
     * Revert rozdělení VH — HARD DELETE zápisu s dumpem (vzor deleteClosingEntry),
     * jen dokud je cílové otevřené období open.
     *
     * Guard (audit 2026-07 #2): pokud na některý z přídělových účtů (364/428/429/342…,
     * vše kromě 431) navázal PO datu rozhodnutí VH jiný zaúčtovaný doklad (typicky výplata
     * podílu 364/221), revert se ODMÍTNE (422 distribution_settled) — hard delete by jinak
     * smazal jen kredit 364, debetní úhrada by zůstala a 364/342 by zůstaly nekonzistentní
     * (fantomový závazek/pohledávka bez podkladu). Nejdřív musí uživatel stornovat navazující
     * platbu, teprve pak jde rozdělení vzít zpět.
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{dump: array<string,mixed>, target_period_id:int}
     */
    public function revertProfitDistribution(int $supplierId, int $periodId, int $targetRowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $targetRowVersion, $meta): array {
            $requested = $this->periods->findById($supplierId, $periodId);
            if ($requested === null) {
                throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
            }
            $context = $this->profitDistributionContext($supplierId, $requested);
            $period = $context['source'];
            $target = $context['target'];
            $sourceId = (int) $period['id'];
            $targetId = (int) $target['id'];
            $lockedTarget = $this->lockPeriod($supplierId, $targetId, $targetRowVersion);
            // Symetrie s zaúčtováním: co jde zaúčtovat do 'closing', musí jít i vzít zpět.
            $this->assertStatus($lockedTarget, self::PROFIT_DISTRIBUTION_TARGET_STATUSES);

            $existing = $this->findEntryWithLines($supplierId, 'profit_distribution', $sourceId);
            if ($existing === null) {
                throw new ClosingException('not_found', 'Žádné zaúčtované rozdělení VH k revertu.', 404);
            }
            $watchCodes = [];
            foreach (($existing['lines'] ?? []) as $l) {
                $code = (string) $l['account_code'];
                if ($code !== '431') {
                    $watchCodes[$code] = true;
                }
            }
            if ($watchCodes !== [] && $this->hasFollowUpPostings($supplierId, array_keys($watchCodes), (string) $existing['entry_date'])) {
                throw new ClosingException(
                    'distribution_settled',
                    'Na účty rozdělení VH (např. 364 — podíly společníků) navazují pozdější zaúčtované doklady '
                        . '(výplata podílu, čerpání fondu apod.) — nejprve stornuj navazující platby, teprve pak vezmi rozdělení zpět.',
                    422,
                );
            }

            $dump = $this->closing->deleteClosingEntry($supplierId, 'profit_distribution', $sourceId);
            if ($dump === null) {
                throw new ClosingException('not_found', 'Žádné zaúčtované rozdělení VH k revertu.', 404);
            }
            $this->bumpVersion($supplierId, $targetId, $targetRowVersion);
            $this->audit($supplierId, 'accounting.profit_distribution_reverted', $periodId, [
                'entry_dump' => $dump,
                'target_period_id' => $targetId,
            ], $meta);
            return ['dump' => $dump, 'target_period_id' => $targetId];
        });
    }

    /**
     * Existuje na některý z $accountCodes zaúčtovaný zápis (jiný než $excludeSourceType)
     * s entry_date > $afterDate? Guard pro revert rozdělení VH (audit #2) — prefix-match
     * jako {@see ClosingRepository::accountBalance}, aby chytil i analytiky.
     *
     * @param list<string> $accountCodes
     */
    private function hasFollowUpPostings(int $supplierId, array $accountCodes, string $afterDate): bool
    {
        if ($accountCodes === []) {
            return false;
        }
        $conditions = [];
        $params = [$supplierId];
        foreach ($accountCodes as $code) {
            $conditions[] = '(a.account_code LIKE CONCAT(?, \'%\') OR COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, \'%\'))';
            $params[] = $code;
            $params[] = $code;
        }
        $params[] = $afterDate;
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.source_type <> \'profit_distribution\'
                AND (' . implode(' OR ', $conditions) . ')
                AND e.entry_date > ?
              LIMIT 1'
        );
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zdrojové schválené a cílové otevřené období pro rozdělení VH. Volání nad
     * otevřeným obdobím kanonicky dohledá jeho bezprostředně předchozí approved rok.
     *
     * @param array<string,mixed> $period
     * @return array{source:array<string,mixed>,target:array<string,mixed>}
     */
    /**
     * Dobová sazba srážkové daně §36 z podílu na zisku pro daný rok — z verzovaných
     * konstant, s fallbackem na {@see self::WITHHOLDING_RATE} pro roky bez klíče.
     */
    private function withholdingRateForYear(int $year): float
    {
        $rate = $this->taxConstants->forYear($year)['withholding_rate'] ?? null;
        return $rate !== null ? (float) $rate : self::WITHHOLDING_RATE;
    }

    /**
     * Cílové období rozdělení smí být `open` i `closing`. Kdo uzávěrku zahájil dřív, než
     * rozdělil loňský výsledek, se jinak zasekne: uzavření knih blokuje precheck
     * `vh_431_undistributed`, přerušit uzávěrku po prvních uzávěrkových zápisech nejde
     * a nad obdobím ve stavu `closing` se rozdělení nenabízelo vůbec. `closed`/`approved`
     * cíl zůstává zakázaný — tam už se účtovat nesmí (§35 ZoÚ).
     */
    private const PROFIT_DISTRIBUTION_TARGET_STATUSES = ['open', 'closing'];

    private function profitDistributionContext(int $supplierId, array $period): array
    {
        if (in_array((string) $period['status'], self::PROFIT_DISTRIBUTION_TARGET_STATUSES, true)) {
            $source = $this->previousPeriod($supplierId, (string) $period['starts_on']);
            if ($source === null || (string) $source['status'] !== 'approved') {
                throw new ClosingException(
                    'profit_distribution_requires_approved_period',
                    'Rozdělení výsledku hospodaření vyžaduje bezprostředně předchozí schválené období.',
                    422,
                );
            }
            return ['source' => $source, 'target' => $period];
        }
        if ((string) $period['status'] !== 'approved') {
            throw new ClosingException(
                'invalid_status_transition',
                'Rozdělení výsledku hospodaření je dostupné jen pro schválené (approved), otevřené nebo uzavírané období (stav: ' . $period['status'] . ').',
            );
        }
        $next = $this->periods->nextPeriod($supplierId, (string) $period['ends_on']);
        if ($next === null) {
            throw new ClosingException('next_period_missing', 'Následující období (kam se rozdělení účtuje) neexistuje — nejprve otevři nový rok.');
        }
        if (!in_array((string) $next['status'], self::PROFIT_DISTRIBUTION_TARGET_STATUSES, true)) {
            throw new ClosingException('next_period_not_open', 'Následující období ' . $next['fiscal_year'] . ' není otevřené (stav: ' . $next['status'] . ').');
        }
        return ['source' => $period, 'target' => $next];
    }

    /**
     * Limit rozdělitelných zdrojů: aktuální VH + nerozdělený zisk − neuhrazená
     * ztráta. Vlastní případný zápis rozdělení se vyloučí kvůli idempotentnímu re-runu.
     *
     * @return array{retained_profit:float,uncovered_loss:float,limit:float}
     */
    private function distributableResources(int $supplierId, string $asOf, float $currentProfit, int $sourcePeriodId): array
    {
        $balance428 = round($this->closing->accountBalance($supplierId, '428', $asOf, 'profit_distribution', $sourcePeriodId), 2);
        $balance429 = round($this->closing->accountBalance($supplierId, '429', $asOf, 'profit_distribution', $sourcePeriodId), 2);
        $retainedProfit = max(0.0, round(-$balance428, 2));
        $uncoveredLoss = max(0.0, round($balance429, 2));
        return [
            'retained_profit' => $retainedProfit,
            'uncovered_loss' => $uncoveredLoss,
            'limit' => max(0.0, round(max(0.0, $currentProfit) + $retainedProfit - $uncoveredLoss, 2)),
        ];
    }

    // ── krok 6: uzavření knih (R8/R9) ─────────────────────────────────────────

    /**
     * Closing zápis (source ('closing', period_id), entry_date = ends_on) + status
     * closed. Gating: stav closing, kroky 1–6 done/skipped (stock jen pro firmy
     * se skladem + double_entry, viz preCloseStepKeys), error kontroly čisté
     * (re-run inline). Invariant Σ702 = 0 hlídá ClosingEntryBuilder
     * (closing_unbalanced_702). Prázdné období (žádné zůstatky) se uzavře bez zápisu.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function closeBooks(int $supplierId, int $periodId, int $rowVersion, array $meta = [], bool $overrideUnposted = false, ?string $overrideReason = null): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta, $overrideUnposted, $overrideReason): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            $this->assertStatus($period, ['closing']);

            $steps = $this->stepsMap($supplierId, $periodId);
            if (!$this->stepsComplete($steps, $this->preCloseStepKeys($supplierId, $period))) {
                throw new ClosingException(
                    'closing_steps_incomplete',
                    'Kroky průvodce před uzavřením knih musí být done/skipped.',
                );
            }
            $failing = $this->failingErrorChecks($supplierId, $period);
            if ($failing !== []) {
                throw new ClosingException(
                    'precheck_failed',
                    'Kontroly uzávěrky blokují uzavření knih: ' . implode(', ', $failing) . '.',
                );
            }

            $startsOn = (string) $period['starts_on'];
            $endsOn = (string) $period['ends_on'];
            $fiscalYear = (int) $period['fiscal_year'];

            // EP-10b: nezaúčtované aktivní doklady (posted_at/reversed_by filtrované dotazy)
            // uzavření knih standardně BLOKUJÍ. Obchodní override je možný jen s konkrétním
            // oprávněním (accounting.periods.close_override — vynucuje action) a doloženým
            // důvodem; zaznamená se neměnná auditní událost a výjimka do payloadu kroku
            // (a odtud do závěrkového balíčku). Bez override = tvrdý blok.
            $unpostedInvoices = $this->closing->unpostedInvoices($supplierId, $startsOn, $endsOn);
            $unpostedPurchases = $this->closing->unpostedPurchases($supplierId, $startsOn, $endsOn);
            $unpostedCount = count($unpostedInvoices) + count($unpostedPurchases);
            $unpostedOverride = null;
            if ($unpostedCount > 0) {
                if (!$overrideUnposted) {
                    throw new ClosingException(
                        'unposted_documents_block',
                        'Uzavření knih blokuje ' . $unpostedCount . ' nezaúčtovaných aktivních dokladů '
                        . '(' . count($unpostedInvoices) . ' vydaných / ' . count($unpostedPurchases) . ' přijatých). '
                        . 'Zaúčtuj je, nebo použij oprávněný override s uvedením důvodu.',
                        422,
                    );
                }
                $reason = trim((string) $overrideReason);
                if ($reason === '') {
                    throw new ClosingException(
                        'validation_failed',
                        'Override nezaúčtovaných dokladů vyžaduje doložený důvod.',
                        422,
                    );
                }
                $unpostedOverride = [
                    'count'        => $unpostedCount,
                    'invoice_ids'  => array_map(static fn (array $r): int => (int) $r['id'], $unpostedInvoices),
                    'purchase_ids' => array_map(static fn (array $r): int => (int) $r['id'], $unpostedPurchases),
                    'reason'       => mb_substr($reason, 0, 500),
                    'overridden_by' => $meta['user_id'] ?? null,
                ];
                $this->audit($supplierId, 'accounting.books_closed_unposted_override', $periodId, $unpostedOverride, $meta);
            }

            $pl = $this->closing->plBalances($supplierId, $periodId, $startsOn, $endsOn);
            $bs = $this->closing->bsBalances($supplierId, $periodId, $endsOn);
            $built = $this->builder->closingLines($pl, $bs);
            $lines = $built['lines'];
            $profit = round((float) $built['profit'], 2);

            $entryId = null;
            $docNo = null;
            if ($lines !== []) {
                $this->assertKnownCodes($supplierId, $lines);
                $existing = $this->journal->findBySource($supplierId, 'closing', $periodId);
                $docNo = $existing !== null && $existing['document_no'] !== null
                    ? (string) $existing['document_no']
                    : $this->series->next($supplierId, 'closing', $fiscalYear);
                $entryId = $this->posting->postDocument($supplierId, 'closing', $periodId, $lines, [
                    'entry_date' => $endsOn,
                    'document_no' => $docNo,
                    'description' => 'Uzavření účetních knih ' . $fiscalYear,
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                    'allow_closing_period' => true,
                ]);
            }

            $this->casStatus($supplierId, $periodId, 'closed', $rowVersion, $meta);

            // D5 (audit 2026-07): zmraž kategorii ÚJ + kritéria uzavíraného období do
            // entity_category_history (výkon scope='auto', historičtí zaměstnanci, §1e
            // kontinuita). Selhání zmražení NESMÍ shodit uzavření knih — kategorizace je
            // odvozená evidence, ne účetní zápis. EP-14: selhání se ale NESMÍ tiše spolknout —
            // vrací se viditelný warning v odpovědi kroku a zákonné schválení (approved) je
            // zablokované, dokud historická kategorie není uložena (viz AccountingPeriodAction).
            $categoryFrozen = true;
            $categoryFreezeError = null;
            try {
                $this->categories->freeze($supplierId, $periodId);
            } catch (\Throwable $e) {
                $categoryFrozen = false;
                $categoryFreezeError = $e->getMessage();
                $this->logger->warning(
                    'Zmražení kategorie ÚJ při uzávěrce selhalo (nezablokuje uzavření knih, blokuje schválení): ' . $e->getMessage(),
                    ['supplier_id' => $supplierId, 'period_id' => $periodId, 'exception' => $e],
                );
            }

            // § 18/1/c — zmraž automaticky předvyplněné sekce přílohy k závěrce.
            // Bez toho se příloha schváleného roku mění spolu s firmou: název, sídlo,
            // IČO i kategorie se braly z AKTUÁLNÍHO supplier, ne ze stavu k rozvahovému
            // dni. Selhání NESMÍ shodit uzavření knih — příloha je textová evidence,
            // ne účetní zápis; stejný důvod jako u zmražení kategorie výše.
            try {
                $this->statementNotes->freezeAutoValues(
                    $supplierId,
                    (int) $period['fiscal_year'],
                    $periodId,
                    $meta['user_id'] ?? null,
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Zmražení přílohy k závěrce selhalo (nezablokuje uzavření knih): ' . $e->getMessage(),
                    ['supplier_id' => $supplierId, 'period_id' => $periodId, 'exception' => $e],
                );
            }

            $payload = [
                'entry_id' => $entryId,
                'profit' => $profit,
                'document_no' => $docNo,
                'lines_count' => count($lines),
                'category_frozen' => $categoryFrozen,
            ];
            if ($unpostedOverride !== null) {
                // Výjimka do závěrkového balíčku (EP-10b) — auditovatelný záznam, že se
                // knihy uzavřely přes nezaúčtované doklady, s důvodem a seznamem dokladů.
                $payload['unposted_override'] = $unpostedOverride;
            }
            if (!$categoryFrozen) {
                $payload['warning'] = 'category_freeze_failed';
                $payload['warning_detail'] = 'Zmražení kategorie účetní jednotky (§1e) se nezdařilo: '
                    . $categoryFreezeError . '. Knihy jsou uzavřené, ale období nelze zákonně schválit, '
                    . 'dokud historická kategorie nebude uložena (zopakuj krok / oprav podklad kategorizace).';
            }
            $this->closing->upsertStep($supplierId, $periodId, 'close_books', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->audit($supplierId, 'accounting.books_closed', $periodId, [
                'entry_id' => $entryId,
                'profit' => $profit,
                'document_no' => $docNo,
                'category_frozen' => $categoryFrozen,
            ], $meta);
            return $payload + ['status' => 'closed'];
        });
    }

    // ── krok 7: otevření nového roku (R8/R11) ─────────────────────────────────

    /**
     * Opening zápis následujícího období (source ('opening', next_id), entry_date
     * = next.starts_on) + volitelné FX storno saldokonta (slot 3, R11) + rozpuštění
     * počátečního stavu zásob do spotřeby (SKLAD §3.4 krok 5, slot 7). Rozvahové
     * zůstatky se NEpřepočítávají — zrcadlí se řádky closing zápisu (část c) a VH
     * z payloadu kroku 6 (kontinuita 702↔701 po haléřích). Následující období se
     * najde/založí dle R5 (period_gap při nenavazující řadě).
     *
     * Stav uzávěrky: běží nad 'closed' i 'approved' obdobím (past #37). Zápisy míří
     * VÝHRADNĚ do N+1, do schváleného období nezasahuje — zámek §17/7 zůstává. Stav
     * období se nemění (jen bumpVersion), takže 'approved' zůstává 'approved'.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array<string,mixed>
     */
    public function openNext(int $supplierId, int $periodId, int $rowVersion, array $meta = []): array
    {
        return $this->tx(function () use ($supplierId, $periodId, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            // Past #37: open_next musí jít i nad 'approved' obdobím. Je to čistě technický
            // přenos počátečních zůstatků do NÁSLEDUJÍCÍHO období (N+1, open) — do knih
            // schváleného období nezasahuje (žádný R7 zápis sem), takže zámek §17/7 zůstává
            // nedotčen. Bez toho by se uživatel, který schválil dřív než otevřel nový rok,
            // zasekl (musel by ručně unapprove→open_next→approve). Období zůstává 'approved'
            // (openNext dělá jen bumpVersion, nikdy casStatus).
            $this->assertStatus($period, ['closed', 'approved']);
            $steps = $this->stepsMap($supplierId, $periodId);
            if ($steps['close_books']['status'] !== 'done') {
                throw new ClosingException('closing_steps_incomplete', 'Nejprve uzavři knihy (krok close_books).');
            }

            $next = $this->ensureNextPeriod($supplierId, $period);
            // EP-3: zamkni i CÍLOVÉ (N+1) období FOR UPDATE ve stejné tx, ať je kontrola
            // jeho stavu konzistentní se souběžným běžným účtováním do N+1 (postDocument téže
            // tx pak zamyká týž řádek reentrantně). Pořadí zámků: uzavírané období → jeho
            // následník (chronologicky vzestupně); jednoobdobové operace zamykají jen svůj
            // řádek, takže nevzniká cyklus.
            $nextLocked = $this->periods->findForUpdate((int) $next['id'], $supplierId);
            if ($nextLocked !== null) {
                $next = $nextLocked;
            }
            if ($next['status'] !== 'open') {
                throw new ClosingException(
                    'next_period_not_open',
                    'Následující období ' . $next['fiscal_year'] . ' není otevřené (stav: ' . $next['status'] . ').',
                );
            }
            $nextId = (int) $next['id'];
            $nextFy = (int) $next['fiscal_year'];
            $nextStart = (string) $next['starts_on'];
            $nextEnds = (string) $next['ends_on'];

            // Zrcadlo části (c) closing zápisu: rozvahové řádky proti 702.
            $closingEntry = $this->findEntryWithLines($supplierId, 'closing', $periodId);
            $profit = round((float) ($steps['close_books']['payload']['profit'] ?? 0.0), 2);
            $bs = [];
            foreach ($closingEntry['lines'] ?? [] as $l) {
                if (!in_array((string) $l['account_type'], ['asset', 'liability', 'equity'], true)) {
                    continue;
                }
                // (c): debetní zůstatek → MD 702 / D účet (řádek účtu = credit);
                //      kreditní zůstatek → MD účet / D 702 (řádek účtu = debit).
                $bal = $l['side'] === 'credit' ? (float) $l['amount'] : -(float) $l['amount'];
                $bs[] = [
                    'account_id' => (int) $l['account_id'],
                    'account_code' => (string) $l['account_code'],
                    'name' => (string) ($l['account_name'] ?? ''),
                    'bal' => $bal,
                ];
            }

            $entryId = null;
            $docNo = null;
            if ($bs !== [] || abs($profit) >= 0.005) {
                $lines = $this->builder->openingLines($bs, $profit);
                if ($lines !== []) {
                    $this->assertKnownCodes($supplierId, $lines);
                    $existingOpening = $this->journal->findBySource($supplierId, 'opening', $nextId);
                    $docNo = $existingOpening !== null && $existingOpening['document_no'] !== null
                        ? (string) $existingOpening['document_no']
                        : $this->series->next($supplierId, 'opening', $nextFy);
                    $entryId = $this->posting->postDocument($supplierId, 'opening', $nextId, $lines, [
                        'entry_date' => $nextStart,
                        'document_no' => $docNo,
                        'description' => 'Otevření účetních knih ' . $nextFy,
                        'posted' => true,
                        'posted_by' => $meta['posted_by'] ?? null,
                        'user_id' => $meta['user_id'] ?? null,
                        'ip' => $meta['ip'] ?? null,
                        'user_agent' => $meta['user_agent'] ?? null,
                    ]);
                }
            }

            // FX storno saldokonta k 1. dni nového období (R11, slot 3) — jen slot 1,
            // banka/pokladna (slot 2) je trvalá úprava carrying amount.
            $fxReversalId = null;
            $settings = $this->settings->get($supplierId);
            $slot1 = null;
            if ((bool) ($settings['fx_reversal_at_open'] ?? true)) {
                $slot1 = $this->findEntryWithLines($supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($periodId));
                if ($slot1 !== null && $slot1['posted_at'] !== null && ($slot1['lines'] ?? []) !== []) {
                    $saldoLines = [];
                    foreach ($slot1['lines'] as $l) {
                        $line = [
                            'account_code' => (string) $l['account_code'],
                            'side' => (string) $l['side'],
                            'amount' => (float) $l['amount'],
                        ];
                        if (($l['currency_code'] ?? null) !== null) {
                            $line['currency_code'] = $l['currency_code'];
                            $line['fx_rate'] = $l['fx_rate'];
                            $line['amount_foreign'] = $l['amount_foreign'];
                        }
                        $saldoLines[] = $line;
                    }
                    $revLines = $this->fx->buildReversal($saldoLines);
                    $revSourceId = ClosingSourceId::fxReversal($periodId);
                    $existingRev = $this->journal->findBySource($supplierId, 'fx_revaluation', $revSourceId);
                    $revDocNo = $existingRev !== null && $existingRev['document_no'] !== null
                        ? (string) $existingRev['document_no']
                        : $this->series->next($supplierId, 'fx', $nextFy);
                    $fxReversalId = $this->posting->postDocument($supplierId, 'fx_revaluation', $revSourceId, $revLines, [
                        'entry_date' => $nextStart,
                        'document_no' => $revDocNo,
                        'description' => 'Storno přecenění saldokonta k 1. dni období',
                        'posted' => true,
                        'posted_by' => $meta['posted_by'] ?? null,
                        'user_id' => $meta['user_id'] ?? null,
                        'ip' => $meta['ip'] ?? null,
                        'user_agent' => $meta['user_agent'] ?? null,
                    ]);
                }
            }
            if ($fxReversalId === null) {
                // Re-run po vypnutí settingu / bez slot-1 zápisu: stale storno
                // z dřívějšího běhu nesmí v deníku zůstat (audit dump si nechává
                // deleteClosingEntry v activity logu).
                $this->closing->deleteClosingEntry($supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($periodId));
            }

            // Otevření roku pro zásoby (SKLAD §3.4 krok 5, způsob B): počáteční stav
            // zpět do spotřeby — MD 501/504 / D 112/132. Řádky jsou PŘESNÉ zrcadlo
            // (swap stran) zaúčtovaného stock-closing slotu, ne nový výpočet —
            // bilanční kontinuita 112/132 (konečný stav N = počáteční N+1) tak platí
            // po haléřích a na TÝCHŽ účtech i při per-tenant overridu kontací
            // (seedované stock.opening.* = zrcadlo stock.closing.*). Bez R7 flagu —
            // N+1 je open (asserted výše). Klíč ('closing', stockOpening(period_id)),
            // vzor fxReversal; reverte se s krokem open_next.
            $stockReleaseId = null;
            $stockClosingEntry = $this->findEntryWithLines($supplierId, 'closing', ClosingSourceId::stockClosing($periodId));
            if ($stockClosingEntry !== null && $stockClosingEntry['posted_at'] !== null && ($stockClosingEntry['lines'] ?? []) !== []) {
                $releaseLines = [];
                foreach ($stockClosingEntry['lines'] as $l) {
                    $releaseLines[] = [
                        'account_code' => (string) $l['account_code'],
                        'side' => $l['side'] === 'debit' ? 'credit' : 'debit',
                        'amount' => (float) $l['amount'],
                    ];
                }
                $relSourceId = ClosingSourceId::stockOpening($periodId);
                $existingRel = $this->journal->findBySource($supplierId, 'closing', $relSourceId);
                $relDocNo = $existingRel !== null && $existingRel['document_no'] !== null
                    ? (string) $existingRel['document_no']
                    : $this->series->next($supplierId, 'opening', $nextFy);
                $stockReleaseId = $this->posting->postDocument($supplierId, 'closing', $relSourceId, $releaseLines, [
                    'entry_date' => $nextStart,
                    'document_no' => $relDocNo,
                    'description' => 'Počáteční stav zásob do spotřeby (způsob B) ' . $nextFy,
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                ]);
            } else {
                // Re-run po revertu stock kroku: stale release z dřívějšího běhu
                // nesmí v deníku zůstat (vzor FX storna výše).
                $this->closing->deleteClosingEntry($supplierId, 'closing', ClosingSourceId::stockOpening($periodId));
            }

            // Rozpuštění časového rozlišení drobného majetku (§DM / Task 11): PŘESNÉ zrcadlo
            // (swap stran) zaúčtovaného defer zápisu N (MD 381 / D 501) → v N+1 MD 501 / D 381.
            // Bez tohoto kroku by odklad trvale „visel" na 381. Klíč ('small_asset_accrual',
            // release_base + period_id), aby se nesrazil s defer zápisem N; entry_date =
            // 1. den N+1 (open, bez R7 flagu). Vzor stock/FX storna výše, reverte se s open_next.
            $smallAssetReleaseId = null;
            $saDeferEntry = $this->findEntryWithLines($supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($periodId));
            if ($saDeferEntry !== null && $saDeferEntry['posted_at'] !== null && ($saDeferEntry['lines'] ?? []) !== []) {
                $relLines = [];
                foreach ($saDeferEntry['lines'] as $l) {
                    $relLines[] = [
                        'account_code' => (string) $l['account_code'],
                        'side' => $l['side'] === 'debit' ? 'credit' : 'debit',
                        'amount' => (float) $l['amount'],
                    ];
                }
                $relSourceId = ClosingSourceId::smallAssetAccrualRelease($periodId);
                $existingRel = $this->journal->findBySource($supplierId, 'small_asset_accrual', $relSourceId);
                $relDocNo = $existingRel !== null && $existingRel['document_no'] !== null
                    ? (string) $existingRel['document_no']
                    : $this->series->next($supplierId, 'opening', $nextFy);
                $smallAssetReleaseId = $this->posting->postDocument($supplierId, 'small_asset_accrual', $relSourceId, $relLines, [
                    'entry_date' => $nextStart,
                    'document_no' => $relDocNo,
                    'description' => 'Rozpuštění časového rozlišení drobného majetku ' . $nextFy,
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                ]);
            } else {
                // Re-run po revertu deferrals / mode=none: stale rozpuštění z dřívějšího běhu.
                $this->closing->deleteClosingEntry($supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($periodId));
            }

            // EP-15: víceleté rozpouštění časového rozlišení nákladů příštích období (§DČR).
            // Rozpustí se JEN tranše cílového období N+1 (windowed per-item, MD 5xx / D 381),
            // ne celý zbytek odkladu — víceletý zbytek (N+2+) zůstává na 381 a rozpustí ho
            // openNext dalšího roku (nachází ho podle accrual dat i bez re-deferu v N+1). Klíč
            // ('prepaid_expense_accrual', release_base + period_id) beze změny, entry_date =
            // 1. den N+1 (open, bez R7 flagu). Reverte se s open_next.
            $prepaidExpenseReleaseId = null;
            $relSourceId = ClosingSourceId::prepaidExpenseAccrualRelease($periodId);
            $peRelease = $this->prepaidExpenseReleaseForPeriod($supplierId, (string) $period['ends_on'], $nextStart, $nextEnds);
            if ((int) round($peRelease['total'] * 100) !== 0) {
                $rule = $this->rules->resolve($supplierId, 'accrual.prepaid.expense');
                $deferAccount = (string) ($rule['debit_account_code'] ?? '381');
                $relLines = [];
                foreach ($peRelease['by_account'] as $account => $amount) {
                    $relLines[] = ['account_code' => (string) $account, 'side' => 'debit', 'amount' => round((float) $amount, 2)];
                }
                $relLines[] = ['account_code' => $deferAccount, 'side' => 'credit', 'amount' => round((float) $peRelease['total'], 2)];
                $this->assertKnownCodes($supplierId, $relLines);
                $existingRel = $this->journal->findBySource($supplierId, 'prepaid_expense_accrual', $relSourceId);
                $relDocNo = $existingRel !== null && $existingRel['document_no'] !== null
                    ? (string) $existingRel['document_no']
                    : $this->series->next($supplierId, 'opening', $nextFy);
                $prepaidExpenseReleaseId = $this->posting->postDocument($supplierId, 'prepaid_expense_accrual', $relSourceId, $relLines, [
                    'entry_date' => $nextStart,
                    'document_no' => $relDocNo,
                    'description' => 'Rozpuštění časového rozlišení nákladů příštích období ' . $nextFy,
                    'posted' => true,
                    'posted_by' => $meta['posted_by'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'ip' => $meta['ip'] ?? null,
                    'user_agent' => $meta['user_agent'] ?? null,
                ]);
            } else {
                // Žádná tranše pro toto období / re-run po revertu: smaž stale rozpuštění.
                $this->closing->deleteClosingEntry($supplierId, 'prepaid_expense_accrual', $relSourceId);
            }

            $payload = [
                'entry_id' => $entryId,
                'fx_reversal_entry_id' => $fxReversalId,
                'stock_release_entry_id' => $stockReleaseId,
                'small_asset_release_entry_id' => $smallAssetReleaseId,
                'prepaid_expense_release_entry_id' => $prepaidExpenseReleaseId,
                'next_period_id' => $nextId,
                'document_no' => $docNo,
            ];
            $this->closing->upsertStep($supplierId, $periodId, 'open_next', 'done', $payload, null, $meta['user_id'] ?? null);
            $this->bumpVersion($supplierId, $periodId, $rowVersion);
            $this->audit($supplierId, 'accounting.books_opened', $periodId, [
                'entry_id' => $entryId,
                'fx_reversal_entry_id' => $fxReversalId,
                'next_period_id' => $nextId,
                'document_no' => $docNo,
            ], $meta);
            return $payload;
        });
    }

    // ── revert kroků (R12) ────────────────────────────────────────────────────

    /**
     * Revert kroku — HARD DELETE závěrkových zápisů (closing/opening/fx_revaluation)
     * s dumpem pro audit; pořadí vynuceno: open_next → close_books → stock /
     * fx_revaluation. Stock release v N+1 (slot 7) se maže s krokem open_next.
     * Checklist kroky jen resetují stav (asistované zápisy se NEmažou — storno
     * jednotlivě přes reverseAssistedEntry). Admin-only vynucuje akce; guard
     * approved je zde. Vrací dumps pro audit accounting.closing_step_reverted.
     *
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{step:string, dumps: array<string,mixed>, status?:string}
     */
    public function revertStep(int $supplierId, int $periodId, string $stepKey, int $rowVersion, array $meta = []): array
    {
        if (!in_array($stepKey, self::STEP_KEYS, true)) {
            throw new ClosingException('unknown_step', 'Neznámý krok uzávěrky "' . $stepKey . '".');
        }
        return $this->tx(function () use ($supplierId, $periodId, $stepKey, $rowVersion, $meta): array {
            $period = $this->lockPeriod($supplierId, $periodId, $rowVersion);
            if ($period['status'] === 'approved') {
                throw new ClosingException(
                    'invalid_status_transition',
                    'Schválenou závěrku nelze revertovat (§17/7 ZoÚ) — nejprve zruš schválení.',
                );
            }
            $steps = $this->stepsMap($supplierId, $periodId);
            $userId = $meta['user_id'] ?? null;
            $dumps = [];
            $result = ['step' => $stepKey];

            switch ($stepKey) {
                case 'open_next':
                    $this->assertStatus($period, ['closed', 'closing']);
                    $next = $this->periods->nextPeriod($supplierId, (string) $period['ends_on']);
                    // §17/7: do uzavřených knih následujícího období se nesahá —
                    // opening jde smazat, jen dokud N+1 nemá vlastní uzávěrku
                    // (deleteClosingEntry jde mimo PostingService a jeho guardy).
                    if ($next !== null && (
                        in_array($next['status'], ['approved', 'closed'], true)
                        || $this->closing->hasClosingEntries($supplierId, (int) $next['id'])
                    )) {
                        throw new ClosingException(
                            'invalid_status_transition',
                            'Následující období je uzavřené/schválené nebo má vlastní uzávěrku — '
                                . 'opening zápis nelze smazat (§17/7). Nejprve znovu otevři následující období.',
                        );
                    }
                    if ($next !== null) {
                        $dump = $this->closing->deleteClosingEntry($supplierId, 'opening', (int) $next['id']);
                        if ($dump !== null) {
                            $dumps['opening'] = $dump;
                        }
                    }
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'fx_revaluation', ClosingSourceId::fxReversal($periodId));
                    if ($dump !== null) {
                        $dumps['fx_reversal'] = $dump;
                    }
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'closing', ClosingSourceId::stockOpening($periodId));
                    if ($dump !== null) {
                        $dumps['stock_release'] = $dump;
                    }
                    // §DM / §DČR rozpouštěcí zápisy zrcadlené do N+1 (MD 5xx / D 381)
                    // patří rovněž k open_next a musí zmizet spolu s ním — jinak by
                    // v N+1 osiřely a trvale nafoukly náklady dalšího roku.
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($periodId));
                    if ($dump !== null) {
                        $dumps['small_asset_release'] = $dump;
                    }
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($periodId));
                    if ($dump !== null) {
                        $dumps['prepaid_expense_release'] = $dump;
                    }
                    $this->closing->resetStep($supplierId, $periodId, 'open_next');
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                case 'close_books':
                    $this->assertStatus($period, ['closed']);
                    $next = $this->periods->nextPeriod($supplierId, (string) $period['ends_on']);
                    if ($steps['open_next']['status'] === 'done'
                        || ($next !== null && $this->closing->hasOpeningEntries($supplierId, (int) $next['id']))) {
                        throw new ClosingException(
                            'revert_order_violation',
                            'Nejprve proveď revert kroku open_next (vynucené pořadí R12).',
                        );
                    }
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'closing', $periodId);
                    if ($dump !== null) {
                        $dumps['closing'] = $dump;
                    }
                    $this->casStatus($supplierId, $periodId, 'closing', $rowVersion, $meta);
                    $this->closing->resetStep($supplierId, $periodId, 'close_books');
                    $result['status'] = 'closing';
                    break;

                case 'fx_revaluation':
                    $this->assertStatus($period, ['closing']);
                    if ($steps['close_books']['status'] === 'done'
                        || $this->journal->findBySource($supplierId, 'closing', $periodId) !== null) {
                        throw new ClosingException(
                            'revert_order_violation',
                            'Nejprve proveď revert kroku close_books (vynucené pořadí R12).',
                        );
                    }
                    foreach (['saldo' => ClosingSourceId::fxSaldo($periodId), 'bank' => ClosingSourceId::fxBank($periodId)] as $slot => $sourceId) {
                        $dump = $this->closing->deleteClosingEntry($supplierId, 'fx_revaluation', $sourceId);
                        if ($dump !== null) {
                            $dumps[$slot] = $dump;
                        }
                    }
                    $this->closing->resetStep($supplierId, $periodId, 'fx_revaluation');
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                case 'stock':
                    $this->assertStatus($period, ['closing']);
                    if ($steps['close_books']['status'] === 'done'
                        || $this->journal->findBySource($supplierId, 'closing', $periodId) !== null) {
                        throw new ClosingException(
                            'revert_order_violation',
                            'Nejprve proveď revert kroku close_books (vynucené pořadí R12).',
                        );
                    }
                    $slots = [
                        'stock_closing' => ClosingSourceId::stockClosing($periodId),
                        'stock_shortage' => ClosingSourceId::stockShortage($periodId),
                        'stock_surplus' => ClosingSourceId::stockSurplus($periodId),
                    ];
                    foreach ($slots as $slot => $sourceId) {
                        $dump = $this->closing->deleteClosingEntry($supplierId, 'closing', $sourceId);
                        if ($dump !== null) {
                            $dumps[$slot] = $dump;
                        }
                    }
                    $this->closing->resetStep($supplierId, $periodId, 'stock');
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                case 'provisions':
                    $this->assertStatus($period, ['closing']);
                    if ($steps['close_books']['status'] === 'done'
                        || $this->journal->findBySource($supplierId, 'closing', $periodId) !== null) {
                        throw new ClosingException(
                            'revert_order_violation',
                            'Nejprve proveď revert kroku close_books (vynucené pořadí R12).',
                        );
                    }
                    foreach (($steps['provisions']['payload']['entries'] ?? []) as $e) {
                        $invId = (int) ($e['invoice_id'] ?? 0);
                        $dump = $this->closing->deleteClosingEntry($supplierId, 'provision', $invId);
                        if ($dump !== null) {
                            $dumps['provision_' . $invId] = $dump;
                        }
                    }
                    $this->closing->resetStep($supplierId, $periodId, 'provisions');
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                case 'income_tax':
                    $this->assertStatus($period, ['closing']);
                    if ($steps['close_books']['status'] === 'done'
                        || $this->journal->findBySource($supplierId, 'closing', $periodId) !== null) {
                        throw new ClosingException(
                            'revert_order_violation',
                            'Nejprve proveď revert kroku close_books (vynucené pořadí R12).',
                        );
                    }
                    $dump = $this->closing->deleteClosingEntry($supplierId, 'income_tax', $periodId);
                    if ($dump !== null) {
                        $dumps['income_tax'] = $dump;
                    }
                    $this->closing->resetStep($supplierId, $periodId, 'income_tax');
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                case 'estimates':
                case 'deferrals':
                    // Bez mazání (R12 výjimka) — payload s evidencí zápisů se zachovává.
                    $this->assertStatus($period, ['open', 'closing']);
                    $step = $steps[$stepKey];
                    $this->closing->upsertStep($supplierId, $periodId, $stepKey, 'pending', $step['payload'], $step['note'], $userId);
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;

                default: // precheck, depreciation
                    $this->assertStatus($period, ['open', 'closing']);
                    $this->closing->resetStep($supplierId, $periodId, $stepKey);
                    $this->bumpVersion($supplierId, $periodId, $rowVersion);
                    break;
            }

            $result['dumps'] = $dumps;
            $this->audit($supplierId, 'accounting.closing_step_reverted', $periodId, [
                'step' => $stepKey,
                'entry_dump' => $dumps,
            ], $meta);
            return $result;
        });
    }

    // ── precheck kontroly (§3.4 tabulka) ──────────────────────────────────────

    /**
     * Kontroly precheku (§3.4) i měsíční kontroly (D8). `$rangeFrom`/`$rangeTo`
     * (default = celé fiskální období) škálují kontroly „mezi daty" (nezaúčtované
     * doklady) a zůstatkové kontroly „k datu" (asOf = rangeTo) — invariantní
     * celoroční kontroly ({@see buildErrorChecks}, chybějící odpisy) zůstávají
     * vždy vázané na CELÉ fiskální období bez ohledu na zvolený rozsah, protože
     * dávají smysl jen v kontextu celého roku (kontinuita VH, roční odpis).
     *
     * @param array<string,mixed> $period
     * @return list<array{key:string, severity:'error'|'warning'|'info', ok:bool, value:mixed}>
     */
    public function buildChecks(
        int $supplierId,
        array $period,
        ?string $rangeFrom = null,
        ?string $rangeTo = null,
        int $cap = CheckFindingNormalizer::CAP,
    ): array {
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];
        $fiscalYear = (int) $period['fiscal_year'];
        $periodId = (int) $period['id'];
        $rangeFrom ??= $startsOn;
        $rangeTo ??= $endsOn;
        $checks = $this->buildErrorChecks($supplierId, $period);

        $unpostedInvoices = $this->closing->unpostedInvoices($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'unposted_invoices',
            'severity' => 'warning',
            'ok' => $unpostedInvoices === [],
            // Celé řádky, ne holá ID — detail kontroly jinak nemá co zobrazit než „#123".
            'value' => ['count' => count($unpostedInvoices), 'items' => $unpostedInvoices],
        ];
        $unpostedPurchases = $this->closing->unpostedPurchases($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'unposted_purchases',
            'severity' => 'warning',
            'ok' => $unpostedPurchases === [],
            'value' => ['count' => count($unpostedPurchases), 'items' => $unpostedPurchases],
        ];

        $checks[] = $this->checkTransit261($supplierId, $rangeTo);
        foreach ([
            'internal_395_open' => '395',
        ] as $key => $code) {
            $bal = round($this->closing->accountBalance($supplierId, $code, $rangeTo), 2);
            $checks[] = ['key' => $key, 'severity' => 'warning', 'ok' => abs($bal) < 0.005, 'value' => ['account' => $code, 'balance' => $bal]];
        }
        $bal041 = round($this->closing->accountBalance($supplierId, '041', $rangeTo), 2);
        $bal042 = round($this->closing->accountBalance($supplierId, '042', $rangeTo), 2);
        $checks[] = [
            'key' => 'acquisition_04x_open',
            'severity' => 'warning',
            'ok' => abs($bal041) < 0.005 && abs($bal042) < 0.005,
            'value' => ['041' => $bal041, '042' => $bal042],
        ];

        // Nedočerpané zálohy na pořízení materiálu/zboží (audit 2026-07 D8 — auditor
        // uváděl 111/131 mezi kontrolami F4, ve skutečnosti chyběly úplně).
        $bal111 = round($this->closing->accountBalance($supplierId, '111', $rangeTo), 2);
        $bal131 = round($this->closing->accountBalance($supplierId, '131', $rangeTo), 2);
        $checks[] = [
            'key' => 'procurement_111_131_open',
            'severity' => 'warning',
            'ok' => abs($bal111) < 0.005 && abs($bal131) < 0.005,
            'value' => ['111' => $bal111, '131' => $bal131],
        ];

        // Zálohové účty 314 (poskytnuté zálohy) / 324 (přijaté zálohy) k rozvahovému
        // dni (audit 2026-07 K1). Na rozdíl od ryze průběžných účtů (261/395/111/131)
        // tu otevřený zůstatek MŮŽE být legitimní — nevypořádaná záloha. Proto jen
        // WARNING (ne error): účetní ověří, že jde o skutečně nevypořádané zálohy,
        // ne o zapomenutý průběžný zůstatek. Účty jsou saldokontní (per partner),
        // takže drobný haléřový zbytek po vypořádání není chyba.
        $bal314 = round($this->closing->accountBalance($supplierId, '314', $rangeTo), 2);
        $bal324 = round($this->closing->accountBalance($supplierId, '324', $rangeTo), 2);
        $checks[] = [
            'key' => 'deposits_314_324_open',
            'severity' => 'warning',
            'ok' => abs($bal314) < 0.005 && abs($bal324) < 0.005,
            'value' => ['314' => $bal314, '324' => $bal324],
        ];

        // K1: generická kontrola zůstatku VŠECH zúčtovacích (průběžných) účtů nad
        // příznakem chart_of_accounts.is_clearing — rozšiřuje ad-hoc kontroly výše
        // (261/041/042/111/131/395/314/324) na cokoli, co si účetní označí jako
        // průběžné, i pro firmy s vlastní osnovou. Otevřený zůstatek MŮŽE být legitimní
        // (nedočerpaná záloha, pořízení na cestě) → warning, ne error. Zůstatek počítán
        // BEZ filtru na reversed_by (originál + storno se v SUM vyruší).
        $clearingOpen = $this->closing->clearingAccountsWithBalance($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'clearing_accounts_open',
            'severity' => 'warning',
            'ok' => $clearingOpen === [],
            'value' => ['count' => count($clearingOpen), 'accounts' => $clearingOpen],
        ];

        // Chybějící účetní odpis hlásit JEN u majetku, který v daném období skutečně odpisovat šel.
        // `status` je dnešní stav, ale kontrola se ptá na minulé období — bez omezení datem by karta
        // zařazená letos křičela i v uzávěrce loňska (nález: BMW zařazené 30. 9. 2025 hlášené v 2024).
        // Vyřazený majetek se v období po vyřazení neodpisuje a majetek bez oprávkového účtu (§27,
        // tax_method='none') se neodpisuje nikdy.
        // Vrací SEZNAM karet, ne jen počet: kontrola hlásící „1 nález" s prázdným popupem
        // je horší než žádná — uživatel ví, že něco je špatně, a nemá jak zjistit co.
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.put_into_use_date, a.input_price
               FROM assets a
              WHERE a.supplier_id = ? AND a.status = \'in_use\'
                AND a.accumulated_account_code IS NOT NULL
                AND a.put_into_use_date IS NOT NULL AND a.put_into_use_date <= ?
                AND (a.disposal_date IS NULL OR a.disposal_date >= ?)
                AND NOT EXISTS (SELECT 1 FROM depreciation_entries de
                                 WHERE de.asset_id = a.id AND de.kind = \'accounting\' AND de.fiscal_year = ?)
              ORDER BY a.put_into_use_date, a.id'
        );
        $stmt->execute([$supplierId, $rangeTo, $rangeFrom, $fiscalYear]);
        $missingDepRows = array_map(static fn (array $r): array => [
            'doc_type'  => 'asset',
            'doc_id'    => (int) $r['id'],
            'doc_no'    => (string) ($r['inventory_number'] ?? ''),
            'doc_date'  => (string) $r['put_into_use_date'],
            'partner'   => (string) ($r['name'] ?? ''),
            'amount'    => round((float) $r['input_price'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        $checks[] = [
            'key' => 'depreciation_missing',
            'severity' => 'warning',
            'ok' => $missingDepRows === [],
            'value' => ['count' => count($missingDepRows), 'items' => $missingDepRows],
        ];

        // Otevřené cizoměnové položky nesou i SEZNAM. Dřív se posílal jen `count`, takže
        // kontrola hlásila „1 nález" a popup byl prázdný — uživatel věděl, že něco k
        // přecenění je, a neměl jak zjistit co. Jeden doklad může mít víc cizoměnových
        // řádků (např. 311 a 343), proto se řádky slučují na doklad; jinak by se počet
        // nálezů rozcházel s počtem dokladů v tabulce.
        $fxItems = $this->closing->openFxItems($supplierId, $rangeTo);
        $fxByDoc = [];
        foreach ($fxItems as $it) {
            $docKey = $it['doc_type'] . '#' . $it['doc_id'];
            if (isset($fxByDoc[$docKey])) {
                $fxByDoc[$docKey]['amount_foreign'] += (float) $it['amount_foreign'];
                continue;
            }
            $fxByDoc[$docKey] = [
                'doc_type'     => $it['doc_type'],
                'doc_id'       => $it['doc_id'],
                'doc_no'       => (string) ($it['varsymbol'] ?? ''),
                'doc_date'     => $it['doc_date'] ?? null,
                'partner_name' => $it['partner_name'] ?? null,
                'amount'       => round((float) $it['total_with_vat'], 2),
                'currency'     => (string) $it['currency_code'],
                'amount_foreign' => (float) $it['amount_foreign'],
            ];
        }
        $fxRows = array_values($fxByDoc);
        $checks[] = [
            'key' => 'fx_open_items',
            'severity' => 'info',
            'ok' => true,
            'value' => ['count' => count($fxRows), 'items' => $fxRows],
        ];

        // K6: invariant cizoměnové stopy — řádek na devizovém účtu (221/211/261…) deklarující
        // cizí měnu MUSÍ nést amount_foreign a naopak (XOR = rozbitá stopa). Bez úplné stopy
        // FxRevaluationService nerealizované přecenění k rozvahovému dni tiše zkreslí. Jen
        // surfacing — nic nepřepisuje.
        $fxFootprint = $this->closing->foreignCurrencyFootprintMissing($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'fx_footprint_missing',
            'severity' => 'warning',
            'ok' => $fxFootprint === [],
            'value' => ['count' => count($fxFootprint), 'accounts' => $fxFootprint],
        ];

        $checks[] = [
            'key' => 'estimates_balances',
            'severity' => 'info',
            'ok' => true,
            'value' => [
                '388' => round($this->closing->accountBalance($supplierId, '388', $rangeTo), 2),
                '389' => round($this->closing->accountBalance($supplierId, '389', $rangeTo), 2),
            ],
        ];

        // § 36a ZDPH / § 23 odst. 7 ZDP — ceny mezi spojenými osobami. Hlásí se jen
        // MĚŘITELNÉ odchylky: položka fakturovaná spojené osobě proti mediánu cen téže
        // položky fakturovaných nespojeným. Kde srovnání není, odchylka se netvrdí —
        // podložit daňové tvrzení odhadem by bylo horší než mlčet. Samotný seznam
        // transakcí se spojenými osobami je v `related_party_transactions` (info).
        $rpDeviations = $this->relatedParties->priceDeviations($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'related_party_price_deviation',
            'severity' => 'warning',
            'ok' => $rpDeviations === [],
            'value' => ['count' => count($rpDeviations), 'items' => $rpDeviations],
        ];

        $rpTransactions = $this->relatedParties->transactions($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'related_party_transactions',
            'severity' => 'info',
            'ok' => true,
            'value' => ['count' => count($rpTransactions), 'items' => $rpTransactions],
        ];

        // ČÚS 019 — dohad PŘENESENÝ z minulého období, který se letos nerozpustil. Jakmile
        // doklad dorazí, dohad musí zmizet; když nezmizí, knihy nesou náklad dvakrát (dohad
        // z loňska + letošní faktura). Rozpuštění je ruční úkon a `estimates_balances` výš
        // je jen `info` s ok => true, takže zůstatek dosud procházel tiše.
        $unreleasedEstimates = $this->closing->unreleasedEstimates($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'estimates_unreleased',
            'severity' => 'warning',
            'ok' => $unreleasedEstimates === [],
            'value' => ['count' => count($unreleasedEstimates), 'accounts' => $unreleasedEstimates],
        ];
        $deferrals = [];
        foreach (['381', '382', '383', '384', '385'] as $code) {
            $deferrals[$code] = round($this->closing->accountBalance($supplierId, $code, $rangeTo), 2);
        }
        $checks[] = ['key' => 'deferrals_balances', 'severity' => 'info', 'ok' => true, 'value' => $deferrals];

        $checks[] = $this->checkAccountsOnUnusualSide($supplierId, $periodId, $rangeTo);
        $checks[] = $this->checkAssetsWithoutAccumulatedDepreciation($supplierId, $rangeTo);
        $checks[] = $this->checkAccount343VsReturn($supplierId, $rangeFrom, $rangeTo);

        // K3: doklad říká „zaplaceno", ale deník o úhradě neví — zaplacené faktury
        // s nevynulovaným saldem na svém saldokontním účtu (FV 311 / FP 321) k rangeTo.
        // Tolerance 0,50 Kč, detail per doklad viz ClosingRepository::paid*OpenSaldo.
        $paidInvoicesSaldo = $this->closing->paidInvoicesOpenSaldo($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'paid_invoices_open_saldo',
            'severity' => 'warning',
            'ok' => $paidInvoicesSaldo === [],
            'value' => ['count' => count($paidInvoicesSaldo), 'items' => $paidInvoicesSaldo],
        ];
        $paidPurchasesSaldo = $this->closing->paidPurchasesOpenSaldo($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'paid_purchases_open_saldo',
            'severity' => 'warning',
            'ok' => $paidPurchasesSaldo === [],
            'value' => ['count' => count($paidPurchasesSaldo), 'items' => $paidPurchasesSaldo],
        ];

        // K3 (proformy): proforma 'paid', ale přijatá záloha na 324 v deníku chybí.
        $paidProformas = $this->closing->paidProformasWithoutAdvance($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'paid_proformas_no_advance',
            'severity' => 'warning',
            'ok' => $paidProformas === [],
            'value' => ['count' => count($paidProformas), 'items' => $paidProformas],
        ];

        // K3 zrcadlově na přijaté straně: zálohová PF 'paid', ale úhrada na 314 v deníku
        // chybí. Zálohová faktura nemá předpis na 321 — do deníku vstupuje až peněžní
        // nohou 314 MD, takže prázdné 314 znamená, že o zaplacené záloze deník neví.
        $paidAdvances = $this->closing->paidAdvancesWithoutBookedPayment($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'paid_advances_no_payment',
            'severity' => 'warning',
            'ok' => $paidAdvances === [],
            'value' => ['count' => count($paidAdvances), 'items' => $paidAdvances],
        ];

        // § 11 odst. 1 písm. b) ZoÚ — zaúčtovaný zápis bez OBSAHU účetního případu. Částky
        // i účty sedí, ale z deníku nejde poznat, čeho se případ týkal; auditní stopa pak
        // doloží jen kdy a kolik, ne co. Nové zápisy sem spadnout nemůžou (PostingService
        // si popis dopočítá ze zdroje), takže jde o historii a data vzniklá mimo aplikaci —
        // proto varování, ne blokující chyba: uzávěrku to zastavit nemá, doplnit popis ano.
        $noDescription = $this->closing->entriesWithoutDescription($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'entries_without_description',
            'severity' => 'warning',
            'ok' => $noDescription === [],
            'value' => ['count' => count($noDescription), 'items' => $noDescription],
        ];

        // Stornovaný doklad s AKTIVNÍM zápisem v uzavíraném období. Účtuje se podle deníku,
        // takže knihy nesou náklad/výnos a saldokonto dokladu, o kterém evidence tvrdí, že
        // neexistuje — uzávěrka by ten rozpor zabetonovala do schváleného období.
        // Vzniká stornem mimo DocumentJournalSync (import, přímý zásah do DB, migrace).
        $cancelledWithEntry = $this->closing->cancelledDocumentsWithActiveEntry($supplierId, $rangeFrom, $rangeTo);
        $checks[] = [
            'key' => 'cancelled_with_entry',
            'severity' => 'warning',
            'ok' => $cancelledWithEntry === [],
            'value' => ['count' => count($cancelledWithEntry), 'items' => $cancelledWithEntry],
        ];

        // K3 (opačný směr): doklad se stavem 'sent', ale úhrada na 311/321 už je v deníku
        // („zaplaceno v deníku, doklad pořád issued") — neaktualizovaný stav dokladu.
        $settledUnpaid = array_merge(
            array_map(static fn (array $r): array => $r + ['doc_type' => 'invoice'], $this->closing->settledButUnpaidInvoices($supplierId, $rangeTo)),
            array_map(static fn (array $r): array => $r + ['doc_type' => 'purchase_invoice'], $this->closing->settledButUnpaidPurchases($supplierId, $rangeTo)),
        );
        $checks[] = [
            'key' => 'settled_but_unpaid',
            'severity' => 'warning',
            'ok' => $settledUnpaid === [],
            'value' => ['count' => count($settledUnpaid), 'items' => $settledUnpaid],
        ];

        // Realizovaný kurzový rozdíl NEZAÚČTOVANÝ na 563/663 (audit — VF 2405007):
        // cizoměnový plně zaplacený doklad, jehož úhrada vypořádala saldokonto jiným
        // kurzem než doklad, ale rozdíl nikdo nepřeúčtoval na kurzový výsledek. Odlišné
        // od NErealizovaných rozdílů k rozvahovému dni (krok fx_revaluation). Detail a
        // guard proti dvojímu hlášení viz ClosingRepository::realizedFxUnbooked.
        $realizedFx = $this->closing->realizedFxUnbooked($supplierId, $rangeTo);
        $checks[] = [
            'key' => 'realized_fx_unbooked',
            'severity' => 'warning',
            'ok' => $realizedFx === [],
            'value' => ['count' => count($realizedFx), 'items' => $realizedFx],
        ];

        $checks[] = $this->checkSmallAssetCards($supplierId, $rangeFrom, $rangeTo);

        $checks[] = $this->checkCnbRateDeviation($supplierId, $rangeFrom, $rangeTo);

        $checks[] = $this->checkPaymentMatchAudit($supplierId, $rangeFrom, $rangeTo);

        // § 99a — nárok na čtvrtletní zdaňovací období. `supplier.vat_period` byl ruční
        // přepínač bez kontroly; nesprávné nastavení znamená celoročně pozdě podávaná
        // přiznání, ne jednu chybu. `ok=false` kryje obrat nad limitem (odst. 1)
        // i rok registrace a rok následující (odst. 3, EPIC VH-04) — obojí je error.
        $vatPeriod = $this->vatPeriodEntitlement->evaluate($supplierId, $fiscalYear);
        $checks[] = [
            'key' => 'vat_period_entitlement',
            'severity' => $vatPeriod['ok'] ? 'info' : 'error',
            'ok' => $vatPeriod['ok'],
            'value' => [
                'vat_period' => $vatPeriod['vat_period'],
                'prior_year' => $vatPeriod['prior_year'],
                'prior_year_turnover' => $vatPeriod['prior_year_turnover'],
                'limit' => $vatPeriod['limit'],
            ],
        ];

        // § 18 odst. 2 ZoÚ — velká a střední ÚJ (a každá s povinným auditem) musí mít
        // v závěrce i přehled o peněžních tocích a o změnách vlastního kapitálu. Balíček
        // závěrky je mezi povinnými částmi nemá, takže takové firmě hlásil „hotovo"
        // u závěrky, které dvě povinné části chyběly — a to je vada, kterou nikdo
        // nezachytí, dokud ji nenajde auditor.
        //
        // Oba přehledy jsou nově součástí balíčku závěrky (`cash_flow`, `equity_changes`),
        // takže kontrola už nemá být natvrdo `ok => false` — to bylo správně jen do doby,
        // kdy se výkazy nedaly vygenerovat a účetní je musela přiložit ručně. Nově se
        // ptá na to, co jediné může být špatně: SEDÍ oba výkazy? Nesedící přehled je vada
        // v datech (typicky pohyb, jehož protiúčet nejde zařadit) a takhle se odevzdat nedá.
        $category = $this->categories->evaluate($supplierId, $periodId);
        $needsSection18 = in_array((string) $category['category'], ['large', 'medium'], true)
            || ($category['scope_override'] === null && (string) $category['scope'] === 'full');
        if ($needsSection18) {
            $cashFlow = $this->cashFlowStatement->build($supplierId, $periodId);
            $equityReconciles = $this->equityStatement->build($supplierId, $periodId)['reconciles'];
            $checks[] = [
                'key' => 'section18_statements_required',
                'severity' => 'warning',
                'ok' => $cashFlow['reconciles'] === true && $equityReconciles === true,
                'value' => [
                    'category'          => $category['category'],
                    'cash_flow_net'     => $cashFlow['net_change'],
                    'cash_flow_ok'      => $cashFlow['reconciles'],
                    'equity_reconciles' => $equityReconciles,
                ],
            ];
        }

        // § 6 ZDPH — vznik plátcovství z obratu. Plátcovství vzniká ZE ZÁKONA, ne
        // přihláškou: kdo si překročení nevšimne, neodvádí daň z plnění, ze kterých ji
        // odvádět měl, a doměrek jde zpětně od data vzniku. Systém obrat i limity znal,
        // ale datum vzniku z nich nikdy nevyvodil.
        //
        // `error`, když plátcovství už VZNIKLO a firma plátcem není — to je stav, ve
        // kterém se každý další doklad vystavuje špatně. Překročení dolního limitu je
        // `warning`: povinnost nastane teprve od 1. ledna, je čas se registrovat.
        $vatReg = $this->vatRegistration->evaluate($supplierId, $fiscalYear);
        if ($vatReg['applicable'] && !$vatReg['is_vat_payer'] && $vatReg['status'] !== 'below') {
            $alreadyPayer = $vatReg['becomes_payer_on'] !== null
                && $vatReg['becomes_payer_on'] <= date('Y-m-d');
            $checks[] = [
                'key' => 'vat_registration_due',
                'severity' => $alreadyPayer ? 'error' : 'warning',
                'ok' => false,
                'value' => [
                    'turnover'         => $vatReg['turnover'],
                    'limit_low'        => $vatReg['limit_low'],
                    'limit_high'       => $vatReg['limit_high'],
                    'status'           => $vatReg['status'],
                    'crossed_on'       => $vatReg['crossed_on'],
                    'becomes_payer_on' => $vatReg['becomes_payer_on'],
                ],
            ];
        }

        // § 79 / § 79a ZDPH (EPIC VH-07) — přechod plátcovství v uzavíraném období
        // bez evidované korekce odpočtu na ř. 45. Warning, ne error: nárok (§ 79)
        // i povinnost snížení (§ 79a) závisí na obchodním majetku ke dni přechodu,
        // což systém z dokladů nevidí — rozhodnout musí účetní v agendě Opravy DPH.
        $s79Check = $this->checkVatStatusS79Missing($supplierId, $startsOn, $endsOn);
        if ($s79Check !== null) {
            $checks[] = $s79Check;
        }

        $checks[] = $this->checkVatClearingFresh($supplierId, $rangeFrom, $rangeTo);

        $checks[] = [
            'key' => 'income_tax_hint',
            'severity' => 'info',
            'ok' => true,
            'value' => 'Splatnou daň z příjmů (MD 591 / D 341) zaúčtuj krokem uzávěrky „Daň z příjmů" — '
                . 'podklad z DPPO přiznání / reportu úprav základu daně (R19).',
        ];

        // Sjednocení tvaru nálezů + strop. Bez toho měl renderer na FE podobu whitelistu
        // podle klíče kontroly a každá nová kontrola tiše spadla do `JSON.stringify`;
        // a seznam bez stropu nafoukl payload kroku u velké firmy na jednotky MB, které
        // se posílaly při každém načtení stránky. `$cap = 0` vrací vše (CSV export).
        return (new CheckFindingNormalizer())->normalizeAll($checks, $cap);
    }

    /**
     * § 79 / § 79a ZDPH — přechod plátcovství v období bez korekce odpočtu (ř. 45).
     *
     * Přechod = řádek historie plátcovství s účinností v období, jehož stav se liší
     * od stavu den před účinností (0→1 registrace, 1→0 zrušení registrace). Baseline
     * řádek (1900-01-01) se nikdy nepočítá a firma bez přechodu kontrolu vůbec
     * nedostane (vrací null). `ok=false`, když k nalezenému druhu přechodu neexistuje
     * v období žádný řádek vat_registration_corrections stejného kind.
     *
     * Public (ne private jako sousední kontroly), aby šla testovat izolovaně bez
     * sestavení celého precheku — nepotřebuje nic než supplier_id a rozsah období.
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}|null
     */
    public function checkVatStatusS79Missing(int $supplierId, string $startsOn, string $endsOn): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT effective_from, is_vat_payer FROM supplier_vat_status_history
              WHERE supplier_id = ? AND effective_from BETWEEN ? AND ?
                AND effective_from > '1900-01-01'
              ORDER BY effective_from, id"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);

        $transitions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $effectiveFrom = (string) $row['effective_from'];
            $isPayer = (bool) $row['is_vat_payer'];
            $wasPayer = \MyInvoice\Service\Vat\VatStatusService::payerAt(
                $pdo,
                $supplierId,
                (new \DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d'),
            );
            if ($wasPayer === $isPayer) {
                continue;
            }
            $transitions[] = [
                'kind'         => $isPayer ? 'registration' : 'deregistration',
                'effective_on' => $effectiveFrom,
            ];
        }
        if ($transitions === []) {
            return null;
        }

        $corr = $pdo->prepare(
            'SELECT COUNT(*) FROM vat_registration_corrections
              WHERE supplier_id = ? AND kind = ? AND effective_on BETWEEN ? AND ?'
        );
        $missing = [];
        foreach ($transitions as $transition) {
            $corr->execute([$supplierId, $transition['kind'], $startsOn, $endsOn]);
            if ((int) $corr->fetchColumn() === 0) {
                $missing[] = $transition;
            }
        }

        return [
            'key' => 'vat_status_s79_missing',
            'severity' => 'warning',
            'ok' => $missing === [],
            'value' => [
                'transitions' => $transitions,
                'missing'     => $missing,
            ],
        ];
    }

    /** @return array{key:string,severity:string,ok:bool,value:array<string,mixed>} */
    private function checkTransit261(int $supplierId, string $asOf): array
    {
        $balance = round($this->closing->accountBalance($supplierId, '261', $asOf), 2);
        if (abs($balance) < 0.005) {
            return [
                'key' => 'transit_261_open',
                'severity' => 'warning',
                'ok' => true,
                'value' => ['account' => '261', 'balance' => $balance, 'documented' => [], 'unexplained' => 0.0],
            ];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id AS tx_id, je.id AS entry_id, bt.posted_at,
                    CASE WHEN jl.side = "debit" THEN jl.amount ELSE -jl.amount END AS signed_amount,
                    CASE WHEN m.out_transaction_id = bt.id THEN m.in_transaction_id
                         ELSE m.out_transaction_id END AS pair_tx_id,
                    pair_bt.posted_at AS pair_posted_at
               FROM journal_entries je
               JOIN bank_transactions bt ON bt.id = je.source_id
               JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
               JOIN chart_of_accounts coa ON coa.id = jl.account_id AND coa.supplier_id = je.supplier_id
          LEFT JOIN bank_transfer_matches m ON m.supplier_id = je.supplier_id
                 AND (m.out_transaction_id = bt.id OR m.in_transaction_id = bt.id)
          LEFT JOIN bank_transactions pair_bt ON pair_bt.id = CASE
                    WHEN m.out_transaction_id = bt.id THEN m.in_transaction_id ELSE m.out_transaction_id END
              WHERE je.supplier_id = ? AND je.source_type = "bank" AND je.reversed_by IS NULL
                AND je.entry_date <= ? AND coa.account_code LIKE "261%"
                AND EXISTS (SELECT 1 FROM bank_posting_suggestions s
                             WHERE s.supplier_id = je.supplier_id AND s.bank_transaction_id = bt.id
                               AND s.journal_entry_id = je.id AND s.source = "transfer"
                               AND s.status IN ("approved", "auto_posted"))
                AND ((m.id IS NOT NULL AND pair_bt.posted_at > ?)
                  OR (m.id IS NULL AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND ?))
              ORDER BY bt.posted_at, bt.id'
        );
        $stmt->execute([$supplierId, $asOf, $asOf, $asOf, $asOf]);
        $documented = [];
        $documentedTotal = 0.0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $amount = round((float) $row['signed_amount'], 2);
            $documentedTotal += $amount;
            $documented[] = [
                'tx_id' => (int) $row['tx_id'],
                'entry_id' => (int) $row['entry_id'],
                'amount' => $amount,
                'posted_at' => (string) $row['posted_at'],
                'pair_tx_id' => $row['pair_tx_id'] === null ? null : (int) $row['pair_tx_id'],
            ];
        }
        $documentedTotal = round($documentedTotal, 2);
        $unexplained = round($balance - $documentedTotal, 2);
        $ok = abs($unexplained) < 0.005;
        return [
            'key' => 'transit_261_open',
            'severity' => $ok ? 'info' : 'warning',
            'ok' => $ok,
            'value' => [
                'account' => '261',
                'balance' => $balance,
                'documented' => $documented,
                'unexplained' => $unexplained,
            ],
        ];
    }

    /**
     * Účty se zůstatkem na neobvyklé straně dle normal_side (inventarizace zůstatků,
     * D8) — pohledávka 311 v kreditu, závazek 321 v debetu apod. Proklikatelné přes
     * `accounts[].account_id` (výpis účtu FE).
     */
    private function checkAccountsOnUnusualSide(int $supplierId, int $periodId, string $asOf): array
    {
        $accounts = $this->closing->accountsOnUnusualSide($supplierId, $periodId, $asOf);
        return [
            'key' => 'accounts_unusual_side',
            'severity' => 'warning',
            'ok' => $accounts === [],
            'value' => ['count' => count($accounts), 'accounts' => $accounts],
        ];
    }

    /**
     * Karty majetku v provozu (0xx), jejichž odpisový účet (07x/08x) nemá k datu
     * žádný zaúčtovaný zůstatek — signál chybějících oprávek (D8). Seskupuje se
     * per dvojice (asset_account_code, accumulated_account_code), aby výsledek
     * nesl proklikatelný seznam karet (`asset_ids`), ne jen účetní kód.
     */
    private function checkAssetsWithoutAccumulatedDepreciation(int $supplierId, string $asOf): array
    {
        $rows = $this->assets->listDepreciableForCheck($supplierId);
        $byPair = [];
        foreach ($rows as $r) {
            $key = $r['asset_account_code'] . '|' . $r['accumulated_account_code'];
            $byPair[$key]['asset_account_code'] ??= $r['asset_account_code'];
            $byPair[$key]['accumulated_account_code'] ??= $r['accumulated_account_code'];
            $byPair[$key]['assets'][] = $r;
        }

        // Nález = KARTA majetku, ne dvojice účtů. Dřív se vracely skupiny (`groups`) a
        // frontend si je rozbaloval na karty — kontrola tedy hlásila „1 nález" a v tabulce
        // se objevilo tolik řádků, kolik měla skupina karet. Účetní navíc oprava dělá po
        // kartách, ne po dvojicích účtů, takže seznam karet je i věcně to, co potřebuje.
        $flagged = [];
        foreach ($byPair as $pair) {
            $assetBal = $this->closing->accountBalance($supplierId, $pair['asset_account_code'], $asOf);
            if (abs($assetBal) < 0.005) {
                continue; // karty bez zůstatku na účtu (např. teprve pořizované) nekontrolujeme
            }
            $accBal = $this->closing->accountBalance($supplierId, $pair['accumulated_account_code'], $asOf);
            if (abs($accBal) >= 0.005) {
                continue;
            }
            foreach ($pair['assets'] as $a) {
                $flagged[] = [
                    'doc_type'  => 'asset',
                    'doc_id'    => $a['id'],
                    'doc_no'    => (string) ($a['inventory_number'] ?? ''),
                    'doc_date'  => $a['put_into_use_date'] ?? null,
                    'partner'   => (string) ($a['name'] ?? ''),
                    'amount'    => round((float) $a['input_price'], 2),
                    'note'      => $pair['asset_account_code'] . ' / ' . $pair['accumulated_account_code'],
                ];
            }
        }

        return [
            'key' => 'assets_without_accumulated_depreciation',
            'severity' => 'warning',
            'ok' => $flagged === [],
            'value' => ['count' => count($flagged), 'items' => $flagged],
        ];
    }

    /**
     * Saldo 343 vs. DPH přiznání (reuse {@see VatCrossCheckService}, C8') — dává
     * smysl jen když zvolený rozsah PŘESNĚ odpovídá kalendářnímu měsíci nebo
     * kvartálu (DPH přiznání se nepodává za libovolné „od-do"). Mimo to vracíme
     * jen informativní poznámku, ne chybu.
     */
    /**
     * Interní doklad zúčtování DPH odpovídá SKUTEČNÉMU obratu období? (migrace 1332)
     *
     * Odlišná otázka než {@see checkAccount343VsReturn()}: ta se ptá, zda zůstatek 343
     * sedí na PŘIZNÁNÍ. Tahle se ptá, zda převod daně období z 343.100/343.200 na
     * zúčtovací 343.900 odpovídá tomu, co v období DNES leží. Rozejde se to přesně
     * tehdy, když do už zúčtovaného období přibude nebo se opraví doklad — opožděná
     * přijatá faktura, dobropis, doklad vytěžený AI o pár dní později. Zůstatek 343.900
     * pak neodpovídá odváděné dani a saldo vůči FÚ přestane být porovnatelné.
     *
     * Kontrola je ČISTĚ ČTECÍ. Zavřené (`approved`/`closed`) ani zamčené období
     * nepřeúčtovává — takové období se v nálezu označí `writable=false`, aby bylo
     * poznat, že se samo neopraví a je potřeba vědomý zásah účetní (posun zámku,
     * případně dodatečné přiznání).
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}
     */
    private function checkVatClearingFresh(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        try {
            $stale = $this->vatClearing->staleForRange($supplierId, $rangeFrom, $rangeTo);
        } catch (\Throwable) {
            // Firma bez DPH osnovy / neplátce / jednoduché účetnictví — kontrola nedává smysl.
            return ['key' => 'vat_clearing_stale', 'severity' => 'info', 'ok' => true, 'value' => null];
        }

        return [
            'key' => 'vat_clearing_stale',
            'severity' => 'warning',
            'ok' => $stale === [],
            'value' => ['count' => count($stale), 'items' => $stale],
        ];
    }

    private function checkAccount343VsReturn(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $monthBounds = self::calendarMonthBounds($rangeFrom, $rangeTo);
        $quarterBounds = self::calendarQuarterBounds($rangeFrom, $rangeTo);
        [$year, $month, $vatPeriod] = $monthBounds ?? $quarterBounds ?? [null, null, null];

        if ($year === null) {
            return [
                'key' => 'vat_343_vs_return',
                'severity' => 'info',
                'ok' => true,
                'value' => null,
                'note' => 'Kontrola se spouští jen pro rozsah = celý kalendářní měsíc nebo kvartál (shodně s DPH přiznáním).',
            ];
        }

        try {
            $findings = $this->vatCrossCheck->checkAccountBalanceVsReturn($supplierId, $year, $month, $vatPeriod);
        } catch (\Throwable) {
            // Firma bez DPH osnovy / plátcovství — kontrola nedává smysl, ne chyba.
            return ['key' => 'vat_343_vs_return', 'severity' => 'info', 'ok' => true, 'value' => null];
        }

        return [
            'key' => 'vat_343_vs_return',
            'severity' => 'warning',
            'ok' => !$this->vatCrossCheck->hasBlockingMismatch($findings),
            'value' => $findings === [] ? null : $findings[0],
        ];
    }

    /**
     * Úplnost evidence karet drobného majetku vs obrat 501 „drobný majetek" (§28/5 ZoÚ —
     * inventarizace). Součet cen karet pořízených v rozsahu ({@see SmallAssetRepository::additionsBetween})
     * BEZ karet vyřazených ve STEJNÉM rozsahu (nákup-a-vratka v jednom období by jinak sumu
     * zkreslil) se porovná s NETTO obratem 501.200 z ŘÁDKŮ přijatých faktur
     * ({@see SmallAssetReportService::expenseBreakdown}, expense_kind='small_asset', po
     * dobropisech). Materiální rozdíl = mezera v evidenci (nezaevidovaná karta, nebo karta
     * bez odpovídajícího řádku) — přesně ta 52 104 nezaevidovaného majetku z auditu.
     *
     * Tolerance |diff| > 1000 Kč (haléřové/drobné rozdíly nehlásíme). Kontrola je čistě
     * o ÚPLNOSTI EVIDENCE — §DM odklad na 381 už staví na obratu 501 (samostatný krok),
     * tohle není o částce odkladu.
     *
     * @return array{key:string,severity:string,ok:bool,value:array{cards_total:float,turnover_501:float,diff:float}}
     */
    private function checkSmallAssetCards(int $supplierId, string $from, string $to): array
    {
        $cardsTotal = 0.0;
        foreach ($this->smallAssets->additionsBetween($supplierId, $from, $to) as $card) {
            $disposedAt = $card['disposed_at'] ?? null;
            if ($disposedAt !== null) {
                $disposed = substr((string) $disposedAt, 0, 10);
                if ($disposed >= $from && $disposed <= $to) {
                    continue; // pořízeno i vyřazeno v témže rozsahu — do přírůstku evidence nepatří
                }
            }
            $cardsTotal = round($cardsTotal + (float) ($card['price'] ?? 0), 2);
        }

        $turnover501 = 0.0;
        foreach (($this->smallAssetReport->expenseBreakdown($supplierId, $from, $to)['groups'] ?? []) as $g) {
            if (($g['expense_kind'] ?? null) === 'small_asset') {
                $turnover501 = round((float) $g['total'], 2);
            }
        }

        $diff = round($cardsTotal - $turnover501, 2);
        return [
            'key' => 'small_asset_cards_incomplete',
            'severity' => 'warning',
            'ok' => abs($diff) <= 1000.0,
            'value' => ['cards_total' => $cardsTotal, 'turnover_501' => $turnover501, 'diff' => $diff],
        ];
    }

    /**
     * Featura C (REAL_data_followup_UX.md) — kurz na cizoměnovém dokladu vs. denní
     * kurz ČNB k DUZP. §24/7 pevný kurz vede k záměrnému `fixed_mode_skipped` (info,
     * ne chyba). Dávkový audit = tahle kontrola nad libovolným rozsahem (viz monthlyCheck).
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}
     */
    private function checkCnbRateDeviation(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $result = $this->cnbRateChecker->findDeviations($supplierId, $rangeFrom, $rangeTo);

        if ($result['fixed_mode_skipped']) {
            return [
                'key' => 'cnb_rate_deviation',
                'severity' => 'info',
                'ok' => true,
                'value' => null,
                'note' => 'Firma má nastavený pevný kurz (§24/7 ZoÚ) — odchylka od denního ČNB kurzu je záměrná, kontrola se nespouští.',
            ];
        }

        return [
            'key' => 'cnb_rate_deviation',
            'severity' => 'warning',
            'ok' => $result['items'] === [],
            'value' => [
                'count' => count($result['items']),
                'items' => $result['items'],
                'missing_cnb_count' => $result['missing_cnb_count'],
            ],
        ];
    }

    /**
     * Featura I (REAL_data_followup_UX.md) — audit spárovaných plateb banka↔faktura: měnový
     * nesoulad, částka mimo toleranci, vymyšlený kurzový rozdíl na CZK↔CZK transakci a
     * (volitelně) neshoda protistrany. Read-only, PŘED dávkovým zaúčtováním — viz
     * {@see \MyInvoice\Service\Bank\Match\PaymentMatchAuditChecker}.
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}
     */
    private function checkPaymentMatchAudit(int $supplierId, string $rangeFrom, string $rangeTo): array
    {
        $items = $this->paymentMatchAudit->audit($supplierId, $rangeFrom, $rangeTo);

        return [
            'key' => 'payment_match_audit',
            'severity' => 'warning',
            'ok' => $items === [],
            'value' => ['count' => count($items), 'items' => $items],
        ];
    }

    /** Rozsah = přesně jeden kalendářní měsíc → [rok, měsíc, 'monthly'], jinak null. */
    private static function calendarMonthBounds(string $from, string $to): ?array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return null;
        }
        $lastDay = $start->modify('last day of this month');
        if ($start->format('d') !== '01' || $end->format('Y-m-d') !== $lastDay->format('Y-m-d')) {
            return null;
        }
        return [(int) $start->format('Y'), (int) $start->format('n'), 'monthly'];
    }

    /** Rozsah = přesně jeden kalendářní kvartál → [rok, poslední měsíc kvartálu, 'quarterly'], jinak null. */
    private static function calendarQuarterBounds(string $from, string $to): ?array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return null;
        }
        $month = (int) $start->format('n');
        if ($start->format('d') !== '01' || (($month - 1) % 3) !== 0) {
            return null;
        }
        $endMonth = $month + 2;
        $expectedEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $start->format('Y'), $endMonth)))
            ->modify('last day of this month');
        if ($end->format('Y-m-d') !== $expectedEnd->format('Y-m-d')) {
            return null;
        }
        return [(int) $start->format('Y'), $endMonth, 'quarterly'];
    }

    /**
     * Precheck payload je bodový snímek — zastará, když se po jeho uložení změní
     * data (typicky uzavření PŘEDCHOZÍHO období: prior_period_open přejde na ok +
     * close_books vynuluje P&L, takže pl_balance_before_period přejde na ok).
     * closeBooks se validuje živě ({@see failingErrorChecks}), takže staleness
     * NENÍ bezpečnostní díra — jen zavádějící zobrazení v průvodci. state() proto
     * hlásí precheck_stale živým přepočtem error-kontrol proti uloženému snímku;
     * FE pak vyzve k opětovnému spuštění prechecku místo zobrazení starých chyb.
     *
     * @param array<string,mixed> $period
     * @param array<string,array<string,mixed>> $steps
     */
    private function precheckStale(int $supplierId, array $period, array $steps): bool
    {
        $precheck = $steps['precheck'] ?? null;
        if ($precheck === null || ($precheck['status'] ?? null) !== 'done') {
            return false;
        }
        $payload = $precheck['payload'] ?? null;
        if (!is_array($payload) || !isset($payload['checks']) || !is_array($payload['checks'])) {
            return false;
        }
        $snapshot = [];
        foreach ($payload['checks'] as $c) {
            if (is_array($c) && isset($c['key'])) {
                $snapshot[(string) $c['key']] = [
                    'ok' => (bool) ($c['ok'] ?? false),
                    'severity' => (string) ($c['severity'] ?? 'error'),
                ];
            }
        }
        foreach ($this->buildErrorChecks($supplierId, $period) as $c) {
            $key = (string) $c['key'];
            $severity = (string) ($c['severity'] ?? 'error');
            $prev = $snapshot[$key] ?? null;

            // Porovnává se i SEVERITA, nejen `ok`. Dřív se braly jen kontroly, které jsou
            // právě teď 'error' — jenže když se kontrola z 'error' přepne na 'warning'
            // (inventory_unresolved u nezaložené inventarizace), starý snímek ji dál
            // ukazoval červeně a staleness se nikdy nespustila. Uživatel pak viděl chybu,
            // která podle živého kódu chybou není, a nedozvěděl se, že stačí kontroly
            // spustit znovu.
            if ($prev === null) {
                if ($severity === 'error') {
                    return true;
                }
                continue;
            }
            if ($prev['ok'] !== (bool) $c['ok'] || $prev['severity'] !== $severity) {
                return true;
            }
        }
        return false;
    }

    /**
     * Celoroční kontroly — sdílené pro precheck i inline gate closeBooks. Severita je
     * u většiny natvrdo 'error'; u některých se určuje za běhu (inventory_unresolved),
     * proto konzumenti MUSÍ filtrovat podle `severity`, ne jen podle `ok`.
     *
     * @param array<string,mixed> $period
     * @return list<array{key:string, severity:'error'|'warning', ok:bool, value:mixed}>
     */
    private function buildErrorChecks(int $supplierId, array $period): array
    {
        $periodId = (int) $period['id'];
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];
        $checks = [];

        $prev = $this->previousPeriod($supplierId, $startsOn);
        $prevOk = $prev === null || in_array($prev['status'], ['closed', 'approved'], true);
        $checks[] = [
            'key' => 'prior_period_open',
            'severity' => 'error',
            'ok' => $prevOk,
            'value' => $prev === null ? null : ['fiscal_year' => $prev['fiscal_year'], 'status' => $prev['status']],
        ];

        $plBefore = round($this->closing->plBalanceBefore($supplierId, $startsOn), 2);
        $checks[] = [
            'key' => 'pl_balance_before_period',
            'severity' => 'error',
            'ok' => abs($plBefore) < 0.005,
            'value' => ['balance' => $plBefore],
        ];

        $drafts = $this->closing->draftsInPeriod($supplierId, $startsOn, $endsOn);
        $checks[] = [
            'key' => 'drafts_in_period',
            'severity' => 'error',
            'ok' => $drafts === [],
            'value' => ['count' => count($drafts), 'ids' => $drafts],
        ];

        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side = \'debit\' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND e.posted_at IS NOT NULL'
        );
        $stmt->execute([$supplierId, $periodId]);
        $journalDiff = round((float) $stmt->fetchColumn(), 2);
        $checks[] = [
            'key' => 'journal_unbalanced',
            'severity' => 'error',
            'ok' => abs($journalDiff) < 0.005,
            'value' => ['difference' => $journalDiff],
        ];

        $balance431 = round($this->closing->accountBalance($supplierId, '431', $endsOn), 2);
        $checks[] = [
            'key' => 'vh_431_undistributed',
            'severity' => 'error',
            'ok' => abs($balance431) < 0.005,
            'value' => ['account' => '431', 'balance' => $balance431],
        ];

        // EP-6: inventarizace rozvahových účtů (§29–30 ZoÚ). Rozlišujeme dva stavy:
        //  - inventarizace vůbec nezaložená → jen 'warning' (upozornění na zákonnou
        //    povinnost), NEBLOKUJE uzavření — firma ji může vést mimo systém;
        //  - rozdělaná inventarizace (exists=true) s nedokončeným stavem nebo nevyřešenými
        //    rozdíly → 'error', blokuje close_books přes failingErrorChecks.
        $inventory = $this->balanceInventory->inventoryStatus($supplierId, $periodId);
        // Důvod se posílá VÝSLOVNĚ. Kontrola dosud vracela jen `unresolved_count`, takže
        // u nezaložené inventarizace svítilo „nevyřešeno: 0" — číslo, které popírá samo
        // hlášení a uživateli neřekne, že má jít inventarizaci vůbec založit.
        $inventoryReason = $inventory['ok']
            ? 'ok'
            : (!$inventory['exists'] ? 'not_started' : (!$inventory['completed'] ? 'not_completed' : 'unresolved'));
        $checks[] = [
            'key' => 'inventory_unresolved',
            'severity' => $inventory['exists'] ? 'error' : 'warning',
            'ok' => $inventory['ok'],
            'value' => [
                'reason' => $inventoryReason,
                'exists' => $inventory['exists'],
                'completed' => $inventory['completed'],
                'unresolved_count' => $inventory['unresolved_count'],
            ],
        ];

        return $checks;
    }

    /**
     * @param array<string,mixed> $period
     * @return list<string> klíče selhaných error kontrol
     */
    private function failingErrorChecks(int $supplierId, array $period): array
    {
        $failing = [];
        foreach ($this->buildErrorChecks($supplierId, $period) as $check) {
            // Severity se u některých kontrol určuje za běhu (např. inventory_unresolved
            // je jen 'warning', dokud inventarizace vůbec není založená) — blokovat smí
            // pouze 'error', jinak by upozornění fungovalo jako tvrdá závora.
            if (($check['severity'] ?? 'error') === 'error' && !$check['ok']) {
                $failing[] = $check['key'];
            }
        }
        return $failing;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    /**
     * Vlastní/vnořená transakce (vzor PostingService) + mapování PostingException
     * na ClosingException (kód a status se propagují).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function tx(callable $work)
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
        } catch (PostingException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new ClosingException($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * FOR UPDATE zámek na řádku období + kontrola row_version (R4).
     *
     * @return array<string,mixed>
     */
    private function lockPeriod(int $supplierId, int $periodId, int $rowVersion): array
    {
        $period = $this->periods->findForUpdate($periodId, $supplierId);
        if ($period === null) {
            throw new ClosingException('not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ((int) $period['row_version'] !== $rowVersion) {
            throw new ClosingException(
                'version_conflict',
                'Období bylo mezitím změněno (row_version ' . $period['row_version'] . ' ≠ ' . $rowVersion . ') — načti stav znovu.',
                409,
            );
        }
        return $period;
    }

    /**
     * CAS bump row_version bez změny stavu — zámek mutací, které stav nemění (R4).
     */
    private function bumpVersion(int $supplierId, int $periodId, int $rowVersion): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE accounting_periods SET row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND row_version = ?'
        );
        $stmt->execute([$periodId, $supplierId, $rowVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new ClosingException('version_conflict', 'Období bylo mezitím změněno — načti stav znovu.', 409);
        }
    }

    /**
     * @param array{user_id?:?int} $meta
     */
    private function casStatus(int $supplierId, int $periodId, string $status, int $rowVersion, array $meta): void
    {
        if (!$this->periods->setStatusCas($periodId, $supplierId, $status, $rowVersion, $meta['user_id'] ?? null)) {
            throw new ClosingException('version_conflict', 'Období bylo mezitím změněno — načti stav znovu.', 409);
        }
    }

    /**
     * EP-4: zapíše workflow auditní událost UVNITŘ probíhající transakce (volat jen
     * z tx() callbacku, před jeho návratem). ActivityLogger sdílí tutéž Connection/PDO,
     * takže INSERT je součástí téže tx jako účetní mutace — selže-li audit, tx() rollbackne
     * i mutaci. Idempotence plyne z atomicity: retry po rollbacku spustí celou tx znovu,
     * takže nikdy nevznikne účetní změna bez události ani duplicitní událost.
     *
     * @param array<string,mixed> $payload
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     */
    private function audit(int $supplierId, string $action, int $periodId, array $payload, array $meta): void
    {
        $this->activity->log(
            $action,
            $meta['user_id'] ?? null,
            'accounting_period',
            $periodId,
            $payload,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
            $supplierId,
        );
    }

    /**
     * @param array<string,mixed> $period
     * @param list<string> $allowed
     */
    private function assertStatus(array $period, array $allowed): void
    {
        if (!in_array((string) $period['status'], $allowed, true)) {
            throw new ClosingException(
                'invalid_status_transition',
                'Operace vyžaduje stav období ' . implode('/', $allowed) . ' (aktuální: ' . $period['status'] . ').',
            );
        }
    }

    /**
     * Všech 7 kroků (chybějící řádky = pending), payload dekódovaný.
     *
     * @return array<string, array{step_key:string, status:string, payload:?array<string,mixed>, note:?string, done_at:?string, done_by:?int}>
     */
    private function stepsMap(int $supplierId, int $periodId): array
    {
        $map = [];
        foreach (self::STEP_KEYS as $key) {
            $map[$key] = ['step_key' => $key, 'status' => 'pending', 'payload' => null, 'note' => null, 'done_at' => null, 'done_by' => null];
        }
        foreach ($this->closing->steps($periodId) as $row) {
            $key = (string) $row['step_key'];
            if (!isset($map[$key])) {
                continue;
            }
            $payload = $row['payload'] ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            $map[$key] = [
                'step_key' => $key,
                'status' => (string) $row['status'],
                'payload' => is_array($payload) ? $payload : null,
                'note' => $row['note'] ?? null,
                'done_at' => $row['done_at'] ?? null,
                'done_by' => isset($row['done_by']) && $row['done_by'] !== null ? (int) $row['done_by'] : null,
            ];
        }
        return $map;
    }

    /**
     * @param array<string, array{status:string}> $steps
     * @param list<string> $keys
     */
    private function stepsComplete(array $steps, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!in_array($steps[$key]['status'] ?? 'pending', ['done', 'skipped'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Krok „Zásoby" je povinný jen pro firmu se zapnutým skladem A podvojným
     * účetnictvím (SKLAD §3.4) — jinak se v gatingu close_books nevyžaduje.
     */
    private function stockStepRequired(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false
            && (int) ($row['stock_enabled'] ?? 0) === 1
            && (string) ($row['accounting_mode'] ?? '') === 'double_entry';
    }

    /**
     * Odpisový krok dává smysl jen tehdy, když v období vůbec byl odpisovaný majetek.
     * Firma bez majetku (nebo rok před jeho pořízením) jinak musí ručně „přeskočit"
     * krok, který nemá co dělat — a skip se v auditní stopě čte jako „rozhodli jsme se
     * odpisy nezaúčtovat", ne jako „nebylo co odepisovat".
     *
     * Rozhoduje stav majetku V OBDOBÍ, ne dnes: `status = 'in_use'` je aktuální příznak,
     * takže by rok 2024 vyžadoval odpisy kvůli majetku zařazenému až v 2025 (a naopak
     * vyřazený majetek by z minulých let zmizel). Proto se testuje překryv intervalu
     * zařazení/vyřazení s obdobím — stejně jako kontrola `depreciation_missing`.
     *
     * @param array<string,mixed> $period
     */
    private function depreciationStepRequired(int $supplierId, array $period): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM assets a
              WHERE a.supplier_id = ?
                AND a.accumulated_account_code IS NOT NULL
                AND a.put_into_use_date IS NOT NULL
                AND a.put_into_use_date <= ?
                AND (a.disposal_date IS NULL OR a.disposal_date >= ?)
              LIMIT 1'
        );
        $stmt->execute([$supplierId, (string) $period['ends_on'], (string) $period['starts_on']]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Kroky vyžadované done/skipped před close_books — 'depreciation' a 'stock'
     * jen jsou-li relevantní.
     *
     * @param array<string,mixed> $period
     * @return list<string>
     */
    private function preCloseStepKeys(int $supplierId, array $period): array
    {
        $keys = ['precheck', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax'];
        if ($this->depreciationStepRequired($supplierId, $period)) {
            $keys[] = 'depreciation';
        }
        if ($this->stockStepRequired($supplierId)) {
            $keys[] = 'stock';
        }
        return $keys;
    }

    private function supplierTaxpayerType(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * MD/D pár řádků dle kontace pro nenulovou částku (haléřové porovnání);
     * nula → žádné řádky (prázdné zápisy se neúčtují). Záporná hodnota = rozbitá
     * skladová evidence (non-negative invariant A3) → 500, nic se nezapisuje.
     *
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    private function stockRuleLines(int $supplierId, string $ruleKey, float $amount): array
    {
        $cents = (int) round($amount * 100.0);
        if ($cents < 0) {
            throw new ClosingException(
                'stock_negative_value',
                'Záporná hodnota zásob pro kontaci "' . $ruleKey . '" (' . $amount . ' Kč) — skladová evidence není konzistentní.',
                500,
            );
        }
        if ($cents === 0) {
            return [];
        }
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        if ($rule === null) {
            throw new ClosingException('posting_rule_missing', 'Kontace "' . $ruleKey . '" není naseedovaná v posting_rules.');
        }
        $debit = $rule['debit_account_code'] ?? null;
        $credit = $rule['credit_account_code'] ?? null;
        if ($debit === null || $credit === null) {
            throw new ClosingException(
                'posting_rule_missing',
                'Kontace "' . $ruleKey . '" nemá pevný pár MD/D — uzávěrka zásob vyžaduje obě strany.',
            );
        }
        $amt = $cents / 100;
        return [
            ['account_code' => (string) $debit, 'side' => 'debit', 'amount' => $amt],
            ['account_code' => (string) $credit, 'side' => 'credit', 'amount' => $amt],
        ];
    }

    /**
     * Bezprostředně předcházející období (poslední s ends_on < starts_on) — R5.
     *
     * @return array<string,mixed>|null
     */
    private function previousPeriod(int $supplierId, string $startsOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status, row_version
               FROM accounting_periods
              WHERE supplier_id = ? AND ends_on < ?
              ORDER BY ends_on DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $startsOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['fiscal_year'] = (int) $row['fiscal_year'];
        return $row;
    }

    /**
     * Najde/založí následující období dle R5: starts_on = ends_on + 1 den, stejná
     * délka roku; existující nenavazující řada → 422 period_gap.
     *
     * @param array<string,mixed> $period
     * @return array<string,mixed>
     */
    private function ensureNextPeriod(int $supplierId, array $period): array
    {
        $endsOn = (string) $period['ends_on'];
        $next = $this->periods->nextPeriod($supplierId, $endsOn);
        if ($next !== null) {
            return $next;
        }

        $nextStart = (new \DateTimeImmutable($endsOn))->modify('+1 day');
        $nextEnd = $nextStart
            ->add(new \DateInterval('P1Y'))
            ->sub(new \DateInterval('P1D'));
        $startStr = $nextStart->format('Y-m-d');
        $endStr = $nextEnd->format('Y-m-d');

        // Existuje pozdější období, které nenavazuje přesně → díra v řadě (R5).
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ? AND starts_on > ?'
        );
        $stmt->execute([$supplierId, $endsOn]);
        $laterStart = $stmt->fetchColumn();
        if ($laterStart !== false && $laterStart !== null) {
            throw new ClosingException(
                'period_gap',
                'Následující období nenavazuje na ' . $endsOn . ' (nejbližší začíná ' . $laterStart . ').',
            );
        }
        if ($this->periods->overlapping($supplierId, $startStr, $endStr, null) !== null) {
            throw new ClosingException('period_overlap', 'Nové období ' . $startStr . '–' . $endStr . ' se překrývá s existujícím.');
        }

        $fiscalYear = (int) $nextStart->format('Y');
        $newId = $this->periods->create($supplierId, $fiscalYear, $startStr, $endStr);
        $created = $this->periods->findById($supplierId, $newId);
        if ($created === null) {
            throw new ClosingException('operation_failed', 'Následující období se nepodařilo založit.', 500);
        }
        return $created;
    }

    /**
     * Zápis dle source klíče vč. řádků s kódem/typem účtu (JOIN chart_of_accounts).
     * Interní čtení pro zrcadlení closing → opening a FX storno.
     *
     * @return array<string,mixed>|null
     */
    private function findEntryWithLines(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $entry = $this->journal->findBySource($supplierId, $sourceType, $sourceId);
        if ($entry === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.account_id, l.side, l.amount, l.currency_code, l.fx_rate, l.amount_foreign,
                    l.cost_center, l.line_no, a.account_code, a.name AS account_name, a.account_type
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.line_no ASC, l.id ASC'
        );
        $stmt->execute([(int) $entry['id'], $supplierId]);
        $entry['lines'] = array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['account_id'] = (int) $r['account_id'];
            $r['amount'] = (float) $r['amount'];
            $r['fx_rate'] = $r['fx_rate'] === null ? null : (float) $r['fx_rate'];
            $r['amount_foreign'] = $r['amount_foreign'] === null ? null : (float) $r['amount_foreign'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $entry;
    }

    /**
     * Všechny kódy řádků existují v osnově firmy — jinak 422 missing_account
     * (přívětivější než unknown_account z PostingService uprostřed zápisu).
     *
     * @param list<array{account_code?:string}> $lines
     */
    private function assertKnownCodes(int $supplierId, array $lines): void
    {
        $codeMap = $this->accounts->codeToIdMap($supplierId);
        foreach ($lines as $line) {
            $code = (string) ($line['account_code'] ?? '');
            if ($code !== '' && !isset($codeMap[$code])) {
                throw new ClosingException(
                    'missing_account',
                    'Účet ' . $code . ' není v účtové osnově firmy — doplň ho před uzávěrkou.',
                );
            }
        }
    }
}
