<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationProfileCompletion;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Dohlášení údajů (REGZEC A3) za zaměstnance přihlášeného dřív přes ONZ.
 *
 * ČSSZ přijímá celý snímek i jen dohlašované údaje; Premier i PAMICA
 * posílaly celý profil (postavení, režim, místo výkonu, profese, pozice,
 * vzdělání, stát rezidence…). Do 10009 jde den odeslání.
 */
final class PayrollRegistrationProfileCompletionTest extends TestCase
{
    private const SENT_ON = '2026-09-26';

    public function testFullProfileCompletionSerializesEveryA3AttributeAndPassesXsd(): void
    {
        $xml = self::serialize(PayrollRegistrationProfileCompletion::FULL, null);

        foreach ([
            'act="3"',
            ' fro="' . self::SENT_ON . '"',
            ' bno="9152031234"',
            ' ikmpsv="1000000001"',
            '<name sur="Novotná" fir="Jana" tit="Ing."/>',
            '<birth dat="1991-02-03"/>',
            '<stat mal="Ž" cnt="CZ"/>',
            '<adr str="Testovací" num="12" onum="3" pnu="11000" cit="Testov" cnt="CZ"/>',
            '<taxidrezid stat="CZ" statchang="2026-08-05"/>',
            ' oid="200000000000000000002"',
            ' rel="1"',
            ' relDetail="1"',
            ' relat="1111"',
            ' workmode="1"',
            ' cont="N"',
            ' contractplace="Testov"',
            ' municode="554782"',
            '<prof clas="24111"/>',
            '<position name="Účetní" lead="N"/>',
            '<insh cnr="111"/>',
            ' highedu="T"',
            '<forinreg juris="N"/>',
        ] as $expected) {
            self::assertStringContainsString($expected, $xml, $expected);
        }
        // EDV 1.4.0.6, ID 10248 a 10526: vzdělání požadované pro profesi a
        // předpokládaná místa výkonu práce jsou u občana ČR zakázané, i když je
        // zdroj profilu nese.
        self::assertStringNotContainsString(' edu=', $xml);
        self::assertStringNotContainsString(' preplace=', $xml);
        // A3 nesmí nést údaje o narození kromě data (EDV: rodné příjmení,
        // místo a stát narození jsou u akce 3 zakázané) ani příznak ZMR.
        self::assertStringNotContainsString(' nam="Nováková"', $xml);
        self::assertStringNotContainsString(' sme=', $xml);
        self::assertStringNotContainsString(' place=', $xml);
    }

    /**
     * Prvotní dohlášení rezidence (10459): nástup před 1. 1. 2026 dává
     * 1. 1. 2026, pozdější nástup den nástupu (matice
     * REGZEC25-PROC.tax-residency-01, FAQ chyba 243). Premier takhle ČSSZ
     * přijala osm vět se starším nástupem.
     */
    public function testInitialTaxResidencyDateIsTheLaterOfStartAndJanuaryFirst2026(): void
    {
        foreach ([
            '2024-05-06' => '2026-01-01',
            '2025-12-31' => '2026-01-01',
            '2026-01-01' => '2026-01-01',
            '2026-03-01' => '2026-03-01',
        ] as $startOn => $expected) {
            foreach ([PayrollRegistrationProfileCompletion::FULL, PayrollRegistrationProfileCompletion::MINIMAL] as $mode) {
                $xml = self::serialize($mode, null, '9152031234', $startOn);

                self::assertStringContainsString(
                    '<taxidrezid stat="CZ" statchang="' . $expected . '"/>',
                    $xml,
                    $startOn . ' ' . $mode,
                );
            }
        }
    }

    public function testMinimalCompletionCarriesOnlyTheDataOnzDidNotHave(): void
    {
        $xml = self::serialize(PayrollRegistrationProfileCompletion::MINIMAL, null);

        foreach ([' relat="1111"', ' workmode="1"', ' highedu="T"', '<taxidrezid stat="CZ"', ' bno='] as $expected) {
            self::assertStringContainsString($expected, $xml, $expected);
        }
        foreach (['<adr ', '<insh ', '<name ', '<pens '] as $absent) {
            self::assertStringNotContainsString($absent, $xml, $absent);
        }
    }

