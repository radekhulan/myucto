<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollRelations;
use PHPUnit\Framework\TestCase;

/**
 * Druh vztahu z druhu zaměstnání PAMICA (`RelDruhZ`). Jednatel (107) i dohoda o pracovní
 * činnosti (3) se dřív zakládali jako pracovní poměr: jednatel pak dostal úvazek 40 h,
 * pojistné pod rozhodným příjmem a hlášení se zastavilo na průměrném výdělku (10345),
 * který formulář člena orgánu (`cinnostKS`) nemá.
 */
final class PohodaPayrollRelationTypeTest extends TestCase
{
    public function testRelationTypeFollowsTheKindOfEmployment(): void
    {
        self::assertSame('employment', PohodaPayrollRelations::relationType(['RelDruhZ' => '0', 'JeDPP' => '0']));
        self::assertSame('dpc', PohodaPayrollRelations::relationType(['RelDruhZ' => '3', 'JeDPP' => '0']));
        self::assertSame('statutory_body', PohodaPayrollRelations::relationType(['RelDruhZ' => '107', 'JeDPP' => '0']));
        self::assertSame('dpp', PohodaPayrollRelations::relationType(['RelDruhZ' => '108', 'JeDPP' => '1']));
        self::assertSame('dpp', PohodaPayrollRelations::relationType(['RelDruhZ' => '0', 'JeDPP' => '1']), 'Příznak DPP má přednost.');
        self::assertSame('employment', PohodaPayrollRelations::relationType(['RelDruhZ' => '104', 'JeDPP' => '0']));
    }
}
