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
        self::assertContains('employment.position_name', $missing);
        self::assertContains('facts.highest_education_code', $missing);
        // „Práce probíhá převážně" je jen pro chráněný trh práce, nechybí.
        self::assertNotContains('employment.prevailing_workplace_code', $missing);
    }

    /**
     * REGZEC25-fact.highedu-07: občanu ČR s DPČ nebo DPP návrh rovnou dosadí
     * vzdělání „Z" (nerelevantní) a nehlásí ho jako chybějící.
     */
    public function testSuggestsEducationNotRelevantForCzechCitizenWithAgreement(): void
    {
        foreach (['dpc' => 'A', 'dpp' => 'T'] as $relationType => $activity) {
            $sources = self::sources();
            $sources['employment']['relation_type'] = $relationType;
            $sources['terms']['activity_code'] = $activity;
            $sources['terms']['relationship_detail_code'] = null;
            $draft = (new PayrollRegistrationA1DraftBuilder())->build(
                $sources,
                self::identity(),
                null,
                null,
                '2026-08-14',
                0,
                null,
            );

            self::assertSame('Z', $draft['suggested']['facts']['highest_education_code'], $activity);
            self::assertNotContains('facts.highest_education_code', self::missingFields($draft));
            self::assertArrayHasKey('facts.highest_education_code', $draft['sources']);
        }

        $employment = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        self::assertNull($employment['suggested']['facts']['highest_education_code']);
    }

    /**
     * Postavení v zaměstnání se navrhne z druhu vztahu a doby určité jako
     * čtyřmístný kód NKPZ — dvoumístný kód ČSSZ nepřijímá.
     */
    public function testSuggestsFourDigitEmploymentStatusFromRelationType(): void
    {
        $build = static function (string $relationType, ?string $fixedTermEndOn, string $activity): array {
            $sources = self::sources();
            $sources['employment']['relation_type'] = $relationType;
            $sources['terms']['activity_code'] = $activity;
            $sources['terms']['relationship_detail_code'] = $activity === '1' ? '1' : null;
            $sources['terms']['fixed_term_end_on'] = $fixedTermEndOn;

            return (new PayrollRegistrationA1DraftBuilder())->build(
                $sources,
                self::identity(),
                null,
                null,
                '2026-08-14',
                0,
                null,
            );
        };

        foreach ([
            ['employment', null, '1', '1111'],
            ['employment', '2026-12-31', '1', '1112'],
            ['dpc', null, 'A', '1211'],
            ['dpp', '2026-10-31', 'T', '1222'],
        ] as [$relationType, $fixedTermEndOn, $activity, $expected]) {
            $draft = $build($relationType, $fixedTermEndOn, $activity);
            self::assertSame(
                $expected,
                $draft['suggested']['employment']['employment_status_code'],
                $relationType,
            );
            self::assertNotContains(
                'employment.employment_status_code',
                self::missingFields($draft),
            );
            self::assertArrayHasKey(
                'employment.employment_status_code',
                $draft['sources'],
            );
        }
    }

    /**
     * DPČ se sjednanou odměnou pod rozhodným příjmem je zaměstnáním malého
     * rozsahu (matice REGZEC25 job.zmr-01, job.sme-05). ČSSZ přijala všechny
     * tři takové A1 se `sme="A"`; návrh dřív dával u DPČ vždy „ne".
     */
    public function testDpcSmallScaleFollowsAgreedRewardAgainstDecisiveIncome(): void
    {
        $build = static function (?int $agreedMinor, ?int $thresholdMinor): array {
            $sources = self::sources();
            $sources['employment']['relation_type'] = 'dpc';
            $sources['terms']['activity_code'] = 'A';
            $sources['terms']['relationship_detail_code'] = null;
            $sources['terms']['monthly_gross_minor'] = $agreedMinor;
            $sources['terms']['small_scale_threshold_minor'] = $thresholdMinor;

            return (new PayrollRegistrationA1DraftBuilder())->build(
                $sources,
                self::identity(),
                null,
                null,
                '2026-08-14',
                0,
                null,
            );
        };

        foreach ([
            [300_000, 450_000, true],
            [449_999, 450_000, true],
            [450_000, 450_000, false],
            [800_000, 450_000, false],
        ] as [$agreed, $threshold, $expected]) {
            $draft = $build($agreed, $threshold);
            self::assertSame($expected, $draft['suggested']['employment']['small_scale'], (string) $agreed);
            self::assertNotContains('employment.small_scale', self::missingFields($draft));
            self::assertArrayHasKey('employment.small_scale', $draft['sources']);
        }

        // Bez odměny nebo rozhodného příjmu zůstává dosavadní „ne", aby se
        // nezastavilo dohlášení A3 (příznak nenese); zdroj na to upozorní.
        foreach ([[null, 450_000], [300_000, null]] as [$agreed, $threshold]) {
            $draft = $build($agreed, $threshold);
            self::assertFalse($draft['suggested']['employment']['small_scale']);
            self::assertNotContains('employment.small_scale', self::missingFields($draft));
            self::assertStringContainsString('chybí', $draft['sources']['employment.small_scale']);
        }
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

    /**
     * Občan EU/EHP/Švýcarska má volný přístup na trh práce ze zákona (§ 87
     * zákona o zaměstnanosti). Návrh A1 ho dřív nechal „chybět" a u osoby
     * bez povolení žádal pracovní oprávnění, které nepotřebuje.
     */
    public function testEuCitizenIsPrefilledWithFreeAccessToTheLabourMarket(): void
    {
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            ['citizenship_country_code' => 'SK'],
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        $missing = self::missingFields($draft);

        self::assertTrue($draft['foreigner']);
        self::assertTrue($draft['suggested']['foreign_worker']['free_access']);
        self::assertSame('1', $draft['suggested']['foreign_worker']['free_access_reason_code']);
        self::assertNull($draft['suggested']['foreign_worker']['permit_from']);
        self::assertNotContains('foreign_worker.free_access', $missing);
        self::assertNotContains('foreign_worker.permit', $missing);
        self::assertNotContains('foreign_worker.permit_identifier', $missing);
        // Doklad totožnosti zůstává povinný i u občana EU.
        self::assertContains('proof_identity.number', $missing);
    }

    /**
     * EDV 1.4.0.6 (ID 10071, 10526, 10248, 10061/10062): u cizince jsou tyhle
     * údaje povinné a aplikace je nevede, návrh je musí vyjmenovat. U občana ČR
     * se nehlásí nic.
     */
    public function testForeignerGapsIncludeTheConditionallyRequiredAttributes(): void
    {
        $sources = self::sources();
        $sources['tax_residence'] = ['residence' => 'non-resident', 'country_code' => 'UA'];
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            $sources,
            ['citizenship_country_code' => 'UA'],
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        $missing = self::missingFields($draft);

        foreach ([
            'proof_identity.foreign_issuer',
            'employment.expected_workplaces',
            'employment.required_education_code',
            'tax_residency.identifier',
            'foreign_worker.issuing_labour_office_code',
        ] as $field) {
            self::assertContains($field, $missing, $field);
        }

        $czech = self::missingFields((new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        ));
        foreach (['employment.expected_workplaces', 'employment.required_education_code'] as $field) {
            self::assertNotContains($field, $czech, $field);
        }
    }

    /** PSČ jde na ČSSZ bez mezer, návrh ho nabízí ve stejném tvaru jako uložený profil. */
    public function testPostalCodeIsSuggestedWithoutSpaces(): void
    {
        $sources = self::sources();
        $sources['permanent_address']['postal_code'] = '602 00';
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            $sources,
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );

        self::assertSame('60200', $draft['suggested']['permanent_address']['postal_code']);
    }

    public function testActivityNDraftOffersTheForeignInsurerSection(): void
    {
        $sources = self::sources();
        $sources['terms']['activity_code'] = 'N';
        $sources['terms']['relationship_detail_code'] = '1';
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            $sources,
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );

        self::assertContains('foreign_insurance.current', self::missingFields($draft));
        self::assertArrayHasKey('foreign_insurance', $draft['suggested']);
        self::assertNull($draft['suggested']['foreign_insurance']['country_code']);

        $plain = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            0,
            null,
        );
        self::assertNotContains('foreign_insurance.current', self::missingFields($plain));
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

    public function testStoredSnapshotDriftIsOfferedForWriteBackButNeverRewritten(): void
    {
        $draft = self::draftWithDrift(false);

        self::assertSame(
            [[
                'field' => 'health_insurance_code',
                'label' => 'Kód zdravotní pojišťovny',
                'stored' => '201',
                'suggested' => '111',
                'writable' => true,
                'reason' => null,
            ]],
            $draft['writeback'],
        );
        // Návrh se nepřepisuje uloženým snímkem ani naopak.
        self::assertSame('111', $draft['suggested']['health_insurance_code']);
    }

    /**
     * Dokud registrace neodešla, není snímek doklad o ničem — rozdíl proti
     * kmenovým datům se proto nehlásí jako rozejití, jen se nabídne k zápisu.
     */
    public function testDivergenceIsReportedOnlyForSubmittedRegistration(): void
    {
        self::assertSame([], self::draftWithDrift(false)['diverged']);
        self::assertFalse(self::draftWithDrift(false)['submitted']);

        $submitted = self::draftWithDrift(true);
        self::assertTrue($submitted['submitted']);
        self::assertSame(
            ['health_insurance_code'],
            array_map(
                static fn (array $item): string => $item['field'],
                $submitted['diverged'],
            ),
        );
    }

    /**
     * Údaj, který se z kmenových dat sice bere, ale zpátky do nich nevede,
     * nesmí nabízet tlačítko — a musí říct proč.
     */
    public function testFieldWithoutWriteBackPathCarriesReason(): void
    {
        $stored = self::storedSnapshot();
        $stored['employment']['small_scale'] = true;
        $draft = (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            1,
            $stored,
            true,
        );
        $item = null;
        foreach ($draft['writeback'] as $candidate) {
            if ($candidate['field'] === 'employment.small_scale') {
                $item = $candidate;
            }
        }

        self::assertNotNull($item);
        self::assertFalse($item['writable']);
        self::assertStringContainsString(
            'druhu pracovního vztahu',
            (string) $item['reason'],
        );
    }

    /** @return array<string,mixed> */
    private static function storedSnapshot(): array
    {
        return (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            1,
            null,
        )['suggested'];
    }

    /** @return array<string,mixed> */
    private static function draftWithDrift(bool $submitted): array
    {
        $stored = self::storedSnapshot();
        $stored['health_insurance_code'] = '201';

        return (new PayrollRegistrationA1DraftBuilder())->build(
            self::sources(),
            self::identity(),
            null,
            null,
            '2026-08-14',
            1,
            $stored,
            $submitted,
        );
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
