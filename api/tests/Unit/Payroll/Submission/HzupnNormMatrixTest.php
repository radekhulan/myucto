<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Řádky normy HZUPN20: identifikace pojištěnce, zaměstnavatele, návrat do
 * práce a řídicí atributy věty. Každé porušení musí skončit konkrétním kódem
 * odmítnutí, správná věta nese hodnoty na svých místech. Data jsou syntetická.
 */
final class HzupnNormMatrixTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function violations(): iterable
    {
        yield 'RČ mimo modulo 11' => [['insuredBirthNumber' => '8001010009'], 'hzupn_birth_number_invalid'];
        yield 'RČ s měsícem 13' => [['insuredBirthNumber' => '8013010004'], 'hzupn_birth_number_invalid'];
        yield 'RČ s osmi číslicemi' => [['insuredBirthNumber' => '80010100'], 'hzupn_birth_number_invalid'];
        yield 'bez RČ i data narození' => [['insuredBirthNumber' => null, 'insuredBirthDate' => null], 'hzupn_insured_identifier_missing'];
        yield 'návrat do práce bez data' => [['returnedOn' => null], 'hzupn_return_date_missing'];
        yield 'bez názvu zaměstnavatele' => [['employerName' => ''], 'hzupn_xsd_validation_failed'];
        yield 'název zaměstnavatele nad 144 znaků' => [['employerName' => str_repeat('a', 145)], 'hzupn_xsd_validation_failed'];
        yield 'variabilní symbol začíná nulou' => [['employerVariableSymbol' => '0123456789'], 'sickness_variable_symbol_invalid'];
        yield 'variabilní symbol chybí' => [['employerVariableSymbol' => ''], 'sickness_variable_symbol_invalid'];
        yield 'jméno prázdné' => [['insuredFirstName' => ''], 'hzupn_xsd_validation_failed'];
        yield 'příjmení nad 100 znaků' => [['insuredLastName' => str_repeat('a', 101)], 'hzupn_xsd_validation_failed'];
        yield 'IČ nad 35 znaků' => [['employerIdentificationNumber' => str_repeat('1', 36)], 'hzupn_xsd_validation_failed'];
        yield 'důvod nenávratu nad 200 znaků' => [['returnedToWork' => false, 'returnReason' => str_repeat('a', 201), 'returnedOn' => null, 'hoursWorkedLastDay' => null, 'shiftHoursLastDay' => null], 'hzupn_xsd_validation_failed'];
        yield 'název programu nad 64 znaků' => [['productName' => str_repeat('a', 65)], 'hzupn_vendor_invalid'];
        yield 'verze programu nad 16 znaků' => [['productVersion' => str_repeat('1', 17)], 'hzupn_vendor_invalid'];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('violations')]
    public function testViolationIsRejectedWithItsCode(array $overrides, string $code): void
    {
        $validator = self::validator();
        $base = self::payload([]);
        $validator->validateHzupn($base, (new HzupnXmlSerializer())->serialize($base), '2026-08-03');

        $payload = self::payload($overrides);
        try {
            $validator->validateHzupn($payload, (new HzupnXmlSerializer())->serialize($payload), '2026-08-03');
            self::fail('Hlášení s porušením prošlo.');
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    /** Správná věta: deklarace, řídicí atributy a identifikační údaje. */
    public function testValidSentenceCarriesTheControlAttributes(): void
    {
        $payload = self::payload([]);
        $xml = (new HzupnXmlSerializer())->serialize($payload);
        self::validator()->validateHzupn($payload, $xml, '2026-08-03');

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $value = static function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);
            self::assertNotFalse($nodes);
            self::assertSame(1, $nodes->length, $query);

            return trim((string) $nodes->item(0)?->nodeValue);
        };
        self::assertSame('20201.01', $value("/*[local-name()='PodaniHZUPN']/@version"));
        self::assertContains($value("//*[local-name()='SENDER']/@ISDSreport"), ['0', '1', '2', '3']);
        self::assertSame('8001010006', $value("//*[local-name()='pojistenec']/*[local-name()='rodCislo']"));
        self::assertSame('1234567890', $value("//*[local-name()='zamestnani']/*[local-name()='variabilniSymbol']"));
        self::assertSame('2026-08-24', $value("//*[local-name()='potvrzeniZamestnavatele']/*[local-name()='datumNavratDoPrace']"));
    }

    /** RČ chybí, rozhoduje datum narození: hlášení projde a nese ho. */
    public function testBirthDateReplacesMissingBirthNumber(): void
    {
        $payload = self::payload(['insuredBirthNumber' => null]);
        $xml = (new HzupnXmlSerializer())->serialize($payload);
        self::validator()->validateHzupn($payload, $xml, '2026-08-03');
        self::assertStringContainsString('1980-01-01', $xml);
        self::assertStringNotContainsString('rodCislo', $xml);
    }

    private static function validator(): SicknessXmlValidator
    {
        return new SicknessXmlValidator(new CsszSchemaCatalog(), new NempriXmlSerializer(), new HzupnXmlSerializer());
    }

    /** @param array<string,mixed> $overrides */
    private static function payload(array $overrides): HzupnXmlPayload
    {
        return new HzupnXmlPayload(...[...[
            'employerReport' => true,
            'personReport' => false,
            'foreignCase' => false,
            'confirmationNumber' => 'E1234567',
            'osszCode' => 115,
            'osszName' => null,
            'issuedOn' => '2026-08-24',
            'correction' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Testovací',
            'insuredTitle' => null,
            'insuredBirthNumber' => '8001010006',
            'insuredBirthDate' => '1980-01-01',
            'employerName' => 'Testovací zaměstnavatel s.r.o.',
            'employerIdentificationNumber' => '12345678',
            'employerVariableSymbol' => '1234567890',
            'returnedToWork' => true,
            'returnReason' => null,
            'returnedOn' => '2026-08-24',
            'hoursWorkedLastDay' => '4',
            'shiftHoursLastDay' => '8',
            'workIntervals' => [],
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '20201.01',
        ], ...$overrides]);
    }
}
