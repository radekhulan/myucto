<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
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
    private const OVERTIME_CODE = 'PREMIE_PRIPLATKY';
    private const BONUS_CODE = 'ODMENA';
    private const EXTERNAL_PREFIX = 'quick-monthly:';
    private const SAVE_SAVEPOINT = 'payroll_quick_input_field';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollRecurringAmountCalculator $recurringAmounts,
    ) {}

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

        $items = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
            $items[] = $this->buildItem(
                $row,
                $byEmployment[$employmentId] ?? [],
                $recurringByEmployment[$employmentId] ?? [],
                $periodStart,
                $periodEnd,
            );
        }
        return ['period' => $period, 'items' => $items, 'total' => $total];
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
     *   versions:array{base:?int,overtime:?int,bonus:?int}
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
                    $overtimeSource = null;
                    if ((bool) $item['overtime_managed_elsewhere']) {
                        if ($row['overtime_mode'] !== 'amount'
                            || (int) $overtimeAmount !== (int) $item['overtime_amount_minor']) {
                            throw new \DomainException(
                                'Přesčas nebo příplatek v tomto měsíci spravuje jiný vstup.'
                            );
                        }
                        return;
                    }
                    if ($row['overtime_mode'] === 'hours') {
                        if (!(bool) $item['overtime_hours_relation_supported']) {
                            throw new \DomainException(
                                'U tohoto typu vztahu nelze přesčas zadat podle hodin. Použijte celkovou částku nebo odměnu.'
                            );
                        }
                        $existing = $item['inputs']['overtime'];
                        $unchanged = is_array($existing)
                            && $existing['quantity_milliunits'] === $hours;
                        if ($unchanged) {
                            $overtimeAmount = (int) $existing['amount_minor'];
                            $overtimeSource = $existing['source_snapshot'] ?? null;
                        } else {
                            $rate = $item['overtime_hourly_rate_minor'];
                            if (!is_int($rate) || $rate <= 0
                                || $row['overtime_average_snapshot_id']
                                    !== $item['overtime_average_snapshot_id']
                                || $row['overtime_average_snapshot_version']
                                    !== $item['overtime_average_snapshot_version']) {
                                throw new \InvalidArgumentException(
                                    'Schválený průměrný výdělek se změnil. Obnovte formulář a výpočet zkontrolujte.'
                                );
                            }
                            if ((int) $hours !== 0 && $rate > intdiv(PHP_INT_MAX, (int) $hours)) {
                                throw new \InvalidArgumentException(
                                    'Výpočet přesčasu překračuje podporovaný rozsah.'
                                );
                            }
                            $overtimeAmount = RoundingMode::HalfUp->roundFraction(
                                $rate * (int) $hours,
                                800,
                            );
                            $overtimeSource = [
                                'schema_version' => 'payroll-quick-overtime-source.v1',
                                'average_snapshot_id' => $row['overtime_average_snapshot_id'],
                                'average_snapshot_row_version' =>
                                    $row['overtime_average_snapshot_version'],
                                'average_hourly_minor' => $rate,
                                'overtime_hours_milli' => $hours,
                                'premium_basis_points' => 2_500,
                                'rounding' => 'half-up-minor-unit',
                            ];
                        }
                    }
                    $this->upsert(
                        $supplierId,
                        (int) $item['employee_id'],
                        $employmentId,
                        $componentIds[self::OVERTIME_CODE],
                        $period,
                        self::OVERTIME_CODE,
                        (int) $overtimeAmount,
                        $hours,
                        $row['versions']['overtime'],
                        $userId,
                        is_array($overtimeSource) ? $overtimeSource : null,
                        false,
                        $autoApprove,
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
    ): array {
        $quick = ['base' => null, 'overtime' => null, 'bonus' => null];
        $managed = ['base' => false, 'overtime' => false, 'bonus' => false];
        $managedAmounts = ['base' => 0, 'overtime' => 0, 'bonus' => 0];
        $blockers = [];
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
            $quickSlot = match ($code) {
                self::BASE_CODE => 'base',
                self::OVERTIME_CODE => 'overtime',
                self::BONUS_CODE => 'bonus',
                default => null,
            };
            $isQuick = $quickSlot !== null
                && $externalId === self::EXTERNAL_PREFIX . $code;
            if ($isQuick) {
                $quick[$quickSlot] = $this->inputView($input);
                continue;
            }
            $managedSlot = $quickSlot ?? match ($kind) {
                'base_wage' => 'base',
                'premium' => 'overtime',
                'bonus', 'commission' => 'bonus',
                default => null,
            };
            $amount = PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
            if ($managedSlot !== null) {
                $managed[$managedSlot] = true;
                $managedAmounts[$managedSlot] += $amount;
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
            $slot = match ($code) {
                self::BASE_CODE => 'base',
                self::OVERTIME_CODE => 'overtime',
                self::BONUS_CODE => 'bonus',
                default => match ($kind) {
                    'base_wage' => 'base',
                    'premium' => 'overtime',
                    'bonus', 'commission' => 'bonus',
                    default => null,
                },
            };
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

        $conflicts = [];
        foreach (['base', 'overtime', 'bonus'] as $slot) {
            $conflicts[$slot] = $managed[$slot] && $quick[$slot] !== null;
            if ($conflicts[$slot]) {
                $blockers[] = "{$slot}_conflict";
            } elseif ($managed[$slot]) {
                $blockers[] = "{$slot}_managed_elsewhere";
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
        $overtime = $managed['overtime']
            ? $managedAmounts['overtime'] + ($quick['overtime']['amount_minor'] ?? 0)
            : ($quick['overtime']['amount_minor'] ?? 0);
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
        $storedOvertimeSource = $quick['overtime']['source_snapshot'] ?? null;
        $usesStoredAverage = $quick['overtime'] !== null
            && $quick['overtime']['quantity_milliunits'] !== null
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
            'overtime_mode' => ($quick['overtime']['quantity_milliunits'] ?? null) === null ? 'amount' : 'hours',
            'overtime_hours_milli' => $quick['overtime']['quantity_milliunits'] ?? null,
            'overtime_amount_minor' => $overtime,
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
            'other_amount_minor' => $other,
            'non_monetary_amount_minor' => $nonMonetary,
            'excluded_from_gross_amount_minor' => $excludedFromGross,
            'gross_preview_minor' => $base + $overtime + $bonus + $other,
            'inputs' => $quick,
            'blockers' => array_values(array_unique($blockers)),
        ];
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
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, id
               FROM payroll_component_definitions
              WHERE supplier_id = ?
                AND code IN ("MZDA_MESICNI", "PREMIE_PRIPLATKY", "ODMENA")
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$supplierId, $effectiveOn, $effectiveOn]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['code']] = (int) $row['id'];
        }
        foreach ([self::BASE_CODE, self::OVERTIME_CODE, self::BONUS_CODE] as $code) {
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
