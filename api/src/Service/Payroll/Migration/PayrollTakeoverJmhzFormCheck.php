<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;

/**
 * Převzatá mzda proti přijatému hlášení JMHZ, které za týž měsíc podal předchozí
 * mzdový program.
 *
 * Převod přebírá KONEČNÝ stav mzdového listu. Hlášení je ale snímek mzdy
 * v okamžiku podání: když účetní mzdu po odeslání opravila a opravné hlášení už
 * nepodala, mají ČSSZ a finanční správa jiné údaje než mzda (typicky celý měsíc
 * v neschopnosti, hlášení odešlo s plným tarifem a mzda se pak přepočítala na
 * nulu). Převzetí je v takovém případě správné; rozdíl je podnět pro účetní
 * podat opravné hlášení, proto jde jen o upozornění a převod ani uzavření roku
 * nedrží.
 *
 * ── Co se porovnává ─────────────────────────────────────────────────────────
 * Osoba × měsíc, protože zúčtovaný příjem, záloha a zdravotní pojistné jsou
 * v hlášení jen v souhrnných datech zaměstnance (na jednom formuláři osoby).
 * Vyměřovací základ sociálního pojištění se sčítá přes formuláře vztahů.
 *
 *  - `gross`: zúčtovaný příjem celkem (10286) proti hrubé mzdě,
 *  - `social_base`: vyměřovací základ SP (10477) proti převzatému základu,
 *  - `advance_tax`: záloha po slevách (10305) proti převzaté záloze,
 *  - `health_insurance`: pojistné ZP zaměstnance a zaměstnavatele (10371 + 10482),
 *    jen ve složkách, které formulář nese (chybějící složka není nula).
 *    Vyměřovací základ zdravotního pojištění hlášení JMHZ nenese (XSD má jen
 *    pojistné), takže zdravotní stranu zastupuje pojistné.
 *
 * ── Tolerance ───────────────────────────────────────────────────────────────
 * Hlášení nese celé koruny, převzaté úhrny haléře. Základy, příjem i záloha jsou
 * v obou programech celé koruny, haléřový zbytek je jen zápis převzaté strany,
 * proto se rozdíl menší než 1 Kč nehlásí. Zdravotní pojistné jsou dvě částky
 * zaokrouhlené každá zvlášť (zaměstnanec a zaměstnavatel), programy se liší tím,
 * jestli zaokrouhlují celek nebo podíly - rozdíl do 2 Kč je proto zaokrouhlení.
 * Sociální pojistné se neporovnává: pojistné zaměstnavatele je veličina firmy,
 * ne osoby, a základ SP rozdíl osoby ukáže sám.
 *
 * ── Zúčtovaný příjem nález sám nezakládá ────────────────────────────────────
 * 10286 zahrnuje i příjmy osvobozené od daně (Pokyny MH 1.4.14 k 10286), kdežto
 * „hrubá mzda“ předchozího programu je jeho vlastní pojem (PAMICA `KcHrubaM`)
 * a osvobozená plnění typu stravenkového paušálu v ní být nemusí. Kdyby rozdíl
 * v příjmu stačil k nálezu, hlásila by kontrola každého zaměstnance s takovým
 * plněním. Nález proto zakládá až rozdíl ve veličině se shodnou definicí na obou
 * stranách (základ SP, záloha, zdravotní pojistné); rozdíl v příjmu se k němu
 * přidá do rozpadu jako vysvětlení.
 *
 * ── Kdy se nic nehlásí ─────────────────────────────────────────────────────
 *  - za osobu a měsíc není přijatý formulář nebo převzatá mzda (chybějící hlášení
 *    hlídá {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService}),
 *  - vztahy osoby s formulářem a s převzatou mzdou nejsou tytéž (srovnání by
 *    porovnalo jiný rozsah, ne jiné údaje),
 *  - převzatá mzda pochází z téhož hlášení (zdroj `jmhz`): porovnávala by se sama se sebou,
 *  - formulář veličinu nenese (`null`).
 */
