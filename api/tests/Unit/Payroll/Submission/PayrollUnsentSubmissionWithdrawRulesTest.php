<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollUnsentSubmissionWithdrawService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Které podání jde „Zrušit přípravu“: jen řádné měsíční hlášení, které se
 * ještě neodesílalo. Odeslané se opravuje opravným hlášením.
 */
final class PayrollUnsentSubmissionWithdrawRulesTest extends TestCase
{
    public function testPreparedUnsentRegularMonthlyReportCanBeWithdrawn(): void
    {
        foreach (['draft', 'validated', 'prepared', 'ready'] as $status) {
            self::assertNull(
                PayrollUnsentSubmissionWithdrawService::blockedReason('JMHZ25', 'regular', $status, 0, false),
                $status,
            );
        }
    }

    public function testJmhz27AndHealthPaymentOverviewCanBeWithdrawn(): void
    {
        self::assertNull(PayrollUnsentSubmissionWithdrawService::blockedReason('JMHZ27', 'regular', 'ready', 0, false));
        self::assertNull(PayrollUnsentSubmissionWithdrawService::blockedReason('PPZ_2026', 'regular', 'ready', 0, false));
        self::assertNotNull(PayrollUnsentSubmissionWithdrawService::blockedReason('PPZ_2026', 'regular', 'submitted', 0, false));
    }

    /** @return iterable<string,array{string,string,string,int,bool,string}> */
    public static function blocked(): iterable
    {
        yield 'odeslané' => ['JMHZ25', 'regular', 'submitted', 0, false, 'už bylo odesláno'];
        yield 'přijaté' => ['JMHZ25', 'regular', 'accepted', 0, false, 'opravným hlášením'];
        yield 'zamítnuté' => ['JMHZ25', 'regular', 'rejected', 0, false, 'zahoďte rozdělané odeslání'];
        yield 'už zrušené' => ['JMHZ25', 'regular', 'cancelled_in_time', 0, false, 'už bylo odesláno'];
        yield 's pokusem o odeslání' => ['JMHZ25', 'regular', 'ready', 1, false, 'má pokus o odeslání'];
        yield 'čeká v datové schránce' => ['JMHZ25', 'regular', 'ready', 0, true, 'čeká v datové schránce'];
        yield 'opravné hlášení' => ['JMHZ25', 'correction', 'ready', 0, false, 'jen u řádného měsíčního hlášení'];
        yield 'registrace' => ['REGZEC25', 'regular', 'ready', 0, false, 'jen u řádného měsíčního hlášení'];
    }

    #[DataProvider('blocked')]
    public function testEverythingElseIsRefusedWithAReason(
        string $agenda,
        string $kind,
        string $status,
        int $attempts,
        bool $queued,
        string $expected,
    ): void {
        $reason = PayrollUnsentSubmissionWithdrawService::blockedReason($agenda, $kind, $status, $attempts, $queued);

        self::assertNotNull($reason);
        self::assertStringContainsString($expected, $reason);
    }
}
