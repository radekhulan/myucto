<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use PHPUnit\Framework\TestCase;

/**
 * Doplňkové údaje mzdy PAMICA (`MZ2`, vazba `RefAg` = `MZ.ID`): sleva pracujícího důchodce
 * po měsících (`SocPojSlevaPracDuch`) a příspěvky zaměstnavatele na penzijní produkty
 * (`KcPDP`, `KcDIP`) pro roční koš osvobození v převzatých měsících.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollSupplementaryDataTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            @unlink($this->tmp . '/12345678_2026/91_mzdy.xml');
            @rmdir($this->tmp . '/12345678_2026');
            @rmdir($this->tmp);
        }
    }

    /** Důchodce: sleva po měsících tak, jak s ní PAMICA mzdu počítala, ne „k ověření". */
    public function testPensionerDiscountComesFromSupplementaryPayslipData(): void
    {
        $records = PohodaPayrollPeople::read($this->file([1 => 0, 2 => 1, 3 => 1]), 2026);

        self::assertSame(
            [['not_claimed', '2026-01-01'], ['verified', '2026-02-01']],
            array_map(static fn (array $run): array => [$run['status'], $run['from']], $records[0]['pensioner_discounts']),
        );
        // Starší export bez `MZ2`: důchodci slevu doplní až podané hlášení.
        self::assertSame([], PohodaPayrollPeople::read($this->file(null), 2026)[0]['pensioner_discounts']);
    }

    public function testPensionProductContributionsGoToTheTakenOverMonth(): void
    {
        self::assertSame(150_000, PayrollMigrationTakeoverFacts::fromPohodaMz(['Rok' => '2026', 'RelMes' => '2', 'KcPDP' => '1000', 'KcDIP' => '500'])
            ->oldAgeSavingsContributionMinor);
        self::assertNull(PayrollMigrationTakeoverFacts::fromPohodaMz(['Rok' => '2026', 'RelMes' => '2'])->oldAgeSavingsContributionMinor);
    }

    /** @param array<int,int>|null $discount měsíc => `SocPojSlevaPracDuch`; `null` = export bez `MZ2` */
    private function file(?array $discount): string
    {
        $this->tmp = $this->tmp !== '' ? $this->tmp : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_mz2_' . bin2hex(random_bytes(5));
        @mkdir($this->tmp . '/12345678_2026', 0755, true);
        $x = '';
        $row = static function (string $table, array $cols) use (&$x): void {
            $x .= "<{$table}>";
            foreach ($cols as $k => $v) {
                $x .= "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>";
            }
            $x .= "</{$table}>";
        };
        $row('sMZslozky', ['ID' => 1, 'Cislo' => 'M01', 'Nazev' => 'Základní mzda']);
        $row('sMzPoj', ['ID' => 1, 'IDS' => 'VZP', 'Kod' => '111']);
        $row('ZAM', ['ID' => 1, 'OsCislo' => '7001', 'Jmeno' => 'Jan', 'Prijmeni' => 'Důchodce', 'DatNar' => '1958-03-04',
            'StatPris' => 'CZ', 'Nerezident' => 0, 'RefPoj' => 1, 'JeDuch' => 1, 'DDrDuch' => 'S']);
        $row('ZAMpomer', ['ID' => 1, 'RefZAM' => 1, 'Poradi' => 1, 'JeDPP' => 0, 'DatNast' => '2020-01-01', 'TUvazek' => 40]);
        foreach ([1 => 10, 2 => 20, 3 => 30] as $month => $mzId) {
            $row('MZ', ['ID' => $mzId, 'RefZAM' => 1, 'RefPomer' => 1, 'Rok' => 2026, 'RelMes' => $month, 'HodFond' => 160,
                'DnyFond2' => 20, 'TUvazek' => 40, 'HodOdpra' => 160, 'RefPoj' => 1, 'KcHrubaM' => 30000, 'KcCistaM' => 24000,
                'KcZaklM' => 30000, 'DnyPrac' => 20, 'DnyOdpra' => 20, 'KcPrum' => 180]);
            $row('MZslozky', ['ID' => $mzId, 'RefAg' => $mzId, 'RefSlozka' => 1, 'KcMzda' => 30000, 'Hodnota1' => 30000]);
            if ($discount !== null) {
                $row('MZ2', ['ID' => $mzId, 'RefAg' => $mzId, 'SocPojSlevaPracDuch' => $discount[$month], 'KcPDP' => 0, 'KcDIP' => 0]);
            }
        }
        $file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<mdbExport version="1" group="mzdy" ico="12345678" year="2026" source="PAMICA" state="ok">' . $x . '</mdbExport>');

        return $file;
    }
}