final class PayrollTakeoverJmhzFormCheck
{
    /** Názvy veličin, jak jdou po drátě (shodu s klientem hlídá PayrollEnumContractTest). */
    public const METRIC_NAMES = ['gross', 'social_base', 'advance_tax', 'health_insurance'];

    /** Veličiny, které nález samy nezakládají (viz docblock třídy). */
    private const CONTEXT_ONLY_METRICS = ['gross'];

    /** Rozdíl v haléřích, který je ještě zaokrouhlením na celé koruny. */
    public const TOLERANCE_MINOR = [
        'gross' => 99,
        'social_base' => 99,
        'advance_tax' => 99,
        'health_insurance' => 200,
    ];

    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly PayrollTakeoverCoverage $coverage,
        private readonly PayrollTakeoverReader $reader,
        private readonly JmhzExternalSubmissionStore $submissions,
    ) {}

    /**
     * @return list<array{employee_id:int,employee_name:string,period:string,employment_ids:list<int>,
     *   submission_id:int,submission_type:?string,submitted_at:?string,
     *   differences:list<array{metric:string,takeover_minor:int,jmhz_minor:int,difference_minor:int}>}>
     */
    public function check(int $supplierId, int $year): array
    {
        $takeoverMonths = $this->coverage->takeoverMonths($supplierId, $year);
        if ($takeoverMonths === []) {
            return [];
        }
        $forms = $this->submissions->effectiveMonthlyForms($supplierId, self::ENVIRONMENT, $year);
        if ($forms === []) {
            return [];
        }
        $months = array_values(array_filter(
            $this->reader->forSupplier($supplierId, $year)->months,
            static fn (PayrollTakeoverMonth $month): bool
                => in_array((int) substr($month->period, 5, 2), $takeoverMonths, true),
        ));

        $rows = self::compare($months, $forms);
        $names = $this->coverage->employeeNames($supplierId, array_values(array_unique(array_column($rows, 'employee_id'))));
        foreach ($rows as $index => $row) {
            $rows[$index]['employee_name'] = $names[$row['employee_id']] ?? ('#' . $row['employee_id']);
        }

        return $rows;
    }

    /**
     * Čisté porovnání - bez databáze, aby šlo testovat.
     *
     * @param list<PayrollTakeoverMonth> $months převzaté mzdy (vztah × měsíc)
     * @param list<array{submission_id:int,submission_type:?string,submitted_at:?string,period:string,
     *   employee_id:?int,employment_id:int,form:JmhzReportForm}> $forms účinné formuláře (vztah × měsíc)
     * @return list<array{employee_id:int,employee_name:string,period:string,employment_ids:list<int>,
     *   submission_id:int,submission_type:?string,submitted_at:?string,
     *   differences:list<array{metric:string,takeover_minor:int,jmhz_minor:int,difference_minor:int}>}>
     */
    public static function compare(array $months, array $forms): array
    {
        /** @var array<string,array{employee_id:int,period:string,employments:array<int,true>,values:array<string,int>,health:array{employee:int,employer:int}}> $takeover */
        $takeover = [];
        foreach ($months as $month) {
            if ($month->employeeId === null || $month->employmentId === null
                || $month->source === PayrollMigrationReferenceTotalsWriter::SOURCE_JMHZ
            ) {
                continue;
            }
            $key = $month->period . '|' . $month->employeeId;
            $takeover[$key] ??= [
                'employee_id' => $month->employeeId,
                'period' => $month->period,
                'employments' => [],
                'values' => array_fill_keys(self::METRIC_NAMES, 0),
                'health' => ['employee' => 0, 'employer' => 0],
            ];
            $takeover[$key]['employments'][$month->employmentId] = true;
            $takeover[$key]['values']['gross'] += $month->grossMinor;
            $takeover[$key]['values']['social_base'] += $month->socialBaseMinor;
            $takeover[$key]['values']['advance_tax'] += $month->advanceTaxMinor;
            $takeover[$key]['health']['employee'] += $month->employeeHealthMinor;
            $takeover[$key]['health']['employer'] += $month->employerHealthMinor;
        }

        /** @var array<string,array{employments:array<int,true>,values:array<string,?int>,health:array<string,int>,submission:array<string,mixed>}> $reported */
        $reported = [];
        foreach ($forms as $entry) {
            $employeeId = $entry['employee_id'];
            if ($employeeId === null || $employeeId <= 0) {
                continue;
            }
            $key = $entry['period'] . '|' . $employeeId;
            $reported[$key] ??= [
                'employments' => [],
                'values' => array_fill_keys(self::METRIC_NAMES, null),
                'health' => [],
                'submission' => $entry,
            ];
            $reported[$key]['employments'][$entry['employment_id']] = true;
            // Odkaz vede na nejpozději podané hlášení osoby za měsíc.
            if ((string) ($entry['submitted_at'] ?? '') >= (string) ($reported[$key]['submission']['submitted_at'] ?? '')) {
                $reported[$key]['submission'] = $entry;
            }
            $form = $entry['form'];
            foreach ([
                'gross' => $form->incomeTotal,
                'social_base' => $form->socialBase,
                'advance_tax' => $form->advance['after_credits'] ?? null,
            ] as $metric => $crowns) {
                if ($crowns === null) {
                    continue;
                }
                $reported[$key]['values'][$metric] = ($reported[$key]['values'][$metric] ?? 0) + $crowns * 100;
            }
            // Zdravotní pojistné po složkách: složku, kterou formulář nenese, nejde brát
            // jako nulu, jinak kontrola hlásí celé pojistné zaměstnavatele jako rozdíl.
            foreach (['employee' => $form->employeeHealth, 'employer' => $form->employerHealth] as $part => $crowns) {
                if ($crowns !== null) {
                    $reported[$key]['health'][$part] = ($reported[$key]['health'][$part] ?? 0) + $crowns * 100;
                }
            }
        }

        $out = [];
        foreach ($takeover as $key => $side) {
            $other = $reported[$key] ?? null;
            if ($other === null) {
                continue;
            }
            $employments = array_keys($side['employments']);
            sort($employments, SORT_NUMERIC);
            $reportedEmployments = array_keys($other['employments']);
            sort($reportedEmployments, SORT_NUMERIC);
            if ($employments !== $reportedEmployments) {
                continue;
            }
            // Zdravotní pojistné se srovnává jen ve složkách, které formulář nese.
            $healthParts = $other['health'];
            if ($healthParts !== []) {
                $other['values']['health_insurance'] = array_sum($healthParts);
                $side['values']['health_insurance'] = array_sum(array_intersect_key($side['health'], $healthParts));
            }
            $differences = [];
            foreach (self::METRIC_NAMES as $metric) {
                $jmhz = $other['values'][$metric];
                if ($jmhz === null) {
                    continue;
                }
                $taken = $side['values'][$metric];
                if (abs($jmhz - $taken) <= self::TOLERANCE_MINOR[$metric]) {
                    continue;
                }
                $differences[] = [
                    'metric' => $metric,
                    'takeover_minor' => $taken,
                    'jmhz_minor' => $jmhz,
                    'difference_minor' => $jmhz - $taken,
                ];
            }
            $decisive = array_filter(
                $differences,
                static fn (array $difference): bool
                    => !in_array($difference['metric'], self::CONTEXT_ONLY_METRICS, true),
            );
            if ($decisive === []) {
                continue;
            }
            $out[] = [
                'employee_id' => $side['employee_id'],
                'employee_name' => '',
                'period' => $side['period'],
                'employment_ids' => $employments,
                'submission_id' => (int) $other['submission']['submission_id'],
                'submission_type' => $other['submission']['submission_type'],
                'submitted_at' => $other['submission']['submitted_at'],
                'differences' => $differences,
            ];
        }
        usort($out, static fn (array $left, array $right): int
            => [$left['period'], $left['employee_id']] <=> [$right['period'], $right['employee_id']]);

        return $out;
    }
}
