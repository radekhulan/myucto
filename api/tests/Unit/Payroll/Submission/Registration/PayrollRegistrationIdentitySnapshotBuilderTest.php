<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type ScopeFixture array{
 *   supplier_id:int,submission_id:int,source_revision_id:int,
 *   employee_id:int,employment_id:int,environment:string,
 *   agenda_code:string,effective_on:string
 * }
 * @phpstan-type IdentityFixture array{
 *   id:int,employee_id:int,first_name:string,last_name:string,
 *   title_prefix:?string,title_suffix:?string,birth_surname:?string,
 *   birth_date:?string,birth_place:?string,birth_country_code:?string,
 *   citizenship_country_code:?string,sex:?string,effective_from:string,
 *   effective_to:?string,row_version:int,full_name?:string
 * }
 * @phpstan-type ExternalFixture array{
 *   id:int,employee_id:int,employment_id:int,environment:string,
 *   identifier_type:string,value:string,valid_from:string,valid_to:?string,
 *   source_kind:string,source_receipt_id:?int,source_reference_hash:string,
 *   row_version:int
 * }
 * @phpstan-type SourceFixture array{
 *   identity:IdentityFixture,
 *   identifiers:array{
 *     birth_number:?string,ecp:?string,vcp:?string,
 *     foreign_tax_identifier:?string
 *   },
 *   identifier_sources:array<string,array{id:int,row_version:int}>,
 *   employment_external_identifier:ExternalFixture,
 *   resolution:array{person_identity:string,employment_external_id:string}
 * }
 */
