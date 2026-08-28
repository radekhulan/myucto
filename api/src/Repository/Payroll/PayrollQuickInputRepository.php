<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\PayrollMonthlyFundService;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollAchievedWage;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollQuickSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeService;
use PDO;

final class PayrollQuickInputRepository
{
    /**
     * Tvrdý strop rychlého zadání. Řádek je pracovní poměr, takže seznam roste
     * lineárně s velikostí firmy — a ke každému řádku se ještě dopočítávají
     * vstupy a opakující se složky.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    public const CARD_PAGE_LIMIT = 25;

    public const CARD_STATUS_FILTERS = ['active', 'away', 'attention', 'all'];

    private const BASE_CODE = 'MZDA_MESICNI';

    /**
     * Dosažená mzda za hodiny práce přesčas (§ 114 odst. 1, první polovina nároku).
     *
     * Ne `MZDA_MESICNI`: měsíční mzda pokrývá fond pracovní doby, kdežto přesčas
     * je práce NAD fond a platí se navíc. Vlastní řádek navíc drží počet hodin
     * v `quantity_milliunits`, takže z mzdového listu je vidět sazba i rozsah.
     */
    private const HOURLY_CODE = 'MZDA_HODINOVA';

    /**
     * Volná sběrná složka. Zůstává pro režim „celková částka", kde uživatel
     * zadává jedno číslo a rozpad na zákonné nároky netvrdí. Pro přesčas zadaný
     * HODINAMI se od W19 nepoužívá — viz {@see self::PREMIUM_CODE}.
     */
    private const OVERTIME_CODE = 'PREMIE_PRIPLATKY';

    /**
     * Příplatková polovina nároku podle § 114 odst. 1.
     *
     * Dokud padala do `PREMIE_PRIPLATKY`, nešlo z mzdového listu doložit, KTERÝ
     * zákonný nárok byl uspokojen a v jaké výši — a přesně to po zaměstnavateli
     * chce § 142 odst. 5 ZP.
     */
    private const PREMIUM_CODE = 'PRIPLATEK_PRESCAS';

    private const BONUS_CODE = 'ODMENA';
    private const EXTERNAL_PREFIX = 'quick-monthly:';
    private const SAVE_SAVEPOINT = 'payroll_quick_input_field';

    /**
     * Prefix `external_id` mzdového vstupu, který vznikl materializací příplatku
     * ze schválené docházky. Shodný s
     * {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer::EXTERNAL_PREFIX}
     * — duplikovat se musí, protože repozitář na materializátoru nezávisí
     * a závislost opačným směrem už existuje.
     */
    private const TIME_SURCHARGE_PREFIX = 'surcharge:';

