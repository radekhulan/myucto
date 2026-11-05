<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use PDO;

/** § 357 v nové hodinové cestě; původní měsíční průměry a jejich stopy nemění. */
final class PayrollHourlyAverageFloorResolver
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return array<int,list<array<string,mixed>>> */
    public function forMany(int $supplierId, array $employmentIds, string $from, string $to, bool $lock = false): array
    {
        if (!IsoDate::isValid($from) || !IsoDate::isValid($to) || $from > $to) throw new \InvalidArgumentException('Období použití hodinového průměru není platné.');
        $ids = array_values(array_unique($employmentIds));
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Pracovní vztah není platný.');
        if ($ids === []) return [];
        sort($ids, SORT_NUMERIC);
        $result = array_fill_keys($ids, []);
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Zápisy podmínek i politik používají stejný zámek vztahu; načítáme je až po něm.
        $stmt = $this->db->pdo()->prepare("SELECT id, relation_type FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids]);
        $relations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($supplierId, array_map('intval', array_keys($relations)), $from, $to);
        if ($terms === []) return $result;
        $policies = (new PayrollSurchargeRepository($this->db))->policiesForMany($supplierId, array_keys($terms), $from, $to, $lock);
        $provider = (new PayrollRulesetRegistry(new PayrollRulesetRepository($this->db)))->provider();
        foreach ($terms as $id => $rows) {
            $hourly = array_values(array_filter($rows, static fn(array $term): bool => ($term['remuneration_type'] ?? null) === 'hourly'));
            $result[$id] = self::segments($hourly, $policies[$id] ?? [], $from, $to, (string) $relations[$id], $provider);
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $intervals @return array{hourly_minor:int,hourly_proof:?array} */
    public static function apply(array $intervals, string $date, int $approvedHourlyMinor): array
    {
        if (!IsoDate::isValid($date) || $approvedHourlyMinor <= 0) throw new \InvalidArgumentException('Použití průměru vyžaduje platné datum a kladný schválený výdělek.');
        $matches = array_values(array_filter($intervals, static fn(array $row): bool => ($row['date_from'] ?? '9999') <= $date && ($row['date_to'] ?? '') >= $date));
        if ($matches === []) return ['hourly_minor' => $approvedHourlyMinor, 'hourly_proof' => null];
        if (count($matches) !== 1 || ($matches[0]['weekly_hours_conflict'] ?? false) === true
            || !is_int($matches[0]['minimum_hourly_minor'] ?? null) || $matches[0]['minimum_hourly_minor'] <= 0) {
            throw new \InvalidArgumentException('Pro hodinovou náhradu doložte jednoznačnou stanovenou týdenní dobu a účinné minimum ke dni jejího použití.');
        }
        $floor = $matches[0];
        $hourly = max($approvedHourlyMinor, $floor['minimum_hourly_minor']);
        return ['hourly_minor' => $hourly, 'hourly_proof' => [...$floor, 'application_date' => $date,
            'raw_average_hourly_minor' => $approvedHourlyMinor, 'average_hourly_minor' => $hourly]];
    }

    /** Původní objekt vracíme beze změny, pokud se nové hodinové pravidlo neuplatní. */
    public static function applyToResult(AverageEarningResult $result, array $intervals, string $date): AverageEarningResult
    {
        $applied = self::apply($intervals, $date, $result->averageHourlyMinor);
        if ($applied['hourly_proof'] === null) return $result;
        $proof = $applied['hourly_proof'];
        return new AverageEarningResult($result->sourceKind, $applied['hourly_minor'], $result->supportStatus,
            [...$result->trace, 'average_hourly_minor' => $applied['hourly_minor'], 'hourly_minimum_proof' => $proof,
                'minimum_wage_hourly_minor' => $proof['minimum_hourly_minor'],
                'minimum_wage_weekly_minutes' => $proof['stated_weekly_minutes'],
                'minimum_wage_ruleset_id' => $proof['ruleset_id'],
                'minimum_wage_floor_applied' => ($result->trace['raw_hourly_minor'] ?? $result->averageHourlyMinor) < $proof['minimum_hourly_minor']]);
    }

    /** Návrh i jeho optimistická verze zahrnují nové minimum pouze v hodinovém úseku. */
    public static function applyToSuggestion(array $suggestion, array $intervals, string $date): array
    {
        $current = array_values(array_filter($intervals, static fn(array $row): bool => $row['date_from'] <= $date && $row['date_to'] >= $date));
        if ($current === []) return $suggestion;
        $suggestion['hourly_minimum_intervals'] = $current;
        $suggestion['input_version'] = hash('sha256', \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode([
            'input_version' => $suggestion['input_version'], 'hourly_minimum_intervals' => $current,
        ]));
        try {
            $probable = $suggestion['probable_hourly_minor'] ?? null;
            $applied = self::apply($current, $date, is_int($probable) && $probable > 0 ? $probable : 1);
            if (is_int($probable) && $probable > 0) {
                $suggestion['probable_hourly_minor'] = $applied['hourly_minor'];
                $suggestion['hourly_minimum_proof'] = $applied['hourly_proof'];
                if ($applied['hourly_minor'] > $probable) $suggestion['probable_rationale'] .= ' Uplatněna zákonná spodní mez průměru podle stanovené týdenní doby.';
            }
        } catch (\InvalidArgumentException) {
            $suggestion['ready'] = false;
            $suggestion['blockers'][] = 'hourly_average_minimum_missing';
        }
        return $suggestion;
    }

    /** § 79 se nesmí zaměnit s kratším smluvním úvazkem podle § 80. */
    /** @return list<array<string,mixed>> */
    public static function segments(array $terms, array $policies, string $start, string $end, string $relation, \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider $provider): array
    {
        $result = [];
        foreach ($terms as $term) {
            $from = max($start, (string) $term['effective_from']);
            $to = min($end, (string) ($term['effective_to'] ?? $end));
            if ($from > $to) continue;
            $boundaries = [$from => true];
            foreach ($provider->versions() as $version) {
                if ($version->domain !== \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain::EmploymentThresholds) continue;
                if ($version->effectiveFrom > $from && $version->effectiveFrom <= $to) $boundaries[$version->effectiveFrom] = true;
                $next = (new \DateTimeImmutable($version->effectiveTo))->modify('+1 day')->format('Y-m-d');
                if ($next > $from && $next <= $to) $boundaries[$next] = true;
            }
            foreach ($policies as $policy) {
                $policyStart = (string) $policy['valid_from'];
                if ($policyStart > $from && $policyStart <= $to) $boundaries[$policyStart] = true;
                if (($policy['valid_to'] ?? null) !== null) {
                    $next = (new \DateTimeImmutable((string) $policy['valid_to']))->modify('+1 day')->format('Y-m-d');
                    if ($next > $from && $next <= $to) $boundaries[$next] = true;
                }
            }
            $dates = array_keys($boundaries);
            sort($dates);
            foreach ($dates as $index => $date) {
                $last = isset($dates[$index + 1]) ? (new \DateTimeImmutable($dates[$index + 1]))->modify('-1 day')->format('Y-m-d') : $to;
                $matching = array_values(array_filter($policies, static fn(array $policy): bool => $policy['valid_from'] <= $date && (($policy['valid_to'] ?? null) === null || $policy['valid_to'] >= $date)));
                $minimum = count($matching) === 1 ? ($matching[0]['hourly_terms']['minimum_wage'] ?? null) : null;
                $stated = self::statedWeeklyMinutes([$term]);
                $override = $minimum['statutory_weekly_minutes'] ?? null;
                $conflict = count($matching) > 1 || (!in_array($relation, ['dpp', 'dpc'], true) && $override !== null && $stated !== null && (int) $override !== $stated);
                // Sjednaný rozsah dohody neurčuje stanovenou týdenní pracovní dobu.
                $minutes = in_array($relation, ['dpp', 'dpc'], true) ? ($override ?? 2400) : ($conflict ? null : ($override ?? $stated));
                $hourly = null; $base = null; $monthly = null; $rulesetId = null;
                try {
                    $version = $provider->forCalculation(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain::EmploymentThresholds, $date);
                    $rulesetId = $version->id;
                    if ($version->effectiveTo < $last) throw new \UnexpectedValueException('Legislativní pravidla nepokrývají celý úsek pracovní doby.');
                    $base = (int) $version->parameter('minimum_wage.hourly_40h_week')->value;
                    $monthly = (int) $version->parameter('minimum_wage.monthly_40h_week')->value;
                    if ($minutes !== null) $hourly = \MyInvoice\Service\Payroll\Hourly\MinimumHourlyRate::calculate($base, (int) $minutes);
                } catch (\LogicException|\UnexpectedValueException|\InvalidArgumentException) {}
                $result[] = ['term_id' => (int) $term['id'], 'date_from' => $date, 'date_to' => $last,
                    'stated_weekly_minutes' => $minutes, 'minimum_base40_minor' => $base, 'minimum_monthly_minor' => $monthly,
                    'minimum_hourly_minor' => $hourly, 'remuneration_type' => $term['remuneration_type'] ?? null,
                    'term_row_version' => (int) ($term['row_version'] ?? 0), 'policy_source' => $matching, 'ruleset_id' => $rulesetId, 'assessment' => $minimum['assessment'] ?? 'hourly',
                    'advance_proof' => $minimum['advance_proof'] ?? null,
                    'statutory_weekly_reference' => $minimum['statutory_weekly_reference'] ?? null,
                    'weekly_hours_conflict' => $conflict];
            }
        }
        return $result;
    }

    public static function statedWeeklyMinutes(array $terms): ?int
    {
        if ($terms === []) return null;
        usort($terms, static fn(array $a, array $b): int => $a['effective_from'] <=> $b['effective_from']);
        $term = $terms[array_key_last($terms)];
        if (!isset($term['weekly_hours'], $term['workload_basis_points']) || !is_int($term['workload_basis_points']) || $term['workload_basis_points'] <= 0) return null;
        $hours = \MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder::statedWeeklyHours((string) $term['weekly_hours'], $term['workload_basis_points']);
        try {
            $minutes = $hours === null ? null : PayrollDuration::exactMinutes(PayrollDuration::parseHours($hours));
        } catch (\InvalidArgumentException|\OverflowException) { return null; }
        return $minutes !== null && $minutes > 0 && $minutes <= 2400 ? $minutes : null;
    }

}
