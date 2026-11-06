<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollHourlyEvidenceBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyEvidenceBodyTest extends TestCase
{
    public function testKeepsExactUnitsAndUnknownSeparateFromZeroAndDropsServerFields(): void
    {
        $input = self::body();
        $input['premium_segments'][0]['night_duration_units'] = 53;
        $input['premium_segments'][0]['weekend_duration_units'] = null;
        $input['premium_segments'][0]['policy_version_id'] = 123;
        $input['prior_consumed_duration_units'] = 999;
        $result = PayrollHourlyEvidenceBody::normalize($input, '2026-06-01');
        self::assertSame(53, $result['premium_segments'][0]['night_duration_units']);
        self::assertNull($result['premium_segments'][0]['weekend_duration_units']);
        self::assertSame(0, $result['premium_segments'][0]['holiday_work_duration_units']);
        self::assertArrayNotHasKey('policy_version_id', $result['premium_segments'][0]);
        self::assertArrayNotHasKey('prior_consumed_duration_units', $result);
    }

    public function testHolidaySelectionsNeverTrustClientBudget(): void
    {
        $input = self::body();
        $input['holiday_time_off_grants'][] = ['entitlement_id' => 12, 'entitlement_revision' => 3,
            'granted_on' => '2026-06-03', 'duration_units' => 53, 'absence_id' => 8,
            'available_duration_units' => 999999, 'average_hourly_minor' => 999999];
        $result = PayrollHourlyEvidenceBody::normalize($input, '2026-06-01');
        self::assertSame(['entitlement_id' => 12, 'entitlement_revision' => 3, 'granted_on' => '2026-06-03',
            'duration_units' => 53, 'absence_id' => 8], $result['holiday_time_off_grants'][0]);
    }

    public function testUnconfirmedGroupStaysUnknownForAutomaticAssessment(): void
    {
        $body = self::body();
        unset($body['nonworked_holidays']);
        $body['overtime_time_off_allocations'] = null;
        $result = PayrollHourlyEvidenceBody::normalize($body, '2026-06-01');
        self::assertNull($result['nonworked_holidays']);
        self::assertNull($result['overtime_time_off_allocations']);
        self::assertSame([], $result['holiday_time_off_grants']);
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidOrAmbiguousEvidence(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollHourlyEvidenceBody::normalize($body, '2026-06-01');
    }

    public static function invalidBodies(): iterable
    {
        $body = self::body();
        $body['nonworked_holidays'] = 'nic';
        yield 'neplatný seznam nároků' => [$body];
        $body = self::body();
        $body['premium_segments'][0]['date_to'] = '2026-06-31';
        yield 'neexistující den' => [$body];
        $body = self::body();
        $body['premium_segments'][0]['night_duration_units'] = 0.5;
        yield 'nepřesné jednotky' => [$body];
        $body = self::body();
        $body['premium_segments'][] = $body['premium_segments'][0];
        yield 'překryv úseků' => [$body];
        $body = self::body();
        $body['nonworked_holidays'] = [['date' => '2026-05-01', 'duration_units' => 53]];
        yield 'jiné období' => [$body];
        $body = self::body();
        $body['premium_segments'][0]['overtime_work_days'] = [
            ['date' => '2026-06-03', 'duration_units' => 50], ['date' => '2026-06-03', 'duration_units' => 3]];
        yield 'duplicitní den' => [$body];
    }

    private static function body(): array
    {
        return ['premium_segments' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30',
            'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0,
            'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []]],
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
    }
}
