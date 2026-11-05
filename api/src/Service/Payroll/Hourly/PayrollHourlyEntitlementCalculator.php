<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;

/** Nároky nad doloženým časem; kalendář ověřuje jen datum svátku, nikdy počet hodin. */
final class PayrollHourlyEntitlementCalculator
{
    private PayrollHourlyWageContext $context;
    private array $evidence = [];
    private array $claims = [];
    private array $amounts = [];
    private array $blocked = [];
    private array $blockers = [];
    private array $entitlements = [];
    private array $included = [];
    private array $consumed = [];
    private array $history = [];
    private array $scheduleUsed = [];

    /** @param list<array<string,mixed>> $segments @param list<array<string,mixed>> $baseClaims */
    public function calculate(PayrollHourlyWageContext $context, array $segments, array $baseClaims, string $hash): PayrollHourlyWageResult
    {
        // Instance neuchovává stav mezi dvěma výpočty.
        $this->context = $context;
        $this->claims = $this->amounts = $this->blocked = $this->blockers = $this->entitlements = $this->included = $this->consumed = $this->history = $this->scheduleUsed = [];
        foreach ($baseClaims as $claim) $this->claims[$claim['purpose']] = $claim;
        foreach (PayrollSurchargeKind::all() as $kind) {
            if ($kind === PayrollSurchargeKind::Overtime && $this->isAgreement()) continue;
            $this->initialize('premium_' . $kind->value, $kind->componentCode());
        }
        $this->initialize('holiday_nonworked_compensation', 'NAHRADA_MZDY_SVATEK');
        $this->initialize('holiday_time_off_compensation', 'NAHRADA_MZDY_VOLNO_ZA_SVATEK');
        if ($context->entitlementContext === null || ($context->entitlementContext['schema_version'] ?? null) !== 'hourly-entitlements.v1') {
            $this->fail('entitlements', 'hourly_premium_evidence_missing', 'Doložte příplatkové hodiny a způsob vypořádání dalších nároků, včetně výslovných nul.');
            foreach ($this->amounts as $purpose => $_) $this->blocked[$purpose] = true;
            if (($this->claims['achieved_overtime']['worked_duration_units'] ?? 0) > 0) $this->blocked['achieved_overtime'] = true;
            return $this->finish($hash);
        }
        $this->evidence = $context->entitlementContext;
        $this->guard('entitlements', function (): void {
            self::date($this->evidence['as_of'] ?? null);
            foreach ($this->evidence['policy_versions'] ?? [] as $policy) {
                foreach ($policy['included_agreement_premiums'] ?? [] as $included) {
                    if (!$this->isAgreement() || !in_array($included['kind'] ?? null, ['night', 'weekend', 'difficult_environment'], true)) {
                        throw new PayrollHourlyCalculationException('hourly_included_premium_kind_invalid', 'Do odměny DPP nebo DPČ lze zahrnout jen doložený příplatek za noc, víkend nebo ztížené prostředí.');
                    }
                }
            }
        });
        $originalOvertime = $this->claims['achieved_overtime'] ?? null;
        if ($originalOvertime !== null) {
            $this->amounts['achieved_overtime'] = new PayrollExactFraction(0);
            $this->claims['achieved_overtime']['duration_units'] = 0;
            $this->claims['achieved_overtime']['segments'] = [];
        }
        foreach ($segments as $segment) {
            $extra = $segment['overtime_duration_units'];
            if ($extra > 0 && $originalOvertime !== null) {
                $this->guard('achieved_overtime', function () use ($segment, $extra, $originalOvertime): void {
                    $policy = $this->policy($segment);
                    $mode = $policy['overtime_mode'] ?? null;
                    if (!in_array($mode, ['surcharge', 'time_off', 'included'], true)) throw new PayrollHourlyCalculationException('hourly_overtime_policy_missing', 'Doložte způsob vypořádání přesčasu.');
                    $covered = $mode === 'included' ? $this->includedOvertime($segment, $policy, $extra) : 0;
                    $trace = array_values(array_filter($originalOvertime['segments'], static fn(array $row): bool => $row['term_id'] === $segment['term']['id'] && $row['date_from'] === $segment['date_from'] && $row['date_to'] === $segment['date_to']))[0];
                    $amount = PayrollExactFraction::product([$trace['raw_numerator_minor'], $extra - $covered], [$trace['raw_denominator'], $extra]);
                    $this->add('achieved_overtime', $amount, $extra - $covered, [...$trace, 'included_duration_units' => $covered, 'paid_duration_units' => $extra - $covered]);
                    if ($covered > 0) {
                        $includedAmount = PayrollExactFraction::product([$trace['raw_numerator_minor'], $covered], [$trace['raw_denominator'], $extra]);
                        $this->included[] = [...$this->allocation('included_overtime', 'achieved_overtime', $segment, $covered, $includedAmount),
                            'scope_key' => 'included-overtime:' . substr($segment['date_from'], 0, 4)];
                    }
                    $this->guard('premium_overtime', function () use ($segment, $policy, $extra, $covered, $mode): void {
                        if ($mode === 'time_off') {
                            $this->overtimeTimeOff($segment, $policy, $extra);
                        } elseif ($extra > $covered) {
                            $amount = $this->premiumAmount(PayrollSurchargeKind::Overtime, $policy, $extra - $covered);
                            $this->add('premium_overtime', $amount, $extra - $covered, $this->segmentTrace($segment, $policy));
                        }
                    });
                });
                if (isset($this->blocked['achieved_overtime'])) $this->blocked['premium_overtime'] = true;
            }
            foreach ([PayrollSurchargeKind::Night, PayrollSurchargeKind::Weekend, PayrollSurchargeKind::DifficultEnvironment, PayrollSurchargeKind::Holiday] as $kind) {
                $purpose = 'premium_' . $kind->value;
                $this->guard($purpose, function () use ($segment, $kind, $purpose): void {
                    $row = $this->premiumSegment($segment);
                    if ($kind === PayrollSurchargeKind::DifficultEnvironment) {
                        $exposures = $row['difficult_exposures'] ?? null;
                        if (!is_array($exposures)) throw new PayrollHourlyCalculationException('hourly_premium_evidence_missing', 'Doložte dobu a počet vlivů ztíženého prostředí, nebo potvrďte, že se nevyskytly.');
                    } else {
                        $key = $kind === PayrollSurchargeKind::Holiday ? 'holiday_work_duration_units' : $kind->value . '_duration_units';
                        $exposures = [['duration_units' => self::units($row[$key] ?? null), 'factors' => 1]];
                    }
                    $sum = 0;
                    foreach ($exposures as $exposure) {
                        $units = self::units($exposure['duration_units'] ?? null);
                        $factors = self::positive($exposure['factors'] ?? null);
                        $sum = PayrollDuration::add($sum, $units);
                        if ($sum > $segment['worked_duration_units']) throw new PayrollHourlyCalculationException('hourly_premium_hours_exceed_work', 'Příplatková vrstva přesahuje doloženou odpracovanou dobu.');
                        if ($units === 0) continue;
                        $policy = $this->policy($segment, $row['policy_version_id'] ?? null);
                        if ($kind === PayrollSurchargeKind::Holiday) {
                            $this->holidayWork($segment, $row, $policy, $units);
                            continue;
                        }
                        $amount = $this->premiumAmount($kind, $policy, $units, $factors);
                        $deduction = $this->includedAgreementPremium($segment, $policy, $kind, $units);
                        $amount = $amount->compare($deduction) > 0 ? $amount->minus($deduction) : new PayrollExactFraction(0);
                        $this->add($purpose, $amount, $units, [...$this->segmentTrace($segment, $policy), 'factors' => $factors]);
                    }
                });
            }
        }
        $this->guard('holiday_nonworked_compensation', fn() => $this->nonworkedHolidays());
        $this->guard('holiday_time_off_compensation', fn() => $this->holidayGrants());
        return $this->finish($hash);
    }

