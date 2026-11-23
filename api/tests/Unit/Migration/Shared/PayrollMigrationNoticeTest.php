<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\PayrollMigrationNotice;
use PHPUnit\Framework\TestCase;

/**
 * Upozornění průvodců převodem na mzdy, které se nepřevedou: důvod podle licence a modulu
 * Mzdy, souhrn přes agendy, text s počtem zaměstnanců a měsíci a zápis do protokolu.
 */
final class PayrollMigrationNoticeTest extends TestCase
{
    private const SUMMARY = ['employees' => 4, 'months' => 10, 'payslips' => 40, 'first' => '2026-01', 'last' => '2026-10'];

    public function testReasonFollowsLicenceFirstThenModule(): void
    {
        self::assertSame(PayrollMigrationNotice::REASON_UNLICENSED, PayrollMigrationNotice::reasonFor(false, false));
        self::assertSame(PayrollMigrationNotice::REASON_UNLICENSED, PayrollMigrationNotice::reasonFor(false, true));
        self::assertSame(PayrollMigrationNotice::REASON_MODULE_DISABLED, PayrollMigrationNotice::reasonFor(true, false));
        self::assertNull(PayrollMigrationNotice::reasonFor(true, true));
    }

    public function testUnlicensedNoticeNamesEmployeesMonthsRemedyAndCompleteAccounting(): void
    {
        $notice = PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_UNLICENSED, self::SUMMARY);

        self::assertNotNull($notice);
        self::assertSame(['warning', PayrollMigrationNotice::CODE, PayrollMigrationNotice::REASON_UNLICENSED], [$notice['level'], $notice['code'], $notice['reason']]);
        self::assertSame(['employees' => 4, 'months' => 10, 'first' => '2026-01', 'last' => '2026-10', 'buy_url' => PayrollMigrationNotice::BUY_URL], $notice['context']);
        self::assertStringContainsString('Export obsahuje mzdy 4 zaměstnanců za 01/2026 až 10/2026 (10 měsíců), které se do MyÚčta nepřevedou', $notice['message']);
        self::assertStringContainsString('licence nezahrnuje mzdový doplněk', $notice['message']);
        self::assertStringContainsString('účetnictví proto zůstává úplné', $notice['message']);
    }

    public function testDisabledModuleNoticePointsToCompanySettings(): void
    {
        $notice = PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_MODULE_DISABLED, self::SUMMARY);

        self::assertNotNull($notice);
        self::assertNull($notice['context']['buy_url']);
        self::assertStringContainsString('firma nemá zapnutý modul Mzdy. Zapněte Mzdy v Nastavení firmy', $notice['message']);
    }

    public function testNoNoticeWithoutReasonOrPayroll(): void
    {
        self::assertNull(PayrollMigrationNotice::build(null, self::SUMMARY));
        self::assertNull(PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_UNLICENSED, null));
        self::assertNull(PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_UNLICENSED, ['employees' => 3, 'months' => 0, 'first' => null, 'last' => null]));
    }

    public function testCombineAcrossAgendasSumsMonthsAndKeepsRange(): void
    {
        self::assertSame(['employees' => 5, 'months' => 22, 'first' => '2025-01', 'last' => '2026-10'], PayrollMigrationNotice::combine([
            ['employees' => 5, 'months' => 12, 'first' => '2025-01', 'last' => '2025-12'],
            null,
            ['employees' => 4, 'months' => 0, 'first' => null, 'last' => null],
            self::SUMMARY,
        ]));
        self::assertNull(PayrollMigrationNotice::combine([null]));
    }

    /** Mzdy vedené přímo v POHODĚ: souhrn i upozornění nesou zdroj; u PAMICA klíč chybí. */
    public function testPohodaPayrollSourceIsCarriedToNotice(): void
    {
        $combined = PayrollMigrationNotice::combine([['source' => 'pamica'] + self::SUMMARY, ['source' => PayrollMigrationNotice::SOURCE_POHODA] + self::SUMMARY]);
        self::assertSame(PayrollMigrationNotice::SOURCE_POHODA, $combined['source'] ?? null);
        self::assertArrayNotHasKey('source', (array) PayrollMigrationNotice::combine([['source' => 'pamica'] + self::SUMMARY]));
        $notice = PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_MODULE_DISABLED, ['source' => PayrollMigrationNotice::SOURCE_POHODA] + self::SUMMARY);
        self::assertNotNull($notice);
        self::assertSame(PayrollMigrationNotice::SOURCE_POHODA, $notice['context']['source']);
        self::assertStringContainsString('(10 měsíců, vedené přímo v POHODĚ v agendě Mzdy)', $notice['message']);
    }

    public function testWarnWritesProtocolWarningAndAttachLeavesPayloadWithoutNotice(): void
    {
        $protocol = new ImportProtocol('dry_run');
        $protocol->begin('preflight');
        PayrollMigrationNotice::warn($protocol, 'preflight', PayrollMigrationNotice::build(PayrollMigrationNotice::REASON_UNLICENSED, self::SUMMARY));
        $protocol->finish('preflight');
        PayrollMigrationNotice::warn($protocol, 'preflight', null);

        self::assertSame('completed_with_warnings', $protocol->status());
        $messages = $protocol->toArray()['steps'][0]['messages'];
        self::assertCount(1, $messages);
        self::assertSame([PayrollMigrationNotice::CODE, PayrollMigrationNotice::REASON_UNLICENSED, 4], [$messages[0]['code'], $messages[0]['context']['reason'], $messages[0]['context']['employees']]);

        self::assertSame(['a' => 1], PayrollMigrationNotice::attach(['a' => 1], null));
        self::assertArrayHasKey(PayrollMigrationNotice::RESPONSE_KEY, PayrollMigrationNotice::attach([], ['code' => 'x']));
    }
}
