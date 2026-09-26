<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxEmployees;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\TestCase;

final class StereoNxEmployeesTest extends TestCase
{
    public function testVerifiedHppIsAdaptedToSharedTakeoverRecord(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['MesTarif'] = 28_000.0;
        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        $takeover = StereoNxEmployees::toTakeoverRecord($plan['records'][0]);
        self::assertSame('TEST-1', $takeover->person->key);
        self::assertSame('111', $takeover->person->healthCoverage?->status);
        self::assertSame('TEST-1', $takeover->employment->relationKey);
        self::assertFalse($takeover->employment->hourlyWage);
        self::assertSame('employment', $plan['records'][0]['relation_type']);
        self::assertSame([['from' => $plan['records'][0]['start'], 'amount' => 28_000.0, 'prorated' => false],
        ], $takeover->employment->monthlyWages);
    }

    public function testHourlyTariffPreventsRecurringMonthlyWage(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['MesTarif'] = 28_000.0;
        $tables['MZAMEST'][0]['HodTarif'] = 215.0;
        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertTrue(StereoNxEmployees::toTakeoverRecord($plan['records'][0])->employment->hourlyWage);
    }

    public function testPreparesOnlyVerifiedEmploymentFieldsAndMarksHistoricalPayrollSkipped(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['MesTarif'] = 28_000.0;
        $tables['MZAMEST'][0]['HodTarif'] = 215.1;
        $tables['MZAMEST'][0]['Ulice'] = 'Syntetická 1';
        $tables['MZAMEST'][0]['BaUcet'] = '1000000005';
        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame([
            'employees_source' => 1,
            'employees_ready' => 1,
            'employees_skipped' => 0,
            'historical_payroll_source' => 1,
            'historical_payroll_skipped' => 1,
            'children_source' => 0,
            'children_skipped' => 0,
            'tax_items_source' => 0,
            'tax_items_skipped' => 0,
            'leave_records_source' => 0,
            'leave_records_skipped' => 0,
            'average_records_source' => 0,
            'average_records_skipped' => 0,
        ], $plan['counts']);
        self::assertSame('employment', $plan['records'][0]['relation_type']);
        self::assertSame('40.00', $plan['records'][0]['weekly_hours']);
        self::assertSame(28_000, $plan['records'][0]['monthly_gross']);
        self::assertSame('920620/0102', $plan['records'][0]['birth_number']);
        self::assertSame('111', $plan['records'][0]['health_insurer_code']);
        self::assertSame('signed', $plan['records'][0]['tax_declarations'][0]['status']);
        self::assertSame(64, strlen($plan['records'][0]['source_hash']));
        self::assertSame([
            'employee_hourly_tariff_unmapped',
            'employee_address_unmapped',
            'employee_bank_account_unmapped',
            'historical_payroll_skipped',
        ], array_column($plan['warnings'], 'code'));
        $messages = json_encode($plan['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('TEST-1', $messages);
        self::assertStringNotContainsString('Vzorová', $messages);
    }

    public function testUnknownRelationIsSkippedInsteadOfGuessed(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['Odvod'] = 'DPP';
        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame(0, $plan['counts']['employees_ready']);
        self::assertSame(1, $plan['counts']['employees_skipped']);
        self::assertSame([], $plan['records']);
        self::assertContains('employee_relation_unsupported', array_column($plan['warnings'], 'code'));
        self::assertSame(1, $plan['counts']['historical_payroll_skipped']);
    }

    public function testInvalidOptionalBirthNumberDoesNotManufactureIdentity(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][0]['RC'] = 'not-a-birth-number';
        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertNull($plan['records'][0]['birth_number']);
        self::assertContains('employee_birth_number_invalid', array_column($plan['warnings'], 'code'));
    }

    public function testDuplicateSourceKeyFailsClosed(): void
    {
        $tables = $this->tables();
        $tables['MZAMEST'][] = $tables['MZAMEST'][0];

        try {
            StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);
            self::fail('Duplicitní karta měla převod zastavit.');
        } catch (StereoNxException $e) {
            self::assertSame('employee_key_duplicate', $e->errorCode);
        }
    }

    public function testRelatedPayrollAgendasAreCountedWithConcreteSkipReasons(): void
    {
        $tables = $this->tables();
        $tables['MDeti'][] = ['Prac' => 'TEST-1', 'Jmeno' => 'Syntetické dítě', 'UplatnitNezdC' => true];
        $tables['MOpNezdC'][] = ['Prac' => 'TEST-1', 'Rok' => 2025, 'Typ' => 'synthetic-unknown'];
        $tables['MDovol'][] = ['Prac' => 'TEST-1', 'Rok' => 2025, 'NarokH' => 160.0];
        $tables['MPRVYD'][] = ['Prac' => 'TEST-1', 'Rok' => 2025, 'Ctvrtleti' => 1, 'PrumVyd' => 200.0];

        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame(1, $plan['counts']['children_skipped']);
        self::assertSame(1, $plan['counts']['tax_items_skipped']);
        self::assertSame(1, $plan['counts']['leave_records_skipped']);
        self::assertSame(1, $plan['counts']['average_records_skipped']);
        self::assertContains('employee_children_skipped', array_column($plan['warnings'], 'code'));
        self::assertContains('employee_tax_items_skipped', array_column($plan['warnings'], 'code'));
        self::assertContains('employee_leave_skipped', array_column($plan['warnings'], 'code'));
        self::assertContains('employee_averages_skipped', array_column($plan['warnings'], 'code'));
    }

    public function testUnknownTaxDeclarationStateIsNotInvented(): void
    {
        $tables = $this->tables();
        $tables['MMzdy'][0]['Prohlaseni'] = null;

        $plan = StereoNxEmployees::fromTables($tables, ['ico' => '12345679'], 1);

        self::assertSame([], $plan['records'][0]['tax_declarations']);
        self::assertContains('employee_tax_declaration_invalid', array_column($plan['warnings'], 'code'));
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        return [
            'MZAMEST' => [[
                'Prac' => 'TEST-1',
                'KrestniJmeno' => 'Jana',
                'Prijmeni' => 'Vzorová',
                'Narozeni' => '1992-06-20',
                'RC' => '920620/0102',
                'DatumNastupu' => '2025-01-01',
                'DatumUkonceni' => null,
                'PracPravVztah' => 'P',
                'Odvod' => 'HPP',
                'Zamestnanec' => true,
                'StatOrg' => false,
                'Vyrazen' => false,
                'TydUvazHod' => 40.0,
                'MesTarif' => 0.0,
                'HodTarif' => 0.0,
                'Pojistovna' => 'synthetic-insurer-key',
                'Email' => 'jana.vzorova@example.test',
                'Telefon' => '+420 777 000 111',
            ]],
            'MMzdy' => [[
                'Klic' => 1,
                'Rok' => 2025,
                'Mesic' => 1,
                'Prac' => 'TEST-1',
                'HrubaMzda' => 28_000.0,
                'Prohlaseni' => true,
            ]],
            'MPOJIST' => [[
                'Pojistovna' => 'synthetic-insurer-key', 'KodZP' => '111',
            ]],
            'MDeti' => [],
            'MOpNezdC' => [],
            'MDovol' => [],
            'MPRVYD' => [],
        ];
    }
}
