<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollMonths;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxPayrollTables;

final class StereoNxPayrollMonthsTest extends TestCase
{
    private function plan(array $tables): array
    {
        return StereoNxPayrollMonths::fromTables($tables, ['ico' => '12345679'], 1);
    }

    public function testConvertsVerifiedMonthWithHistoricalRatesAndFacts(): void
    {
        $plan = $this->plan(SyntheticStereoNxPayrollTables::tables());
        self::assertSame('2026-01', $plan['last_source_period']);
        self::assertSame(1, $plan['counts']['historical_payroll_ready']);
        $record = $plan['records'][0];
        self::assertSame('7', $record['source_key']);
        self::assertEquals(2480, $record['amounts']['employer_social']);
        self::assertEquals(900, $record['amounts']['employer_health']);
        self::assertSame(31, $record['facts']['insuranceDays']);
        self::assertSame(2050, $record['facts']['workedDaysHundredths']);
        self::assertSame(9840, $record['facts']['workedMinutes']);
        self::assertNull($record['facts']['payoutDate']);
        self::assertInstanceOf(PayrollMigrationTakeoverFacts::class, new PayrollMigrationTakeoverFacts(...$record['facts']));
        $reference = PayrollMigrationReferenceTotals::fromAmounts(
            $record['period'], 'stereo-nx:' . $record['employee_key'],
            'stereo-nx:' . $record['source_key'], null, null,
            $record['amounts'], new PayrollMigrationTakeoverFacts(...$record['facts']),
        );
        self::assertSame(248000, $reference->employerSocialMinor);
        self::assertContains('employer_amounts_reconstructed', array_column($plan['warnings'], 'code'));
        self::assertSame(64, strlen($record['source_hash']));
        $hash = $record['source_hash'];
        unset($record['source_hash']);
        self::assertSame($hash, StereoNxImportMap::fingerprint($record));
    }

    public function testHistoricalRateBoundaryChangesHashAndRoundsPerPerson(): void
    {
        $tables = SyntheticStereoNxPayrollTables::tables();
        $old = $this->plan($tables)['records'][0];
        $tables['Gparrok'][] = [
            'DatumOd' => '2026-01-01', 'SOCPodnikatel' => 24.81,
            'SOCPojistneP' => 7.1, 'ZDRPojistneP' => 4.5, 'ZDRPodnikatel' => 9,
        ];
        $new = $this->plan($tables)['records'][0];
        self::assertEquals(2481, $new['amounts']['employer_social']);
        self::assertNotSame($old['source_hash'], $new['source_hash']);
        $tables['Gparrok'][1]['DatumOd'] = '2026-01-15';
        self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready']);
        $tables['Gparrok'][1]['DatumOd'] = '2026-01-01';
        $tables['Gparrok'][] = $tables['Gparrok'][1];
        self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready']);
    }

    public function testRoundingIsPerPersonAndHealthEmployerIsResidual(): void
    {
        $tables = SyntheticStereoNxPayrollTables::tables();
        $month =& $tables['MMzdy'][0];
        $month['HrubaMzda'] = $month['ZaklSP'] = $month['ZaklZP'] = 100.01;
        $month['SPZa'] = 8;
        $month['ZPZa'] = 5;
        $month['Dan'] = 0;
        $month['CistaMzda'] = 87.01;
        $month['Srazky'] = $month['StravPO'] = 0;
        $month['Dobirka'] = 87.01;
        $record = $this->plan($tables)['records'][0];
        self::assertEquals(25, $record['amounts']['employer_social']);
        self::assertEquals(9, $record['amounts']['employer_health']);
    }