    private function includedOvertime(array $segment, array $policy, int $units): int
    {
        $agreement = $policy['included_overtime'] ?? null;
        if (!is_array($agreement) || ($agreement['agreement_kind'] ?? null) !== 'contract' || $this->isAgreement()) {
            throw new PayrollHourlyCalculationException('hourly_included_overtime_evidence_missing', 'Zahrnutí přesčasu vyžaduje smluvně sjednanou mzdu a konkrétní doložený rozsah.');
        }
        self::reference($agreement['agreement_reference'] ?? null);
        $year = (int) substr($segment['date_from'], 0, 4);
        if (($agreement['calendar_year'] ?? null) !== $year || !in_array($agreement['scope_kind'] ?? null, ['annual_hours', 'manager_statutory'], true)) throw new PayrollHourlyCalculationException('hourly_included_overtime_evidence_missing', 'Rozsah zahrnutého přesčasu neodpovídá roku výpočtu.');
        $cap = self::positive($agreement['annual_limit_duration_units'] ?? null);
        if ($cap > PayrollDuration::fromMinutes(150 * 60) || $agreement['scope_kind'] === 'manager_statutory') {
            self::reference($agreement['manager_proof_reference'] ?? null);
            self::reference($agreement['manager_lawful_scope_reference'] ?? null);
            if ($cap > self::positive($agreement['manager_lawful_scope_duration_units'] ?? null)) throw new PayrollHourlyCalculationException('hourly_included_overtime_evidence_missing', 'Sjednaný přesčas překračuje doložený zákonný rozsah vedoucího zaměstnance.');
        }
        $opening = self::date($agreement['opening_as_of'] ?? null);
        if (substr($opening, 0, 4) !== (string) $year || $opening > $segment['date_from']) throw new PayrollHourlyCalculationException('hourly_included_overtime_history_incomplete', 'Počáteční stav zahrnutého přesčasu neodpovídá hodnocenému roku.');
        return $this->consume('included-overtime:' . $year, $agreement, $cap, $units);
    }

