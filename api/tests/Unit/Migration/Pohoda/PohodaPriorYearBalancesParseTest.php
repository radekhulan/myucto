<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;
use PHPUnit\Framework\TestCase;

/**
 * Kniha pohledávek pro opravu úhrad minulých let podle zůstatků: CSV `číslo dokladu;zbývá`
 * s hlavičkou, BOM, desetinnou čárkou a mezerami v částkách.
 */
final class PohodaPriorYearBalancesParseTest extends TestCase
{
    public function testParsesCzechAmountsHeaderAndBom(): void
    {
        $csv = "\u{FEFF}Číslo dokladu;Zbývá uhradit\r\n"
            . "2501001;4 840,00\r\n"
            . "\r\n"
            . "2501002;1\u{00A0}234\u{00A0}567,5\r\n"
            . "\"OD0001\";-2 709,40;\n"
            . "2501003;0\n"
            . "2501004;12.5\n";

        self::assertSame(
            ['2501001' => 4840.0, '2501002' => 1234567.5, 'OD0001' => -2709.4, '2501003' => 0.0, '2501004' => 12.5],
            PohodaPriorYearPaymentRepair::parseBalances($csv),
        );
    }

    public function testWithoutHeaderFirstRowIsData(): void
    {
        self::assertSame(['25FV0098' => 400.0], PohodaPriorYearPaymentRepair::parseBalances("25FV0098;400\n"));
    }

    public function testRejectsDuplicateDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('podruhé');
        PohodaPriorYearPaymentRepair::parseBalances("2501001;100\n2501001;200\n");
    }

    public function testRejectsInvalidAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('řádek 3');
        PohodaPriorYearPaymentRepair::parseBalances("Číslo;Zbývá\n2501001;100\n2501002;sto\n");
    }
}
