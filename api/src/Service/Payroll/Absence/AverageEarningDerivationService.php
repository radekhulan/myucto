<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PDO;

/**
 * Odvození vstupů průměrného výdělku ze zmrazených mzdových běhů.
 *
 * Účetní dosud opisovala tři čísla, která aplikace už má: započitatelnou mzdu,
 * odpracovanou dobu a odpracované dny za rozhodné čtvrtletí. Průměr je přitom
 * povinný pro náhradu za dovolenou, pro náhradu při DPN i pro JMHZ (atribut
 * 10345), takže se opisovalo každé čtvrtletí u každého, kdo něco čerpal.
 *
 * Služba jen NAVRHUJE. Nic neukládá, průměr nepočítá (to je
 * {@see AverageEarningCalculator}) a schválení nenahrazuje — potvrdit čísla
 * musí pořád člověk, stejně jako u automatického nároku na dovolenou
 * ({@see AutomaticLeaveEntitlementService}), podle kterého je postavená.
 *
 * ── Odkud se která hodnota bere ─────────────────────────────────────────────
 *
 *  * **Započitatelná mzda** (`gross_earnings_minor`) — součet
 *    `totals.average_earning_base_minor` ze zmrazeného výsledku běhu
 *    (`payroll_run_employments.result_json`) za všechny tři měsíce rozhodného
 *    období. Tohle číslo NEODHADUJE tahle služba: vyrábí ho
 *    {@see \MyInvoice\Service\Payroll\Run\PayrollRunCalculator} z
 *    `average_earning_treatment` KAŽDÉ mzdové složky, a to z klasifikace
 *    zmrazené ve `payroll_inputs.component_snapshot_json` v okamžiku schválení
 *    vstupu. Do základu tedy vstupuje jen to, co je označené `included`
 *    (mzda, odměna, provize, zákonné příplatky § 114 až § 118, doplatek mzdy);
 *    náhrada mzdy, náhrada při DPN, odstupné, benefity, nepeněžní příjem
 *    ani cestovní náhrady tam nejsou. Složka s klasifikací `manual_review`
 *    výpočet běhu shodí ({@see
 *    \MyInvoice\Service\Payroll\Component\PayrollComponentDefinition::impact()}),
 *    takže SCHVÁLENÁ revize je sama o sobě důkazem, že v období nezůstala
 *    složka, kterou aplikace neumí zařadit.
 *
 *  * **Odpracovaná doba** (`worked_minutes`) — potvrzené
 *    `values.worked_millihours` z pracovního souhrnu JMHZ zmrazeného v
 *    `payroll_run_employments.input_json` (`time_month.jmhz_work_summary`).
 *    Je to TÁŽ hodnota, kterou účetní odklepla při schválení docházky a která
 *    odchází do měsíčního hlášení ČSSZ — a protože byla zmrazená do běhu, je
 *    to i doba, ze které se počítala mzda za daný měsíc.
 *
 *  * **Odpracované dny** (`worked_days`) — počet různých místních kalendářních
 *    dnů s odpracovaným intervalem, spočítaný ze zmrazeného
 *    `source_snapshot_json.time_entries` téhož souhrnu. Ten seznam je zdroj,
 *    ze kterého vznikly potvrzené hodiny; služba ho projde stejným postupem
 *    jako {@see \MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder}
 *    (kategorie `regular` a `overtime`, minus přestávka) a MUSÍ dojít na
 *    minutu ke stejnému číslu jako potvrzené hodiny. Když nedojde, návrh
 *    nevznikne — dny by pak stály na jiné evidenci než hodiny.
 *
 * ── Co se NEODVOZUJE ────────────────────────────────────────────────────────
 *
 *  * **Poměrná část mzdy za delší období než čtvrtletí (§ 358 ZP)** —
 *    `longer_period_allocated_minor` zůstává vždy `null`. Aplikace u mzdové
 *    složky nevede údaj „za jaké období se poskytuje"; `frequency_kind`
 *    rozlišuje jen pravidelnou a jednorázovou složku, a jednorázový je i
 *    příplatek za noční práci. Roční prémii tedy od měsíční odměny nerozezná
 *    nic. Číslo proto zadává účetní a formulář to u pole říká.
 *
 *  * **Pravděpodobný výdělek (§ 355 ZP)** — když v rozhodném období nebyl
 *    odpracován zákonný minimální počet dnů (nováček, dlouhá nemoc), stanoví
 *    se průměr jinou úvahou, kterou z evidence odvodit nejde. Služba v takovém
 *    případě nenavrhne nic a vrátí blokátor `probable_earning_required`.
 *
 * ── Proč jsou tři metody statické ───────────────────────────────────────────
 *
 * `monthFromRow()`, `combine()` a `workedFromEntries()` jsou čisté převody bez
 * databáze. Veřejné a statické jsou ze stejného důvodu jako
 * `PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions()`: dají se ověřit
 * testem přímo, bez sestavování celého mzdového běhu. Právě v nich sedí
 * všechna rozhodnutí „tohle se navrhnout nedá", a ta se testovat musí.
 */