final class PayrollRegistrationIdentitySnapshotBuilderTest extends TestCase
{
    private PayrollRegistrationIdentitySnapshotBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayrollRegistrationIdentitySnapshotBuilder();
    }

    public function testBuildsDeterministicRegzecSnapshotWithoutParsingDisplayName(): void
    {
        $source = $this->source();
        $source['identity']['full_name'] = 'Tento text není zdroj strukturovaného jména';

        $snapshot = $this->builder->build($this->scope(), $source);
        $reordered = $source;
        $reordered['identifiers'] = array_reverse(
            $reordered['identifiers'],
            true,
        );

        self::assertSame(
            $snapshot->canonicalJson(),
            $this->builder->build($this->scope(), $reordered)->canonicalJson(),
        );
        self::assertSame('Jana', $snapshot->identity['first_name']);
        self::assertSame('Novotná', $snapshot->identity['last_name']);
        self::assertArrayNotHasKey('full_name', $snapshot->identity);
        self::assertNull($snapshot->identifiers['birth_number']);
        self::assertSame(
            'FOREIGN-SYNTHETIC-001',
            $snapshot->identifiers['foreign_tax_identifier'],
        );
        self::assertNotNull($snapshot->employmentExternalIdentifier);
        self::assertSame(
            '100000000000000000001',
            $snapshot->employmentExternalIdentifier['value'],
        );
        $serialized = $snapshot->toArray();
        $officialSubmission = $serialized['official_submission'] ?? null;
        self::assertIsArray($officialSubmission);
        self::assertFalse($officialSubmission['supported']);
    }

    public function testPrezecRequiresBirthNumberOrEcpButBirthNumberIsNullable(): void
    {
        $source = $this->source();
        $source['employment_external_identifier'] = null;
        $source['resolution']['employment_external_id'] = 'not_assigned';
        $scope = $this->scope();
        $scope['agenda_code'] = 'PREZEC26';

        $this->expectCode(
            'registration_identity_prezec_bno_missing',
            fn () => $this->builder->build($scope, $source),
        );

        $source['identifiers']['ecp'] = '123456789';
        $source['identifier_sources']['ecp'] = [
            'id' => 122,
            'row_version' => 1,
        ];
        $snapshot = $this->builder->build($scope, $source);
        self::assertNull($snapshot->identifiers['birth_number']);
        self::assertSame(
            'domestic_citizenship_country_code',
            $snapshot->registrationEligibility['basis'],
        );
    }

    public function testRejectsUnresolvedPersonIdentity(): void
    {
        $source = $this->source();
        $source['resolution']['person_identity'] = 'unresolved';

        $this->expectCode(
            'registration_identity_unresolved',
            fn () => $this->builder->build($this->scope(), $source),
        );
    }

    public function testRejectsCrossEnvironmentIdPpv(): void
    {
        $source = $this->source();
        $source['employment_external_identifier']['environment'] = 'test';

        $this->expectCode(
            'registration_identity_id_ppv_scope_mismatch',
            fn () => $this->builder->build($this->scope(), $source),
        );
    }

    public function testForeignTaxIdentifierDoesNotReplaceRegistrationIdentity(): void
    {
        $source = $this->source();
        $source['identity']['birth_place'] = null;

        $this->expectCode(
            'registration_identity_regzec_identity_incomplete',
            fn () => $this->builder->build($this->scope(), $source),
        );
    }

    public function testRejectsIdentifiersOutsideLocalXsdFacets(): void
    {
        foreach ([
            'birth_number' => '12345A789',
            'ecp' => '12345678',
            'vcp' => '012345678',
            'vcp_other_prefix' => '123456789',
        ] as $type => $invalid) {
            $type = $type === 'vcp_other_prefix' ? 'vcp' : $type;
            $source = $this->source();
            $source['identifiers']['foreign_tax_identifier'] = null;
            unset($source['identifier_sources']['foreign_tax_identifier']);
            $source['identifiers'][$type] = $invalid;
            $source['identifier_sources'][$type] = [
                'id' => 140,
                'row_version' => 1,
            ];

            $this->expectCode(
                'registration_identity_identifier_invalid',
                fn () => $this->builder->build($this->scope(), $source),
            );
        }
    }

    public function testRegzecIgnoresPrezecDomesticEligibility(): void
    {
        $source = $this->source();
        $source['identity']['citizenship_country_code'] = 'SK';

        $snapshot = $this->builder->build($this->scope(), $source);

        self::assertSame(
            'not_applicable',
            $snapshot->registrationEligibility['status'],
        );
        self::assertSame(
            'agenda_not_prezec',
            $snapshot->registrationEligibility['basis'],
        );
    }

    public function testPrezecAcceptsVerifiedDomesticCitizenship(): void
    {
        $source = $this->source();
        $source['identifiers']['birth_number'] = '9102030014';
        $source['identifier_sources']['birth_number'] = [
            'id' => 151,
            'row_version' => 1,
        ];
        $scope = $this->scope();
        $scope['agenda_code'] = 'PREZEC26';

        $snapshot = $this->builder->build($scope, $source);

        self::assertSame(
            'verified',
            $snapshot->registrationEligibility['status'],
        );
        self::assertSame(
            'domestic_citizenship_country_code',
            $snapshot->registrationEligibility['basis'],
        );
        self::assertSame(
            'CZ',
            $snapshot->registrationEligibility['citizenship_country_code'],
        );
    }

    public function testPrezecRejectsForeignEmployeeRequiringFullRegistration(): void
    {
        $source = $this->source();
        $source['identifiers']['ecp'] = '123456789';
        $source['identifier_sources']['ecp'] = [
            'id' => 152,
            'row_version' => 1,
        ];
        $source['identity']['citizenship_country_code'] = 'SK';
        $scope = $this->scope();
        $scope['agenda_code'] = 'PREZEC26';

        $this->expectCode(
            'registration_identity_prezec_foreign_requires_full_registration',
            fn () => $this->builder->build($scope, $source),
        );
    }

    public function testPrezecBlocksUnverifiedCitizenship(): void
    {
        $source = $this->source();
        $source['identifiers']['ecp'] = '123456789';
        $source['identifier_sources']['ecp'] = [
            'id' => 153,
            'row_version' => 1,
        ];
        $source['identity']['citizenship_country_code'] = null;
        $scope = $this->scope();
        $scope['agenda_code'] = 'PREZEC26';

        $this->expectCode(
            'registration_identity_prezec_citizenship_unverified',
            fn () => $this->builder->build($scope, $source),
        );
    }

    /** @return ScopeFixture */
    private function scope(): array
    {
        return [
            'supplier_id' => 41,
            'submission_id' => 71,
            'source_revision_id' => 81,
            'employee_id' => 91,
            'employment_id' => 101,
            'environment' => 'production',
            'agenda_code' => 'REGZEC25',
            'effective_on' => '2026-08-04',
        ];
    }

    /** @return SourceFixture */
    private function source(): array
    {
        return [
            'identity' => [
                'id' => 111,
                'employee_id' => 91,
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
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'row_version' => 2,
            ],
            'identifiers' => [
                'birth_number' => null,
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => 'FOREIGN-SYNTHETIC-001',
            ],
            'identifier_sources' => [
                'foreign_tax_identifier' => [
                    'id' => 121,
                    'row_version' => 1,
                ],
            ],
            'employment_external_identifier' => [
                'id' => 131,
                'employee_id' => 91,
                'employment_id' => 101,
                'environment' => 'production',
                'identifier_type' => 'id_ppv',
                'value' => '100000000000000000001',
                'valid_from' => '2026-08-01',
                'valid_to' => null,
                'source_kind' => 'verified_manual_import',
                'source_receipt_id' => null,
                'source_reference_hash' => str_repeat('a', 64),
                'row_version' => 1,
            ],
            'resolution' => [
                'person_identity' => 'resolved',
                'employment_external_id' => 'resolved',
            ],
        ];
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
