<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeBasis;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use MyInvoice\Service\Report\CzechWorkingDays;
use PDO;

/** Dávkové zmrazení skutečných podkladů; klient nikdy neurčuje zůstatky ani sazby. */
final class PayrollHourlyEntitlementContextLoader
{
    public const AUTOMATIC_RULES = 'hourly-automatic-zero.v1';

    public function __construct(private readonly Connection $db) {}

    /** @return array<int,array<string,mixed>> */
    public function forMany(int $supplierId, string $periodStart, array $employments, array $termsByEmployment, array $sourcesByEmployment, bool $lock = false): array
    {
        $ids = array_keys($termsByEmployment);
        if ($ids === []) return [];
        $end = self::end($periodStart);
        $policies = (new PayrollSurchargeRepository($this->db))->policiesForMany($supplierId, $ids, $periodStart, $end, $lock);
        $evidence = (new PayrollHourlyEvidenceRepository($this->db))->latestMany($supplierId, $ids, $periodStart);
        $from = substr($periodStart, 0, 4) . '-01-01';
        foreach ($policies as $rows) foreach ($rows as $row) foreach ($row['hourly_terms']['included_agreement_premiums'] ?? [] as $included) $from = min($from, substr($included['opening_as_of'], 0, 7) . '-01');
        $ledger = (new PayrollHourlyEntitlementLedger($this->db))->loadMany($supplierId, $ids, $from, $end, $periodStart, $lock);
        $data = $this->proofData($supplierId, $ids, $periodStart, $lock);
        $averages = $this->averages($supplierId, $ids, $periodStart, $lock);
        $hourlyIds = array_keys(array_filter($termsByEmployment, static fn(array $terms): bool => array_any($terms, static fn(array $term): bool => ($term['remuneration_type'] ?? null) === 'hourly')));
        $minimumInputs = (new PayrollHourlyMinimumInputs($this->db))->forMany($supplierId, $hourlyIds, $periodStart, $lock);
        $registry = new PayrollRulesetRegistry(new PayrollRulesetRepository($this->db));
        $provider = $registry->provider();
        try {
            $rules = PayrollSurchargeRuleset::forDate($provider, $periodStart);
            $floor = MinimumWageFloor::forDate($provider, $periodStart);
        } catch (\LogicException|\UnexpectedValueException $e) {
            $unavailable = [];
            foreach ($ids as $id) $unavailable[$id] = ['schema_version' => 'hourly-entitlements.unavailable', 'reason' => $e->getMessage(), 'work_segments' => PayrollHourlyWagePreview::segments($sourcesByEmployment[$id] ?? null, $termsByEmployment[$id])];
            return $unavailable;
        }
        $calendar = self::calendar($periodStart, $ledger);
        $employerPolicies = $this->employerPolicies($supplierId, $periodStart, $end, $lock);
        $currentFloors = [];
        $floorSegments = [];
        $employmentTypes = [];
        foreach ($employments as $employment) $employmentTypes[(int) $employment['employment_id']] = (string) $employment['relation_type'];
        foreach ($termsByEmployment as $id => $terms) {
            $floorSegments[$id] = PayrollHourlyAverageFloorResolver::segments($terms, $policies[$id] ?? [], $periodStart, $end, $employmentTypes[$id] ?? '', $provider);
            $current = array_values(array_filter($floorSegments[$id], static fn(array $part): bool => $part['date_from'] <= $end && $part['date_to'] >= $end));
            $currentFloors[$id] = count($current) === 1 ? $current[0]['minimum_hourly_minor'] : null;
            if (isset($averages[$id])) $averages[$id]['raw_hourly_minor'] = $averages[$id]['hourly_minor'];
        }
        $historical = $this->historicalOvertime($supplierId, $periodStart, $ledger, $provider, $currentFloors, $lock);
        $result = [];
        foreach ($ids as $id) {
            $source = $sourcesByEmployment[$id] ?? null;
            if ($source === null || ($source['work_source'] ?? null) === 'import_summary') $source = self::buildSettlementSource($id, $periodStart, $termsByEmployment[$id], $ledger[$id]) ?? $source;
            $saved = $evidence[$id] ?? null;
            $current = $saved !== null && $source !== null && hash_equals($saved['work_source_hash'], PayrollHourlyEvidenceRepository::bindingHash($source));
            try {
                $policy = self::policyVersions($policies[$id] ?? [], $employerPolicies, $rules, $ledger[$id]['included_consumption'] ?? [], $periodStart, $end);
            } catch (\LogicException|\UnexpectedValueException) {
                $policy = [];
            }
            $intervals = array_map(static fn(array $row): array => ['id' => $row['source'] . ':' . ($row['id'] ?? '') . ':' . $row['date_from'], 'valid_from' => $row['date_from'], 'valid_to' => $row['date_to']], $policy);
            $segments = PayrollHourlyWagePreview::segments($source, $termsByEmployment[$id], $intervals);
            $context = [
                'schema_version' => 'hourly-entitlements.v1', 'as_of' => $end,
                'ruleset' => ['id' => $rules->version->id, 'content_sha256' => $rules->version->contentHash,
                    'holiday_time_off_months' => $rules->compensatoryTimeOffMonths(PayrollSurchargeKind::Holiday),
                    'overtime_time_off_months' => $rules->compensatoryTimeOffMonths(PayrollSurchargeKind::Overtime)],
                'holiday_calendar' => $calendar, 'average_earning' => $averages[$id] ?? null,
                'minimum_hourly_minor' => $floor->baseHourlyMinor, 'minimum_ruleset_id' => $floor->rulesetId,
                'historical_overtime' => $historical[$id] ?? [], 'current_minimum_hourly_minor' => $currentFloors[$id],
                'minimum_floor_segments' => $floorSegments[$id],
                ...(array_key_exists($id, $minimumInputs) ? ['minimum_input_evidence' => $minimumInputs[$id]] : []),
                'policy_versions' => $policy, 'work_segments' => $segments,
                'policy_source' => $policies[$id] ?? [], 'employer_policy_source' => $employerPolicies,
                'evidence_source' => $saved, 'ledger_source' => $ledger[$id],
                'settlement_source' => ($source['work_source'] ?? null) === 'settlement_only' ? $source : null,
                'ledger_source_sha256' => self::hash($ledger[$id]),
                'schedule_source' => $data[$id]['shifts'], 'absence_source' => $data[$id]['absences'],
                'premium_segments' => $current ? $saved['premium_segments'] : null,
                'nonworked_holidays' => $current ? [] : null, 'holiday_time_off_grants' => $current ? [] : null,
                'overtime_time_off_allocations' => $current ? [] : null,
            ];
            if (!$current) {
                // Kde nárok objektivně vzniknout nemůže, posoudí server výslovnou nulu sám.
                $automatic = self::automaticZeros($termsByEmployment[$id], $employmentTypes[$id] ?? '', $periodStart, $end, $calendar, $data[$id], $ledger[$id]);
                foreach ($automatic as $key => $_) $context[$key] = [];
                if ($automatic !== []) $context['automatic_assessment'] = ['rule_version' => self::AUTOMATIC_RULES, 'rules' => $automatic];
            }
            if (($source['work_source'] ?? null) === 'entries') {
                $context['premium_segments'] = PayrollHourlyNativePremiums::project($source['time_entries'], $segments, $policy, $rules, $periodStart, $end, $calendar);
                // Docházka doloží příplatky, další nároky vyžadují výslovné potvrzení účetní.
            }
            if ($current) {
                try {
                    $context = [...$context, ...self::selectionProofs($saved, $source, $data[$id], $ledger[$id], $calendar, $termsByEmployment[$id], $averages[$id] ?? null)];
                } catch (\InvalidArgumentException $e) {
                    $context['selection_error'] = $e->getMessage();
                    $context['nonworked_holidays'] = $context['holiday_time_off_grants'] = $context['overtime_time_off_allocations'] = null;
                }
            }
            if ($registry->degradedReason() !== null || $rules->version->effectiveTo < $end) $context['ruleset'] = null;
            $result[$id] = $context;
        }
        return $result;
    }

