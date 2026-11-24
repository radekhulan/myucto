<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PayrollSubmissionCalendarTest extends TestCase
{
    public function testSummerHalfHourAfterMidnightIsStillTheCzechDay(): void
    {
        $clock = new MockClock('2026-10-08 00:30:00 Europe/Prague');

        self::assertSame('2026-10-08', PayrollSubmissionCalendar::today($clock->now()));
        self::assertSame(
            '2026-10-08T00:30:00+02:00',
            PayrollSubmissionCalendar::filledAt($clock->now()),
        );
        self::assertSame(
            '2026-10-07',
            $clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            'Předpoklad testu: UTC je v tu chvíli ještě předchozí den.',
        );
    }

    public function testWinterHalfHourAfterMidnightIsStillTheCzechDay(): void
    {
        $clock = new MockClock('2026-01-15 00:30:00 Europe/Prague');

        self::assertSame('2026-01-15', PayrollSubmissionCalendar::today($clock->now()));
        self::assertSame(
            '2026-01-15T00:30:00+01:00',
            PayrollSubmissionCalendar::filledAt($clock->now()),
        );
    }

    public function testUtcInstantIsConvertedToCzechDay(): void
    {
        $utc = new \DateTimeImmutable('2026-10-07 22:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-10-08', PayrollSubmissionCalendar::today($utc));
        self::assertSame('2026-10-08T00:30:00+02:00', PayrollSubmissionCalendar::filledAt($utc));
    }

    /**
     * `payroll_submissions.submitted_at` je v UTC. Podání odeslané po české
     * půlnoci patří dalšímu dni, i když je v UTC ještě předchozí den.
     */
    public function testStoredUtcSubmissionReadsAsCzechDayAcrossMidnight(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
        try {
            self::assertSame('2026-08-21', PayrollSubmissionCalendar::dayOfUtc('2026-08-20 22:30:00'));
            self::assertSame('2026-08-20', PayrollSubmissionCalendar::dayOfUtc('2026-08-20 21:59:59'));
            self::assertSame('2026-01-21', PayrollSubmissionCalendar::dayOfUtc('2026-01-20 23:30:00'));
            self::assertSame('2026-01-20', PayrollSubmissionCalendar::dayOfUtc('2026-01-20 22:59:59'));
            self::assertNull(PayrollSubmissionCalendar::dayOfUtc(null));
            self::assertNull(PayrollSubmissionCalendar::dayOfUtc(''));
            self::assertNull(PayrollSubmissionCalendar::dayOfUtc('nesmysl'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testEveryCzechCalendarDayReadsFromTheZoneNotFromTheServerDefault(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame(
                (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d'),
                PayrollSubmissionCalendar::today(),
            );
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testEnvelopeAcceptsCzechOffsetAndUtcFormsOnly(): void
    {
        foreach (['2026-10-08T00:30:00+02:00', '2026-10-07T22:30:00Z'] as $accepted) {
            self::assertSame($accepted, $this->envelope($accepted)->filledAt);
        }
        foreach ([
            '2026-10-08T00:30:00',
            '2026-10-08 00:30:00+02:00',
            '2026-10-08T00:30:00+0200',
            '2026-02-30T00:30:00+01:00',
            '2026-10-08',
        ] as $rejected) {
            try {
                $this->envelope($rejected);
                self::fail("Čas vyplnění {$rejected} musel být odmítnut.");
            } catch (JmhzXmlException $exception) {
                self::assertSame('jmhz_envelope_filled_at_invalid', $exception->validationCode);
            }
        }
    }

    private function envelope(string $filledAt): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            '01985E5E-4C3B-7000-8000-000000000001',
            [101 => '01985E5E-4C3B-7000-8000-000000000002'],
            $filledAt,
            'MyÚčto.cz',
            '1.0',
        );
    }
}
