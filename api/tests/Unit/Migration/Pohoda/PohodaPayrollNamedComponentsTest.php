<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Payroll\Absence\VacationCompensationReturn;
use PHPUnit\Framework\TestCase;

/**
 * Položky katalogu PAMICA, jejichž význam plyne z názvu a daňových příznaků číselníku
 * (`RelTpDan`, `JeSoc`, `JeZdr`), ne z čísla: čísla si firmy přečíslovávají, takže se
 * klasifikace zkouší i s vymyšleným číslem. Bez řádku číselníku nebo s jinými příznaky
 * zůstane položka neznámá a převod ji vypíše.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollNamedComponentsTest extends TestCase
{
    private const WAGE = ['RelTpDan' => '1', 'JeSoc' => '1', 'JeZdr' => '1'];
    private const UNTAXED = ['RelTpDan' => '4', 'JeSoc' => '0', 'JeZdr' => '0'];

    public function testPersonalEvaluationIsABonusByNameAndFlags(): void
    {
        foreach (['C20', 'X41'] as $number) {
            $c = PohodaPayrollCatalog::component($number, 'Os. ohodnocení', false, self::WAGE);
            self::assertSame(['component', 'bonus', 'PAM_' . $number], [$c['meaning'], $c['kind'], $c['code']], $number);
        }
        self::assertSame('unknown', PohodaPayrollCatalog::component('C20', 'Os. ohodnocení', false)['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::component('C20', 'Os. ohodnocení', false, self::UNTAXED)['meaning']);
    }

    public function testWageCompensationEnteredAsTotalIsAGeneralCompensation(): void
    {
        $c = PohodaPayrollCatalog::component('J01', 'Náhrada celkovou částkou', false, self::WAGE);
        self::assertSame(['component', 'compensation', 'PAM_J01'], [$c['meaning'], $c['kind'], $c['code']]);
        self::assertSame('unknown', PohodaPayrollCatalog::component('J01', 'Náhrada celkovou částkou', false)['meaning']);
    }

    /** Proplacení nevyčerpané dovolené ve dnech i v hodinách jde tam, kam ho PAMICA hlásí: 10338. */
    public function testLeavePayoutGoesToTheVacationSettlementComponent(): void
    {
        foreach ([['J08', 'Proplacení nevyčerpané dovolené (dny)'], ['Q77', 'Proplacení nevyčerpané dovolené (dny)'], ['J11', 'Proplacení nevyčerpané dovolené (hod.)']] as [$number, $name]) {
            $c = PohodaPayrollCatalog::component($number, $name, false, self::WAGE);
            self::assertSame(['component', VacationCompensationReturn::SETTLEMENT_CODE], [$c['meaning'], $c['code']], $number);
        }
        self::assertSame('unknown', PohodaPayrollCatalog::component('J08', 'Proplacení nevyčerpané dovolené (dny)', false)['meaning']);
    }

    /** Doplatek do minima a přeplatek z ročního zúčtování nejsou mzdové složky. */
    public function testComputedOrSettledItemsAreDeliberatelyIgnored(): void
    {
        foreach (['Doplatek zdravotního pojištění do minima', 'Roční zúčtování - přeplatek na dani', 'Oprava ročního zúčtování - přeplatek na dani'] as $name) {
            self::assertSame('ignore', PohodaPayrollCatalog::component('Z99', $name, false, self::UNTAXED)['meaning'], $name);
            self::assertSame('unknown', PohodaPayrollCatalog::component('Z99', $name, false, self::WAGE)['meaning'], $name);
        }
        // Pravidelná záloha na mzdu: MyÚčto ji nevede, zůstává k ručnímu dořešení.
        self::assertSame('unknown', PohodaPayrollCatalog::component('L01', 'Řádná záloha', false, self::UNTAXED)['meaning']);
    }

    public function testMonthCarriesTheItemsInsteadOfReportingThem(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_named_' . bin2hex(random_bytes(5));
        mkdir($tmp . '/12345678_2026', 0755, true);
        $file = $tmp . '/12345678_2026/91_mzdy.xml';
        $xml = PohodaPayrollMealAllowanceAndSickDaysTest::payrollXml();
        $catalog = '<sMZslozky><ID>51</ID><Cislo>C20</Cislo><Nazev>Os. ohodnocení</Nazev><RelTpDan>1</RelTpDan><JeSoc>1</JeSoc><JeZdr>1</JeZdr></sMZslozky>'
            . '<sMZslozky><ID>52</ID><Cislo>J08</Cislo><Nazev>Proplacení nevyčerpané dovolené (dny)</Nazev><RelTpDan>1</RelTpDan><JeSoc>1</JeSoc><JeZdr>1</JeZdr></sMZslozky>'
            . '<sMZslozky><ID>53</ID><Cislo>Z03</Cislo><Nazev>Doplatek zdravotního pojištění do minima</Nazev><RelTpDan>4</RelTpDan><JeSoc>0</JeSoc><JeZdr>0</JeZdr></sMZslozky>'
            . '<MZslozky><ID>501</ID><RefAg>10</RefAg><RefSlozka>51</RefSlozka><KcMzda>2500</KcMzda></MZslozky>'
            . '<MZslozky><ID>502</ID><RefAg>10</RefAg><RefSlozka>52</RefSlozka><KcMzda>1736</KcMzda><Hodnota1>2</Hodnota1></MZslozky>'
            . '<MZslozky><ID>503</ID><RefAg>20</RefAg><RefSlozka>53</RefSlozka><KcMzda>300</KcMzda></MZslozky>';
        file_put_contents($file, str_replace('</mdbExport>', $catalog . '</mdbExport>', $xml));
        try {
            $month = PohodaPayrollConverter::read($file)->month('2026-03');
        } finally {
            @unlink($file);
            @rmdir($tmp . '/12345678_2026');
            @rmdir($tmp);
        }

        self::assertArrayNotHasKey('component:C20', $month['unconverted_items']);
        self::assertArrayNotHasKey('component:J08', $month['unconverted_items']);
        self::assertArrayNotHasKey('component:Z03', $month['unconverted_items']);
        $codes = [];
        foreach ($month['columns'] as $header => $column) {
            if ($column['code'] !== null) {
                $codes[$column['code']] = $header;
            }
        }
        self::assertArrayHasKey('PAM_C20', $codes);
        self::assertArrayHasKey(VacationCompensationReturn::SETTLEMENT_CODE, $codes);
        $first = $month['rows'][0]['Osobní číslo'] === '6101' ? $month['rows'][0] : $month['rows'][1];
        self::assertSame(2500.0, $first[$codes['PAM_C20']]);
        self::assertSame(1736.0, $first[$codes[VacationCompensationReturn::SETTLEMENT_CODE]]);
    }
}
