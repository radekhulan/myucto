<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Sick day (V18) nebo placené volno (V03), za které PAMICA nic nezaplatila, se převádí jako
 * neplacené volno (V04). Dřív za něj MyÚčto dopočítalo průměr a vyměřovací základ vyšel
 * o celou denní náhradu vyšší než v podání, které ČSSZ přijala.
 */
final class PohodaPayrollUnpaidSickDayTest extends TestCase
{
    public function testPaidLeaveWithoutCompensationIsUnpaidLeave(): void
    {
        $sickDays = ['Cislo' => 'V18', 'Nazev' => 'Sick days'];

        self::assertSame('V04', PohodaPayrollCatalog::effectiveAbsenceNumber($sickDays, ['HodPrac' => '8', 'KcNahr' => '0']));
        self::assertSame('V18', PohodaPayrollCatalog::effectiveAbsenceNumber($sickDays, ['HodPrac' => '8', 'KcNahr' => '1821']));
        self::assertSame('V04', PohodaPayrollCatalog::effectiveAbsenceNumber(['Cislo' => 'V03', 'Nazev' => 'Placené volno'], ['HodPrac' => '2', 'KcNahr' => '0']));
        self::assertSame('V03', PohodaPayrollCatalog::effectiveAbsenceNumber(['Cislo' => 'V03', 'Nazev' => 'Lékař'], ['HodPrac' => '2', 'KcNahr' => '0']), 'Lékař není placené volno.');
        self::assertSame('V01', PohodaPayrollCatalog::effectiveAbsenceNumber(['Cislo' => 'V01', 'Nazev' => 'Dovolená'], ['HodPrac' => '8', 'KcNahr' => '0']));
    }
}
