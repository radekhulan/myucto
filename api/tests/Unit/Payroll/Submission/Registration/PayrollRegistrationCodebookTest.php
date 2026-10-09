<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCodebooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kódy profilu REGZEC A1 musí být z číselníků EDV 1.4.0.6 (C_STAT, C_DUCH,
 * KKOV, pracovní režim, průběh práce, typ dokladu, typ daňové identifikace,
 * zdravotní omezení, druh oprávnění, důvod volného přístupu, pobočky ÚP).
 * Kód mimo číselník je vada pole, kód z číselníku projde. Data jsou syntetická.
 */
final class PayrollRegistrationCodebookTest extends TestCase
{
    /**
     * Pole => [úprava zdroje hodnotou, cizinec?, pole vady, číselník].
     *
     * @return iterable<string,array{callable(array<string,mixed>,string):array<string,mixed>,bool,string,list<string>}>
     */
    public static function fields(): iterable
    {
        yield 'stát trvalého pobytu' => [static function (array $s, string $v): array {
            $s['permanent_address']['country_code'] = $v;
            $s['czech_residence_address'] = ['street' => 'Testovací', 'house_number' => '1', 'city' => 'Testov', 'postal_code' => '11000'];

            return $s;
        }, true, 'permanent_address.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'stát kontaktní adresy' => [static function (array $s, string $v): array {
            $s['contact_address'] = ['street' => 'Testovací', 'house_number' => '1', 'city' => 'Testov', 'postal_code' => '11000', 'country_code' => $v];

            return $s;
        }, false, 'contact_address.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'stát daňové rezidence' => [static function (array $s, string $v): array {
            $s['tax_residency'] = ['country_code' => $v, 'identifier_type' => 'D', 'identifier' => 'SYN-1', 'residence_address' => null];

            return $s;
        }, true, 'tax_residency.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'stát dokladu' => [static function (array $s, string $v): array {
            $s['proof_identity']['country_code'] = $v;

