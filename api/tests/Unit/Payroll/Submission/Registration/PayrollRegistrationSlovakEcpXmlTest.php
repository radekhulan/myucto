<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSlovakBirthNumberRule;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use PHPUnit\Framework\TestCase;

/**
 * REGZEC A1 občana SR narozeného po 31. 12. 1992, který má na kartě rodné číslo
 * i EČP: do `client/@bno` jde EČP. Slovenské rodné číslo takové osoby se do
 * přihlášky neuvádí (pokyny REGZEC, ID 10057) a přihlášku s EČP ČSSZ přijímá.
 * Bez EČP zůstává rodné číslo s varováním.
 *
 * Data jsou syntetická.
 */
final class PayrollRegistrationSlovakEcpXmlTest extends TestCase
{
    private const BIRTH_NUMBER = '9352030006';
    private const ECP = '1000000005';

    public function testSlovakCitizenBornAfter1992SendsTheEcp(): void
    {
        $xml = self::serializeRegzecA1('SK', '1993-02-03', self::BIRTH_NUMBER, self::ECP);

        self::assertMatchesRegularExpression('/<client [^>]*bno="' . self::ECP . '"/', $xml);
        self::assertStringNotContainsString(self::BIRTH_NUMBER, $xml);
    }

    public function testWithoutEcpTheBirthNumberStaysAndIsWarned(): void
    {
        $xml = self::serializeRegzecA1('SK', '1993-02-03', self::BIRTH_NUMBER, null);

        self::assertMatchesRegularExpression('/<client [^>]*bno="' . self::BIRTH_NUMBER . '"/', $xml);
        self::assertNotNull(PayrollRegistrationSlovakBirthNumberRule::warning('SK', self::BIRTH_NUMBER, '1993-02-03'));
    }

    public function testEcpRemovesTheWarning(): void
    {
        self::assertNull(PayrollRegistrationSlovakBirthNumberRule::warning('SK', self::BIRTH_NUMBER, '1993-02-03', self::ECP));
    }

    public function testOtherPersonsKeepTheBirthNumberFirst(): void
    {
        self::assertSame(self::BIRTH_NUMBER, PayrollRegistrationSlovakBirthNumberRule::bno('CZ', self::BIRTH_NUMBER, self::ECP, '1993-02-03'));
        self::assertSame('9152030008', PayrollRegistrationSlovakBirthNumberRule::bno('SK', '9152030008', self::ECP, '1991-02-03'));
        self::assertSame(self::ECP, PayrollRegistrationSlovakBirthNumberRule::bno('SK', null, self::ECP, '1993-02-03'));
        self::assertNull(PayrollRegistrationSlovakBirthNumberRule::bno('SK', null, null, '1993-02-03'));
    }

    private static function serializeRegzecA1(string $citizenship, string $birthDate, ?string $birthNumber, ?string $ecp): string
    {
        $identity = [
            'first_name' => 'Jana',
            'last_name' => 'Novotná',
            'title_prefix' => null,
            'title_suffix' => null,
            'birth_surname' => 'Nováková',
            'birth_date' => $birthDate,
            'birth_place' => 'Testov',
            'birth_country_code' => $citizenship,
            'citizenship_country_code' => $citizenship,
            'sex' => 'female',
        ];
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['facts']['highest_education_code'] = 'T';
        $source['proof_identity'] = ['type_code' => 'P', 'number' => 'SYN-54321', 'foreign_issuer' => 'Syntetický úřad', 'country_code' => $citizenship];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            $identity,
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'],
        );
        $snapshot = new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => 'REGZEC25',
                'effective_on' => '2026-08-04',
            ],
            identity: $identity,
            identifiers: [
                'birth_number' => $birthNumber,
                'ecp' => $ecp,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
        $payload = new PayrollRegistrationXmlPayload(
            identity: $snapshot,
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: null,
        );

        return (new PayrollRegistrationXmlSerializer())->serialize($payload);
    }
}
