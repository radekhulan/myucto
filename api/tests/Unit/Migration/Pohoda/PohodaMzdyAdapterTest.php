<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PayrollBuiltin\PohodaMzdyAdapter;
use MyInvoice\Service\Migration\Pohoda\PayrollBuiltin\PohodaMzdyReconciliation;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollRelations;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSickness;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMzdy;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy vedené přímo v POHODĚ (agenda Mzdy bez pracovních poměrů a položek mezd) převedené
 * do tvaru PAMICA: rozpoznání zdroje, poměr z karty zaměstnance, složky a nepřítomnosti ze
 * sloupců mzdy, slevy z `ZAMpDet`, dovolená a nemoc z `ZAMpDov`, náhrada z `MZnahr`
 * a kontrola mezd samých se sebou.
 */
final class PohodaMzdyAdapterTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_mzdy_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testDetectsPohodaPayrollAndLeavesPamicaAlone(): void
    {
        $pohoda = SyntheticPohodaMzdy::write($this->tmp . '/pohoda');
        $pamica = SyntheticPohodaPayroll::write($this->tmp . '/pamica');

        self::assertSame(PohodaMzdyAdapter::VARIANT_POHODA, PohodaMzdyAdapter::detect($pohoda));
        self::assertSame(PohodaMzdyAdapter::VARIANT_POHODA, PohodaExport::payrollSummary($pohoda, SyntheticPohodaMzdy::YEAR)['source']);
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaMzdyAdapter::detect($pamica));
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaExport::payrollSummary($pamica, SyntheticPohodaPayroll::YEAR)['source']);
        self::assertNull(PohodaMzdyAdapter::prepare($pamica), 'Mzdy PAMICA se nepřevádějí, převod čte původní soubor.');
        self::assertFileDoesNotExist(dirname($pamica) . '/' . PohodaMzdyAdapter::ADAPTED_FILE);

        $prepared = PohodaMzdyAdapter::prepare($pohoda);
        self::assertNotNull($prepared);
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaMzdyAdapter::detect($prepared['file']), 'Převedený soubor se podruhé nepřevádí.');
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaMzdyAdapter::variant(2, 2, true));
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaMzdyAdapter::variant(2, 0, true));
        self::assertSame(PohodaMzdyAdapter::VARIANT_POHODA, PohodaMzdyAdapter::variant(2, 0, false));
        self::assertSame(PohodaMzdyAdapter::VARIANT_PAMICA, PohodaMzdyAdapter::variant(0, 0, false), 'Bez zaměstnanců není co převádět.');
    }

    /**
     * Bez převodu by převodník PAMICA mzdu z POHODY odmítl (chybí poměr); hláška musí říct,
     * co v exportu chybí, ne jen „nemá zaměstnance nebo pracovní poměr".
     */
    public function testPayslipWithoutRelationHasClearErrorWithoutAdapter(): void
    {
        $file = SyntheticPohodaMzdy::write($this->tmp);
        $xml = (string) file_get_contents($file);
        file_put_contents($file, str_replace('<ZAM><ID>12</ID>', '<ZAMpomer><ID>99</ID><RefZAM>99</RefZAM></ZAMpomer><ZAM><ID>12</ID>', $xml));

        try {
            PohodaPayrollConverter::read($file, SyntheticPohodaMzdy::YEAR)->month('2026-01');
            self::fail('Mzda bez poměru musí skončit chybou.');
        } catch (PohodaException $e) {
            self::assertSame('payroll_inconsistent', $e->errorCode);
            self::assertStringContainsString('nemá vazbu na pracovní poměr (RefPomer)', $e->getMessage());
        }
    }

    public function testEmploymentFromCardAndPayslipColumnsIntoMonthlyWorkbook(): void
    {
        $prepared = PohodaMzdyAdapter::prepare(SyntheticPohodaMzdy::write($this->tmp));
        self::assertNotNull($prepared);
        $converter = PohodaPayrollConverter::read($prepared['file'], SyntheticPohodaMzdy::YEAR);
        self::assertSame(['2026-01', '2026-02'], $converter->closedPeriods(SyntheticPohodaMzdy::YEAR));

        $january = self::rows($converter->month('2026-01'));
        self::assertSame('pracovní poměr', $january['P001']['Druh vztahu']);
        self::assertSame(40.0, $january['P001']['Týdenní úvazek']);
        self::assertSame(40000.0, $january['P001']['Měsíční mzda']);
        self::assertSame(176.0, $january['P001']['Fond pracovní doby (h)']);
        self::assertSame(168.0, $january['P001']['Odpracováno (h)'], 'POHODA svátek do odpracovaných hodin nepočítá.');
        self::assertSame(8.0, $january['P001']['Svátek (h)']);
        self::assertSame(2719.5, $january['P001']['Příspěvek na stravování - osvobozená část (Kč)']);
        self::assertSame(40182.0, $january['P001']['Hrubá mzda (Kč)']);
        self::assertSame(20.0, $january['P002']['Týdenní úvazek']);
        self::assertSame(30000.0, $january['P002']['Měsíční mzda']);
        self::assertSame(88.0, $january['P002']['Fond pracovní doby (h)']);
        self::assertSame(84.0, $january['P002']['Odpracováno (h)']);
        self::assertSame(4.0, $january['P002']['Svátek (h)']);
        self::assertStringContainsString('nástup 1. 3. 2024', (string) $january['P001']['Nástup / ukončení']);

        $february = $converter->month('2026-02');
        $rows = self::rows($february);
        self::assertSame(16.0, $rows['P001']['Dovolená (h)']);
        self::assertSame(144.0, $rows['P001']['Odpracováno (h)']);
        self::assertArrayNotHasKey('Nemoc (h)', $february['columns'], 'Nemoc s daty jde do evidence nepřítomností, ne do souhrnu.');
        self::assertSame(40.0, $rows['P002']['Odpracováno (h)']);
        self::assertSame(13260.0 + 2016.0, $rows['P002']['Čistá mzda (Kč)'], 'Čistá mzda nese i náhradu mzdy při nemoci (JMHZ 10344).');
        self::assertSame((int) round(SyntheticPohodaMzdy::GROSS['2026-02'] * 100), $february['totals']['gross_minor']);
    }

    public function testReliefsLeaveAbsencesAndSicknessFromCards(): void
    {
        $prepared = PohodaMzdyAdapter::prepare(SyntheticPohodaMzdy::write($this->tmp));
        self::assertNotNull($prepared);
        $file = $prepared['file'];

        $records = [];
        foreach (PohodaPayrollPeople::read($file, SyntheticPohodaMzdy::YEAR) as $record) {
            $records[$record['personal_number']] = $record;
        }
        self::assertSame(['P001', 'P002'], array_keys($records));
        self::assertSame('2024-03-01', $records['P001']['start']);
        self::assertTrue($records['P001']['first_signed']);
        self::assertTrue($records['P001']['social_participation']);
        self::assertSame([['from' => '2026-01-01', 'amount' => 40000.0, 'prorated' => false]], $records['P001']['monthly_wages']);
        self::assertSame(168.0, $records['P001']['leave']['balance_hours'], 'Nárok 160 h, převod 24 h, čerpáno 16 h.');
        self::assertSame('43111', $records['P001']['cz_isco']);
        // Slevy z karty: sleva na poplatníka u obou, zvýhodnění na první dítě u Aleny.
        $january = $records['P001']['months'][1];
        self::assertSame(257000, $january['non_refundable']);
        self::assertSame(126700, $january['child']);
        self::assertSame(219300, $january['advance_tax']);
        self::assertSame([['type' => 'vacation', 'from' => '2026-02-09', 'to' => '2026-02-10']],
            array_map(static fn (array $a): array => array_intersect_key($a, array_flip(['type', 'from', 'to'])), $records['P001']['absences']));
        self::assertSame([['type' => 'dpn', 'from' => '2026-02-16', 'to' => '2026-02-27']],
            array_map(static fn (array $a): array => array_intersect_key($a, array_flip(['type', 'from', 'to'])), $records['P002']['absences']));
        self::assertSame(0, $records['P002']['absences_without_dates']);
        self::assertNotNull($records['P001']['evidence']['health_start'], 'Oznámení pojišťovně se váže na poměr zaměstnance.');

        $relations = PohodaPayrollRelations::read($file, SyntheticPohodaMzdy::YEAR, '2026-03-05');
        self::assertSame(['employment', 'employment'], array_column($relations, 'relation_type'));
        self::assertSame(['40.00', '20.00'], array_column($relations, 'weekly_hours'));

        $sickness = PohodaPayrollSickness::read($file, SyntheticPohodaMzdy::YEAR, '2026-03');
        self::assertCount(1, $sickness['cases']);
        self::assertSame('dpn', $sickness['cases'][0]['type']);
        self::assertSame(1, $sickness['wage_compensation_rows']);
    }

    public function testReconciliationPassesAndFailsOnTamperedColumn(): void
    {
        $file = SyntheticPohodaMzdy::write($this->tmp);
        $prepared = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($prepared);
        self::assertSame([], $prepared['failures']);
        foreach (SyntheticPohodaMzdy::GROSS as $period => $gross) {
            self::assertSame((int) round($gross * 100), $prepared['months'][$period]['metrics']['gross']);
            self::assertSame((int) round(SyntheticPohodaMzdy::PAYOUT[$period] * 100), $prepared['months'][$period]['metrics']['payout']);
            self::assertSame(2, $prepared['months'][$period]['payslips']);
        }
        self::assertSame(257000 + 126700 + 257000, $prepared['months']['2026-01']['metrics']['tax_reliefs']);
        self::assertSame([], PohodaMzdyReconciliation::violations($prepared['failures'], []));

        // Náhrada za dovolenou, která nesedí na hrubou mzdu: sloupec, o kterém převod neví.
        $xml = (string) file_get_contents($file);
        file_put_contents($file, str_replace('<KcDov>4000</KcDov>', '<KcDov>4500</KcDov>', $xml));
        $tampered = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($tampered);
        self::assertSame(['2026-02'], array_keys($tampered['failures']));
        self::assertStringContainsString('hrubá mzda 40 000,00 Kč', $tampered['failures']['2026-02'][0]);
        $violations = PohodaMzdyReconciliation::violations($tampered['failures'], []);
        self::assertSame([PohodaMzdyReconciliation::SOURCE_MISMATCH], array_column($violations, 'code'));

        // Sražené pojistné, které nesedí na čistou mzdu.
        file_put_contents($file, str_replace('<KcSoc>2853</KcSoc>', '<KcSoc>2953</KcSoc>', $xml));
        $tampered = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($tampered);
        self::assertSame(['2026-01'], array_keys($tampered['failures']));
        self::assertStringContainsString('čistá mzda', $tampered['failures']['2026-01'][0]);
    }

    /**
     * Roční zúčtování daně: přeplatek daně (`KcPreplDanRZ`) je v čisté mzdě, doplatek
     * daňového bonusu (`KcDoplDanBonRZ`) jen ve výplatě, `KcDanUpr` je jejich součet.
     * Mzda s nimi sedí sama se sebou; částky zůstávají nepřevedenými položkami.
     */
    public function testAnnualTaxSettlementRefundKeepsPayslipConsistent(): void
    {
        $file = SyntheticPohodaMzdy::write($this->tmp);
        $xml = (string) file_get_contents($file);
        $xml = str_replace('<KcCistaM>33327</KcCistaM>', '<KcCistaM>34327</KcCistaM><KcPreplDanRZ>1000</KcPreplDanRZ><KcDoplDanBonRZ>500</KcDoplDanBonRZ><KcDanUpr>1500</KcDanUpr>', $xml);
        $xml = str_replace('<KcVyuct>36046.5</KcVyuct>', '<KcVyuct>37546.5</KcVyuct>', $xml);
        file_put_contents($file, $xml);

        $prepared = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($prepared);
        self::assertSame([], $prepared['failures']);
        $items = $prepared['unconverted'][0]['unconverted_items'];
        self::assertSame(1000.0, $items['component:KcPreplDanRZ']['amount'] ?? null);
    }

    public function testUnknownColumnsAndCardPeriodsAreReportedNotGuessed(): void
    {
        $file = SyntheticPohodaMzdy::write($this->tmp);
        $xml = (string) file_get_contents($file);
        // Prémie (sloupec bez ověřeného významu) v hrubé mzdě a doba neznámého druhu na kartě.
        $xml = str_replace('<KcHrubaM>40182</KcHrubaM>', '<KcHrubaM>41182</KcHrubaM><KcPremM>1000</KcPremM>', $xml);
        $xml = str_replace('<KcCistaM>33327</KcCistaM>', '<KcCistaM>34327</KcCistaM>', $xml);
        $xml = str_replace('<KcVyuct>36046.5</KcVyuct>', '<KcVyuct>37046.5</KcVyuct>', $xml);
        $xml = str_replace('<ZAMzp>', '<ZAMpDov><ID>503</ID><RefAg>12</RefAg><DatZac>2026-01-20</DatZac><DatKon>2026-01-20</DatKon><Dnu>1</Dnu><RelDrDov>77</RelDrDov></ZAMpDov><ZAMzp>', $xml);
        file_put_contents($file, $xml);

        $prepared = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($prepared);
        self::assertSame([], $prepared['failures'], 'Nepřevedený sloupec hrubé mzdy kontrola započte, rozdíl nevznikne.');
        $items = $prepared['unconverted'][0]['unconverted_items'];
        self::assertSame(['absence:RelDrDov 77', 'component:KcPremM'], array_keys($items));
        self::assertSame(1000.0, $items['component:KcPremM']['amount']);
        self::assertSame(4.0, $items['absence:RelDrDov 77']['hours']);
        $message = PohodaPayrollConverter::unconvertedItemsMessage($prepared['unconverted'], 20, 'POHODY');
        self::assertIsString($message);
        self::assertStringStartsWith('Položky mezd POHODY, které převod nezná: ', $message);
        self::assertStringContainsString('KcPremM (1 vstup, 1 000,00 Kč)', $message);
        self::assertStringContainsString('v úhrnech POHODY', $message);

        $month = PohodaPayrollConverter::read($prepared['file'], SyntheticPohodaMzdy::YEAR)->month('2026-01');
        self::assertSame([], $month['unconverted_items'], 'Do sešitu jdou jen převedené položky.');
        self::assertSame(271950, $month['totals']['components_minor'], 'Prémie bez ověřeného významu se do složek nepřevede.');
    }

    /**
     * Zvláštní sazba daně (`ZvlSazD`): daň vybraná srážkou jde do srážkové daně PAMICA,
     * záloha a slevy zůstanou nulové. Druh zaměstnání 3 je dohoda o pracovní činnosti.
     */
    public function testWithholdingTaxAndAgreementKind(): void
    {
        $file = SyntheticPohodaMzdy::write($this->tmp);
        $xml = (string) file_get_contents($file);
        $xml = str_replace('<RelDruhZ>0</RelDruhZ><RelDruhM>1</RelDruhM><TUvazek>20</TUvazek>', '<RelDruhZ>3</RelDruhZ><RelDruhM>1</RelDruhM><TUvazek>20</TUvazek>', $xml);
        $xml = preg_replace('#(<MZ><ID>102</ID>.*?)<ZvlSazD>0</ZvlSazD>#s', '$1<ZvlSazD>1</ZvlSazD>', $xml) ?? $xml;
        file_put_contents($file, $xml);

        $prepared = PohodaMzdyAdapter::prepare($file);
        self::assertNotNull($prepared);
        self::assertSame(257000 + 126700, $prepared['months']['2026-01']['metrics']['tax_reliefs'], 'Srážková daň slevy nemá.');
        self::assertSame(185500, $prepared['months']['2026-01']['metrics']['withholding_tax']);
        $relations = PohodaPayrollRelations::read($prepared['file'], SyntheticPohodaMzdy::YEAR, '2026-03-05');
        self::assertSame(['employment', 'dpc'], array_column($relations, 'relation_type'));
        $records = [];
        foreach (PohodaPayrollPeople::read($prepared['file'], SyntheticPohodaMzdy::YEAR) as $record) {
            $records[$record['personal_number']] = $record;
        }
        self::assertSame(185500, $records['P002']['months'][1]['withholding_tax']);
        self::assertSame(0, $records['P002']['months'][1]['advance_tax']);
        self::assertSame(0, $records['P002']['months'][1]['non_refundable']);
    }

    /** @return array<string,array<string,mixed>> osobní číslo => řádek sešitu */
    private static function rows(array $month): array
    {
        $out = [];
        foreach ($month['rows'] as $row) {
            $out[(string) $row['Osobní číslo']] = $row;
        }
        return $out;
    }
}