    /** Předpona pole rychlého zadání, které nese jeden druh příplatku. */
    public const SURCHARGE_FIELD_PREFIX = 'surcharge_';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollRecurringAmountCalculator $recurringAmounts,
        private readonly PayrollMonthlyFundService $fund,
        private readonly PayrollSurchargeService $surcharges,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollQuickSurchargeCalculator $quickSurcharges,
        private readonly PayrollSurchargeClaimRepository $surchargeClaims,
    ) {}

    /**
     * Mzdové složky, které rychlé zadání spravuje — včetně těch příplatkových.
     *
     * @return list<string>
     */
    private static function managedCodes(): array
    {
        return [
            self::BASE_CODE,
            self::HOURLY_CODE,
            self::OVERTIME_CODE,
            self::PREMIUM_CODE,
            self::BONUS_CODE,
            ...array_map(
                static fn (PayrollSurchargeKind $kind): string => $kind->componentCode(),
                PayrollSurchargeKind::quickManualEntry(),
            ),
        ];
    }

    /**
     * Jeden měsíc rychlého zadání, stránkovaně.
     *
     * `$employmentId` zúží měsíc na jeden pracovní vztah. Filtr padá do TÉHOŽ
     * dotazu jako stránkování — dokud zužoval prohlížeč nad načtenou stránkou,
     * vztah z jiné strany se tiše neprojevil a zúžený seznam byl prázdný, aniž
     * by to kdokoli řekl. Zúžení mění i `total`, takže pager mluví o zúženém
     * seznamu, ne o celém měsíci.
     *
     * @return array{period:string,items:list<array<string,mixed>>,total:int}
     */
    public function month(
        int $supplierId,
        string $period,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
    ): array {
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }
        return $this->collect($supplierId, $period, null, $limit, $offset, $employmentId);
    }

    /**
     * Stránka karet pro mzdový přehled.
     *
     * Výpočet rychlých vstupů zůstává jediným zdrojem částek i blokátorů. Celý
     * měsíc se projde po omezených dávkách, aby souhrn nelhal jen podle první
     * stránky; do odpovědi se ale vrátí nejvýš 25 karet. Úplné záznamy absencí
     * se dotáhnou až pro vztahy výsledné stránky.
     *
     * @return array{
     *   period:string,items:list<array<string,mixed>>,total:int,company_headcount:int,
     *   summary:array{people:int,gross_preview_minor:int,away:int,attention:int}
     * }
     */
    public function employeeCards(
        int $supplierId,
        string $period,
        int $limit = self::CARD_PAGE_LIMIT,
        int $offset = 0,
        string $search = '',
        string $status = 'active',
    ): array {
        if (!in_array($status, self::CARD_STATUS_FILTERS, true)) {
            throw new \InvalidArgumentException('Neplatný filtr stavu zaměstnanců.');
        }
        $limit = max(1, min(self::CARD_PAGE_LIMIT, $limit));
        $offset = max(0, $offset);

        $all = [];
        $cursor = 0;
        do {
            $batch = $this->collect(
                $supplierId,
                $period,
                null,
                self::LIST_MAX_LIMIT,
                $cursor,
            );
            array_push($all, ...$batch['items']);
            $cursor += count($batch['items']);
        } while ($cursor < $batch['total'] && $batch['items'] !== []);

        $people = [];
        $awayPeople = [];
        $attentionPeople = [];
        $gross = 0;
        foreach ($all as $item) {
            $employeeId = PayrollTimeValue::int($item['employee_id'] ?? null, 'employee_id');
            $people[$employeeId] = true;
            $gross += PayrollTimeValue::int(
                $item['gross_preview_minor'] ?? null,
                'gross_preview_minor',
            );
            if (($item['away_in_month'] ?? false) === true) {
                $awayPeople[$employeeId] = true;
            }
            if (self::cardNeedsAttention($item)) {
                $attentionPeople[$employeeId] = true;
            }
        }

        $needle = self::normalizedSearch($search);
        $filtered = array_values(array_filter(
            $all,
            static function (array $item) use ($needle, $status): bool {
                if ($needle !== '') {
                    $haystack = self::normalizedSearch(
                        PayrollTimeValue::string($item['full_name'] ?? null, 'full_name')
                        . ' '
                        . PayrollTimeValue::string(
                            $item['employment_code'] ?? null,
                            'employment_code',
                        ),
                    );
                    if (!str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                return match ($status) {
                    'active' => ($item['effective_status'] ?? null) === 'active'
                        && ($item['suspended_in_month'] ?? false) !== true,
                    'away' => ($item['away_in_month'] ?? false) === true,
                    'attention' => self::cardNeedsAttention($item),
                    'all' => true,
                };
            },
        ));

        $items = array_slice($filtered, $offset, $limit);
        $absenceByEmployment = $this->cardAbsences(
            $supplierId,
            $period . '-01',
            (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d'),
            array_map(
                static fn (array $item): int => PayrollTimeValue::int(
                    $item['employment_id'] ?? null,
                    'employment_id',
                ),
                $items,
            ),
        );
        foreach ($items as &$item) {
            $employmentId = PayrollTimeValue::int(
                $item['employment_id'] ?? null,
                'employment_id',
            );
            $item['absences'] = $absenceByEmployment[$employmentId] ?? [];
        }
        unset($item);

        return [
            'period' => $period,
            'items' => $items,
            'total' => count($filtered),
            'company_headcount' => $this->companyHeadcount($supplierId),
            'summary' => [
                'people' => count($people),
                'gross_preview_minor' => $gross,
                'away' => count($awayPeople),
                'attention' => count($attentionPeople),
            ],
        ];
    }

    /**
     * Táž data jen pro vyjmenované pracovní vztahy. Ukládání potřebuje ověřit
     * PRÁVĚ ty vztahy, které přišly v požadavku — kdyby si k tomu bralo
     * stránku měsíce, uložení kohokoli za koncem první stránky by skončilo
     * hláškou „vztah nepatří této firmě".
     *
     * @param list<int> $employmentIds
     * @return array{period:string,items:list<array<string,mixed>>,total:int}
     */
    private function forEmployments(
        int $supplierId,
        string $period,
        array $employmentIds,
    ): array {
        if ($employmentIds === []) {
            return ['period' => $period, 'items' => [], 'total' => 0];
        }

        // Limit ani offset se u výčtu vztahů neuplatní — rozsah je dán
        // seznamem, který přišel v požadavku.
        return $this->collect(
            $supplierId,
            $period,
            $employmentIds,
            self::LIST_DEFAULT_LIMIT,
            0,
        );
    }

    /**
     * @param list<int>|null $employmentIds `null` = stránka celého měsíce;
     *        u výčtu vztahů se `$limit`/`$offset` neuplatní
     * @param int|null $focusEmploymentId zúžení stránky měsíce na jeden vztah;
     *        u výčtu vztahů nedává smysl a neuplatní se
     * @return array{period:string,items:list<array<string,mixed>>,total:int}
     */
    private function collect(
        int $supplierId,
        string $period,
        ?array $employmentIds,
        int $limit,
        int $offset,
        ?int $focusEmploymentId = null,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
        $year = (int) substr($period, 0, 4);
        $this->components->list($supplierId, $periodStart);

        $focusEmploymentId = $employmentIds === null ? $focusEmploymentId : null;
        $employmentFilter = $employmentIds === null
            ? ($focusEmploymentId === null ? '' : ' AND employment.id = ?')
            : ' AND employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')';

        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status,
                           EXISTS (
                               SELECT 1
                                 FROM payroll_employment_events lifecycle
                                WHERE lifecycle.supplier_id = employment.supplier_id
                                  AND lifecycle.employment_id = employment.id
                                  AND lifecycle.event_type = "status_changed"
                                  AND lifecycle.effective_on BETWEEN ? AND ?
                                  AND (
                                      lifecycle.from_status = "suspended"
                                      OR lifecycle.to_status = "suspended"
                                  )
                           ) AS suspended_in_month
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT employment.id AS employment_id, employment.employee_id,
                    employment.code AS employment_code, employment.relation_type,
                    employment.monthly_gross_minor, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employment.row_version AS employment_row_version,
                    employment.effective_status, employment.suspended_in_month,
                    employee.full_name,
                    EXISTS (
                        SELECT 1
                          FROM payroll_absences absence
                         WHERE absence.supplier_id = employment.supplier_id
                           AND absence.employment_id = employment.id
                           AND absence.status IN ("requested", "approved")
                           AND absence.date_from <= ?
                           AND absence.date_to >= ?
                    ) AS away_in_month,
                    (
                        SELECT identifier.value_masked
                          FROM payroll_person_identifiers identifier
                         WHERE identifier.supplier_id = employment.supplier_id
                           AND identifier.employee_id = employment.employee_id
                           AND identifier.identifier_type = "birth_number"
                         ORDER BY identifier.id DESC
                         LIMIT 1
                    ) AS birth_number_masked,
                    average.id AS overtime_average_snapshot_id,
                    average.row_version AS overtime_average_snapshot_version,
                    average.average_hourly_minor AS overtime_hourly_rate_minor
               FROM effective_employment employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
          LEFT JOIN (
                    SELECT ranked.*
                      FROM (
                            SELECT snapshot.*,
                                   ROW_NUMBER() OVER (
                                     PARTITION BY snapshot.supplier_id,
                                                  snapshot.employment_id,
                                                  snapshot.applicable_year,
                                                  snapshot.applicable_quarter
                                     ORDER BY snapshot.revision_no DESC, snapshot.id DESC
                                   ) AS position_no
                              FROM payroll_average_earning_snapshots snapshot
                             WHERE snapshot.status = "approved"
                               AND snapshot.support_status = "supported"
                           ) ranked
                     WHERE ranked.position_no = 1
                    ) average
                 ON average.supplier_id = employment.supplier_id
                AND average.employment_id = employment.id
                AND average.applicable_year = ?
                AND average.applicable_quarter = ?
              WHERE employment.effective_status IN ("active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)'
            . $employmentFilter
            . ' ORDER BY employee.full_name, employment.is_primary DESC, employment.id'
            . ($employmentIds === null ? ' LIMIT ? OFFSET ?' : '')
        );
        $params = [
            $periodEnd,
            $periodStart,
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodStart,
            $year,
            $quarter,
            $periodEnd,
            $periodStart,
            ...($employmentIds ?? ($focusEmploymentId === null ? [] : [$focusEmploymentId])),
        ];
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        if ($employmentIds === null) {
            $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'quick_employments');

        $total = $employmentIds === null
            ? $this->countMonth($supplierId, $periodStart, $periodEnd, $focusEmploymentId)
            : count($rows);

        // Vstupy i opakující se složky se dotahují JEN pro řádky stránky.
        // Bez toho by stránkování seznamu nic neušetřilo — dva doprovodné
        // dotazy by dál četly celý měsíc firmy.
        $employmentIdsOnPage = array_values(array_unique(array_map(
            static fn (array $row): int => PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            ),
            $rows,
        )));
        if ($employmentIdsOnPage === []) {
            return ['period' => $period, 'items' => [], 'total' => $total];
        }
        $pageFilter = ' AND %s.employment_id IN ('
            . implode(',', array_fill(0, count($employmentIdsOnPage), '?'))
            . ')';

        $inputStmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.employment_id, input.amount_minor,
                    input.quantity_milliunits, input.source_kind, input.external_id,
                    input.status, input.row_version, input.source_snapshot_json,
                    component.code AS component_code,
                    component.component_kind, component.value_kind,
                    component.tax_treatment
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.period_start = ?
                AND input.status <> "cancelled"'
            . sprintf($pageFilter, 'input')
            . ' ORDER BY input.id'
        );
        $inputStmt->execute([$supplierId, $periodStart, ...$employmentIdsOnPage]);
        $byEmployment = [];
        foreach (PayrollTimeValue::rows($inputStmt->fetchAll(PDO::FETCH_ASSOC), 'quick_inputs') as $input) {
            $byEmployment[(int) $input['employment_id']][] = $input;
        }

        $recurringStmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status,
                           EXISTS (
                               SELECT 1
                                 FROM payroll_employment_events lifecycle
                                WHERE lifecycle.supplier_id = employment.supplier_id
                                  AND lifecycle.employment_id = employment.id
                                  AND lifecycle.event_type = "status_changed"
                                  AND lifecycle.effective_on BETWEEN ? AND ?
                                  AND (
                                      lifecycle.from_status = "suspended"
                                      OR lifecycle.to_status = "suspended"
                                  )
                           ) AS suspended_in_month
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT recurring.*, component.code AS component_code,
                    component.component_kind, component.value_kind,
                    component.tax_treatment, employment.monthly_gross_minor,
                    COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) AS employment_start,
                    employment.end_date AS employment_end,
                    employment.effective_status AS employment_effective_status,
                    employment.suspended_in_month
                        AS employment_suspended_in_month
               FROM payroll_recurring_components recurring
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
               JOIN effective_employment employment
                 ON employment.supplier_id = recurring.supplier_id
                AND employment.id = recurring.employment_id
              WHERE recurring.supplier_id = ?
                AND recurring.is_active = 1
                AND recurring.valid_from <= ?
                AND (recurring.valid_to IS NULL OR recurring.valid_to >= ?)
                AND component.is_active = 1
                AND component.valid_from <= ?
                AND (component.valid_to IS NULL OR component.valid_to >= ?)'
            . sprintf($pageFilter, 'recurring')
            . ' ORDER BY recurring.employment_id, recurring.id'
        );
        $recurringStmt->execute([
            $periodEnd,
            $periodStart,
            $periodEnd,
            $supplierId,
            $supplierId,
            $periodEnd,
            $periodStart,
            $periodEnd,
            $periodStart,
            ...$employmentIdsOnPage,
        ]);
        $recurringByEmployment = [];
        foreach (PayrollTimeValue::rows(
            $recurringStmt->fetchAll(PDO::FETCH_ASSOC),
            'quick_recurring',
        ) as $recurring) {
            $recurringByEmployment[(int) $recurring['employment_id']][] = $recurring;
        }

        // Podklad příplatků se čte JEDNOU pro celou stránku, ne po řádcích: sada
        // pravidel je pro měsíc jedna a sjednané zásady se dají dotáhnout jedním
        // dotazem. Dotaz na zásadu u každého z 200 řádků by z jedné obrazovky
        // udělal 200 dotazů navíc.
        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
        $policies = $this->surchargePolicies(
            $supplierId,
            $periodStart,
            $employmentIdsOnPage,
            $ruleset,
        );
        $claims = $this->surchargeClaims->sourcesForPeriod(
            $supplierId,
            $periodStart,
            $employmentIdsOnPage,
        );

        $items = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
            $items[] = $this->buildItem(
                $row,
                $byEmployment[$employmentId] ?? [],
                $recurringByEmployment[$employmentId] ?? [],
                $periodStart,
                $periodEnd,
                $ruleset,
                $policies[$employmentId] ?? PayrollSurchargePolicy::statutoryDefault(),
                $claims[$employmentId] ?? [],
            );
        }
        return ['period' => $period, 'items' => $items, 'total' => $total];
    }

    /**
     * Sjednané zásady příplatků pro celou stránku jedním dotazem.
     *
     * Vadná zásada (neznámý režim, sazba pod kogentním minimem) NESMÍ shodit
     * celý seznam — jinak by jeden špatný řádek v databázi zavřel rychlé zadání
     * celé firmě. Takový vztah dostane výchozí zákonnou zásadu a příplatky u něj
     * vyjdou jako nedostupné; opravit ji jde na kartě vztahu.
     *
     * @param list<int> $employmentIds
     * @return array<int,PayrollSurchargePolicy>
     */
    private function surchargePolicies(
        int $supplierId,
        string $periodStart,
        array $employmentIds,
        PayrollSurchargeRuleset $ruleset,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT policy.*
               FROM payroll_employment_surcharge_policies policy
               JOIN (
                     SELECT employment_id, MAX(valid_from) AS valid_from
                       FROM payroll_employment_surcharge_policies
                      WHERE supplier_id = ? AND valid_from <= ?
                        AND (valid_to IS NULL OR valid_to >= ?)
                        AND employment_id IN ('
            . implode(',', array_fill(0, count($employmentIds), '?'))
            . ')
                      GROUP BY employment_id
                    ) newest
                 ON newest.employment_id = policy.employment_id
                AND newest.valid_from = policy.valid_from
              WHERE policy.supplier_id = ?'
        );
        $stmt->execute([
            $supplierId,
            $periodStart,
            $periodStart,
            ...$employmentIds,
            $supplierId,
        ]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            try {
                $result[$employmentId] = PayrollSurchargePolicy::agreed(
                    PayrollSurchargeCompensationMode::from((string) $row['overtime_mode']),
                    PayrollSurchargeCompensationMode::from((string) $row['holiday_mode']),
                    self::nullableColumn($row, 'difficult_environment_factors'),
                    [
                        PayrollSurchargeKind::Overtime->value =>
                            self::nullableColumn($row, 'overtime_rate_bp'),
                        PayrollSurchargeKind::Holiday->value =>
                            self::nullableColumn($row, 'holiday_rate_bp'),
                        PayrollSurchargeKind::Night->value =>
                            self::nullableColumn($row, 'night_rate_bp'),
                        PayrollSurchargeKind::Weekend->value =>
                            self::nullableColumn($row, 'weekend_rate_bp'),
                        PayrollSurchargeKind::DifficultEnvironment->value =>
                            self::nullableColumn($row, 'difficult_environment_rate_bp'),
                    ],
                    $ruleset,
                );
            } catch (\ValueError | \InvalidArgumentException) {
                // Zásada je v databázi vadná. Výchozí zákonná zásada je tu
                // bezpečná volba: u svátku vede na „nelze zadat", ne na tichou
                // výplatu podle rozbitého řádku.
                $result[$employmentId] = PayrollSurchargePolicy::statutoryDefault();
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function nullableColumn(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Kolik pracovních vztahů měsíc vůbec má. Bez `total` by uživatel neměl jak
     * poznat, že za koncem stránky ještě někdo je.
     */
    private function countMonth(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        ?int $focusEmploymentId = null,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT COUNT(*)
               FROM effective_employment employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.effective_status IN ("active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)'
            . ($focusEmploymentId === null ? '' : ' AND employment.id = ?')
        );
        $stmt->execute([
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodStart,
            ...($focusEmploymentId === null ? [] : [$focusEmploymentId]),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array{
     *   employment_id:int,employment_row_version:int,base_amount_minor:?int,overtime_mode:string,
     *   overtime_hours_milli:?int,overtime_amount_minor:?int,bonus_amount_minor:int,
     *   overtime_average_snapshot_id:?int,overtime_average_snapshot_version:?int,
     *   surcharges:array<string,array{hours_milli:?int,factors:?int}>,
     *   versions:array{base:?int,overtime:?int,bonus:?int,surcharges:array<string,?int>}
     * }> $rows
     * @param bool $autoApprove Zadal to někdo s právem `payroll.approve`?
     *        Pak vstup nemá proč čekat na druhý klik na jiné obrazovce: uloží se
     *        rovnou jako schválený, včetně zmrazeného snímku definice složky a
     *        jeho SHA-256 — vyrábí ho táž cesta, která schvaluje po jednom
     *        ({@see \MyInvoice\Repository\Payroll\PayrollInputRepository::approve()}),
     *        takže integrita snímku je stejná. Kdo právo nemá, ukládá dál jako
     *        koncept; dvoustupňový režim tím zůstává možný, jen není povinný.
     * @param ?list<array{employment_id:int,field:string,code:string,message:string,
     *        current_row_version:?int}> $failures Výstupní parametr: co se
     *        neuložilo a proč. Jeden vadný řádek nesmí shodit uložení zbytku
     *        stránky — u 25 lidí by kvůli jednomu duplicitnímu základu přišlo
     *        vniveč 24 vyplněných řádků. Každé pole má vlastní savepoint, takže
     *        po chybě nezůstane rozepsaná polovina řádku.
     * @return array{period:string,items:list<array<string,mixed>>,total:int}
     */
    public function save(
        int $supplierId,
        string $period,
        array $rows,
        ?int $userId,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $focusEmploymentId = null,
        bool $autoApprove = false,
        ?array &$failures = null,
    ): array {
        $collected = [];
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $this->forEmployments(
                $supplierId,
                $period,
                array_values(array_unique(array_map(
                    static fn (array $row): int => $row['employment_id'],
                    $rows,
                ))),
            );
            $items = [];
            foreach ($current['items'] as $item) {
                $items[(int) $item['employment_id']] = $item;
            }
            $componentIds = $this->componentIds($supplierId, $period . '-01');
            usort($rows, static fn(array $left, array $right): int =>
                $left['employment_id'] <=> $right['employment_id']);
            foreach ($rows as $row) {
                $employmentId = $row['employment_id'];
                $item = $items[$employmentId] ?? null;
                if ($item === null) {
                    $collected[] = self::failure(
                        $employmentId,
                        'row',
                        new \InvalidArgumentException(
                            'Pracovní vztah nepatří této firmě nebo není v daném měsíci účinný.'
                        ),
                    );
                    continue;
                }
                if (!$this->guard($pdo, $collected, $employmentId, 'row', function () use (
                    $supplierId,
                    $employmentId,
                    $row,
                ): void {
                    $this->lockEffectiveEmployment(
                        $supplierId,
                        $employmentId,
                        $row['employment_row_version'],
                    );
                })) {
                    continue;
                }

                $this->guard($pdo, $collected, $employmentId, 'base', function () use (
                    $supplierId,
                    $employmentId,
                    $item,
                    $row,
                    $componentIds,
                    $period,
                    $userId,
                    $autoApprove,
                ): void {
                    if ((bool) $item['base_conflict']) {
                        throw new \DomainException(
                            'Základní mzda je v měsíci evidována rychlým i jiným vstupem. Duplicitní podklady nejprve opravte v měsíčních vstupech.'
                        );
                    }
                    if ((bool) $item['base_managed_elsewhere']) {
                        // Nevyplněné pole (null) si na spravovaný základ nedělá nárok;
                        // jiná částka ano, a to je konflikt.
                        if ($row['base_amount_minor'] !== null
                            && $row['base_amount_minor'] !== (int) $item['base_amount_minor']
                        ) {
                            throw new \DomainException(
                                'Základní mzdu v tomto měsíci spravuje jiný schválený nebo pravidelný vstup.'
                            );
                        }
                        return;
                    }
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::BASE_CODE],
                        $period,
                        self::BASE_CODE,
                        $row['base_amount_minor'],
                        null,
                        $row['versions']['base'],
                        $userId,
                        null,
                        true,
                        $autoApprove,
                    );
                });

                $this->guard($pdo, $collected, $employmentId, 'overtime', function () use (
                    $supplierId,
                    $employmentId,
                    $item,
                    $row,
                    $componentIds,
                    $period,
                    $userId,
                    $autoApprove,
                ): void {
                    if ((bool) $item['overtime_conflict']) {
                        throw new \DomainException(
                            'Přesčas je v měsíci evidován rychlým i jiným vstupem. Duplicitní podklady nejprve opravte.'
                        );
                    }
                    $overtimeAmount = $row['overtime_amount_minor'];
                    $hours = $row['overtime_hours_milli'];
                    if ((bool) $item['overtime_managed_elsewhere']) {
                        if ($row['overtime_mode'] !== 'amount'
                            || (int) $overtimeAmount !== (int) $item['overtime_amount_minor']) {
                            throw new \DomainException(
                                'Přesčas nebo příplatek v tomto měsíci spravuje jiný vstup.'
                            );
                        }
                        return;
                    }
                    // Optimistické zamykání zůstává na JEDNÉ verzi, protože pole
                    // je ve formuláři jedno. Kontroluje se proti verzi nosného
                    // řádku, kterou formulář dostal; ostatní dva řádky se hýbou
                    // s ním a svou verzi si repozitář dohledá sám.
                    if ($row['versions']['overtime'] !== $item['overtime_row_version']) {
                        throw new PayrollInputConflictException(
                            is_int($item['overtime_row_version'])
                                ? $item['overtime_row_version']
                                : 0,
                        );
                    }
                    if ($row['overtime_mode'] !== 'hours') {
                        // Celková částka: rozpad na zákonné nároky se netvrdí,
                        // proto dál sběrná složka. Řádky hodinového rozpadu se
                        // ruší, aby po přepnutí režimu nezůstal viset přesčas
                        // ze staré varianty.
                        $this->upsertOvertimeParts(
                            $supplierId,
                            (int) $item['employee_id'],
                            $employmentId,
                            $componentIds,
                            $period,
                            $userId,
                            $autoApprove,
                            legacyAmount: $overtimeAmount === null ? null : (int) $overtimeAmount,
                            wageAmount: null,
                            premiumAmount: null,
                            hours: null,
                            wageSource: null,
                            premiumSource: null,
                        );
                        return;
                    }
                    if (!(bool) $item['overtime_hours_relation_supported']) {
                        throw new \DomainException(
                            'U tohoto typu vztahu nelze přesčas zadat podle hodin. Použijte celkovou částku nebo odměnu.'
                        );
                    }
                    $split = $this->overtimeSplit(
                        $supplierId,
                        $employmentId,
                        $period,
                        $item,
                        $row,
                        (int) $hours,
                    );
                    $this->upsertOvertimeParts(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds,
                        $period,
                        $userId,
                        $autoApprove,
                        legacyAmount: null,
                        wageAmount: $split['wage_minor'],
                        premiumAmount: $split['premium_minor'],
                        hours: (int) $hours,
                        wageSource: $split['wage_source'],
                        premiumSource: $split['premium_source'],
                    );
                });

                $this->guard($pdo, $collected, $employmentId, 'bonus', function () use (
                    $supplierId,
                    $employmentId,
                    $item,
                    $row,
                    $componentIds,
                    $period,
                    $userId,
                    $autoApprove,
                ): void {
                    if ((bool) $item['bonus_conflict']) {
                        throw new \DomainException(
                            'Odměna je v měsíci evidována rychlým i jiným vstupem. Duplicitní podklady nejprve opravte.'
                        );
                    }
                    if ((bool) $item['bonus_managed_elsewhere']) {
                        if ($row['bonus_amount_minor'] !== (int) $item['bonus_amount_minor']) {
                            throw new \DomainException(
                                'Bonus nebo odměnu v tomto měsíci spravuje jiný vstup.'
                            );
                        }
                        return;
                    }
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::BONUS_CODE],
                        $period,
                        self::BONUS_CODE,
                        $row['bonus_amount_minor'],
                        null,
                        $row['versions']['bonus'],
                        $userId,
                        null,
                        false,
                        $autoApprove,
                    );
                });

                // Zákonné příplatky § 115 až § 118. Každý druh má vlastní
                // savepoint: nedoložený počet ztěžujících vlivů u § 117 nesmí
                // shodit uložení noční práce, která je v pořádku.
                foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
                    $this->guard(
                        $pdo,
                        $collected,
                        $employmentId,
                        self::SURCHARGE_FIELD_PREFIX . $kind->value,
                        function () use (
                            $supplierId,
                            $employmentId,
                            $item,
                            $row,
                            $componentIds,
                            $period,
                            $userId,
                            $autoApprove,
                            $kind,
                        ): void {
                            $this->saveSurcharge(
                                $supplierId,
                                $employmentId,
                                $item,
                                $row,
                                $componentIds,
                                $period,
                                $userId,
                                $autoApprove,
                                $kind,
                            );
                        },
                    );
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->rollback($pdo);
            }
            throw $e;
        }
        $failures = $collected;
        // Po uložení se vrací TÁŽ stránka, na které uživatel byl, i s TÝMŽ
        // zúžením. Vracet natvrdo první stránku celého měsíce by ho odhodilo
        // na začátek a do formuláře nasypalo lidi, které při zúžení nevidí —
        // a právě obsah formuláře se posílá zpátky k uložení.
        return $this->month($supplierId, $period, $limit, $offset, $focusEmploymentId);
    }

    /**
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $inputs
     * @param list<array<string,mixed>> $recurring
     * @return array<string,mixed>
     */
    private function buildItem(
        array $employment,
        array $inputs,
        array $recurring,
        string $periodStart,
        string $periodEnd,
        PayrollSurchargeRuleset $ruleset,
        PayrollSurchargePolicy $policy,
        array $claimSources,
    ): array {
        // Přesčas má od W19 TŘI možné vlastní řádky, protože § 114 odst. 1 přiznává
        // dvě různé věci vedle sebe (dosaženou mzdu a příplatek) a čtvrtý stav je
        // starý režim „celková částka" do sběrné složky. Ve formuláři je to dál
        // JEDNO pole; rozpad je věc mzdového listu, ne obrazovky.
        $quick = [
            'base' => null,
            'overtime' => null,
            'overtime_wage' => null,
            'overtime_premium' => null,
            'bonus' => null,
        ];
        $managed = ['base' => false, 'overtime' => false, 'bonus' => false];
        $managedAmounts = ['base' => 0, 'overtime' => 0, 'bonus' => 0];
        // Ručně zadávané příplatky § 115 až § 118 mají každý VLASTNÍ slot.
        // Dokud padaly do slotu přesčasu (jsou to složky druhu `premium`),
        // materializovaný noční příplatek tvrdil, že přesčas spravuje jiný
        // vstup, a přesčas pak nešlo zadat vůbec.
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $quick[$kind->value] = null;
            $managed[$kind->value] = false;
            $managedAmounts[$kind->value] = 0;
        }
        $blockers = [];
        /** @var array<string,bool> $fromAttendance */
        $fromAttendance = [];
        $other = 0;
        $nonMonetary = 0;
        $excludedFromGross = 0;
        foreach ($inputs as $input) {
            $code = PayrollTimeValue::string($input['component_code'] ?? null, 'component_code');
            $kind = PayrollTimeValue::string(
                $input['component_kind'] ?? null,
                'component_kind',
            );
            $externalId = $input['external_id'] === null
                ? null
                : PayrollTimeValue::string($input['external_id'], 'external_id');
            $quickSlot = self::quickSlot($code);
            $isQuick = $quickSlot !== null
                && $externalId === self::EXTERNAL_PREFIX . $code;
            if ($isQuick) {
                $quick[$quickSlot] = $this->inputView($input);
                continue;
            }
            // Cizí vstup se zařazuje podle KÓDU, ne podle rychlého slotu:
            // `MZDA_HODINOVA` je u hodinově odměňovaného zaměstnance jeho ZÁKLAD,
            // kdežto jako rychlý řádek je to dosažená mzda za přesčas. Kdyby se
            // mapoval přes `$quickSlot`, spadl by cizí hodinový základ do
            // přesčasu a formulář by tvrdil, že přesčas spravuje jiný vstup.
            $managedSlot = self::managedSlot($code, $kind);
            $amount = PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
            if ($managedSlot !== null) {
                $managed[$managedSlot] = true;
                $managedAmounts[$managedSlot] += $amount;
                if ($externalId !== null
                    && str_starts_with($externalId, self::TIME_SURCHARGE_PREFIX)
                ) {
                    // Vstup z materializace schválené docházky. Musí se poznat
                    // od jakéhokoli jiného cizího vstupu, protože hláška „už to
                    // přišlo z docházky" je něco jiného než „spravuje to jiný
                    // vstup" a uživatel na ni reaguje jinak.
                    $fromAttendance[$managedSlot] = true;
                }
            } else {
                $taxTreatment = PayrollTimeValue::string(
                    $input['tax_treatment'] ?? null,
                    'tax_treatment',
                );
                $valueKind = PayrollTimeValue::string(
                    $input['value_kind'] ?? null,
                    'value_kind',
                );
                if (in_array(
                    $taxTreatment,
                    ['included', 'withholding_candidate'],
                    true,
                )) {
                    $other += $amount;
                    if ($valueKind === 'non_monetary') {
                        $nonMonetary += $amount;
                    }
                } elseif ($taxTreatment === 'exempt') {
                    $excludedFromGross += $amount;
                } else {
                    $blockers[] = 'other_component_manual_review';
                }
            }
        }

        foreach ($recurring as $assignment) {
            $code = PayrollTimeValue::string(
                $assignment['component_code'] ?? null,
                'component_code',
            );
            $kind = PayrollTimeValue::string(
                $assignment['component_kind'] ?? null,
                'component_kind',
            );
            $slot = self::managedSlot($code, $kind);
            $calculation = $this->recurringAmounts->calculate($assignment, $periodStart);
            if ($calculation['status'] === 'supported'
                && is_int($calculation['amount_minor'])) {
                $amount = $calculation['amount_minor'];
                if ($slot !== null) {
                    $managed[$slot] = true;
                    $managedAmounts[$slot] += $amount;
                } else {
                    $taxTreatment = PayrollTimeValue::string(
                        $assignment['tax_treatment'] ?? null,
                        'tax_treatment',
                    );
                    $valueKind = PayrollTimeValue::string(
                        $assignment['value_kind'] ?? null,
                        'value_kind',
                    );
                    if (in_array(
                        $taxTreatment,
                        ['included', 'withholding_candidate'],
                        true,
                    )) {
                        $other += $amount;
                        if ($valueKind === 'non_monetary') {
                            $nonMonetary += $amount;
                        }
                    } elseif ($taxTreatment === 'exempt') {
                        $excludedFromGross += $amount;
                    } else {
                        $blockers[] = 'other_component_manual_review';
                    }
                }
            } else {
                if ($slot !== null) {
                    $managed[$slot] = true;
                }
                $blockers[] = ($slot ?? 'other') . '_recurring_manual_review';
            }
        }

        // Přesčas je „rychle zadaný", drží-li aspoň jeden ze tří vlastních řádků.
        $quickOvertimeRows = array_values(array_filter([
            $quick['overtime'],
            $quick['overtime_wage'],
            $quick['overtime_premium'],
        ]));
        $quickPresence = [
            'base' => $quick['base'] !== null,
            'overtime' => $quickOvertimeRows !== [],
            'bonus' => $quick['bonus'] !== null,
        ];

        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $quickPresence[$kind->value] = $quick[$kind->value] !== null;
        }

        $conflicts = [];
        foreach (['base', 'overtime', 'bonus'] as $slot) {
            $conflicts[$slot] = $managed[$slot] && $quickPresence[$slot];
            if ($conflicts[$slot]) {
                $blockers[] = "{$slot}_conflict";
            } elseif ($managed[$slot]) {
                $blockers[] = "{$slot}_managed_elsewhere";
            }
        }
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $slot = $kind->value;
            $conflicts[$slot] = $managed[$slot] && $quickPresence[$slot];
            if ($conflicts[$slot]) {
                $blockers[] = ($fromAttendance[$slot] ?? false)
                    ? 'surcharge_attendance_conflict'
                    : 'surcharge_conflict';
            } elseif ($managed[$slot] && !($fromAttendance[$slot] ?? false)) {
                // Vstup z docházky NENÍ blokátor: je to normální, správný stav
                // měsíce, kde se příplatky vedou docházkou. Blokátorem je jen
                // cizí ruční nebo pravidelný vstup na téže složce.
                $blockers[] = 'surcharge_managed_elsewhere';
            }
        }

        $effectiveStart = $employment['actual_start_date']
            ?? $employment['start_date']
            ?? $periodStart;
        $effectiveEnd = $employment['end_date'] ?? $periodEnd;
        $partialMonth = (string) $effectiveStart > $periodStart
            || (string) $effectiveEnd < $periodEnd;
        $effectiveStatus = PayrollTimeValue::string(
            $employment['effective_status'] ?? null,
            'effective_status',
        );
        $suspendedInMonth = $effectiveStatus === 'suspended'
            || PayrollTimeValue::int(
                $employment['suspended_in_month'] ?? null,
                'suspended_in_month',
            ) === 1;
        $baseRequiresEntry = ($partialMonth || $suspendedInMonth)
            && !$managed['base']
            && $quick['base'] === null;
        if ($baseRequiresEntry) {
            $blockers[] = $suspendedInMonth
                ? 'suspended_month_base_required'
                : 'partial_month_base_required';
        }

        $base = $managed['base']
            ? $managedAmounts['base'] + ($quick['base']['amount_minor'] ?? 0)
            : ($quick['base']['amount_minor'] ?? (
                $baseRequiresEntry || $employment['monthly_gross_minor'] === null
                    ? 0
                    : PayrollTimeValue::int(
                        $employment['monthly_gross_minor'],
                        'monthly_gross_minor',
                    )
            ));
        $overtimeWage = $quick['overtime_wage']['amount_minor'] ?? 0;
        $overtimePremium = $quick['overtime_premium']['amount_minor'] ?? 0;
        $quickOvertime = ($quick['overtime']['amount_minor'] ?? 0)
            + $overtimeWage
            + $overtimePremium;
        $overtime = $managed['overtime']
            ? $managedAmounts['overtime'] + $quickOvertime
            : $quickOvertime;
        $bonus = $managed['bonus']
            ? $managedAmounts['bonus'] + ($quick['bonus']['amount_minor'] ?? 0)
            : ($quick['bonus']['amount_minor'] ?? 0);
        $currentRate = $employment['overtime_hourly_rate_minor'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_hourly_rate_minor'],
                'overtime_hourly_rate_minor',
            );
        $currentAverageId = $employment['overtime_average_snapshot_id'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_average_snapshot_id'],
                'overtime_average_snapshot_id',
            );
        $currentAverageVersion = $employment['overtime_average_snapshot_version'] === null
            ? null
            : PayrollTimeValue::int(
                $employment['overtime_average_snapshot_version'],
                'overtime_average_snapshot_version',
            );
        // Řádek, který nese hodiny a auditní stopu výpočtu: nově příplatková
        // část (§ 114 odst. 1), u dosud neuložených měsíců ještě starý sběrný
        // řádek. Starý tvar se čte dál — přepočítat ho zpětně by změnilo částku
        // už schváleného měsíce.
        $overtimeCarrier = $quick['overtime_premium'] ?? $quick['overtime'];
        $storedOvertimeSource = $overtimeCarrier['source_snapshot'] ?? null;
        $usesStoredAverage = $overtimeCarrier !== null
            && $overtimeCarrier['quantity_milliunits'] !== null
            && is_array($storedOvertimeSource);
        $rate = $usesStoredAverage
            ? ($storedOvertimeSource['average_hourly_minor'] ?? null)
            : $currentRate;
        $averageId = $usesStoredAverage
            ? ($storedOvertimeSource['average_snapshot_id'] ?? null)
            : $currentAverageId;
        $averageVersion = $usesStoredAverage
            ? ($storedOvertimeSource['average_snapshot_row_version'] ?? null)
            : $currentAverageVersion;
        $relationType = PayrollTimeValue::string(
            $employment['relation_type'] ?? null,
            'relation_type',
        );
        $overtimeHoursRelationSupported = in_array(
            $relationType,
            ['employment', 'small_scale_employment'],
            true,
        );

        $surcharges = [];
        $surchargeTotal = 0;
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $slot = $kind->value;
            $stored = $quick[$slot];
            $storedSource = is_array($stored['source_snapshot'] ?? null)
                ? $stored['source_snapshot']
                : null;
            $availability = $this->quickSurcharges->availability(
                $kind,
                $periodStart,
                $policy,
                $ruleset,
                $currentRate ?? 0,
            );
            $claimedBy = $claimSources[$slot] ?? null;
            $takenByAttendance = ($fromAttendance[$slot] ?? false)
                || $claimedBy === PayrollSurchargeClaimRepository::SOURCE_TIME;
            $surchargeTotal += $managedAmounts[$slot]
                + ($stored['amount_minor'] ?? 0);
            $surcharges[$slot] = [
                ...$availability,
                'kind' => $slot,
                'label' => $kind->label(),
                // Průměrný výdělek pro příplatky je VŽDY ten aktuálně schválený,
                // ne ten zmrazený u přesčasu. `overtime_hourly_rate_minor` se
                // totiž u hodinově zadaného přesčasu přepíná na historickou
                // hodnotu ze snímku — a základ příplatku za noční práci nesmí
                // záviset na tom, jestli si někdo v témže měsíci zadal přesčas
                // hodinami, nebo částkou.
                'average_hourly_minor' => $currentRate,
                'average_snapshot_id' => $currentAverageId,
                'average_snapshot_version' => $currentAverageVersion,
                // Ručně zadané hodiny nesou vlastní řádek, takže verze je jeho
                // vlastní — na rozdíl od přesčasu, kde se tři řádky hýbou spolu
                // a formuláři stačí verze toho nosného.
                'hours_milli' => $stored['quantity_milliunits'] ?? null,
                'factors' => $kind === PayrollSurchargeKind::DifficultEnvironment
                    ? (is_int($storedSource['difficulty_factors'] ?? null)
                        ? $storedSource['difficulty_factors']
                        : $availability['default_factors'])
                    : null,
                'amount_minor' => $stored['amount_minor'] ?? 0,
                'row_version' => $stored['row_version'] ?? null,
                'status' => $stored['status'] ?? null,
                'managed_amount_minor' => $managedAmounts[$slot],
                'managed_elsewhere' => $managed[$slot],
                'from_attendance' => $takenByAttendance,
                'conflict' => $conflicts[$slot],
                // Zadat jde, jen když to dovolí zákon i sjednaná zásada A ZÁROVEŇ
                // si nárok za tenhle měsíc nezabrala docházka.
                //
                // Vlastní ULOŽENÝ řádek zůstává editovatelný i tehdy, když
                // podklad mezitím zmizel (třeba se zrušilo schválení průměrného
                // výdělku). Jinak by omylem zadanou hodinu nešlo vzít zpátky
                // a jediným východiskem by byl zásah do databáze.
                'entry_available' => ($availability['available'] || $stored !== null)
                    && !$takenByAttendance
                    && !$managed[$slot],
                // Zadat novou hodnotu nejde, vymazat ano.
                'clear_only' => !$availability['available'] && $stored !== null,
                // Pořadí je pořadím toho, co uživatele skutečně zastavilo:
                // zabraný nárok je silnější důvod než chybějící podklad, protože
                // doplnit podklad by tu stejně nepomohlo.
                'unavailable_reason' => match (true) {
                    $takenByAttendance => 'claimed_by_attendance',
                    $managed[$slot] => 'managed_elsewhere',
                    default => $availability['reason'],
                },
            ];
        }

        return [
            'employee_id' => PayrollTimeValue::int($employment['employee_id'] ?? null, 'employee_id'),
            'employment_id' => PayrollTimeValue::int($employment['employment_id'] ?? null, 'employment_id'),
            'employment_row_version' => PayrollTimeValue::int(
                $employment['employment_row_version'] ?? null,
                'employment_row_version',
            ),
            'full_name' => PayrollTimeValue::string($employment['full_name'] ?? null, 'full_name'),
            'birth_number_masked' => $employment['birth_number_masked'] === null
                ? null
                : PayrollTimeValue::string($employment['birth_number_masked'], 'birth_number_masked'),
            'employment_code' => PayrollTimeValue::string($employment['employment_code'] ?? null, 'employment_code'),
            'relation_type' => $relationType,
            'effective_status' => $effectiveStatus,
            'suspended_in_month' => $suspendedInMonth,
            'away_in_month' => PayrollTimeValue::int(
                $employment['away_in_month'] ?? null,
                'away_in_month',
            ) === 1,
            'base_amount_minor' => $base,
            'base_managed_elsewhere' => $managed['base'],
            'base_conflict' => $conflicts['base'],
            'partial_month' => $partialMonth,
            'base_requires_entry' => $baseRequiresEntry,
            'overtime_mode' => ($overtimeCarrier['quantity_milliunits'] ?? null) === null
                ? 'amount'
                : 'hours',
            'overtime_hours_milli' => $overtimeCarrier['quantity_milliunits'] ?? null,
            'overtime_amount_minor' => $overtime,
            // Rozpad § 114 odst. 1 pro mzdový list. Ve formuláři je pole jedno,
            // ale doložit se musí obě poloviny nároku zvlášť (§ 142 odst. 5 ZP).
            'overtime_wage_minor' => $overtimeWage,
            'overtime_premium_minor' => $overtimePremium,
            // Verze, se kterou prohlížeč přijde na uložení. Tři řádky přesčasu
            // se hýbou VŽDY spolu, takže formuláři stačí jedna a je to verze
            // nosného řádku; ostatní si repozitář dohledá sám.
            'overtime_row_version' => $overtimeCarrier['row_version'] ?? null,
            'overtime_hourly_rate_minor' => is_int($rate) ? $rate : null,
            'overtime_average_snapshot_id' => is_int($averageId) ? $averageId : null,
            'overtime_average_snapshot_version' =>
                is_int($averageVersion) ? $averageVersion : null,
            'overtime_hours_relation_supported' => $overtimeHoursRelationSupported,
            'overtime_hours_available' =>
                $overtimeHoursRelationSupported && is_int($rate) && $rate > 0,
            'overtime_managed_elsewhere' => $managed['overtime'],
            'overtime_conflict' => $conflicts['overtime'],
            'bonus_amount_minor' => $bonus,
            'bonus_managed_elsewhere' => $managed['bonus'],
            'bonus_conflict' => $conflicts['bonus'],
            // Zákonné příplatky § 115 až § 118 zadané ručně, po druzích.
            // Přesčas (§ 114) tu není: má vlastní pole s vlastním rozpadem.
            'surcharges' => $surcharges,
            'surcharge_amount_minor' => $surchargeTotal,
            'other_amount_minor' => $other,
            'non_monetary_amount_minor' => $nonMonetary,
            'excluded_from_gross_amount_minor' => $excludedFromGross,
            // Příplatky se do náhledu ZAPOČÍTÁVAJÍ. Dřív padaly do slotu
            // přesčasu a v součtu byly taky; teď mají vlastní sloupec, ale
            // hrubý příjem se tím měnit nesmí.
            'gross_preview_minor' => $base + $overtime + $bonus + $other + $surchargeTotal,
            'inputs' => $quick,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Rozpad přesčasu zadaného hodinami na obě poloviny nároku podle
     * § 114 odst. 1 ZP.
     *
     * ── Proč to nebylo správně ──────────────────────────────────────────────
     *
     * Do W19 tady stál jediný vzorec `průměrný výdělek × hodiny × 1,25`, a to
     * s konstantami `800` (= 1000 / 1,25) a `premium_basis_points => 2500`
     * natvrdo v kódu. Byly na tom tři vady najednou:
     *
     *  1. ZÁKLAD. § 114 odst. 1 přiznává „dosaženou mzdu" a K NÍ „příplatek
     *     nejméně 25 % průměrného výdělku". To jsou dvě čísla z různých období
     *     (§ 353 zjišťuje průměr z PŘEDCHOZÍHO čtvrtletí), ne jedno vynásobené
     *     1,25. U zaměstnance, kterému mzda vzrostla, se tak systematicky
     *     podpláceli přesčasy — a na pásce to vypadalo v pořádku.
     *  2. SAZBA MIMO SADU. Změna sazby v administraci rulesetů ani sjednaná
     *     vyšší sazba v kolektivní smlouvě se do rychlého zadání nepropsaly.
     *  3. SBĚRNÁ SLOŽKA. Celá částka padala do `PREMIE_PRIPLATKY`, takže
     *     z mzdového listu nešlo doložit, který zákonný nárok byl uspokojen
     *     (§ 142 odst. 5 ZP).
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $row
     * @return array{
     *   wage_minor:int,premium_minor:int,
     *   wage_source:array<string,mixed>,premium_source:array<string,mixed>
     * }
     */
    private function overtimeSplit(
        int $supplierId,
        int $employmentId,
        string $period,
        array $item,
        array $row,
        int $hours,
    ): array {
        $storedWage = $item['inputs']['overtime_wage'] ?? null;
        $storedPremium = $item['inputs']['overtime_premium'] ?? null;
        if (is_array($storedWage)
            && is_array($storedPremium)
            && $storedWage['quantity_milliunits'] === $hours
            && $storedPremium['quantity_milliunits'] === $hours
            && is_array($storedWage['source_snapshot'] ?? null)
            && is_array($storedPremium['source_snapshot'] ?? null)
        ) {
            // Beze změny počtu hodin se NEPŘEPOČÍTÁVÁ. Uložená částka je
            // výsledek podkladu platného v okamžiku zadání; přepočet při každém
            // uložení by měnil už zadanou mzdu podle toho, co se mezitím stalo
            // s průměrným výdělkem nebo s kalendářem.
            return [
                'wage_minor' => (int) $storedWage['amount_minor'],
                'premium_minor' => (int) $storedPremium['amount_minor'],
                'wage_source' => $storedWage['source_snapshot'],
                'premium_source' => $storedPremium['source_snapshot'],
            ];
        }

        $rate = $item['overtime_hourly_rate_minor'];
        if (!is_int($rate) || $rate <= 0
            || $row['overtime_average_snapshot_id'] !== $item['overtime_average_snapshot_id']
            || $row['overtime_average_snapshot_version']
                !== $item['overtime_average_snapshot_version']
        ) {
            throw new \InvalidArgumentException(
                'Schválený průměrný výdělek se změnil. Obnovte formulář a výpočet zkontrolujte.'
            );
        }
        if ($hours < 0) {
            throw new \InvalidArgumentException('Počet hodin přesčasu nesmí být záporný.');
        }

        $periodStart = $period . '-01';
        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
        $policy = $this->surcharges->policyFor(
            $supplierId,
            $employmentId,
            $periodStart,
            $ruleset,
        );
        $mode = $policy->mode(PayrollSurchargeKind::Overtime);
        if ($mode === PayrollSurchargeCompensationMode::IncludedInWage) {
            // § 114 odst. 3 — mzda sjednaná už s přihlédnutím k práci přesčas.
            // Vyplácet cokoli navíc by odporovalo sjednanému; zapsat nulu by
            // vypadalo jako výpočet. Fail-closed.
            throw new \DomainException(
                'U tohoto vztahu je mzda sjednána s přihlédnutím k práci přesčas '
                . '(§ 114 odst. 3), takže příplatek ani náhradní volno nepřísluší. '
                . 'Přesčas hodinami tu zadat nelze.'
            );
        }
        $effective = $policy->effectiveRate(PayrollSurchargeKind::Overtime, $ruleset);

        // Příplatková polovina: `PV × čitatel × hodiny / (jmenovatel × 1000)`.
        // Jedním zlomkem, aby se nezaokrouhlovalo dvakrát — stejně jako
        // {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeLine}.
        $premium = $mode === PayrollSurchargeCompensationMode::CompensatoryTimeOff
            ? 0
            : RoundingMode::HalfUp->roundFraction(
                self::multiplyExactly(
                    self::multiplyExactly($rate, $effective['rate']->numerator),
                    $hours,
                ),
                self::multiplyExactly($effective['rate']->denominator, 1_000),
            );

        $fundMinutes = $this->fund->minutes($supplierId, $employmentId, $period);
        if ($fundMinutes === null) {
            throw new \DomainException(
                'Dosaženou mzdu za práci přesčas nelze určit: pracovní vztah nemá '
                . 'pro tento měsíc pracovní kalendář. Přiřaďte kalendář, nebo přesčas '
                . 'zadejte celkovou částkou.'
            );
        }
        $baseMinor = PayrollTimeValue::int($item['base_amount_minor'] ?? null, 'base_amount_minor');
        $wage = PayrollAchievedWage::forMilliHours($baseMinor, $fundMinutes, $hours);

        $common = [
            'schema_version' => 'payroll-quick-overtime-source.v2',
            'average_snapshot_id' => $row['overtime_average_snapshot_id'],
            'average_snapshot_row_version' => $row['overtime_average_snapshot_version'],
            'average_hourly_minor' => $rate,
            'overtime_hours_milli' => $hours,
            'compensation_mode' => $mode->value,
            'premium_basis_points' => self::basisPoints($effective['rate']),
            'premium_rate_is_agreed' => $effective['agreed'],
            'ruleset_id' => $ruleset->version->id,
            'ruleset_content_hash' => $ruleset->version->contentHash,
            'rounding' => 'half-up-minor-unit',
        ];

        return [
            'wage_minor' => $wage,
            'premium_minor' => $premium,
            'wage_source' => $common + [
                'part' => 'achieved_wage',
                'section' => '§ 114 odst. 1',
                'monthly_base_minor' => $baseMinor,
                'fund_minutes' => $fundMinutes,
                'achieved_hourly_minor' => PayrollAchievedWage::hourlyMinor(
                    $baseMinor,
                    $fundMinutes,
                ),
                'amount_minor' => $wage,
            ],
            'premium_source' => $common + [
                'part' => 'surcharge',
                'section' => '§ 114 odst. 1',
                'amount_minor' => $premium,
            ],
        ];
    }

    /**
     * Uloží jeden druh ručně zadaného zákonného příplatku § 115 až § 118.
     *
     * ── Co se tu hlídá, než se něco zapíše ──────────────────────────────────
     *
     *  1. NÁROK NESMÍ DRŽET DOCHÁZKA. Materializace ze schválené docházky
     *     a ruční zadání jsou dva podklady pro TÝŽ nárok; kdyby prošly oba,
     *     zaměstnanec dostane příplatek dvakrát. Zábrana je dvojí: čitelná
     *     hláška podle stavu měsíce a pod ní zápis nároku
     *     ({@see PayrollSurchargeClaimRepository}), který ho pojistí i proti
     *     souběhu dvou transakcí.
     *  2. ZÁKONNÉ PODMÍNKY. Svátek bez sjednané zásady (§ 115 odst. 1),
     *     ztížené prostředí bez počtu vlivů (§ 117), chybějící průměrný výdělek
     *     — všechno fail-closed, nikdy tichá nula.
     *  3. OPTIMISTICKÝ ZÁMEK. Každý druh je jeden řádek a nese vlastní verzi.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $row
     * @param array<string,int> $componentIds
     */
    private function saveSurcharge(
        int $supplierId,
        int $employmentId,
        array $item,
        array $row,
        array $componentIds,
        string $period,
        ?int $userId,
        bool $autoApprove,
        PayrollSurchargeKind $kind,
    ): void {
        $slot = $kind->value;
        /** @var array<string,mixed> $state */
        $state = $item['surcharges'][$slot];
        $entry = $row['surcharges'][$slot] ?? null;
        if (!is_array($entry)) {
            // Druh v požadavku vůbec nebyl. To NENÍ vyprázdnění: klient, který
            // o příplatcích neví (nebo má sekci schovanou), by jinak každým
            // uložením zrušil, co zadal někdo jiný.
            return;
        }
        $hours = $entry['hours_milli'];
        $factors = $entry['factors'];
        $wantsEntry = is_int($hours) && $hours > 0;
        $hasStored = $state['row_version'] !== null;
        $periodStart = $period . '-01';

        if (!$wantsEntry && !$hasStored) {
            // Prázdné pole u druhu, který nikdy zadaný nebyl. Nedělá se nic —
            // ani se nesahá na nárok, ani nevzniká nulový koncept.
            return;
        }

        if ((bool) $state['conflict']) {
            throw new \DomainException(sprintf(
                '%s (%s) je v měsíci evidován rychlým i jiným vstupem. '
                . 'Duplicitní podklady nejprve opravte v měsíčních vstupech.',
                $kind->label(),
                $kind->section(),
            ));
        }

        if (!$wantsEntry) {
            // Vyprázdnění. Nárok se pouští, aby ho směla převzít docházka;
            // kdyby zůstal zabraný, měsíc by už z docházky nešlo doplnit.
            if ($state['row_version'] !== $row['versions']['surcharges'][$slot]) {
                throw new PayrollInputConflictException(
                    is_int($state['row_version']) ? $state['row_version'] : 0,
                );
            }
            $this->upsert(
                $supplierId,
                (int) $item['employee_id'],
                $employmentId,
                $componentIds[$kind->componentCode()],
                $period,
                $kind->componentCode(),
                null,
                null,
                $row['versions']['surcharges'][$slot],
                $userId,
                null,
                false,
                $autoApprove,
            );
            $this->surchargeClaims->release(
                $supplierId,
                $employmentId,
                $periodStart,
                $kind,
                PayrollSurchargeClaimRepository::SOURCE_MANUAL,
            );

            return;
        }

        if ((bool) $state['from_attendance']) {
            throw new \DomainException(sprintf(
                '%s (%s) už za toto období vznikl ze schválené docházky. Ručně ho '
                . 'zadat nelze — příplatek by se vyplatil dvakrát. Buď opravte '
                . 'docházku, nebo příplatek z docházky nejdřív zrušte.',
                $kind->label(),
                $kind->section(),
            ));
        }
        if ((bool) $state['managed_elsewhere']) {
            throw new \DomainException(sprintf(
                '%s (%s) v tomto měsíci spravuje jiný mzdový nebo pravidelný vstup.',
                $kind->label(),
                $kind->section(),
            ));
        }
        if ($state['row_version'] !== $row['versions']['surcharges'][$slot]) {
            throw new PayrollInputConflictException(
                is_int($state['row_version']) ? $state['row_version'] : 0,
            );
        }

        $stored = $item['inputs'][$slot] ?? null;
        $storedSource = is_array($stored['source_snapshot'] ?? null)
            ? $stored['source_snapshot']
            : null;
        $storedFactors = is_int($storedSource['difficulty_factors'] ?? null)
            ? $storedSource['difficulty_factors']
            : null;
        $requestedFactors = $kind === PayrollSurchargeKind::DifficultEnvironment
            ? $factors
            : null;
        $unchanged = $storedSource !== null
            && ($stored['quantity_milliunits'] ?? null) === $hours
            && ($requestedFactors === null || $storedFactors === $requestedFactors);
        if ($unchanged) {
            // Beze změny hodin a počtu vlivů se NEPŘEPOČÍTÁVÁ — stejně jako
            // u přesčasu. Uložená částka je výsledek podkladu platného
            // v okamžiku zadání; přepočet při každém uložení by měnil už zadanou
            // mzdu podle toho, co se mezitím stalo s průměrným výdělkem.
            $amount = PayrollTimeValue::int($stored['amount_minor'] ?? null, 'amount_minor');
            $source = $storedSource;
        } else {
            $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
            $policy = $this->surcharges->policyFor(
                $supplierId,
                $employmentId,
                $periodStart,
                $ruleset,
            );
            try {
                $computed = $this->quickSurcharges->calculate(
                    $kind,
                    $periodStart,
                    $policy,
                    $ruleset,
                    is_int($state['average_hourly_minor'] ?? null)
                        ? $state['average_hourly_minor']
                        : 0,
                    $hours,
                    $factors,
                    [
                        'id' => $state['average_snapshot_id'],
                        'row_version' => $state['average_snapshot_version'],
                    ],
                );
            } catch (PayrollSurchargeException $exception) {
                // Chybějící podklad je vstupní stav uživatele, ne vada kódu:
                // musí se vrátit u konkrétního pole, ne shodit celou dávku.
                throw new \DomainException($exception->getMessage(), 0, $exception);
            }
            $amount = $computed['amount_minor'];
            $source = $computed['source'];
        }

        // Nárok se zabírá PŘED zápisem vstupu. Kdyby se zabíral až po něm,
        // souběžná materializace z docházky by mezitím stihla zapsat svůj —
        // a zabrání by pak jen oznámilo škodu, místo aby jí zabránilo.
        $this->surchargeClaims->claim(
            $supplierId,
            $employmentId,
            $periodStart,
            $kind,
            PayrollSurchargeClaimRepository::SOURCE_MANUAL,
            $userId,
        );
        $this->upsert(
            $supplierId,
            (int) $item['employee_id'],
            $employmentId,
            $componentIds[$kind->componentCode()],
            $period,
            $kind->componentCode(),
            $amount,
            $hours,
            $row['versions']['surcharges'][$slot],
            $userId,
            $source,
            // Nula hodin sem nedojde (odbavuje ji větev vyprázdnění), ale nulová
            // ČÁSTKA při kladných hodinách ano — u sjednané sazby 0 by to byl
            // legitimní záznam „hodiny byly, příplatek se nesjednal". Řádek
            // proto vzniknout musí, jinak by se hodiny ztratily.
            true,
            $autoApprove,
        );
    }

    /**
     * Uloží všechny tři možné řádky přesčasu najednou.
     *
     * Nevyplněná část znamená ZRUŠENÍ svého řádku, ne nulu. Bez toho by po
     * přepnutí režimu z hodin na celkovou částku (a naopak) zůstal viset řádek
     * z předchozí varianty a přesčas by se vyplatil dvakrát.
     *
     * @param array<string,int> $componentIds
     * @param array<string,mixed>|null $wageSource
     * @param array<string,mixed>|null $premiumSource
     */
    private function upsertOvertimeParts(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        array $componentIds,
        string $period,
        ?int $userId,
        bool $autoApprove,
        ?int $legacyAmount,
        ?int $wageAmount,
        ?int $premiumAmount,
        ?int $hours,
        ?array $wageSource,
        ?array $premiumSource,
    ): void {
        foreach ([
            [self::OVERTIME_CODE, $legacyAmount, null, null],
            [self::HOURLY_CODE, $wageAmount, $hours, $wageSource],
            [self::PREMIUM_CODE, $premiumAmount, $hours, $premiumSource],
        ] as [$code, $amount, $quantity, $source]) {
            $this->upsert(
                $supplierId,
                $employeeId,
                $employmentId,
                $componentIds[$code],
                $period,
                $code,
                $amount,
                $amount === null ? null : $quantity,
                null,
                $userId,
                $amount === null ? null : $source,
                false,
                $autoApprove,
                versionFromDatabase: true,
            );
        }
    }

    private static function basisPoints(DecimalRate $rate): int
    {
        return RoundingMode::HalfUp->roundFraction(
            self::multiplyExactly($rate->numerator, 10_000),
            $rate->denominator,
        );
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException(
                'Výpočet přesčasu nepracuje se zápornými činiteli.'
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new \InvalidArgumentException(
                'Výpočet přesčasu překračuje podporovaný rozsah.'
            );
        }

        return $left * $right;
    }

    /** Který slot rychlého zadání kód obsluhuje, spravuje-li ho tenhle formulář. */
    private static function quickSlot(string $code): ?string
    {
        return match ($code) {
            self::BASE_CODE => 'base',
            self::HOURLY_CODE => 'overtime_wage',
            self::OVERTIME_CODE => 'overtime',
            self::PREMIUM_CODE => 'overtime_premium',
            self::BONUS_CODE => 'bonus',
            default => self::surchargeKindForCode($code)?->value,
        };
    }

    /**
     * Které pole rychlého zadání by CIZÍ vstup (nebo pravidelná složka) zabral.
     *
     * Rozhoduje se podle KÓDU, ne podle druhu složky: `MZDA_HODINOVA` je
     * u hodinově odměňovaného zaměstnance jeho ZÁKLAD, kdežto jako rychlý řádek
     * je to dosažená mzda za přesčas. Příplatkové kódy mají od W20 vlastní
     * slot — jako složky druhu `premium` by jinak spadly do přesčasu a rychlé
     * zadání by tvrdilo, že přesčas spravuje jiný vstup.
     */
    private static function managedSlot(string $code, string $componentKind): ?string
    {
        $surcharge = self::surchargeKindForCode($code);
        if ($surcharge !== null) {
            return $surcharge->value;
        }

        return match ($code) {
            self::BASE_CODE, self::HOURLY_CODE => 'base',
            self::OVERTIME_CODE, self::PREMIUM_CODE => 'overtime',
            self::BONUS_CODE => 'bonus',
            default => match ($componentKind) {
                'base_wage', 'hourly_wage' => 'base',
                'premium' => 'overtime',
                'bonus', 'commission' => 'bonus',
                default => null,
            },
        };
    }

    /** Druh příplatku, který se v rychlém zadání schovává za mzdovou složku. */
    private static function surchargeKindForCode(string $code): ?PayrollSurchargeKind
    {
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            if ($kind->componentCode() === $code) {
                return $kind;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $item */
    private static function cardNeedsAttention(array $item): bool
    {
        return ($item['blockers'] ?? []) !== []
            || ($item['base_conflict'] ?? false) === true
            || ($item['overtime_conflict'] ?? false) === true
            || ($item['bonus_conflict'] ?? false) === true
            || ($item['base_requires_entry'] ?? false) === true;
    }

    private static function normalizedSearch(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function cardAbsences(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        array $employmentIds,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employment_id, absence_type, date_from, date_to, status
               FROM payroll_absences
              WHERE supplier_id = ?
                AND status IN ("requested", "approved")
                AND date_from <= ? AND date_to >= ?
                AND employment_id IN ('
            . implode(',', array_fill(0, count($employmentIds), '?'))
            . ')
              ORDER BY employment_id, date_from, id'
        );
        $stmt->execute([$supplierId, $periodEnd, $periodStart, ...$employmentIds]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'card_absences') as $row) {
            $employmentId = PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            );
            $result[$employmentId][] = [
                'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
                'employment_id' => $employmentId,
                'absence_type' => PayrollTimeValue::string(
                    $row['absence_type'] ?? null,
                    'absence_type',
                ),
                'date_from' => PayrollTimeValue::string($row['date_from'] ?? null, 'date_from'),
                'date_to' => PayrollTimeValue::string($row['date_to'] ?? null, 'date_to'),
                'status' => PayrollTimeValue::string($row['status'] ?? null, 'status'),
            ];
        }

        return $result;
    }

    private function companyHeadcount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?',
        );
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function lockEffectiveEmployment(
        int $supplierId,
        int $employmentId,
        int $expectedVersion,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.row_version
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $currentVersion = $stmt->fetchColumn();
        if ($currentVersion === false) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nepatří této firmě nebo není v daném měsíci účinný.'
            );
        }
        if ((int) $currentVersion !== $expectedVersion) {
            throw new PayrollEmploymentConflictException((int) $currentVersion);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   id:int,amount_minor:int,quantity_milliunits:?int,source_kind:string,
     *   status:string,row_version:int,source_snapshot:?array<string,mixed>
     * }
     */
    private function inputView(array $input): array
    {
        return [
            'id' => PayrollTimeValue::int($input['id'] ?? null, 'id'),
            'amount_minor' => PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor'),
            'quantity_milliunits' => $input['quantity_milliunits'] === null
                ? null
                : PayrollTimeValue::int($input['quantity_milliunits'], 'quantity_milliunits'),
            'source_kind' => PayrollTimeValue::string($input['source_kind'] ?? null, 'source_kind'),
            'status' => PayrollTimeValue::string($input['status'] ?? null, 'status'),
            'row_version' => PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
            'source_snapshot' => $input['source_snapshot_json'] === null
                ? null
                : PayrollTimeValue::row(
                    json_decode(
                        PayrollTimeValue::string(
                            $input['source_snapshot_json'],
                            'source_snapshot_json',
                        ),
                        true,
                        flags: JSON_THROW_ON_ERROR,
                    ),
                    'source_snapshot',
                ),
        ];
    }

    /**
     * Spustí jeden krok uložení tak, aby jeho selhání nezabilo zbytek dávky.
     *
     * Savepoint je tu proto, že „částečné uložení" nesmí znamenat „polovina
     * řádku v databázi". Selže-li přesčas, vrátí se právě ta jeho část a
     * základní mzda uložená o kus výš zůstane platná.
     *
     * Chytají se jen očekávané doménové chyby. Cokoli jiného (chyba spojení,
     * porušení integrity) je vada, ne vstupní data uživatele, a musí shodit
     * celou transakci — jinak by se tvářila jako „jeden řádek se neuložil".
     *
     * @param list<array{employment_id:int,field:string,code:string,message:string,
     *        current_row_version:?int}> $failures
     */
    private function guard(
        PDO $pdo,
        array &$failures,
        int $employmentId,
        string $field,
        \Closure $step,
    ): bool {
        $pdo->exec('SAVEPOINT ' . self::SAVE_SAVEPOINT);
        try {
            $step();
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVE_SAVEPOINT);
            return true;
        } catch (
            PayrollEmploymentConflictException
            | PayrollInputConflictException
            | \DomainException
            | \InvalidArgumentException $e
        ) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVE_SAVEPOINT);
            $failures[] = self::failure($employmentId, $field, $e);
            return false;
        }
    }

    /**
     * @return array{employment_id:int,field:string,code:string,message:string,
     *   current_row_version:?int}
     */
    private static function failure(int $employmentId, string $field, \Throwable $e): array
    {
        // Vlastní kód schvalovací nebo stornovací výjimky se nesmí ztratit:
        // `benefit_limit_exceeded` a `meal_shift_evidence_incomplete` říkají
        // uživateli, co má udělat, kdežto obecné „konflikt stavu" nic.
        $code = match (true) {
            $e instanceof PayrollEmploymentConflictException
                => 'employment_row_version_conflict',
            $e instanceof PayrollInputConflictException => 'row_version_conflict',
            $e instanceof PayrollInputApprovalException,
            $e instanceof PayrollInputCancellationException => $e->errorCode,
            $e instanceof \InvalidArgumentException => 'validation_failed',
            default => 'input_state_conflict',
        };
        $version = null;
        if ($e instanceof PayrollEmploymentConflictException
            || $e instanceof PayrollInputConflictException
        ) {
            $version = $e->currentVersion;
        }

        return [
            'employment_id' => $employmentId,
            'field' => $field,
            'code' => $code,
            'message' => $e->getMessage(),
            'current_row_version' => $version,
        ];
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @return array<string,int> */
    private function componentIds(int $supplierId, string $effectiveOn): array
    {
        $codes = self::managedCodes();
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, id
               FROM payroll_component_definitions
              WHERE supplier_id = ?
                AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$supplierId, ...$codes, $effectiveOn, $effectiveOn]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['code']] = (int) $row['id'];
        }
        foreach ($codes as $code) {
            if (!isset($result[$code])) {
                throw new \InvalidArgumentException("Chybí účinná mzdová složka {$code}.");
            }
        }
        return $result;
    }

    /**
     * Uloží jedno pole rychlého zadání.
     *
     * Formulář má tři pole a ukládají se všechna najednou, i když uživatel vyplnil
     * jen jedno. Prázdné pole proto NESMÍ zakládat řádek: nulový koncept by
     * vyrobil blokátor `draft_inputs_present` a mzdový běh by nešlo spustit.
     * Vyprázdnění už existujícího pole znamená zrušení jeho vstupu — ne uložení
     * nuly, která by po schválení skončila jako nulový řádek na výplatní pásce.
     *
     * Prázdné pole a zadaná nula jsou ale dvě různé věci a `$amountMinor` je
     * rozlišuje: `null` = nevyplněno, `0` = uživatel zadal nulu.
     *
     * @param ?int $amountMinor null = pole zůstalo prázdné
     * @param bool $zeroIsAnEntry nese zadaná nula informaci?
     *        U základní mzdy ano — v částečném nebo přerušeném měsíci znamená
     *        „nic se nevydělalo" a řádek musí vzniknout. Že takový koncept pak
     *        drží mzdový běh, dokud ho někdo neschválí, je správné: uživatel ho
     *        zadal vědomě. U přesčasu a odměny ne — nula hodin za nula korun
     *        nenese žádnou informaci a řádek by byl jen ten nulový koncept,
     *        kvůli kterému se to celé řešilo.
     * @param array<string,mixed>|null $sourceSnapshot
     * @param bool $autoApprove Ukládá to někdo s právem `payroll.approve`?
     *        Pak vstup nekončí jako koncept, ale rovnou jako schválený.
     *        Už schválený vstup se přitom musí dát ještě opravit, dokud ho
     *        nepohltil mzdový běh — jinak by si uživatel první uloženou částkou
     *        zabetonoval vlastní řádek. Proto se vrací do konceptu, přepíše
     *        a schválí znovu; snímek definice složky tím vzniká NOVÝ, k datu
     *        toho druhého schválení, což je právě to, co má odpovídat výplatě.
     */
    private function upsert(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        int $componentId,
        string $period,
        string $componentCode,
        ?int $amountMinor,
        ?int $quantityMilliunits,
        ?int $expectedVersion,
        ?int $userId,
        ?array $sourceSnapshot,
        bool $zeroIsAnEntry = false,
        bool $autoApprove = false,
        bool $versionFromDatabase = false,
    ): void {
        $periodStart = $period . '-01';
        $externalId = self::EXTERNAL_PREFIX . $componentCode;
        $find = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, quantity_milliunits, status, row_version
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "manual" AND external_id = ?
                AND status <> "cancelled"
              FOR UPDATE'
        );
        $find->execute([$supplierId, $employmentId, $periodStart, $externalId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($versionFromDatabase) {
            // Řádek, který uživatel nevidí jako vlastní pole (druhá polovina
            // rozpadu přesčasu). Jeho verzi formulář nezná a znát nemá; souběh
            // hlídá verze nosného řádku a zámek na pracovním vztahu, které se
            // ověřují dřív, než se sem dojde.
            $expectedVersion = $row === false ? null : (int) $row['row_version'];
        }
        $isEmpty = $amountMinor === null
            || (!$zeroIsAnEntry
                && $amountMinor === 0
                && ($quantityMilliunits === null || $quantityMilliunits === 0));
        if ($row === false) {
            if ($expectedVersion !== null) {
                throw new PayrollInputConflictException($expectedVersion);
            }
            if ($isEmpty) {
                return;
            }
            $data = [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $periodStart,
                'source_period_start' => null,
                'amount_minor' => (int) $amountMinor,
                'quantity_milliunits' => $quantityMilliunits,
                'source_kind' => 'manual',
                'external_id' => $externalId,
                'source_snapshot_json' => $sourceSnapshot === null
                    ? null
                    : CanonicalJson::encode($sourceSnapshot),
                'source_snapshot_hash' => $sourceSnapshot === null
                    ? null
                    : hash('sha256', CanonicalJson::encode($sourceSnapshot), true),
            ];
            // Založit koncept a schválit ho druhým zápisem by řádek posunulo na
            // verzi 2, zatímco formulář by pracoval s jedničkou — každá druhá
            // editace téhož pole by pak spadla na 409 „změnil to jiný uživatel".
            // Schválený vstup proto vzniká rovnou, jedním INSERTem.
            if ($autoApprove) {
                $this->inputs->createApproved($supplierId, $data, $userId);
            } else {
                $this->inputs->create($supplierId, $data, $userId);
            }
            return;
        }

        $currentAmount = (int) $row['amount_minor'];
        $currentQuantity = $row['quantity_milliunits'] === null ? null : (int) $row['quantity_milliunits'];
        $currentVersion = (int) $row['row_version'];
        $status = (string) $row['status'];
        if ($isEmpty) {
            // Vyprázdněné pole = zrušení vstupu. Schválený vlastní vstup se na
            // to musí nejdřív vrátit do konceptu (`cancel()` bere jen koncept);
            // dva bumpy verze tu nevadí, protože řádek z formuláře mizí a
            // prohlížeč na něj příště pošle `versions.* = null`.
            if ($autoApprove && $status === 'approved') {
                if ($expectedVersion === null || $expectedVersion !== $currentVersion) {
                    throw new PayrollInputConflictException($currentVersion);
                }
                $this->inputs->revertToDraft($supplierId, (int) $row['id'], $currentVersion);
                $status = 'draft';
                $currentVersion += 1;
                $expectedVersion = $currentVersion;
            }
            if ($status !== 'draft') {
                throw new \DomainException(
                    'Schválený nebo uzamčený mzdový vstup nelze rychlým formulářem přepsat.'
                );
            }
            if ($expectedVersion === null || $expectedVersion !== $currentVersion) {
                throw new PayrollInputConflictException($currentVersion);
            }
            $this->inputs->cancel($supplierId, (int) $row['id'], $currentVersion);
            return;
        }
        if ($currentAmount === $amountMinor && $currentQuantity === $quantityMilliunits) {
            // Beze změny částky se nic nepřepisuje. Koncept, který zadal někdo
            // bez práva schvalovat, ale smí ten, kdo právo má, uložením potvrdit
            // — jinak by „Uložit vše" tichým no-opem nechalo běh viset na
            // blokátoru `draft_inputs_present`.
            if ($autoApprove && $status === 'draft') {
                $this->inputs->approve(
                    $supplierId,
                    (int) $row['id'],
                    $currentVersion,
                    $userId,
                );
            }
            return;
        }
        // Bez práva schvalovat platí původní pravidlo: přepsat jde jen koncept.
        // S právem schvalovat jde i vlastní dosud nezamčený schválený vstup —
        // jinak by si uživatel první uloženou částkou zabetonoval svůj řádek.
        if ($status !== 'draft' && !($autoApprove && $status === 'approved')) {
            throw new \DomainException(
                'Schválený nebo uzamčený mzdový vstup nelze rychlým formulářem přepsat.'
            );
        }
        if ($expectedVersion === null || $expectedVersion !== $currentVersion) {
            throw new PayrollInputConflictException($currentVersion);
        }
        $data = [
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'component_id' => $componentId,
            'period_start' => $periodStart,
            'source_period_start' => null,
            'amount_minor' => (int) $amountMinor,
            'quantity_milliunits' => $quantityMilliunits,
            'source_kind' => 'manual',
            'external_id' => $externalId,
            'source_snapshot_json' => $sourceSnapshot === null
                ? null
                : CanonicalJson::encode($sourceSnapshot),
            'source_snapshot_hash' => $sourceSnapshot === null
                ? null
                : hash('sha256', CanonicalJson::encode($sourceSnapshot), true),
        ];
        // Nová hodnota i schvalovací sloupce jedním UPDATEm: běžná editace tak
        // posune `row_version` právě o jedničku, přesně jako před zavedením
        // automatického schvalování.
        $updated = $autoApprove
            ? $this->inputs->updateApproved(
                $supplierId,
                (int) $row['id'],
                $data,
                $currentVersion,
                $userId,
            )
            : $this->inputs->update($supplierId, (int) $row['id'], $data, $currentVersion);
        if ($updated === null) {
            throw new PayrollInputConflictException($currentVersion);
        }
    }
}
