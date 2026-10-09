<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Service\Migration\Premier\PremierPayrollDeductions;
use MyInvoice\Service\Migration\Premier\PremierPayrollTakeover;
use MyInvoice\Service\Migration\Premier\PremierPayrollTime;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Časové evidence ze syntetické zálohy PREMIER: nepřítomnosti z položek mezd, trvající
 * neschopnost, zůstatek dovolené v hodinách a průměry čtvrtletí převáděného roku.
 */
final class PremierPayrollTimeTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testAbsencesFromPayrollItems(): void
    {
        $time = PremierPayrollTime::read($this->backup());
        self::assertSame([
            ['vacation', '2025-08-04', '2025-08-08'],
            ['dpn', '2025-11-10', '2025-11-23'],
            ['dpn', '2025-11-24', '2025-11-30'],
            ['dpn', '2025-12-01', '2025-12-31'],
        ], array_map(static fn (array $a): array => [$a['type'], $a['from'], $a['to']], $time['absences'][5]),
            'Srážky (702, 750) nepřítomnostmi nejsou.');
        self::assertSame(['balance' => 155.0, 'taken' => 38.75], $time['leave'][5]['2025-12']);
        self::assertSame([['from' => '2025-11-10', 'to' => '2026-01-20', 'kind' => 'DPN']], $time['sickness'][5]);
    }

    /**
     * Brána G1: složka z řady nepřítomností, kterou převod nezná, i karta srážek s neznámou
     * složkou se vrátí k varování v protokolu. Náhrada za svátek (510) nepřítomnost vědomě
     * není a varování nevyvolá, stejně jako osobní ohodnocení (303) na kartě srážek.
     */
    public function testUnknownAbsenceAndDeductionCodesAreReportedNotDroppedSilently(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_time_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true, 'payroll_unknown_codes' => true]);
        $backup = PremierBackup::open($this->tmp);

        self::assertSame(['557' => 1], PremierPayrollTime::unknownAbsenceCodes($backup));
        self::assertSame(['721' => 1], PremierPayrollDeductions::unknownCodes($backup));
    }

    public function testKnownCodesRaiseNoUnknownCodeWarning(): void
    {
        $backup = $this->backup();

        self::assertSame([], PremierPayrollTime::unknownAbsenceCodes($backup));
        self::assertSame([], PremierPayrollDeductions::unknownCodes($backup));
    }

    public function testTakeoverSelectsTransferredYearAndContinuesSickness(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup())->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];

        $employment = PremierPayrollTakeover::record($employee, '2025-12-31', null, '2026-02')->employment;
        self::assertSame(['2025-11-10', '2025-12-31', '2026-01-01', '2026-01-20'], [$employment->absences[1]['from'], $employment->absences[3]['to'],
            $employment->absences[4]['from'], $employment->absences[4]['to']], 'Neschopnost trvající přes převáděné období pokračuje do konce případu.');
        self::assertSame(['year' => 2025, 'balance_hours' => 155.0, 'balance_days' => 20.0, 'taken_hours' => 38.75, 'daily_hours' => 7.75, 'from_days' => false],
            $employment->leave);
        self::assertSame([[1, 180.5], [2, 190.25], [3, 190.25], [4, 190.25]], array_map(static fn (array $a): array => [$a['quarter'], $a['hourly']], $employment->averages));
        self::assertSame('2025-01', $employment->transferStart);

        // Začátek vedení mezd 10/2025: listopad a prosinec převzaté nejsou.
        $early = PremierPayrollTakeover::record($employee, '2025-12-31', null, '2025-10')->employment;
        self::assertSame(['vacation'], array_column($early->absences, 'type'));
        self::assertSame(155.0, $early->leave['balance_hours'], 'Zůstatek ke konci posledního převáděného měsíce (8/2025).');
        self::assertCount(3, $early->averages, 'Průměr čtvrtletí, které už vede MyÚčto, se nepřevádí.');

        // Případ bez známého konce jen spočítá.
        $open = $employee;
        $open['sickness'][0]['to'] = null;
        self::assertSame(1, PremierPayrollTakeover::absences($open, '2025-12-31', '2026-02')['open_sickness']);
    }

    private function backup(): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_time_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true, 'payroll_detail' => true]);
        return PremierBackup::open($this->tmp);
    }
}
