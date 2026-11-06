<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * EDV REGZEC25 1.4.0.7 (14. 10. 2026): Kontrola 5 (údaje cizince v A3/A4
 * jako ucelená sada se státním občanstvím 10067), občan EU a 10009 <= dnes
 * platí i pro ročník 2026. Data jsou syntetická.
 */
final class PayrollRegistrationRegzec25Edv1407Test extends TestCase
{
    private const START = '2026-08-05';

    private const EFFECTIVE = '2026-08-04';

    private const PREPARED = '2026-08-12';

    public function testFreeAccessSetCarriesCitizenshipAndNoPermitData(): void
    {
        $xml = self::validated(self::eventPayload(3, self::data(['free_access' => true, 'free_access_reason_code' => '2']), 'UA'));
        self::assertStringContainsString('<stat cnt="UA"/>', $xml);
        self::assertStringNotContainsString('issue=', $xml);
    }

    public function testPermitWithoutLabourOfficeCarriesCitizenshipAndNoOffice(): void
    {
        $xml = self::validated(self::eventPayload(4, self::data(self::permit('2')), 'UA'));
        self::assertStringContainsString('<stat cnt="UA"/>', $xml);
        self::assertStringNotContainsString('issue=', $xml);
    }

    public function testEmploymentPermitCarriesLabourOffice(): void
    {
        $worker = self::permit('1') + ['issuing_labour_office_code' => 'HMP'];
        $xml = self::validated(self::eventPayload(3, self::data($worker), 'UA'));
        self::assertStringContainsString('issue="HMP"', $xml);

        unset($worker['issuing_labour_office_code']);
        self::assertCode('registration_regzec27_foreign_worker_set_incomplete', self::eventPayload(3, self::data($worker), 'UA'));
    }

    public function testSurplusAttributesOfAnotherVariantAreRejected(): void
    {
        $withOffice = self::permit('2') + ['issuing_labour_office_code' => 'HMP'];
        self::assertCode('registration_regzec27_foreign_worker_set_incomplete', self::eventPayload(3, self::data($withOffice), 'UA'));

        $freeWithPermit = ['free_access' => true, 'free_access_reason_code' => '2', 'permit_type_code' => '2'];
        self::assertCode('registration_regzec27_foreign_worker_set_incomplete', self::eventPayload(4, self::data($freeWithPermit), 'UA'));
    }

    public function testCzechCitizenMustNotCarryForeignWorkerData(): void
    {
        self::assertCode(
            'registration_regzec27_foreign_worker_forbidden',
            self::eventPayload(3, self::data(['free_access' => true, 'free_access_reason_code' => '2']), 'CZ'),
        );
    }

    public function testEuCitizenHasFreeAccessWithReasonOne(): void
    {
        $ok = self::validated(self::eventPayload(3, self::data(['free_access' => true, 'free_access_reason_code' => '1']), 'SK'));
        self::assertStringContainsString('<stat cnt="SK"/>', $ok);
        self::assertCode('registration_regzec27_eu_free_access', self::eventPayload(3, self::data(['free_access' => true, 'free_access_reason_code' => '2']), 'SK'));
        self::assertCode('registration_regzec27_eu_free_access', self::eventPayload(3, self::data(self::permit('2')), 'SK'));
    }

    public function testChangeInTheFutureIsRejectedAlsoIn2026(): void
    {
        $payload = self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ], 'CZ', '2026-08-20');

        self::assertCode('registration_regzec27_change_in_future', $payload);
    }

    /**
     * @param array<string,mixed> $worker
     * @return array<string,mixed>
     */
    private static function data(array $worker): array
    {
        return [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'delta' => ['foreign_worker' => $worker],
        ];
    }

    /** @return array<string,mixed> */
    private static function permit(string $type): array
    {
        return [
            'free_access' => false,
            'permit_type_code' => $type,
            'permit_identifier' => 'SYN-PERMIT-1',
            'permit_from' => '2026-01-01',
            'permit_to' => '2027-12-31',
        ];
    }

    private static function validated(PayrollRegistrationXmlPayload $payload): string
    {
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))->validate($payload, $xml);

        return $xml;
    }

    private static function assertCode(string $code, PayrollRegistrationXmlPayload $payload): void
    {
        try {
            self::validated($payload);
            self::fail("Očekávaná vada {$code} nenastala.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $data */
    private static function eventPayload(
        int $action,
        array $data,
        string $citizenship,
        string $effectiveOn = self::EFFECTIVE,
    ): PayrollRegistrationXmlPayload {
        $interaction = [3 => 'change', 4 => 'correction'][$action];
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = $citizenship;
        $identity['birth_country_code'] = $citizenship;

        return new PayrollRegistrationXmlPayload(
            identity: new PayrollRegistrationIdentitySnapshot(
                scope: [
                    'supplier_id' => 11,
                    'submission_id' => 21,
                    'source_revision_id' => null,
                    'employee_id' => 41,
                    'employment_id' => 51,
                    'environment' => 'production',
                    'agenda_code' => 'REGZEC25',
                    'effective_on' => self::START,
                ],
                identity: $identity,
                identifiers: [
                    'birth_number' => $citizenship === 'CZ' ? '9152031234' : null,
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                employmentExternalIdentifier: null,
                registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
                sourceVersions: [],
                regzecA1: null,
            ),
            interaction: new PayrollRegistrationInteraction('REGZEC25', $interaction, $action),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::PREPARED,
            expectedStartOn: null,
            actualStartOn: null,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: [
                'schema_reference' => 'payroll-registration-event-snapshot.v1',
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'interaction' => $interaction,
                'action_code' => $action,
                'effective_on' => $effectiveOn,
                'notification_trigger_on' => $effectiveOn,
                'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
                'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
                'employer' => [
                    'variable_symbol' => '1100000007',
                    'name' => 'Syntetický zaměstnavatel s.r.o.',
                    'workplace_code' => '110',
                ],
                'data' => $data,
                'source' => ['kind' => 'synthetic', 'reference' => 'synthetic:' . $interaction],
            ],
            productName: 'MyÚčto.cz',
            productVersion: '6.48.1',
        );
    }
}
