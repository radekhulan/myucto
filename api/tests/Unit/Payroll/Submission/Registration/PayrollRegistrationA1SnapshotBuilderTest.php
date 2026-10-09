<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEducationRule;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityRequirements;
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

    /**
     * REGZEC25-fact.highedu-07 (Zásady REGZEC 1.4.6, ID 10091): občan ČR
     * s DPČ (A až J) nebo DPP (T až ZC) má vzdělání „Z" (nerelevantní).
     * U pracovního poměru a u cizince s dohodou se vzdělání uvádí.
     */
    public function testCzechCitizenWithAgreementMustHaveEducationNotRelevant(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        foreach (['A', 'J', 'T', 'ZC'] as $activity) {
            $source = self::source($activity, null);
            $source['facts']['highest_education_code'] = 'T';
            $problem = self::educationProblem($builder->problems(
                $source,
                self::identity(),
                self::scope(),
            ));
            self::assertNotNull($problem, $activity);
            self::assertSame('registration_regzec_a1_field_value_invalid', $problem['code']);
            self::assertSame('education_not_relevant', $problem['message_key']);
            self::assertSame(['activity' => $activity, 'value' => 'T'], $problem['params']);

            $source['facts']['highest_education_code'] = 'Z';
            self::assertNull(self::educationProblem($builder->problems(
                $source,
                self::identity(),
                self::scope(),
            )), $activity);
        }

        $employment = self::source('1', '1');
        $employment['facts']['highest_education_code'] = 'T';
        self::assertNull(self::educationProblem($builder->problems(
            $employment,
            self::identity(),
            self::scope(),
        )));

        $foreigner = self::source('A', null);
        $foreigner['facts']['highest_education_code'] = 'T';
        $identity = self::identity();
        $identity['citizenship_country_code'] = 'SK';
        self::assertNull(self::educationProblem($builder->problems(
            $foreigner,
            $identity,
            self::scope(),
        )));
    }

    /**
     * @param list<array<string,mixed>> $problems
     * @return array<string,mixed>|null
     */
    private static function educationProblem(array $problems): ?array
    {
        foreach ($problems as $problem) {
            if (($problem['field'] ?? null) === 'facts.highest_education_code') {
                return $problem;
            }
        }

        return null;
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

    /**
     * Kontrola ve formuláři a kontrola při podání musí padat na týchž polích.
     * Sběrný režim proto běží nad stejnými pravidly, jen místo první výjimky
     * vrátí celý seznam — a u pole řekne i to, kde se zadává.
     */
    public function testProblemsCollectEveryGapAtOnce(): void
    {
        $source = self::source('1', '1');
        $source['facts']['highest_education_code'] = null;
        $source['employment']['position_name'] = null;
        $source['permanent_address']['house_number'] = null;
        $source['permanent_address']['city'] = null;

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            self::identity(),
            self::scope(),
        );

        $fields = array_column($problems, 'field');
        self::assertContains('facts.highest_education_code', $fields);
        self::assertContains('employment.position_name', $fields);
        self::assertContains('permanent_address.house_number', $fields);
        foreach ($problems as $problem) {
            self::assertNotSame('', trim($problem['message']));
            self::assertSame(
                'registration_regzec_a1_required_field_missing',
                $problem['code'],
            );
        }
        $byField = array_column($problems, 'message', 'field');
        // Věta musí ZAČÍNAT lidským názvem údaje. Účetní čte první dvě slova;
        // když tam stojí název sloupce, hláška je pro ni k ničemu.
        self::assertStringStartsWith(
            'Nejvyšší dosažené vzdělání',
            $byField['facts.highest_education_code'],
        );
        self::assertStringContainsString(
            'v tomhle formuláři',
            $byField['employment.position_name'],
        );
        self::assertStringContainsString(
            'Historie adres',
            $byField['permanent_address.city'],
        );
        // Technická cesta se veze v `field`, ne na začátku věty.
        foreach ($problems as $problem) {
            self::assertIsString($problem['field']);
            self::assertStringStartsNotWith(
                (string) $problem['field'],
                $problem['message'],
            );
        }
    }

    /**
     * Vyplněná, ale vadná hodnota nesmí hlásit „chybí".
     *
     * Účetní by koukala na vyplněné pole a hledala prázdné. Hláška musí říct,
     * JAK má hodnota vypadat — jinak se dá jen hádat.
     */
    public function testFilledButUnusableValuesSayWhatShapeIsExpected(): void
    {
        $source = self::source('1', '1');
        $source['permanent_address']['country_code'] = 'CZE';
        $source['health_insurance_code'] = '11';
        $source['employment']['actual_start_on'] = '2026-13-45';
        $source['employment']['position_name'] = str_repeat('a', 300);

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            self::identity(),
            self::scope(),
        );

        $byField = array_column($problems, 'message', 'field');
        $codes = array_column($problems, 'code', 'field');
        foreach ([
            'permanent_address.country_code' => 'dvoupísmenná zkratka státu',
            'health_insurance_code' => 'přesně 3 číslicích',
            'employment.actual_start_on' => 'RRRR-MM-DD',
            'employment.position_name' => '255 znaků',
        ] as $field => $expected) {
            self::assertArrayHasKey($field, $byField, $field);
            self::assertStringContainsString($expected, $byField[$field], $field);
            self::assertStringNotContainsString(' chybí', $byField[$field], $field);
            self::assertSame(
                'registration_regzec_a1_field_value_invalid',
                $codes[$field],
                $field,
            );
        }
        self::assertStringStartsWith(
            'Stát trvalého pobytu',
            $byField['permanent_address.country_code'],
        );
    }

    /**
     * V anglickém UI se ukazovala česká věta serveru. Každá hláška pole
     * proto nese jazykově nezávislý klíč a parametry, podle kterých ji
     * frontend přeloží.
     */
    public function testProblemsCarryATranslatableKeyAndParameters(): void
    {
        $source = self::source('1', '1');
        $source['permanent_address']['country_code'] = 'CZE';
        $source['health_insurance_code'] = '11';
        $source['employment']['actual_start_on'] = '2026-13-45';
        $source['employment']['position_name'] = str_repeat('a', 300);
        $source['facts']['highest_education_code'] = null;

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            self::identity(),
            self::scope(),
        );
        $byField = [];
        foreach ($problems as $problem) {
            $byField[(string) $problem['field']] = $problem;
        }

        self::assertSame('country', $byField['permanent_address.country_code']['message_key']);
        self::assertSame('digits', $byField['health_insurance_code']['message_key']);
        self::assertSame(['length' => 3], $byField['health_insurance_code']['params']);
        self::assertSame('date', $byField['employment.actual_start_on']['message_key']);
        self::assertSame('too_long', $byField['employment.position_name']['message_key']);
        self::assertSame(
            ['max' => 255, 'length' => 300],
            $byField['employment.position_name']['params'],
        );
        self::assertSame('missing', $byField['facts.highest_education_code']['message_key']);
        self::assertSame([], $byField['facts.highest_education_code']['params']);
    }

    /** Přísný režim nemá kam dát `field`, takže cesta jde do závorky. */
    public function testStrictModeKeepsTheTechnicalPathInBrackets(): void
    {
        $source = self::source('1', '1');
        $source['facts']['highest_education_code'] = null;

        try {
            (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                self::identity(),
                self::scope(),
            );
            self::fail('Očekávána chyba chybějícího pole.');
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            self::assertStringStartsWith(
                'Nejvyšší dosažené vzdělání',
                $exception->getMessage(),
            );
            self::assertStringEndsWith(
                ' (facts.highest_education_code)',
                $exception->getMessage(),
            );
        }
    }

    /** Úplný snímek nemá co hlásit. */
    public function testProblemsAreEmptyForACompleteSnapshot(): void
    {
        self::assertSame([], (new PayrollRegistrationA1SnapshotBuilder())->problems(
            self::source('1', '1'),
            self::identity(),
            self::scope(),
        ));
    }

    /** Chybějící občanství pojmenuje sekci karty osoby, ne jen sloupec. */
    public function testMissingCitizenshipNamesThePlaceWhereItIsEntered(): void
    {
        $identity = self::identity();
        $identity['citizenship_country_code'] = null;

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            self::source('1', '1'),
            $identity,
            self::scope(),
        );

        $byField = array_column($problems, 'message', 'field');
        self::assertArrayHasKey('citizenship_country_code', $byField);
        self::assertStringContainsString(
            'Údaje pro registraci zaměstnance',
            $byField['citizenship_country_code'],
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
                employerVariableSymbol: '1100000007',
                employerName: 'Syntetický zaměstnavatel s.r.o.',
                csszWorkplaceCode: '110',
            );
            $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);

            (new PayrollRegistrationXmlValidator(
                new PayrollRegistrationSchemaCatalog(),
            ))->validate($payload, $xml);
            self::assertStringContainsString(
                '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"'
                    . ' version="1.4" partialAccept="A">',
                $xml,
            );
            self::assertStringContainsString(' rel="' . $activity . '"', $xml);
            self::assertStringContainsString('<adr ', $xml);
            if ($activity === '10') {
                self::assertStringNotContainsString('<taxidrezid', $xml);
                self::assertStringNotContainsString('<insh', $xml);
            }
        }
    }

    /**
     * EDV REGZEC 1.4.0.6, ID 10249: „musí jít o 4místný kód, kratší nejsou
     * akceptovány". Všech 39 přijatých A1 z cizích programů nese 1111–1222;
     * dřív se do pole vešly jen dva znaky.
     */
    /**
     * EDV 1.4.0.6, R91 (job/prof/@clas): z CZ-ISCO jen kódy o pěti znacích.
     * Čtyřmístnou podskupinu (přípustnou v JMHZ) registrace neodešle.
     */
    public function testProfessionCodeMustBeFiveDigitCategory(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        foreach (['2411' => true, '24111' => false] as $code => $rejected) {
            $source = self::source('1', '1');
            $source['employment']['profession_code'] = (string) $code;
            $problems = array_column(
                $builder->problems($source, self::identity(), self::scope()),
                'message',
                'field',
            );
            if ($rejected) {
                self::assertStringContainsString('pětimístný kód CZ-ISCO', $problems['employment.profession_code'] ?? '');
            } else {
                self::assertArrayNotHasKey('employment.profession_code', $problems);
            }
        }
    }

    public function testEmploymentStatusMustBeFourDigitCodebookCode(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        foreach (['11' => 'čtyřmístný', '9999' => 'není v číselníku'] as $code => $expected) {
            $source = self::source('1', '1');
            $source['employment']['employment_status_code'] = (string) $code;
            $problems = array_column(
                $builder->problems($source, self::identity(), self::scope()),
                'message',
                'field',
            );
            self::assertArrayHasKey('employment.employment_status_code', $problems, (string) $code);
            self::assertStringContainsString(
                $expected,
                $problems['employment.employment_status_code'],
            );
        }

        $customs = self::source('15', null);
        $customs['employment']['employment_status_code'] = '1111';
        $problems = array_column(
            $builder->problems($customs, self::identity(), self::scope()),
            'message',
            'field',
        );
        self::assertStringContainsString(
            '1341 nebo 1342',
            $problems['employment.employment_status_code'] ?? '',
        );
    }

    public function testFourDigitEmploymentStatusReachesXmlAndPassesXsd(): void
    {
        $source = self::source('1', '1');
        $source['employment']['employment_status_code'] = '1112';
        $xml = self::serialize((new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::identity(),
            self::scope(),
        ));

        self::assertStringContainsString(' relat="1112"', $xml);
    }

    /**
     * 10258 „práce probíhá převážně" je podle EDV povinná jen na chráněném
     * trhu práce u zaměstnance se zdravotním omezením, jinde je zakázaná.
     */
    public function testPrevailingWorkplaceOnlyOnProtectedLabourMarket(): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        $plain = self::source('1', '1');
        $plain['employment']['prevailing_workplace_code'] = null;
        self::assertSame([], $builder->problems($plain, self::identity(), self::scope()));
        $xml = self::serialize($builder->build(self::source('1', '1'), self::identity(), self::scope()));
        self::assertStringNotContainsString(' place=', $xml);

        $restricted = self::source('1', '1');
        $restricted['facts']['health_restrictions'] = [
            ['type_code' => '1', 'from' => '2025-01-01', 'to' => null],
        ];
        $restricted['employment']['prevailing_workplace_code'] = null;
        self::assertSame([], $builder->problems($restricted, self::identity(), self::scope()));
        self::assertContains(
            'employment.prevailing_workplace_code',
            array_column(
                $builder->problems($restricted, self::identity(), self::scope(), true),
                'field',
            ),
        );

        $restricted['employment']['prevailing_workplace_code'] = '2';
        $xml = self::serialize($builder->build($restricted, self::identity(), self::scope(), true));
        self::assertStringContainsString(' place="2"', $xml);
    }

    /**
     * Chybějící místo narození vracela ČSSZ cizímu programu už u ONZ; u nás
     * na něj přišla až výjimka při přípravě. Kontrola profilu ho musí hlásit
     * i s cestou na kartu osoby.
     */
    public function testMissingBirthDataIsReportedByTheProfileCheck(): void
    {
        $identity = self::identity();
        $identity['birth_place'] = null;
        $identity['birth_surname'] = ' ';

        $problems = array_column(
            (new PayrollRegistrationA1SnapshotBuilder())->problems(
                self::source('1', '1'),
                $identity,
                self::scope(),
            ),
            'message',
            'field',
        );

        self::assertArrayHasKey('identity.birth_place', $problems);
        self::assertArrayHasKey('identity.birth_surname', $problems);
        self::assertStringStartsWith('Místo narození', $problems['identity.birth_place']);
    }

    private static function serialize(
        \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot $a1,
    ): string {
        $payload = new PayrollRegistrationXmlPayload(
            identity: self::snapshot($a1),
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
        (new PayrollRegistrationXmlValidator(
            new PayrollRegistrationSchemaCatalog(),
        ))->validate($payload, $xml);

        return $xml;
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

    /**
     * `fdr` (czAdrType) atribut `cnt` nemá a serializér stát nepíše; adresa
     * pobytu v ČR přenesená z cizí věty (import) proto nese stát jen,
     * pokud ho někdo doplnil. Bez státu se nesmí A1/A3 odmítnout.
     */
    public function testCzechResidenceAddressDoesNotRequireCountry(): void
    {
        $source = self::source('1', '1');
        $source['permanent_address']['country_code'] = 'SK';
        $source['czech_residence_address'] = [
            'street' => 'Testovací',
            'house_number' => '7',
            'city' => 'Testov',
            'postal_code' => '602 00',
        ];

        $snapshot = (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::identity(),
            self::scope(),
        );

        self::assertSame('CZ', $snapshot->czechResidenceAddress['country_code']);
        self::assertSame('60200', $snapshot->czechResidenceAddress['postal_code']);
    }

    public function testPermanentAddressStillRequiresCountry(): void
    {
        $source = self::source('1', '1');
        unset($source['permanent_address']['country_code']);

        $this->expectCode(
            'registration_regzec_a1_required_field_missing',
            static fn () => (new PayrollRegistrationA1SnapshotBuilder())->build(
                $source,
                self::identity(),
                self::scope(),
            ),
        );
    }

    /**
     * Dohlášení A3 nenese rodné příjmení, místo ani stát narození, takže
     * snímek pro A3 je nesmí vyžadovat; přihláška A1 je vyžaduje dál.
     */
    public function testCompletionIdentityKeysDoNotRequireBirthPlaceAndSurname(): void
    {
        $identity = self::identity();
        $identity['birth_surname'] = null;
        $identity['birth_place'] = null;
        $identity['birth_country_code'] = null;

        $snapshot = (new PayrollRegistrationA1SnapshotBuilder())->build(
            self::source('1', '1'),
            $identity,
            self::scope(),
            false,
            PayrollRegistrationIdentityRequirements::completionIdentityFields('full'),
        );
        self::assertSame('OST', $snapshot->variant);

        $this->expectCode(
            'registration_regzec_a1_required_field_missing',
            static fn () => (new PayrollRegistrationA1SnapshotBuilder())->build(
                self::source('1', '1'),
                $identity,
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
                'employment_status_code' => '1111',
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
                // Fixture je občan ČR: u dohody se vzdělání nesleduje (Z).
                'highest_education_code' => PayrollRegistrationEducationRule::mustBeNotRelevant('CZ', $activityCode)
                    ? 'Z'
                    : 'T',
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
