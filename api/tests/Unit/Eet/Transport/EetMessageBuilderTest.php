<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetMessageBuilder;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetValidationException;
use MyInvoice\Service\Eet\Transport\EetXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EetMessageBuilderTest extends TestCase
{
    private const UUID = 'b3a09b52-7c87-4014-a496-4c7a53cf9125';

    /** @return iterable<string, array{int, string}> */
    public static function amounts(): iterable
    {
        yield 'běžná' => [121000, '1210.00'];
        yield 'nula' => [0, '0.00'];
        yield 'haléř' => [1, '0.01'];
        yield 'záporný haléř' => [-1, '-0.01'];
        yield 'záporná oprava' => [-21000, '-210.00'];
        yield 'desetník' => [20, '0.20'];
        yield 'horní mez' => [9_999_999_999, '99999999.99'];
        yield 'dolní mez' => [-9_999_999_999, '-99999999.99'];
    }

    #[DataProvider('amounts')]
    public function testAmountsHaveExactlyTwoDecimalsWithoutFloats(int $cents, string $expected): void
    {
        self::assertSame($expected, EetMessageBuilder::formatAmount($cents));
    }

    public function testAmountOverLimitIsRejected(): void
    {
        $this->expectException(EetValidationException::class);
        EetMessageBuilder::formatAmount(10_000_000_000);
    }

    public function testTimesUseCzechOffsetForWinterAndSummer(): void
    {
        self::assertSame(
            '2027-01-09T04:25:28+01:00',
            EetMessageBuilder::formatDateTime(new \DateTimeImmutable('2027-01-09 03:25:28.987654', new \DateTimeZone('UTC'))),
        );
        self::assertSame(
            '2027-06-09T05:25:28+02:00',
            EetMessageBuilder::formatDateTime(new \DateTimeImmutable('2027-06-09T03:25:28Z')),
        );
    }

    public function testOptionalAttributesAreOmittedAndVerificationOnlyWhenRequested(): void
    {
        $data = new EetSaleData('CZ00000019', 11, 'MYUCTO-DEV-01', 'A/1', new \DateTimeImmutable('2026-10-24T10:00:00Z'), 121000);
        $document = (new EetMessageBuilder())->build($data, new EetMessageHeader(self::UUID, new \DateTimeImmutable('2026-10-24T10:00:05Z'), true));
        $xml = (string) $document->saveXML($document->documentElement);

        self::assertStringContainsString('prvni_zaslani="true"', $xml);
        self::assertStringContainsString('celk_trzba="1210.00"', $xml);
        self::assertStringContainsString('dat_trzby="2026-10-24T12:00:00+02:00"', $xml);
        foreach (['overeni', 'eic_poverujiciho', 'povereni_vice_popl', 'urceno_cerp_zuct', 'cerp_zuct'] as $attribute) {
            self::assertStringNotContainsString($attribute . '=', $xml);
        }
        (new EetXmlValidator())->validate($document);

        $verification = (new EetMessageBuilder())->build($data, new EetMessageHeader(self::UUID, new \DateTimeImmutable(), false, true));
        $xml = (string) $verification->saveXML($verification->documentElement);
        self::assertStringContainsString('overeni="true"', $xml);
        self::assertStringContainsString('prvni_zaslani="false"', $xml);
    }

    public function testAllOptionalItemsAreValidAgainstSchema(): void
    {
        $data = new EetSaleData('CZ8551015704', 181, '00/2535/CN58', '0/2482/IE25', new \DateTimeImmutable(), 8798800, 34300, 23700, 'CZ00000019', true);
        $document = (new EetMessageBuilder())->build($data, EetMessageHeader::create(true));
        (new EetXmlValidator())->validate($document);
        $xml = (string) $document->saveXML($document->documentElement);
        self::assertStringContainsString('urceno_cerp_zuct="343.00"', $xml);
        self::assertStringContainsString('cerp_zuct="237.00"', $xml);
        self::assertStringContainsString('povereni_vice_popl="true"', $xml);
    }

    /**
     * Vzory datové oblasti <Data> ze situací 4 a 5 dokumentu FS „Popis položek datové
     * zprávy s příklady jejich vyplnění“ v1.0 (str. 21 až 23). Výčet atributů v dokumentu
     * je kompletní, proto se porovnává celá množina atributů.
     *
     * @return iterable<string, array{EetSaleData, array<string,string>}>
     */
    public static function delegationExamples(): iterable
    {
        yield 'situace 4: čerpací stanice v pověření' => [
            new EetSaleData('CZ683555118', 31, 'Sto3', '1234-812/9', new \DateTimeImmutable('2027-03-22T15:39:01+01:00'), 86000, null, null, 'CZ00000019', null),
            ['eic_popl' => 'CZ683555118', 'eic_poverujiciho' => 'CZ00000019', 'id_jednotky' => '31', 'id_pokl' => 'Sto3',
                'porad_cis' => '1234-812/9', 'dat_trzby' => '2027-03-22T15:39:01+01:00', 'celk_trzba' => '860.00'],
        ];
        yield 'situace 4 s příznakem false: příznak se neuvádí' => [
            new EetSaleData('CZ683555118', 31, 'Sto3', '1234-812/9', new \DateTimeImmutable('2027-03-22T15:39:01+01:00'), 86000, null, null, 'CZ00000019', false),
            ['eic_popl' => 'CZ683555118', 'eic_poverujiciho' => 'CZ00000019', 'id_jednotky' => '31', 'id_pokl' => 'Sto3',
                'porad_cis' => '1234-812/9', 'dat_trzby' => '2027-03-22T15:39:01+01:00', 'celk_trzba' => '860.00'],
        ];
        yield 'situace 5, příklad 1: společník bez EIČ pověřujícího' => [
            new EetSaleData('CZ8551015704', 35871, '3', '12252/22', new \DateTimeImmutable('2027-03-22T15:39:01+01:00'), 2500000, null, null, null, true),
            ['eic_popl' => 'CZ8551015704', 'povereni_vice_popl' => 'true', 'id_jednotky' => '35871', 'id_pokl' => '3',
                'porad_cis' => '12252/22', 'dat_trzby' => '2027-03-22T15:39:01+01:00', 'celk_trzba' => '25000.00'],
        ];
        yield 'situace 5, příklad 2: třetí osoba s EIČ pověřujícího' => [
            new EetSaleData('CZ00000019', 555002, '5/5583', '22', new \DateTimeImmutable('2027-05-15T10:32:01+02:00'), 20000, null, null, 'CZ8551015704', true),
            ['eic_popl' => 'CZ00000019', 'eic_poverujiciho' => 'CZ8551015704', 'povereni_vice_popl' => 'true', 'id_jednotky' => '555002',
                'id_pokl' => '5/5583', 'porad_cis' => '22', 'dat_trzby' => '2027-05-15T10:32:01+02:00', 'celk_trzba' => '200.00'],
        ];
    }

    /** @param array<string,string> $expected */
    #[DataProvider('delegationExamples')]
    public function testDelegationExamplesFromMethodology(EetSaleData $data, array $expected): void
    {
        $document = (new EetMessageBuilder())->build($data, EetMessageHeader::create(true));
        (new EetXmlValidator())->validate($document);
        $element = $document->getElementsByTagNameNS(EetMessageBuilder::NAMESPACE, 'Data')->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);
        $actual = [];
        foreach ($element->attributes as $attribute) {
            $actual[$attribute->name] = $attribute->value;
        }
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    public function testGeneratedUuidIsVersionFour(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', EetMessageHeader::uuidV4());
        }
    }

    /** @return iterable<string, array{EetSaleData, string, string}> */
    public static function invalidData(): iterable
    {
        $at = new \DateTimeImmutable('2026-10-24T10:00:00Z');
        yield 'UUIDv7' => [new EetSaleData('CZ00000019', 11, 'P1', 'A1', $at, 100), '01923b9e-7c4a-7d2e-8f3a-1b2c3d4e5f60', 'uuid_zpravy'];
        yield 'EIČ bez CZ' => [new EetSaleData('00000019', 11, 'P1', 'A1', $at, 100), self::UUID, 'eic_popl'];
        yield 'dlouhé id_pokl' => [new EetSaleData('CZ00000019', 11, str_repeat('A', 21), 'A1', $at, 100), self::UUID, 'id_pokl'];
        yield 'diakritika v id_pokl' => [new EetSaleData('CZ00000019', 11, 'Pokladna-č1', 'A1', $at, 100), self::UUID, 'id_pokl'];
        yield 'dlouhé porad_cis' => [new EetSaleData('CZ00000019', 11, 'P1', str_repeat('1', 26), $at, 100), self::UUID, 'porad_cis'];
        yield 'nepovolený znak v porad_cis' => [new EetSaleData('CZ00000019', 11, 'P1', 'A+1', $at, 100), self::UUID, 'porad_cis'];
        yield 'jednotka nula' => [new EetSaleData('CZ00000019', 0, 'P1', 'A1', $at, 100), self::UUID, 'id_jednotky'];
        yield 'pověřující shodný s poplatníkem' => [new EetSaleData('CZ00000019', 11, 'P1', 'A1', $at, 100, null, null, 'CZ00000019'), self::UUID, 'eic_poverujiciho'];
        yield 'shodné EIČ i u více poplatníků' => [new EetSaleData('CZ00000019', 11, 'P1', 'A1', $at, 100, null, null, 'CZ00000019', true), self::UUID, 'eic_poverujiciho'];
        yield 'EIČ pověřujícího s mezerou' => [new EetSaleData('CZ00000019', 11, 'P1', 'A1', $at, 100, null, null, 'CZ 8551015704'), self::UUID, 'eic_poverujiciho'];
        yield 'částka nad limit' => [new EetSaleData('CZ00000019', 11, 'P1', 'A1', $at, 10_000_000_000), self::UUID, 'celk_trzba'];
    }

    #[DataProvider('invalidData')]
    public function testInvalidDataIsRejectedBeforeSigning(EetSaleData $data, string $uuid, string $field): void
    {
        try {
            (new EetMessageBuilder())->build($data, new EetMessageHeader($uuid, new \DateTimeImmutable(), true));
            self::fail('Neplatná data prošla.');
        } catch (EetValidationException $exception) {
            self::assertSame($field, $exception->field);
        }
    }
}
