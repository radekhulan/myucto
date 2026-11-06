<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use PHPUnit\Framework\TestCase;

/**
 * Převzaté úhrny ze mzdy PAMICA (`MZ` v `91_mzdy.xml`) jako v podaném měsíčním hlášení:
 * čistá mzda včetně náhrady mzdy za nemoc (JMHZ 10344) a vyměřovací základ jen v měsíci
 * s účastí na pojištění (10477), příjem nepojištěné dohody jako 10476. Syntetická data.
 */
final class PayrollMigrationReferenceTotalsPamicaTest extends TestCase
{
    public function testNetIncludesSickPayCompensation(): void
    {
        $totals = PayrollMigrationReferenceTotals::fromPohodaMz([
            'RelMes' => '1', 'RefZAM' => '7', 'RefPomer' => '9', 'JeSocPP' => '1', 'DnyKal' => '31',
            'KcHrubaM' => '28309', 'KcSocZak' => '28309', 'KcCistaM' => '23335', 'KcNahrNem' => '7993', 'KcVyuct' => '32480',
        ], 2026);

        self::assertSame(3132800, $totals->netMinor);
        self::assertSame(2830900, $totals->socialBaseMinor);
        self::assertSame(2830900, $totals->facts->uninsuredIncomeMinor);
    }

    public function testMonthOnlyWithSickPayHasNet(): void
    {
        $totals = PayrollMigrationReferenceTotals::fromPohodaMz([
            'RelMes' => '3', 'RefZAM' => '7', 'RefPomer' => '9', 'JeSocPP' => '1', 'DnyKal' => '31', 'KcNahrNem' => '986',
        ], 2026);

        self::assertSame(98600, $totals->netMinor);
    }

    public function testUninsuredAgreementHasNoAssessmentBase(): void
    {
        $totals = PayrollMigrationReferenceTotals::fromPohodaMz([
            'RelMes' => '1', 'RefZAM' => '3', 'RefPomer' => '4', 'JeSocPP' => '0', 'DnyKal' => '31',
            'KcHrubaM' => '11500', 'KcSocZak' => '11500', 'KcZdrZak' => '11500', 'KcCistaM' => '11500',
        ], 2026);

        self::assertSame(0, $totals->socialBaseMinor);
        self::assertSame(1150000, $totals->facts->uninsuredIncomeMinor);
        self::assertSame(1150000, $totals->netMinor);
    }

    public function testExportWithoutParticipationFlagKeepsBase(): void
    {
        $totals = PayrollMigrationReferenceTotals::fromPohodaMz([
            'RelMes' => '2', 'RefZAM' => '1', 'RefPomer' => '1', 'KcSocZak' => '30000', 'KcCistaM' => '23000',
        ], 2026);

        self::assertSame(3000000, $totals->socialBaseMinor);
    }
}
