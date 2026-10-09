<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use PHPUnit\Framework\TestCase;

/**
 * Pracovní podmínky, které se v PAMICA změnily během roku: karta vztahu (`ZAMpomer`)
 * nese jen dnešní stav, historie je jen ve mzdách měsíců (`MZ`, `MZslozky`).
 *
 * Vzor: v lednu stanovená doba 37,5 h (`DUvazek` 7,5) při úvazku 37,5 h, od února
 * stanovená 40 h (`DUvazek` 8) při stejném úvazku; měsíční mzda (`M09`) do února,
 * od března úkolová (`U02`). Převod dřív znal jen dnešní stanovenou dobu a vztah,
 * který měl hodinovou nebo úkolovou mzdu kdykoli v roce, nedostal předpis měsíční
 * mzdy ani za měsíce před změnou.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollTermsHistoryTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_terms_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/12345678_2026', 0755, true);
        $this->file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($this->file, self::payrollXml());
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->tmp . '/12345678_2026');
        @rmdir($this->tmp);
    }

    public function testStatedWeeklyHoursFollowTheMonthNotTheCard(): void
    {
        $records = PohodaPayrollPeople::read($this->file, 2026);
        self::assertCount(1, $records);

        self::assertSame(['2026-01' => 37.5, '2026-02' => 37.5, '2026-03' => 37.5], $records[0]['weekly_hours_by_month']);
        self::assertSame(
            ['2026-01' => 37.5, '2026-02' => 40.0, '2026-03' => 40.0],
            $records[0]['stated_weekly_hours_by_month'],
            'Leden PAMICA počítala se stanovenou dobou 37,5 h, karta dnes nese 40 h.',
        );
    }

    public function testHourlyWageFromMarchKeepsTheMonthlyWageBeforeIt(): void
    {
        $records = PohodaPayrollPeople::read($this->file, 2026);

        self::assertTrue($records[0]['hourly_wage']);
        self::assertSame('2026-03-01', $records[0]['hourly_wage_from']);
        self::assertSame([['from' => '2026-01-01', 'amount' => 25000.0, 'prorated' => false]], $records[0]['monthly_wages']);
    }

    public function testRelationHourlyFromTheFirstMonthHasNoSwitchDate(): void
    {
        file_put_contents($this->file, self::payrollXml(hourlyFrom: 1));
        $records = PohodaPayrollPeople::read($this->file, 2026);

        self::assertTrue($records[0]['hourly_wage']);
        self::assertNull($records[0]['hourly_wage_from']);
    }

    private static function payrollXml(int $hourlyFrom = 3): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M09', 'Nazev' => 'Základní mzda - zkrácený úvazek']);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'U02', 'Nazev' => 'Úkolová mzda']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '7001', 'Jmeno' => 'Eva', 'Prijmeni' => 'Režimová', 'DatNar' => '1990-03-04',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2021-04-01',
            'DUvazek' => 8, 'TUvazek' => 37.5, 'UvazekZ' => 7.5]);
        foreach ([1 => 10, 2 => 20, 3 => 30] as $month => $mzId) {
            $daily = $month === 1 ? 7.5 : 8;
            $row('MZ', ['ID' => $mzId, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => $month, 'HodFond' => $daily * 20,
                'DnyFond2' => 20, 'DUvazek' => $daily, 'TUvazek' => 37.5, 'UvazekZ' => 7.5, 'HodOdpra' => 150, 'RefPoj' => 1,
                'KcHrubaM' => 25000, 'KcCistaM' => 20000, 'KcZaklM' => 25000, 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 160]);
            $hourly = $month >= $hourlyFrom;
            $row('MZslozky', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => $hourly ? 2 : 1, 'KcMzda' => 25000,
                'Hodnota1' => $hourly ? 0 : 25000]);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>';
    }
}
