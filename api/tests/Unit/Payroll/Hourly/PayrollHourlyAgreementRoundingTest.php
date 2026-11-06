<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyAgreementRoundingTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function agreements(): iterable
    {
        yield 'DPP' => ['dpp'];
        yield 'DPČ' => ['dpc'];
    }

    #[DataProvider('agreements')]
    public function testAgreementRewardUsesCompanyRoundingAfterSummingEffectiveSegments(string $type): void
    {
        $calculator = new PayrollHourlyWageCalculator();
        $hashes = [];
        foreach ([[PayrollWageRounding::Ceil, 19600], [PayrollWageRounding::HalfUp, 19500]] as [$rounding, $payable]) {
            $context = $this->context($type, $rounding);
            $result = $calculator->calculate($context);
            self::assertTrue($result->complete);
            self::assertSame([], $result->blockers);
            $purposes = array_column($result->claims, 'purpose');
            self::assertNotContains('achieved_overtime', $purposes);
            self::assertNotContains('premium_overtime', $purposes);
            $base = array_values(array_filter($result->claims, static fn(array $claim): bool => $claim['purpose'] === 'ordinary_base'));
            self::assertCount(1, $base);
            $claim = $base[0];
            self::assertSame('MZDA_HODINOVA_ZAKLAD', $claim['component_code']);
            self::assertSame(3022, $claim['duration_units']);
            self::assertSame([1, 2], array_column($claim['segments'], 'term_id'));
            self::assertSame([18750, 20000], array_column($claim['segments'], 'hourly_rate_minor'));
            self::assertSame([38025, 30020], array_column($claim['segments'], 'raw_numerator_minor'));
            self::assertSame([4, 3], array_column($claim['segments'], 'raw_denominator'));
            // Přesný součet 95,0625 + 100,0666… Kč se zaokrouhlí až za měsíc.
            self::assertSame(234155, $claim['raw_numerator_minor']);
            self::assertSame(12, $claim['raw_denominator']);
            self::assertSame(19513, $claim['analytical_minor']);
            self::assertSame($rounding->value, $claim['rounding']);
            self::assertSame($payable, $claim['payable_minor']);
            self::assertSame($payable, array_sum(array_column($result->claims, 'payable_minor')));
            self::assertSame(hash('sha256', CanonicalJson::encode([
                ...get_object_vars($context), 'rounding' => $rounding->value,
                'calculator_version' => 'hourly-wage.v1',
            ])), $result->evidenceHash);
            $hashes[] = $result->evidenceHash;
        }
        self::assertNotSame($hashes[0], $hashes[1]);
    }

    private function context(string $type, PayrollWageRounding $rounding): PayrollHourlyWageContext
    {
        $terms = [
            ['id' => 1, 'row_version' => 1, 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-15',
                'remuneration_type' => 'hourly', 'hourly_rate_minor' => 18750, 'monthly_gross_minor' => null],
            ['id' => 2, 'row_version' => 1, 'effective_from' => '2026-06-16', 'effective_to' => null,
                'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000, 'monthly_gross_minor' => null],
        ];
        $segments = [
            ['date_from' => '2026-06-01', 'date_to' => '2026-06-15', 'worked_duration_units' => 1521, 'overtime_duration_units' => 0, 'worked_days' => null],
            ['date_from' => '2026-06-16', 'date_to' => '2026-06-30', 'worked_duration_units' => 1501, 'overtime_duration_units' => 0, 'worked_days' => null],
        ];
        $source = ['schema_version' => 'payroll-worked-time.v1', 'duration_units_per_hour' => 3000,
            'time_month_id' => 9, 'revision_no' => 1, 'row_version' => 3, 'status' => 'approved',
            'work_source' => 'manual_summary', 'period_start' => '2026-06-01',
            'manual_summary' => ['id' => 11, 'time_month_id' => 9, 'time_month_revision_no' => 1, 'segments' => $segments]];
        $source['source_sha256'] = hash('sha256', CanonicalJson::encode($source));
        $evidence = ['schema_version' => 'hourly-entitlements.v1', 'as_of' => '2026-06-30',
            'ruleset' => ['id' => 1, 'content_sha256' => str_repeat('a', 64)],
            'policy_versions' => [],
            'premium_segments' => array_map(static fn(array $segment): array => [
                'date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'night_duration_units' => 0, 'weekend_duration_units' => 0,
                'holiday_work_duration_units' => 0, 'difficult_exposures' => [],
            ], $segments),
            'nonworked_holidays' => [], 'holiday_time_off_grants' => []];
        return new PayrollHourlyWageContext(7, '2026-06-01', $type, $terms, $segments, $source, $rounding, entitlementContext: $evidence);
    }
}
