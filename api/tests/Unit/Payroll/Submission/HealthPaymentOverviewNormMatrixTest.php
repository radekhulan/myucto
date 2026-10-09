<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthEmployerIdentification;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlSerializer;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceXmlValidator;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Řádky normy přehledu o platbě pojistného zaměstnavatele (PPPZ XML):
 * délky řetězců podle XSD, povinná adresa a základ pojistného. Porušení musí
 * podání zastavit (serializér nebo validátor), správná věta nese hodnoty na
 * svých místech. Data jsou syntetická.
 */
final class HealthPaymentOverviewNormMatrixTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,array<string,mixed>,string}> */
    public static function violations(): iterable
    {
        yield 'název plátce nad 80 znaků' => [['name' => str_repeat('a', 81)], [], 'zp_employer_name_too_long'];
        yield 'číslo popisné nad 60 znaků' => [['houseNumber' => str_repeat('9', 61)], [], 'zp_employer_house_number_too_long'];
        yield 'interní identifikace nad 60 znaků' => [[], ['internalReference' => str_repeat('a', 61)], 'zp_internal_reference_invalid'];
        yield 'základ nad 14 platných číslic' => [[], ['assessmentBaseMinorUnits' => 1_000_000_000_000_000], 'zp_overview_assessment_base_invalid'];
    }

    /**
     * @param array<string,mixed> $employer
     * @param array<string,mixed> $payload
     */
    #[DataProvider('violations')]
    public function testViolationStopsTheOverview(array $employer, array $payload, string $code): void
    {
        [$serializer, $validator] = self::tools();
        $base = self::payload([], []);
        $validator->validatePaymentOverview($base, $serializer->serializePaymentOverview($base));

        try {
            $broken = self::payload($employer, $payload);
            $validator->validatePaymentOverview($broken, $serializer->serializePaymentOverview($broken));
            self::fail('Přehled s porušením prošel.');
        } catch (HealthNotificationException $exception) {
            self::assertSame($code, $exception->errorCode, $exception->getMessage());
        }
    }

    /** Hranice: přesně 80 znaků názvu a 60 znaků čísla popisného i interní identifikace projde. */
    public function testValuesAtTheLimitPassAndSitInPlace(): void
    {
        [$serializer, $validator] = self::tools();
        $payload = self::payload(
            ['name' => str_repeat('a', 80), 'houseNumber' => '12/3'],
            ['internalReference' => str_repeat('b', 60), 'assessmentBaseMinorUnits' => 1234567890123],
        );
        $xml = $serializer->serializePaymentOverview($payload);
        $validator->validatePaymentOverview($payload, $xml);

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $value = static function (string $name) use ($xpath): string {
            $nodes = $xpath->query("//*[local-name()='{$name}']");
            self::assertNotFalse($nodes);
            self::assertSame(1, $nodes->length, $name);

            return (string) $nodes->item(0)?->nodeValue;
        };
        self::assertSame(str_repeat('a', 80), $value('nazevPlatce'));
        self::assertSame('12/3', $value('adresaPlatceCisloPopisneOrientacni'));
        self::assertSame(str_repeat('b', 60), $value('interniIdentifikacePodaniPodavatele'));
        self::assertSame('12345678901.23', $value('soucetZakladuPojistneho'));
    }

    /** @return array{HealthInsuranceXmlSerializer,HealthInsuranceXmlValidator} */
    private static function tools(): array
    {
        $schemas = new HealthInsuranceSchemaCatalog();
        $codes = new HealthNotificationCodeCatalog();
        $serializer = new HealthInsuranceXmlSerializer($schemas, $codes);

        return [$serializer, new HealthInsuranceXmlValidator($schemas, $codes, $serializer)];
    }

    /**
     * @param array<string,mixed> $employer
     * @param array<string,mixed> $overrides
     */
    private static function payload(array $employer, array $overrides): HealthPaymentOverviewPayload
    {
        return new HealthPaymentOverviewPayload(...[...[
            'insurerCode' => '111',
            'overviewKind' => HealthPaymentOverviewPayload::KIND_REGULAR,
            'employer' => HealthEmployerIdentification::fromBusinessId(...[...[
                'businessId' => '12345678',
                'accountingUnit' => '00',
                'name' => 'Testovací firma s.r.o.',
                'street' => 'Zkušební',
                'houseNumber' => '12',
                'postalCode' => '11000',
                'city' => 'Praha 1',
                'phone' => '+420111222333',
            ], ...$employer]),
            'month' => 1,
            'year' => 2026,
            'employeeCount' => 3,
            'assessmentBaseMinorUnits' => 12345600,
            'contributionCzk' => 16667,
        ], ...$overrides]);
    }
}