    private function includedAgreementPremium(array $segment, array $policy, PayrollSurchargeKind $kind, int $units): PayrollExactFraction
    {
        $matches = array_values(array_filter($policy['included_agreement_premiums'] ?? [], static fn(array $row): bool => ($row['kind'] ?? null) === $kind->value
            && ($row['date_from'] ?? '9999') <= $segment['date_to'] && ($row['date_to'] ?? '') >= $segment['date_from']));
        if ($matches === []) return new PayrollExactFraction(0);
        if (!$this->isAgreement() || count($matches) !== 1 || !in_array($kind, [PayrollSurchargeKind::Night, PayrollSurchargeKind::Weekend, PayrollSurchargeKind::DifficultEnvironment], true)) throw new PayrollHourlyCalculationException('hourly_included_premium_kind_invalid', 'Zahrnutý příplatek nemá platný druh nebo jednoznačné sjednání.');
        $included = $matches[0];
        $from = self::date($included['date_from'] ?? null);
        $to = self::date($included['date_to'] ?? null);
        if ($from > $segment['date_from'] || $to < $segment['date_to']) throw new PayrollHourlyCalculationException('hourly_included_premium_partition_missing', 'Rozdělte hodiny podle sjednaného období zahrnutého příplatku.');
        $openingDate = self::date($included['opening_as_of'] ?? null);
        if ($openingDate < $from || $openingDate > $segment['date_from']) throw new PayrollHourlyCalculationException('hourly_included_premium_history_incomplete', 'Počáteční čerpání příplatku neodpovídá období doložené práce.');
        $reference = self::reference($included['agreement_reference'] ?? null);
        $scopeKey = 'agreement-premium:' . $kind->value . ':' . hash('sha256', CanonicalJson::encode(['reference' => $reference, 'from' => $from, 'to' => $to]));
        $covered = $this->consume($scopeKey, $included, self::positive($included['scope_duration_units'] ?? null), $units);
        $amount = PayrollExactFraction::product([self::positive($included['included_hourly_minor'] ?? null), $covered], [PayrollDuration::UNITS_PER_HOUR]);
        if ($covered > 0) $this->included[] = [...$this->allocation('included_agreement_premium', 'premium_' . $kind->value, $segment, $covered, $amount), 'premium_kind' => $kind->value, 'scope_key' => $scopeKey, 'exclude_from_minimum_wage' => true];
        return $amount;
    }

