<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Řádky normy PREZEC26 pro předregistraci P1: okno podání, datum vyplnění,
 * občanství, jména a identifikace programu. Každé porušení musí skončit
 * konkrétním kódem odmítnutí; výchozí věta projde. Data jsou syntetická.
 */
final class PayrollPrezecNormMatrixTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,array<string,mixed>,string}> */
    public static function violations(): iterable
    {
        yield 'datum vyplnění po předpokládaném nástupu' => [['preparedOn' => '2026-08-06'], [], 'registration_prezec_start_window_invalid'];
        yield 'nástup víc než 8 dní po vyplnění' => [['expectedStartOn' => '2026-08-13'], [], 'registration_prezec_start_window_invalid'];
        yield 'občanství na tři znaky' => [[], ['citizenship_country_code' => 'CZE'], 'registration_prezec_foreign_requires_full_registration'];
        yield 'příjmení s mezerou na konci' => [[], ['last_name' => 'Novotná '], 'registration_xsd_validation_failed'];
        yield 'rodné příjmení s mezerou na konci' => [[], ['birth_surname' => 'Nováková '], 'registration_xsd_validation_failed'];
        yield 'název programu nad 64 znaků' => [['productName' => str_repeat('a', 65)], [], 'registration_vendor_invalid'];
        yield 'verze programu nad 16 znaků' => [['productVersion' => str_repeat('1', 17)], [], 'registration_vendor_invalid'];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $identity
     */
    #[DataProvider('violations')]
    public function testViolationIsRejectedWithItsCode(array $payload, array $identity, string $code): void
    {
        $validator = new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog());
        $base = self::payload([], []);
        $validator->validate($base, (new PayrollRegistrationXmlSerializer())->serialize($base));

        $broken = self::payload($payload, $identity);
        try {
            $validator->validate($broken, (new PayrollRegistrationXmlSerializer())->serialize($broken));
            self::fail('Předregistrace s porušením prošla.');
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @param array<string,mixed> $identity
     */
    private static function payload(array $overrides, array $identity): PayrollRegistrationXmlPayload
    {
        $snapshot = new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => 31,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => 'PREZEC26',
                'effective_on' => '2026-08-04',
            ],
            identity: [...[
                'first_name' => 'Jana',
                'last_name' => 'Novotná',
                'title_prefix' => null,
                'title_suffix' => null,
                'birth_surname' => 'Nováková',
                'birth_date' => '1991-02-03',
                'birth_place' => 'Testov',
                'birth_country_code' => 'CZ',
                'citizenship_country_code' => 'CZ',
                'sex' => 'female',
            ], ...$identity],
            identifiers: ['birth_number' => '9152031234', 'ecp' => null, 'vcp' => null, 'foreign_tax_identifier' => null],
            employmentExternalIdentifier: null,
            registrationEligibility: [
                'status' => 'verified',
                'basis' => 'domestic_citizenship_country_code',
                'citizenship_country_code' => 'CZ',
            ],
            sourceVersions: [],
        );

        return new PayrollRegistrationXmlPayload(...[...[
            'identity' => $snapshot,
            'interaction' => new PayrollRegistrationInteraction('PREZEC26', 'limited_pre_registration', 9),
            'sequenceNumber' => 1,
            'formGuid' => '12345678-1234-1234-1234-123456789ABC',
            'preparedOn' => '2026-08-04',
            'expectedStartOn' => '2026-08-05',
            'actualStartOn' => null,
            'employerVariableSymbol' => '1100000007',
            'employerName' => 'Syntetický zaměstnavatel s.r.o.',
            'csszWorkplaceCode' => '110',
            'eventSnapshot' => null,
            'productName' => 'MyUcto',
            'productVersion' => '5.6.0',
        ], ...$overrides]);
    }
}
