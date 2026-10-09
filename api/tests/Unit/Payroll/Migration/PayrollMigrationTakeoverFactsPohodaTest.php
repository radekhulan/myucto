<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use PHPUnit\Framework\TestCase;

/**
 * Převzatá mzda z PAMICA nese vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb. (`MZ.NahrDoby`).
 * Bez nich rozhodné období NEMPRI u měsíce bez příjmu (rodičovská) blokovalo jako neznámé.
 * Syntetická data.
 */
final class PayrollMigrationTakeoverFactsPohodaTest extends TestCase
{
    public function testSicknessExcludedDaysFromPamicaPayslip(): void
    {
        // Celý měsíc rodičovské: vyloučená doba důchodového pojištění 0, vyloučené dny nemocenského 31.
        $parental = PayrollMigrationTakeoverFacts::fromPohodaMz(['RelMes' => '3', 'JeSocPP' => '1', 'DnyKal' => '31', 'NahrDoby' => '31', 'NahrDobyDP' => '0']);
        self::assertSame(31, $parental->sicknessExcludedDays);
        self::assertSame(0, $parental->excludedDays);

        $sick = PayrollMigrationTakeoverFacts::fromPohodaMz(['RelMes' => '3', 'JeSocPP' => '1', 'DnyKal' => '31', 'NahrDoby' => '5', 'NahrDobyDP' => '5']);
        self::assertSame(5, $sick->sicknessExcludedDays);
        self::assertSame(5, $sick->toColumns()['sickness_excluded_days'] ?? null);

        self::assertNull(PayrollMigrationTakeoverFacts::fromPohodaMz(['RelMes' => '3', 'JeSocPP' => '1', 'DnyKal' => '31'])->sicknessExcludedDays,
            'Export bez sloupce údaj nevydal.');
    }
}