    /** Čerpání používá jednou historický stav a potom všechny úseky aktuálního výpočtu. */
    private function consume(string $key, array $proof, int $cap, int $requested): int
    {
        $opening = self::units($proof['opening_used_duration_units'] ?? null);
        $prior = self::units($proof['prior_consumed_duration_units'] ?? null);
        self::reference($proof['opening_reference'] ?? null);
        self::hash($proof['prior_claims_hash'] ?? null);
        $history = PayrollDuration::add($opening, $prior);
        if (isset($this->history[$key]) && $this->history[$key] !== $history) throw new PayrollHourlyCalculationException('hourly_included_history_conflict', 'Historické čerpání stejného sjednaného rozsahu si odporuje.');
        $this->history[$key] = $history;
        $used = PayrollDuration::add($history, $this->consumed[$key] ?? 0);
        $covered = min($requested, max(0, $cap - $used));
        $this->consumed[$key] = PayrollDuration::add($this->consumed[$key] ?? 0, $covered);
        return $covered;
    }

    private function premiumAmount(PayrollSurchargeKind $kind, array $policy, int $units, int $factors = 1): PayrollExactFraction
    {
        self::reference((string) ($this->evidence['ruleset']['id'] ?? ''));
        self::hash($this->evidence['ruleset']['content_sha256'] ?? null);
        $rate = $policy['rates'][$kind->value] ?? null;
        if (!is_array($rate)) throw new PayrollHourlyCalculationException('hourly_premium_rate_missing', 'Chybí doložená sazba příplatku z pravidel nebo platného sjednání.');
        $basis = $kind === PayrollSurchargeKind::DifficultEnvironment ? 'minimum' : 'average';
        if (($rate['statutory_basis'] ?? null) !== $basis) throw new PayrollHourlyCalculationException('hourly_premium_basis_invalid', 'Příplatek má nesprávný zákonný výpočtový základ.');
        $statutory = self::units($rate['statutory_rate_bp'] ?? null);
        $agreed = $rate['agreed_rate_bp'] ?? null;
        $fixed = $rate['agreed_fixed_hourly_minor'] ?? null;
        if ($agreed !== null && $fixed !== null) throw new PayrollHourlyCalculationException('hourly_premium_rate_invalid', 'Procentní a pevná sazba příplatku nesmějí být sjednány současně.');
        if ($agreed !== null || $fixed !== null) self::reference($rate['agreement_reference'] ?? null);
        if ($fixed !== null && $kind->allowsLowerAgreedRate()) {
            return PayrollExactFraction::product([self::units($fixed), $units, $factors], [PayrollDuration::UNITS_PER_HOUR]);
        }
        $basisMinor = $basis === 'average' ? $this->average() : self::positive($this->evidence['minimum_hourly_minor'] ?? null);
        $statutoryHourly = PayrollExactFraction::product([$basisMinor, $statutory], [10000]);
        if ($fixed !== null) {
            $hourly = new PayrollExactFraction(self::units($fixed));
            if ($hourly->compare($statutoryHourly) < 0) $hourly = $statutoryHourly;
        } else {
            $effective = $agreed === null ? $statutory : self::units($agreed);
            if (!$kind->allowsLowerAgreedRate()) $effective = max($statutory, $effective);
            $hourly = PayrollExactFraction::product([$basisMinor, $effective], [10000]);
        }
        return PayrollExactFraction::product([$hourly->numerator, $units, $factors], [$hourly->denominator, PayrollDuration::UNITS_PER_HOUR]);
    }

