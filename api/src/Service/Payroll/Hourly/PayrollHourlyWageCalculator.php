<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeSource;

/** Čistý výpočet tarifní mzdy; peněžní zápisy a jejich revize spravuje materializer. */
final class PayrollHourlyWageCalculator
{
    public function calculate(PayrollHourlyWageContext $context): PayrollHourlyWageResult
    {
        $hash = hash('sha256', CanonicalJson::encode([
            ...get_object_vars($context), 'rounding' => $context->rounding->value,
            'calculator_version' => 'hourly-wage.v1',
        ]));
        try {
            return $this->calculateChecked($context, $hash);
        } catch (PayrollHourlyCalculationException $exception) {
            return new PayrollHourlyWageResult(false, [], [[
                'code' => $exception->reason, 'message' => $exception->getMessage(), 'context' => $exception->context,
            ]], $hash);
        } catch (\InvalidArgumentException|\OverflowException $exception) {
            return new PayrollHourlyWageResult(false, [], [[
                'code' => $exception instanceof \OverflowException ? 'hourly_amount_overflow' : 'hourly_context_invalid',
                'message' => $exception->getMessage(), 'context' => [],
            ]], $hash);
        }
    }

    private function calculateChecked(PayrollHourlyWageContext $context, string $hash): PayrollHourlyWageResult
    {
        if ($context->employmentId <= 0 || !in_array($context->employmentType, ['employment', 'small_scale_employment', 'dpp', 'dpc'], true)) {
            throw new \InvalidArgumentException('Druh pracovního vztahu nepodporuje hodinovou mzdu.');
        }
        if ($context->settlementOnly) {
            if (!\MyInvoice\Http\IsoDate::isValid($context->periodStart) || substr($context->periodStart, 8) !== '01') throw new \InvalidArgumentException('Období vypořádání není platné.');
            if (($context->entitlementContext['holiday_time_off_grants'] ?? []) !== [] || ($context->entitlementContext['overtime_time_off_allocations'] ?? []) !== []) $this->assertSource($context);
            return (new PayrollHourlyEntitlementCalculator())->calculate($context, [], [], $hash);
        }
        $partitioner = new PayrollHourlyTimePartitioner();
        $terms = $partitioner->terms($context->terms, $context->periodStart);
        if (array_filter($terms, static fn(array $term): bool => ($term['remuneration_type'] ?? null) === 'hourly') === []) {
            throw new PayrollHourlyCalculationException('hourly_terms_missing', 'V období nejsou doložené hodinové podmínky.');
        }
        $this->assertSource($context);
        $segments = $partitioner->partition($terms, $context->segments);
        $this->assertSourceAllocation($context, $terms, $segments, $partitioner);
        $agreement = in_array($context->employmentType, ['dpp', 'dpc'], true);
        $ordinary = new PayrollExactFraction(0);
        $overtime = new PayrollExactFraction(0);
        $ordinaryUnits = $overtimeUnits = $ordinaryWorkedUnits = 0;
        $ordinaryTrace = $overtimeTrace = [];
        $monthlyTerms = [];
        foreach ($terms as $term) {
            $mode = $term['remuneration_type'] ?? 'monthly';
            if (!in_array($mode, ['monthly', 'hourly'], true)) throw new \InvalidArgumentException('Způsob odměňování není platný.');
            if ($mode === 'monthly') $monthlyTerms[$term['id']] = $term;
        }
        $monthly = $this->monthlyParts($monthlyTerms, $context->monthlyParts);
        foreach ($segments as $segment) {
            $term = $segment['term'];
            $worked = $segment['worked_duration_units'];
            $extra = $segment['overtime_duration_units'];
            $ordinaryWorkedUnits = PayrollDuration::add($ordinaryWorkedUnits, $worked - $extra);
            if ($agreement && $extra > 0) throw new PayrollHourlyCalculationException('hourly_agreement_overtime_invalid', 'DPP a DPČ nemají přesčasový nárok podle § 114; opravte klasifikaci odpracované doby.');
            if (($term['remuneration_type'] ?? 'monthly') === 'hourly') {
                $rate = $term['hourly_rate_minor'] ?? null;
                if (!is_int($rate) || $rate <= 0) throw new PayrollHourlyCalculationException('hourly_rate_missing', 'Účinná hodinová sazba chybí nebo není kladná.');
                $regularAmount = PayrollExactFraction::product([$rate, $worked - $extra], [PayrollDuration::UNITS_PER_HOUR]);
                $extraAmount = PayrollExactFraction::product([$rate, $extra], [PayrollDuration::UNITS_PER_HOUR]);
                $ordinary = $ordinary->plus($regularAmount);
                $ordinaryUnits = PayrollDuration::add($ordinaryUnits, $worked - $extra);
                $ordinaryTrace[] = $this->trace($segment, $regularAmount, $worked - $extra);
            } else {
                $part = $monthly[$term['id']];
                $extraAmount = PayrollExactFraction::product([$term['monthly_gross_minor'], $extra], [$part['fund_duration_units']]);
            }
            $overtime = $overtime->plus($extraAmount);
            $overtimeUnits = PayrollDuration::add($overtimeUnits, $extra);
            if ($extra > 0) $overtimeTrace[] = $this->trace($segment, $extraAmount, $extra);
        }
        foreach ($monthly as $id => $part) {
            $term = $monthlyTerms[$id];
            $amount = PayrollExactFraction::product([$term['monthly_gross_minor'], $part['retained_duration_units']], [$part['fund_duration_units']]);
            $ordinary = $ordinary->plus($amount);
            $ordinaryUnits = PayrollDuration::add($ordinaryUnits, $part['retained_duration_units']);
            $ordinaryTrace[] = $this->trace([...$part, 'term' => $term], $amount, $part['retained_duration_units']);
        }
        usort($ordinaryTrace, static fn(array $a, array $b): int => $a['date_from'] <=> $b['date_from']);
        $claims = [$this->claim('ordinary_base', 'MZDA_HODINOVA_ZAKLAD', $ordinary, $ordinaryUnits, $ordinaryTrace, $context, $ordinaryWorkedUnits)];
        if (!$agreement) $claims[] = $this->claim('achieved_overtime', 'MZDA_DOSAZENA_PRESCAS', $overtime, $overtimeUnits, $overtimeTrace, $context);
        return (new PayrollHourlyEntitlementCalculator())->calculate($context, $segments, $claims, $hash);
    }

