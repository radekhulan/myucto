<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Podmíněně povinné a zakázané atributy REGZEC A1 podle EDV 1.4.0.6.
 *
 * XSD ČSSZ má skoro všechno `minOccurs=0`, takže "XSD zelené" nic nedokazuje:
 * povinnost podle občanství, státu rezidence a druhu oprávnění žije jen
 * v legendě EDV (porušení vede k zamítnutí) a hlídá ji builder snímku.
 * Zdroj dat je syntetický.
 */
final class PayrollRegistrationA1ConditionalFieldsTest extends TestCase
{
    /**
     * ID 10071 foreigninst, 10526 preplace, 10248 edu, 10061/10062 taxidrezid.
     * Zdroj cizince bez těchto údajů dřív prošel bez jediné vady.
     */
    public function testForeignerRequiresIssuerPlannedWorkplaceRequiredEducationAndTaxIdentifier(): void
    {
        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            self::foreignSource('1', '1'),
            self::foreignIdentity(),
            self::scope(),
        );

        $fields = array_column($problems, 'field');
        foreach ([
            'proof_identity.foreign_issuer',
            'employment.expected_workplaces',
            'employment.required_education_code',
            'tax_residency.identifier_type',
            'tax_residency.identifier',
        ] as $expected) {
            self::assertContains($expected, $fields, $expected);
        }
    }

    public function testSpecVariantDoesNotRequireEducationButRequiresPlannedWorkplace(): void
    {
        $source = self::foreignSource('11', '1');
        $source['employment']['required_education_code'] = null;

        $fields = array_column(
            (new PayrollRegistrationA1SnapshotBuilder())->problems(
                $source,
                self::foreignIdentity(),
                self::scope(),
            ),
            'field',
        );

        self::assertNotContains('employment.required_education_code', $fields);
        self::assertContains('employment.expected_workplaces', $fields);
        self::assertContains('proof_identity.foreign_issuer', $fields);
    }

    public function testCompleteForeignerSerializesConditionalAttributesAndPassesXsd(): void
    {
        $source = self::foreignSource('1', '1');
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['employment']['required_education_code'] = 'T';
        $source['proof_identity']['foreign_issuer'] = 'Municipal office, Testov';
        $source['tax_residency']['identifier_type'] = 'D';
        $source['tax_residency']['identifier'] = 'SK123456789';

        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::foreignIdentity(),
            self::scope(),
        );
        $xml = self::serialize($a1);

        self::assertStringContainsString(' foreigninst="Municipal office, Testov"', $xml);
        self::assertStringContainsString(' preplace="Sídlo zaměstnavatele"', $xml);
        self::assertStringContainsString('<prof clas="24110" edu="T"/>', $xml);
        self::assertStringContainsString('<taxidrezid type="D" num="SK123456789" stat="SK"/>', $xml);
    }

    /**
     * Občan ČR: 10526, 10248 a daňová identifikace (10061/10062 u rezidence
     * v ČR) jsou ZAKÁZANÉ. Hodnota ze zdroje se nesmí odeslat.
     */
    public function testCzechCitizenNeverSendsForeignOnlyAttributes(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['employment']['expected_workplaces'] = 'Testov';
        $source['employment']['required_education_code'] = 'T';
        $source['tax_residency']['identifier_type'] = 'D';
        $source['tax_residency']['identifier'] = 'CZ123456789';

        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );

        self::assertNull($a1->employment['expected_workplaces']);
        self::assertNull($a1->employment['required_education_code']);
        self::assertIsArray($a1->taxResidency);
        self::assertNull($a1->taxResidency['identifier_type']);
        self::assertNull($a1->taxResidency['identifier']);
        $xml = self::serialize($a1);
        self::assertStringNotContainsString(' preplace=', $xml);
        self::assertStringNotContainsString(' edu=', $xml);
        self::assertStringContainsString('<taxidrezid stat="CZ"/>', $xml);
    }

    /** ID 10107: krajská pobočka ÚP je povinná jen u povolení k zaměstnání. */
    public function testLabourOfficeIsRequiredOnlyForEmploymentPermit(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        $permit = [
            'free_access' => false,
            'free_access_reason_code' => null,
            'permit_type_code' => '1',
            'issuing_labour_office_code' => null,
            'permit_identifier' => 'SYN-0001',
            'permit_from' => '2026-01-01',
            'permit_to' => '2027-01-01',
        ];
        $source = self::completeForeignSource();
        $source['foreign_worker'] = $permit;

        self::assertContains(
            'foreign_worker.issuing_labour_office_code',
            array_column(
                $builder->problems($source, self::foreignIdentity(), self::scope()),
                'field',
            ),
        );

        $source['foreign_worker']['issuing_labour_office_code'] = 'HMP';
        self::assertSame(
            [],
            $builder->problems($source, self::foreignIdentity(), self::scope()),
        );

        // Zaměstnanecká karta: pobočka se neuvádí, zadaná hodnota se zahodí.
        $source['foreign_worker']['permit_type_code'] = '2';
        $a1 = $builder->build($source, self::foreignIdentity(), self::scope());
        self::assertNull($a1->foreignWorker['issuing_labour_office_code']);
        self::assertStringNotContainsString(' issue=', self::serialize($a1));
    }

    /** ID 10092 a 10099: u druhu činnosti "N" je cizozemský nositel povinný. */
    public function testActivityNRequiresForeignInsurerSpecification(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('N', '1');
        $source['employment']['employment_status_code'] = '1111';

        $fields = array_column(
            $builder->problems($source, PayrollRegistrationA1SnapshotBuilderTest::identity(), self::scope()),
            'field',
        );
        self::assertContains('foreign_insurance.current', $fields);
        self::assertContains('foreign_insurance.country_code', $fields);

        // Hodnota "N" (není) povinnost nespouští, u "N" je nepřípustná.
        $source['foreign_insurance'] = ['current' => 'N', 'country_code' => 'DE'];
        self::assertContains(
            'foreign_insurance.current',
            array_column(
                $builder->problems($source, PayrollRegistrationA1SnapshotBuilderTest::identity(), self::scope()),
                'field',
            ),
        );

        $source['foreign_insurance'] = [
            'current' => 'P',
            'name' => 'Syntetická pojišťovna',
            'country_code' => 'DE',
            'identifier' => 'SYN-12345',
            'sector' => '06',
        ];
        $a1 = $builder->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $xml = self::serialize($a1);

        self::assertStringContainsString(
            '<forin cur="P" nam="Syntetická pojišťovna" cnt="DE" id="SYN-12345" sec="06"/>',
            $xml,
        );
        // employeeType: job, forin, pens, insh, fact...
        self::assertLessThan(strpos($xml, '<pens'), strpos($xml, '<forin'));
        self::assertGreaterThan(strpos($xml, '<job'), strpos($xml, '<forin'));
    }

    /** Serializer je poslední pojistka: A1 "N" bez nositele nesmí odejít. */
    public function testSerializerRefusesActivityNWithoutForeignInsurer(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('N', '1');
        $source['foreign_insurance'] = ['current' => 'S', 'country_code' => 'DE'];
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $withoutInsurer = new PayrollRegistrationA1Snapshot(
            $a1->variant,
            $a1->source,
            $a1->permanentAddress,
            $a1->taxResidency,
            $a1->employment,
            $a1->pension,
            $a1->healthInsuranceCode,
            $a1->facts,
            $a1->foreignLegislation,
            $a1->proofIdentity,
            $a1->foreignWorker,
            $a1->czechResidenceAddress,
            $a1->contactAddress,
            $a1->attachments,
        );

        try {
            self::serialize($withoutInsurer);
            self::fail('Očekávána chyba chybějícího cizozemského nositele.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame(
                'registration_regzec_a1_foreign_insurance_missing',
                $exception->validationCode,
            );
        }
    }

    public function testForeignInsurerAddressIsAllOrNothing(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['foreign_insurance'] = [
            'current' => 'S',
            'country_code' => 'DE',
            'street' => 'Teststraße',
        ];

        $fields = array_column(
            (new PayrollRegistrationA1SnapshotBuilder())->problems(
                $source,
                PayrollRegistrationA1SnapshotBuilderTest::identity(),
                self::scope(),
            ),
            'field',
        );

        foreach (['house_number', 'postal_code', 'city'] as $leaf) {
            self::assertContains('foreign_insurance.' . $leaf, $fields);
        }
    }

    /** `pnu` je na typu bez mezer, `fdr` dokonce `\d{5}`; "602 00" dřív neprošlo. */
    public function testPostalCodeIsNormalizedToTheShapeTheSchemaAccepts(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['permanent_address']['postal_code'] = ' 602 00 ';
        $source['permanent_address']['country_code'] = 'SK';
        $source['czech_residence_address'] = [
            'street' => 'Testovací',
            'house_number' => '5',
            'orientation_number' => null,
            'city' => 'Brno',
            'postal_code' => "602\u{00A0}00",
            'country_code' => 'CZ',
            'ruian_point' => null,
        ];

        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );

        self::assertSame('60200', $a1->permanentAddress['postal_code']);
        self::assertSame('60200', $a1->czechResidenceAddress['postal_code'] ?? null);
        $xml = self::serialize($a1);
        self::assertStringContainsString(' pnu="60200"', $xml);
        self::assertStringNotContainsString('pnu="602', str_replace('pnu="60200"', '', $xml));
    }

    public function testCzechResidenceAddressNeedsFiveDigitPostalCode(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['permanent_address']['country_code'] = 'SK';
        $source['czech_residence_address'] = [
            'street' => null,
            'house_number' => '5',
            'orientation_number' => null,
            'city' => 'Brno',
            'postal_code' => '6020',
            'country_code' => 'CZ',
            'ruian_point' => null,
        ];

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $byField = array_column($problems, 'message', 'field');

        self::assertArrayHasKey('czech_residence_address.postal_code', $byField);
        self::assertStringContainsString('pět číslic', $byField['czech_residence_address.postal_code']);
    }

    /** REGZEC25.xsd: `fact/healtrest` nemá maxOccurs, tedy jediné omezení. */
    public function testMoreThanOneHealthRestrictionIsAHumanProblem(): void
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['facts']['health_restrictions'] = [
            ['type_code' => '1', 'from' => '2025-01-01', 'to' => null],
            ['type_code' => '2', 'from' => '2025-02-01', 'to' => null],
        ];

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $byField = array_column($problems, 'message', 'field');

        self::assertArrayHasKey('facts.health_restrictions', $byField);
        self::assertStringContainsString('jediné omezení', $byField['facts.health_restrictions']);
        self::assertStringNotContainsString('XSD', $byField['facts.health_restrictions']);
    }

    /** @return array<string,mixed> */
    private static function foreignSource(string $activity, string $detail): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source($activity, $detail);
        $source['employment']['expected_workplaces'] = null;
        $source['employment']['required_education_code'] = null;
        $source['tax_residency'] = [
            'country_code' => 'SK',
            'identifier_type' => null,
            'identifier' => null,
            'residence_address' => [
                'street' => 'Testovacia',
                'house_number' => '7',
                'orientation_number' => null,
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country_code' => 'SK',
                'ruian_point' => null,
            ],
        ];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => null,
            'country_code' => 'SK',
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '1',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function completeForeignSource(): array
    {
        $source = self::foreignSource('1', '1');
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['employment']['required_education_code'] = 'T';
        $source['proof_identity']['foreign_issuer'] = 'Municipal office, Testov';
        $source['tax_residency']['identifier_type'] = 'D';
        $source['tax_residency']['identifier'] = 'SK123456789';

        return $source;
    }

    /** @return array<string,mixed> */
    private static function foreignIdentity(): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = 'SK';
        $identity['birth_country_code'] = 'SK';

        return $identity;
    }

    /** @return array<string,mixed> */
    private static function scope(): array
    {
        return [
            'supplier_id' => 11,
            'employee_id' => 41,
            'employment_id' => 51,
            'effective_on' => '2026-08-05',
        ];
    }

    private static function serialize(PayrollRegistrationA1Snapshot $a1): string
    {
        $identity = $a1->proofIdentity !== null
            ? self::foreignIdentity()
            : PayrollRegistrationA1SnapshotBuilderTest::identity();
        $payload = new PayrollRegistrationXmlPayload(
            identity: new PayrollRegistrationIdentitySnapshot(
                scope: [
                    'supplier_id' => 11,
                    'submission_id' => 21,
                    'source_revision_id' => null,
                    'employee_id' => 41,
                    'employment_id' => 51,
                    'environment' => 'production',
                    'agenda_code' => 'REGZEC25',
                    'effective_on' => '2026-08-05',
                ],
                identity: $identity,
                identifiers: [
                    'birth_number' => '9152031234',
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                employmentExternalIdentifier: null,
                registrationEligibility: [
                    'status' => 'not_applicable',
                    'basis' => 'agenda_not_prezec',
                ],
                sourceVersions: ['regzec_a1' => $a1->source],
                regzecA1: $a1,
            ),
            interaction: new PayrollRegistrationInteraction(
                'REGZEC25',
                'direct_full_registration',
                1,
            ),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        return $xml;
    }
}