final class AverageEarningDerivationService
{
    /**
     * Stavy mzdového běhu, které se dají považovat za uzavřené.
     *
     * `draft`, `inputs_locked`, `calculated` a `reviewed` jsou rozpracované;
     * `correction_pending` a `reopened` znamenají opravu v běhu, takže dnešní
     * schválená revize nemusí být tou poslední; `cancelled` je zrušený běh.
     * Z ničeho z toho se průměrný výdělek odvozovat nesmí — jsou to peníze
     * pro náhradu a údaj do hlášení ČSSZ.
     */
    public const CLOSED_RUN_STATUSES = [
        'approved', 'posted', 'payment_ready', 'paid', 'closed',
    ];

    /** Verze odvození pracovního souhrnu, ze které umíme číst rozpad směn. */
    public const SUPPORTED_WORK_SUMMARY_VERSION = 'jmhz-work-month.v2';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * Návrh vstupů průměru pro jeden pracovní vztah a jedno použité čtvrtletí.
     *
     * Rozhodné období je podle § 354 odst. 1 ZP předchozí kalendářní čtvrtletí;
     * počítá se stejně jako v
     * {@see \MyInvoice\Service\Payroll\PayrollAbsenceValidator::average()},
     * aby navržené `decisive_from`/`decisive_to` prošly validací beze změny.
     *
     * @return array<string,mixed>
     */
    public function suggest(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
    ): array {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah není platný.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Rok použití průměru není platný.');
        }
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        $this->assertEmployment($supplierId, $employmentId);