    /** Jediný binding pro historické vypořádání bez vymyšleného zdroje hodin. */
    public function sourceForEvidence(int $supplierId, int $employmentId, string $periodStart, ?array $actualSource): ?array
    {
        if ($actualSource !== null && ($actualSource['work_source'] ?? null) !== 'import_summary') return $actualSource;
        $state = (new PayrollHourlyEntitlementLedger($this->db))->loadMany($supplierId, [$employmentId], substr($periodStart, 0, 4) . '-01-01', self::end($periodStart), $periodStart)[$employmentId];
        return self::buildSettlementSource($employmentId, $periodStart, $this->terms($supplierId, $employmentId, $periodStart), $state) ?? $actualSource;
    }

    /** Neobsahuje worked units: dostupný nárok není důkaz odpracované nuly. */
    public static function buildSettlementSource(int $employmentId, string $periodStart, array $terms, array $ledger): ?array
    {
        foreach ($terms as $term) if (($term['remuneration_type'] ?? null) === 'hourly' && $term['effective_from'] <= self::end($periodStart) && ($term['effective_to'] === null || $term['effective_to'] >= $periodStart)) return null;
        if (array_filter($ledger['entitlements'] ?? [], static fn(array $row): bool => $row['available_duration_units'] > 0) === []) return null;
        $source = ['schema_version' => 'hourly-settlement-source.v1', 'work_source' => 'settlement_only',
            'employment_id' => $employmentId, 'period_start' => $periodStart,
            'ledger_source_sha256' => self::hash($ledger['entitlements'])];
        $source['source_sha256'] = self::hash($source);
        return $source;
    }

