<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityRequirements;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Jméno a místo narození v registračních větách.
 *
 * REG-07: PREZEC nemá atribut pro stát narození, mimo ČR se stát píše za obec
 * ("obec, stát", Všeobecné zásady Předregistrace, ID 10066).
 * REG-08: REGZEC nese tituly před i za jménem (ID 10055) a dřívější příjmení
 * (ID 10064). Data jsou syntetická.
 */
final class PayrollRegistrationNameAndBirthPlaceXmlTest extends TestCase
{
    public function testPrezecBornAbroadCarriesTheStateAfterTheMunicipality(): void
    {
        $xml = self::serializePrezec(self::identity([
            'birth_place' => 'Bratislava',
            'birth_country_code' => 'SK',
        ]));

        self::assertStringContainsString('<birth nam="Nováková" cit="Bratislava, Slovensko"/>', $xml);
    }

    public function testPrezecBornInCzechRepublicKeepsTheBareMunicipality(): void
    {
        $xml = self::serializePrezec(self::identity());

        self::assertStringContainsString('<birth nam="Nováková" cit="Testov"/>', $xml);
    }

    public function testPrezecWithoutBirthStateStaysClosedWithAHumanMessage(): void
    {
        try {
            self::serializePrezec(self::identity(['birth_country_code' => null]));
            self::fail('Očekávána chyba chybějícího státu narození.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame('registration_identity_invalid', $exception->validationCode);
            self::assertStringStartsWith('Stát narození', $exception->getMessage());
        }
    }

    /** PREZEC26-nam-1: EDV 1.2 (podání v roce 2026) drží rodné příjmení povinné. */
    public function testPrezecFiledIn2026StillRequiresTheBirthSurname(): void
    {
        try {
            self::serializePrezec(self::identity(['birth_surname' => null]), '2026-12-20', '2027-01-04');
            self::fail('Očekávána chyba chybějícího rodného příjmení.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame('registration_identity_invalid', $exception->validationCode);
            self::assertStringStartsWith('Rodné příjmení', $exception->getMessage());
        }
    }

    /** PREZEC26-nam-1: EDV PREZEC 1.5 (podání od 1. 1. 2027) rodné příjmení nevyžaduje. */
    public function testPrezecFiledFrom2027OmitsAMissingBirthSurname(): void
    {
        $xml = self::serializePrezec(self::identity(['birth_surname' => null]), '2027-01-02', '2027-01-04');

        self::assertStringContainsString('<birth cit="Testov"/>', $xml);
        self::assertStringContainsString(
            '<birth nam="Nováková" cit="Testov"/>',
            self::serializePrezec(self::identity(), '2027-01-02', '2027-01-04'),
        );
    }

    public function testBirthPlaceLongerThanTheSchemaAllowsIsAHumanMessageNotAnXsdDump(): void
    {
        $place = str_repeat('Dlouhé-Město-', 4);
        try {
            self::serializePrezec(self::identity([
                'birth_place' => $place,
                'birth_country_code' => 'SK',
            ]));
            self::fail('Očekávána chyba příliš dlouhého místa narození.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame('registration_identity_invalid', $exception->validationCode);
            self::assertStringContainsString('nejvýš 50 znaků', $exception->getMessage());
            self::assertStringNotContainsString('XSD', $exception->getMessage());
        }
    }

    public function testPrezecPreparationAsksForTheBirthState(): void
    {
        $identity = [
            'first_name' => 'Jana',
            'last_name' => 'Novotná',
            'birth_surname' => 'Nováková',
            'birth_place' => 'Testov',
            'citizenship_country_code' => 'CZ',
        ];

        $fields = array_column(
            PayrollRegistrationIdentityRequirements::missing(
                PayrollRegistrationIdentityRequirements::AGENDA_PREZEC,
                $identity,
                ['birth_number' => '9152031234'],
            ),
            'field',
        );

        self::assertContains('identity.birth_country_code', $fields);
    }

    public function testRegzecA1CarriesTitlesBeforeAndAfterTheNameAndPreviousSurnames(): void
    {
        $xml = self::serializeRegzecA1(self::identity([
            'title_prefix' => 'Ing.',
            'title_suffix' => 'Ph.D.',
            'previous_surnames' => 'Dvořáková, Nguyen Quoc',
        ]));

        self::assertStringContainsString(
            '<name sur="Novotná" fir="Jana" tit="Ing. Ph.D." ona="Dvořáková, Nguyen Quoc"/>',
            $xml,
        );
    }

    public function testRegzecA1WithoutHistoryKeepsTheShortName(): void
    {
        $xml = self::serializeRegzecA1(self::identity());

        self::assertStringContainsString('<name sur="Novotná" fir="Jana" tit="Ing."/>', $xml);
        self::assertStringNotContainsString(' ona=', $xml);
    }

    public function testTitlesLongerThanThirtyCharactersAreAHumanMessage(): void
    {
        try {
            self::serializeRegzecA1(self::identity([
                'title_prefix' => 'Prof. Ing. Mgr. Bc.',
                'title_suffix' => 'Ph.D., DrSc., MBA',
            ]));
            self::fail('Očekávána chyba příliš dlouhých titulů.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame('registration_identity_invalid', $exception->validationCode);
            self::assertStringContainsString('nejvýš 30 znaků', $exception->getMessage());
        }
    }

    /** Kontrakt s E2: delta identity nese `previous_surnames`, A3 z něj zapíše `ona`. */
    public function testRegzecA3DeltaWritesPreviousSurnamesAndBothTitles(): void
    {
        $serializer = new PayrollRegistrationXmlSerializer();
        $payload = self::eventPayload([
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'delta' => [
                'identity' => [
                    'last_name' => 'Nová',
                    'first_name' => 'Jana',
                    'previous_surnames' => 'Novotná, Nováková',
                ],
                'title_prefix' => 'Ing.',
                'title_suffix' => 'Ph.D.',
            ],
        ]);
        $xml = $serializer->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        self::assertStringContainsString(
            '<name sur="Nová" fir="Jana" tit="Ing. Ph.D." ona="Novotná, Nováková"/>',
            $xml,
        );
    }

    /** EDV 1.4.0.6, ID 10064: u varianty 10 je `ona` v matici „/", podání s ním ČSSZ zamítne. */
    public function testRegzecA1Variant10NeverWritesPreviousSurnames(): void
    {
        $xml = self::serializeRegzecA1(
            self::identity([
                'title_prefix' => 'Ing.',
                'previous_surnames' => 'Dvořáková, Nguyen Quoc',
            ]),
            '10',
            null,
        );

        self::assertStringContainsString(' rel="10"', $xml);
        self::assertStringContainsString('<name sur="Novotná" fir="Jana" tit="Ing."/>', $xml);
        self::assertStringNotContainsString(' ona=', $xml);
    }

    public function testRegzecA3AndA4Variant10NeverWritePreviousSurnames(): void
    {
        foreach ([3, 4] as $action) {
            $payload = self::eventPayload([
                'activity_code' => '10',
                'delta' => [
                    'identity' => [
                        'last_name' => 'Nová',
                        'first_name' => 'Jana',
                        'previous_surnames' => 'Novotná, Nováková',
                    ],
                ],
            ], $action);
            $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
            (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
                ->validate($payload, $xml);

            self::assertStringContainsString('<name sur="Nová" fir="Jana"/>', $xml, "A{$action}");
            self::assertStringNotContainsString(' ona=', $xml, "A{$action}");
        }
    }

    public function testRegzecA4StandardVariantKeepsPreviousSurnames(): void
    {
        $payload = self::eventPayload([
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'delta' => [
                'identity' => [
                    'last_name' => 'Nová',
                    'first_name' => 'Jana',
                    'previous_surnames' => 'Novotná',
                ],
            ],
        ], 4);
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

        self::assertStringContainsString('<name sur="Nová" fir="Jana" ona="Novotná"/>', $xml);
    }

    /** Snímek identity dřívější příjmení přenese; bez nich klíč nevznikne. */
    public function testIdentitySnapshotCarriesPreviousSurnamesOnlyWhenPresent(): void
    {
        $with = self::frozenIdentity(['previous_surnames' => 'Dvořáková']);
        $without = self::frozenIdentity([]);

        self::assertSame('Dvořáková', $with->identity['previous_surnames']);
        self::assertArrayNotHasKey('previous_surnames', $without->identity);
    }

    /** @param array<string,mixed> $extra */
    private static function frozenIdentity(array $extra): PayrollRegistrationIdentitySnapshot
    {
        return (new PayrollRegistrationIdentitySnapshotBuilder())->build(
            [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => 'REGZEC25',
                'effective_on' => '2026-08-04',
            ],
            [
                'identity' => $extra + [
                    'id' => 111,
                    'employee_id' => 41,
                    'first_name' => 'Jana',
                    'last_name' => 'Novotná',
                    'title_prefix' => null,
                    'title_suffix' => null,
                    'birth_surname' => 'Nováková',
                    'birth_date' => '1991-02-03',
                    'birth_place' => 'Testov',
                    'birth_country_code' => 'CZ',
                    'citizenship_country_code' => 'CZ',
                    'sex' => 'female',
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 2,
                ],
                'identifiers' => [
                    'birth_number' => '9152031234',
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                'identifier_sources' => ['birth_number' => ['id' => 121, 'row_version' => 1]],
                'employment_external_identifier' => null,
                'resolution' => [
                    'person_identity' => 'resolved',
                    'employment_external_id' => 'not_assigned',
                ],
            ],
        );
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private static function identity(array $override = []): array
    {
        return array_replace([
            'first_name' => 'Jana',
            'last_name' => 'Novotná',
            'title_prefix' => 'Ing.',
            'title_suffix' => null,
            'birth_surname' => 'Nováková',
            'birth_date' => '1991-02-03',
            'birth_place' => 'Testov',
            'birth_country_code' => 'CZ',
            'citizenship_country_code' => 'CZ',
            'sex' => 'female',
        ], $override);
    }

    /** @param array<string,mixed> $identity */
    private static function serializePrezec(
        array $identity,
        string $preparedOn = '2026-08-04',
        string $expectedStartOn = '2026-08-05',
    ): string {
        $payload = self::payload(
            self::snapshot($identity, 'PREZEC26'),
            new PayrollRegistrationInteraction('PREZEC26', 'limited_pre_registration', 9),
            expectedStartOn: $expectedStartOn,
            preparedOn: $preparedOn,
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        return $xml;
    }

    /** @param array<string,mixed> $identity */
    private static function serializeRegzecA1(
        array $identity,
        string $activityCode = '1',
        ?string $detailCode = '1',
    ): string {
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            PayrollRegistrationA1SnapshotBuilderTest::source($activityCode, $detailCode),
            $identity,
            [
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'effective_on' => '2026-08-05',
            ],
        );
        $payload = self::payload(
            self::snapshot($identity, 'REGZEC25', $a1),
            new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        return $xml;
    }

    /** @param array<string,mixed> $data */
    private static function eventPayload(array $data, int $action = 3): PayrollRegistrationXmlPayload
    {
        return self::payload(
            self::snapshot(self::identity(), 'REGZEC25'),
            new PayrollRegistrationInteraction(
                'REGZEC25',
                $action === 3 ? 'change' : 'correction',
                $action,
            ),
            expectedStartOn: null,
            eventSnapshot: [
                'schema_reference' => 'payroll-registration-event-snapshot.v1',
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'interaction' => $action === 3 ? 'change' : 'correction',
                'action_code' => $action,
                'effective_on' => '2026-08-04',
                'notification_trigger_on' => '2026-08-04',
                'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
                'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
                'employer' => [
                    'variable_symbol' => '1100000007',
                    'name' => 'Syntetický zaměstnavatel s.r.o.',
                    'workplace_code' => '110',
                ],
                'data' => $data,
                'source' => ['kind' => 'synthetic', 'reference' => 'synthetic:change'],
            ],
        );
    }

    private static function payload(
        PayrollRegistrationIdentitySnapshot $snapshot,
        PayrollRegistrationInteraction $interaction,
        ?string $expectedStartOn = '2026-08-05',
        ?string $actualStartOn = null,
        ?array $eventSnapshot = null,
        string $preparedOn = '2026-08-04',
    ): PayrollRegistrationXmlPayload {
        return new PayrollRegistrationXmlPayload(
            identity: $snapshot,
            interaction: $interaction,
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: $preparedOn,
            expectedStartOn: $expectedStartOn,
            actualStartOn: $actualStartOn,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: $eventSnapshot,
        );
    }

    /** @param array<string,mixed> $identity */
    private static function snapshot(
        array $identity,
        string $agenda,
        mixed $a1 = null,
    ): PayrollRegistrationIdentitySnapshot {
        return new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => $agenda,
                'effective_on' => '2026-08-04',
            ],
            identity: $identity,
            identifiers: [
                'birth_number' => '9152031234',
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: $agenda === 'PREZEC26'
                ? [
                    'status' => 'verified',
                    'basis' => 'domestic_citizenship_country_code',
                    'citizenship_country_code' => 'CZ',
                ]
                : ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: $a1 === null ? [] : ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
    }
}