    public function testUnsupportedAndUnknownValuesSkipInsteadOfBecomingZero(): void
    {
        foreach ([
            ['TypDan', 'N'], ['JenDP', null], ['Srazky', null],
            ['PPSHrubaMzda', null], ['HrubaMzda', NAN], ['Dan', -1],
            ['OdpracovaneH', null], ['NeprKalDny', null], ['NVOdvZa', true],
            ['PPZa', 1], ['RocniVyuctDanBon', 1],
        ] as [$field, $value]) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            $tables['MMzdy'][0][$field] = $value;
            self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready'], $field);
        }
    }

    public function testSpecialEmployeeInsuranceAndRelationshipFlagsNeverBecomeStandardHpp(): void
    {
        foreach ([
            ['ZakPoj', false], ['ZamMR', true], ['ZPS', 'S'],
            ['MinZamestnavatel', 'A'], ['PracRezim', 'R'], ['DruhD', 'X'],
            ['OZP', true], ['Duchod', 1], ['DuchodDruh', '1'],
            ['DuchodPredcasny', true], ['DuchodSnizVek', true],
            ['HlavniPPV', false], ['DruhCinnosti', 'X'], ['ELDPKod', 'X'],
            ['StatVD', true],
        ] as [$field, $value]) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            $tables['MZAMEST'][0][$field] = $value;
            $plan = $this->plan($tables);
            self::assertSame(0, $plan['counts']['historical_payroll_ready'], $field);
            self::assertSame(1, $plan['counts']['historical_payroll_skipped'], $field);
            self::assertContains('employment_insurance_regime_unsupported', array_column($plan['warnings'], 'code'));
        }
    }

    public function testUnknownEmployeeInsuranceFlagsAlsoSkip(): void
    {
        foreach (['ZakPoj', 'ZamMR', 'ZPS', 'MinZamestnavatel', 'OZP', 'HlavniPPV'] as $field) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            unset($tables['MZAMEST'][0][$field]);
            self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready'], $field);
        }
    }

    public function testSpecialOrMissingDeductionParametersSkip(): void
    {
        foreach ([['ZamMR', true], ['SPTyp', 'X'], ['ZamMR', null], ['SPTyp', null]] as [$field, $value]) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            $tables['MOdvPar'][0][$field] = $value;
            $plan = $this->plan($tables);
            self::assertSame(0, $plan['counts']['historical_payroll_ready'], $field);
            self::assertContains('payroll_parameters_unverified', array_column($plan['warnings'], 'code'));
        }
    }

    public function testMissingRateAndParameterValuesSkip(): void
    {
        foreach (['SOCPodnikatel', 'SOCPojistneP', 'ZDRPojistneP', 'ZDRPodnikatel'] as $field) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            unset($tables['Gparrok'][0][$field]);
            self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready'], $field);
        }
        $tables = SyntheticStereoNxPayrollTables::tables();
        $tables['MOdvPar'][0]['ZdrPoj'] = null;
        self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready']);
    }

    public function testNetAndPayoutMustMatchExactCents(): void
    {
        foreach (['CistaMzda', 'Dobirka'] as $field) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            $tables['MMzdy'][0][$field] += 0.01;
            self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready'], $field);
        }
    }

    public function testPeriodEvidenceMustAgree(): void
    {
        $tables = SyntheticStereoNxPayrollTables::tables();
        $tables['MMzdy'][0]['MzOb'] = '2026-02-01';
        self::assertSame(0, $this->plan($tables)['counts']['historical_payroll_ready']);
    }

    public function testSourceDuplicatesFailClosed(): void
    {
        foreach (['MZAMEST', 'MMzdy', 'person_period'] as $table) {
            $tables = SyntheticStereoNxPayrollTables::tables();
            if ($table === 'person_period') {
                $tables['MMzdy'][] = $tables['MMzdy'][0];
                $tables['MMzdy'][1]['Klic'] = 8;
            } else {
                $tables[$table][] = $tables[$table][0];
            }
            try {
                $this->plan($tables);
                self::fail('Duplicitní zdrojová identita měla převod zastavit.');
            } catch (StereoNxException $e) {
                self::assertContains($e->errorCode, [
                    'employee_key_duplicate', 'monthly_key_duplicate', 'monthly_period_duplicate',
                ]);
            }
        }
    }
}
