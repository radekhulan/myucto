<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollCsszDistrictCodebook;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationAttachmentRules;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationBirthNumberConsistency;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationBusinessMatrix;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationDeltaVariantRule;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationForeignInsurerAddress;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationHouseNumber;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationMinimumAge;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSlovakBirthNumberRule;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSpecialStartDate;
use PHPUnit\Framework\TestCase;

/**
 * Sdílená pravidla registračních podání REGZEC25 (EDV 1.4.0.6): věk, rodné
 * číslo, číselník okresů, adresa nositele pojištění, přílohy a varianty.
 * Zdroj dat je syntetický.
 */
final class PayrollRegistrationNormCoverageRulesTest extends TestCase
{
    public function testMinimumAgeIsFourteenFullYears(): void
    {
        self::assertTrue(PayrollRegistrationMinimumAge::isUnderage('2012-08-06', '2026-08-05'));
        self::assertFalse(PayrollRegistrationMinimumAge::isUnderage('2012-08-05', '2026-08-05'));
        self::assertFalse(PayrollRegistrationMinimumAge::isUnderage('1990-01-01', '2026-08-05'));
        self::assertFalse(PayrollRegistrationMinimumAge::isUnderage('neplatne', '2026-08-05'));
        self::assertSame(
            '1991-02-03',
            PayrollRegistrationMinimumAge::birthDateFromBirthNumber('915203/0008'),
        );
        self::assertNull(PayrollRegistrationMinimumAge::birthDateFromBirthNumber('123'));
        self::assertNull(PayrollRegistrationMinimumAge::birthDateFromBirthNumber(null));
    }

    public function testFictiveStartOnlyForSpecialActivitiesStartedBeforeTwentyTwentySix(): void
    {
        self::assertSame('2026-01-01', PayrollRegistrationSpecialStartDate::reported('13', '1', '2025-06-01'));
        self::assertSame('2026-01-01', PayrollRegistrationSpecialStartDate::reported('1', '2', '2025-06-01'));
        self::assertSame('2026-02-01', PayrollRegistrationSpecialStartDate::reported('13', '1', '2026-02-01'));
        self::assertSame('2025-06-01', PayrollRegistrationSpecialStartDate::reported('1', '1', '2025-06-01'));
        self::assertSame('2025-06-01', PayrollRegistrationSpecialStartDate::reported('M', '1', '2025-06-01'));
    }

    public function testDistrictCodebookHasEightyNineCodesAndGuardsTheSettings(): void
    {
        self::assertCount(89, PayrollCsszDistrictCodebook::CODES);
        self::assertTrue(PayrollCsszDistrictCodebook::contains('110'));
        self::assertFalse(PayrollCsszDistrictCodebook::contains('301'));
        self::assertTrue(PayrollEmployerSettingsValidator::isValidSocialSecurityOfficeCode('894'));
        self::assertFalse(PayrollEmployerSettingsValidator::isValidSocialSecurityOfficeCode('999'));
        self::assertFalse(PayrollEmployerSettingsValidator::isValidSocialSecurityOfficeCode('11'));
    }

    public function testBirthNumberDecidesBirthDateAndSex(): void
    {
        $identity = ['birth_date' => '1991-02-03', 'sex' => 'female'];
        self::assertSame([], PayrollRegistrationBirthNumberConsistency::problems($identity, '9152030008'));
        self::assertSame([], PayrollRegistrationBirthNumberConsistency::problems($identity, null));

        $codes = array_column(
            PayrollRegistrationBirthNumberConsistency::problems(
                ['birth_date' => '1991-02-04', 'sex' => 'male'],
                '9152030008',
            ),
            'code',
        );
        self::assertSame(
            ['registration_identity_birth_date_mismatch', 'registration_identity_sex_mismatch'],
            $codes,
        );
    }

    public function testForeignInsurerAddressIsAllOrNothing(): void
    {
        self::assertSame([], PayrollRegistrationForeignInsurerAddress::missing(['country_code' => 'SK']));
        self::assertSame(
            ['house_number', 'postal_code', 'city'],
            PayrollRegistrationForeignInsurerAddress::missing(['street' => 'Testovacia']),
        );
        self::assertSame(
            ['city'],
            PayrollRegistrationForeignInsurerAddress::missing([
                'house_number' => '7', 'postal_code' => '81101',
            ]),
        );
        self::assertSame([], PayrollRegistrationForeignInsurerAddress::missing([
            'house_number' => '7', 'postal_code' => '81101', 'city' => 'Bratislava',
        ]));
    }

