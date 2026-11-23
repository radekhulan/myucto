<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
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
 * REGZEC27 (XSD 1.5, EDV 1.5.0.3) pro události s rozhodným datem od 1. 1. 2027.
 * Každá věta projde validátorem včetně připnutého XSD 1.5. Data jsou syntetická.
 */
final class PayrollRegistrationRegzec27Test extends TestCase
{
    private const NS = 'http://schemas.cssz.cz/REGZEC/2027';

    private const START = '2027-01-04';

    private const PREPARED_A1 = '2027-01-02';

    private const EFFECTIVE = '2027-01-10';

    private const PREPARED = '2027-01-12';

    public function testCzechA1Is2027FormAndPassesXsd15(): void
    {
        $xml = self::validated(self::a1Payload(self::a1(self::source()), self::identity()));
        $root = self::xpath($xml);

        self::assertSame(self::NS, $root->document->documentElement?->namespaceURI);
        self::assertSame('1.5', $root->evaluate('string(/r:REGZEC/@version)'));
        self::assertSame(self::START, $root->evaluate('string(//r:job/@fro)'));
        self::assertStringNotContainsString('REGZEC/2025', $xml);
    }

    /** EDV 1.5 (JMHZ-3428), ID 10568/10569: platnost pobytu u občana mimo EHP. */
    public function testForeignerOutsideEeaCarriesStayValidityAndPassesXsd(): void
    {
        $source = self::foreignSource('UA');
        $source['foreign_worker']['stay_valid_from'] = '2026-06-01';
        $source['foreign_worker']['stay_valid_to'] = '2028-05-31';
        $xml = self::validated(self::a1Payload(self::a1($source, 'UA'), self::identity('UA')));

        self::assertStringContainsString('stayvalidfro="2026-06-01"', $xml);
        self::assertStringContainsString('stayvalidto="2028-05-31"', $xml);
        self::assertStringContainsString('perm="22"', $xml);
    }

    public function testForeignerOutsideEeaWithoutStayValidityIsRejected(): void
    {
        $payload = self::a1Payload(self::a1(self::foreignSource('UA'), 'UA'), self::identity('UA'));

        self::assertCode('registration_regzec27_stay_validity_missing', $payload);
    }

    public function testEuCitizenNeedsNoStayValidityButMustHaveReasonOne(): void
    {
        $source = self::foreignSource('SK');
        $source['foreign_worker']['free_access_reason_code'] = '1';
        $xml = self::validated(self::a1Payload(self::a1($source, 'SK'), self::identity('SK')));
        self::assertStringNotContainsString('stayvalid', $xml);

        $source['foreign_worker']['free_access_reason_code'] = '3';
        self::assertCode(
            'registration_regzec27_eu_free_access',
            self::a1Payload(self::a1($source, 'SK'), self::identity('SK')),
        );
    }