    private function assertSource(PayrollHourlyWageContext $context): void
    {
        $source = $context->workedTimeEvidence;
        if ($source === null) throw new PayrollHourlyCalculationException('hourly_time_missing', 'Doložte skutečně odpracovanou dobu; plánovaný fond ji nenahrazuje.');
        if (($source['work_source'] ?? null) === 'import_summary') throw new PayrollHourlyCalculationException('hourly_import_source_unsupported', 'Hodinovou mzdu z importovaného souhrnu hodin mzdový běh nepočítá. Na kartě vztahu přepněte způsob odměňování na měsíční (u dohody stačí 0 Kč) a docházku importujte znovu: mzdu ze sazby a hodin dopočte import. Jinak ji zadejte v rychlém měsíčním vstupu.');
        if (!in_array($source['work_source'] ?? null, ['entries', 'manual_summary'], true)) throw new PayrollHourlyCalculationException('hourly_time_evidence_invalid', 'Zdroj pracovní doby není podporovaný nebo není jednoznačný.');
        if (($source['status'] ?? null) !== 'approved') throw new PayrollHourlyCalculationException('hourly_time_not_approved', 'Nejprve schvalte podklady pracovní doby.');
        $storedHash = $source['source_sha256'] ?? null;
        unset($source['source_sha256']);
        if (($source['schema_version'] ?? null) !== 'payroll-worked-time.v1'
            || ($source['period_start'] ?? null) !== $context->periodStart
            || ($source['duration_units_per_hour'] ?? null) !== PayrollDuration::UNITS_PER_HOUR
            || !is_string($storedHash) || !hash_equals(hash('sha256', CanonicalJson::encode($source)), $storedHash)) {
            throw new PayrollHourlyCalculationException('hourly_time_evidence_invalid', 'Otisk nebo identita zdroje pracovní doby není platná.');
        }
        if (($source['work_source'] ?? null) === 'manual_summary'
            && (($source['manual_summary']['time_month_id'] ?? null) !== ($source['time_month_id'] ?? null)
                || ($source['manual_summary']['time_month_revision_no'] ?? null) !== ($source['revision_no'] ?? null))) {
            throw new PayrollHourlyCalculationException('hourly_time_evidence_invalid', 'Ruční souhrn patří jiné revizi pracovního měsíce.');
        }
        $worked = PayrollWorkedTimeSource::fromSnapshot($source, $context->periodStart);
        if ($worked['issues'] !== [] || $worked['worked_duration_units'] === null) {
            throw new PayrollHourlyCalculationException('hourly_time_evidence_invalid', 'Zdroj pracovní doby je neúplný nebo rozporný.', ['issues' => $worked['issues']]);
        }
        if ($worked['overtime_duration_units'] === null) throw new PayrollHourlyCalculationException('hourly_overtime_not_provided', 'Doplňte přesčasovou část pracovní doby nebo výslovně potvrďte nulu.');
    }

