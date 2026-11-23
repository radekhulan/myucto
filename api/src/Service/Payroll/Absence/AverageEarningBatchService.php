<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use PDO;

/**
 * Průměrné výdělky za čtvrtletí hromadně: přehled návrhů pro všechny vztahy
 * firmy a založení se schválením vybraných.
 *
 * Průměr je povinný pro každého, kdo ve čtvrtletí čerpá náhradu, i pro JMHZ
 * (atribut 10345) — tedy u firmy s pěti sty lidmi pět set formulářů za
 * čtvrtletí. Návrh každého čísla dělá {@see AverageEarningDerivationService}
 * (skutečný průměr z uzavřených běhů, u nového vztahu pravděpodobný výdělek
 * podle § 355 ZP); tahle služba nic nepočítá jinak, jen návrhy sbírá a po
 * potvrzení účetní je zakládá a schvaluje.
 *
 * Potvrzení je výběr v přehledu: každá položka nese `input_version` a zápis
 * ji pod zámkem vztahu přepočítá. Když se podklady mezitím změnily, dávka se
 * nezapíše celá — stejně jako {@see AutomaticLeaveEntitlementService}.
 */
final class AverageEarningBatchService
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /**
     * § 358 ZP (mzda za delší období než čtvrtletí) z evidence odvodit nejde —
     * viz AverageEarningDerivationService. Hromadně založený skutečný průměr
     * ji proto nezohledňuje a odůvodnění to říká.
     */
    private const ACTUAL_RATIONALE = 'Hromadně z uzavřených mzdových běhů rozhodného období;'
        . ' poměrná část mzdy za delší období než čtvrtletí (§ 358 zákoníku práce) nezadána.';

    public function __construct(
        private readonly Connection $db,
        private readonly AverageEarningDerivationService $derivation,
        private readonly AverageEarningCalculator $calculator,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public function page(
        int $supplierId,
        int $year,
        int $quarter,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        [$from, $to] = $this->quarter($year, $quarter);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $where = "employment.supplier_id = ?
                  AND employment.status NOT IN ('archived', 'no_show')
                  AND COALESCE(employment.actual_start_date, employment.start_date, ?) <= ?
                  AND (employment.end_date IS NULL OR employment.end_date >= ?)";
        $count = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employments employment WHERE {$where}",
        );
        $count->execute([$supplierId, $from, $to, $from]);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.code, employment.relation_type, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE {$where}
              ORDER BY employee.full_name, employment.code, employment.id
              LIMIT ? OFFSET ?",
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $from);
        $stmt->bindValue(3, $to);
        $stmt->bindValue(4, $from);
        $stmt->bindValue(5, $limit, PDO::PARAM_INT);
        $stmt->bindValue(6, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existing = $this->existing(
            $supplierId,
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $year,
            $quarter,
        );
        $floorDate = sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1);
        $hourlyFloors = (new \MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver($this->db))
            ->forMany($supplierId, array_map(static fn(array $row): int => (int) $row['id'], $rows), $floorDate, $floorDate);
        $items = [];
        foreach ($rows as $row) {
            $employmentId = (int) $row['id'];
            $items[] = $this->candidate(
                $this->derivation->suggest($supplierId, $employmentId, $year, $quarter, $hourlyFloors[$employmentId] ?? []),
                (string) $row['full_name'],
                (string) $row['code'],
                $existing[$employmentId] ?? null,
            ) + ['relation_type' => (string) $row['relation_type']];
        }

        return compact('items', 'total', 'limit', 'offset');
    }

    /**
     * Založí a schválí průměry vybraných vztahů.
     *
     * Změněné podklady (jiná `input_version`) zastaví celou dávku, protože
     * účetní potvrzovala čísla, která už neplatí. Vztah, jehož návrh připravený
     * není (chybí podklad, průměr za čtvrtletí už existuje, převzaté mzdy), dávku
     * nezastaví: přeskočí se a vrátí v `skipped` se jménem a kódy důvodů, aby ho
     * přehled ukázal u osoby a ostatní vztahy se schválily.
     *
     * @param list<mixed> $requested položky `{employment_id, input_version}`
     *        z přehledu
     * @return array{approved:list<array<string,mixed>>,skipped:list<array{employment_id:int,employee_id:int,employee_name:string,employment_code:string,blockers:list<string>}>}
     */
    public function createBatch(
        int $supplierId,
        int $year,
        int $quarter,
        array $requested,
        ?int $userId,
    ): array {
        [$applicationStart] = $this->quarter($year, $quarter);
        if ($requested === [] || count($requested) > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'Dávka musí obsahovat 1 až %d pracovních vztahů.',
                self::MAX_LIMIT,
            ));
        }
        $versions = [];
        $recalculate = [];
        foreach ($requested as $item) {
            $employmentId = is_array($item) ? ($item['employment_id'] ?? null) : null;
            $version = is_array($item) ? ($item['input_version'] ?? null) : null;
            if (!is_int($employmentId) || $employmentId <= 0
                || !is_string($version)
                || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1
            ) {
                throw new \InvalidArgumentException('Dávka průměrů nemá platný výběr a verze podkladů.');
            }
            if (isset($versions[$employmentId])) {
                throw new \InvalidArgumentException('Pracovní vztah je v dávce uveden vícekrát.');
            }
            $versions[$employmentId] = $version;
            // Výslovná žádost o novou revizi neaktuálního schváleného průměru.
            if (($item['recalculate_outdated'] ?? false) === true) {
                $recalculate[$employmentId] = true;
            }
        }

        $ruleset = $this->rulesets->forDate(
            PayrollRulesetDomain::CompensationAverages,
            $applicationStart,
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $hourlyFloors = (new \MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver($this->db))
                ->forMany($supplierId, array_keys($versions), $applicationStart, $applicationStart, true);
            $approved = [];
            $skipped = [];
            $existing = $recalculate === []
                ? []
                : $this->existing($supplierId, array_keys($recalculate), $year, $quarter);
            foreach ($versions as $employmentId => $expectedVersion) {
                $this->lockEmployment($supplierId, $employmentId);
                if ($this->averages->quarterMismatch($supplierId, $employmentId, $year, $quarter) !== null) {
                    // Vztah ve čtvrtletí netrval: přehled ho nenabízí, takže tu
                    // může být jen z ručně sestavené dávky. Přeskočí se, dávka ne.
                    continue;
                }
                $suggestion = $this->derivation->suggest($supplierId, $employmentId, $year, $quarter, $hourlyFloors[$employmentId] ?? []);
                if (!hash_equals($expectedVersion, (string) $suggestion['input_version'])) {
                    throw new AverageEarningBatchConflictException($employmentId);
                }
                $outdated = isset($recalculate[$employmentId], $existing[$employmentId])
                    && $existing[$employmentId]['status'] === 'approved'
                    && !self::needsIndividualReview($suggestion)
                    && array_values((array) ($suggestion['blockers'] ?? [])) === ['average_already_exists']
                        ? self::outdatedDiagnosis($suggestion, $existing[$employmentId])
                        : null;
                if ($outdated !== null) {
                    // Schválený průměr neodpovídá dnešním podkladům: nová
                    // revize z aktuálních běhů. Schválením se dosavadní revize
                    // nahradí; náhrady, které z ní už vznikly, se nemění.
                    $approved[] = $this->createAndApprove(
                        $supplierId,
                        $employmentId,
                        $year,
                        $quarter,
                        $applicationStart,
                        [
                            'source_kind' => 'actual',
                            'gross_earnings_minor' => $outdated['gross_minor'],
                            'worked_minutes' => PayrollDuration::exactMinutes($outdated['duration_units']),
                            'worked_duration_units' => $outdated['duration_units'],
                            'worked_days' => $outdated['worked_days'],
                        ] + $suggestion,
                        $ruleset,
                        $userId,
                        $hourlyFloors[$employmentId] ?? [],
                        self::ACTUAL_RATIONALE,
                        'bulk_recalculation',
                    );
                    continue;
                }
                if (($suggestion['ready'] ?? false) !== true || self::needsIndividualReview($suggestion)) {
                    $blockers = array_values(array_map('strval', (array) ($suggestion['blockers'] ?? [])));
                    if (self::needsIndividualReview($suggestion)) {
                        $blockers[] = 'takeover_needs_review';
                    }
                    $skipped[$employmentId] = array_values(array_unique($blockers));
                    continue;
                }
                $approved[] = $this->createAndApprove(
                    $supplierId,
                    $employmentId,
                    $year,
                    $quarter,
                    $applicationStart,
                    $suggestion,
                    $ruleset,
                    $userId,
                    $hourlyFloors[$employmentId] ?? [],
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            $people = $this->people($supplierId, array_keys($skipped));
            $skippedRows = [];
            foreach ($skipped as $employmentId => $blockers) {
                $skippedRows[] = [
                    'employment_id' => $employmentId,
                    'employee_id' => (int) ($people[$employmentId]['employee_id'] ?? 0),
                    'employee_name' => (string) ($people[$employmentId]['full_name'] ?? ''),
                    'employment_code' => (string) ($people[$employmentId]['code'] ?? ''),
                    'blockers' => $blockers,
                ];
            }

            return ['approved' => $approved, 'skipped' => $skippedRows];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Odůvodnění automaticky založeného pravděpodobného výdělku ze sazby. */
    public const AUTOMATIC_RATE_RATIONALE = 'Automaticky ze sjednané hodinové sazby (§ 355 odst. 2 ZP). '
        . 'Založeno automaticky; pokud mají být zohledněny další obvyklé složky výdělku, opravte ho novou revizí.';

    /** Zdroje pravděpodobného výdělku, které se smí založit automaticky. */
    private const AUTOMATIC_PROBABLE_SOURCES = [
        AverageEarningDerivationService::PROBABLE_SOURCE_AGREED_HOURLY_RATE,
        AverageEarningDerivationService::PROBABLE_SOURCE_TERMS,
    ];

    /**
     * Pravděpodobný výdělek ze sjednané sazby, pokud za čtvrtletí chybí.
     *
     * Rozhodnutí maintainera (11/2026): u hodinově odměňovaného vztahu, kde
     * skutečný průměr z rozhodného období odvodit nejde (nový vztah, málo
     * odpracovaných dnů), se pravděpodobný výdělek podle § 355 ZP založí
     * a schválí automaticky ze sjednané hodinové sazby, nebo z pravděpodobného
     * výdělku zadaného v podmínkách vztahu. Bez něj zákonný příplatek za noc
     * a víkend (10 % průměru) zastavil výpočet u každé brigádnice zvlášť.
     * Záznam nese v odůvodnění i ve stopě, že vznikl automaticky, a opravuje
     * se novou revizí jako každý jiný.
     *
     * Nezakládá nic, když za čtvrtletí už nějaký průměr je (i neschválený),
     * když návrh není pravděpodobný výdělek z těchto zdrojů, nebo když vztah
     * ve čtvrtletí netrval. Volá se z akcí, které mění podklady (schválení
     * docházky, „Počítat ze směn", hromadné doplnění v kontrole běhu), nikdy
     * při čtení.
     *
     * @return array<string,mixed>|null schválený snapshot, nebo `null`
     */
    public function ensureProbableFromRate(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
        ?int $userId,
    ): ?array {
        [$applicationStart] = $this->quarter($year, $quarter);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockEmployment($supplierId, $employmentId);
            $created = null;
            if ($this->hourlyInQuarter($supplierId, $employmentId, $year, $quarter)
                && $this->existing($supplierId, [$employmentId], $year, $quarter) === []
                && $this->averages->quarterMismatch($supplierId, $employmentId, $year, $quarter) === null
            ) {
                $floors = (new \MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver($this->db))
                    ->forMany($supplierId, [$employmentId], $applicationStart, $applicationStart, true);
                $suggestion = $this->derivation->suggest($supplierId, $employmentId, $year, $quarter, $floors[$employmentId] ?? []);
                if (($suggestion['ready'] ?? false) === true
                    && ($suggestion['source_kind'] ?? null) === 'probable'
                    && in_array($suggestion['probable_source'] ?? null, self::AUTOMATIC_PROBABLE_SOURCES, true)
                    && (int) ($suggestion['probable_hourly_minor'] ?? 0) > 0
                ) {
                    $rationale = ($suggestion['probable_source'] ?? null) === AverageEarningDerivationService::PROBABLE_SOURCE_TERMS
                        ? mb_substr('Automaticky z pravděpodobného výdělku v podmínkách vztahu (§ 355 odst. 2 ZP): '
                            . (string) $suggestion['probable_rationale'], 0, 1000)
                        : self::AUTOMATIC_RATE_RATIONALE;
                    $created = $this->createAndApprove(
                        $supplierId,
                        $employmentId,
                        $year,
                        $quarter,
                        $applicationStart,
                        $suggestion,
                        $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $applicationStart),
                        $userId,
                        $floors[$employmentId] ?? [],
                        $rationale,
                        'probable_from_rate',
                    );
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $created;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    private function createAndApprove(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
        string $applicationStart,
        array $suggestion,
        \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion $ruleset,
        ?int $userId,
        array $hourlyFloorIntervals,
        ?string $rationaleOverride = null,
        string $automatic = 'bulk',
    ): array {
        $probable = $suggestion['source_kind'] === 'probable';
        $gross = $probable ? 0 : (int) $suggestion['gross_earnings_minor'];
        $minutes = $probable ? 0 : $suggestion['worked_minutes'];
        $units = $minutes === null ? AverageEarningDerivationService::durationUnits($suggestion) : null;
        $days = $probable ? 0 : (int) $suggestion['worked_days'];
        $rationale = $rationaleOverride ?? ($probable ? (string) $suggestion['probable_rationale'] : self::ACTUAL_RATIONALE);
        $calculated = $this->calculator->calculate(
            $applicationStart,
            $gross,
            0,
            $minutes,
            $days,
            $probable ? (int) $suggestion['probable_hourly_minor'] : null,
            $probable ? $rationale : null,
            // Původní výpočet zachováváme; nové hodinové minimum se ověří níže.
            null,
            $units,
        );
        \MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver::assertFloorKnown($hourlyFloorIntervals, $applicationStart, $calculated->averageHourlyMinor);
        $trace = $calculated->trace;
        $trace['automatic'] = $automatic;
        $trace['input_version'] = (string) $suggestion['input_version'];
        if ($probable) {
            $trace['probable_source'] = (string) $suggestion['probable_source'];
        }
        $snapshot = $this->averages->create(
            $supplierId,
            $employmentId,
            $year,
            $quarter,
            (string) $suggestion['decisive_from'],
            (string) $suggestion['decisive_to'],
            $gross,
            0,
            $minutes,
            $days,
            $rationale,
            new AverageEarningResult(
                $calculated->sourceKind,
                $calculated->averageHourlyMinor,
                $calculated->supportStatus,
                $trace,
            ),
            $ruleset,
            $userId,
            $units,
        );

        return $this->averages->approve(
            $supplierId,
            (int) $snapshot['id'],
            (int) $snapshot['row_version'],
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $suggestion
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    /**
     * Průměr z převzatých mezd se hromadně neschvaluje: převzatá hrubá mzda
     * není započitatelná mzda § 354 ZP a účetní ji musí posoudit sama.
     *
     * Návrh ze sjednané hodinové sazby se dřív hromadně nezakládal
     * (`probable_hourly_rate_needs_review`). Rozhodnutí maintainera (11/2026):
     * zakládá se automaticky ze sazby a jde opravit novou revizí, viz
     * {@see ensureProbableFromRate()}. Pojistka proto platí jen pro převzaté mzdy.
     *
     * @param array<string,mixed> $suggestion
     */
    private static function needsIndividualReview(array $suggestion): bool
    {
        return (array) ($suggestion['takeover_periods'] ?? []) !== [];
    }

    private function candidate(
        array $suggestion,
        string $employeeName,
        string $employmentCode,
        ?array $existing,
    ): array {
        $takeover = self::needsIndividualReview($suggestion);
        $outdated = $existing === null ? null : self::outdatedDiagnosis($suggestion, $existing);

        return [
            'employment_id' => (int) $suggestion['employment_id'],
            'employee_name' => $employeeName,
            'employment_code' => $employmentCode,
            'decisive_from' => $suggestion['decisive_from'],
            'decisive_to' => $suggestion['decisive_to'],
            'ready' => $suggestion['ready'] && !$takeover,
            'blockers' => $takeover
                ? [...$suggestion['blockers'], 'takeover_needs_review']
                : $suggestion['blockers'],
            'source_kind' => $suggestion['source_kind'],
            'probable_source' => $suggestion['probable_source'],
            'probable_hourly_minor' => $suggestion['probable_hourly_minor'],
            'probable_rationale' => $suggestion['probable_rationale'],
            // Proč pravděpodobný výdělek a ne skutečný průměr (§ 355 odst. 1 ZP).
            'probable_reason' => AverageEarningDerivationService::probableReason($suggestion),
            'below_minimum_wage' => $suggestion['below_minimum_wage'] ?? null,
            'probable_unusable' => $suggestion['probable_unusable'] ?? null,
            'gross_earnings_minor' => $suggestion['gross_earnings_minor'],
            'worked_minutes' => $suggestion['worked_minutes'],
            'worked_duration_units' => $suggestion['worked_duration_units'] ?? null,
            'worked_days' => $suggestion['worked_days'],
            'input_version' => $suggestion['input_version'],
            'existing' => $existing,
            'existing_outdated' => $outdated !== null,
            // Proč schválený průměr neodpovídá podkladům a kolik by vyšel
            // nově; přehled podle toho účetní řekne, co se změnilo, a nabídne
            // hromadné založení nové revize.
            'existing_outdated_reason' => $outdated['reason'] ?? null,
            'suggested_average_hourly_minor' => $outdated === null
                ? null
                : $this->suggestedAverage($suggestion, $outdated),
        ];
    }

    /**
     * Průměr, který by z dnešních podkladů vyšel, nebo `null`, když ho
     * spočítat nejde (pak účetní řeší vztah jednotlivě).
     *
     * @param array<string,mixed> $suggestion
     * @param array{reason:string,gross_minor:int,duration_units:int,worked_days:int} $outdated
     */
    private function suggestedAverage(array $suggestion, array $outdated): ?int
    {
        try {
            return $this->calculator->calculate(
                // Průměr se zjišťuje k prvnímu dni po rozhodném období (§ 354 odst. 2 ZP).
                (new \DateTimeImmutable((string) $suggestion['decisive_to']))->modify('+1 day')->format('Y-m-d'),
                $outdated['gross_minor'],
                0,
                PayrollDuration::exactMinutes($outdated['duration_units']),
                $outdated['worked_days'],
                null,
                null,
                null,
                $outdated['duration_units'],
            )->averageHourlyMinor;
        } catch (\InvalidArgumentException|\OverflowException|\DomainException) {
            return null;
        }
    }

    /**
     * Nejnovější průměr za čtvrtletí u každého vztahu stránky — jeden dotaz.
     *
     * @param list<int> $employmentIds
     * @return array<int,array<string,mixed>>
     */
    private function existing(int $supplierId, array $employmentIds, int $year, int $quarter): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, employment_id, status, source_kind, average_hourly_minor, revision_no,
                    gross_earnings_minor, longer_period_allocated_minor, worked_minutes, worked_duration_units, worked_days,
                    JSON_VALUE(input_trace, '$.probable_source') AS probable_source
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND applicable_year = ? AND applicable_quarter = ?
                AND employment_id IN ({$in})
                AND status IN ('manual_review', 'approved')
              ORDER BY employment_id, revision_no",
        );
        $stmt->execute([$supplierId, $year, $quarter, ...$employmentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'source_kind' => (string) $row['source_kind'],
                'average_hourly_minor' => (int) $row['average_hourly_minor'],
                // Odkud byl pravděpodobný výdělek vzat (minimální mzda, sazba,
                // zadání účetní); přehled podle toho nabídne výběr řádků
                // s průměrem jen z minimální mzdy.
                'probable_source' => is_string($row['probable_source'] ?? null) ? $row['probable_source'] : null,
                'gross_earnings_minor' => (int) $row['gross_earnings_minor'],
                'longer_period_allocated_minor' => (int) $row['longer_period_allocated_minor'],
                'worked_minutes' => $row['worked_minutes'] === null ? null : (int) $row['worked_minutes'],
                'worked_duration_units' => $row['worked_duration_units'] === null ? null : (int) $row['worked_duration_units'],
                'worked_days' => (int) $row['worked_days'],
            ];
        }

        return $result;
    }

    /**
     * Proč schválený skutečný průměr neodpovídá dnešním podkladům.
     *
     * Vrací `null`, když průměr sedí nebo se s návrhem porovnat nedá
     * (pravděpodobný výdělek, převzaté měsíce, měsíc s blokací). Důvody:
     *
     *  - `holiday_hours`: započitatelná mzda je stejná, liší se jen
     *    odpracovaná doba, a to právě o hodiny svátku krytého nekrácenou
     *    měsíční mzdou. Ty do odpracované doby patří (§ 353 odst. 2 zákoníku
     *    práce: „doba, za kterou zaměstnanci přísluší mzda“), dříve schválený
     *    průměr je nezapočítal a vyšel vyšší.
     *  - `earnings`: změnila se mzda rozhodného období (oprava běhu po
     *    schválení průměru).
     *  - `worked_time`: změnila se odpracovaná doba nebo dny (oprava docházky).
     *  - `mixed`: obojí.
     *
     * @param array<string,mixed> $suggestion
     * @param array<string,mixed> $existing
     * @return array{reason:string,gross_minor:int,duration_units:int,worked_days:int}|null
     */
    public static function outdatedDiagnosis(array $suggestion, array $existing): ?array
    {
        if (($existing['source_kind'] ?? null) !== 'actual') {
            return null;
        }
        $gross = 0;
        $units = 0;
        $days = 0;
        $holidayUnits = 0;
        foreach ((array) ($suggestion['months'] ?? []) as $month) {
            if ((array) ($month['blockers'] ?? []) !== []
                || ($month['takeover'] ?? false) === true
                || !is_int($month['gross_earnings_minor'] ?? null)
                || AverageEarningDerivationService::durationUnits($month) === null
                || !is_int($month['worked_days'] ?? null)
            ) {
                return null;
            }
            $gross += $month['gross_earnings_minor'];
            $units = PayrollDuration::add($units, AverageEarningDerivationService::durationUnits($month));
            $days += $month['worked_days'];
            if (is_int($month['wage_covered_holiday_duration_units'] ?? null)) {
                $holidayUnits = PayrollDuration::add($holidayUnits, $month['wage_covered_holiday_duration_units']);
            }
        }
        $existingUnits = AverageEarningDerivationService::durationUnits($existing);
        $grossDiffers = $gross !== $existing['gross_earnings_minor'] - $existing['longer_period_allocated_minor'];
        $timeDiffers = $units !== $existingUnits || $days !== $existing['worked_days'];
        if (!$grossDiffers && !$timeDiffers) {
            return null;
        }

        return [
            'reason' => match (true) {
                $grossDiffers && $timeDiffers => 'mixed',
                $grossDiffers => 'earnings',
                $holidayUnits > 0 && $days === $existing['worked_days']
                    && $existingUnits !== null && $units - $existingUnits === $holidayUnits => 'holiday_hours',
                default => 'worked_time',
            },
            'gross_minor' => $gross,
            'duration_units' => $units,
            'worked_days' => $days,
        ];
    }

    /**
     * Totéž jako {@see ensureProbableFromRate()} pro období měsíce, ale chyba
     * nezmění odpověď akce, která ho vyvolala (schválení docházky, přepnutí
     * zdroje hodin). Odvolanou transakci vyhazuje dál.
     *
     * @return array<string,mixed>|null
     */
    public function ensureProbableFromRateAfterTimeChange(int $supplierId, int $employmentId, string $periodStart, ?int $userId): ?array
    {
        try {
            return $this->ensureProbableFromRate(
                $supplierId,
                $employmentId,
                (int) substr($periodStart, 0, 4),
                intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1,
                $userId,
            );
        } catch (\Throwable $exception) {
            if (\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($exception)) {
                throw $exception;
            }
            error_log(sprintf(
                'Automatický pravděpodobný výdělek vztahu %d za %s se nepodařilo založit: %s',
                $employmentId,
                $periodStart,
                $exception->getMessage(),
            ));

            return null;
        }
    }

    /** Má vztah ve čtvrtletí hodinové podmínky s kladnou sazbou? */
    private function hourlyInQuarter(int $supplierId, int $employmentId, int $year, int $quarter): bool
    {
        [$from, $to] = $this->quarter($year, $quarter);
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND remuneration_type = "hourly" AND hourly_rate_minor > 0
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $employmentId, $to, $from]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Pravděpodobný výdělek zadaný účetní (§ 355 odst. 2 ZP) jako nová
     * schválená revize průměru za čtvrtletí. Schválení odsune dosavadní
     * schválenou revizi stejně jako na kartě průměru
     * ({@see PayrollAverageEarningRepository::approve()}); jestli se to smí,
     * rozhoduje volající ({@see ProbableEarningBulkService}).
     *
     * @return array<string,mixed> schválený snapshot
     */
    public function approveProbableEarning(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
        int $hourlyMinor,
        string $rationale,
        ?int $userId,
    ): array {
        [$applicationStart] = $this->quarter($year, $quarter);
        $start = new \DateTimeImmutable($applicationStart);
        $floors = (new \MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver($this->db))
            ->forMany($supplierId, [$employmentId], $applicationStart, $applicationStart, true);

        return $this->createAndApprove(
            $supplierId,
            $employmentId,
            $year,
            $quarter,
            $applicationStart,
            [
                'source_kind' => 'probable',
                'probable_source' => AverageEarningDerivationService::PROBABLE_SOURCE_TERMS,
                'probable_hourly_minor' => $hourlyMinor,
                'probable_rationale' => $rationale,
                'worked_minutes' => 0,
                'decisive_from' => $start->modify('-3 months')->format('Y-m-d'),
                'decisive_to' => $start->modify('-1 day')->format('Y-m-d'),
                'input_version' => hash('sha256', \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode([$employmentId, $year, $quarter, $hourlyMinor, $rationale])),
            ],
            $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $applicationStart),
            $userId,
            $floors[$employmentId] ?? [],
            $rationale,
            'bulk_probable',
        );
    }

    /**
     * Jméno a označení vztahů pro hlášení přeskočených řádků jedním dotazem.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{employee_id:int,full_name:string,code:string}>
     */
    public function people(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id IN ({$in})",
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'code' => (string) $row['code'],
            ];
        }

        return $result;
    }

    private function lockEmployment(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
    }

    /** @return array{0:string,1:string} první a poslední den čtvrtletí */
    private function quarter(int $year, int $quarter): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Rok použití průměru není platný.');
        }
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1));

        return [
            $start->format('Y-m-d'),
            $start->modify('+2 months')->modify('last day of this month')->format('Y-m-d'),
        ];
    }
}
