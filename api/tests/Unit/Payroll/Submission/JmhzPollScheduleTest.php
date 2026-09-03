<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzPollSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Rozvrh dotazů na protokol ČSSZ. Čistý výpočet — bez databáze i bez sítě,
 * protože právě tady se rozhoduje, jak často se smí obtěžovat protistrana
 * a kdy se to má vzdát.
 */
final class JmhzPollScheduleTest extends TestCase
{
    /**
     * Odstup roste, ale nikdy neklesne pod doporučení brány ani nepřeroste
     * hodinu. Pevný odstup by u rychlých podání zbytečně čekal a u pomalých
     * zbytečně bušil.
     */
    public function testDelayGrowsFromTheGatewayFloorAndStopsAtTheCeiling(): void
    {
        self::assertSame(60, JmhzPollSchedule::delaySeconds(0, 60));
        self::assertSame(120, JmhzPollSchedule::delaySeconds(1, 60));
        self::assertSame(240, JmhzPollSchedule::delaySeconds(2, 60));
        self::assertSame(3600, JmhzPollSchedule::delaySeconds(6, 60));
        self::assertSame(3600, JmhzPollSchedule::delaySeconds(50, 60));
    }

    /**
     * Doporučení brány je PODLAHA, ne rozvrh. Ptát se častěji, než ČSSZ říká,
     * by bylo nezdvořilé; ptát se řídčeji jen proto, že brána mlčí, by ubíralo
     * čas ze lhůty.
     */
    public function testGatewayIntervalRaisesTheFloorButNeverLowersIt(): void
    {
        self::assertSame(300, JmhzPollSchedule::delaySeconds(0, 300));
        self::assertSame(60, JmhzPollSchedule::delaySeconds(0, 5));
        self::assertSame(60, JmhzPollSchedule::delaySeconds(0, null));
        self::assertSame(60, JmhzPollSchedule::delaySeconds(-3, null));
    }

    public function testNextRetryIsWrittenInLedgerShapeAndInUtc(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 10:00:00', new \DateTimeZone('Europe/Prague'));

        // 10:00 v Praze je v létě 08:00 UTC; ledger má jednu osu času a je to UTC.
        self::assertSame('2026-08-15 08:01:00', JmhzPollSchedule::nextRetryAt($now, 0, 60));
        self::assertSame('2026-08-15 08:02:00', JmhzPollSchedule::nextRetryAt($now, 1, 60));
        self::assertSame('2026-08-15 08:01:00', JmhzPollSchedule::nextCloseAt($now, 0));
    }

    public function testFreshAttemptIsNotExhausted(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertNull(
            JmhzPollSchedule::exhaustedReason($now, '2026-08-15 11:00:00', 3),
        );
    }

    /**
     * Po uplynutí stropu stáří už protokol sám nepřijde. Zpráva musí být věta,
     * podle které se dá jednat — ne kód a ne „vypršel timeout".
     */
    public function testAttemptOlderThanTheAgeCapGivesAnActionableReason(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));
        $sentAt = $now->modify('-' . (JmhzPollSchedule::MAX_AGE_HOURS + 1) . ' hours');
        $reason = JmhzPollSchedule::exhaustedReason($now, $sentAt->format('Y-m-d H:i:s'), 3);

        self::assertIsString($reason);
        self::assertStringContainsString('ePortálu ČSSZ', $reason);
        self::assertStringContainsString((string) JmhzPollSchedule::MAX_AGE_HOURS, $reason);
    }

    /**
     * Těsně pod stropem stáří se ještě dotazujeme, těsně nad ním už ne — je
     * to tahle hranice, ne o hodinu vedle, na kterou se automatika spoléhá.
     */
    public function testAgeCapBoundaryIsExact(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));

        $justUnder = $now->modify('-' . (JmhzPollSchedule::MAX_AGE_HOURS - 1) . ' hours');
        self::assertNull(
            JmhzPollSchedule::exhaustedReason($now, $justUnder->format('Y-m-d H:i:s'), 3),
        );

        $justOver = $now->modify('-' . (JmhzPollSchedule::MAX_AGE_HOURS + 1) . ' hours');
        self::assertIsString(
            JmhzPollSchedule::exhaustedReason($now, $justOver->format('Y-m-d H:i:s'), 3),
        );
    }

    public function testAttemptCountCapIsTheSecondBrake(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));
        $reason = JmhzPollSchedule::exhaustedReason(
            $now,
            '2026-08-15 11:00:00',
            JmhzPollSchedule::MAX_ATTEMPTS,
        );

        self::assertIsString($reason);
        self::assertStringContainsString((string) JmhzPollSchedule::MAX_ATTEMPTS, $reason);
    }

    /**
     * Pokus bez času odeslání nemá jak zestárnout. Kdyby se bral jako čerstvý,
     * ptali bychom se na něj donekonečna — proto se vzdává hned a nahlas.
     */
    public function testAttemptWithoutSendTimeIsGivenUpInsteadOfPolledForever(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));

        self::assertIsString(JmhzPollSchedule::exhaustedReason($now, null, 0));
        self::assertIsString(JmhzPollSchedule::exhaustedReason($now, '   ', 0));
    }
}
