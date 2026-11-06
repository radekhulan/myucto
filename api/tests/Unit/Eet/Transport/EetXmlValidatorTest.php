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

/**
 * Připnuté XSD je druhá pojistka za builderem: atributy se tu podvrhují přímo
 * v DOM, aby se ověřilo, že schéma samo neplatné hodnoty odmítne.
 */
final class EetXmlValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function schemaViolations(): iterable
    {
        yield 'UUIDv7' => ['Hlavicka', 'uuid_zpravy', '01923b9e-7c4a-7d2e-8f3a-1b2c3d4e5f60'];
        yield 'čas bez offsetu' => ['Hlavicka', 'dat_odesl', '2026-10-24T10:00:00'];
        yield 'čas se zlomky sekund' => ['Data', 'dat_trzby', '2026-10-24T10:00:00.5+02:00'];
        yield 'dlouhé id_pokl' => ['Data', 'id_pokl', str_repeat('A', 21)];
        yield 'znaková sada id_pokl' => ['Data', 'id_pokl', 'Pokladna*1'];
        yield 'mínus nula' => ['Data', 'celk_trzba', '-0.00'];
        yield 'vedoucí nula' => ['Data', 'celk_trzba', '020.45'];
        yield 'jedno desetinné místo' => ['Data', 'celk_trzba', '10.5'];
        yield 'prázdné EIČ pověřujícího' => ['Data', 'eic_poverujiciho', ''];
    }

    #[DataProvider('schemaViolations')]
    public function testSchemaRejectsInvalidValues(string $element, string $attribute, string $value): void
    {
        $document = (new EetMessageBuilder())->build(
            new EetSaleData('CZ00000019', 11, 'P1', 'A1', new \DateTimeImmutable(), 121000),
            EetMessageHeader::create(true),
        );
        $node = $document->getElementsByTagNameNS(EetMessageBuilder::NAMESPACE, $element)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $node->setAttribute($attribute, $value);

        $this->expectException(EetValidationException::class);
        (new EetXmlValidator())->validate($document);
    }

    public function testBoundaryNegativeAmountPassesSchema(): void
    {
        $document = (new EetMessageBuilder())->build(
            new EetSaleData('CZ00000019', 11, 'P1', 'A1', new \DateTimeImmutable(), -1),
            EetMessageHeader::create(true),
        );
        (new EetXmlValidator())->validate($document);
        self::assertStringContainsString('celk_trzba="-0.01"', (string) $document->saveXML());
    }

    public function testPinnedSchemaMatchesRecordedChecksum(): void
    {
        self::assertSame(
            '83597d08520cbfab7e51145df9a765d5ca22e2f9a38644a468d695657c7a032a',
            hash_file('sha256', EetXmlValidator::defaultSchemaPath()),
        );
    }
}
