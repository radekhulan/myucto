<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanecké a zdravotní benefity PAMICA (Z23, Z24) se dřív nepřevedly vůbec: měsíc
 * počítaný MyÚčtem pak vykázal osvobozený příjem (10289) nižší o celý benefit.
 */
final class PohodaPayrollBenefitCatalogTest extends TestCase
{
    private const UNTAXED = ['RelTpDan' => '4', 'JeSoc' => '0', 'JeZdr' => '0'];

    public function testEmployeeBenefitsGoToTheDefaultBenefitComponents(): void
    {
        self::assertSame('REKREACE_VOLNY_CAS', PohodaPayrollCatalog::component('Z23', 'Multisport karta', true, self::UNTAXED)['code']);
        self::assertSame('ZDRAVOTNI_BENEFIT', PohodaPayrollCatalog::component('Z24', 'Zdravotní benefity', false, self::UNTAXED)['code']);
        self::assertSame(
            'unknown',
            PohodaPayrollCatalog::component('Z23', 'Benefit', false, ['RelTpDan' => '1', 'JeSoc' => '1', 'JeZdr' => '1'])['meaning'],
            'Zdaněný „benefit" není osvobozené plnění, zařazení zůstává na účetní.',
        );
    }
}