    private function holidayWork(array $segment, array $row, array $policy, int $units): void
    {
        $days = $this->workDays($segment, $row['holiday_work_days'] ?? null, $units, true);
        $mode = $policy['holiday_mode'] ?? null;
        if ($mode === 'surcharge') {
            self::reference($policy['holiday_surcharge_agreement_reference'] ?? null);
            $this->add('premium_holiday', $this->premiumAmount(PayrollSurchargeKind::Holiday, $policy, $units), $units, $this->segmentTrace($segment, $policy));
            return;
        }
        if ($mode !== 'time_off') throw new PayrollHourlyCalculationException('hourly_holiday_policy_missing', 'Doložte náhradní volno za svátek nebo dohodu o příplatku.');
        foreach ($days as $day) $this->entitlements[] = [
            'kind' => 'holiday_time_off_accrual', 'purpose' => 'premium_holiday', 'work_date' => $day['date'],
            'duration_units' => $day['duration_units'], 'due_on' => $this->dueOn($day['date'], 'holiday'),
            'entitlement_key' => 'holiday:' . $this->context->employmentId . ':' . $day['date'],
            'policy_version_id' => $policy['id'], 'source_sha256' => $this->context->workedTimeEvidence['source_sha256'],
        ];
    }

    private function overtimeTimeOff(array $segment, array $policy, int $units): void
    {
        self::reference($policy['document_reference'] ?? null);
        $row = $this->premiumSegment($segment);
        $days = $this->workDays($segment, $row['overtime_work_days'] ?? null, $units, false);
        foreach ($days as $day) {
            $due = $this->dueOn($day['date'], 'overtime');
            $allocated = 0;
            foreach ($this->evidence['overtime_time_off_allocations'] ?? [] as $allocation) {
                if (($allocation['work_date'] ?? null) !== $day['date']) continue;
                self::reference($allocation['reference'] ?? null);
                if (self::date($allocation['granted_on'] ?? null) > $this->evidence['as_of']) continue;
                $allocated = PayrollDuration::add($allocated, self::positive($allocation['duration_units'] ?? null));
            }
            if ($allocated > $day['duration_units']) throw new PayrollHourlyCalculationException('hourly_overtime_time_off_overdrawn', 'Čerpání přesčasového volna přesahuje doložený přesčas.');
            $remaining = $day['duration_units'] - $allocated;
            if ($due < $this->evidence['as_of'] && $remaining > 0) {
                $this->add('premium_overtime', $this->premiumAmount(PayrollSurchargeKind::Overtime, $policy, $remaining), $remaining, [...$this->segmentTrace($segment, $policy), 'work_date' => $day['date'], 'time_off_due_on' => $due]);
            } else {
                $this->entitlements[] = ['kind' => 'overtime_time_off_accrual', 'purpose' => 'premium_overtime',
                    'work_date' => $day['date'], 'duration_units' => $day['duration_units'], 'allocated_duration_units' => $allocated,
                    'due_on' => $due, 'entitlement_key' => 'overtime:' . $this->context->employmentId . ':' . $day['date']];
            }
        }
    }

    private function nonworkedHolidays(): void
    {
        $rows = $this->evidence['nonworked_holidays'] ?? null;
        if (!is_array($rows)) throw new PayrollHourlyCalculationException('hourly_holiday_evidence_missing', 'Doložte neodpracované svátky nebo potvrďte, že žádný nárok nevznikl.');
        foreach ($rows as $row) {
            $date = $this->periodDate($row['date'] ?? null);
            $this->assertHoliday($date);
            if (($row['reason'] ?? null) !== 'holiday') throw new PayrollHourlyCalculationException('hourly_holiday_reason_invalid', 'Náhrada přísluší jen za dobu neodpracovanou z důvodu svátku.');
            $term = $this->termOn($date);
            if (($term['remuneration_type'] ?? null) !== 'hourly') throw new PayrollHourlyCalculationException('hourly_holiday_already_in_monthly_wage', 'Svátek krytý měsíční mzdou nelze zaplatit ještě hodinovou náhradou.');
            $units = $this->scheduleCapacity($row, $date);
            $this->add('holiday_nonworked_compensation', PayrollExactFraction::product([$this->average(), $units], [PayrollDuration::UNITS_PER_HOUR]), $units, $row);
        }
    }

