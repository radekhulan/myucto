<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentSourceRule;
use PHPUnit\Framework\TestCase;

/**
 * PAMICA „Náhrada nezdaněná" (J03, J09): náhrada výdajů vyplacená nad čistou mzdu,
 * v číselníku bez daně a bez pojistného. PAMICA ji nedává do hrubé mzdy ani do úhrnů
 * hlášení (10286, 10289). Převod ji dřív neznal a vypsal mezi nepřevedenými položkami,
 * takže ve výplatě chyběla.
 *
 * Kód složky nerozhoduje: v jiné instalaci je J03 srážka za obědy. Rozhoduje název
 * spolu s příznaky číselníku, a když neodpovídají, položka zůstane neznámá.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollUntaxedReimbursementTest extends TestCase
{
    private const HEADER = 'Náhrada výdajů nepodléhající dani (Kč)';

    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_untaxed_' . bin2hex(random_bytes(5));
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

    public function testUntaxedReimbursementReachesTheWorkbook(): void
    {
        $month = PohodaPayrollConverter::read($this->file)->month('2026-03');
        $column = $month['columns'][self::HEADER] ?? null;

        self::assertSame('component', $column['meaning'] ?? null);
        self::assertSame(PohodaPayrollCatalog::UNTAXED_REIMBURSEMENT, $column['code'] ?? null);
        // J03 750 Kč a J09 250 Kč téže osoby v jednom sloupci.
        self::assertSame(1000.0, $this->row($month, '7101')[self::HEADER] ?? null);
        self::assertArrayNotHasKey('component:J03', $month['unconverted_items']);
        self::assertArrayNotHasKey('component:J09', $month['unconverted_items']);
    }

    /**
     * Stejný název se zdaněním nebo s pojistným je jiné plnění a převod ho nehádá.
     * Nemocenská dávka bez daně je dávka, ne náhrada výdajů.
     */
    public function testOnlyTheUntaxedUninsuredCatalogRowCounts(): void
    {
        $month = PohodaPayrollConverter::read($this->file)->month('2026-03');

        self::assertSame(['code' => 'J13', 'name' => 'Náhrada nezdaněná', 'kind' => 'component', 'inputs' => 1, 'amount' => 300.0, 'hours' => 0.0],
            $month['unconverted_items']['component:J13'] ?? null);
        self::assertSame(['code' => 'J05', 'name' => 'Nemocenská dávka, jednorázová', 'kind' => 'component', 'inputs' => 1, 'amount' => 400.0, 'hours' => 0.0],
            $month['unconverted_items']['component:J05'] ?? null);
        self::assertCount(2, $month['unconverted_items']);
    }

    public function testCatalogDecidesByNameAndTaxFlagsNotByNumber(): void
    {
        $untaxed = ['RelTpDan' => '4', 'JeSoc' => '0', 'JeZdr' => '0'];

        self::assertSame(PohodaPayrollCatalog::UNTAXED_REIMBURSEMENT, PohodaPayrollCatalog::component('J03', 'Náhrada nezdaněná', false, $untaxed)['code']);
        self::assertSame(PohodaPayrollCatalog::UNTAXED_REIMBURSEMENT, PohodaPayrollCatalog::component('J09', 'Náhrada nezdaněná zadaná jednicově', false, $untaxed)['code']);
        // Jiná instalace: J03 je doplatek za obědy, srážka z čisté mzdy.
        self::assertSame('meal', PohodaPayrollCatalog::component('J03', 'Doplatek za obědy', false, $untaxed)['meaning']);
        // Bez řádku číselníku nejde daňový režim ověřit.
        self::assertSame('unknown', PohodaPayrollCatalog::component('J03', 'Náhrada nezdaněná', false)['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::component('J03', 'Náhrada nezdaněná', false, ['RelTpDan' => '1', 'JeSoc' => '0', 'JeZdr' => '0'])['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::component('J03', 'Náhrada nezdaněná', false, ['RelTpDan' => '4', 'JeSoc' => '1', 'JeZdr' => '0'])['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::component('Z20', 'Osvobozené příjmy', false, $untaxed)['meaning']);
    }

    /**
     * § 6 odst. 7 ZDP: příjmem není vůbec, takže ani v úhrnu 10286, ani mezi osvobozenými
     * příjmy 10289, bez pojistného, mimo průměr i exekuční srážky.
     */
    public function testDefaultComponentIsOutsideIncomeInsuranceAndReport(): void
    {
        $rows = [];
        foreach ((new PayrollComponentDefaults(CzechPayrollRulesets2026::provider()))->versions() as $version) {
            foreach ($version['rows'] as $row) {
                $rows[$row['code']] = $row;
            }
        }
        $component = $rows[PohodaPayrollCatalog::UNTAXED_REIMBURSEMENT] ?? null;
        self::assertNotNull($component, 'Nezdaněná náhrada nemá složku výchozího číselníku.');

        self::assertSame(['monetary', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded'], [
            $component['value_kind'], $component['tax_treatment'], $component['social_treatment'], $component['health_treatment'],
            $component['average_earning_treatment'], $component['enforcement_treatment'], $component['jmhz_treatment'],
        ]);
        self::assertSame(PayrollExemptionBasis::NotSubjectToTax->value, $component['exemption_basis']);
        self::assertNotSame('travel_reimbursement', $component['component_kind'], 'Převzatá náhrada se nesmí účtovat jako cestovné.');
        self::assertNull(JmhzComponentSourceRule::issueCode($component['jmhz_treatment'], null, $component['tax_treatment'], $component['component_kind']));
    }

    /** @param array<string,mixed> $month @return array<string,mixed> */
    private function row(array $month, string $personalNumber): array
    {
        foreach ($month['rows'] as $row) {
            if ((string) $row['Osobní číslo'] === $personalNumber) {
                return $row;
            }
        }
        self::fail("Řádek {$personalNumber} v sešitu chybí.");
    }

    /**
     * Březen 2026, jeden fiktivní vztah s měsíční mzdou: nezdaněná náhrada J03 a J09,
     * náhrada stejného jména se zdaněním (J13) a nemocenská dávka bez daně (J05).
     */
    private static function payrollXml(): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $untaxed = ['RelTpDan' => 4, 'JeSoc' => 0, 'JeZdr' => 0, 'RelMzSkp' => 9];
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda', 'RelTpDan' => 1, 'JeSoc' => 1, 'JeZdr' => 1]);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'J03', 'Nazev' => 'Náhrada nezdaněná'] + $untaxed);
        $row('sMZslozky', ['ID' => 3, 'Cislo' => 'J09', 'Nazev' => 'Náhrada nezdaněná zadaná jednicově'] + $untaxed);
        $row('sMZslozky', ['ID' => 4, 'Cislo' => 'J13', 'Nazev' => 'Náhrada nezdaněná', 'RelTpDan' => 1, 'JeSoc' => 0, 'JeZdr' => 0]);
        $row('sMZslozky', ['ID' => 5, 'Cislo' => 'J05', 'Nazev' => 'Nemocenská dávka, jednorázová'] + $untaxed);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '7101', 'Jmeno' => 'Test', 'Prijmeni' => 'Náhrada', 'DatNar' => '1990-01-01',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2020-01-01', 'TUvazek' => 40]);
        $row('MZ', ['ID' => 10, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => 3, 'HodFond' => 176, 'DnyFond2' => 22,
            'TUvazek' => 40, 'HodOdpra' => 176, 'DnyStSv' => 0, 'RefPoj' => 1, 'KcPrum' => 200, 'KcHrubaM' => 40000, 'KcCistaM' => 30000,
            'KcZaklM' => 40000, 'KcNahrN' => 1000]);
        $row('MZslozky', ['ID' => 100, 'RefAg' => 10, 'RefSlozka' => 1, 'KcMzda' => 40000, 'Hodnota1' => 40000]);
        $row('MZslozky', ['ID' => 101, 'RefAg' => 10, 'RefSlozka' => 2, 'KcMzda' => 750, 'Hodnota1' => 750]);
        $row('MZslozky', ['ID' => 102, 'RefAg' => 10, 'RefSlozka' => 3, 'KcMzda' => 250, 'Hodnota1' => 250]);
        $row('MZslozky', ['ID' => 103, 'RefAg' => 10, 'RefSlozka' => 4, 'KcMzda' => 300]);
        $row('MZslozky', ['ID' => 104, 'RefAg' => 10, 'RefSlozka' => 5, 'KcMzda' => 400]);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>';
    }
}
