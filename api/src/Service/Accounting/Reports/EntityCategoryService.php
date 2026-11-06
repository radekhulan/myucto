<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\EntityCategoryHistoryRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Kategorizace účetní jednotky (§1b a §1e ZoÚ, R9–R11):
 * kategorie = nejnižší, u níž ÚJ nepřekračuje ≥ 2 ze 3 kritérií (aktiva netto /
 * čistý obrat / průměrný počet zaměstnanců). Dle §1e odst. 2 se
 * kategorie mění až od počátku období NÁSLEDUJÍCÍHO po dvou po sobě jdoucích
 * rozvahových dnech potvrzujících překročení téže hranice kategorie. Bez
 * historie se používá raw stav běžného období. Admin override `statement_scope_override`
 * vyhrává. Default (žádná data) = mikro.
 *
 * Volá JEN repositories + StatementMapper (ne FinancialStatementService) —
 * žádná kruhová závislost (§2.8).
 */
final class EntityCategoryService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly StatementDefinitionRepository $definitions,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly AccountingPeriodRepository $periods,
        private readonly StatementMapper $mapper,
        private readonly EntityCategoryHistoryRepository $history,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly StatementMapResolver $maps,
        private readonly StatementFrameworkResolver $frameworkResolver,
        private readonly Statement504Service $statements504,
        private readonly NetTurnoverReadService $turnover,
    ) {}

    /**
     * @return array{
     *     category: string, raw_current: string, raw_previous: ?string,
     *     criteria: array{assets_net: float, net_turnover: float, employees: int},
     *     thresholds: array<string, array{assets_net: float, net_turnover: float, employees: int}>,
     *     scope: string, scope_override: ?string
     * }
     */
    public function evaluate(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $settings = $this->settings->get($supplierId);
        $employees = (int) ($settings['avg_employees'] ?? 0); // R10: NULL → 0

        $criteria = $this->criteriaFor($supplierId, $period);
        $criteria['employees'] = $employees;
        $thresholds = $this->thresholdsForPeriod($period);
        $rawCurrent = $this->classify($criteria, $thresholds, $period);

        $closedRaws = $this->rawsForClosedPeriods($supplierId, $period, $employees);
        $rawPrevious = $closedRaws === [] ? null : $closedRaws[count($closedRaws) - 1];

        $category = self::categoryFromHistory($rawCurrent, $closedRaws);

        $scopeOverride = $settings['statement_scope_override'];
        $scope = $scopeOverride ?? match ($category) {
            'micro' => 'micro',
            'small' => 'small',
            default => 'full',
        };
        // R18 (Epic F4): povinný audit §20 ZoÚ → plný rozsah výkazů (§3a vyhl.
        // 500/2002) — má přednost i před zmenšujícím override.
        if (!empty($settings['statutory_audit'])) {
            $scope = 'full';
        }

        return [
            'category'       => $category,
            'raw_current'    => $rawCurrent,
            'raw_previous'   => $rawPrevious,
            'criteria'       => [
                'assets_net'   => $criteria['assets_net'],
                'net_turnover' => $criteria['net_turnover'],
                'employees'    => $employees,
            ],
            'thresholds'     => $thresholds,
            'scope'          => $scope,
            'scope_override' => $scopeOverride,
        ];
    }

    /** @param list<string> $closedRaws Chronologické kategorie dle limitů jednotlivých období. */
    public static function categoryFromHistory(string $rawCurrent, array $closedRaws): string
    {
        if ($closedRaws === []) {
            return $rawCurrent;
        }
        $categories = ['micro', 'small', 'medium', 'large'];
        $ranks = array_map(static function (string $raw) use ($categories): int {
            $rank = array_search($raw, $categories, true);
            if ($rank === false) {
                throw new \InvalidArgumentException('Unknown entity category: ' . $raw);
            }
            return $rank;
        }, $closedRaws);
        $effective = $ranks[0];
        for ($i = 1, $n = count($ranks); $i < $n; $i++) {
            // §1e odst. 2: každá hranice musí být překročena (nebo nepřekročena)
            // v obou obdobích; rozdílné raw kategorie mohou potvrdit společnou hranici.
            $lower = min($ranks[$i - 1], $ranks[$i]);
            $upper = max($ranks[$i - 1], $ranks[$i]);
            if ($effective < $lower) {
                $effective = $lower;
            } elseif ($effective > $upper) {
                $effective = $upper;
            }
        }
        return $categories[$effective];
    }

    /**
     * Rozsah výkazů pro scope='auto' (R11/R12): mikro→'micro', malá→'small',
     * střední+velká→'full'; override vyhrává.
     */
    public function statementScope(int $supplierId, int $periodId): string
    {
        return $this->evaluate($supplierId, $periodId)['scope'];
    }

    /**
     * Raw kategorie všech uzavřených období před hodnoceným obdobím,
     * chronologicky (nejstarší první). Zaměstnanci bez historie (R10) —
     * použije se aktuální hodnota pro všechna období.
     *
     * @param array<string,mixed> $period
     * @return list<string>
     */
    private function rawsForClosedPeriods(int $supplierId, array $period, int $employees): array
    {
        $previous = [];
        $cursor = $period;
        while (count($previous) < 30
            && ($p = $this->ledger->previousPeriod($supplierId, (string) $cursor['starts_on'])) !== null) {
            $previous[] = $p;
            $cursor = $p;
        }

        $raws = [];
        foreach (array_reverse($previous) as $p) {
            // D5: uzavřené období se zmraženým řádkem se čte z entity_category_history
            // (výkon + zmražení zaměstnanci/kritéria); období bez záznamu (neuzavřené
            // nebo z doby před D5) fallbackuje na přepočet — zachováno current behavior.
            $frozen = $this->history->findRaw($supplierId, (int) $p['id']);
            if ($frozen !== null) {
                $raws[] = $frozen;
                continue;
            }
            $criteria = $this->criteriaFor($supplierId, $p);
            $criteria['employees'] = $employees;
            $raws[] = $this->classify($criteria, $this->thresholdsForPeriod($p), $p);
        }

        return $raws;
    }

    /**
     * D5 (audit 2026-07): zmrazí kritéria kategorizace právě uzavíraného období do
     * entity_category_history (volá ClosingService::closeBooks). Reuse criteriaFor +
     * classify — stejná logika jako evaluate(). Idempotentní (upsert): re-run uzávěrky
     * jen přepíše řádek.
     */
    public function freeze(int $supplierId, int $periodId): void
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $settings = $this->settings->get($supplierId);
        $employees = (int) ($settings['avg_employees'] ?? 0);

        $criteria = $this->criteriaFor($supplierId, $period);
        $criteria['employees'] = $employees;
        $raw = $this->classify($criteria, $this->thresholdsForPeriod($period), $period);

        $this->history->upsert(
            $supplierId,
            $periodId,
            $criteria['assets_net'],
            $criteria['net_turnover'],
            $employees,
            $raw,
        );
    }

    /**
     * Je kategorie ÚJ pro období zmražená (existuje řádek entity_category_history)?
     * EP-14: zákonné schválení závěrky se o tento stav opírá — schválit lze až po
     * uložení historické kategorie.
     */
    public function isFrozen(int $supplierId, int $periodId): bool
    {
        return $this->history->findRaw($supplierId, $periodId) !== null;
    }

    /**
     * Zajistí zmraženou historickou kategorii období (idempotentně). Když už zmražená
     * je, nedělá nic; jinak ji dozmrazí ({@see freeze}). Při selhání propaguje výjimku —
     * volající (schválení závěrky) ji smí použít jako tvrdou bránu (§17/7).
     */
    public function ensureFrozen(int $supplierId, int $periodId): void
    {
        if ($this->isFrozen($supplierId, $periodId)) {
            return;
        }
        $this->freeze($supplierId, $periodId);
    }

    /**
     * Kritéria za jedno období: aktiva netto (mapa rozvahy + StatementMapper,
     * bez volání FinancialStatementService) a čistý obrat dle R9.
     *
     * @param array<string,mixed> $period
     * @return array{assets_net: float, net_turnover: float}
     */
    private function criteriaFor(int $supplierId, array $period): array
    {
        $context = $this->frameworkResolver->accountingContext($supplierId, (int) $period['id'], max($period['starts_on'], min($period['ends_on'], date('Y-m-d'))));
        if ($context['framework'] === 'cz_504') {
            // Kritéria 504 již obsahují roční obrat, stejně jako dosud uložená historie.
            return $this->statements504->criteria($supplierId, $period, $context['as_of']) + ['turnover_months' => 12];
        }
        $asOf = min((string) $period['ends_on'], date('Y-m-d'));
        $version = $this->definitions->findVersion('balance_sheet', $asOf);
        if ($version === null) {
            throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování rozvahy.');
        }
        $rows = $this->definitions->rows((int) $version['id']);
        // Stejná sloučená mapa jako rozvaha (globální + výjimky firmy) — aktiva netto pro
        // kategorii ÚJ se nesmí počítat z jiného zařazení než rozvaha sama.
        $map  = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);

        // D2 (H9): saldové účty (balance_condition != 'any') se nettují per analytika,
        // ne přes syntetiku — aktiva netto pak nezahrnou kompenzovaný kontokorent. N1 guard
        // (fail-loud na nepárový prefix) je sdílený se StatementMapper (§2.8, žádná duplicita).
        $splitCodes = $this->mapper->noCompensationPrefixes($map);

        // Analytické prefixy mapy (311D, výjimka na 365.100…) musí dostat zůstatek své
        // analytiky zvlášť, stejně jako v rozvaze (FinancialStatementService::buildStatement).
        $balances = $this->ledger->syntheticBalances(
            $supplierId,
            $asOf,
            (string) $period['starts_on'],
            $splitCodes,
            $this->mapper->analyticPrefixes($map),
        );
        $mapped   = $this->mapper->map($rows, $map, $balances);
        // Aktiva netto stejná jako v rozvaze i při souhrnném vykázání daní vůči FÚ (§ 58/2).
        if ($this->settings->taxAuthorityOffsetAppliesIn($supplierId, (int) $period['fiscal_year'])) {
            $mapped = TaxAuthorityOffset::apply($rows, $mapped)['mapped'];
        }

        return [
            'assets_net'   => $this->mapper->assetsNet($rows, $mapped),
            'net_turnover' => $this->turnover->forPeriod($supplierId, $period),
        ];
    }

    /**
     * Kategorie = nejnižší, u níž ÚJ NEpřekračuje ≥ 2 ze 3 kritérií (R11).
     *
     * @param array{assets_net: float, net_turnover: float, employees: int} $criteria
     */
    private function classify(array $criteria, array $thresholds, array $period): string
    {
        return self::classifyCriteria($criteria, $thresholds, $criteria['turnover_months'] ?? EntityCategoryPeriodPolicy::commencedMonths(
            (string) $period['starts_on'],
            (string) $period['ends_on'],
        ));
    }

    public static function classifyCriteria(array $criteria, array $thresholds, int $turnoverMonths = 12): string
    {
        if ($turnoverMonths < 1) {
            throw new \InvalidArgumentException('Počet započatých měsíců musí být kladný.');
        }
        foreach ($thresholds as $category => $limits) {
            $exceeded = 0;
            if ($criteria['assets_net'] > $limits['assets_net']) {
                $exceeded++;
            }
            if ($turnoverMonths === 12
                ? $criteria['net_turnover'] > $limits['net_turnover']
                : EntityCategoryPeriodPolicy::turnoverExceeds($criteria['net_turnover'], $limits['net_turnover'], $turnoverMonths)) {
                $exceeded++;
            }
            if ($criteria['employees'] > $limits['employees']) {
                $exceeded++;
            }
            if ($exceeded < 2) {
                return $category;
            }
        }
        return 'large';
    }

    /** @param array<string,mixed> $period */
    private function thresholdsForPeriod(array $period): array
    {
        $year = EntityCategoryPeriodPolicy::thresholdYear((string) $period['starts_on'], (string) $period['ends_on']);
        return (array) $this->taxConstants->forYear($year)['entity_category_thresholds'];
    }
}