    /** @param list<array<string,mixed>> $terms @param list<array<string,mixed>> $segments */
    private function assertSourceAllocation(PayrollHourlyWageContext $context, array $terms, array $segments, PayrollHourlyTimePartitioner $partitioner): void
    {
        $source = $context->workedTimeEvidence;
        if ($source['work_source'] === 'manual_summary') {
            $expected = $partitioner->partition($terms, $source['manual_summary']['segments']);
            $projection = static fn(array $rows): array => array_map(static fn(array $row): array => [
                $row['date_from'], $row['date_to'], $row['worked_duration_units'], $row['overtime_duration_units'],
            ], $rows);
            if ($projection($expected) !== $projection($segments)) throw new PayrollHourlyCalculationException('hourly_source_partition_mismatch', 'Rozdělení hodin neodpovídá schválenému ručnímu souhrnu.');
            return;
        }
        $entries = [];
        foreach ($source['time_entries'] as $entry) {
            if (!in_array($entry['category'] ?? null, ['regular', 'overtime'], true)) continue;
            if (($entry['status'] ?? null) !== 'approved') throw new PayrollHourlyCalculationException('hourly_time_not_approved', 'Docházkový interval nebyl schválen.');
            $time = PayrollWorkedTimeSource::fromEntries([$entry], $context->periodStart);
            $zone = new \DateTimeZone($entry['timezone_name']);
            $utc = new \DateTimeZone('UTC');
            $start = (new \DateTimeImmutable($entry['starts_at_utc'], $utc))->setTimezone($zone);
            $end = (new \DateTimeImmutable($entry['ends_at_utc'], $utc))->setTimezone($zone);
            $entries[] = ['date_from' => $start->format('Y-m-d'), 'date_to' => $end->modify('-1 second')->format('Y-m-d'),
                'worked_duration_units' => $time['worked_duration_units'], 'overtime_duration_units' => $time['overtime_duration_units']];
        }
        if ($entries === []) throw new PayrollHourlyCalculationException('hourly_time_missing', 'Docházka nedokládá práci ani výslovně potvrzenou nulu.');
        $expected = $partitioner->partition($terms, $entries, true);
        $totals = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $id = $row['term']['id'];
                $result[$id] ??= [0, 0];
                $result[$id][0] = PayrollDuration::add($result[$id][0], $row['worked_duration_units']);
                $result[$id][1] = PayrollDuration::add($result[$id][1], $row['overtime_duration_units']);
            }
            ksort($result);
            return $result;
        };
        if ($totals($expected) !== $totals($segments)) throw new PayrollHourlyCalculationException('hourly_source_partition_mismatch', 'Rozdělení hodin mezi sazby neodpovídá schválené docházce.');
    }

    /** @param array<int,array<string,mixed>> $terms @param list<array<string,mixed>> $parts @return array<int,array<string,mixed>> */
    private function monthlyParts(array $terms, array $parts): array
    {
        $result = [];
        foreach ($parts as $part) {
            $id = $part['term_id'] ?? null;
            $term = is_int($id) ? ($terms[$id] ?? null) : null;
            if ($term === null || isset($result[$id]) || ($part['date_from'] ?? null) !== $term['date_from'] || ($part['date_to'] ?? null) !== $term['date_to']) {
                throw new PayrollHourlyCalculationException('hourly_monthly_partition_invalid', 'Měsíční část musí přesně odpovídat účinnému intervalu podmínek.');
            }
            $fund = $part['fund_duration_units'] ?? null;
            $retained = $part['retained_duration_units'] ?? null;
            if (!is_int($fund) || $fund <= 0 || !is_int($retained) || $retained < 0 || $retained > $fund
                || !is_int($term['monthly_gross_minor'] ?? null) || $term['monthly_gross_minor'] < 0) {
                throw new PayrollHourlyCalculationException('hourly_monthly_partition_invalid', 'Měsíční část nemá platnou částku, fond nebo dobu krytou mzdou.');
            }
            $result[$id] = $part;
        }
        if (count($result) !== count($terms)) throw new PayrollHourlyCalculationException('hourly_monthly_partition_missing', 'Doložte měsíční fond a část krytou měsíční mzdou pro smíšený měsíc.');
        return $result;
    }

    /** @param array<string,mixed> $segment @return array<string,mixed> */
    private function trace(array $segment, PayrollExactFraction $amount, int $units): array
    {
        return ['term_id' => $segment['term']['id'], 'term_row_version' => $segment['term']['row_version'] ?? null,
            'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'], 'duration_units' => $units,
            'hourly_rate_minor' => $segment['term']['hourly_rate_minor'] ?? null,
            'monthly_gross_minor' => $segment['term']['monthly_gross_minor'] ?? null,
            'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator];
    }

    /** @param list<array<string,mixed>> $segments @return array<string,mixed> */
    private function claim(string $purpose, string $code, PayrollExactFraction $amount, int $units, array $segments, PayrollHourlyWageContext $context, ?int $workedUnits = null): array
    {
        return ['purpose' => $purpose, 'component_code' => $code, 'duration_units' => $units,
            'worked_duration_units' => $workedUnits ?? $units, 'ready' => true,
            'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator,
            'analytical_minor' => $amount->analyticalMinor(), 'payable_minor' => $amount->payableMinor($context->rounding),
            'rounding' => $context->rounding->value, 'segments' => $segments];
    }
}