    private function holidayGrants(): void
    {
        $rows = $this->evidence['holiday_time_off_grants'] ?? null;
        if (!is_array($rows)) throw new PayrollHourlyCalculationException('hourly_holiday_time_off_evidence_missing', 'Doložte čerpání placeného volna za svátek nebo potvrďte nulové čerpání.');
        $used = [];
        $availableById = [];
        foreach ($rows as $row) {
            $date = $this->periodDate($row['granted_on'] ?? null);
            $workDate = self::date($row['work_date'] ?? null);
            $this->assertHoliday($workDate);
            if ($date < $workDate) throw new PayrollHourlyCalculationException('hourly_holiday_time_off_date_invalid', 'Čerpání svátečního volna nesmí předcházet vzniku nároku.');
            $id = self::positive($row['entitlement_id'] ?? null);
            self::positive($row['entitlement_revision'] ?? null);
            self::positive($row['absence_id'] ?? null);
            $available = self::units($row['available_duration_units'] ?? null);
            if (isset($availableById[$id]) && $availableById[$id] !== $available) throw new PayrollHourlyCalculationException('hourly_holiday_time_off_evidence_conflict', 'Zůstatek téhož svátečního nároku si odporuje.');
            $availableById[$id] = $available;
            $units = $this->scheduleCapacity($row, $date);
            $used[$id] = PayrollDuration::add($used[$id] ?? 0, $units);
            if ($used[$id] > $available) throw new PayrollHourlyCalculationException('hourly_holiday_time_off_overdrawn', 'Čerpání placeného volna přesahuje zbývající nárok za svátek.');
            self::positive($row['average_snapshot_id'] ?? null);
            $rate = self::positive($row['average_hourly_minor'] ?? null);
            $this->add('holiday_time_off_compensation', PayrollExactFraction::product([$rate, $units], [PayrollDuration::UNITS_PER_HOUR]), $units, $row);
            $this->entitlements[] = ['kind' => 'holiday_time_off_allocation', 'purpose' => 'holiday_time_off_compensation',
                'entitlement_id' => $id, 'entitlement_revision' => $row['entitlement_revision'], 'work_date' => $workDate,
                'granted_on' => $date, 'duration_units' => $units, 'absence_id' => $row['absence_id']];
        }
    }

    private function scheduleCapacity(array $row, string $date): int
    {
        self::positive($row['schedule_revision_id'] ?? null);
        self::hash($row['schedule_hash'] ?? null);
        self::hash($row['absence_coverage_hash'] ?? null);
        $scheduled = self::units($row['scheduled_duration_units'] ?? null);
        $worked = self::units($row['worked_duration_units'] ?? null);
        $other = self::units($row['other_absence_duration_units'] ?? null);
        $units = self::positive($row['duration_units'] ?? null);
        $covered = PayrollDuration::add($worked, $other);
        $remaining = max(0, $scheduled - $covered);
        $used = PayrollDuration::add($this->scheduleUsed[$date] ?? 0, $units);
        if ($used > $remaining) throw new PayrollHourlyCalculationException('hourly_holiday_time_overlap', 'Náhrada překračuje skutečný rozvrh po odečtení práce a ostatních nepřítomností.');
        $this->scheduleUsed[$date] = $used;
        return $units;
    }

    private function policy(array $segment, mixed $id = null): array
    {
        $matches = array_values(array_filter($this->evidence['policy_versions'] ?? [], static fn(array $row): bool => ($row['date_from'] ?? '9999') <= $segment['date_from'] && ($row['date_to'] ?? '') >= $segment['date_to']));
        if (count($matches) !== 1 || ($id !== null && ($matches[0]['id'] ?? null) !== $id)) throw new PayrollHourlyCalculationException('hourly_policy_partition_missing', 'Doložte účinnou politiku a rozdělte práci podle jejích hranic.');
        $policy = $matches[0];
        if (($policy['source'] ?? null) !== 'statutory') {
            self::positive($policy['id'] ?? null);
            self::positive($policy['revision_no'] ?? null);
        }
        self::hash($policy['content_sha256'] ?? null);
        self::date($policy['date_from'] ?? null);
        self::date($policy['date_to'] ?? null);
        return $policy;
    }

