<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRegistrationData;
use PHPUnit\Framework\TestCase;

final class AttendanceRegistrationDataTest extends TestCase
{
    private const NOTHING_KNOWN = [
        'residence_address' => false,
        'citizenship' => null,
        'cz_isco_code' => null,
        'activity_code' => null,
        'workplace' => false,
        'tax_residence' => false,
        'tax_declaration' => false,
        'a1_profile' => false,
    ];

    public function testPlanConvertsTemplateValuesToRegistrationSteps(): void
    {
        $plan = AttendanceRegistrationData::plan([
            'address_street' => 'Ukázková',
            'address_house_number' => '12/3',
            'address_city' => 'Praha',
            'address_postal_code' => '110 00',
            'citizenship' => 'sk',
            'cz_isco_code' => '43111',
            'activity_code' => 't',
            'workplace' => '554782',
            'tax_declaration' => 'Ano',
            'education_code' => 'm',
            'employment_status_code' => '1111',
        ], '2026-06-01');

        self::assertSame([], $plan['warnings']);
        self::assertSame([
            'addresses' => ['residence' => [
                'street_line' => 'Ukázková 12/3',
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
            ]],
            'identity_facts' => ['citizenship_country_code' => 'SK'],
            'terms' => [
                'cz_isco_code' => '43111',
                'activity_code' => 'T',
                'jmhz_workplace_municipality_code' => '554782',
                'jmhz_workplace_country_code' => 'CZ',
            ],
            // Bez sloupce rezidence platí ČR podle české adresy.
            'tax_residence' => ['residence' => 'czech-resident', 'country_code' => 'CZ', 'identifier' => null],
            'a1_profile' => [
                'employment' => ['employment_status_code' => '1111'],
                'facts' => ['highest_education_code' => 'M'],
            ],
        ], $plan['steps']);
        self::assertSame(['signed_on' => '2026-06-01'], $plan['declaration']);
    }

    public function testUnreadableValuesAreSkippedWithWarning(): void
    {
        $plan = AttendanceRegistrationData::plan([
            'address_city' => 'Praha',
            'citizenship' => 'Česko',
            'cz_isco_code' => 'skladník',
            'tax_declaration' => 'možná',
            'education_code' => 'Q',
        ], '2026-06-01');

        self::assertSame([], $plan['steps']);
        self::assertNull($plan['declaration']);
        self::assertCount(5, $plan['warnings']);
    }

    public function testGapsKeepOnlyDataMissingInRecords(): void
    {
        $plan = AttendanceRegistrationData::plan([
            'address_street' => 'Ukázková', 'address_house_number' => '12', 'address_city' => 'Praha',
            'address_postal_code' => '11000', 'citizenship' => 'CZ', 'cz_isco_code' => '43111',
            'workplace' => 'Praha', 'tax_declaration' => 'ano', 'education_code' => 'M',
        ], '2026-06-01');

        self::assertSame($plan, AttendanceRegistrationData::gaps($plan, self::NOTHING_KNOWN));

        $gaps = AttendanceRegistrationData::gaps($plan, [
            'residence_address' => true,
            'citizenship' => 'CZ',
            'cz_isco_code' => '93290',
            'activity_code' => 'T',
            'workplace' => false,
            'tax_residence' => true,
            'tax_declaration' => true,
            'a1_profile' => false,
        ]);
        self::assertSame(['terms' => ['work_place' => 'Praha'], 'a1_profile' => ['facts' => ['highest_education_code' => 'M']]], $gaps['steps']);
        self::assertNull($gaps['declaration']);
        self::assertSame(
            [['key' => 'workplace', 'value' => 'Praha'], ['key' => 'education_code', 'value' => 'M']],
            AttendanceRegistrationData::display($gaps),
        );
    }
}
