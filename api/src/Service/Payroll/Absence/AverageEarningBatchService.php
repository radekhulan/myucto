<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
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
            "SELECT employment.id, employment.code, employee.full_name
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
        $items = [];
        foreach ($rows as $row) {
            $employmentId = (int) $row['id'];
            $items[] = $this->candidate(
                $this->derivation->suggest($supplierId, $employmentId, $year, $quarter),
                (string) $row['full_name'],
                (string) $row['code'],
                $existing[$employmentId] ?? null,
            );
        }

        return compact('items', 'total', 'limit', 'offset');
    }

    /**
     * Založí a schválí průměry vybraných vztahů. Vše, nebo nic.
     *
     * @param list<mixed> $requested položky `{employment_id, input_version}`
     *        z přehledu
     * @return list<array<string,mixed>> schválené snapshoty
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
            $approved = [];
            foreach ($versions as $employmentId => $expectedVersion) {
                $this->lockEmployment($supplierId, $employmentId);
                $suggestion = $this->derivation->suggest($supplierId, $employmentId, $year, $quarter);
                if (!hash_equals($expectedVersion, (string) $suggestion['input_version'])) {
                    throw new AverageEarningBatchConflictException($employmentId);
                }
                if (($suggestion['ready'] ?? false) !== true) {
                    throw new \InvalidArgumentException(sprintf(
                        'Pracovní vztah %d nemá připravený návrh průměru: %s.',
                        $employmentId,
                        implode(', ', (array) $suggestion['blockers']),
                    ));
                }
                if (self::needsIndividualReview($suggestion)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Pracovní vztah %d má průměr z převzatých mezd předchozího programu; '
                        . 'potvrďte ho jednotlivě v Nepřítomnostech → Průměrné výdělky.',
                        $employmentId,
                    ));
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
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $approved;
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
    ): array {
        $probable = $suggestion['source_kind'] === 'probable';
        $gross = $probable ? 0 : (int) $suggestion['gross_earnings_minor'];
        $minutes = $probable ? 0 : (int) $suggestion['worked_minutes'];
        $days = $probable ? 0 : (int) $suggestion['worked_days'];
        $rationale = $probable ? (string) $suggestion['probable_rationale'] : self::ACTUAL_RATIONALE;
        $calculated = $this->calculator->calculate(
            $applicationStart,
            $gross,
            0,
            $minutes,
            $days,
            $probable ? (int) $suggestion['probable_hourly_minor'] : null,
            $probable ? $rationale : null,
            // Stejně jako ruční založení: stanovenou kratší týdenní dobu
            // podmínky vztahu nerozlišují, minimum platí pro 40 hodin.
            null,
        );
        $trace = $calculated->trace;
        $trace['automatic'] = 'bulk';
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
            'gross_earnings_minor' => $suggestion['gross_earnings_minor'],
            'worked_minutes' => $suggestion['worked_minutes'],
            'worked_days' => $suggestion['worked_days'],
            'input_version' => $suggestion['input_version'],
            'existing' => $existing,
            'existing_outdated' => $existing !== null && self::existingOutdated($suggestion, $existing),
        ];
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
                    gross_earnings_minor, longer_period_allocated_minor, worked_minutes, worked_days
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
                'gross_earnings_minor' => (int) $row['gross_earnings_minor'],
                'longer_period_allocated_minor' => (int) $row['longer_period_allocated_minor'],
                'worked_minutes' => (int) $row['worked_minutes'],
                'worked_days' => (int) $row['worked_days'],
            ];
        }

        return $result;
    }

    /**
     * Platí založený skutečný průměr pořád? Mzdový běh rozhodného období
     * se mohl po schválení průměru opravit (zpětně doplněná odměna, oprava
     * docházky). Průměr pak nesedí na uzavřené běhy a s ním náhrady
     * i atribut 10345 JMHZ v měsících čtvrtletí. Porovnávají se úhrny
     * z aktuálních běhů s úhrny uloženými v průměru; ruční průměr
     * z podkladů mimo MyÚčto (měsíce bez běhu) se posoudit nedá.
     *
     * @param array<string,mixed> $suggestion
     * @param array<string,mixed> $existing
     */
    private static function existingOutdated(array $suggestion, array $existing): bool
    {
        if ($existing['source_kind'] !== 'actual') {
            return false;
        }
        $gross = 0;
        $minutes = 0;
        $days = 0;
        foreach ((array) ($suggestion['months'] ?? []) as $month) {
            if ((array) ($month['blockers'] ?? []) !== []
                || ($month['takeover'] ?? false) === true
                || !is_int($month['gross_earnings_minor'] ?? null)
                || !is_int($month['worked_minutes'] ?? null)
                || !is_int($month['worked_days'] ?? null)
            ) {
                return false;
            }
            $gross += $month['gross_earnings_minor'];
            $minutes += $month['worked_minutes'];
            $days += $month['worked_days'];
        }

        return $gross !== $existing['gross_earnings_minor'] - $existing['longer_period_allocated_minor']
            || $minutes !== $existing['worked_minutes']
            || $days !== $existing['worked_days'];
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