    private function premiumSegment(array $segment): array
    {
        $matches = array_values(array_filter($this->evidence['premium_segments'] ?? [], static fn(array $row): bool => ($row['date_from'] ?? null) === $segment['date_from'] && ($row['date_to'] ?? null) === $segment['date_to']));
        if (count($matches) !== 1) throw new PayrollHourlyCalculationException('hourly_premium_partition_missing', 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.');
        return $matches[0];
    }

    private function workDays(array $segment, mixed $rows, int $expected, bool $holiday): array
    {
        if (!is_array($rows) || $rows === []) throw new PayrollHourlyCalculationException('hourly_time_off_work_dates_missing', 'Pro vznik náhradního volna doložte skutečné dny a dobu práce.');
        $sum = 0;
        $seen = [];
        foreach ($rows as $row) {
            $date = self::date($row['date'] ?? null);
            if ($date < $segment['date_from'] || $date > $segment['date_to'] || isset($seen[$date])) throw new PayrollHourlyCalculationException('hourly_time_off_work_dates_invalid', 'Rozpad práce pro náhradní volno má neplatné nebo opakované datum.');
            if ($holiday) $this->assertHoliday($date);
            $seen[$date] = true;
            $sum = PayrollDuration::add($sum, self::positive($row['duration_units'] ?? null));
        }
        if ($sum !== $expected) throw new PayrollHourlyCalculationException('hourly_time_off_work_dates_invalid', 'Součet jednotlivých dnů neodpovídá doložené práci pro náhradní volno.');
        return $rows;
    }

    private function average(): int
    {
        self::positive($this->evidence['average_earning']['id'] ?? null);
        self::positive($this->evidence['average_earning']['revision_no'] ?? null);
        return self::positive($this->evidence['average_earning']['hourly_minor'] ?? null);
    }

    private function dueOn(string $date, string $kind): string
    {
        $months = self::positive($this->evidence['ruleset'][$kind . '_time_off_months'] ?? null);
        if ($months > 120) throw new \InvalidArgumentException('Lhůta náhradního volna není platná.');
        return (new \DateTimeImmutable($date))->modify('first day of this month')->modify('+' . ($months + 1) . ' months')->modify('-1 day')->format('Y-m-d');
    }

    private function termOn(string $date): array
    {
        foreach ($this->context->terms as $term) {
            if (($term['effective_from'] ?? '9999') <= $date && ($term['effective_to'] ?? '9999-12-31') >= $date) return $term;
        }
        throw new PayrollHourlyCalculationException('hourly_holiday_terms_missing', 'Pro den náhrady chybí účinné pracovní podmínky.');
    }

    private function periodDate(mixed $value): string
    {
        $date = self::date($value);
        if (substr($date, 0, 7) !== substr($this->context->periodStart, 0, 7)) throw new \InvalidArgumentException('Datum náhrady nepatří do zpracovávaného měsíce.');
        return $date;
    }

    private function assertHoliday(string $date): void
    {
        $calendar = $this->evidence['holiday_calendar'] ?? null;
        $dates = is_array($calendar) ? ($calendar['dates'] ?? null) : null;
        if (!is_array($dates) || !is_string($calendar['content_sha256'] ?? null)
            || !hash_equals(hash('sha256', CanonicalJson::encode($dates)), $calendar['content_sha256'])) {
            throw new PayrollHourlyCalculationException('hourly_holiday_calendar_evidence_missing', 'Chybí zmrazený číselník zákonných svátků pro doložený nárok.');
        }
        if (!in_array($date, $dates, true)) throw new PayrollHourlyCalculationException('hourly_holiday_date_invalid', 'Doložený den není zákonným svátkem.');
    }

    private function isAgreement(): bool
    {
        return in_array($this->context->employmentType, ['dpp', 'dpc'], true);
    }

    private function initialize(string $purpose, string $code): void
    {
        $this->amounts[$purpose] = new PayrollExactFraction(0);
        $this->claims[$purpose] = ['purpose' => $purpose, 'component_code' => $code, 'duration_units' => 0,
            'worked_duration_units' => 0, 'ready' => true, 'segments' => [], 'rounding' => $this->context->rounding->value];
    }

    private function add(string $purpose, PayrollExactFraction $amount, int $units, array $trace): void
    {
        $this->amounts[$purpose] = $this->amounts[$purpose]->plus($amount);
        $this->claims[$purpose]['duration_units'] = PayrollDuration::add($this->claims[$purpose]['duration_units'], $units);
        $this->claims[$purpose]['segments'][] = [...$trace, 'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator, 'duration_units' => $units];
    }

    private function guard(string $purpose, callable $work): void
    {
        try {
            $work();
        } catch (PayrollHourlyCalculationException $exception) {
            $this->fail($purpose, $exception->reason, $exception->getMessage(), $exception->context);
        } catch (\InvalidArgumentException|\OverflowException $exception) {
            $this->fail($purpose, $exception instanceof \OverflowException ? 'hourly_amount_overflow' : 'hourly_entitlement_evidence_invalid', $exception->getMessage());
        }
    }

    private function fail(string $purpose, string $code, string $message, array $context = []): void
    {
        $this->blocked[$purpose] = true;
        $this->blockers[] = ['code' => $code, 'message' => $message, 'context' => ['purpose' => $purpose, ...$context]];
    }

    private function finish(string $hash): PayrollHourlyWageResult
    {
        foreach ($this->amounts as $purpose => $amount) {
            $this->guard($purpose, function () use ($purpose, $amount): void {
                // Dosažený přesčas původně obsahoval tarif za veškerý přesčas.
                $this->claims[$purpose] = [...$this->claims[$purpose],
                    'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator,
                    'analytical_minor' => $amount->analyticalMinor(), 'payable_minor' => $amount->payableMinor($this->context->rounding)];
            });
        }
        foreach ($this->claims as $purpose => &$claim) {
            if (!isset($this->blocked[$purpose])) continue;
            $claim['ready'] = false;
            foreach (['raw_numerator_minor', 'raw_denominator', 'analytical_minor', 'payable_minor'] as $key) $claim[$key] = null;
        }
        unset($claim);
        foreach ($this->entitlements as &$row) $row['ready'] = !isset($this->blocked[$row['purpose']]);
        unset($row);
        foreach ($this->included as &$row) $row['ready'] = !isset($this->blocked[$row['purpose']]);
        unset($row);
        return new PayrollHourlyWageResult($this->blockers === [], array_values($this->claims), $this->blockers, $hash, $this->entitlements, $this->included);
    }

    private function allocation(string $kind, string $purpose, array $segment, int $units, PayrollExactFraction $amount): array
    {
        return ['kind' => $kind, 'purpose' => $purpose, 'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
            'duration_units' => $units, 'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator, 'analytical_minor' => $amount->analyticalMinor()];
    }

    private function segmentTrace(array $segment, array $policy): array
    {
        return ['date_from' => $segment['date_from'], 'date_to' => $segment['date_to'], 'term_id' => $segment['term']['id'], 'policy_version_id' => $policy['id'], 'policy_revision_no' => $policy['revision_no']];
    }

    private static function units(mixed $value): int
    {
        if (!is_int($value) || $value < 0) throw new PayrollHourlyCalculationException('hourly_premium_evidence_missing', 'Doplňte doloženou dobu nebo výslovně potvrďte nulu.');
        return $value;
    }

    private static function positive(mixed $value): int
    {
        if (!is_int($value) || $value <= 0) throw new \InvalidArgumentException('Chybí povinný kladný údaj nároku.');
        return $value;
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') throw new PayrollHourlyCalculationException('hourly_entitlement_reference_missing', 'Doplňte odkaz na smlouvu, dohodu nebo doložení historického stavu.');
        return $value;
    }

    private static function hash(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) throw new \InvalidArgumentException('Chybí platný otisk doložených podkladů.');
        return $value;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || !IsoDate::isValid($value)) throw new \InvalidArgumentException('Datum nároku není platné.');
        return $value;
    }
}
