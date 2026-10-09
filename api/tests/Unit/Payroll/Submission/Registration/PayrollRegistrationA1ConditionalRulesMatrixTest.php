<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Podmíněné povinnosti (P/Z) a logické kontroly REGZEC25 A1 (EDV 1.4.0.6),
 * které hlídá sestavení snímku přihlášky. Každý případ porušení musí skončit
 * vadou na konkrétním poli, nebo zakázaná hodnota nesmí dojít do věty;
 * nejbližší správný vstup projde. Data jsou syntetická.
 */
final class PayrollRegistrationA1ConditionalRulesMatrixTest extends TestCase
{
    private const NS = 'http://schemas.cssz.cz/REGZEC/2025';

    /**
     * Porušení => [úprava zdroje, cizinec?, pole s očekávanou vadou].
     *
     * @return iterable<string,array{callable(array<string,mixed>):array<string,mixed>,bool,string}>
     */
    public static function violations(): iterable
    {
        $contact = static fn (array $address): callable => static function (array $s) use ($address): array {
            $s['contact_address'] = $address;

            return $s;
        };
        yield 'cdr jen stát (Z u obce, čísla, PSČ)' => [$contact(['country_code' => 'CZ']), false, 'contact_address.city'];
        yield 'cdr jen obec (Z u státu)' => [$contact(['city' => 'Testov']), false, 'contact_address.country_code'];
        yield 'cdr bez čísla popisného' => [$contact(['city' => 'Testov', 'postal_code' => '11000', 'country_code' => 'CZ']), false, 'contact_address.house_number'];
        yield 'cdr ulice bez obce' => [$contact(['street' => 'Testovací', 'house_number' => '1', 'postal_code' => '11000', 'country_code' => 'CZ']), false, 'contact_address.city'];
        yield 'cdr CZ číslo popisné s písmenem' => [$contact(self::address('CZ', '11000', '12a')), false, 'contact_address.house_number'];
        yield 'cdr CZ PSČ začíná 9' => [$contact(self::address('CZ', '91000')), false, 'contact_address.postal_code'];
        yield 'adr ulice bez obce' => [static function (array $s): array {
            unset($s['permanent_address']['city']);

            return $s;
        }, false, 'permanent_address.city'];
        yield 'fdr ulice bez obce' => [static function (array $s): array {
            $s['permanent_address'] = self::address('UA', '01001');
            $s['czech_residence_address'] = ['street' => 'Testovací', 'house_number' => '1', 'postal_code' => '11000'];

            return $s;
        }, true, 'czech_residence_address.city'];
        yield 'fdr orientační číslo nad 4 znaky' => [static function (array $s): array {
            $s['permanent_address'] = self::address('UA', '01001');
            $s['czech_residence_address'] = self::address('CZ', '11000') + [];
            $s['czech_residence_address']['orientation_number'] = '12345';

            return $s;
        }, true, 'czech_residence_address.orientation_number'];
        yield 'rdr ulice bez obce' => [static function (array $s): array {
            $s['tax_residency'] = [
                'country_code' => 'SK',
                'identifier_type' => 'D',
                'identifier' => 'SK1000000005',
                'residence_address' => ['street' => 'Testovacia', 'house_number' => '1', 'postal_code' => '81101', 'country_code' => 'SK'],
            ];

            return $s;
        }, true, 'tax_residency.residence_address.city'];
        yield 'rdr orientační číslo bez obce' => [static function (array $s): array {
            $s['tax_residency'] = [
                'country_code' => 'SK',
                'identifier_type' => 'D',
                'identifier' => 'SK1000000005',
                'residence_address' => ['orientation_number' => '2', 'house_number' => '1', 'postal_code' => '81101', 'country_code' => 'SK'],
            ];

            return $s;
        }, true, 'tax_residency.residence_address.city'];
        yield 'forin ulice bez čísla, PSČ a obce' => [static function (array $s): array {
            $s['foreign_insurance'] = ['current' => 'P', 'country_code' => 'SK', 'street' => 'Testovacia'];

            return $s;
        }, false, 'foreign_insurance.house_number'];
        yield 'forinreg příslušnost bez státu' => [static function (array $s): array {
            $s['foreign_legislation'] = ['applies' => true, 'country_code' => null];

            return $s;
        }, false, 'foreign_legislation.country_code'];
        yield 'důchod druh bez data od' => [static function (array $s): array {
            $s['pension']['type_code'] = '1';

            return $s;
        }, false, 'pension.type_and_received_from'];
        yield 'důchod datum od bez druhu' => [static function (array $s): array {
            $s['pension']['received_from'] = '2020-01-01';

            return $s;
        }, false, 'pension.type_and_received_from'];
        yield 'zdravotní omezení od po do' => [static function (array $s): array {
            $s['facts']['health_restrictions'] = [['type_code' => '1', 'from' => '2026-01-01', 'to' => '2025-01-01']];

            return $s;
        }, false, 'facts.health_restrictions'];
        yield 'zdravotní omezení od rovno do' => [static function (array $s): array {
            $s['facts']['health_restrictions'] = [['type_code' => '1', 'from' => '2026-01-01', 'to' => '2026-01-01']];

            return $s;
        }, false, 'facts.health_restrictions'];
        yield 'zdravotní omezení typ bez data od' => [static function (array $s): array {
            $s['facts']['health_restrictions'] = [['type_code' => '1', 'to' => '2027-01-01']];

            return $s;
        }, false, 'facts.from'];
        yield 'oprávnění od po do' => [static function (array $s): array {
            $s['foreign_worker'] = self::permit('2027-01-01', '2026-01-01');

            return $s;
        }, true, 'foreign_worker.permit_to'];
        yield 'oprávnění bez data od' => [static function (array $s): array {
            $s['foreign_worker'] = self::permit(null, '2027-01-01');

            return $s;
        }, true, 'foreign_worker.permit'];
        yield 'občan ČR s údaji o přístupu na trh práce' => [static function (array $s): array {
            $s['foreign_worker'] = ['free_access' => true, 'free_access_reason_code' => '1'];

            return $s;
        }, false, 'proof_identity'];
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mutate
     */
    #[DataProvider('violations')]
    public function testViolationIsReportedOnTheField(callable $mutate, bool $foreigner, string $field): void
    {
        $source = $foreigner ? self::foreignSource() : PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $identity = self::identity($foreigner);
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        self::assertSame([], $builder->problems($source, $identity, self::scope()), 'základ musí projít');

        $fields = array_column($builder->problems($mutate($source), $identity, self::scope()), 'field');
        self::assertContains($field, $fields, implode(', ', array_map('strval', $fields)));
    }

    /**
     * Atribut se zákazem (Z) nesmí dojít do věty, i když ho zdroj nese.
     *
     * @return iterable<string,array{callable(array<string,mixed>):array<string,mixed>,bool,list<string>,list<string>}>
     */
    public static function forbiddenWhenConditionHolds(): iterable
    {
        yield 'forinreg bez příslušnosti nenese stát' => [static function (array $s): array {
            $s['foreign_legislation'] = ['applies' => false, 'country_code' => 'SK'];

            return $s;
        }, false, ['forinreg/@state'], ['forinreg/@juris']];
        yield 'forinreg s příslušností nese stát' => [static function (array $s): array {
            $s['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];

            return $s;
        }, false, [], ['forinreg/@state', 'forinreg/@juris']];
        yield 'volný přístup nenese oprávnění' => [static function (array $s): array {
            $s['foreign_worker'] = self::permit('2025-01-01', '2027-01-01');
            $s['foreign_worker']['free_access'] = true;
            $s['foreign_worker']['free_access_reason_code'] = '1';

            return $s;
        }, true, ['nocitizen/@permtype', 'nocitizen/@permid', 'nocitizen/@permfro', 'nocitizen/@permto', 'nocitizen/@issue'], ['nocitizen/@freeacc', 'nocitizen/@perm']];
        yield 'bez volného přístupu nenese důvod' => [static function (array $s): array {
            $s['foreign_worker'] = self::permit('2025-01-01', '2027-01-01');
            $s['foreign_worker']['free_access_reason_code'] = '1';

            return $s;
        }, true, ['nocitizen/@perm'], ['nocitizen/@freeacc', 'nocitizen/@permtype', 'nocitizen/@permid', 'nocitizen/@permfro', 'nocitizen/@permto']];
        yield 'kontaktní adresa úplná' => [static function (array $s): array {
            $s['contact_address'] = self::address('CZ', '11000');

            return $s;
        }, false, [], ['client/cdr/@cit', 'client/cdr/@cnt', 'client/cdr/@num', 'client/cdr/@pnu']];
        yield 'bez kontaktní adresy' => [static fn (array $s): array => $s, false, ['client/cdr'], []];
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mutate
     * @param list<string> $absent
     * @param list<string> $present
     */
    #[DataProvider('forbiddenWhenConditionHolds')]
    public function testForbiddenValueDoesNotReachTheSentence(
        callable $mutate,
        bool $foreigner,
        array $absent,
        array $present,
    ): void {
        $source = $mutate($foreigner ? self::foreignSource() : PayrollRegistrationA1SnapshotBuilderTest::source('1', '1'));
        $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build($source, self::identity($foreigner), self::scope());
        $xpath = self::xpath(self::serialize($a1, $foreigner));
        foreach ($absent as $path) {
            self::assertFalse(self::exists($xpath, $path), "{$path} nesmí být ve větě");
        }
        foreach ($present as $path) {
            self::assertTrue(self::exists($xpath, $path), "{$path} má být ve větě");
        }
    }

    /** @return array<string,mixed> */
    private static function foreignSource(): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN123456',
            'foreign_issuer' => 'Syntetický úřad',
            'country_code' => 'SK',
        ];
        $source['foreign_worker'] = ['free_access' => true, 'free_access_reason_code' => '1'];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function permit(?string $from, ?string $to): array
    {
        return [
            'free_access' => false,
            'free_access_reason_code' => null,
            'permit_type_code' => '2',
            'permit_identifier' => 'SYN-PERMIT-1',
            'permit_from' => $from,
            'permit_to' => $to,
            'issuing_labour_office_code' => null,
        ];
    }

    /** @return array<string,string> */
    private static function address(string $country, string $postalCode, string $houseNumber = '12'): array
    {
        return [
            'street' => 'Testovací',
            'house_number' => $houseNumber,
            'orientation_number' => '3',
            'city' => 'Testov',
            'postal_code' => $postalCode,
            'country_code' => $country,
        ];
    }

    /** @return array<string,mixed> */
    private static function identity(bool $foreigner): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        if ($foreigner) {
            $identity['citizenship_country_code'] = 'SK';
        }

        return $identity;
    }

    /** @return array<string,mixed> */
    private static function scope(): array
    {
        return ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'];
    }

    private static function serialize(PayrollRegistrationA1Snapshot $a1, bool $foreigner): string
    {
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
                identity: self::identity($foreigner),
                identifiers: ['birth_number' => '9152031234', 'ecp' => null, 'vcp' => null, 'foreign_tax_identifier' => null],
                employmentExternalIdentifier: null,
                registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
                sourceVersions: ['regzec_a1' => $a1->source],
                regzecA1: $a1,
            ),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: '2026-08-04',
            expectedStartOn: null,
            actualStartOn: '2026-08-05',
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
        );

        return (new PayrollRegistrationXmlSerializer())->serialize($payload);
    }

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::NS);

        return $xpath;
    }

    private static function exists(DOMXPath $xpath, string $path): bool
    {
        $steps = array_map(
            static fn (string $step): string => str_starts_with($step, '@') ? $step : 'r:' . $step,
            explode('/', $path),
        );
        $nodes = $xpath->query('/r:REGZEC/r:employees/r:employee/' . implode('/', $steps));

        return $nodes !== false && $nodes->length > 0;
    }
}
