<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Employment\PayrollHourlyRemuneration;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Service\Payroll\PayrollHourlyWagePreview;
use MyInvoice\Service\Payroll\PayrollRunClosedException;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\PayrollMonthlyFundService;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeSource;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollAchievedWage;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollQuickSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;
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

    /**
     * Ruční přepis importované hodnoty: `override:<id importního vstupu>`.
     *
     * Importní vstup se přepisem NEMAŽE ani nemění — přejde do stavu
     * `cancelled` a zůstane jako doklad dávky (řádek importu na něj dál
     * ukazuje). Do výpočtu jde jen přepis. Zrušený vstup vylučuje z výpočtu
     * každé místo, které `payroll_inputs` čte (běh bere jen `approved`
     * a `locked`), takže dvojí započtení nevznikne ani tam, kde o přepisu
     * nikdo neví. Sdílí ho import ({@see PayrollInputImportRepository}), aby
     * opakovaný import přepis poznal a nepřepsal.
     */
    public const OVERRIDE_PREFIX = 'override:';

    /** Předpona pole rychlého zadání, které nese jednu dynamickou mzdovou složku. */
    public const COMPONENT_FIELD_PREFIX = 'component:';

    /**
     * Hodnoty `components.*.source` v odpovědi — odkud hodnota buňky přišla
     * ({@see self::inputSource()}). Páruje je PayrollEnumContractTest s unií
     * `PayrollQuickComponentSource` ve frontendu.
     */
    public const COMPONENT_CELL_SOURCES = ['import', 'recurring', 'manual', 'attendance', 'surcharge'];

    /**
     * Hodnoty `components.*.mode` — co s buňkou sloupce složky jde udělat
     * ({@see self::componentCells()}). Páruje je PayrollEnumContractTest
     * s unií `PayrollQuickComponentMode`.
     */
    public const COMPONENT_CELL_MODES = ['manual', 'import', 'override', 'managed'];

    /**
     * Prefix `external_id` vstupu z importu docházky. Shodný se zápisem
     * v {@see \MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService}.
     */
    private const ATTENDANCE_IMPORT_PREFIX = 'attendance:';

    /**
     * Druhy složek, které rychlé zadání dovolí nově zadat do vlastního sloupce.
     *
     * Chybí tu záměrně základní mzda (má pevné pole), náhrady (vznikají
     * z absencí), benefity (roční koše a doklady), cestovné (z vyúčtování cest)
     * a odstupné či zpětné doplatky — to jsou jednotlivé případy pro Mzdové
     * vstupy, ne pro měsíční sloupec u 500 lidí.
     */
    private const QUICK_COMPONENT_KINDS = [
        'hourly_wage',
        'task_wage',
        'bonus',
        'premium',
        'commission',
        'allowance',
        'other',
    ];

    /** Pořadí skupin v nabídce sloupců a v tabulce. */
    private const COMPONENT_KIND_ORDER = [
        'premium',
        'allowance',
        'bonus',
        'commission',
        'hourly_wage',
        'task_wage',
        'compensation',
        'other',
    ];

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
        private readonly PayrollWageProrationService $wageProration,
        private readonly PayrollClosedRunGuard $closedRuns,
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
     * Kódy složek s pevným polem rychlého zadání (základ, přesčas, odměna,
     * příplatky § 115–118). Dynamický sloupec pro ně nevzniká.
     *
     * @return list<string>
     */
    public static function fixedComponentCodes(): array
    {
        return self::managedCodes();
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
     * Součtový řádek (`totals`) i počty řádků s hodnotou u sloupců složek
     * (`columns`) jsou za CELÝ zúžený měsíc, ne za stránku — kontrolní součet
     * proti podkladu, který by se měnil s listováním, nic nekontroluje. Proto
     * se měsíc prochází po dávkách celý (stejně jako přehled karet) a stránka
     * se z něj vyřízne. Částky jdou tou jedinou cestou, která je počítá i pro
     * řádek, takže se součet od řádků rozejít nemůže. Do 200 vztahů je to
     * jedna dávka se stejným počtem dotazů jako dřív stránka.
     *
     * @return array{
     *   period:string,items:list<array<string,mixed>>,total:int,
     *   columns:list<array<string,mixed>>,totals:array<string,mixed>
     * }
     */
    public function month(
        int $supplierId,
        string $period,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
        string $search = '',
    ): array {
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $all = [];
        $cursor = 0;
        $total = 0;
        do {
            $batch = $this->collect(
                $supplierId,
                $period,
                null,
                self::LIST_MAX_LIMIT,
                $cursor,
                $employmentId,
                $search,
            );
            array_push($all, ...$batch['items']);
            $total = $batch['total'];
            $cursor += count($batch['items']);
        } while ($cursor < $total && $batch['items'] !== []);

        // Uzavřená mzda se hlásí u řádku, aby ho formulář zamkl dřív, než do
        // něj někdo napíše; ukládání ho odmítne tak jako tak. Jeden dotaz za
        // celý měsíc, ne po řádcích. Neuložené návrhy ani nevytvořené vstupy
        // z pravidelných složek se u takového řádku nehlásí — nic z nich už
        // udělat nejde.
        $closedRuns = $this->closedRuns->closingRuns($supplierId, $period);
        $all = array_map(
            static fn (array $item): array => $item + [
                'closed_run' => $closedRuns[(int) $item['employment_id']] ?? null,
            ],
            $all,
        );
        $open = array_values(array_filter(
            $all,
            static fn (array $item): bool => $item['closed_run'] === null,
        ));
        $recurringPending = array_values(array_filter(
            $open,
            static fn (array $item): bool => (int) ($item['recurring_pending_count'] ?? 0) > 0,
        ));

        return [
            'period' => $period,
            'items' => array_slice($all, $offset, $limit),
            'total' => $total,
            'columns' => $this->componentColumns($supplierId, $period . '-01', $all),
            'totals' => self::periodTotals($all),
            'unsaved_suggestions' => self::unsavedSuggestions($open),
            // Za celý zúžený měsíc, ne za stránku: akce „vytvořit vstupy
            // z pravidelných složek" jde přes celý měsíc najednou.
            'recurring_pending' => [
                'employments' => count($recurringPending),
                'assignments' => array_sum(array_map(
                    static fn (array $item): int => (int) $item['recurring_pending_count'],
                    $recurringPending,
                )),
            ],
        ];
    }

    /**
     * Vztahy, u kterých formulář ukazuje základní mzdu jen jako návrh
     * ze sjednaných podmínek, ale žádný vstup za měsíc ještě neexistuje.
     *
     * Předvyplněné pole vypadá stejně jako uložená hodnota, jenže mzdový běh
     * ho nevidí a hlásí „chybí schválená mzdová složka". Počet je za celý
     * zúžený měsíc, protože uložení jde přes všechny stránky najednou.
     *
     * @param list<array<string,mixed>> $items
     * @return array{employments:int,amount_minor:int}
     */
    public static function unsavedSuggestions(array $items): array
    {
        $count = 0;
        $amount = 0;
        foreach ($items as $item) {
            $base = (int) ($item['base_amount_minor'] ?? 0);
            if ($base <= 0
                || ($item['base_managed_elsewhere'] ?? false) === true
                || ($item['base_requires_entry'] ?? false) === true
                || ($item['inputs']['base'] ?? null) !== null
            ) {
                continue;
            }
            $count++;
            $amount += $base;
        }

        return ['employments' => $count, 'amount_minor' => $amount];
    }

    /**
     * Sloupce mzdových složek, které rychlé zadání umí ukázat.
     *
     * Nabízí se každá účinná jednorázová složka firmy, kterou jde zadat ručně,
     * a k tomu KAŽDÁ složka, která má v měsíci hodnotu — i ta, kterou zadat
     * nejde (pravidelná, benefit, náhrada). Ta je v nabídce jen pro přehled,
     * aby účetní viděla, z čeho se hrubá mzda skládá.
     *
     * @param list<array<string,mixed>> $items celý zúžený měsíc
     * @return list<array<string,mixed>>
     */
    private function componentColumns(int $supplierId, string $periodStart, array $items): array
    {
        $rowsWithValue = [];
        foreach ($items as $item) {
            foreach ((array) ($item['components'] ?? []) as $code => $cell) {
                if (!is_array($cell) || !self::cellHasValue($cell)) {
                    continue;
                }
                $rowsWithValue[(string) $code] = ($rowsWithValue[(string) $code] ?? 0) + 1;
            }
        }

        $fixed = array_flip(self::managedCodes());
        $definitions = [];
        foreach ($this->effectiveDefinitions($supplierId, $periodStart) as $definition) {
            $code = PayrollTimeValue::string($definition['code'] ?? null, 'code');
            if (isset($fixed[$code])) {
                continue;
            }
            $editable = self::quickEditableDefinition($definition);
            if (!$editable && !isset($rowsWithValue[$code])) {
                continue;
            }
            $definitions[$code] = $definition + ['_editable' => $editable];
        }
        $missing = array_values(array_diff(array_keys($rowsWithValue), array_keys($definitions)));
        foreach ($this->definitionsByCode($supplierId, $missing) as $definition) {
            $code = PayrollTimeValue::string($definition['code'] ?? null, 'code');
            if (!isset($fixed[$code])) {
                $definitions[$code] = $definition + ['_editable' => false];
            }
        }

        $columns = [];
        foreach ($definitions as $code => $definition) {
            $kind = PayrollTimeValue::string($definition['component_kind'] ?? null, 'component_kind');
            $taxTreatment = PayrollTimeValue::string(
                $definition['tax_treatment'] ?? null,
                'tax_treatment',
            );
            $columns[] = [
                'code' => (string) $code,
                'name' => PayrollTimeValue::string($definition['name'] ?? null, 'name'),
                'kind' => $kind,
                'unit' => 'money',
                'editable_in_quick' => $definition['_editable'] === true,
                'rows_with_value' => $rowsWithValue[(string) $code] ?? 0,
                // Hrubý náhled tu složku sčítá? Rozhoduje totéž pravidlo jako
                // u řádku: pevný slot, nebo zdanitelné zařazení.
                'counts_in_gross' => self::managedSlot((string) $code, $kind) !== null
                    || in_array($taxTreatment, ['included', 'withholding_candidate'], true),
            ];
        }
        usort($columns, static function (array $left, array $right): int {
            $order = array_flip(self::COMPONENT_KIND_ORDER);
            return [
                $order[$left['kind']] ?? count($order),
                $left['name'],
                $left['code'],
            ] <=> [
                $order[$right['kind']] ?? count($order),
                $right['name'],
                $right['code'],
            ];
        });

        return $columns;
    }

    /**
     * Smí se složka v rychlém zadání NOVĚ zadat? Jediné místo tohoto pravidla —
     * řídí se jím nabídka sloupců i ukládání, aby se nerozešly.
     *
     * @param array<string,mixed> $definition
     */
    private static function quickEditableDefinition(array $definition): bool
    {
        $code = PayrollTimeValue::string($definition['code'] ?? null, 'code');

        return !in_array($code, self::managedCodes(), true)
            && ($definition['frequency_kind'] ?? null) === 'one_off'
            && ($definition['value_kind'] ?? null) === 'monetary'
            && ($definition['tax_treatment'] ?? null) !== 'manual_review'
            && in_array($definition['component_kind'] ?? null, self::QUICK_COMPONENT_KINDS, true);
    }

    /**
     * Účinná verze každé aktivní složky firmy k začátku měsíce.
     *
     * @return list<array<string,mixed>>
     */
    private function effectiveDefinitions(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ranked.id, ranked.code, ranked.name, ranked.component_kind,
                    ranked.value_kind, ranked.frequency_kind, ranked.tax_treatment
               FROM (
                     SELECT component.id, component.code, component.name,
                            component.component_kind, component.value_kind,
                            component.frequency_kind, component.tax_treatment,
                            ROW_NUMBER() OVER (
                              PARTITION BY component.code
                              ORDER BY component.valid_from DESC, component.id DESC
                            ) AS position_no
                       FROM payroll_component_definitions component
                      WHERE component.supplier_id = ?
                        AND component.is_active = 1
                        AND component.valid_from <= ?
                        AND (component.valid_to IS NULL OR component.valid_to >= ?)
                    ) ranked
              WHERE ranked.position_no = 1'
        );
        $stmt->execute([$supplierId, $periodStart, $periodStart]);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'quick_component_definitions');
    }

    /**
     * Nejnovější verze vyjmenovaných složek bez ohledu na účinnost — pro
     * složky, které mají v měsíci hodnotu, ale mezitím je někdo ukončil.
     *
     * @param list<string> $codes
     * @return list<array<string,mixed>>
     */
    private function definitionsByCode(int $supplierId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ranked.id, ranked.code, ranked.name, ranked.component_kind,
                    ranked.value_kind, ranked.frequency_kind, ranked.tax_treatment
               FROM (
                     SELECT component.id, component.code, component.name,
                            component.component_kind, component.value_kind,
                            component.frequency_kind, component.tax_treatment,
                            ROW_NUMBER() OVER (
                              PARTITION BY component.code
                              ORDER BY component.valid_from DESC, component.id DESC
                            ) AS position_no
                       FROM payroll_component_definitions component
                      WHERE component.supplier_id = ?
                        AND component.code IN ('
            . implode(',', array_fill(0, count($codes), '?'))
            . ')
                    ) ranked
              WHERE ranked.position_no = 1'
        );
        $stmt->execute([$supplierId, ...$codes]);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'quick_component_definitions');
    }

    /**
     * Součtový řádek za celý zúžený měsíc. Sčítají se hodnoty, které server
     * spočítal pro jednotlivé řádky — jiná cesta by se od řádků rozešla.
     *
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function periodTotals(array $items): array
    {
        $totals = [
            'rows' => count($items),
            'base_amount_minor' => 0,
            'overtime_amount_minor' => 0,
            'bonus_amount_minor' => 0,
            'surcharge_amount_minor' => 0,
            'other_amount_minor' => 0,
            'excluded_from_gross_amount_minor' => 0,
            'gross_preview_minor' => 0,
            'surcharges' => [],
            'components' => [],
        ];
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $totals['surcharges'][$kind->value] = 0;
        }
        foreach ($items as $item) {
            foreach ([
                'base_amount_minor',
                'overtime_amount_minor',
                'bonus_amount_minor',
                'surcharge_amount_minor',
                'other_amount_minor',
                'excluded_from_gross_amount_minor',
                'gross_preview_minor',
            ] as $key) {
                $totals[$key] += PayrollTimeValue::int($item[$key] ?? null, $key);
            }
            foreach ((array) ($item['surcharges'] ?? []) as $kind => $state) {
                if (is_array($state) && isset($totals['surcharges'][$kind])) {
                    $totals['surcharges'][$kind] += (int) ($state['amount_minor'] ?? 0)
                        + (int) ($state['managed_amount_minor'] ?? 0);
                }
            }
            foreach ((array) ($item['components'] ?? []) as $code => $cell) {
                if (!is_array($cell)) {
                    continue;
                }
                $current = $totals['components'][$code] ?? [
                    'amount_minor' => 0,
                    'quantity_milliunits' => null,
                    'rows_with_value' => 0,
                ];
                $current['amount_minor'] += (int) $cell['amount_minor'];
                if ($cell['quantity_milliunits'] !== null) {
                    $current['quantity_milliunits'] = ($current['quantity_milliunits'] ?? 0)
                        + (int) $cell['quantity_milliunits'];
                }
                if (self::cellHasValue($cell)) {
                    ++$current['rows_with_value'];
                }
                $totals['components'][$code] = $current;
            }
        }

        return $totals;
    }

    /** @param array<string,mixed> $cell */
    private static function cellHasValue(array $cell): bool
    {
        return (int) ($cell['amount_minor'] ?? 0) !== 0
            || ($cell['quantity_milliunits'] ?? null) !== null
            || ($cell['input_count'] ?? 0) > 0;
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
        string $search = '',
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $periodStart = $period . '-01';
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
        $year = (int) substr($period, 0, 4);
        $this->components->list($supplierId, $periodStart);

        $focusEmploymentId = $employmentIds === null ? $focusEmploymentId : null;
        // Hledání zužuje jen stránku měsíce. Výčet vztahů při ukládání zúžit
        // nesmí: uložit se musí i rozepsaný řádek, který aktuální hledání
        // zrovna nevrací.
        $searchClause = self::searchClause($employmentIds === null ? $search : '');
        $employmentFilter = ($employmentIds === null
            ? ($focusEmploymentId === null ? '' : ' AND employment.id = ?')
            : ' AND employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')')
            . $searchClause['sql'];

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
                    ' . PayrollHourlyRemuneration::overlapsPeriodSql() . '
                      AS hourly_remuneration_in_period,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . '
                      AS monthly_gross_minor,
                    employment.start_date,
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
                        SELECT time_month.work_source
                          FROM payroll_time_months time_month
                         WHERE time_month.supplier_id = employment.supplier_id
                           AND time_month.employment_id = employment.id
                           AND time_month.period_start = ?
                    ) AS time_work_source,
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
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodStart,
            $periodStart,
            $year,
            $quarter,
            $periodEnd,
            $periodStart,
            ...($employmentIds ?? ($focusEmploymentId === null ? [] : [$focusEmploymentId])),
            ...$searchClause['params'],
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
            ? $this->countMonth($supplierId, $periodStart, $periodEnd, $focusEmploymentId, $search)
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

        // Zrušený importní vstup se čte jen tehdy, když na něj ukazuje živý ruční
        // přepis — je to „hodnota z importu", ke které se lze vrátit. Do žádné
        // částky se nesčítá (viz buildItem()).
        $inputStmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.employment_id, input.component_id, input.amount_minor,
                    input.quantity_milliunits, input.source_kind, input.external_id,
                    input.status, input.row_version, input.source_snapshot_json,
                    input.recurring_component_id,
                    component.code AS component_code,
                    component.component_kind, component.value_kind,
                    component.tax_treatment
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.period_start = ?
                AND (
                      input.status <> "cancelled"
                      OR (
                          input.source_kind = "import"
                          AND EXISTS (
                              SELECT 1
                                FROM payroll_inputs override_input
                               WHERE override_input.supplier_id = input.supplier_id
                                 AND override_input.employment_id = input.employment_id
                                 AND override_input.period_start = input.period_start
                                 AND override_input.source_kind = "manual"
                                 AND override_input.status <> "cancelled"
                                 AND override_input.external_id = CONCAT(?, input.id)
                          )
                      )
                )'
            . sprintf($pageFilter, 'input')
            . ' ORDER BY input.id'
        );
        $inputStmt->execute([
            $supplierId,
            $periodStart,
            self::OVERRIDE_PREFIX,
            ...$employmentIdsOnPage,
        ]);
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
                    component.tax_treatment,
                    ' . PayrollHourlyRemuneration::overlapsPeriodSql() . '
                      AS hourly_remuneration_in_period,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . '
                      AS monthly_gross_minor,
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
            $periodEnd,
            $periodStart,
            $periodEnd,
            $periodEnd,
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
        $employerDefault = $this->surcharges->policyForEmployer(
            $supplierId,
            $periodStart,
            $ruleset,
        );
        $claims = $this->surchargeClaims->sourcesForPeriod(
            $supplierId,
            $periodStart,
            $employmentIdsOnPage,
        );
        $workedTime = $this->workedTimeForPage($supplierId, $periodStart, $periodEnd, $rows, $employmentIdsOnPage);
        $hourlyPreviews = (new PayrollHourlyWagePreview($this->db))->forMany($supplierId, $periodStart, $rows);

        $items = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
            $item = $this->buildItem(
                $supplierId,
                $period,
                $row,
                $byEmployment[$employmentId] ?? [],
                $recurringByEmployment[$employmentId] ?? [],
                $periodStart,
                $periodEnd,
                $ruleset,
                // Vztah bez vlastního sjednání dědí firemní výchozí sazby
                // (migrace 1846); teprve pod nimi platí zákonné minimum.
                // Čte se JEDNOU pro celou stránku, ne po řádcích.
                $policies[$employmentId] ?? $employerDefault,
                $claims[$employmentId] ?? [],
            );
            $item['worked_time'] = $workedTime[$employmentId];
            $preview = $hourlyPreviews[$employmentId] ?? null;
            if ($preview !== null) {
                unset($preview['_source_snapshot']);
                foreach ($preview['blockers'] as $blocker) $item['blockers'][] = (string) $blocker['code'];
                $item['blockers'] = array_values(array_unique($item['blockers']));
            }
            $item['hourly_wage_preview'] = $preview;
            $items[] = $item;
        }
        return ['period' => $period, 'items' => $items, 'total' => $total];
    }

    /** @param list<array<string,mixed>> $rows @param list<int> $ids @return array<int,array<string,mixed>> */
    private function workedTimeForPage(int $supplierId, string $periodStart, string $periodEnd, array $rows, array $ids): array
    {
        $evidence = (new PayrollWorkedTimeEvidence($this->db))->loadMany($supplierId, $ids, $periodStart);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $terms = $this->db->pdo()->prepare("SELECT employment_id, effective_from, effective_to FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id IN ($in) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY employment_id, effective_from, id");
        $terms->execute([$supplierId, ...$ids, $periodEnd, $periodStart]);
        $byEmployment = [];
        foreach ($terms->fetchAll(PDO::FETCH_ASSOC) as $term) $byEmployment[(int) $term['employment_id']][] = $term;
        $confirmed = $this->db->pdo()->prepare("SELECT month_row.employment_id FROM payroll_time_months month_row JOIN payroll_jmhz_work_month_revisions summary ON summary.supplier_id = month_row.supplier_id AND summary.time_month_id = month_row.id AND summary.time_month_revision_no = month_row.revision_no WHERE month_row.supplier_id = ? AND month_row.period_start = ? AND month_row.status = 'approved' AND month_row.employment_id IN ($in) GROUP BY month_row.employment_id");
        $confirmed->execute([$supplierId, $periodStart, ...$ids]);
        $confirmedIds = array_fill_keys(array_map('intval', $confirmed->fetchAll(PDO::FETCH_COLUMN)), true);
        $imports = $this->db->pdo()->prepare("SELECT DISTINCT employment_id FROM payroll_time_month_import_summaries WHERE supplier_id = ? AND period_start = ? AND employment_id IN ($in)");
        $imports->execute([$supplierId, $periodStart, ...$ids]);
        $importIds = array_fill_keys(array_map('intval', $imports->fetchAll(PDO::FETCH_COLUMN)), true);
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['employment_id'];
            $start = max($periodStart, (string) ($row['actual_start_date'] ?? $row['start_date'] ?? $periodStart));
            $end = min($periodEnd, (string) ($row['end_date'] ?? $periodEnd));
            $ranges = [];
            foreach ($byEmployment[$id] ?? [] as $term) {
                $from = max($start, (string) $term['effective_from']);
                $to = min($end, (string) ($term['effective_to'] ?? $end));
                if ($from <= $to) $ranges[] = ['date_from' => $from, 'date_to' => $to];
            }
            $source = $evidence[$id] ?? null;
            $hasExplicitSource = $source !== null && ($source['work_source'] !== 'entries' || ($source['time_entries'] ?? []) !== []);
            $value = $source !== null && $source['status'] === 'approved' && $hasExplicitSource
                ? PayrollWorkedTimeSource::fromSnapshot($source, $periodStart) : null;
            if ($value !== null && $value['issues'] !== []) $value = null;
            $result[$id] = [
                'month' => $source === null ? null : [
                    'id' => $source['time_month_id'], 'status' => $source['status'],
                    'revision_no' => $source['revision_no'], 'row_version' => $source['row_version'],
                    'work_source' => $source['work_source'],
                ],
                'manual_summary' => $source['manual_summary'] ?? null,
                'suggested_segments' => $ranges,
                'worked_duration_units' => $value['worked_duration_units'] ?? null,
                'overtime_duration_units' => $value['overtime_duration_units'] ?? null,
                'worked_days' => $value['worked_days'] ?? null,
                'jmhz_confirmed' => isset($confirmedIds[$id]),
                'available_sources' => isset($importIds[$id]) ? ['entries', 'import_summary'] : ['entries'],
            ];
        }
        return $result;
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
                    // Pevná částka za hodinu (migrace 1845) musí do rychlého
                    // zadání stejně jako sazba. Bez ní by se týž nárok počítal
                    // na dvou obrazovkách jinak: docházka podle sjednaných
                    // 42 Kč/h, rychlý vstup podle zákonných deseti procent.
                    [
                        PayrollSurchargeKind::Overtime->value =>
                            self::nullableColumn($row, 'overtime_fixed_hourly_minor'),
                        PayrollSurchargeKind::Holiday->value =>
                            self::nullableColumn($row, 'holiday_fixed_hourly_minor'),
                        PayrollSurchargeKind::Night->value =>
                            self::nullableColumn($row, 'night_fixed_hourly_minor'),
                        PayrollSurchargeKind::Weekend->value =>
                            self::nullableColumn($row, 'weekend_fixed_hourly_minor'),
                        PayrollSurchargeKind::DifficultEnvironment->value =>
                            self::nullableColumn($row, 'difficult_environment_fixed_hourly_minor'),
                    ],
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
        string $search = '',
    ): int {
        // Počet se zužuje TÝMŽ hledáním jako stránka, jinak by pager nabízel
        // stránky, na kterých po hledání nikdo není.
        $searchClause = self::searchClause($search);
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
            . $searchClause['sql']
        );
        $stmt->execute([
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodStart,
            ...($focusEmploymentId === null ? [] : [$focusEmploymentId]),
            ...$searchClause['params'],
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array{
     *   employment_id:int,employment_row_version:int,base_amount_minor:?int,overtime_mode:string,
     *   overtime_hours_milli:?int,overtime_amount_minor:?int,bonus_amount_minor:int,
     *   overtime_average_snapshot_id:?int,overtime_average_snapshot_version:?int,
     *   surcharges:array<string,array{hours_milli:?int,factors:?int}>,
     *   components:array<string,array{amount_minor:?int,quantity_milliunits:?int,
     *     quantity_provided:bool,row_version:?int,revert:bool}>,
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
        string $search = '',
    ): array {
        $collected = [];
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $requestedIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['employment_id'], $rows)));
            sort($requestedIds, SORT_NUMERIC);
            $claimed = [];
            if ($requestedIds !== []) {
                $in = implode(',', array_fill(0, count($requestedIds), '?'));
                $lock = $pdo->prepare("SELECT id FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
                $lock->execute([$supplierId, ...$requestedIds]);
                $lock->fetchAll(PDO::FETCH_COLUMN);
                $query = $pdo->prepare("SELECT claim.employment_id, claim.purpose,
                    SUM(CASE WHEN m.input_id IS NULL OR input.status <> 'cancelled' THEN m.delta_amount_minor ELSE 0 END) AS amount,
                    SUM(CASE WHEN m.input_id IS NULL OR input.status <> 'cancelled' THEN m.duration_delta_units ELSE 0 END) AS duration
                    FROM payroll_hourly_wage_claims claim
                    JOIN payroll_hourly_wage_materializations m ON m.supplier_id = claim.supplier_id AND m.claim_id = claim.id
                    LEFT JOIN payroll_inputs input ON input.supplier_id = m.supplier_id AND input.id = m.input_id
                    WHERE claim.supplier_id = ? AND claim.employment_id IN ($in) AND claim.work_period_start = ? AND claim.claim_source = 'hourly_calculation'
                    GROUP BY claim.employment_id, claim.purpose HAVING amount <> 0 OR duration <> 0");
                $query->execute([$supplierId, ...$requestedIds, $period . '-01']);
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $claim) $claimed[(int) $claim['employment_id']][(string) $claim['purpose']] = true;
            }
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
                $purposes = $claimed[(int) $item['employment_id']] ?? [];
                $item['_hourly_claimed'] = isset($purposes['ordinary_base']) || isset($purposes['achieved_overtime']);
                $item['_hourly_overtime_claimed'] = isset($purposes['achieved_overtime']) || isset($purposes['premium_overtime']);
                $items[(int) $item['employment_id']] = $item;
            }
            $closedRuns = $this->closedRuns->closingRuns($supplierId, $period, array_keys($items));
            $componentIds = $this->componentIds($supplierId, $period . '-01');
            /** @var array<string,array<string,mixed>>|null $dynamicDefinitions načte se až u první buňky složky */
            $dynamicDefinitions = null;
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
                if (isset($closedRuns[$employmentId])) {
                    // Mzda za měsíc je u vztahu uzavřená. Nový podklad by se do
                    // výplaty nedostal a v přehledu by vypadal, že dostal.
                    $collected[] = self::failure(
                        $employmentId,
                        'row',
                        new PayrollRunClosedException($closedRuns[$employmentId]),
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
                    if (($item['_hourly_claimed'] ?? false) && $row['base_amount_minor'] !== null
                        && $row['base_amount_minor'] !== $item['base_amount_minor']) {
                        throw new \DomainException('Základní mzda je již spravována výpočtem hodinové mzdy.');
                    }
                    if (($item['hourly_remuneration_in_period'] ?? false) === true) {
                        if ($row['base_amount_minor'] !== null
                            && $row['base_amount_minor'] !== (int) $item['base_amount_minor']
                        ) {
                            throw new \DomainException(PayrollHourlyRemuneration::MESSAGE);
                        }
                        return;
                    }
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
                        self::componentId($componentIds, self::BASE_CODE),
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
                    if (($item['_hourly_overtime_claimed'] ?? false)
                        && (($hours !== null && $hours !== 0) || ($overtimeAmount !== null && $overtimeAmount !== 0))) {
                        throw new \DomainException('Přesčasová mzda je již spravována výpočtem hodinové mzdy.');
                    }
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
                        self::componentId($componentIds, self::BONUS_CODE),
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

                // Dynamické sloupce mzdových složek. Každá buňka má vlastní
                // savepoint: zamčený importní vstup jedné složky nesmí shodit
                // uložení ostatních.
                foreach ($row['components'] ?? [] as $code => $entry) {
                    $code = (string) $code;
                    $this->guard(
                        $pdo,
                        $collected,
                        $employmentId,
                        self::COMPONENT_FIELD_PREFIX . $code,
                        function () use (
                            $supplierId,
                            $employmentId,
                            $item,
                            $code,
                            $entry,
                            $period,
                            $userId,
                            $autoApprove,
                            &$dynamicDefinitions,
                        ): void {
                            $dynamicDefinitions ??= $this->definitionMap(
                                $this->effectiveDefinitions($supplierId, $period . '-01'),
                            );
                            $this->saveComponent(
                                $supplierId,
                                $employmentId,
                                $item,
                                $code,
                                $entry,
                                $dynamicDefinitions,
                                $period,
                                $userId,
                                $autoApprove,
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
        return $this->month($supplierId, $period, $limit, $offset, $focusEmploymentId, $search);
    }

    /**
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $inputs
     * @param list<array<string,mixed>> $recurring
     * @return array<string,mixed>
     */
    private function buildItem(
        int $supplierId,
        string $period,
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
        $hourlyRemuneration = (bool) ($employment['hourly_remuneration_in_period'] ?? false);
        $blockers = [];
        /** @var array<string,bool> $fromAttendance */
        $fromAttendance = [];
        $other = 0;
        $nonMonetary = 0;
        $excludedFromGross = 0;
        $fixedCodes = array_flip(self::managedCodes());
        /** @var array<int,array<string,mixed>> $overriddenImports */
        $overriddenImports = [];
        foreach ($inputs as $input) {
            if (($input['status'] ?? null) === 'cancelled') {
                $overriddenImports[PayrollTimeValue::int($input['id'] ?? null, 'id')] = $input;
            }
        }
        /** @var array<string,list<array<string,mixed>>> $componentInputs */
        $componentInputs = [];
        /** @var array<string,array{amount_minor:int,supported:bool}> $componentRecurring */
        $componentRecurring = [];
        foreach ($inputs as $input) {
            if (($input['status'] ?? null) === 'cancelled') {
                // Přepsaná hodnota z importu: doklad, ne částka.
                continue;
            }
            $code = PayrollTimeValue::string($input['component_code'] ?? null, 'component_code');
            $kind = PayrollTimeValue::string(
                $input['component_kind'] ?? null,
                'component_kind',
            );
            $externalId = $input['external_id'] === null
                ? null
                : PayrollTimeValue::string($input['external_id'], 'external_id');
            if (!isset($fixedCodes[$code])) {
                $componentInputs[$code][] = $input;
            }
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
            if (($managedSlot === 'overtime' || $managedSlot === 'bonus')
                && self::hasOwnColumnOrigin($code, $externalId, $overriddenImports)
            ) {
                // Složka z importu docházky (a její ruční přepis nebo vlastní
                // zadání ve sloupci složky) má VLASTNÍ sloupec. Dokud padala
                // podle druhu do slotu přesčasu nebo odměny, `PRIPLATEK_BOZP`
                // zamkl přesčas hláškou „spravuje jiný vstup" a ani jedno nešlo
                // upravit. Základ se tu záměrně nepouští: hodinová mzda
                // z docházky musí dál brzdit návrh měsíční mzdy, jinak by
                // uložení stránky přidalo hodinovému zaměstnanci i měsíční.
                $managedSlot = null;
            }
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

        // Předpis, ze kterého už za měsíc vznikl vstup, se podruhé nesčítá: jeho
        // částku nese ten vstup. Dokud tu kontrola chyběla, ukazoval řádek po
        // vytvoření vstupů z pravidelných složek dvojnásobnou základní mzdu.
        $materializedRecurring = [];
        foreach ($inputs as $input) {
            if (($input['status'] ?? null) !== 'cancelled'
                && ($input['recurring_component_id'] ?? null) !== null
            ) {
                $materializedRecurring[(int) $input['recurring_component_id']] = true;
            }
        }
        $recurringPending = 0;
        foreach ($recurring as $assignment) {
            if (isset($materializedRecurring[(int) ($assignment['id'] ?? 0)])) {
                continue;
            }
            ++$recurringPending;
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
            if (!isset($fixedCodes[$code])) {
                $supported = $calculation['status'] === 'supported'
                    && is_int($calculation['amount_minor']);
                $componentRecurring[$code] = [
                    'amount_minor' => ($componentRecurring[$code]['amount_minor'] ?? 0)
                        + ($supported ? (int) $calculation['amount_minor'] : 0),
                    'supported' => ($componentRecurring[$code]['supported'] ?? true) && $supported,
                ];
            }
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
        $awayInMonth = PayrollTimeValue::int(
            $employment['away_in_month'] ?? null,
            'away_in_month',
        ) === 1;
        // Mzda přísluší za vykonanou práci (§ 109 odst. 1 ZP), takže návrh
        // měsíční mzdy nesmí ignorovat evidované absence. Poměr se počítá
        // z HODIN individuálního rozvrhu, ne z pracovních dnů — viz
        // {@see \MyInvoice\Service\Payroll\Calculation\MonthlyWageProration}.
        // Dotaz se pouští jen u vztahu, který v měsíci nějakou absenci má;
        // `away_in_month` to ví už z hlavního dotazu, takže běžný řádek
        // nic navíc nestojí.
        // Měsíc ze souhrnu importu docházky nemá směny, takže nepřítomnost
        // v něm nese jen měsíční součet hodin — krátí se podle něj.
        $importSummaryMonth = ($employment['time_work_source'] ?? null) === 'import_summary';
        // Nástup nebo skončení v průběhu měsíce se krátí stejně jako předpis
        // ({@see \MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer}):
        // hodinami fondu mimo trvání vztahu, spolu s nepřítomnostmi.
        $proration = null;
        if (($awayInMonth || $importSummaryMonth || $partialMonth)
            && !$suspendedInMonth
            && !$managed['base']
            && !$hourlyRemuneration
            && $employment['monthly_gross_minor'] !== null
        ) {
            $proration = ($importSummaryMonth
                ? $this->wageProration->forImportSummary(...)
                : $this->wageProration->forMonth(...))(
                $supplierId,
                PayrollTimeValue::int($employment['employment_id'] ?? null, 'employment_id'),
                $period,
                PayrollTimeValue::int(
                    $employment['monthly_gross_minor'],
                    'monthly_gross_minor',
                ),
                $partialMonth
                    ? ['from' => max((string) $effectiveStart, $periodStart), 'to' => min((string) $effectiveEnd, $periodEnd)]
                    : null,
            );
        }
        $partialProrated = $partialMonth && $proration !== null && $proration['supported'] === true;
        $prorationUnsupported = $proration !== null && $proration['supported'] === false;

        $baseRequiresEntry = (($partialMonth && !$partialProrated) || $suspendedInMonth || $prorationUnsupported)
            && !$managed['base']
            && $quick['base'] === null;
        if ($baseRequiresEntry) {
            $blockers[] = match (true) {
                $suspendedInMonth => 'suspended_month_base_required',
                $partialMonth => 'partial_month_base_required',
                default => 'absence_month_base_required',
            };
        }

        $base = $managed['base']
            ? $managedAmounts['base'] + ($quick['base']['amount_minor'] ?? 0)
            : ($quick['base']['amount_minor'] ?? (
                $hourlyRemuneration || $baseRequiresEntry || $employment['monthly_gross_minor'] === null
                    ? 0
                    : ($proration['amount_minor'] ?? PayrollTimeValue::int(
                        $employment['monthly_gross_minor'],
                        'monthly_gross_minor',
                    ))
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
            'away_in_month' => $awayInMonth,
            'base_amount_minor' => $base,
            'hourly_remuneration_in_period' => $hourlyRemuneration,
            'base_managed_elsewhere' => $managed['base'],
            // Pravidelné složky účinné v měsíci, ze kterých ještě nevznikl vstup.
            // Mzdový běh je nevidí, dokud je někdo nevytvoří a neschválí.
            'recurring_pending_count' => $recurringPending,
            'base_conflict' => $conflicts['base'],
            'partial_month' => $partialMonth,
            'base_requires_entry' => $baseRequiresEntry,
            // Doklad ke krácení: fond měsíce, hodiny ponechané v základní mzdě
            // a hodiny nahrazené jiným titulem. `null` = vztah v měsíci žádnou
            // absenci nemá a krátit není za co.
            'base_proration' => $proration === null || $proration['trace'] === null
                ? null
                : [
                    'fund_minutes' => $proration['fund_minutes'],
                    'replaced_minutes' => $proration['replaced_minutes'],
                    'replaced_minutes_by_title' => $proration['replaced_minutes_by_title'],
                    'amount_minor' => $proration['amount_minor'],
                ],
            'base_proration_unsupported_reason' => $prorationUnsupported
                ? $proration['reason']
                : null,
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
                !$hourlyRemuneration && $overtimeHoursRelationSupported && is_int($rate) && $rate > 0,
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
            // Rozpad po mzdových složkách mimo pevná pole. Je to POHLED na
            // částky, které už jsou v polích nahoře a v `other_amount_minor`;
            // do hrubého náhledu se znovu nepřičítá.
            'components' => $this->componentCells($componentInputs, $componentRecurring, $overriddenImports),
            'inputs' => $quick,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Pochází vstup ze zdroje, který má v rychlém zadání vlastní sloupec?
     *
     * Tedy import docházky, ruční přepis importované hodnoty docházky, nebo
     * vlastní zadání do sloupce složky. Pevné kódy mají vlastní pole a tady
     * nikdy neprojdou.
     *
     * @param array<int,array<string,mixed>> $overriddenImports
     */
    private static function hasOwnColumnOrigin(
        string $code,
        ?string $externalId,
        array $overriddenImports,
    ): bool {
        if ($externalId === null || in_array($code, self::managedCodes(), true)) {
            return false;
        }
        if (str_starts_with($externalId, self::ATTENDANCE_IMPORT_PREFIX)
            || $externalId === self::EXTERNAL_PREFIX . $code
        ) {
            return true;
        }
        $origin = self::overriddenImport($externalId, $overriddenImports);

        return $origin !== null
            && is_string($origin['external_id'] ?? null)
            && str_starts_with($origin['external_id'], self::ATTENDANCE_IMPORT_PREFIX);
    }

    /**
     * Importní vstup, na který ukazuje `override:<id>`, je-li mezi načtenými.
     *
     * @param array<int,array<string,mixed>> $overriddenImports
     * @return array<string,mixed>|null
     */
    private static function overriddenImport(?string $externalId, array $overriddenImports): ?array
    {
        if ($externalId === null || !str_starts_with($externalId, self::OVERRIDE_PREFIX)) {
            return null;
        }
        $id = substr($externalId, strlen(self::OVERRIDE_PREFIX));
        if (!ctype_digit($id)) {
            return null;
        }

        return $overriddenImports[(int) $id] ?? null;
    }

    /**
     * Buňky dynamických sloupců jednoho řádku.
     *
     * `mode` říká, co s buňkou jde udělat, a rozhoduje o tom server:
     *  - `manual`   vlastní zadání z rychlého vstupu — upravit, vymazat;
     *  - `import`   jediný importní vstup — upravit znamená ručně přepsat;
     *  - `override` ruční přepis importu — upravit, nebo vrátit na import;
     *  - `managed`  cokoli jiného (pravidelná složka, více vstupů, vstup
     *               z docházky či absence) — jen ke čtení.
     *
     * @param array<string,list<array<string,mixed>>> $componentInputs
     * @param array<string,array{amount_minor:int,supported:bool}> $componentRecurring
     * @param array<int,array<string,mixed>> $overriddenImports
     * @return array<string,array<string,mixed>>
     */
    private function componentCells(
        array $componentInputs,
        array $componentRecurring,
        array $overriddenImports,
    ): array {
        $cells = [];
        $codes = array_values(array_unique([
            ...array_map('strval', array_keys($componentInputs)),
            ...array_map('strval', array_keys($componentRecurring)),
        ]));
        foreach ($codes as $code) {
            $inputs = $componentInputs[$code] ?? [];
            $recurring = $componentRecurring[$code] ?? null;
            $amount = $recurring['amount_minor'] ?? 0;
            $quantity = null;
            foreach ($inputs as $input) {
                $amount += PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
                if ($input['quantity_milliunits'] !== null) {
                    $quantity = ($quantity ?? 0)
                        + PayrollTimeValue::int($input['quantity_milliunits'], 'quantity_milliunits');
                }
            }
            $primary = count($inputs) === 1 && $recurring === null ? $inputs[0] : null;
            $mode = 'managed';
            $overrideOf = null;
            if ($primary !== null) {
                $sourceKind = PayrollTimeValue::string($primary['source_kind'] ?? null, 'source_kind');
                $externalId = $primary['external_id'] === null
                    ? null
                    : PayrollTimeValue::string($primary['external_id'], 'external_id');
                $origin = self::overriddenImport($externalId, $overriddenImports);
                if ($sourceKind === 'manual' && $externalId === self::EXTERNAL_PREFIX . $code) {
                    $mode = 'manual';
                } elseif ($sourceKind === 'manual' && $origin !== null) {
                    $mode = 'override';
                    $overrideOf = [
                        'input_id' => PayrollTimeValue::int($origin['id'] ?? null, 'id'),
                        'amount_minor' => PayrollTimeValue::int(
                            $origin['amount_minor'] ?? null,
                            'amount_minor',
                        ),
                        'quantity_milliunits' => $origin['quantity_milliunits'] === null
                            ? null
                            : PayrollTimeValue::int(
                                $origin['quantity_milliunits'],
                                'quantity_milliunits',
                            ),
                        'external_id' => $origin['external_id'] === null
                            ? null
                            : PayrollTimeValue::string($origin['external_id'], 'external_id'),
                    ];
                } elseif ($sourceKind === 'import') {
                    $mode = 'import';
                }
            }
            $first = $primary ?? ($inputs[0] ?? null);
            $status = $first === null
                ? null
                : PayrollTimeValue::string($first['status'] ?? null, 'status');
            $cells[$code] = [
                'amount_minor' => $amount,
                'quantity_milliunits' => $quantity,
                'status' => $status,
                'source' => $first === null
                    ? 'recurring'
                    : self::inputSource(
                        PayrollTimeValue::string($first['source_kind'] ?? null, 'source_kind'),
                        $first['external_id'] === null
                            ? null
                            : PayrollTimeValue::string($first['external_id'], 'external_id'),
                    ),
                'mode' => $mode,
                'input_id' => $primary === null
                    ? null
                    : PayrollTimeValue::int($primary['id'] ?? null, 'id'),
                'component_id' => $primary === null
                    ? null
                    : PayrollTimeValue::int($primary['component_id'] ?? null, 'component_id'),
                'row_version' => $primary === null
                    ? null
                    : PayrollTimeValue::int($primary['row_version'] ?? null, 'row_version'),
                'external_id' => $primary === null || $primary['external_id'] === null
                    ? null
                    : PayrollTimeValue::string($primary['external_id'], 'external_id'),
                'override_of' => $overrideOf,
                'input_count' => count($inputs),
                'has_recurring' => $recurring !== null,
                // Upravit jde vlastní zadání, importní hodnotu (přepisem)
                // a přepis. Uzamčený vstup jen opravnou revizí; schválený smí
                // měnit jen ten, kdo schvaluje (to posoudí prohlížeč i server).
                'entry_available' => $mode !== 'managed' && $status !== 'locked',
            ];
        }

        return $cells;
    }

    /** Odkud hodnota v buňce přišla — pro ikonu zdroje. */
    private static function inputSource(string $sourceKind, ?string $externalId): string
    {
        if ($externalId !== null && str_starts_with($externalId, self::TIME_SURCHARGE_PREFIX)) {
            return 'surcharge';
        }

        return match ($sourceKind) {
            'import' => 'import',
            'recurring' => 'recurring',
            'time' => 'attendance',
            default => 'manual',
        };
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

        if (($item['hourly_remuneration_in_period'] ?? false) === true) {
            throw new \DomainException(PayrollHourlyRemuneration::MESSAGE);
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

        // Pevná částka za hodinu (migrace 1845) nahrazuje hodinovou sazbu.
        // Zákonné minimum § 114 je kogentní, takže nižší sjednaná částka se
        // dorovnává — jinak by rychlé zadání vyplatilo míň než docházka.
        $fixedHourly = $policy->agreedFixedHourlyMinor(PayrollSurchargeKind::Overtime);
        $statutoryHourly = RoundingMode::HalfUp->roundFraction(
            self::multiplyExactly($rate, $effective['rate']->numerator),
            $effective['rate']->denominator,
        );
        $belowStatutory = $fixedHourly !== null && $fixedHourly < $statutoryHourly;
        $appliedHourly = $belowStatutory ? $statutoryHourly : $fixedHourly;

        // Příplatková polovina: `PV × čitatel × hodiny / (jmenovatel × 1000)`.
        // Jedním zlomkem, aby se nezaokrouhlovalo dvakrát — stejně jako
        // {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeLine}.
        $premium = $mode === PayrollSurchargeCompensationMode::CompensatoryTimeOff
            ? 0
            : ($appliedHourly === null
                ? RoundingMode::HalfUp->roundFraction(
                    self::multiplyExactly(
                        self::multiplyExactly($rate, $effective['rate']->numerator),
                        $hours,
                    ),
                    self::multiplyExactly($effective['rate']->denominator, 1_000),
                )
                : RoundingMode::HalfUp->roundFraction(
                    self::multiplyExactly($appliedHourly, $hours),
                    1_000,
                ));

        $fundMinutes = $this->fund->wageFundMinutes($supplierId, $employmentId, $period);
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
            'agreed_fixed_hourly_minor' => $fixedHourly,
            'applied_fixed_hourly_minor' => $appliedHourly,
            'below_statutory' => $belowStatutory,
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
                self::componentId($componentIds, $kind->componentCode()),
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
            $amount = PayrollSurchargeInputMaterializer::wholeCzk(
                $computed['amount_minor'],
                PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId),
            );
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
            self::componentId($componentIds, $kind->componentCode()),
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
     * Uloží jednu buňku dynamického sloupce mzdové složky.
     *
     * ── Ruční přepis importované hodnoty ────────────────────────────────────
     *
     * Importní vstup se NEPŘEPISUJE. Přejde do stavu `cancelled` a vedle něj
     * vznikne ruční vstup `override:<id>` se stejnou složkou a obdobím. Proč
     * ne úprava na místě: řádek importu (doklad dávky) by pak ukazoval na
     * částku, kterou dávka nikdy neobsahovala, a opakovaný import by ruční
     * opravu tiše přemázl. Proč zrovna `cancelled`: to je jediný stav, který
     * z výpočtu vylučuje KAŽDÉ místo, které mzdové vstupy čte — běh bere jen
     * `approved`/`locked`, souhrny a koše vynechávají `cancelled`. Kdyby
     * importní vstup zůstal schválený a „jen se nepočítal", muselo by o tom
     * vědět každé z těch míst a stačilo by jedno zapomenuté ke dvojí výplatě.
     *
     * Obojí proběhne pod jedním savepointem pole: buď je importní vstup zrušený
     * A přepis založený, nebo se nestane nic.
     *
     * @param array<string,mixed> $item
     * @param array{amount_minor:?int,quantity_milliunits:?int,quantity_provided:bool,
     *   row_version:?int,revert:bool} $entry
     * @param array<string,array<string,mixed>> $definitions účinné složky podle kódu
     */
    private function saveComponent(
        int $supplierId,
        int $employmentId,
        array $item,
        string $code,
        array $entry,
        array $definitions,
        string $period,
        ?int $userId,
        bool $autoApprove,
    ): void {
        if (($item['_hourly_claimed'] ?? false)
            && in_array($definitions[$code]['component_kind'] ?? null, ['base_wage', 'hourly_wage'], true)
            && (($entry['amount_minor'] ?? null) !== null || ($entry['revert'] ?? false))) {
            throw new \DomainException('Základní mzda je již spravována výpočtem hodinové mzdy.');
        }
        if (in_array($code, self::managedCodes(), true)) {
            throw new \InvalidArgumentException(
                "Složku {$code} zadávejte v jejím vlastním poli rychlého vstupu."
            );
        }
        /** @var array<string,mixed>|null $cell */
        $cell = is_array($item['components'][$code] ?? null) ? $item['components'][$code] : null;
        $mode = $cell === null ? 'empty' : PayrollTimeValue::string($cell['mode'] ?? null, 'mode');
        $employeeId = PayrollTimeValue::int($item['employee_id'] ?? null, 'employee_id');

        if ($entry['revert']) {
            if ($cell === null || $mode !== 'override') {
                throw new \DomainException(
                    "Složka {$code} není ručně přepsaná, takže není k čemu se vracet."
                );
            }
            self::assertCellVersion($cell, $entry['row_version']);
            $this->revertOverride($supplierId, $cell, $userId, $autoApprove);

            return;
        }

        $amount = $entry['amount_minor'];
        if ($cell === null || $mode === 'manual') {
            if ($cell === null) {
                if ($amount === null) {
                    // Prázdná buňka, do které nikdo nic nezadal.
                    return;
                }
                $definition = $definitions[$code] ?? null;
                if ($definition === null || !self::quickEditableDefinition($definition)) {
                    throw new \DomainException(
                        "Složku {$code} v rychlém vstupu zadat nelze (není účinná jednorázová "
                        . 'peněžní složka s uzavřeným zdaněním). Zadejte ji v Mzdových vstupech.'
                    );
                }
                $componentId = PayrollTimeValue::int($definition['id'] ?? null, 'id');
            } else {
                $componentId = PayrollTimeValue::int($cell['component_id'] ?? null, 'component_id');
            }
            $this->upsert(
                $supplierId,
                $employeeId,
                $employmentId,
                $componentId,
                $period,
                $code,
                $amount,
                $amount === null ? null : $entry['quantity_milliunits'],
                $entry['row_version'],
                $userId,
                null,
                false,
                $autoApprove,
            );

            return;
        }

        $currentQuantity = $cell['quantity_milliunits'] === null
            ? null
            : PayrollTimeValue::int($cell['quantity_milliunits'], 'quantity_milliunits');
        $quantity = $entry['quantity_provided'] ? $entry['quantity_milliunits'] : $currentQuantity;
        $unchanged = $amount === PayrollTimeValue::int($cell['amount_minor'] ?? null, 'amount_minor')
            && $quantity === $currentQuantity;

        if ($mode === 'managed') {
            if ($unchanged) {
                return;
            }
            throw new \DomainException(
                "Složku {$code} v tomto měsíci spravuje jiný vstup (pravidelná složka, "
                . 'docházka nebo více vstupů). Upravte ji v Mzdových vstupech.'
            );
        }
        if ($amount === null) {
            // Vymazat importovanou hodnotu by znamenalo nevyplatit ji, a to
            // se nemá stát omylem prázdným políčkem. Nula je výslovná.
            throw new \InvalidArgumentException(
                'Zadejte částku (i nulu). K hodnotě z importu se vrátíte akcí '
                . '„Vrátit na hodnotu z importu".'
            );
        }

        if ($mode === 'import') {
            if ($unchanged) {
                return;
            }
            self::assertCellVersion($cell, $entry['row_version']);
            $importId = PayrollTimeValue::int($cell['input_id'] ?? null, 'input_id');
            $version = PayrollTimeValue::int($cell['row_version'] ?? null, 'row_version');
            $status = PayrollTimeValue::string($cell['status'] ?? null, 'status');
            if ($status === 'locked') {
                throw new \DomainException(
                    'Importovaný vstup je uzamčený mzdovým během; změnu proveďte opravnou revizí.'
                );
            }
            if ($status === 'approved') {
                if (!$autoApprove) {
                    throw new \DomainException(
                        'Importovaný vstup je schválený. Ručně ho přepsat smí jen uživatel '
                        . 's právem schvalovat mzdové vstupy.'
                    );
                }
                $this->inputs->revertToDraft($supplierId, $importId, $version);
                ++$version;
            }
            // Nejdřív zrušit, pak založit: zrušení hlídá, že vstup není
            // zmrazený v revizi běhu ani navázaný na jiný doklad.
            $this->inputs->cancel($supplierId, $importId, $version);
            $this->upsert(
                $supplierId,
                $employeeId,
                $employmentId,
                PayrollTimeValue::int($cell['component_id'] ?? null, 'component_id'),
                $period,
                $code,
                $amount,
                $quantity,
                null,
                $userId,
                null,
                // Přepis na nulu je plnohodnotný údaj („import tvrdil 500,
                // skutečně nic") — řádek vzniknout musí.
                true,
                $autoApprove,
                externalId: self::OVERRIDE_PREFIX . $importId,
            );

            return;
        }

        // $mode === 'override'
        $origin = is_array($cell['override_of'] ?? null) ? $cell['override_of'] : [];
        if ($amount === ($origin['amount_minor'] ?? null)
            && $quantity === ($origin['quantity_milliunits'] ?? null)
        ) {
            // Přepis zpátky na importovanou hodnotu je návrat k importu, ne
            // druhý ruční vstup se stejnou částkou.
            self::assertCellVersion($cell, $entry['row_version']);
            $this->revertOverride($supplierId, $cell, $userId, $autoApprove);

            return;
        }
        $this->upsert(
            $supplierId,
            $employeeId,
            $employmentId,
            PayrollTimeValue::int($cell['component_id'] ?? null, 'component_id'),
            $period,
            $code,
            $amount,
            $quantity,
            $entry['row_version'],
            $userId,
            null,
            true,
            $autoApprove,
            externalId: PayrollTimeValue::string($cell['external_id'] ?? null, 'external_id'),
        );
    }

    /**
     * Zruší ruční přepis a vrátí do hry importní vstup, který přepisoval.
     *
     * Importní vstup se obnoví jako koncept (s právem schvalovat rovnou jako
     * schválený — stejně jako každé uložení rychlého vstupu). Obnovuje se
     * s hodnotou, kterou má: opakovaný import ji během přepisu aktualizuje,
     * takže je to hodnota POSLEDNÍ dávky.
     *
     * @param array<string,mixed> $cell
     */
    private function revertOverride(
        int $supplierId,
        array $cell,
        ?int $userId,
        bool $autoApprove,
    ): void {
        $overrideId = PayrollTimeValue::int($cell['input_id'] ?? null, 'input_id');
        $version = PayrollTimeValue::int($cell['row_version'] ?? null, 'row_version');
        $status = PayrollTimeValue::string($cell['status'] ?? null, 'status');
        $origin = is_array($cell['override_of'] ?? null) ? $cell['override_of'] : [];
        $importId = PayrollTimeValue::int($origin['input_id'] ?? null, 'override_of.input_id');
        if ($status === 'locked') {
            throw new \DomainException(
                'Ruční přepis je uzamčený mzdovým během; vrátit ho lze jen opravnou revizí.'
            );
        }
        if ($status === 'approved') {
            if (!$autoApprove) {
                throw new \DomainException(
                    'Ruční přepis je schválený. Vrátit ho smí jen uživatel s právem '
                    . 'schvalovat mzdové vstupy.'
                );
            }
            $this->inputs->revertToDraft($supplierId, $overrideId, $version);
            ++$version;
        }
        $this->inputs->cancel($supplierId, $overrideId, $version);

        $pdo = $this->db->pdo();
        try {
            $restore = $pdo->prepare(
                'UPDATE payroll_inputs
                    SET status = "draft", row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ?
                    AND source_kind = "import" AND status = "cancelled"'
            );
            $restore->execute([$supplierId, $importId]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \DomainException(
                    'Hodnotu z importu nejde obnovit: pro tentýž řádek importu už '
                    . 'v měsíci existuje jiný živý vstup.',
                    0,
                    $e,
                );
            }
            throw $e;
        }
        if ($restore->rowCount() !== 1) {
            throw new \DomainException(
                'Importovaný vstup se mezitím změnil. Obnovte formulář a zkuste to znovu.'
            );
        }
        if ($autoApprove) {
            $current = $pdo->prepare(
                'SELECT row_version FROM payroll_inputs WHERE supplier_id = ? AND id = ?'
            );
            $current->execute([$supplierId, $importId]);
            $this->inputs->approve(
                $supplierId,
                $importId,
                PayrollTimeValue::int($current->fetchColumn(), 'row_version'),
                $userId,
            );
        }
    }

    /** @param array<string,mixed> $cell */
    private static function assertCellVersion(array $cell, ?int $expected): void
    {
        $current = $cell['row_version'] ?? null;
        if (!is_int($current) || $expected !== $current) {
            throw new PayrollInputConflictException(is_int($current) ? $current : 0);
        }
    }

    /**
     * @param list<array<string,mixed>> $definitions
     * @return array<string,array<string,mixed>>
     */
    private function definitionMap(array $definitions): array
    {
        $map = [];
        foreach ($definitions as $definition) {
            $map[PayrollTimeValue::string($definition['code'] ?? null, 'code')] = $definition;
        }

        return $map;
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
                self::componentId($componentIds, $code),
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

    /**
     * Hledání v rychlém vstupu podle jména nebo osobního čísla (kódu vztahu).
     *
     * Stránka je stránkovaná serverem, takže hledat musí server — prohlížeč by
     * našel jen toho, kdo je náhodou na zobrazené stránce. Sloupce mají
     * `utf8mb4_unicode_ci`, takže LIKE nerozlišuje velikost písmen ani
     * diakritiku. Napsané `%` a `_` jsou hledaný text, ne zástupné znaky.
     * Hledá se v tomtéž jméně, které řádek zobrazuje (`employee.full_name`).
     *
     * @return array{sql:string,params:list<string>}
     */
    private static function searchClause(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['sql' => '', 'params' => []];
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        return [
            'sql' => " AND (employee.full_name LIKE ? ESCAPE '!' OR employment.code LIKE ? ESCAPE '!')",
            'params' => [$like, $like],
        ];
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
            $e instanceof PayrollInputCancellationException,
            $e instanceof PayrollRunClosedException => $e->errorCode,
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

    /**
     * Mapa kódů spravovaných složek na id účinné verze.
     *
     * Vrací se i neúplná. Dřív se tu kvůli JEDNÉ chybějící složce shazovalo
     * celé uložení měsíce (v obrazovce klidně 500 vztahů) hláškou
     * „Chybí účinná mzdová složka X" — a přitom stačilo, že si někdo složku
     * deaktivoval nebo že měla platnost až od dalšího měsíce. Načtení měsíce
     * přitom projde vždy, takže se to poznalo až při Uložit.
     *
     * Chybějící kód se teď projeví až u pole, které ho opravdu potřebuje, jako
     * jedna položka v seznamu neúspěchů — zbytek měsíce se uloží.
     *
     * @return array<string,int>
     */
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

        return $result;
    }

    /**
     * @param array<string,int> $componentIds
     */
    private static function componentId(array $componentIds, string $code): int
    {
        $id = $componentIds[$code] ?? null;
        if ($id === null) {
            throw new \InvalidArgumentException(
                "Pro tenhle měsíc není účinná mzdová složka {$code}. Otevřete "
                . 'Mzdy → Mzdové složky, složku aktivujte nebo jí založte verzi '
                . 'platnou od tohoto měsíce. Ostatní pole se uložila.',
            );
        }

        return $id;
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
        ?string $externalId = null,
    ): void {
        $periodStart = $period . '-01';
        // Vlastní řádek rychlého zadání, nebo ruční přepis importu
        // (`override:<id>`) — oba jsou `manual` a řídí se týmiž pravidly.
        $externalId ??= self::EXTERNAL_PREFIX . $componentCode;
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
                $this->inputs->createApproved($supplierId, $data, $userId, sourceAlreadyGuarded: true);
            } else {
                $this->inputs->create($supplierId, $data, $userId, sourceAlreadyGuarded: true);
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
                sourceAlreadyGuarded: true,
            )
            : $this->inputs->update($supplierId, (int) $row['id'], $data, $currentVersion, sourceAlreadyGuarded: true);
        if ($updated === null) {
            throw new PayrollInputConflictException($currentVersion);
        }
    }
}
