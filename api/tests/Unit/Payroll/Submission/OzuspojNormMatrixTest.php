<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Řádky normy OZUSPOJ23: povinné a podmíněné údaje, tvar dat, kontaktního
 * pracovníka a identifikace odesílatele. Každé porušení musí skončit
 * konkrétním kódem odmítnutí, správná věta nese hodnoty na svých místech.
 * Data jsou syntetická.
 */
final class OzuspojNormMatrixTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function violations(): iterable
    {
        yield 'datumOd chybí u oznámení' => [['intentFrom' => null], 'ozuspoj_intent_from_required'];
        yield 'datumOd chybí u storna' => [['kind' => OzuspojSubmissionKind::Cancellation, 'intentFrom' => null], 'ozuspoj_intent_from_required'];
        yield 'datumDo před datumOd' => [['kind' => OzuspojSubmissionKind::Cancellation, 'intentTo' => '2026-08-31'], 'ozuspoj_intent_period_invalid'];
        yield 'neexistující den narození' => [['employeeBirthDate' => '1980-02-30'], 'ozuspoj_date_invalid'];
        yield 'datum ve tvaru D.M.RRRR' => [['intentFrom' => '1.9.2026'], 'ozuspoj_date_invalid'];
        yield 'jméno zaměstnance prázdné' => [['employeeFirstName' => ' '], 'ozuspoj_employee_first_name_missing'];
        yield 'příjmení zaměstnance prázdné' => [['employeeLastName' => ''], 'ozuspoj_employee_last_name_missing'];
        yield 'rodné číslo chybí' => [['employeeBirthNumber' => null], 'ozuspoj_birth_number_missing'];
        yield 'kontaktní e-mail bez zavináče' => [['contactEmail' => 'kontakt.example.cz'], 'ozuspoj_xsd_validation_failed'];
        yield 'kontaktní e-mail nad 250 znaků' => [['contactEmail' => str_repeat('a', 240) . '@example.cz'], 'ozuspoj_xsd_validation_failed'];
        yield 'jméno kontaktu s číslicí' => [['contactFirstName' => 'Jana2'], 'ozuspoj_xsd_validation_failed'];
        yield 'jméno kontaktu nad 100 znaků' => [['contactFirstName' => str_repeat('a', 101)], 'ozuspoj_xsd_validation_failed'];
        yield 'příjmení kontaktu s mezerou na konci' => [['contactLastName' => 'Nová '], 'ozuspoj_xsd_validation_failed'];
        yield 'telefon nad 33 znaků' => [['contactPhone' => str_repeat('1', 34)], 'ozuspoj_xsd_validation_failed'];
        yield 'telefon se zavináčem' => [['contactPhone' => '123@456'], 'ozuspoj_xsd_validation_failed'];
        yield 'notifikační e-mail bez zavináče' => [['notificationEmail' => 'notifikace'], 'ozuspoj_notification_email_invalid'];
        yield 'verze programu nad 16 znaků' => [['productVersion' => str_repeat('1', 17)], 'ozuspoj_vendor_invalid'];
        yield 'název programu nad 64 znaků' => [['productName' => str_repeat('a', 65)], 'ozuspoj_vendor_invalid'];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('violations')]
    public function testViolationIsRejectedWithItsCode(array $overrides, string $code): void
    {
        $base = self::payload([]);
        $validator = new OzuspojXmlValidator(new OzuspojSchemaCatalog());
        $validator->validate($base, (new OzuspojXmlSerializer())->serialize($base));

        $payload = self::payload($overrides);
        try {
            $validator->validate($payload, (new OzuspojXmlSerializer())->serialize($payload));
            self::fail('Podání s porušením prošlo.');
        } catch (OzuspojException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * Správná věta: deklarace XML, identifikace odesílatele, e-mail pro
     * notifikaci, ISDSreport, kontaktní pracovník v pořadí jmeno, prijmeni,
     * telefon, email a povinné údaje zaměstnance.
     */
    public function testValidSentenceCarriesTheValuesInPlace(): void
    {
        $payload = self::payload([
            'notificationEmail' => 'notifikace@example.cz',
            'contactFirstName' => 'Jana',
            'contactLastName' => 'Nová',
            'contactPhone' => '+420 123 456 789',
            'contactEmail' => 'kontakt@example.cz',
        ]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);
        (new OzuspojXmlValidator(new OzuspojSchemaCatalog()))->validate($payload, $xml);

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
        self::assertSame('MyUcto', $value("//*[local-name()='VENDOR']/@productName"));
        self::assertSame('1.0.0', $value("//*[local-name()='VENDOR']/@productVersion"));
        self::assertSame('notifikace@example.cz', $value("//*[local-name()='SENDER']/@EmailNotifikace"));
        self::assertSame('3', $value("//*[local-name()='SENDER']/@ISDSreport"));
        self::assertSame('Jan', $value("//*[local-name()='zamestnanec']/*[local-name()='jmeno']"));
        self::assertSame('Malý', $value("//*[local-name()='zamestnanec']/*[local-name()='prijmeni']"));
        self::assertSame('8001011117', $value("//*[local-name()='zamestnanec']/*[local-name()='rodneCislo']"));
        self::assertSame('2026-09-01', $value("//*[local-name()='zamer']/*[local-name()='datumOd']"));
        $contact = $xpath->query("//*[local-name()='pracovnik']/*");
        self::assertNotFalse($contact);
        $order = [];
        foreach ($contact as $node) {
            $order[] = $node->localName . '=' . $node->nodeValue;
        }
        self::assertSame(
            ['jmeno=Jana', 'prijmeni=Nová', 'telefon=+420 123 456 789', 'email=kontakt@example.cz'],
            $order,
        );
    }

    /** Kontaktní pracovník je nepovinný: bez údajů se prvek nevytvoří a věta projde. */
    public function testContactPersonIsOptional(): void
    {
        $payload = self::payload([]);
        $xml = (new OzuspojXmlSerializer())->serialize($payload);
        (new OzuspojXmlValidator(new OzuspojSchemaCatalog()))->validate($payload, $xml);
        self::assertStringNotContainsString('pracovnik', $xml);
    }

    /** @param array<string,mixed> $overrides */
    private static function payload(array $overrides): OzuspojXmlPayload
    {
        return new OzuspojXmlPayload(...array_merge([
            'kind' => OzuspojSubmissionKind::Start,
            'osszCode' => 222,
            'intentFrom' => '2026-09-01',
            'intentTo' => null,
            'employerVariableSymbol' => '1182114205',
            'employerIdentificationNumber' => '12345678',
            'employerName' => 'Zkušební firma s.r.o.',
            'employeeFirstName' => 'Jan',
            'employeeLastName' => 'Malý',
            'employeeBirthDate' => '1980-01-01',
            'employeeBirthNumber' => '8001011117',
            'productName' => 'MyUcto',
            'productVersion' => '1.0.0',
        ], $overrides));
    }
}
