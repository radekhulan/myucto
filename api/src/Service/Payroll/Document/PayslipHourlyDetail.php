<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Payroll\Hourly\PayrollExactFraction;

/** Výhradně prezentace neměnných nároků; žádné nové zjišťování hodin, sazeb ani nároků. */
final class PayslipHourlyDetail
{
    public static function label(string $purpose): string
    {
        return match ($purpose) {
            'ordinary_base' => 'Základní mzda', 'achieved_overtime' => 'Dosažená mzda za přesčas',
            'premium_overtime' => 'Příplatek za přesčas', 'premium_night' => 'Příplatek za noční práci',
            'premium_weekend' => 'Příplatek za sobotu a neděli', 'premium_holiday' => 'Příplatek za práci ve svátek',
            'premium_difficult_environment' => 'Příplatek za ztížené prostředí',
            'holiday_nonworked_compensation' => 'Náhrada mzdy za svátek',
            'holiday_time_off_compensation' => 'Náhrada za volno za práci ve svátek',
            'minimum_wage_top_up' => 'Doplatek do minimální mzdy',
            default => throw new \DomainException('Neznámý hodinový nárok pásky.'),
        };
    }

    public static function validateInput(array $detail, int $amount): void
    {
        self::positive($detail, 'claim_id');
        $delta = self::integer($detail, 'delta_amount_minor');
        if ($delta !== $amount || self::nonNegative($detail, 'full_payable_minor') - self::nonNegative($detail, 'previous_payable_minor') !== $delta
            || !in_array($detail['display_basis'] ?? null, ['full_claim', 'delta'], true)) throw new \DomainException('Zúčtovaný hodinový rozdíl pásky nesouhlasí.');
        $units = $detail['quantity_duration_units'] ?? null;
        if ($units !== null && (!is_int($units) || self::duration($units) !== ($detail['quantity_text'] ?? null))) throw new \DomainException('Množství hodinového řádku pásky nesouhlasí.');
        if ($units === null && ($detail['quantity_text'] ?? null) !== null) throw new \DomainException('Nedoložené množství pásky má popisek.');
        if ($detail['display_basis'] === 'delta' && ($detail['rate_minor'] ?? null) !== null) throw new \DomainException('Opravný rozdíl nemá samostatně doloženou sazbu.');
    }

    public static function duration(int $units): string
    {
        if ($units === PHP_INT_MIN) throw new \DomainException('Doba pásky je mimo podporovaný rozsah.');
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        if ($units % 50 === 0) {
            $minutes = intdiv($units, 50);
            return $sign . intdiv($minutes, 60) . ' h' . ($minutes % 60 === 0 ? '' : ' ' . ($minutes % 60) . ' min');
        }
        if ($units % 3 === 0) {
            $milli = intdiv($units, 3);
            return $sign . intdiv($milli, 1000) . ',' . rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0') . ' h';
        }
        $minutes = intdiv($units, 50);
        $tenths = ($units % 50) * 12;
        return $sign . intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min ' . intdiv($tenths, 10) . ($tenths % 10 === 0 ? '' : ',' . ($tenths % 10)) . ' s';
    }

    /** @return array<string,mixed> */
    public static function input(array $source, int $amount): array
    {
        $claim = self::sourceClaim($source);
        $delta = self::integer($source, 'delta_amount_minor');
        $previous = self::nonNegative($source, 'previous_payable_minor');
        if ($delta !== $amount || $claim['payable_minor'] - $previous !== $delta) throw new \DomainException('Hodinový rozdíl neodpovídá skutečně zúčtované částce.');
        $full = $previous === 0 && self::nonNegative($source, 'previous_worked_duration_units') === 0;
        $units = $full ? self::nonNegative($claim, 'duration_units') : self::integer($source, 'worked_duration_delta_units');
        // Přesčas může být zahrnut ve mzdě, měsíční část je hrazený fond.
        // Jejich změnu placené doby nelze zaměnit za delta odpracované doby pro průměr.
        if (!$full && ($claim['purpose'] !== 'ordinary_base'
            || self::nonNegative($claim, 'worked_duration_units') !== self::nonNegative($claim, 'duration_units')
            || array_filter($claim['segments'] ?? [], static fn(array $row): bool => !is_int($row['hourly_rate_minor'] ?? null)) !== [])) $units = null;
        $rate = null;
        if ($full && in_array($claim['purpose'], ['ordinary_base', 'achieved_overtime'], true)) {
            $rates = array_unique(array_map(static fn(array $segment): mixed => $segment['hourly_rate_minor'] ?? null, $claim['segments'] ?? []), SORT_REGULAR);
            if (count($rates) === 1 && is_int(reset($rates))) $rate = reset($rates);
        }
        return ['claim_id' => self::positive($source, 'claim_id'), 'purpose' => $claim['purpose'],
            'work_period_start' => self::date($source['work_period_start'] ?? null), 'sequence_no' => self::positive($source, 'sequence_no'),
            'display_basis' => $full ? 'full_claim' : 'delta', 'quantity_duration_units' => $units,
            'quantity_text' => $units === null ? null : self::duration($units), 'rate_minor' => $rate,
            'full_payable_minor' => $claim['payable_minor'], 'previous_payable_minor' => $previous,
            'delta_amount_minor' => $delta, 'worked_duration_delta_units' => self::integer($source, 'worked_duration_delta_units')];
    }