            return $s;
        }, true, 'proof_identity.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'stát cizozemského nositele' => [static function (array $s, string $v): array {
            $s['foreign_insurance'] = ['current' => 'P', 'country_code' => $v];

            return $s;
        }, false, 'foreign_insurance.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'stát cizích předpisů' => [static function (array $s, string $v): array {
            $s['foreign_legislation'] = ['applies' => true, 'country_code' => $v];

            return $s;
        }, false, 'foreign_legislation.country_code', PayrollRegistrationCodebooks::COUNTRY];
        yield 'druh důchodu' => [static function (array $s, string $v): array {
            $s['pension']['type_code'] = $v;
            $s['pension']['received_from'] = '2020-01-01';

            return $s;
        }, false, 'pension.type_code', PayrollRegistrationCodebooks::PENSION_TYPE];
        yield 'nejvyšší vzdělání' => [static function (array $s, string $v): array {
            $s['facts']['highest_education_code'] = $v;

            return $s;
        }, false, 'facts.highest_education_code', PayrollRegistrationCodebooks::EDUCATION];
        yield 'vzdělání pro profesi' => [static function (array $s, string $v): array {
            $s['employment']['required_education_code'] = $v;

            return $s;
        }, false, 'employment.required_education_code', PayrollRegistrationCodebooks::EDUCATION];
        yield 'pracovní režim' => [static function (array $s, string $v): array {
            $s['employment']['work_mode_code'] = $v;

            return $s;
        }, false, 'employment.work_mode_code', PayrollRegistrationCodebooks::WORK_MODE];
        yield 'průběh práce' => [static function (array $s, string $v): array {
            $s['employment']['prevailing_workplace_code'] = $v;

            return $s;
        }, false, 'employment.prevailing_workplace_code', PayrollRegistrationCodebooks::WORK_PLACE];
        yield 'typ dokladu' => [static function (array $s, string $v): array {
            $s['proof_identity']['type_code'] = $v;

            return $s;
        }, true, 'proof_identity.type_code', PayrollRegistrationCodebooks::PROOF_TYPE];
        yield 'typ daňové identifikace' => [static function (array $s, string $v): array {
            $s['tax_residency'] = ['country_code' => 'SK', 'identifier_type' => $v, 'identifier' => 'SYN-1', 'residence_address' => null];

            return $s;
        }, true, 'tax_residency.identifier_type', PayrollRegistrationCodebooks::TAX_IDENTIFIER_TYPE];
        yield 'typ zdravotního omezení' => [static function (array $s, string $v): array {
            $s['facts']['health_restrictions'] = [['type_code' => $v, 'from' => '2025-01-01', 'to' => null]];

            return $s;
        }, false, 'facts.type_code', PayrollRegistrationCodebooks::HEALTH_RESTRICTION];
        yield 'důvod volného přístupu' => [static function (array $s, string $v): array {
            $s['foreign_worker'] = ['free_access' => true, 'free_access_reason_code' => $v];

            return $s;
        }, true, 'foreign_worker.free_access_reason_code', PayrollRegistrationCodebooks::FREE_ACCESS_REASON];
        yield 'druh oprávnění' => [static function (array $s, string $v): array {
            $s['foreign_worker'] = self::permit($v, null);

            return $s;
        }, true, 'foreign_worker.permit_type_code', PayrollRegistrationCodebooks::PERMIT_TYPE];
        yield 'pobočka ÚP' => [static function (array $s, string $v): array {
            $s['foreign_worker'] = self::permit('1', $v);

            return $s;
        }, true, 'foreign_worker.issuing_labour_office_code', PayrollRegistrationCodebooks::LABOUR_OFFICE];
    }

    /**
     * @param callable(array<string,mixed>,string):array<string,mixed> $set
     * @param list<string> $codebook
     */
    #[DataProvider('fields')]
    public function testCodeOutsideTheCodebookIsAFieldProblem(callable $set, bool $foreigner, string $field, array $codebook): void
    {
        $builder = new PayrollRegistrationA1SnapshotBuilder();
        $invalid = $codebook === PayrollRegistrationCodebooks::COUNTRY ? 'QQ' : 'Q';
        $problem = self::problem($builder->problems($set(self::source($foreigner), $invalid), self::identity($foreigner), self::scope()), $field);
        self::assertNotNull($problem, "{$field}: kód mimo číselník prošel");
        self::assertSame('codebook', $problem['message_key']);
        self::assertSame($invalid, $problem['params']['value']);

        foreach ($codebook as $code) {
            $problem = self::problem($builder->problems($set(self::source($foreigner), $code), self::identity($foreigner), self::scope()), $field);
            self::assertTrue(
                $problem === null || $problem['message_key'] !== 'codebook',
                "{$field}: kód {$code} z číselníku byl odmítnut",
            );
        }
    }

    public function testCodebooksMatchTheirOfficialSize(): void
    {
        self::assertCount(250, PayrollRegistrationCodebooks::COUNTRY);
        self::assertSame(['1', '2', '8', 'A', 'B', 'C'], PayrollRegistrationCodebooks::PENSION_TYPE);
        self::assertCount(16, PayrollRegistrationCodebooks::EDUCATION);
        self::assertSame(['I', 'P', 'O'], PayrollRegistrationCodebooks::PROOF_TYPE);
        self::assertSame(['D', 'R', 'S', 'J'], PayrollRegistrationCodebooks::TAX_IDENTIFIER_TYPE);
    }

    /**
     * @param list<array<string,mixed>> $problems
     * @return array<string,mixed>|null
     */
    private static function problem(array $problems, string $field): ?array
    {
        foreach ($problems as $problem) {
            if (($problem['field'] ?? null) === $field) {
                return $problem;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function source(bool $foreigner): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        if ($foreigner) {
            $source['proof_identity'] = ['type_code' => 'P', 'number' => 'SYN1', 'foreign_issuer' => 'Syntetický úřad', 'country_code' => 'SK'];
            $source['foreign_worker'] = ['free_access' => true, 'free_access_reason_code' => '1'];
        }

        return $source;
    }

    /** @return array<string,mixed> */
    private static function permit(string $type, ?string $office): array
    {
        return [
            'free_access' => false,
            'permit_type_code' => $type,
            'permit_identifier' => 'SYN-PERMIT-1',
            'permit_from' => '2025-01-01',
            'permit_to' => '2027-01-01',
            'issuing_labour_office_code' => $office,
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
}
