<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentSourceRule;
use PHPUnit\Framework\TestCase;

/**
 * Stravenkový paušál (Z21, Z21a) a Sick days (V18) v sešitu převodu z PAMICA.
 *
 * Obojí převod dřív tiše zahodil: paušál chyběl ve výplatě i v úhrnech hlášení
 * (10286, 10289), Sick days chyběly ve mzdě i v souhrnu hodin měsíce.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollMealAllowanceAndSickDaysTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_meal_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/12345678_2026', 0755, true);
        $this->file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($this->file, self::payrollXml());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * Osvobozenou část paušálu spočítala PAMICA za směny, které eviduje (Hodnota3),
     * a nadlimitní část vede zvlášť (Hodnota4). Sešit je nese na dvou složkách.
     */
    public function testMealAllowanceIsSplitIntoExemptAndTaxablePart(): void
    {
        $columns = $this->month()['columns'];
        $exempt = $columns['Příspěvek na stravování - osvobozená část (Kč)'] ?? null;
        $taxable = $columns['Příspěvek na stravování - zdanitelná část (Kč)'] ?? null;

        self::assertSame('component', $exempt['meaning'] ?? null);
        self::assertSame(PohodaPayrollCatalog::MEAL_ALLOWANCE_EXEMPT, $exempt['code'] ?? null);
        self::assertSame('component', $taxable['meaning'] ?? null);
        self::assertSame(PohodaPayrollCatalog::MEAL_ALLOWANCE_TAXABLE, $taxable['code'] ?? null);

        self::assertSame(2478.0, $this->row('6101')['Příspěvek na stravování - osvobozená část (Kč)'] ?? null);
        self::assertArrayNotHasKey('Příspěvek na stravování - zdanitelná část (Kč)', $this->row('6101'));
        self::assertSame(2590.0, $this->row('6102')['Příspěvek na stravování - osvobozená část (Kč)'] ?? null);
        // Nadlimitní část paušálu 410 Kč a paušál bez osvobozeného limitu (Z21a) 500 Kč.
        self::assertSame(910.0, $this->row('6102')['Příspěvek na stravování - zdanitelná část (Kč)'] ?? null);
    }

    public function testMealAllowanceComponentsHaveTheStatutoryRegime(): void
    {
        $rows = [];
        foreach ((new PayrollComponentDefaults(CzechPayrollRulesets2026::provider()))->versions() as $version) {
            foreach ($version['rows'] as $row) {
                $rows[$row['code']] = $row;
            }
        }
        $exempt = $rows[PohodaPayrollCatalog::MEAL_ALLOWANCE_EXEMPT] ?? null;
        $taxable = $rows[PohodaPayrollCatalog::MEAL_ALLOWANCE_TAXABLE] ?? null;
        self::assertNotNull($exempt, 'Osvobozená část paušálu nemá složku výchozího číselníku.');
        self::assertNotNull($taxable, 'Zdanitelná část paušálu nemá složku výchozího číselníku.');

        // § 6 odst. 9 písm. b) ZDP: do limitu bez daně a bez pojistného, v JMHZ 10286 a 10289.
        self::assertSame(['benefit_meal', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'included'], [
            $exempt['component_kind'], $exempt['value_kind'], $exempt['frequency_kind'], $exempt['tax_treatment'],
            $exempt['social_treatment'], $exempt['health_treatment'], $exempt['average_earning_treatment'], $exempt['jmhz_treatment'],
        ]);
        self::assertNotNull($exempt['exemption_basis']);
        self::assertTrue(JmhzComponentSourceRule::belongsOutsideWageBreakdown('included', 'exempt', $exempt['component_kind']));

        // Nad limit je to běžný zdanitelný příjem a vyměřovací základ obou pojistných.
        self::assertSame(['monetary', 'one_off', 'included', 'included', 'included', 'included'], [
            $taxable['value_kind'], $taxable['frequency_kind'], $taxable['tax_treatment'],
            $taxable['social_treatment'], $taxable['health_treatment'], $taxable['jmhz_treatment'],
        ]);
        self::assertSame(
            PayrollComponentJmhzMappingDefaults::targetForCode(PohodaPayrollCatalog::TAXABLE_MEAL),
            PayrollComponentJmhzMappingDefaults::targetForCode(PohodaPayrollCatalog::MEAL_ALLOWANCE_TAXABLE),
            'Zdanitelná část stravování má v hlášení jedno zařazení, ať je nepeněžní, nebo peněžní.',
        );
    }

    /**
     * Sick days = placené volno nad rámec zákona, PAMICA za ně platí průměr jako za V03.
     * Hodiny jdou do téhož sloupce placeného volna, aby měsíc neměl díru v hodinách
     * a převod za ně dopočítal náhradu.
     */
    public function testSickDaysArePaidLeaveHours(): void
    {
        $column = $this->month()['columns']['Placené volno (h)'] ?? null;

        self::assertSame('obstacle_employee_hours', $column['meaning'] ?? null);
        self::assertSame(15.0, $this->row('6101')['Placené volno (h)'] ?? null, 'Sick days 7,5 h + placené volno V03 7,5 h.');
        self::assertSame(7.5, $this->row('6102')['Placené volno (h)'] ?? null);
    }

    /** @return array<string,mixed> */
    private function month(): array
    {
        return PohodaPayrollConverter::read($this->file)->month('2026-03');
    }

    /** @return array<string,mixed> */
    private function row(string $personalNumber): array
    {
        foreach ($this->month()['rows'] as $row) {
            if ((string) $row['Osobní číslo'] === $personalNumber) {
                return $row;
            }
        }
        self::fail("Řádek {$personalNumber} v sešitu chybí.");
    }

    /**
     * Březen 2026, dva fiktivní vztahy s měsíční mzdou. 6101: paušál pod limitem,
     * Sick days a placené volno, neznámá nepřítomnost a neznámá složka. 6102: paušál
     * s nadlimitní částí, paušál bez osvobozeného limitu, rodičovská s daty.
     */
    public static function payrollXml(): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V18', 'Nazev' => 'Sick days']);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'V03', 'Nazev' => 'Placené volno']);
        $row('sMZneprit', ['ID' => 3, 'Cislo' => 'V99', 'Nazev' => 'Firemní volno']);
        $row('sMZneprit', ['ID' => 4, 'Cislo' => 'H08', 'Nazev' => 'Rodičovská dovolená']);
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda']);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'Z21', 'Nazev' => 'Stravenkový paušál']);
        $row('sMZslozky', ['ID' => 3, 'Cislo' => 'Z21a', 'Nazev' => 'Stravenkový paušál bez osvobozeného limitu']);
        $row('sMZslozky', ['ID' => 4, 'Cislo' => 'Z20', 'Nazev' => 'Osvobozené příjmy']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        foreach ([1 => '6101', 2 => '6102'] as $id => $number) {
            $row('ZAM', ['ID' => $id, 'OsCislo' => $number, 'Jmeno' => 'Test', 'Prijmeni' => "Paušál{$id}", 'DatNar' => '1990-01-0' . $id,
                'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
            $row('ZAMpomer', ['ID' => $id, 'RefZAM' => $id, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2020-01-01', 'TUvazek' => 37.5]);
            $row('MZ', ['ID' => 10 * $id, 'RefZAM' => $id, 'RefPomer' => $id, 'Rok' => 2026, 'RelMes' => 3, 'HodFond' => 165, 'DnyFond2' => 22,
                'TUvazek' => 37.5, 'HodOdpra' => 150, 'DnyStSv' => 0, 'RefPoj' => 1, 'KcPrum' => 200, 'KcHrubaM' => 40000, 'KcCistaM' => 30000,
                'KcZaklM' => 40000]);
            $row('MZslozky', ['ID' => 100 * $id, 'RefAg' => 10 * $id, 'RefSlozka' => 1, 'KcMzda' => 40000, 'Hodnota1' => 40000]);
        }
        // 6101: 20 směn × 123,90 Kč, celé pod limitem 129,50 Kč za směnu.
        $row('MZslozky', ['ID' => 101, 'RefAg' => 10, 'RefSlozka' => 2, 'KcMzda' => 2478, 'Hodnota1' => 123.9, 'Hodnota3' => 20]);
        $row('MZslozky', ['ID' => 102, 'RefAg' => 10, 'RefSlozka' => 4, 'KcMzda' => 1000]);
        $row('MZneprit', ['ID' => 103, 'RefAg' => 10, 'RefSlozka' => 1, 'HodPrac' => 7.5, 'KcNahr' => 1500,
            'DatZac' => '2026-03-10', 'DatKon' => '2026-03-10']);
        $row('MZneprit', ['ID' => 104, 'RefAg' => 10, 'RefSlozka' => 2, 'HodPrac' => 7.5, 'KcNahr' => 1500,
            'DatZac' => '2026-03-11', 'DatKon' => '2026-03-11']);
        $row('MZneprit', ['ID' => 105, 'RefAg' => 10, 'RefSlozka' => 3, 'HodPrac' => 8, 'KcNahr' => 800,
            'DatZac' => '2026-03-12', 'DatKon' => '2026-03-12']);
        // 6102: 20 směn × 150 Kč, nad limit 20 × 20,50 = 410 Kč; k tomu Z21a 500 Kč.
        $row('MZslozky', ['ID' => 201, 'RefAg' => 20, 'RefSlozka' => 2, 'KcMzda' => 3000, 'Hodnota1' => 150, 'Hodnota3' => 20, 'Hodnota4' => 410]);
        $row('MZslozky', ['ID' => 202, 'RefAg' => 20, 'RefSlozka' => 3, 'KcMzda' => 500, 'Hodnota1' => 25, 'Hodnota3' => 20]);
        $row('MZslozky', ['ID' => 203, 'RefAg' => 20, 'RefSlozka' => 4, 'KcMzda' => 500]);
        $row('MZneprit', ['ID' => 204, 'RefAg' => 20, 'RefSlozka' => 1, 'HodPrac' => 7.5, 'KcNahr' => 1500,
            'DatZac' => '2026-03-20', 'DatKon' => '2026-03-20']);
        $row('MZneprit', ['ID' => 205, 'RefAg' => 20, 'RefSlozka' => 4, 'HodPrac' => 15, 'KcNahr' => 0,
            'DatZac' => '2026-03-01', 'DatKon' => '2026-03-31']);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>';
    }
}