    /**
     * Části evidence, u nichž nárok v měsíci objektivně vzniknout nemůže.
     * Náhrada za neodpracovaný svátek (§ 115 odst. 3 ZP) dohodám nepřísluší;
     * jinak se nula posoudí, jen když žádný svátek nepadá na rozvržený den.
     *
     * @param list<array<string,mixed>> $terms
     * @param array{shifts:list<array<string,mixed>>,absences:list<array<string,mixed>>} $data
     * @param array{entitlements:list<array<string,mixed>>} $ledger
     * @return array<string,string> klíč evidence => použité pravidlo
     */
    public static function automaticZeros(array $terms, string $employmentType, string $periodStart, string $end, ?array $calendar, array $data, array $ledger): array
    {
        $result = [];
        if (in_array($employmentType, ['dpp', 'dpc'], true)) {
            $result['nonworked_holidays'] = 'agreement_not_entitled';
        } elseif ($calendar !== null) {
            $holidays = array_values(array_filter($calendar['dates'] ?? [], static fn(string $date): bool => $date >= $periodStart && $date <= $end && self::hourlyOn($terms, $date)));
            if ($holidays === []) {
                $result['nonworked_holidays'] = 'no_holiday_on_hourly_day';
            } elseif ($data['shifts'] !== []) {
                $scheduled = false;
                foreach ($data['shifts'] as $shift) {
                    [$start, $shiftEnd] = PayrollHourlyScheduleProof::bounds($shift);
                    foreach ($holidays as $date) if ($start->format('Y-m-d') <= $date && $shiftEnd->modify('-1 second')->format('Y-m-d') >= $date) $scheduled = true;
                }
                if (!$scheduled) $result['nonworked_holidays'] = 'no_shift_on_holiday';
            }
        }
        $available = static fn(string $kind): bool => array_filter($ledger['entitlements'] ?? [], static fn(array $row): bool => $row['kind'] === $kind && $row['available_duration_units'] > 0) !== [];
        $absent = static fn(string $type): bool => array_filter($data['absences'], static fn(array $row): bool => $row['absence_type'] === $type) !== [];
        if (!$available('holiday') && !$absent('holiday_compensatory_time_off')) $result['holiday_time_off_grants'] = 'no_holiday_time_off_entitlement';
        if (!$available('overtime') && !$absent('compensatory_time_off')) $result['overtime_time_off_allocations'] = 'no_overtime_time_off_entitlement';
        return $result;
    }

