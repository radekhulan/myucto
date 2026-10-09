<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlSerializer;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlValidator;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationAddress;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Řádky normy hromadného oznámení zaměstnavatele (HOZ XML): název plátce,
 * obec a datum změny. Porušení musí podání zastavit s konkrétním kódem,
 * hodnoty na hranici projdou. Data jsou syntetická.
 */
final class HealthBulkNotificationNormMatrixTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,string,string}> */
    public static function violations(): iterable
    {
        yield 'název plátce chybí' => [['name' => ''], '2026-02-01', 'zp_employer_name_missing'];
        yield 'název plátce nad 80 znaků' => [['name' => str_repeat('a', 81)], '2026-02-01', 'zp_employer_name_too_long'];
        yield 'obec chybí' => [['city' => ''], '2026-02-01', 'zp_employer_city_missing'];
        yield 'obec nad 60 znaků' => [['city' => str_repeat('a', 61)], '2026-02-01', 'zp_employer_city_too_long'];
        yield 'neexistující datum změny' => [[], '2026-02-30', 'zp_xsd_validation_failed'];
        yield 'datum změny ve tvaru D.M.RRRR' => [[], '01.02.2026', 'zp_change_date_invalid'];
    }

    /** @param array<string,mixed> $employer */
    #[DataProvider('violations')]
    public function testViolationStopsTheNotification(array $employer, string $changedOn, string $code): void
    {
        [$serializer, $validator] = self::tools();
        $base = self::payload([], '2026-02-01');
        $validator->validateBulkNotification($base, $serializer->serializeBulkNotification($base));

        try {
            $broken = self::payload($employer, $changedOn);
            $validator->validateBulkNotification($broken, $serializer->serializeBulkNotification($broken));
            self::fail('Oznámení s porušením prošlo.');
        } catch (HealthNotificationException $exception) {
            self::assertSame($code, $exception->errorCode, $exception->getMessage());
        }
    }

    public function testValuesAtTheLimitPass(): void
    {
        [$serializer, $validator] = self::tools();
        $payload = self::payload(['name' => str_repeat('a', 80), 'city' => str_repeat('b', 60)], '2026-02-28');
        $xml = $serializer->serializeBulkNotification($payload);
        $validator->validateBulkNotification($payload, $xml);
        self::assertStringContainsString('<nazevPlatce>' . str_repeat('a', 80) . '</nazevPlatce>', $xml);
        self::assertStringContainsString('<adresaPlatceObec>' . str_repeat('b', 60) . '</adresaPlatceObec>', $xml);
        self::assertStringContainsString('2026-02-28', $xml);
    }

    /** @return array{HealthInsuranceXmlSerializer,HealthInsuranceXmlValidator} */
    private static function tools(): array
    {
        $schemas = new HealthInsuranceSchemaCatalog();
        $codes = new HealthNotificationCodeCatalog();
        $serializer = new HealthInsuranceXmlSerializer($schemas, $codes);

        return [$serializer, new HealthInsuranceXmlValidator($schemas, $codes, $serializer)];
    }

    /** @param array<string,mixed> $employer */
    private static function payload(array $employer, string $changedOn): HealthBulkNotificationPayload
    {
        return new HealthBulkNotificationPayload(
            '111',
            HealthEmployerIdentification::fromBusinessId(...[...[
                'businessId' => '12345678',
                'accountingUnit' => '00',
                'name' => 'Testovací firma s.r.o.',
                'street' => 'Zkušební',
                'houseNumber' => '12',
                'postalCode' => '11000',
                'city' => 'Praha 1',
                'phone' => '+420111222333',
            ], ...$employer]),
            [new HealthNotificationChange(
                changeCode: 'P',
                changedOn: $changedOn,
                insuranceNumber: '9001011234',
                firstName: 'Ludmila',
                lastName: 'Testovací',
                address: new HealthNotificationAddress('Na Můstku', '7', '60200', 'Testov'),
            )],
        );
    }
}
