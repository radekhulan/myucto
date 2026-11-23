<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationGate;
use PHPUnit\Framework\TestCase;

/**
 * Závora přípravy měsíčního hlášení: konec období se posuzuje stejně jako
 * kontrola 90 katalogu a účetní dostane jednu větu s datem, ne výčet kontrol.
 */
final class JmhzPreparationGateTest extends TestCase
{
    public function testPeriodIsFinishedOnlyAfterItsLastDay(): void
    {
        self::assertFalse(JmhzPreparationGate::periodFinished('2026-12', '2026-11-11'));
        self::assertFalse(JmhzPreparationGate::periodFinished('2026-12', '2026-12-31'));
        self::assertTrue(JmhzPreparationGate::periodFinished('2026-12', '2027-01-01'));
        self::assertTrue(JmhzPreparationGate::periodFinished('2026-04', '2026-11-11'));
    }

    public function testAvailableFromIsFirstDayOfTheFollowingMonth(): void
    {
        self::assertSame('2027-01-01', JmhzPreparationGate::availableFrom('2026-12'));
        self::assertSame('2026-03-01', JmhzPreparationGate::availableFrom('2026-02'));
    }

    public function testNotFinishedMessageSaysWhenInOneSentenceWithoutControlNumbers(): void
    {
        $message = JmhzPreparationGate::notFinishedMessage('2026-12');

        self::assertStringContainsString('Období 12/2026 ještě neskončilo', $message);
        self::assertStringContainsString('připravit ho půjde od 1. 1. 2027', $message);
        self::assertStringNotContainsString('kontrola', $message);
        self::assertStringNotContainsString('2026-12', $message);
    }
}
