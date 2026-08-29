<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1DraftBuilder;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA1DraftBuilderTest extends TestCase
{
    public function testSuggestsWhatTheApplicationAlreadyKnowsWithItsSource(): void
    {
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );

        self::assertSame('OST', $draft['variant']);
        self::assertNull($draft['variant_error']);
        self::assertFalse($draft['foreigner']);

        $suggested = $draft['suggested'];
        self::assertSame('Dlouhá', $suggested['permanent_address']['street']);
        self::assertSame('Praha', $suggested['permanent_address']['city']);
        self::assertSame('CZ', $suggested['permanent_address']['country_code']);
        self::assertSame('CZ', $suggested['tax_residency']['country_code']);
        self::assertSame('111', $suggested['health_insurance_code']);
        self::assertSame('1', $suggested['employment']['activity_code']);
        self::assertSame('2026-08-14', $suggested['employment']['actual_start_on']);
        self::assertSame('2026-08-01', $suggested['employment']['contract_start_on']);
        self::assertFalse($suggested['employment']['small_scale']);
        self::assertSame('2411', $suggested['employment']['profession_code']);
        self::assertSame(
            '554782',
            $suggested['employment']['workplace_municipality_code'],
        );

        // U každé odvozené hodnoty musí být poznat, co účetní potvrzuje.
        self::assertArrayHasKey('permanent_address.city', $draft['sources']);
        self::assertArrayHasKey('health_insurance_code', $draft['sources']);
        self::assertArrayHasKey('employment.activity_code', $draft['sources']);
    }

    public function testNamesMissingValuesConcretelyInsteadOfGuessingThem(): void
    {
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        $missing = self::missingFields($draft);

        // Aplikace vede adresu jedním řádkem, číslo popisné se nedomýšlí.
        self::assertContains('permanent_address.house_number', $missing);
        self::assertNull(
            $draft['suggested']['permanent_address']['house_number'],
        );
        // Varianta OST vyžaduje údaje, které aplikace vůbec nevede.
        self::assertContains('employment.employment_status_code', $missing);
        self::assertContains('employment.position_name', $missing);
        self::assertContains('facts.highest_education_code', $missing);
    }

    public function testForeignerRequiresIdentityDocumentTheApplicationDoesNotTrack(): void
    {
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            ['citizenship_country_code' => 'UA'],
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        $missing = self::missingFields($draft);

        self::assertTrue($draft['foreigner']);
        self::assertContains('proof_identity.type_code', $missing);
        self::assertContains('proof_identity.number', $missing);
        self::assertNull($draft['suggested']['proof_identity']['number']);
        self::assertSame(
            'UA',
            $draft['suggested']['proof_identity']['country_code'],
        );
        self::assertSame(
            '2026-01-01',
            $draft['suggested']['foreign_worker']['permit_from'],
        );
        self::assertContains('foreign_worker.permit_identifier', $missing);
    }

    public function testUnverifiedEvidenceIsReportedRatherThanAssumed(): void
    {
        $sources = self::sources();
        $sources['tax_residence'] = [
            'residence' => 'unverified',
            'country_code' => null,
        ];
        $sources['health_coverage'] = [
            'jurisdiction' => 'unverified',
            'foreign_country_code' => null,
            'insurer_status' => 'unverified',
            'insurer_code' => null,
        ];
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            $sources,
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        $missing = self::missingFields($draft);

        self::assertNull($draft['suggested']['tax_residency']['country_code']);
        self::assertNull($draft['suggested']['health_insurance_code']);
        self::assertContains('tax_residency.country_code', $missing);
        self::assertContains('health_insurance_code', $missing);
    }

    public function testStoredSnapshotDriftIsShownButNeverRewritten(): void
    {
        $builder = new PayrollRegistrationA1DraftBuilder();
        $stored = $builder->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            1,
            null,
        )['suggested'];
        $stored['health_insurance_code'] = '201';

        $sources = self::sources();
        $draft = $builder->build(
            $sources,
            self::identity(),
            null,
            null,
            '2026-08-14',
            1,
            $stored,
        );

        self::assertSame(
            [['field' => 'health_insurance_code', 'stored' => '201', 'suggested' => '111']],
            $draft['diverged'],
        );
        // Návrh se nepřepisuje uloženým snímkem ani naopak.
        self::assertSame('111', $draft['suggested']['health_insurance_code']);
    }

    public function testMissingIdentityHistoryIsReportedInsteadOfFailing(): void
    {
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            null,
            'K rozhodnému datu chybí historická identita osoby.',
            null,
            '2026-08-14',
            0,
            null,
        );

        self::assertNull($draft['citizenship_country_code']);
        self::assertFalse($draft['foreigner']);
        self::assertContains('identity', self::missingFields($draft));
    }

    /**
     * @param array<string,mixed> $draft
     * @return list<string>
     */
    private static function missingFields(array $draft): array
    {
        return array_map(
            static fn (array $gap): string => $gap['field'],
            $draft['missing'],
        );
    }

    /** @return array<string,mixed> */
    private static function identity(): array
    {
        return ['citizenship_country_code' => 'CZ'];
    }

    /** @return array<string,mixed> */
    private static function sources(): array
    {
        return [
            'permanent_address' => [
                'street_line' => 'Dlouhá',
                'city' => 'Praha',
                'postal_code' => '11000',
                'country_code' => 'CZ',
            ],
            'contact_address' => null,
            'tax_residence' => [
                'residence' => 'czech-resident',
                'country_code' => 'CZ',
            ],
            'health_coverage' => [
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'insurer_status' => 'verified',
                'insurer_code' => '111',
            ],
            'terms' => [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'planned_start_on' => '2026-08-01',
                'actual_start_on' => '2026-08-14',
                'work_place' => 'Praha 1, Dlouhá 1',
                'workplace_municipality_code' => '554782',
                'cz_isco_code' => '2411',
                'foreign_legislation_country_code' => null,
            ],
            'employment' => [
                'relation_type' => 'employment',
                'start_date' => '2026-08-14',
                'actual_start_date' => '2026-08-14',
            ],
            'work_permit' => [
                'permit_label' => 'Zaměstnanecká karta',
                'issuing_country_code' => 'CZ',
                'effective_from' => '2026-01-01',
                'valid_until' => '2027-01-01',
            ],
        ];
    }
}