        $applicationStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-01',
            $year,
            (($quarter - 1) * 3) + 1,
        ));
        $decisiveStart = $applicationStart->modify('-3 months');

        $months = [];
        foreach ([0, 1, 2] as $offset) {
            $periodStart = $decisiveStart->modify("+{$offset} months")->format('Y-m-d');
            $months[] = ['period_start' => $periodStart] + self::monthFromRow(
                $this->latestRunResult($supplierId, $employmentId, $periodStart),
                $periodStart,
            );
        }

        return [
            'employment_id' => $employmentId,
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $decisiveStart->format('Y-m-d'),
            'decisive_to' => $applicationStart->modify('-1 day')->format('Y-m-d'),
        ] + self::combine(
            $months,
            AbsenceRuleset::forDate($this->rulesets, $applicationStart->format('Y-m-d'))
                ->averageEarningMinimumWorkedDays(),
            $this->existingSnapshot($supplierId, $employmentId, $year, $quarter) !== null,
        );
    }

    /**
     * Sečte tři měsíce rozhodného období do návrhu, nebo do blokátorů.
     *
     * Částečný výsledek se nevrací nikdy: sečtený neúplný základ vypadá jako
     * hotové číslo a nikdo na něm nepozná, že měsíc chybí. Jakmile má aspoň
     * jeden měsíc blokátor, jsou všechna tři čísla `null`.
     *
     * @param list<array<string,mixed>> $months výstupy {@see monthFromRow()}
     *        doplněné o `period_start`
     * @return array<string,mixed>
     */
    public static function combine(
        array $months,
        int $minimumWorkedDays,
        bool $hasExistingSnapshot,
    ): array {
        /** @var array<string,bool> $blockers */
        $blockers = [];
        $grossMinor = 0;
        $workedMinutes = 0;
        $workedDays = 0;
        $sources = [];

        foreach ($months as $month) {
            foreach ((array) $month['blockers'] as $code) {
                $blockers[(string) $code] = true;
            }
            $sources[] = [
                'period_start' => $month['period_start'] ?? null,
                'revision_id' => $month['revision_id'] ?? null,
                'result_hash' => $month['result_hash'] ?? null,
                'work_summary_sha256' => $month['work_summary_sha256'] ?? null,
                'time_month_row_version' => $month['time_month_row_version'] ?? null,
            ];
            if ($month['blockers'] !== []) {
                continue;
            }
            $grossMinor += (int) $month['gross_earnings_minor'];
            $workedMinutes += (int) $month['worked_minutes'];
            $workedDays += (int) $month['worked_days'];
        }

        if ($blockers === []) {
            // § 355 odst. 1 ZP — pod zákonným minimem odpracovaných dnů se
            // průměr nezjišťuje, ale STANOVUJE jako pravděpodobný výdělek.
            // To je jiná úvaha (čeho by zaměstnanec pravděpodobně dosáhl), ne
            // podíl dvou čísel z evidence — návrh se proto nedělá vůbec.
            if ($workedDays < $minimumWorkedDays) {
                $blockers['probable_earning_required'] = true;
            }
            // Kalkulátor skutečný průměr bez kladné mzdy a kladné doby odmítne;
            // navrhnout nulu, se kterou formulář spadne, je horší než mlčet.
            if ($grossMinor <= 0 || $workedMinutes <= 0) {
                $blockers['worked_time_missing'] = true;
            }
        }
        if ($hasExistingSnapshot) {
            $blockers['average_already_exists'] = true;
        }

        $ready = $blockers === [];

        return [
            'minimum_worked_days' => $minimumWorkedDays,
            'ready' => $ready,
            'blockers' => array_keys($blockers),
            'gross_earnings_minor' => $ready ? $grossMinor : null,
            // § 358 ZP se z evidence odvodit nedá — viz docblock třídy.
            'longer_period_allocated_minor' => null,
            'worked_minutes' => $ready ? $workedMinutes : null,
            'worked_days' => $ready ? $workedDays : null,
            'months' => array_map(
                static fn (array $month): array => [
                    'period_start' => $month['period_start'] ?? null,
                    'run_id' => $month['run_id'] ?? null,
                    'revision_id' => $month['revision_id'] ?? null,
                    'revision_no' => $month['revision_no'] ?? null,
                    'gross_earnings_minor' => $month['gross_earnings_minor'] ?? null,
                    'worked_minutes' => $month['worked_minutes'] ?? null,
                    'worked_days' => $month['worked_days'] ?? null,
                    'work_summary_id' => $month['work_summary_id'] ?? null,
                    'blockers' => $month['blockers'],
                ],
                $months,
            ),
            'input_version' => hash('sha256', CanonicalJson::encode($sources)),
        ];
    }

    /**
     * Podklady jednoho měsíce rozhodného období z jednoho řádku výsledku běhu.
     *
     * `$row === null` znamená, že za měsíc není žádný běh, ve kterém by vztah
     * figuroval — typicky nováček, u kterého se průměr stanovuje jako
     * pravděpodobný výdělek. Domýšlet se za něj nic nesmí.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    public static function monthFromRow(?array $row, string $periodStart): array
    {
        $context = [
            'blockers' => [],
            'run_id' => null,
            'revision_id' => null,
            'revision_no' => null,
            'gross_earnings_minor' => null,
            'worked_minutes' => null,
            'worked_days' => null,
            'work_summary_id' => null,
            'work_summary_sha256' => null,
            'result_hash' => null,
            'time_month_row_version' => null,
        ];
        if ($row === null) {
            return ['blockers' => ['run_missing']] + $context;
        }
        if (($row['ambiguous_runs'] ?? false) === true) {
            // Jeden vztah patří do jedné mzdové účtárny, takže dva běhy za týž
            // měsíc znamenají, že nevíme, který z nich mzdu opravdu vyplatil.
            return ['blockers' => ['multiple_runs_for_month']] + $context;
        }

        $context['run_id'] = self::nullableInt($row['run_id'] ?? null);
        $context['revision_id'] = self::nullableInt($row['revision_id'] ?? null);
        $context['revision_no'] = self::nullableInt($row['revision_no'] ?? null);
        $context['result_hash'] = is_string($row['result_hash'] ?? null)
            ? $row['result_hash']
            : null;

        if (!in_array((string) ($row['run_status'] ?? ''), self::CLOSED_RUN_STATUSES, true)
            || ($row['revision_status'] ?? null) !== 'approved'
        ) {
            return ['blockers' => ['run_not_approved']] + $context;
        }
        if (!is_string($row['result_json'] ?? null)
            || ($row['result_status'] ?? null) !== 'calculated'
        ) {
            return ['blockers' => ['run_result_missing']] + $context;
        }

        $result = self::decode((string) $row['result_json']);
        $input = is_string($row['input_json'] ?? null)
            ? self::decode((string) $row['input_json'])
            : null;
        if ($result === null || $input === null) {
            return ['blockers' => ['run_result_missing']] + $context;
        }

        $gross = $result['totals']['average_earning_base_minor'] ?? null;
        if (!is_int($gross) || $gross < 0) {
            return ['blockers' => ['average_earning_base_missing']] + $context;
        }
        $context['gross_earnings_minor'] = $gross;

        $timeMonth = $input['time_month'] ?? null;
        if (!is_array($timeMonth)) {
            return ['blockers' => ['time_month_missing']] + $context;
        }
        $context['time_month_row_version'] = self::nullableInt($timeMonth['row_version'] ?? null);
        if (($timeMonth['status'] ?? null) !== 'approved') {
            return ['blockers' => ['time_month_not_approved']] + $context;
        }

        $summary = $timeMonth['jmhz_work_summary'] ?? null;
        if (!is_array($summary)) {
            return ['blockers' => ['work_summary_missing']] + $context;
        }
        $context['work_summary_id'] = self::nullableInt($summary['id'] ?? null);
        $context['work_summary_sha256'] = is_string($summary['summary_sha256'] ?? null)
            ? $summary['summary_sha256']
            : null;
        if (($summary['derivation_version'] ?? null) !== self::SUPPORTED_WORK_SUMMARY_VERSION) {
            return ['blockers' => ['work_summary_version_unsupported']] + $context;
        }

        $confirmedMillihours = $summary['values']['worked_millihours'] ?? null;
        $sourceJson = $summary['source_snapshot_json'] ?? null;
        $sourceHash = $summary['source_snapshot_sha256'] ?? null;
        if (!is_int($confirmedMillihours)
            || $confirmedMillihours < 0
            || !is_string($sourceJson)
            || !is_string($sourceHash)
            || !hash_equals($sourceHash, hash('sha256', $sourceJson))
        ) {
            return ['blockers' => ['work_summary_source_corrupt']] + $context;
        }

        $source = self::decode($sourceJson);
        if ($source === null
            || ($source['schema_version'] ?? null) !== self::SUPPORTED_WORK_SUMMARY_VERSION
            || !is_array($source['time_entries'] ?? null)
        ) {
            return ['blockers' => ['work_summary_source_corrupt']] + $context;
        }

        $worked = self::workedFromEntries($source['time_entries'], $periodStart);
        if ($worked === null) {
            return ['blockers' => ['worked_time_not_derivable']] + $context;
        }

        // Kontrola, že odpracované DNY stojí na téže evidenci jako odpracované
        // HODINY: minuty přepočtené ze zmrazených směn se musí do poslední
        // milihodiny shodovat s hodnotou, kterou účetní potvrdila do hlášení.
        // Porovnává se v celých číslech (minuty × 1000 proti milihodinám × 60),
        // takže se nikde nezaokrouhluje. Rozejít se můžou tehdy, když účetní
        // navrženou hodinu přepsala — pak jsou dny odjinud než hodiny a návrh
        // by tvrdil souvislost, která tam není.
        if ($worked['minutes'] * 1000 !== $confirmedMillihours * 60) {
            return ['blockers' => ['work_summary_hours_mismatch']] + $context;
        }

        $context['worked_minutes'] = $worked['minutes'];
        $context['worked_days'] = $worked['days'];

        return $context;
    }

    /**
     * Odpracované minuty a dny ze zmrazeného seznamu směn.
     *
     * Postup je záměrně TOTOŽNÝ s
     * {@see \MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder}:
     * bere kategorie `regular` a `overtime` (přesčas je odpracovaná doba a mzda
     * za něj je hrubá mzda, takže do průměru podle § 353 odst. 1 ZP patří
     * obojí), odečítá přestávku a odmítá interval přes hranici místního měsíce
     * i překryv dvou intervalů. Kdyby se postup lišil, kontrola shody
     * s potvrzenými hodinami by neměla žádnou vypovídací hodnotu.
     *
     * Den se počítá podle MÍSTNÍHO data začátku směny — týž den se nezapočítá
     * dvakrát ani při dělené směně (základní doba ráno, přesčas odpoledne).
     *
     * @param array<mixed> $entries
     * @return array{minutes:int,days:int}|null `null` = evidenci nelze bez
     *         posouzení sečíst (překryv, interval přes měsíc, záporná doba)
     */
    public static function workedFromEntries(array $entries, string $periodStart): ?array
    {
        $periodMonth = substr($periodStart, 0, 7);
        $utc = new \DateTimeZone('UTC');
        $minutes = 0;
        $days = [];
        $intervals = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !is_string($entry['category'] ?? null)
                || !is_string($entry['starts_at_utc'] ?? null)
                || !is_string($entry['ends_at_utc'] ?? null)
                || !is_string($entry['timezone_name'] ?? null)
                || !is_int($entry['break_minutes'] ?? null)
            ) {
                return null;
            }
            if (!in_array($entry['category'], ['regular', 'overtime'], true)) {
                continue;
            }
            try {
                $timezone = new \DateTimeZone($entry['timezone_name']);
                $start = new \DateTimeImmutable($entry['starts_at_utc'], $utc);
                $end = new \DateTimeImmutable($entry['ends_at_utc'], $utc);
            } catch (\Exception) {
                return null;
            }
            $startLocal = $start->setTimezone($timezone);
            $startMonth = $startLocal->format('Y-m');
            $endMonth = $end->setTimezone($timezone)->format('Y-m');
            if ($startMonth !== $periodMonth && $endMonth !== $periodMonth) {
                if ($startMonth === $endMonth) {
                    continue;
                }
            }
            if ($startMonth !== $periodMonth || $endMonth !== $periodMonth) {
                return null;
            }
            foreach ($intervals as [$seenStart, $seenEnd]) {
                if ($start < $seenEnd && $end > $seenStart) {
                    return null;
                }
            }
            $intervals[] = [$start, $end];
            $net = intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
                - $entry['break_minutes'];
            if ($net < 0) {
                return null;
            }
            $minutes += $net;
            if ($net > 0) {
                $days[$startLocal->format('Y-m-d')] = true;
            }
        }

        return ['minutes' => $minutes, 'days' => count($days)];
    }

    /**
     * Nejnovější revize výsledku běhu za měsíc, ve které vztah figuruje.
     *
     * Vrací `null`, když za měsíc žádný běh není. Když jich je víc (dvě mzdové
     * účtárny), vrací příznak `ambiguous_runs` místo dat — vybrat jeden z nich
     * by znamenalo hádat, který mzdu vyplatil.
     *
     * @return array<string,mixed>|null
     */
    private function latestRunResult(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.id AS revision_id,
                    revision.run_id,
                    revision.revision_no,
                    revision.status AS revision_status,
                    run.status AS run_status,
                    result.input_json,
                    result.result_json,
                    result.result_hash,
                    result.status AS result_status
               FROM payroll_run_employments result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE result.supplier_id = ?
                AND result.employment_id = ?
                AND result.period_start = ?
              ORDER BY revision.run_id, revision.revision_no',
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        /** @var array<int,array<string,mixed>> $latestByRun */
        $latestByRun = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $row['run_id'] = $runId;
            $row['revision_id'] = (int) $row['revision_id'];
            $row['revision_no'] = (int) $row['revision_no'];
            if (!isset($latestByRun[$runId])
                || $row['revision_no'] > $latestByRun[$runId]['revision_no']
            ) {
                $latestByRun[$runId] = $row;
            }
        }
        if (count($latestByRun) > 1) {
            return ['ambiguous_runs' => true];
        }

        return array_values($latestByRun)[0];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
            ? (int) $value
            : null;
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function assertEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    /**
     * Nejnovější revize průměru pro totéž použité čtvrtletí, ať už čeká na
     * schválení, nebo je schválená.
     *
     * Návrh existující výpočet nepřepisuje ze stejného důvodu jako u nároku
     * na dovolenou: nové číslo by tiše nahradilo to, ze kterého už mohla odejít
     * náhrada nebo hlášení. Opravit se dá ručně.
     *
     * @return array<string,mixed>|null
     */
    private function existingSnapshot(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, revision_no, status
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status IN ('manual_review', 'approved')
              ORDER BY revision_no DESC
              LIMIT 1",
        );
        $stmt->execute([$supplierId, $employmentId, $year, $quarter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