    /** @return array<string,mixed> */
    public static function claim(array $source, int $employmentId, string $label): array
    {
        $claim = self::sourceClaim($source);
        $purpose = $claim['purpose'];
        $raw = self::fraction($claim);
        $segments = [];
        $sum = new PayrollExactFraction(0);
        $displaySum = 0;
        foreach ($claim['segments'] ?? [] as $segment) {
            $amount = self::fraction($segment);
            $units = self::nonNegative($segment, $purpose === 'minimum_wage_top_up' ? 'regular_worked_duration_units' : 'duration_units');
            $from = self::date($segment['date_from'] ?? $segment['date'] ?? $segment['granted_on'] ?? null);
            $to = self::date($segment['date_to'] ?? $from);
            if ($from > $to) throw new \DomainException('Úsek hodinové pásky nemá platné období.');
            $rate = in_array($purpose, ['ordinary_base', 'achieved_overtime'], true) ? ($segment['hourly_rate_minor'] ?? null) : null;
            if ($rate !== null && (!is_int($rate) || $rate <= 0)) throw new \DomainException('Hodinová sazba pásky není platná.');
            $segments[] = ['date_from' => $from, 'date_to' => $to, 'term_id' => $segment['term_id'] ?? null,
                'duration_units' => $units, 'quantity_text' => self::duration($units), 'rate_minor' => $rate,
                'monthly_gross_minor' => $segment['monthly_gross_minor'] ?? null,
                'rate_label' => self::rateLabel($source, $claim, $segment),
                'raw_numerator_minor' => $amount->numerator, 'raw_denominator' => $amount->denominator,
                'analytical_minor' => $amount->analyticalMinor()];
            $sum = $sum->plus($amount);
            $displaySum += $amount->analyticalMinor();
        }
        if ($purpose !== 'minimum_wage_top_up' && $sum->compare($raw) !== 0) throw new \DomainException('Rozpad hodinového nároku nesouhlasí s přesným úhrnem.');
        $previous = self::nonNegative($source, 'previous_payable_minor');
        $delta = self::integer($source, 'delta_amount_minor');
        if ($claim['payable_minor'] - $previous !== $delta) throw new \DomainException('Úplný hodinový nárok nesouhlasí s opravou.');
        return ['employment_id' => $employmentId, 'claim_id' => self::positive($source, 'claim_id'),
            'purpose' => $purpose, 'label' => $label, 'component_code' => $claim['component_code'],
            'work_period_start' => self::date($source['work_period_start'] ?? null), 'sequence_no' => self::positive($source, 'sequence_no'),
            'full_duration_units' => self::nonNegative($claim, 'duration_units'),
            'full_worked_duration_units' => self::nonNegative($claim, 'worked_duration_units'),
            'quantity_text' => self::duration($claim['duration_units']),
            'raw_numerator_minor' => $raw->numerator, 'raw_denominator' => $raw->denominator,
            'analytical_minor' => $raw->analyticalMinor(), 'payable_minor' => $claim['payable_minor'],
            'rounding_adjustment_minor' => $claim['payable_minor'] - $raw->analyticalMinor(),
            'segment_display_adjustment_minor' => $purpose === 'minimum_wage_top_up' ? null : $raw->analyticalMinor() - $displaySum,
            'previous_payable_minor' => $previous, 'delta_amount_minor' => $delta,
            'worked_duration_delta_units' => self::integer($source, 'worked_duration_delta_units'),
            'segment_role' => $purpose === 'minimum_wage_top_up' ? 'minimum_threshold' : 'claim', 'segments' => $segments,
            'minimum_assessment' => $purpose === 'minimum_wage_top_up' ? ($source['minimum_wage']['assessment_evidence'] ?? null) : null];
    }

