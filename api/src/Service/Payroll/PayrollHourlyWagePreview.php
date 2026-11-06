<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyMinimumWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyMinimumWageContext;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;

/** Dávkový náhled nad stejnými neměnnými podklady, které použije běh. */
final class PayrollHourlyWagePreview
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<array<string,mixed>> $employments @return array<int,array<string,mixed>> */
    public function forMany(int $supplierId, string $periodStart, array $employments, bool $lock = false): array
    {
        if ($employments === []) return [];
        $ids = array_map(static fn (array $row): int => (int) $row['employment_id'], $employments);
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $batch = new PayrollRunSnapshotBatchLoader($this->db);
        $hourlyTerms = $batch->hourlyRemunerationTerms($supplierId, $ids, $periodStart, $end);
        $outstanding = (new PayrollHourlyEntitlementLedger($this->db))->employmentIdsWithOutstanding($supplierId, $ids, $periodStart, includeCurrentClaims: true);
        $settlementIds = array_values(array_diff($outstanding, array_keys($hourlyTerms)));
        $settlementTerms = $batch->remunerationTerms($supplierId, $settlementIds, $periodStart, $end);
        foreach ($settlementIds as $id) $settlementTerms[$id] ??= [];
        $terms = $hourlyTerms + $settlementTerms;
        if ($terms === []) return [];
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($supplierId, array_keys($terms), $periodStart, $lock);
        $entitlementContexts = (new PayrollHourlyEntitlementContextLoader($this->db))->forMany($supplierId, $periodStart, $employments, $terms, $sources, $lock);
        $minimumFundIds = self::minimumFundIds($entitlementContexts);
        $monthlyParts = (new PayrollHourlyMonthlyParts($this->db))->forMany($supplierId, $periodStart, $employments, $hourlyTerms, $lock, $minimumFundIds);
        return $this->fromSources($supplierId, $periodStart, $employments, $terms, $hourlyTerms, $sources, $entitlementContexts, $monthlyParts);
    }

    /**
     * Stejná blokace z více úseků (např. „Doložte příplatkové vrstvy…" za
     * každý úsek pracovní doby) se ukáže jednou; kontext zůstává u první.
     *
     * @param list<array<string,mixed>> $blockers
     * @return list<array<string,mixed>>
     */
    private static function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[($blocker['code'] ?? '') . '|' . ($blocker['message'] ?? '')] ??= $blocker;
        }

        return array_values($unique);
    }

    /** Výpočet nad již dávkově načtenými podklady snapshotu, bez jejich opětovného čtení. */
    public function fromSources(int $supplierId, string $periodStart, array $employments, array $terms, array $hourlyTerms,
        array $sources, array $entitlementContexts, array $monthlyParts): array
    {
        if ($terms === []) return [];
        $rounding = PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
        $calculator = new PayrollHourlyWageCalculator();
        $result = [];
        $componentProblems = null;
        foreach ($employments as $employment) {
            $id = (int) $employment['employment_id'];
            if (!isset($terms[$id])) continue;
            $source = $sources[$id] ?? null;
            $entitlementContext = $entitlementContexts[$id] ?? null;
            if ($entitlementContext !== null && isset($monthlyParts[$id])) {
                $entitlementContext['monthly_fund_source'] = $monthlyParts[$id]['source'];
            }
            $context = new PayrollHourlyWageContext(
                employmentId: $id,
                periodStart: $periodStart,
                employmentType: (string) $employment['relation_type'],
                terms: $terms[$id],
                segments: $entitlementContext['work_segments'] ?? self::segments($source, $terms[$id]),
                workedTimeEvidence: $source,
                rounding: $rounding,
                monthlyParts: $monthlyParts[$id]['parts'] ?? [],
                entitlementContext: $entitlementContext,
                settlementOnly: !isset($hourlyTerms[$id]),
            );
            $calculated = $calculator->calculate($context);
            $minimum = null;
            $claims = $calculated->claims;
            $blockers = $calculated->blockers;
            $complete = $calculated->complete;
            $evidenceHash = $calculated->evidenceHash;
            if (!$context->settlementOnly) {
                $minimumContext = self::minimumContext($context, $calculated->claims, $calculated->includedAllocations);
                $assessment = (new PayrollHourlyMinimumWageCalculator())->calculate($minimumContext);
                $minimumBlockers = $assessment->blockers;
                foreach ($entitlementContext['minimum_floor_segments'] ?? [] as $floor) {
                    if (($floor['weekly_hours_conflict'] ?? false) === true) {
                        $minimumBlockers[] = ['code' => 'hourly_minimum_weekly_hours_conflict',
                            'message' => 'Stanovená týdenní doba ve sjednání odporuje podmínkám vztahu. Opravte doložené údaje.',
                            'context' => ['term_id' => $floor['term_id'], 'date_from' => $floor['date_from'], 'date_to' => $floor['date_to']]];
                    }
                }
                $minimumClaim = $assessment->claim;
                if ($minimumBlockers !== $assessment->blockers) {
                    $minimumClaim = [...$minimumClaim, 'ready' => false, 'raw_numerator_minor' => null, 'raw_denominator' => null, 'analytical_minor' => null, 'payable_minor' => null];
                }
                $minimum = ['complete' => $assessment->complete && count($minimumBlockers) === 0, 'claim' => $minimumClaim,
                    'blockers' => $minimumBlockers, 'assessment_evidence' => $assessment->assessmentEvidence,
                    'evidence_hash' => $assessment->evidenceHash];
                $claims[] = $minimumClaim;
                $blockers = [...$blockers, ...$minimumBlockers];
                $complete = $complete && $minimum['complete'];
                $evidenceHash = hash('sha256', CanonicalJson::encode(['hourly_wage' => $calculated->evidenceHash, 'minimum_wage' => $assessment->evidenceHash]));
            }
            foreach ($claims as $claim) {
                $code = (string) ($claim['component_code'] ?? '');
                if (in_array($claim['payable_minor'] ?? null, [null, 0], true)) continue;
                $componentProblems ??= PayrollHourlyWageComponents::definitionProblems($this->db->pdo(), $supplierId, $periodStart);
                if (!isset($componentProblems[$code])) continue;
                $blockers[] = ['code' => 'hourly_component_definition_invalid',
                    'message' => sprintf('Mzdová složka %s je ve firmě vedená jako vlastní nebo nemá v měsíci jednoznačnou verzi s klasifikací hodinové mzdy. Upravte nebo ukončete vlastní složku v číselníku.', $code),
                    'context' => ['component_code' => $code, 'reason' => $componentProblems[$code]]];
                $complete = false;
            }
            $total = null;
            if ($complete) {
                $total = 0;
                foreach ($claims as $claim) {
                    $amount = (int) $claim['payable_minor'];
                    if ($amount > PHP_INT_MAX - $total) throw new \OverflowException('Součet hodinové mzdy překročil celočíselný rozsah.');
                    $total += $amount;
                }
            }
            $result[$id] = [
                'settlement_only' => $context->settlementOnly,
                'complete' => $complete,
                'blockers' => self::uniqueBlockers($blockers),
                'rounding' => $rounding->value,
                'evidence_hash' => $evidenceHash,
                'claims' => $claims,
                'minimum_wage' => $minimum,
                'entitlements' => $calculated->entitlements,
                'included_allocations' => $calculated->includedAllocations,
                'payable_total_minor' => $total,
                '_source_snapshot' => [
                    'calculator_version' => 'hourly-wage.v1',
                    'settlement_only' => $context->settlementOnly,
                    'terms' => $terms[$id],
                    'worked_time_evidence' => $source,
                    'segments' => $context->segments,
                    'rounding' => $rounding->value,
                    'monthly_parts' => $context->monthlyParts,
                    'entitlement_context' => $context->entitlementContext,
                    ...($minimum === null ? [] : ['minimum_wage' => $minimum]),
                ],
            ];
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $claims @param list<array<string,mixed>> $included */
    private static function minimumContext(PayrollHourlyWageContext $wage, array $claims, array $included): PayrollHourlyMinimumWageContext
    {
        $source = $wage->entitlementContext ?? [];
        $parts = [];
        $assessments = [];
        $proofs = [];
        foreach ($wage->segments as $segment) {
            $matches = array_values(array_filter($source['minimum_floor_segments'] ?? [], static fn(array $floor): bool =>
                $floor['date_from'] <= $segment['date_to'] && $floor['date_to'] >= $segment['date_from']));
            $floor = count($matches) === 1 && $matches[0]['date_from'] <= $segment['date_from'] && $matches[0]['date_to'] >= $segment['date_to'] ? $matches[0] : [];
            $term = null;
            foreach ($wage->terms as $candidate) if ((int) $candidate['id'] === (int) ($floor['term_id'] ?? 0)) { $term = $candidate; break; }
            $mode = $floor['assessment'] ?? 'hourly';
            $assessments[$mode] = true;
            if (($floor['advance_proof'] ?? null) !== null) $proofs[CanonicalJson::encode($floor['advance_proof'])] = $floor['advance_proof'];
            $worked = $segment['worked_duration_units'] ?? null;
            $overtime = $segment['overtime_duration_units'] ?? null;
            $contractual = null;
            try { if (isset($term['weekly_hours'])) $contractual = PayrollDuration::parseHours((string) $term['weekly_hours']); }
            catch (\InvalidArgumentException|\OverflowException) {}
            $parts[] = ['term_id' => $floor['term_id'] ?? null, 'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'regular_worked_duration_units' => is_int($worked) && is_int($overtime) && $worked >= $overtime ? $worked - $overtime : null,
                'stated_weekly_minutes' => $floor['stated_weekly_minutes'] ?? null, 'minimum_base40_minor' => $floor['minimum_base40_minor'] ?? null,
                'minimum_monthly_minor' => $floor['minimum_monthly_minor'] ?? null,
                'full_month_fund_duration_units' => ($source['monthly_fund_source']['minimum_fund_usable'] ?? false) ? ($source['monthly_fund_source']['full_fund_duration_units'] ?? null) : null,
                'contractual_weekly_duration_units' => $contractual];
        }
        $assessment = count($assessments) === 1 ? (string) array_key_first($assessments) : (count($assessments) === 0 ? 'hourly' : 'mixed');
        $base = null;
        foreach ($claims as $claim) if (($claim['purpose'] ?? null) === 'ordinary_base' && ($claim['ready'] ?? false) === true && is_int($claim['payable_minor'] ?? null)) $base = $claim['payable_minor'];
        return new PayrollHourlyMinimumWageContext($wage->employmentId, $wage->periodStart, $wage->employmentType,
            $assessment, $parts, $base, $source['minimum_input_evidence'] ?? [], $included, $wage->rounding,
            count($proofs) === 1 ? array_values($proofs)[0] : null);
    }

    /** @param array<int,array<string,mixed>> $contexts @return list<int> */
    public static function minimumFundIds(array $contexts): array
    {
        $ids = [];
        foreach ($contexts as $id => $context) foreach ($context['minimum_floor_segments'] ?? [] as $segment) {
            if (($segment['assessment'] ?? null) === 'monthly') { $ids[] = (int) $id; break; }
        }
        return $ids;
    }

    /** @param ?array<string,mixed> $source @param list<array<string,mixed>> $terms @param list<array<string,mixed>> $policyIntervals @return list<array<string,mixed>> */
    public static function segments(?array $source, array $terms, array $policyIntervals = []): array
    {
        if (($source['work_source'] ?? null) === 'manual_summary') {
            return $source['manual_summary']['segments'] ?? [];
        }
        if (($source['work_source'] ?? null) !== 'entries') return [];
        $groups = [];
        foreach ($source['time_entries'] ?? [] as $entry) {
            if (!in_array($entry['category'] ?? null, ['regular', 'overtime'], true)) continue;
            $zone = new \DateTimeZone((string) $entry['timezone_name']);
            $start = new \DateTimeImmutable((string) $entry['starts_at_utc'], new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable((string) $entry['ends_at_utc'], new \DateTimeZone('UTC'));
            $day = $start->setTimezone($zone)->format('Y-m-d');
            $lastDay = $end->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
            $matching = array_values(array_filter($terms, static fn (array $term): bool =>
                $term['effective_from'] <= $day && ($term['effective_to'] === null || $term['effective_to'] >= $lastDay)));
            // Záznam přes změnu sazby vyžaduje doložené rozdělení přestávky.
            if (count($matching) !== 1) return [];
            $termId = (int) $matching[0]['id'];
            $policyId = '';
            if ($policyIntervals !== []) {
                $matchingPolicy = array_values(array_filter($policyIntervals, static fn (array $policy): bool =>
                    $policy['valid_from'] <= $day && (($policy['valid_to'] ?? null) === null || $policy['valid_to'] >= $lastDay)));
                if (count($matchingPolicy) !== 1) return [];
                $policyId = (string) $matchingPolicy[0]['id'];
            }
            $group = $termId . ':' . $policyId;
            $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60) - (int) $entry['break_minutes'];
            if ($minutes < 0) return [];
            $units = PayrollDuration::fromMinutes($minutes);
            $groups[$group] ??= ['date_from' => $day, 'date_to' => $lastDay, 'worked_duration_units' => 0, 'overtime_duration_units' => 0];
            $groups[$group]['date_from'] = min($groups[$group]['date_from'], $day);
            $groups[$group]['date_to'] = max($groups[$group]['date_to'], $lastDay);
            $groups[$group]['worked_duration_units'] = PayrollDuration::add($groups[$group]['worked_duration_units'], $units);
            if ($entry['category'] === 'overtime') {
                $groups[$group]['overtime_duration_units'] = PayrollDuration::add($groups[$group]['overtime_duration_units'], $units);
            }
        }
        $segments = array_values($groups);
        usort($segments, static fn (array $a, array $b): int => $a['date_from'] <=> $b['date_from']);
        return $segments;
    }
}