    /**
     * Dohlášení jde i za vztah, který už skončil (MPSV 28. 4. 2026); v podání
     * se pak uvádí i datum skončení (Všeobecné zásady REGZEC, akce 3).
     */
    public function testCompletionForEndedEmploymentCarriesTheEndDate(): void
    {
        $xml = self::serialize(PayrollRegistrationProfileCompletion::MINIMAL, '2026-06-30');

        self::assertStringContainsString(' to="2026-06-30"', $xml);
    }

    /** Karta osoby vede rodné číslo s lomítkem; XSD pustí jen 9 až 10 číslic. */
    public function testBirthNumberFromThePersonCardIsSentWithoutSlash(): void
    {
        $digits = RegistrationXmlFixtures::birthNumber('1991-02-03', 'female', 7);
        $xml = self::serialize(
            PayrollRegistrationProfileCompletion::MINIMAL,
            null,
            substr($digits, 0, 6) . '/' . substr($digits, 6),
        );

        self::assertStringContainsString(' bno="' . $digits . '"', $xml);
    }

    public function testCompletionIsOfferedOnlyForTheStandardVariant(): void
    {
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
            PayrollRegistrationA1SnapshotBuilderTest::source('11', '1'),
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            self::scope(),
        );
        $this->expectException(PayrollRegistrationXmlException::class);
        PayrollRegistrationProfileCompletion::delta(
            $a1,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            ['birth_number' => '9152031234'],
            PayrollRegistrationProfileCompletion::FULL,
            null,
        );
    }

    private static function serialize(
        string $mode,
        ?string $endOn,
        string $birthNumber = '9152031234',
        ?string $startOn = null,
    ): string {
        $a1 = self::a1($startOn);
        $delta = PayrollRegistrationProfileCompletion::delta(
            $a1,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            ['birth_number' => $birthNumber, 'ecp' => null, 'vcp' => null],
            $mode,
            $endOn,
        );
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
                    'effective_on' => self::SENT_ON,
                ],
                identity: PayrollRegistrationA1SnapshotBuilderTest::identity(),
                identifiers: [
                    'birth_number' => '9152031234',
                    'ecp' => null,
                    'vcp' => null,
                    'foreign_tax_identifier' => null,
                ],
                employmentExternalIdentifier: null,
                registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
                sourceVersions: [],
            ),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'change', 3),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::SENT_ON,
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
                'interaction' => 'change',
                'action_code' => 3,
                'effective_on' => self::SENT_ON,
                'notification_trigger_on' => self::SENT_ON,
                'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
                'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
                'employer' => [
                    'variable_symbol' => '1100000007',
                    'name' => 'Syntetický zaměstnavatel s.r.o.',
                    'workplace_code' => '110',
                ],
                'data' => [
                    'completion' => $mode,
                    'delta' => $delta,
                    'activity_code' => '1',
                    'relationship_detail_code' => '1',
                ],
                'source' => ['kind' => 'verified_change', 'reference' => 'dohlaseni:' . $mode . ':' . self::SENT_ON],
            ],
        );
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))
            ->validate($payload, $xml);

        return $xml;
    }

    private static function a1(?string $startOn = null): PayrollRegistrationA1Snapshot
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['employment']['required_education_code'] = 'T';
        $scope = self::scope();
        if ($startOn !== null) {
            $source['employment']['actual_start_on'] = $startOn;
            $source['employment']['contract_start_on'] = $startOn;
            $source['source']['effective_on'] = $startOn;
            $scope['effective_on'] = $startOn;
        }

        return (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            PayrollRegistrationA1SnapshotBuilderTest::identity(),
            $scope,
        );
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
}