    /** Staré dokumenty se nedopočítávají; u v3 se kontroluje pouze již uložený rozpis. */
    public static function validate(array $detail): void
    {
        self::positive($detail, 'claim_id');
        $raw = self::fraction($detail);
        if (self::integer($detail, 'analytical_minor') !== $raw->analyticalMinor()
            || self::integer($detail, 'payable_minor') - $raw->analyticalMinor() !== self::integer($detail, 'rounding_adjustment_minor')) throw new \DomainException('Uložené zaokrouhlení hodinové pásky nesouhlasí.');
        if (!is_array($detail['segments'] ?? null)) throw new \DomainException('Chybí uložený rozpad hodinové pásky.');
        $sum = new PayrollExactFraction(0);
        $displaySum = 0;
        foreach ($detail['segments'] as $segment) {
            $sum = $sum->plus(self::fraction($segment));
            $displaySum += self::integer($segment, 'analytical_minor');
            if (self::fraction($segment)->analyticalMinor() !== self::integer($segment, 'analytical_minor')
                || self::duration(self::nonNegative($segment, 'duration_units')) !== ($segment['quantity_text'] ?? null)) throw new \DomainException('Uložený úsek hodinové pásky nesouhlasí.');
        }
        if (($detail['segment_role'] ?? null) !== 'minimum_threshold' && ($sum->compare($raw) !== 0 || self::integer($detail, 'segment_display_adjustment_minor') !== $raw->analyticalMinor() - $displaySum)) throw new \DomainException('Uložený rozpad hodinové pásky nesouhlasí s úhrnem.');
        if (self::nonNegative($detail, 'payable_minor') - self::nonNegative($detail, 'previous_payable_minor') !== self::integer($detail, 'delta_amount_minor')
            || self::duration(self::nonNegative($detail, 'full_duration_units')) !== ($detail['quantity_text'] ?? null)) throw new \DomainException('Uložený hodinový úhrn pásky nesouhlasí.');
    }

    private static function sourceClaim(array $source): array
    {
        if (($source['schema_version'] ?? null) !== 'hourly-payslip-source.v1' || !is_array($source['full_claim'] ?? null)) throw new \DomainException('Chybí zmrazený podklad hodinové pásky.');
        $claim = $source['full_claim'];
        if (($claim['ready'] ?? false) !== true || !is_string($claim['purpose'] ?? null) || ($source['purpose'] ?? null) !== $claim['purpose']) throw new \DomainException('Nárok hodinové pásky není úplný.');
        self::label($claim['purpose']);
        self::nonNegative($claim, 'payable_minor');
        if (self::fraction($claim)->analyticalMinor() !== self::integer($claim, 'analytical_minor')) throw new \DomainException('Analytická částka nároku neodpovídá přesnému podkladu.');
        return $claim;
    }

    private static function rateLabel(array $source, array $claim, array $segment): ?string
    {
        $purpose = $claim['purpose'];
        if ($purpose === 'minimum_wage_top_up') return 'Zákonné minimum';
        if (isset($segment['monthly_gross_minor'])) return $purpose === 'ordinary_base' ? 'Hrazená část měsíčního fondu' : 'Přesčas při měsíční mzdě';
        if (is_int($segment['applied_average_hourly_minor'] ?? null)) return 'Použitý průměr ' . self::money($segment['applied_average_hourly_minor']) . ' Kč/h';
        if (!str_starts_with($purpose, 'premium_')) return null;
        $kind = substr($purpose, 8);
        $policies = $source['calculation_context']['policy_versions'] ?? [];
        foreach ($policies as $policy) {
            if (($policy['id'] ?? null) !== ($segment['policy_version_id'] ?? null)
                || ($policy['date_from'] ?? '9999') > ($segment['date_from'] ?? '') || ($policy['date_to'] ?? '') < ($segment['date_to'] ?? '9999')) continue;
            $rate = $policy['rates'][$kind] ?? null;
            if (!is_array($rate)) return null;
            if (is_int($rate['agreed_fixed_hourly_minor'] ?? null)) return 'Sjednaná pevná sazba ' . self::money($rate['agreed_fixed_hourly_minor']) . ' Kč/h' . (in_array($kind, ['night', 'weekend'], true) ? '' : ', nejméně zákonná sazba');
            $bp = $rate['agreed_rate_bp'] ?? $rate['statutory_rate_bp'] ?? null;
            if (!is_int($bp)) return null;
            if (!in_array($kind, ['night', 'weekend'], true)) $bp = max($bp, $rate['statutory_rate_bp'] ?? 0);
            return self::money($bp) . ' % ' . (($rate['statutory_basis'] ?? null) === 'minimum' ? 'hodinového minima' : 'průměrného výdělku')
                . (($segment['factors'] ?? 1) > 1 ? ', ' . $segment['factors'] . ' vlivy' : '');
        }
        return null;
    }

    private static function money(int $minor): string { return intdiv($minor, 100) . ',' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT); }
    private static function fraction(array $row): PayrollExactFraction { return new PayrollExactFraction(self::nonNegative($row, 'raw_numerator_minor'), self::positive($row, 'raw_denominator')); }
    private static function integer(array $row, string $key): int { if (!is_int($row[$key] ?? null)) throw new \DomainException('Hodinová páska nemá celočíselný údaj ' . $key . '.'); return $row[$key]; }
    private static function nonNegative(array $row, string $key): int { $value = self::integer($row, $key); if ($value < 0) throw new \DomainException('Hodinová páska má záporný údaj ' . $key . '.'); return $value; }
    private static function positive(array $row, string $key): int { $value = self::integer($row, $key); if ($value <= 0) throw new \DomainException('Hodinová páska nemá kladný údaj ' . $key . '.'); return $value; }
    private static function date(mixed $value): string { if (!is_string($value) || !IsoDate::isValid($value)) throw new \DomainException('Hodinová páska nemá platné datum.'); return $value; }
}
