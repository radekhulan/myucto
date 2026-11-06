<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollTakeover;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use PHPUnit\Framework\TestCase;

/**
 * Sjednaná měsíční mzda a sazba náhrady za překážky na straně zaměstnavatele, jak je
 * převod z PAMICA čte pro měsíce, které po převodu počítá MyÚčto.
 *
 * `KcZaklM` je základní mzda už krácená o hodiny, za které náleží náhrada (dovolená,
 * lékař). Sjednaná mzda je měsíční sazba složky `M01` (`Hodnota1`). Převod dřív bral
 * `KcZaklM` z „celého" měsíce, a protože se dny odpracované počítají i s částečně
 * odpracovanými dny, do sjednané mzdy se propsala krácená částka.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollAgreedWageTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_wage_' . bin2hex(random_bytes(5));
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

    public function testAgreedWageIsTheMonthlyRateNotTheReducedBaseWage(): void
    {
        $records = PohodaPayrollPeople::read($this->file, 2026);
        self::assertCount(1, $records);

        self::assertSame(
            [['from' => '2026-01-01', 'amount' => 40000.0, 'prorated' => false]],
            $records[0]['monthly_wages'],
            'Sjednaná mzda je měsíční sazba M01, ne základní mzda krácená o dovolenou a lékaře.',
        );
    }

    public function testRaiseOfTheMonthlyRateStartsANewVersion(): void
    {
        file_put_contents($this->file, self::payrollXml(raiseInMarch: true));
        $records = PohodaPayrollPeople::read($this->file, 2026);

        self::assertSame(
            [
                ['from' => '2026-01-01', 'amount' => 40000.0, 'prorated' => false],
                ['from' => '2026-03-01', 'amount' => 42000.0, 'prorated' => false],
            ],
            $records[0]['monthly_wages'],
        );
    }

    public function testEmployerObstacleRateIsTheOneThePreviousProgramPaid(): void
    {
        $converter = PohodaPayrollConverter::read($this->file);
        $months = [$converter->month('2026-01'), $converter->month('2026-02'), $converter->month('2026-03')];

        self::assertSame(100, PohodaPayrollConverter::obstacleEmployerRate($months), 'PAMICA platila 100 % průměru (§ 208 ZP).');

        $rates = [];
        foreach (AttendanceRules::validate(PohodaPayrollConverter::profile($months)['rules']) as $rule) {
            if ($rule['meaning'] === AttendanceRules::RATE_MEANING) {
                $rates[] = $rule['rate_percent'] ?? null;
            }
        }
        self::assertSame([100], $rates, 'Profil importu nese sazbu, se kterou se náhrada spočítá v MyÚčtu.');
    }

    public function testMixedObstacleRatesFallBackToTheImportDefault(): void
    {
        self::assertNull(PohodaPayrollConverter::obstacleEmployerRate([['obstacle_rates' => [80]], ['obstacle_rates' => [100]]]));
        self::assertNull(PohodaPayrollConverter::obstacleEmployerRate([['obstacle_rates' => [45]]]), 'Mimo 60 až 100 % zákon sazbu nepřipouští.');
        self::assertSame(60, PohodaPayrollConverter::obstacleEmployerRate([['obstacle_rates' => [60]], ['obstacle_rates' => []]]));
    }

    public function testEmployerPensionContributionIsTheLastUnbrokenAmount(): void
    {
        file_put_contents($this->file, self::payrollXml(pension: [1 => 500, 2 => 1000, 3 => 1000]));
        $record = PohodaPayrollPeople::read($this->file, 2026)[0];

        self::assertSame(['from' => '2026-02', 'amount' => 1000.0], $record['pension_contribution']);

        $components = (new \ReflectionMethod(PohodaPayrollTakeover::class, 'recurringComponents'))->invoke(null, $record);
        self::assertCount(1, $components);
        self::assertSame('PRISPEVEK_PENZE_ZIVOTNI', $components[0]['code']);
        self::assertSame('benefit_pension', $components[0]['kind']);
        self::assertSame(1000.0, $components[0]['amount']);
        self::assertSame('2026-02-01', $components[0]['from']);
    }

    public function testEndedPensionContributionIsNotCarriedOver(): void
    {
        file_put_contents($this->file, self::payrollXml(pension: [1 => 1000, 2 => 1000]));
        $record = PohodaPayrollPeople::read($this->file, 2026)[0];

        self::assertNull($record['pension_contribution'], 'V posledním měsíci příspěvek nebyl, skončil.');
        self::assertSame([], (new \ReflectionMethod(PohodaPayrollTakeover::class, 'recurringComponents'))->invoke(null, $record));
    }

    public function testHourlyAgreementHasNoAgreedMonthlyWage(): void
    {
        file_put_contents($this->file, self::payrollXml(hourlyAgreement: true));
        $record = PohodaPayrollPeople::read($this->file, 2026)[0];

        self::assertTrue($record['hourly_wage'], 'Dohoda v hodinové sazbě (D02) měsíční mzdu nepobírá.');
        self::assertSame([], $record['monthly_wages'], 'Hodiny × sazba nejsou sjednaná mzda, jinak by z každého měsíce vznikla verze podmínek.');
    }

    public function testSwitchFromBaseWageToFunctionRewardEndsTheMonthlyWage(): void
    {
        file_put_contents($this->file, self::payrollXml(rewardFromMarch: true));
        $records = PohodaPayrollPeople::read($this->file, 2026);

        self::assertSame(
            [
                ['from' => '2026-01-01', 'amount' => 40000.0, 'prorated' => false],
                ['from' => '2026-03-01', 'amount' => 0.0, 'prorated' => false],
            ],
            $records[0]['monthly_wages'],
            'Od března jen odměna mimo hrubou mzdu: předpis měsíční mzdy má skončit, jinak se platí obojí.',
        );
    }

    public function testAttendanceHoursKeepThousandths(): void
    {
        file_put_contents($this->file, self::payrollXml(workedHours: 148.266667));
        $row = PohodaPayrollConverter::read($this->file)->month('2026-01')['rows'][0];

        self::assertSame(148.267, $row['Odpracováno (h)'], 'PAMICA vede minuty a hlásí hodiny na tisíciny.');
    }

    /**
     * Jedna fiktivní osoba s měsíční sazbou 40 000 Kč. Každý měsíc čerpá dovolenou
     * a jde k lékaři, takže `KcZaklM` je vždy nižší než sazba, přestože dny odpracované
     * se rovnají pracovním dnům. V únoru je překážka na straně zaměstnavatele placená
     * plným průměrem.
     */
    private static function payrollXml(bool $raiseInMarch = false, array $pension = [], bool $hourlyAgreement = false, float $workedHours = 148, bool $rewardFromMarch = false): string
    {
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZneprit', ['ID' => 1, 'Cislo' => 'V01', 'Nazev' => 'Dovolená']);
        $row('sMZneprit', ['ID' => 2, 'Cislo' => 'V06c', 'Nazev' => 'Překážky na straně zaměstnavatele – ostatní']);
        $row('sMZslozky', $hourlyAgreement ? ['ID' => 1, 'Cislo' => 'D02', 'Nazev' => 'Dohoda - hodinová sazba'] : ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda']);
        $row('sMZslozky', ['ID' => 2, 'Cislo' => 'O01', 'Nazev' => 'Odměna za výkon funkce']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '5001', 'Jmeno' => 'Petr', 'Prijmeni' => 'Sazba', 'DatNar' => '1985-06-07',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1]);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2020-01-01', 'TUvazek' => 40]);
        foreach ([1 => 10, 2 => 20, 3 => 30] as $month => $mzId) {
            $rate = $raiseInMarch && $month === 3 ? 42000 : 40000;
            if ($rewardFromMarch && $month === 3) {
                // Jednatel: odměna za výkon funkce mimo hrubou mzdu, základní mzda už žádná.
                $row('MZ', ['ID' => $mzId, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => $month, 'KcZaklM' => 0, 'KcHrubaM' => 0, 'DnyPrac' => 0, 'DnyOdpra' => 0]);
                $row('MZslozky', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => 2, 'KcMzda' => 500, 'Hodnota1' => 500]);
                continue;
            }
            $row('MZ', ['ID' => $mzId, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => $month, 'HodFond' => 160,
                'DnyFond2' => 20, 'TUvazek' => 40, 'HodOdpra' => $workedHours, 'RefPoj' => 1, 'KcHrubaM' => $rate, 'KcCistaM' => 30000, 'KcPripoj' => $pension[$month] ?? 0,
                'KcZaklM' => (int) round($rate * 148 / 160), 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 250]);
            $row('MZslozky', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => 1, 'KcMzda' => (int) round($rate * 148 / 160), 'Hodnota1' => $rate]);
            $row('MZneprit', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => 1, 'HodPrac' => 8, 'KcNahr' => 2000,
                'DatZac' => sprintf('2026-%02d-02', $month), 'DatKon' => sprintf('2026-%02d-02', $month)]);
        }
        $row('MZneprit', ['ID' => 99, 'RefAg' => 20, 'RefSlozka' => 2, 'HodPrac' => 4, 'KcNahr' => 1000,
            'DatZac' => '2026-02-10', 'DatKon' => '2026-02-10']);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>';
    }
}
