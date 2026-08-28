<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA1SnapshotBuilderTest extends TestCase
{
    public function testBuildsAllAuthoritativeA1Variants(): void
    {
        foreach ([
            ['1', '1', 'OST'],
            ['10', null, '10'],
            ['11', '1', 'SPEC'],
        ] as [$activityCode, $detailCode, $variant]) {
            $source = self::source($activityCode, $detailCode);
            $snapshot = (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                self::identity(),
                self::scope(),
            );

            self::assertSame($variant, $snapshot->variant);
            self::assertSame($activityCode, $snapshot->employment['activity_code']);
            self::assertSame(
                'a1-source-synthetic',
                $snapshot->source['source_key'],
            );
            self::assertSame($snapshot->toArray(), $snapshot->toArray());
        }
    }

    public function testMissingVariantFieldFailsClosed(): void
    {
        $source = self::source('1', '1');
        unset($source['facts']['highest_education_code']);

        $this->expectCode(
            'registration_regzec_a1_required_field_missing',
            static fn () => (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                self::identity(),
                self::scope(),
            ),
        );
    }

    public function testAllA1VariantsSerializeAndPassPinnedXsd(): void
    {
        foreach ([['1', '1'], ['10', null], ['11', '1']] as [$activity, $detail]) {
            $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
                self::source($activity, $detail),
                self::identity(),
                self::scope(),
            );
            $snapshot = self::snapshot($a1);
            $payload = new PayrollRegistrationXmlPayload(
                identity: $snapshot,
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
                employerVariableSymbol: '1234567890',
                employerName: 'Syntetický zaměstnavatel s.r.o.',
                csszWorkplaceCode: '110',
            );
            $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

            (new PayrollRegistrationXmlValidator(
                new PayrollRegistrationSchemaCatalog(),
            ))->validate($payload, $xml);
            self::assertStringContainsString(' rel="' . $activity . '"', $xml);
            self::assertStringContainsString('<adr ', $xml);
            if ($activity === '10') {
                self::assertStringNotContainsString('<taxidrezid', $xml);
                self::assertStringNotContainsString('<insh', $xml);
            }
        }
    }

    public function testForeignIdentityRequiresProofAndLabourMarketDecision(): void
    {
        $identity = self::identity();
        $identity['citizenship_country_code'] = 'SK';
        $source = self::source('11', '1');

        $this->expectCode(
            'registration_regzec_a1_foreign_data_missing',
            static fn () => (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                $identity,
                self::scope(),
            ),
        );
    }

    public function testIdentityBuilderFreezesA1SourceAndItsProvenance(): void
    {
        $source = [
            'identity' => self::identity() + [
                'id' => 301,
                'employee_id' => 41,
                'title_suffix' => null,
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
            'identifier_sources' => [
                'birth_number' => ['id' => 302, 'row_version' => 1],
            ],
            'employment_external_identifier' => null,
            'resolution' => [
                'person_identity' => 'resolved',
                'employment_external_id' => 'not_assigned',
            ],
            'regzec_a1' => self::source(),
        ];
        $scope = self::scope() + [
            'submission_id' => 21,
            'source_revision_id' => null,
            'environment' => 'production',
            'agenda_code' => 'REGZEC25',
        ];

        $snapshot = (new PayrollRegistrationIdentitySnapshotBuilder())->build(
            $scope,
            $source,
        );

        self::assertNotNull($snapshot->regzecA1);
        self::assertTrue($snapshot->toArray()['official_submission']['supported']);
        self::assertSame(
            701,
            $snapshot->sourceVersions['regzec_a1']['source_id'],
        );
    }

    public function testA1SourceCannotCrossEmploymentScope(): void
    {
        $source = self::source();
        $source['source']['employment_id'] = 999;

        $this->expectCode(
            'registration_regzec_a1_source_scope_mismatch',
            static fn () => (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                self::identity(),
                self::scope(),
            ),
        );
    }

    /** @return array<string,mixed> */
    public static function source(
        string $activityCode = '1',
        ?string $detailCode = '1',
    ): array {
        return [
            'source' => [
                'source_key' => 'a1-source-synthetic',
                'source_id' => 701,
                'row_version' => 3,
                'reference_hash' => str_repeat('a', 64),
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'effective_on' => '2026-08-05',
            ],
            'permanent_address' => [
                'street' => 'Testovací',
                'house_number' => '12',
                'orientation_number' => '3',
                'city' => 'Testov',
                'postal_code' => '11000',
                'country_code' => 'CZ',
                'ruian_point' => null,
            ],
            'tax_residency' => [
                'country_code' => 'CZ',
                'identifier_type' => null,
                'identifier' => null,
                'residence_address' => null,
            ],
            'employment' => [
                'activity_code' => $activityCode,
                'relationship_detail_code' => $detailCode,
                'actual_start_on' => '2026-08-05',
                'contract_start_on' => '2026-08-05',
                'small_scale' => false,
                'employment_status_code' => '1',
                'work_mode_code' => '1',
                'continuous_operation' => false,
                'prevailing_workplace_code' => '1',
                'expected_workplaces' => 'Testov',
                'contract_workplace' => 'Testov',
                'workplace_city' => 'Testov',
                'workplace_municipality_code' => '554782',
                'profession_code' => '24110',
                'required_education_code' => 'T',
                'position_name' => 'Účetní',
                'leadership' => false,
            ],
            'pension' => [
                'type_code' => null,
                'received_from' => null,
                'early_retirement' => false,
                'reduced_retirement_age' => false,
            ],
            'health_insurance_code' => '111',
            'facts' => [
                'highest_education_code' => 'T',
                'disability_card' => false,
                'health_restrictions' => [],
            ],
            'foreign_legislation' => [
                'applies' => false,
                'country_code' => null,
            ],
            'proof_identity' => null,
            'foreign_worker' => null,
            'czech_residence_address' => null,
            'contact_address' => null,
            'attachments' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function identity(): array
    {
        return [
            'first_name' => 'Jana',
            'last_name' => 'Novotná',
            'title_prefix' => 'Ing.',
            'birth_surname' => 'Nováková',
            'previous_surnames' => null,
            'birth_date' => '1991-02-03',
            'birth_place' => 'Testov',
            'birth_country_code' => 'CZ',
            'citizenship_country_code' => 'CZ',
            'sex' => 'female',
        ];
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

    private static function snapshot(
        \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot $a1,
    ): PayrollRegistrationIdentitySnapshot {
        return new PayrollRegistrationIdentitySnapshot(
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
            identity: self::identity(),
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
        );
    }

    /** @param callable():mixed $callback */
    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }
}

