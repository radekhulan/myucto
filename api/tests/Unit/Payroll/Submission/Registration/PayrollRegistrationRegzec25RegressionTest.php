<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * REGZEC25 (události do 31. 12. 2026) se zavedením REGZEC27 nesmí změnit ani
 * o bajt. Otisky jsou spočtené serializérem před zavedením verze 2027
 * (feat/mzdy-2027, 14d0473af) nad týmiž syntetickými daty.
 */
final class PayrollRegistrationRegzec25RegressionTest extends TestCase
{
    private const START = '2026-08-05';

    private const EFFECTIVE = '2026-08-04';

    /** @var array<string,string> */
    private const SHA256 = [
        'A1 občan ČR' => '3c4f6aed7f510cee64527499aae5fae6b9c914e2f06c472ab8eff2b7dd518cb9',
        'A1 cizinec SK' => '1cf84853f639829c11cbd3378146e1e03cf20175c20303201142adad4c1a1794',
        'A2 odstupné' => 'f18566709b137ac5f2da650b0e1de7e72585825973cbb4d3d64202c227a87cac',
        'A2 služební poměr odchodné' => 'b9dafae9e9ba368b3b6e0815fea34740eef753c0933f992d02a22875d5a2fdfc',
        'A3 změna' => 'f75ae7235271d03a8f4f72dc8678245b4fd1b5fe6cf35b6b0570b0cf525831b6',
        'A4 oprava' => 'a0e72846d16fd376921cd6204a20c904c9e92c8fd792d18f5a9a6bd5132bf71b',
        'A5 převod VS' => '44139aeed564550dd08ba4c0e475778a592acec54b1e2406ab87d54d7945547b',
        'A6 vznik českých předpisů' => '67da6ec143c5eca1f2c893b77a85bb848699e2d414154463c8c1078a046bc5a5',
        'A7 skončení českých předpisů' => '8743d524720f6f2317c3b2685fdc61272bffe3bb9223460e68fbab55f54c48ea',
        'A8 storno' => '80f260c5f9cb2ac7d13d7ca3a6d226f6538bdf713d2e5b001594d0402f97a2b3',
    ];

    /** @return iterable<string,array{string}> */
    public static function cases(): iterable
    {
        foreach (array_keys(self::SHA256) as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testRegzec25BytesStayTheSame(string $case): void
    {
        $payload = self::payload($case);
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))->validate($payload, $xml);

        self::assertStringContainsString('xmlns="http://schemas.cssz.cz/REGZEC/2025"', $xml);
        self::assertSame(self::SHA256[$case], hash('sha256', $xml), $case . "\n" . $xml);
    }

    private static function payload(string $case): PayrollRegistrationXmlPayload
    {
        return match ($case) {
            'A1 občan ČR' => self::a1Payload(self::a1(PayrollRegistrationA1SnapshotBuilderTest::source('1', '1'), 'CZ'), 'CZ'),
            'A1 cizinec SK' => self::a1Payload(self::a1(self::foreignSource(), 'SK'), 'SK'),
            'A2 odstupné' => self::eventPayload(2, self::a2([
                'employment_type' => '1',
                'termination_reason' => '4',
                'entitlement' => 'A',
                'paid_in_full' => 'A',
                'golden_handshake' => '60000',
            ])),
            'A2 služební poměr odchodné' => self::eventPayload(2, self::a2([
                'employment_type' => '2',
                'service_termination_reason' => '4',
                'entitlement' => 'A',
                'paid_in_full' => 'N',
                'severance_pay' => '45000',
            ])),
            'A3 změna' => self::eventPayload(3, self::delta([
                'permanent_address' => [
                    'street' => 'Nová', 'house_number' => '5', 'orientation_number' => '12a',
                    'postal_code' => '11000', 'city' => 'Testov', 'country_code' => 'CZ',
                ],
                'health_insurance_code' => '201',
            ])),
            'A4 oprava' => self::eventPayload(4, self::delta([
                'health_insurance_code' => '211',
                'foreign_legislation' => ['applies' => true, 'country_code' => 'SK'],
            ])),
            'A5 převod VS' => self::eventPayload(5, [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'new_variable_symbol' => '9876543210',
            ]),
            'A6 vznik českých předpisů' => self::eventPayload(6, self::foreignInsurance('P')),
            'A7 skončení českých předpisů' => self::eventPayload(7, self::foreignInsurance('S')),
            'A8 storno' => self::eventPayload(8, [
                'activity_code' => '1',
                'relationship_detail_code' => '1',
                'not_started' => true,
            ]),
        };
    }

    /** @param array<string,mixed> $settlement @return array<string,mixed> */
    private static function a2(array $settlement): array
    {
        return [
            'end_on' => self::EFFECTIVE,
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'ended_by_death' => false,
            'unemployment' => [
                'average_net_earnings' => '30000',
                'pension_periods' => [['from' => '2025-01-01', 'to' => self::EFFECTIVE]],
            ] + $settlement,
        ];
    }

    /** @param array<string,mixed> $delta @return array<string,mixed> */
    private static function delta(array $delta): array
    {
        return [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => $delta,
        ];
    }

    /** @return array<string,mixed> */
    private static function foreignInsurance(string $current): array
    {
        return [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'foreign_insurance' => [
                'current' => $current,
                'name' => 'Syntetická instituce',
                'country_code' => 'SK',
                'identifier' => 'SYN-123',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function foreignSource(): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['tax_residency'] = [
            'country_code' => 'SK',
            'identifier_type' => 'D',
            'identifier' => 'SK123456789',
            'residence_address' => [
                'street' => 'Testovacia',
                'house_number' => '7',
                'orientation_number' => '123456',
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country_code' => 'SK',
                'ruian_point' => null,
            ],
        ];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
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
            // Klíče REGZEC27; do snímku přihlášky 2026 se nesmí dostat.
            'stay_valid_from' => '2026-01-01',
            'stay_valid_to' => '2028-01-01',
        ];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function identity(string $citizenship): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = $citizenship;
        $identity['birth_country_code'] = $citizenship;

        return $identity;
    }

    /** @param array<string,mixed> $source */
    private static function a1(array $source, string $citizenship): PayrollRegistrationA1Snapshot
    {
        return (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::identity($citizenship),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => self::START],
        );
    }

    private static function a1Payload(PayrollRegistrationA1Snapshot $a1, string $citizenship): PayrollRegistrationXmlPayload
    {
        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot($citizenship, $a1),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::EFFECTIVE,
            expectedStartOn: null,
            actualStartOn: self::START,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            productName: 'MyÚčto.cz',
            productVersion: '6.48.1',
        );
    }

    /** @param array<string,mixed> $data */
    private static function eventPayload(int $action, array $data): PayrollRegistrationXmlPayload
    {
        $interaction = [
            2 => 'termination', 3 => 'change', 4 => 'correction',
            5 => 'variable_symbol_transfer', 6 => 'czech_legislation_start',
            7 => 'czech_legislation_end', 8 => 'cancellation',
        ][$action];

        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot('CZ', null),
            interaction: new PayrollRegistrationInteraction('REGZEC25', $interaction, $action),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::EFFECTIVE,
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
                'effective_on' => self::EFFECTIVE,
                'notification_trigger_on' => self::EFFECTIVE,
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

    private static function snapshot(string $citizenship, ?PayrollRegistrationA1Snapshot $a1): PayrollRegistrationIdentitySnapshot
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
            identity: self::identity($citizenship),
            identifiers: [
                'birth_number' => $citizenship === 'CZ' ? '9152031234' : null,
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