    public function testAttachmentRulesCheckExtensionNameAndSizes(): void
    {
        $small = base64_encode('x');
        self::assertTrue(PayrollRegistrationAttachmentRules::extensionAllowed('a.DOCX'));
        self::assertFalse(PayrollRegistrationAttachmentRules::extensionAllowed('a.exe'));
        self::assertFalse(PayrollRegistrationAttachmentRules::extensionAllowed('a.'));
        self::assertFalse(PayrollRegistrationAttachmentRules::extensionAllowed('a'));
        $kinds = array_column(PayrollRegistrationAttachmentRules::violations([
            ['name' => 'a.pdf', 'data_base64' => $small],
            ['name' => 'A.pdf', 'data_base64' => $small],
            ['name' => 'b.exe', 'data_base64' => $small],
        ]), 'kind');
        self::assertSame(['duplicate_name', 'extension'], $kinds);
        $big = base64_encode(str_repeat('x', PayrollRegistrationAttachmentRules::MAX_FILE_BYTES));
        self::assertSame([], PayrollRegistrationAttachmentRules::violations([
            ['name' => 'a.pdf', 'data_base64' => $big],
        ]));
        self::assertSame(['file_size'], array_column(PayrollRegistrationAttachmentRules::violations([
            ['name' => 'a.pdf', 'data_base64' => base64_encode(str_repeat('x', 2 * 1024 * 1024 + 1))],
        ]), 'kind'));
        self::assertSame(['total_size'], array_column(PayrollRegistrationAttachmentRules::violations([
            ['name' => 'a.pdf', 'data_base64' => $big],
            ['name' => 'b.pdf', 'data_base64' => $big],
            ['name' => 'c.pdf', 'data_base64' => $small],
        ]), 'kind'));
    }

    public function testHouseNumberRules(): void
    {
        self::assertTrue(PayrollRegistrationHouseNumber::validDescriptive('1234'));
        self::assertTrue(PayrollRegistrationHouseNumber::validDescriptive('0'));
        self::assertFalse(PayrollRegistrationHouseNumber::validDescriptive('12345'));
        self::assertFalse(PayrollRegistrationHouseNumber::validDescriptive('12a'));
        self::assertFalse(PayrollRegistrationHouseNumber::validDescriptive('12/3'));
        self::assertFalse(PayrollRegistrationHouseNumber::validDescriptive('0', true));
        self::assertTrue(PayrollRegistrationHouseNumber::validDescriptive('9999', true));
        self::assertTrue(PayrollRegistrationHouseNumber::validOrientation('12ab', true));
        self::assertFalse(PayrollRegistrationHouseNumber::validOrientation('12abc', true));
        self::assertTrue(PayrollRegistrationHouseNumber::validOrientation('123456789012', false));
        self::assertFalse(PayrollRegistrationHouseNumber::validOrientation('1234567890123', false));
    }

    public function testDeltaVariantRuleListsForbiddenParts(): void
    {
        $delta = [
            'title_prefix' => 'Ing.',
            'contact_address' => ['city' => 'Praha'],
            'pension' => ['type_code' => 'S'],
            'employment' => ['work_mode_code' => '1', 'contract_workplace' => 'Praha'],
        ];
        self::assertSame(
            ['contact_address', 'pension', 'employment.work_mode_code'],
            PayrollRegistrationDeltaVariantRule::forbiddenPaths(
                PayrollRegistrationBusinessMatrix::VARIANT_SPEC,
                $delta,
            ),
        );
        self::assertSame([], PayrollRegistrationDeltaVariantRule::forbiddenPaths(
            PayrollRegistrationBusinessMatrix::VARIANT_OST,
            $delta,
        ));
        self::assertContains('employment.contract_workplace', PayrollRegistrationDeltaVariantRule::forbiddenPaths(
            PayrollRegistrationBusinessMatrix::VARIANT_10,
            $delta,
        ));
    }