    /**
     * Changelog 1.5 ř. 227 až 230: číslo orientační nejvýš 4 znaky u všech
     * adres. REGZEC25 dovoluje u zahraniční adresy 12 znaků.
     */
    public function testForeignOrientationNumberLongerThanFourCharactersIsRejectedFrom2027(): void
    {
        $source = self::foreignSource('SK');
        $source['foreign_worker']['free_access_reason_code'] = '1';
        $source['tax_residency']['residence_address']['orientation_number'] = '12345';

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            self::identity('SK'),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => self::START],
        );
        self::assertContains('tax_residency.residence_address.orientation_number', array_column($problems, 'field'));

        $source2026 = $source;
        $source2026['source']['effective_on'] = '2026-08-05';
        $source2026['employment']['actual_start_on'] = '2026-08-05';
        $source2026['employment']['contract_start_on'] = '2026-08-05';
        $source2026['foreign_worker']['free_access_reason_code'] = '1';
        $problems2026 = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source2026,
            self::identity('SK'),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'],
        );
        self::assertNotContains('tax_residency.residence_address.orientation_number', array_column($problems2026, 'field'));

        $delta = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['contact_address' => [
                'street' => 'Testovacia', 'house_number' => '7', 'orientation_number' => '12345',
                'postal_code' => '81101', 'city' => 'Testovo', 'country_code' => 'SK',
            ]],
        ];
        self::assertCode('registration_regzec27_orientation_number_too_long', self::eventPayload(3, $delta));
    }

    /** Changelog 1.5 ř. 217 až 219: kód adresního místa 1 až 9 číslic. */
    public function testShortRuianPointIsAcceptedAndOverlongRejected(): void
    {
        $source = self::source();
        $source['permanent_address']['ruian_point'] = '1234';
        self::validated(self::a1Payload(self::a1($source), self::identity()));

        $source['permanent_address']['ruian_point'] = '1234567890';
        self::assertCode(
            'registration_regzec27_ruian_point_invalid',
            self::a1Payload(self::a1($source), self::identity()),
        );
    }

    /** A2 (skončení od 1. 1. 2027): 10588 místo 10531 až 10533. */
    public function testA2WritesSeveranceBenefitInsteadOfGoldenHandshake(): void
    {
        $xml = self::validated(self::eventPayload(2, [
            'end_on' => self::EFFECTIVE,
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'ended_by_death' => false,
            'unemployment' => [
                'employment_type' => '1',
                'termination_reason' => '4',
                'entitlement' => 'A',
                'paid_in_full' => 'A',
                'average_net_earnings' => '30000',
                'golden_handshake' => '60000',
                'pension_periods' => [['from' => '2025-01-01', 'to' => self::EFFECTIVE]],
            ],
        ]));

        self::assertStringContainsString('severencebenefit="60000"', $xml);
        self::assertStringNotContainsString('goldenhandshake', $xml);
        self::assertStringContainsString('<job oid="200000000000000000002" to="' . self::EFFECTIVE . '"', $xml);
    }

    /** EDV 1.5 (JMHZ-3394): služební poměr podle z. 361/2003 Sb. a jeho důvody 7 až 14. */
    public function testA2ServiceTypeThreeAcceptsOnlyItsReasons(): void
    {
        $data = [
            'end_on' => self::EFFECTIVE,
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'ended_by_death' => false,
            'unemployment' => [
                'employment_type' => '3',
                'service_termination_reason' => '9',
                'average_net_earnings' => '30000',
                'pension_periods' => [['from' => '2025-01-01', 'to' => self::EFFECTIVE]],
            ],
        ];
        $xml = self::validated(self::eventPayload(2, $data));
        self::assertStringContainsString('typeempl="3"', $xml);
        self::assertStringContainsString('rsnterrel="9"', $xml);

        $data['unemployment']['service_termination_reason'] = '2';
        self::assertCode('registration_regzec27_service_termination_reason', self::eventPayload(2, $data));
    }

    public function testA3ChangePassesXsdAndCannotCarryForeignLegislation(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => [
                'permanent_address' => [
                    'street' => 'Nová', 'house_number' => '5', 'orientation_number' => '12a',
                    'postal_code' => '11000', 'city' => 'Testov', 'country_code' => 'CZ',
                ],
            ],
        ];
        $xml = self::validated(self::eventPayload(3, $data));
        self::assertStringContainsString('fro="' . self::EFFECTIVE . '"', $xml);

        $data['delta']['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];
        self::assertCode('registration_regzec27_a3_foreign_legislation_forbidden', self::eventPayload(3, $data));
    }

    /** 10009 <= datum vyplnění: změnu do budoucna ČSSZ nepřijme. */
    public function testA3ChangeInTheFutureIsRejected(): void
    {
        $payload = self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ], preparedOn: '2027-01-08');

        self::assertCode('registration_regzec27_change_in_future', $payload);
    }

    /** Kontrola 4: doklad totožnosti v A4 jen celý. */
    public function testA4CorrectionPassesXsdAndNeedsCompleteProofOfIdentity(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ];
        self::validated(self::eventPayload(4, $data));

        $data['delta']['proof_identity'] = ['type_code' => 'P', 'number' => 'SYN1'];
        self::assertCode('registration_regzec27_proof_identity_incomplete', self::eventPayload(4, $data));
    }

    /** EDV 1.5.0.1 (JMHZ-2997): A7 nese povinně kód státu cizích předpisů 10428. */
    public function testA7CarriesForeignLegislationStateAndPassesXsd(): void
    {
        $xml = self::validated(self::eventPayload(7, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'foreign_insurance' => [
                'current' => 'S',
                'name' => 'Syntetická instituce',
                'country_code' => 'SK',
                'identifier' => 'SYN-123',
            ],
        ]));

        self::assertStringContainsString('<forinreg state="SK"/>', $xml);
    }

    /**
     * Kontrola 5 (EDV 1.5.0.3, Vysvětlivky): údaje cizince v A3/A4 jen jako
     * celá sada se státním občanstvím 10067. Občan ČR je nesmí mít vůbec.
     */
    public function testForeignWorkerChangeIsACompleteSetWithCitizenship(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'delta' => ['foreign_worker' => [
                'free_access' => false,
                'permit_type_code' => '2',
                'permit_identifier' => 'SYN-PERMIT-1',
                'permit_from' => '2027-01-01',
                'permit_to' => '2028-12-31',
                'stay_valid_from' => '2027-01-01',
                'stay_valid_to' => '2028-12-31',
            ]],
        ];
        $xml = self::validated(self::eventPayload(3, $data, identity: self::identity('UA')));
        self::assertStringContainsString('<stat cnt="UA"/>', $xml);
        self::assertStringContainsString('stayvalidto="2028-12-31"', $xml);

        $incomplete = $data;
        unset($incomplete['delta']['foreign_worker']['permit_identifier']);
        self::assertCode(
            'registration_regzec27_foreign_worker_set_incomplete',
            self::eventPayload(3, $incomplete, identity: self::identity('UA')),
        );
        self::assertCode('registration_regzec27_foreign_worker_forbidden', self::eventPayload(3, $data));
    }

    /** EDV 1.5.0.1 (JMHZ-2997): A1-SPEC nese příslušnost k cizím předpisům 10427. */
    public function testSpecialA1In2027CarriesForeignLegislation(): void
    {
        $source = self::source();
        $source['employment']['activity_code'] = '11';
        $source['employment']['relationship_detail_code'] = '1';
        $source['foreign_legislation'] = ['applies' => false, 'country_code' => null];
        $xml = self::validated(self::a1Payload(self::a1($source), self::identity()));

        self::assertStringContainsString('<forinreg juris="N"/>', $xml);
    }

    /** Changelog 1.5 ř. 220: pravidlo devíti dnů u 10223 platí v REGZEC27 jen pro A1. */
    public function testNewStartInChangeIsNotLimitedToNineDaysIn2027(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['employment' => ['actual_start_on' => '2027-02-15']],
        ];
        self::validated(self::eventPayload(3, $data));

        $data2026 = $data;
        $data2026['delta']['employment']['actual_start_on'] = '2026-12-28';
        self::assertCode(
            'registration_a3_start_window_invalid',
            self::eventPayload(3, $data2026, preparedOn: '2026-12-12', version: JmhzSpecVersion::V2026, effectiveOn: '2026-12-10'),
        );
    }

    /** Hodnoty číselníků, které přidala až verze 2027, REGZEC25 neodešle. */
    public function testRegzec25RefusesCodebookValuesFrom2027(): void
    {
        foreach ([
            ['employment' => ['profession_code' => '23596']],
            ['employment' => ['workplace_municipality_code' => '111111']],
        ] as $delta) {
            self::assertCode('registration_regzec25_value_from_2027', self::eventPayload(3, [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'birth_number' => '9152031234',
                'delta' => $delta,
            ], preparedOn: '2026-12-12', version: JmhzSpecVersion::V2026, effectiveOn: '2026-12-10'));
        }
        $xml = self::validated(self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['employment' => ['profession_code' => '23596', 'workplace_municipality_code' => '111111']],
        ]));
        self::assertStringContainsString('clas="23596"', $xml);
    }

    /** Hranice verzí (EDV 1.5.0.3, Slovník): ročník musí odpovídat rozhodnému datu. */
    public function testPayloadWithWrongVintageIsRejected(): void
    {
        $payload = self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ], version: JmhzSpecVersion::V2026);

        self::assertCode('registration_spec_version_mismatch', $payload);
    }

    public function testDecisiveDateFollowsEndDateForChangeAndEffectiveDateOtherwise(): void
    {
        self::assertSame('2026-12-31', PayrollRegistrationSchemaCatalog::decisiveDate(3, '2027-01-05', [
            'delta' => ['employment' => ['end_on' => '2026-12-31']],
        ]));
        self::assertSame('2027-01-05', PayrollRegistrationSchemaCatalog::decisiveDate(3, '2027-01-05', ['delta' => []]));
        self::assertSame('2027-01-05', PayrollRegistrationSchemaCatalog::decisiveDate(2, '2027-01-05', null));
    }

    /**
     * ČSSZ, Otázky a odpovědi k NV2027, odpovědi 4, 5 a 13: u A3/A4 rozhoduje
     * výhradně 10224. Prázdné znamená REGZEC27, ten ale ostrá ČSSZ přijímá až
     * od 23. 12. 2026, do té doby jde věta jako REGZEC25.
     */
    public function testChangeVintageFollowsOnlyEndDateAndWithoutItThePreparationDay(): void
    {
        $active = ['delta' => ['title_prefix' => 'Mgr.']];
        self::assertSame(JmhzSpecVersion::V2027, PayrollRegistrationSchemaCatalog::versionFor(3, '2026-12-15', $active, '2027-01-05'));
        self::assertSame(JmhzSpecVersion::V2027, PayrollRegistrationSchemaCatalog::versionFor(4, '2026-06-01', $active, '2027-01-05'));
        self::assertSame(JmhzSpecVersion::V2026, PayrollRegistrationSchemaCatalog::versionFor(3, '2026-12-01', $active, '2026-12-10'));
        self::assertSame(JmhzSpecVersion::V2026, PayrollRegistrationSchemaCatalog::versionFor(3, '2026-12-01', $active, '2026-12-22'));
        self::assertSame(JmhzSpecVersion::V2027, PayrollRegistrationSchemaCatalog::versionFor(3, '2026-12-01', $active, '2026-12-23'));

        $ended2026 = ['delta' => ['employment' => ['end_on' => '2026-11-30']]];
        self::assertSame(JmhzSpecVersion::V2026, PayrollRegistrationSchemaCatalog::versionFor(3, '2026-11-20', $ended2026, '2027-01-05'));
        $ended2027 = ['delta' => ['employment' => ['end_on' => '2027-01-31']]];
        self::assertSame(JmhzSpecVersion::V2027, PayrollRegistrationSchemaCatalog::versionFor(3, '2027-01-20', $ended2027, '2027-02-05'));

        // Ostatní akce den vyhotovení nezajímá.
        self::assertSame(JmhzSpecVersion::V2026, PayrollRegistrationSchemaCatalog::versionFor(5, '2026-12-15', [], '2027-01-05'));
        self::assertSame(JmhzSpecVersion::V2026, PayrollRegistrationSchemaCatalog::versionFor(2, '2026-12-31', null, '2027-01-05'));
    }

    /** Pojistka ve validátoru drží stejnou volbu jako služba podání. */
    public function testChangeOfActiveEmploymentFrom2026PreparedIn2027PassesAsRegzec27Only(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ];
        $xml = self::validated(self::eventPayload(3, $data, preparedOn: '2027-01-05', effectiveOn: '2026-12-15'));
        self::assertStringContainsString('xmlns="' . self::NS . '"', $xml);
        self::assertStringContainsString('fro="2026-12-15"', $xml);

        self::assertCode(
            'registration_spec_version_mismatch',
            self::eventPayload(3, $data, preparedOn: '2027-01-05', version: JmhzSpecVersion::V2026, effectiveOn: '2026-12-15'),
        );
        self::assertCode(
            'registration_spec_version_mismatch',
            self::eventPayload(3, $data, preparedOn: '2026-12-10', effectiveOn: '2026-12-01'),
        );
        self::validated(self::eventPayload(3, $data, preparedOn: '2026-12-23', effectiveOn: '2026-12-01'));
    }

    /** Odpověď 4: změna u skončeného vztahu nese 10224 a ročník podle něj. */
    public function testChangeOfEndedEmploymentCarriesEndDateInItsVintage(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => [
                'health_insurance_code' => '201',
                'employment' => ['end_on' => '2026-11-30'],
            ],
        ];
        $xml2025 = self::validated(self::eventPayload(
            3,
            $data,
            preparedOn: '2027-01-05',
            version: JmhzSpecVersion::V2026,
            effectiveOn: '2026-11-20',
        ));
        self::assertStringContainsString('http://schemas.cssz.cz/REGZEC/2025', $xml2025);
        self::assertStringContainsString(' to="2026-11-30"', $xml2025);

        $data['delta']['employment']['end_on'] = '2027-01-31';
        $xml2027 = self::validated(self::eventPayload(3, $data, preparedOn: '2027-02-05', effectiveOn: '2027-01-20'));
        self::assertStringContainsString(' to="2027-01-31"', $xml2027);
    }

    /** Obálka: `eType` REGZEC27 z jmenného prostoru zmrazené věty, Class beze změny. */
    public function testEnvelopeTypeFollowsTheFrozenSentence(): void
    {
        $xml = self::validated(self::a1Payload(self::a1(self::source()), self::identity()));

        self::assertSame('REGZEC27', JmhzGovTalkRequestShape::forPayload('CSSZ_REGZEC', $xml)->bodyEnvelopeType);
    }

    /**
     * PREZEC s nástupem 4. 1. 2027 podaný 28. 12. 2026: balík „PREZEC27 1.5"
     * má jmenný prostor `/PREZEC/2026`, takže věta je PREZEC26, projde XSD 1.2
     * i 1.5 a obálka nese `eType` PREZEC26.
     */
    public function testPreRegistrationForStartIn2027StaysPrezec26AndPassesBothSchemas(): void
    {
        self::assertSame('PREZEC26', (new PayrollRegistrationSchemaCatalog())->documentTypeFor('PREZEC26', self::START));
        $payload = new PayrollRegistrationXmlPayload(
            identity: new PayrollRegistrationIdentitySnapshot(
                scope: [
                    'supplier_id' => 11, 'submission_id' => 21, 'source_revision_id' => 31,
                    'employee_id' => 41, 'employment_id' => 51, 'environment' => 'production',
                    'agenda_code' => 'PREZEC26', 'effective_on' => self::START,
                ],
                identity: self::identity() + ['title_suffix' => null],
                identifiers: ['birth_number' => '9152031234', 'ecp' => null, 'vcp' => null, 'foreign_tax_identifier' => null],
                employmentExternalIdentifier: null,
                registrationEligibility: [
                    'status' => 'verified',
                    'basis' => 'domestic_citizenship_country_code',
                    'citizenship_country_code' => 'CZ',
                ],
                sourceVersions: [],
            ),
            interaction: new PayrollRegistrationInteraction('PREZEC26', 'limited_pre_registration', 9),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-12-28',
            expectedStartOn: self::START,
            actualStartOn: null,
            employerVariableSymbol: '1100000007',
            productName: 'MyÚčto.cz',
            productVersion: '6.49.0',
        );
        $xml = self::validated($payload);

        $document = new DOMDocument();
        $document->loadXML($xml);
        self::assertTrue($document->schemaValidate(dirname(__DIR__, 5) . '/xsd/jmhz/prezec-1.5/PREZEC26_1_5.xsd'));
        self::assertSame('PREZEC26', JmhzGovTalkRequestShape::forPayload('CSSZ_PREZEC', $xml)->bodyEnvelopeType);
        self::assertStringContainsString('predat="' . self::START . '"', $xml);
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

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::NS);

        return $xpath;
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['source']['effective_on'] = self::START;
        $source['employment']['actual_start_on'] = self::START;
        $source['employment']['contract_start_on'] = self::START;

        return $source;
    }

    /** @return array<string,mixed> */
    private static function foreignSource(string $citizenship): array
    {
        $source = self::source();
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['employment']['required_education_code'] = 'T';
        $source['tax_residency'] = [
            'country_code' => $citizenship,
            'identifier_type' => 'D',
            'identifier' => $citizenship . '123456789',
            'residence_address' => [
                'street' => 'Testovacia',
                'house_number' => '7',
                'orientation_number' => null,
                'city' => 'Testovo',
                'postal_code' => '81101',
                'country_code' => $citizenship,
                'ruian_point' => null,
            ],
        ];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => $citizenship,
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '22',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function identity(string $citizenship = 'CZ'): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = $citizenship;
        $identity['birth_country_code'] = $citizenship;

        return $identity;
    }

    /** @param array<string,mixed> $source */
    private static function a1(array $source, string $citizenship = 'CZ'): PayrollRegistrationA1Snapshot
    {
        return (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::identity($citizenship),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => self::START],
        );
    }

    /** @param array<string,mixed> $identity */
    private static function a1Payload(PayrollRegistrationA1Snapshot $a1, array $identity): PayrollRegistrationXmlPayload
    {
        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot($identity, $a1),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::PREPARED_A1,
            expectedStartOn: null,
            actualStartOn: self::START,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            productName: 'MyÚčto.cz',
            productVersion: '6.49.0',
            specVersion: JmhzSpecVersion::V2027,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $identity
     */
    private static function eventPayload(
        int $action,
        array $data,
        string $preparedOn = self::PREPARED,
        JmhzSpecVersion $version = JmhzSpecVersion::V2027,
        ?array $identity = null,
        string $effectiveOn = self::EFFECTIVE,
    ): PayrollRegistrationXmlPayload {
        $interaction = [
            2 => 'termination', 3 => 'change', 4 => 'correction',
            5 => 'variable_symbol_transfer', 6 => 'czech_legislation_start',
            7 => 'czech_legislation_end', 8 => 'cancellation',
        ][$action];

        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot($identity ?? self::identity(), null),
            interaction: new PayrollRegistrationInteraction('REGZEC25', $interaction, $action),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: $preparedOn,
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
            productVersion: '6.49.0',
            specVersion: $version,
        );
    }

    /** @param array<string,mixed> $identity */
    private static function snapshot(array $identity, ?PayrollRegistrationA1Snapshot $a1): PayrollRegistrationIdentitySnapshot
    {
        return new PayrollRegistrationIdentitySnapshot(
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
                'birth_number' => $identity['citizenship_country_code'] === 'CZ' ? '9152031234' : null,
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: $a1 === null ? [] : ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
    }
}