    /** Výběry nevyžadují průměr; jejich platnost je samostatná od ocenění náhrady. */
    public function validateSelections(int $supplierId, int $employmentId, string $periodStart, array $payload, array $source): void
    {
        if ($payload['nonworked_holidays'] !== [] && in_array($this->relationType($supplierId, $employmentId), ['dpp', 'dpc'], true)) {
            throw new \InvalidArgumentException('Náhrada mzdy za neodpracovaný svátek se u dohod o pracích konaných mimo pracovní poměr neposkytuje.');
        }
        if ($payload['nonworked_holidays'] === [] && $payload['holiday_time_off_grants'] === [] && $payload['overtime_time_off_allocations'] === []) return;
        $ledger = (new PayrollHourlyEntitlementLedger($this->db))->loadMany($supplierId, [$employmentId], substr($periodStart, 0, 4) . '-01-01', self::end($periodStart), $periodStart, true);
        $data = $this->proofData($supplierId, [$employmentId], $periodStart, true);
        self::selectionProofs($payload, $source, $data[$employmentId], $ledger[$employmentId], self::calendar($periodStart, $ledger), $this->terms($supplierId, $employmentId, $periodStart), null);
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function evidenceOptions(int $supplierId, int $employmentId, string $periodStart): array
    {
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($supplierId, [$employmentId], $periodStart)[$employmentId] ?? null;
        $ledger = (new PayrollHourlyEntitlementLedger($this->db))->loadMany($supplierId, [$employmentId], substr($periodStart, 0, 4) . '-01-01', self::end($periodStart), $periodStart);
        $data = $this->proofData($supplierId, [$employmentId], $periodStart)[$employmentId];
        $calendar = self::calendar($periodStart, $ledger);
        $terms = $this->terms($supplierId, $employmentId, $periodStart);
        $agreement = in_array($this->relationType($supplierId, $employmentId), ['dpp', 'dpc'], true);
        $options = ['nonworked_holidays' => [], 'holiday_time_off_entitlements' => [], 'overtime_time_off_entitlements' => [], 'time_off_absences' => [],
            'automatic_assessment' => self::automaticZeros($terms, $agreement ? 'dpp' : 'employment', $periodStart, self::end($periodStart), $calendar, $data, $ledger[$employmentId])];
        foreach ($agreement ? [] : $calendar['dates'] ?? [] as $date) {
            if ($date < $periodStart || $date > self::end($periodStart) || !self::hourlyOn($terms, $date)) continue;
            try {
                $proof = PayrollHourlyScheduleProof::forDate($date, $data['shifts'], $data['absences'], $source);
                if ($proof['available_duration_units'] > 0) $options['nonworked_holidays'][] = ['date' => $date, 'available_duration_units' => $proof['available_duration_units']];
            } catch (\InvalidArgumentException) { /* Nedoložený den není nabídka k automatické náhradě. */ }
        }
        foreach ($ledger[$employmentId]['entitlements'] as $row) {
            if ($row['kind'] === 'holiday') $options['holiday_time_off_entitlements'][] = ['entitlement_id' => $row['id'], 'entitlement_revision' => $row['revision'], 'work_date' => $row['work_date'], 'due_on' => $row['due_on'], 'available_duration_units' => $row['available_duration_units']];
            else $options['overtime_time_off_entitlements'][] = ['work_date' => $row['work_date'], 'due_on' => $row['due_on'], 'available_duration_units' => $row['available_duration_units']];
        }
        foreach ($data['absences'] as $row) {
            if ($row['status'] !== 'approved' || !in_array($row['absence_type'], ['holiday_compensatory_time_off', 'compensatory_time_off'], true)) continue;
            for ($date = max($periodStart, $row['date_from']); $date <= min(self::end($periodStart), $row['date_to']); $date = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')) {
                try {
                    $proof = PayrollHourlyScheduleProof::forDate($date, $data['shifts'], $data['absences'], $source, (int) $row['id']);
                    if ($proof['available_duration_units'] > 0) $options['time_off_absences'][] = ['absence_id' => (int) $row['id'], 'date' => $date, 'available_duration_units' => $proof['available_duration_units'], 'kind' => $row['absence_type'] === 'compensatory_time_off' ? 'overtime' : 'holiday'];
                } catch (\InvalidArgumentException) { /* Chybějící důkaz se nesmí změnit na nabídku hodin. */ }
            }
        }
        $saved = (new PayrollHourlyEvidenceRepository($this->db))->latestMany($supplierId, [$employmentId], $periodStart)[$employmentId] ?? null;
        if ($source !== null && ($saved === null || hash_equals($saved['work_source_hash'], PayrollHourlyEvidenceRepository::bindingHash($source)))) {
            for ($date = $periodStart; $date <= self::end($periodStart); $date = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')) {
                $units = self::currentOvertimeOn($source, $date, $saved['premium_segments'] ?? []);
                if ($units !== null && $units > 0 && !in_array($date, array_column($options['overtime_time_off_entitlements'], 'work_date'), true)) $options['overtime_time_off_entitlements'][] = ['work_date' => $date, 'available_duration_units' => $units];
            }
        }
        return self::preserveSavedOptions($options, $saved);
    }

    /** Serverový důkaz sdílený zápisem evidence a zmrazením běhu. */
    public static function selectionProofs(array $payload, array $source, array $data, array $ledger, ?array $calendar, array $terms, ?array $average): array
    {
        $result = ['nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
        $usedDay = $usedEntitlement = [];
        $entitlements = array_column($ledger['entitlements'], null, 'id');
        $absences = array_column($data['absences'], null, 'id');
        foreach ($result as $key => $_) foreach ($payload[$key] as $row) {
            $date = $row['date'] ?? $row['granted_on'];
            $absenceId = $row['absence_id'] ?? null;
            if ($absenceId !== null) {
                $absence = $absences[$absenceId] ?? null;
                $kind = $key === 'holiday_time_off_grants' ? 'holiday_compensatory_time_off' : 'compensatory_time_off';
                if ($absence === null || $absence['absence_type'] !== $kind || ($kind === 'holiday_compensatory_time_off' && $absence['compensation_policy'] !== 'hourly_claim')) throw new \InvalidArgumentException('Vybraná nepřítomnost nedokládá příslušný druh náhradního volna.');
            }
            $proof = PayrollHourlyScheduleProof::forDate($date, $data['shifts'], $data['absences'], $source, $absenceId);
            $usedDay[$date] = PayrollDuration::add($usedDay[$date] ?? 0, $row['duration_units']);
            if ($usedDay[$date] > $proof['available_duration_units']) throw new \InvalidArgumentException('Čerpání překračuje rozvrh po odečtení práce a jiných nepřítomností.');
            if ($key === 'nonworked_holidays') {
                if (!in_array($date, $calendar['dates'] ?? [], true) || !self::hourlyOn($terms, $date)) throw new \InvalidArgumentException('Náhradu neodpracovaného svátku lze doložit jen ve svátek s hodinovou mzdou.');
                $result[$key][] = [...$row, ...$proof, 'reason' => 'holiday'];
                continue;
            }
            if ($key === 'holiday_time_off_grants') {
                $entitlement = $entitlements[$row['entitlement_id']] ?? null;
                if ($entitlement === null || $entitlement['kind'] !== 'holiday' || $entitlement['revision'] !== $row['entitlement_revision']) throw new \InvalidArgumentException('Nárok na sváteční volno se změnil nebo není dostupný. Obnovte podklady.');
                if (!in_array($entitlement['work_date'], $calendar['dates'] ?? [], true) || $date < $entitlement['work_date']) throw new \InvalidArgumentException('Den čerpání nebo doloženého svátečního nároku není platný.');
                $id = $entitlement['id'];
                $usedEntitlement[$id] = PayrollDuration::add($usedEntitlement[$id] ?? 0, $row['duration_units']);
                if ($usedEntitlement[$id] > $entitlement['available_duration_units']) throw new \InvalidArgumentException('Čerpání překračuje zbývající nárok na placené sváteční volno.');
                $result[$key][] = [...$row, ...$proof, 'work_date' => $entitlement['work_date'], 'available_duration_units' => $entitlement['available_duration_units'], 'average_snapshot_id' => $average['id'] ?? null, 'average_hourly_minor' => $average['hourly_minor'] ?? null];
            } else {
                $matches = array_values(array_filter($ledger['entitlements'], static fn(array $item): bool => $item['kind'] === 'overtime' && $item['work_date'] === $row['work_date']));
                if (isset($matches[0]['due_on']) && $date > $matches[0]['due_on']) throw new \InvalidArgumentException('Čerpání přesčasového volna je po doložené lhůtě; nárok se vypořádá příplatkem.');
                $available = $matches[0]['available_duration_units'] ?? self::currentOvertimeOn($source, $row['work_date'], $payload['premium_segments'] ?? []);
                $id = 'overtime:' . $row['work_date'];
                $usedEntitlement[$id] = PayrollDuration::add($usedEntitlement[$id] ?? 0, $row['duration_units']);
                if ($row['work_date'] > $date || $available === null || $usedEntitlement[$id] > $available) throw new \InvalidArgumentException('Čerpání přesčasového volna překračuje doložený dostupný přesčas.');
                $result[$key][] = [...$row, ...$proof, 'available_duration_units' => $available];
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function policyVersions(array $rows, array $employerRows, PayrollSurchargeRuleset $rules, array $consumption, string $from, string $end): array
    {
        $boundaries = [$from, (new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d')];
        foreach ([...$rows, ...$employerRows] as $row) {
            if ($row['valid_from'] > $from && $row['valid_from'] <= $end) $boundaries[] = $row['valid_from'];
            if ($row['valid_to'] !== null && $row['valid_to'] >= $from && $row['valid_to'] < $end) $boundaries[] = (new \DateTimeImmutable($row['valid_to']))->modify('+1 day')->format('Y-m-d');
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);
        $result = [];
        for ($i = 0; $i < count($boundaries) - 1; ++$i) {
            $start = $boundaries[$i];
            $through = (new \DateTimeImmutable($boundaries[$i + 1]))->modify('-1 day')->format('Y-m-d');
            $effective = array_values(array_filter($rows, static fn(array $row): bool => $row['valid_from'] <= $start && ($row['valid_to'] === null || $row['valid_to'] >= $through)));
            if (count($effective) > 1) continue;
            $row = $effective[0] ?? null;
            $employers = array_values(array_filter($employerRows, static fn(array $row): bool => $row['valid_from'] <= $start && ($row['valid_to'] === null || $row['valid_to'] >= $through)));
            if (count($employers) > 1) continue;
            $employer = $employers[0] ?? null;
            $reference = $row['agreement_reference'] ?? null;
            $base = $row ?? $employer;
            $policy = ['id' => $base === null ? null : (int) $base['id'], 'revision_no' => $base === null ? null : (int) $base['row_version'],
                'source' => $row !== null ? 'employment' : ($employer !== null ? 'employer' : 'statutory'),
                'date_from' => $start, 'date_to' => $through, 'document_reference' => $reference,
                'overtime_mode' => match ($row['overtime_mode'] ?? 'surcharge') { 'included_in_wage' => 'included', 'compensatory_time_off' => 'time_off', default => 'surcharge' },
                'holiday_mode' => ($row['holiday_mode'] ?? 'compensatory_time_off') === 'surcharge' ? 'surcharge' : 'time_off',
                'holiday_surcharge_agreement_reference' => $reference,
                'difficult_environment_factors' => isset($row['difficult_environment_factors']) ? (int) $row['difficult_environment_factors'] : null,
                'included_overtime' => $row['hourly_terms']['included_overtime'] ?? null,
                'included_agreement_premiums' => $row['hourly_terms']['included_agreement_premiums'] ?? [], 'rates' => []];
            foreach (PayrollSurchargeKind::all() as $kind) {
                $rate = $rules->statutoryRate($kind);
                $numerator = $rate->numerator * 10000;
                if ($numerator % $rate->denominator !== 0) throw new \UnexpectedValueException('Zákonná sazba nemá přesné vyjádření v bazických bodech.');
                $rateSource = ($row[$kind->value . '_rate_bp'] ?? null) !== null || ($row[$kind->value . '_fixed_hourly_minor'] ?? null) !== null ? $row : $employer;
                $policy['rates'][$kind->value] = ['statutory_basis' => $rules->basis($kind) === PayrollSurchargeBasis::AverageEarning ? 'average' : 'minimum',
                    'statutory_rate_bp' => intdiv($numerator, $rate->denominator),
                    'agreed_rate_bp' => isset($rateSource[$kind->value . '_rate_bp']) ? (int) $rateSource[$kind->value . '_rate_bp'] : null,
                    'agreed_fixed_hourly_minor' => isset($rateSource[$kind->value . '_fixed_hourly_minor']) ? (int) $rateSource[$kind->value . '_fixed_hourly_minor'] : null,
                    'agreement_reference' => $rateSource === $row ? $reference : ($employer === null ? null : 'Firemní mzdová politika ' . $employer['id'])];
            }
            if ($policy['included_overtime'] !== null) {
                $included = $policy['included_overtime'];
                $policy['included_overtime'] = [...$included, ...self::prior($consumption, 'included-overtime:' . $included['calendar_year'], $included['opening_as_of'])];
            }
            foreach ($policy['included_agreement_premiums'] as &$included) $included = [...$included, ...self::prior($consumption, self::agreementScope($included), $included['opening_as_of'])];
            unset($included);
            $policy['content_sha256'] = self::hash(['mapped' => $policy, 'employment' => $row, 'employer' => $employer]);
            $result[] = $policy;
        }
        return $result;
    }

    public static function agreementScope(array $row): string
    {
        return \MyInvoice\Service\Payroll\Hourly\PayrollHourlyEntitlementCalculator::agreementScope($row);
    }

    private static function prior(array $rows, string $scope, string $opening): array
    {
        // Počáteční stav je doložen před první prací účinného sjednání;
        // účetní měsíc, v němž leží, se nezapočítá podruhé jako starší čerpání.
        $matching = array_values(array_filter($rows, static fn(array $row): bool => $row['scope_key'] === $scope && $row['accounting_period_start'] >= substr($opening, 0, 7) . '-01'));
        return ['prior_consumed_duration_units' => array_sum(array_column($matching, 'duration_units')), 'prior_claims_hash' => self::hash($matching)];
    }

    /** Starší nárok se oceňuje průměrem čtvrtletí práce, s aktuální podlahou § 357. */
    private function historicalOvertime(int $supplierId, string $period, array $ledger, \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider $provider, array $currentFloors, bool $lock): array
    {
        $pending = [];
        $quarters = [];
        $from = $period;
        foreach ($ledger as $id => $state) foreach ($state['entitlements'] as $row) {
            if ($row['kind'] !== 'overtime' || $row['work_date'] >= $period || $row['available_duration_units'] <= 0) continue;
            $pending[$id][] = $row;
            $from = min($from, $row['work_date']);
            $year = (int) substr($row['work_date'], 0, 4);
            $quarter = intdiv((int) substr($row['work_date'], 5, 2) - 1, 3) + 1;
            $quarters[$year . ':' . $quarter] = [$year, $quarter];
        }
        if ($pending === []) return [];
        $ids = array_keys($pending);
        $policies = (new PayrollSurchargeRepository($this->db))->policiesForMany($supplierId, $ids, $from, $period, $lock);
        $employers = $this->employerPolicies($supplierId, $from, $period, $lock);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pairs = implode(',', array_fill(0, count($quarters), '(?, ?)'));
        $stmt = $this->db->pdo()->prepare("SELECT id, employment_id, applicable_year, applicable_quarter, revision_no, average_hourly_minor
            FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id IN ($in)
              AND (applicable_year, applicable_quarter) IN ($pairs) AND status = 'approved'
            ORDER BY employment_id, applicable_year, applicable_quarter, revision_no DESC" . ($lock ? ' FOR UPDATE' : ''));
        $args = [$supplierId, ...$ids];
        foreach ($quarters as $pair) array_push($args, ...$pair);
        $stmt->execute($args);
        $averages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $averages[(int) $row['employment_id']][$row['applicable_year'] . ':' . $row['applicable_quarter']] ??= [
            'id' => (int) $row['id'], 'revision_no' => (int) $row['revision_no'], 'hourly_minor' => (int) $row['average_hourly_minor']];
        $result = [];
        foreach ($pending as $id => $rows) foreach ($rows as $row) {
            $date = $row['work_date'];
            $key = substr($date, 0, 4) . ':' . (intdiv((int) substr($date, 5, 2) - 1, 3) + 1);
            $item = [...$row, 'average_earning' => $averages[$id][$key] ?? null, 'current_minimum_hourly_minor' => $currentFloors[$id] ?? null,
                'average_basis' => 'work_quarter', 'policy' => null, 'ruleset' => null];
            try {
                $rules = PayrollSurchargeRuleset::forDate($provider, $date);
                $item['policy'] = self::policyVersions($policies[$id] ?? [], $employers, $rules, [], $date, $date)[0] ?? null;
                $item['ruleset'] = ['id' => $rules->version->id, 'content_sha256' => $rules->version->contentHash];
            } catch (\LogicException|\UnexpectedValueException $e) {
                $item['reason'] = $e->getMessage();
            }
            $result[$id][] = $item;
        }
        return $result;
    }

    /** Zpětně kompatibilní veřejný převod používaný staršími volajícími. */
    public static function statedWeeklyMinutes(array $terms): ?int
    {
        return PayrollHourlyAverageFloorResolver::statedWeeklyMinutes($terms);
    }

    private function proofData(int $supplierId, array $ids, string $periodStart, bool $lock = false): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $result = array_fill_keys($ids, ['shifts' => [], 'absences' => []]);
        $from = (new \DateTimeImmutable($periodStart))->modify('-2 days')->format('Y-m-d H:i:s');
        $until = (new \DateTimeImmutable($periodStart))->modify('first day of next month')->modify('+2 days')->format('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare("SELECT * FROM payroll_shifts FORCE INDEX (idx_payroll_shift_month) WHERE supplier_id = ? AND employment_id IN ($in) AND starts_at_utc >= ? AND starts_at_utc < ? AND status <> 'superseded' ORDER BY employment_id, starts_at_utc, id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids, $from, $until]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['employment_id']]['shifts'][] = $row;
        $stmt = $this->db->pdo()->prepare("SELECT * FROM payroll_absences FORCE INDEX (idx_payroll_absence_period) WHERE supplier_id = ? AND employment_id IN ($in) AND date_from <= ? AND date_to >= ? AND status = 'approved' ORDER BY employment_id, date_from, id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids, self::end($periodStart), $periodStart]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['employment_id']]['absences'][] = $row;
        return $result;
    }

    private function averages(int $supplierId, array $ids, string $periodStart, bool $lock): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Stejné pořadí revizí jako PayrollAverageEarningRepository::findApproved.
        $stmt = $this->db->pdo()->prepare("SELECT id, employment_id, revision_no, average_hourly_minor FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id IN ($in) AND applicable_year = ? AND applicable_quarter = ? AND status = 'approved' ORDER BY employment_id, revision_no DESC" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...$ids, (int) substr($periodStart, 0, 4), intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['employment_id']] ??= ['id' => (int) $row['id'], 'revision_no' => (int) $row['revision_no'], 'hourly_minor' => (int) $row['average_hourly_minor']];
        return $result;
    }

    private function employerPolicies(int $supplierId, string $from, string $through, bool $lock): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payroll_employer_policies WHERE supplier_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from, id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $through, $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function relationType(int $supplierId, int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT relation_type FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return (string) $stmt->fetchColumn();
    }

    private function terms(int $supplierId, int $id, string $period): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from, id');
        $stmt->execute([$supplierId, $id, self::end($period), $period]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function calendar(string $period, array $ledger): ?array
    {
        $years = [(int) substr($period, 0, 4) => true];
        foreach ($ledger as $row) foreach ($row['entitlements'] as $entitlement) $years[(int) substr($entitlement['work_date'], 0, 4)] = true;
        $dates = [];
        foreach (array_keys($years) as $year) {
            array_push($dates, ...array_keys((new CzechHolidayCalendar())->forYear($year)));
            if (CzechWorkingDays::usingFallback()) return null;
        }
        sort($dates);
        return ['dates' => $dates, 'content_sha256' => self::hash($dates)];
    }

    private static function hourlyOn(array $terms, string $date): bool
    {
        foreach ($terms as $row) if ($row['effective_from'] <= $date && ($row['effective_to'] === null || $row['effective_to'] >= $date)) return $row['remuneration_type'] === 'hourly';
        return false;
    }

    private static function currentOvertimeOn(array $source, string $date, array $premiums = []): ?int
    {
        if (($source['work_source'] ?? null) === 'entries') {
            $units = 0;
            foreach ($source['time_entries'] as $row) {
                if ($row['category'] !== 'overtime') continue;
                [$start, , $minutes] = PayrollHourlyScheduleProof::bounds($row);
                if ($start->format('Y-m-d') === $date) $units = PayrollDuration::add($units, PayrollDuration::fromMinutes($minutes));
            }
            return $units;
        }
        foreach ($source['manual_summary']['segments'] ?? [] as $row) if ($row['date_from'] === $date && $row['date_to'] === $date) return $row['overtime_duration_units'];
        foreach ($premiums as $row) foreach ($row['overtime_work_days'] ?? [] as $day) if ($day['date'] === $date) return $day['duration_units'];
        return null;
    }

    /** Uložené neplatné volby se uživateli nesmějí při otevření editoru ztratit. */
    private static function preserveSavedOptions(array $options, ?array $saved): array
    {
        foreach ($saved['nonworked_holidays'] ?? [] as $row) {
            if (!in_array($row['date'], array_column($options['nonworked_holidays'], 'date'), true)) $options['nonworked_holidays'][] = ['date' => $row['date'], 'available_duration_units' => 0, 'valid' => false, 'reason' => 'Chybí platný rozvrh nebo důkaz neodpracovaného svátku.'];
        }
        foreach ($saved['holiday_time_off_grants'] ?? [] as $row) {
            if (!in_array($row['entitlement_id'], array_column($options['holiday_time_off_entitlements'], 'entitlement_id'), true)) $options['holiday_time_off_entitlements'][] = ['entitlement_id' => $row['entitlement_id'], 'entitlement_revision' => $row['entitlement_revision'], 'work_date' => null, 'due_on' => null, 'available_duration_units' => 0, 'valid' => false, 'reason' => 'Nárok není dostupný v platné schválené evidenci.'];
        }
        foreach ($saved['overtime_time_off_allocations'] ?? [] as $row) {
            if (!in_array($row['work_date'], array_column($options['overtime_time_off_entitlements'], 'work_date'), true)) $options['overtime_time_off_entitlements'][] = ['work_date' => $row['work_date'], 'available_duration_units' => 0, 'valid' => false, 'reason' => 'Přesčas není doložen v platném zdroji hodin nebo v nároku.'];
        }
        foreach (['holiday_time_off_grants' => 'holiday', 'overtime_time_off_allocations' => 'overtime'] as $key => $kind) foreach ($saved[$key] ?? [] as $row) {
            $matches = array_filter($options['time_off_absences'], static fn(array $item): bool => $item['absence_id'] === $row['absence_id'] && $item['date'] === $row['granted_on']);
            if ($matches === []) $options['time_off_absences'][] = ['absence_id' => $row['absence_id'], 'date' => $row['granted_on'], 'kind' => $kind, 'available_duration_units' => 0, 'valid' => false, 'reason' => 'Nepřítomnost nebo rozvrh již nejsou platné.'];
        }
        return $options;
    }

    private static function hash(array $value): string { return hash('sha256', CanonicalJson::encode($value)); }
    private static function end(string $period): string { return (new \DateTimeImmutable($period))->modify('last day of this month')->format('Y-m-d'); }
}
