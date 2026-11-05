<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
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
        $monthlyParts = (new PayrollHourlyMonthlyParts($this->db))->forMany($supplierId, $periodStart, $employments, $hourlyTerms, $lock);
        $rounding = PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
        $calculator = new PayrollHourlyWageCalculator();
        $result = [];
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
            $total = null;
            if ($calculated->complete) {
                $total = 0;
                foreach ($calculated->claims as $claim) {
                    $amount = (int) $claim['payable_minor'];
                    if ($amount > PHP_INT_MAX - $total) throw new \OverflowException('Součet hodinové mzdy překročil celočíselný rozsah.');
                    $total += $amount;
                }
            }
            $result[$id] = [
                'settlement_only' => $context->settlementOnly,
                'complete' => $calculated->complete,
                'blockers' => $calculated->blockers,
                'rounding' => $rounding->value,
                'evidence_hash' => $calculated->evidenceHash,
                'claims' => $calculated->claims,
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
                ],
            ];
        }
        return $result;
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