    /** REGZEC25-client.bno-02 a client.birth.dat-05 v přihlášce A1. */
    public function testA1IdentifierRulesInTheSnapshotBuilder(): void
    {
        $builder = new PayrollRegistrationIdentitySnapshotBuilder();
        $withoutNumber = self::identitySource(null, null);
        $this->expectCode(
            'registration_identity_regzec_identity_incomplete',
            fn () => $builder->build(self::scope(), $withoutNumber),
        );

        $ecpOnly = self::identitySource(null, '123456789');
        self::assertNotNull($builder->build(self::scope(), $ecpOnly)->regzecA1);

        $consistent = self::identitySource('915203/0008', null);
        self::assertNotNull($builder->build(self::scope(), $consistent)->regzecA1);

        $wrongDate = self::identitySource('915203/0008', null);
        $wrongDate['identity']['birth_date'] = '1991-02-04';
        $this->expectCode(
            'registration_identity_birth_date_mismatch',
            fn () => $builder->build(self::scope(), $wrongDate),
        );

        $wrongSex = self::identitySource('915203/0008', null);
        $wrongSex['identity']['sex'] = 'male';
        $this->expectCode(
            'registration_identity_sex_mismatch',
            fn () => $builder->build(self::scope(), $wrongSex),
        );

        $ecpIgnoresDate = self::identitySource(null, '123456789');
        $ecpIgnoresDate['identity']['birth_date'] = '1980-05-05';
        self::assertNotNull($builder->build(self::scope(), $ecpIgnoresDate)->regzecA1);
    }

    /**
     * REGZEC25-client.bno-09 (pokyny REGZEC, ID 10057): „slovenské" RČ občana
     * SR přiděleného po 31. 12. 1992 se do A1 neuvádí, cizinec s pobytem v ČR
     * ale může mít české RČ a to se uvádět musí. Evidence je nerozliší a ČSSZ
     * přihlášky s RČ u takových osob přijímá, proto varování, ne zákaz.
     */
    public function testSlovakCitizenBornAfter1992WithBirthNumberIsWarnedNotBlocked(): void
    {
        $builder = new PayrollRegistrationIdentitySnapshotBuilder();
        $after = self::identitySource('935203/0006', null);
        $after['identity']['birth_date'] = '1993-02-03';
        $after['identity']['citizenship_country_code'] = 'SK';
        // Syntetická karta nemá údaje cizince, sestavení na nich může skončit,
        // jen ne na rodném čísle.
        self::assertNotSame(
            'registration_identity_slovak_birth_number_after_1992',
            self::failureCode(fn () => $builder->build(self::scope(), $after)),
        );
        self::assertSame(
            'registration_identity_slovak_birth_number_after_1992',
            PayrollRegistrationSlovakBirthNumberRule::warning('SK', '935203/0006', '1993-02-03')['code'] ?? null,
        );
        // Bez data narození na kartě se rozhodne podle data z RČ.
        self::assertNotNull(PayrollRegistrationSlovakBirthNumberRule::warning('SK', '935203/0006', null));

        self::assertNull(PayrollRegistrationSlovakBirthNumberRule::warning('SK', '915203/0008', '1991-02-03'));
        self::assertNull(PayrollRegistrationSlovakBirthNumberRule::warning('SK', null, '1993-02-03'));
        self::assertNull(PayrollRegistrationSlovakBirthNumberRule::warning('CZ', '935203/0006', '1993-02-03'));
    }

    /** Kód, se kterým sestavení skončilo; `null`, když prošlo. */
    private static function failureCode(callable $build): ?string
    {
        try {
            $build();
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            return $exception->validationCode;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function scope(): array
    {
        return [
            'supplier_id' => 11,
            'submission_id' => 21,
            'source_revision_id' => null,
            'employee_id' => 41,
            'employment_id' => 51,
            'environment' => 'production',
            'agenda_code' => 'REGZEC25',
            'effective_on' => '2026-08-05',
        ];
    }

    /** @return array<string,mixed> */
    private static function identitySource(?string $birthNumber, ?string $ecp): array
    {
        $sources = ['foreign_tax_identifier' => ['id' => 121, 'row_version' => 1]];
        if ($birthNumber !== null) {
            $sources['birth_number'] = ['id' => 151, 'row_version' => 1];
        }
        if ($ecp !== null) {
            $sources['ecp'] = ['id' => 152, 'row_version' => 1];
        }

        return [
            'identity' => [
                'id' => 111,
                'employee_id' => 41,
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
                'birth_number' => $birthNumber,
                'ecp' => $ecp,
                'vcp' => null,
                'foreign_tax_identifier' => 'SYN-TIN-1',
            ],
            'identifier_sources' => $sources,
            'employment_external_identifier' => null,
            'resolution' => [
                'person_identity' => 'resolved',
                'employment_external_id' => 'not_assigned',
            ],
            'regzec_a1' => PayrollRegistrationA1SnapshotBuilderTest::source('1', '1'),
        ];
    }

    /** @param callable():mixed $callback */
    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail("Očekávána chyba {$code}.");
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }
}
