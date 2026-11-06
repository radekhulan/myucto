<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Xml;

use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocxExtractor;
use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Xml\SafeXmlLoader;
use MyInvoice\Service\Xml\UnsafeXmlException;
use PHPUnit\Framework\TestCase;

/**
 * Sdílené bezpečné načtení XML: plochý dokument s milióny uzlů (DOM by stál
 * desítky násobků velikosti souboru mimo memory_limit) a DOCTYPE v UTF-16 se
 * odmítnou dřív, než se postaví strom. Platné doklady (i velké a s BOM) projdou.
 */
final class SafeXmlLoaderTest extends TestCase
{
    private const ISDOC_NS = 'http://isdoc.cz/namespace/2013';

    /** `<a/>x` = dva uzly na pět bajtů; milion opakování je ~5 MB a 2 mil. uzlů. */
    private static function flatBomb(string $root = 'r', string $ns = ''): string
    {
        $xmlns = $ns !== '' ? ' xmlns="' . $ns . '"' : '';
        return '<?xml version="1.0" encoding="UTF-8"?><' . $root . $xmlns . '>'
            . str_repeat('<a/>x', 1_000_000) . '</' . $root . '>';
    }

    public function testFlatDocumentOverNodeLimitIsRejectedBeforeBuildingDom(): void
    {
        try {
            SafeXmlLoader::load(self::flatBomb(), 20 * 1024 * 1024);
            self::fail('Plochý dokument nad stropem uzlů musí být odmítnutý.');
        } catch (UnsafeXmlException $e) {
            self::assertSame(UnsafeXmlException::TOO_MANY_NODES, $e->reason);
        }
    }

    public function testSizeLimitIsEnforced(): void
    {
        $this->expectException(UnsafeXmlException::class);
        SafeXmlLoader::load('<r>' . str_repeat('x', 2048) . '</r>', 1024);
    }

    public function testUtf16DoctypeIsRejected(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY e "x">]><r>&e;</r>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
        self::assertDoesNotMatchRegularExpression('/<!DOCTYPE/i', $utf16, 'Předpoklad: regex nad bajty DOCTYPE nevidí.');

        try {
            SafeXmlLoader::load($utf16, 1024 * 1024);
            self::fail('DOCTYPE v UTF-16 musí být odmítnutý.');
        } catch (UnsafeXmlException $e) {
            self::assertSame(UnsafeXmlException::DOCTYPE, $e->reason);
        }
    }

    public function testUtf8BomAndValidUtf16DocumentLoad(): void
    {
        $dom = SafeXmlLoader::load("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?><r><n>Příliš žluťoučký</n></r>", 1024);
        self::assertSame('Příliš žluťoučký', $dom->documentElement?->textContent);

        $xml = '<?xml version="1.0" encoding="UTF-16"?><r><n>kůň</n></r>';
        $dom = SafeXmlLoader::load("\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'), 1024);
        self::assertSame('kůň', $dom->documentElement?->textContent);
    }

    public function testInvalidXmlReportsInvalid(): void
    {
        try {
            SafeXmlLoader::load('<r><unclosed></r>', 1024);
            self::fail('Neplatné XML musí skončit výjimkou.');
        } catch (UnsafeXmlException $e) {
            self::assertSame(UnsafeXmlException::INVALID, $e->reason);
        }
    }

    /** Legitimní ~20 MiB ISDOC (desítky tisíc řádků) se pořád načte. */
    public function testLargeLegitimateIsdocStillLoads(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Shoptet/isdoc-faktura.isdoc');
        self::assertSame(1, preg_match('~<InvoiceLine>.*?</InvoiceLine>~s', $fixture, $m));
        $pos = strpos($fixture, $m[0]) + strlen($m[0]);
        $copies = intdiv(20 * 1024 * 1024 - strlen($fixture), strlen($m[0]) + 5);
        $xml = substr($fixture, 0, $pos) . str_repeat("\n    " . $m[0], $copies) . substr($fixture, $pos);

        $dom = SafeXmlLoader::load($xml, 32 * 1024 * 1024);
        self::assertSame(
            $copies + substr_count($fixture, '<InvoiceLine>'),
            $dom->getElementsByTagNameNS(self::ISDOC_NS, 'InvoiceLine')->length,
        );
    }

    public function testIsdocParserRejectsFlatBombAndUtf16Doctype(): void
    {
        try {
            (new IsdocParser())->parse(self::flatBomb('Invoice', self::ISDOC_NS));
            self::fail('Plochý ISDOC musí být odmítnutý před stavbou DOM.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('příliš rozsáhlé', $e->getMessage());
        }

        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE Invoice><Invoice xmlns="' . self::ISDOC_NS . '"/>';
        $this->expectExceptionMessage('DOCTYPE');
        (new IsdocParser())->parse("\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    }

    public function testIsdocParserAcceptsFixtureWithUtf8Bom(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Shoptet/isdoc-faktura.isdoc');
        $parsed = (new IsdocParser())->parse("\xEF\xBB\xBF" . $fixture);
        self::assertCount(1, $parsed['invoices']);
        self::assertArrayNotHasKey('__error', $parsed['invoices'][0]);
    }

    /**
     * Manifest ZIP-bomb (malý ZIP, manifest rozbalený na MB) se vůbec neparsuje;
     * hlavní ISDOC se najde podle názvu jako u balíčku bez manifestu.
     */
    public function testIsdocxWithBombManifestFallsBackWithoutParsingIt(): void
    {
        $isdoc = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Invoice xmlns="' . self::ISDOC_NS . '" version="6.0.2"><ID>PKG-1</ID></Invoice>';
        $tmp = (string) tempnam(sys_get_temp_dir(), 'isdocx-bomb-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('manifest.xml', self::flatBomb('manifest'));
        $zip->addFromString('a.isdoc', $isdoc);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        self::assertLessThan(200_000, strlen($bytes));

        $out = (new IsdocxExtractor())->unwrap($bytes);
        self::assertNotNull($out);
        self::assertSame('a.isdoc', $out['isdoc_name']);
    }

    public function testDppoParserRejectsFlatBomb(): void
    {
        $this->expectException(TaxReturnException::class);
        (new DppoEpoXmlParser())->parse(self::flatBomb('Pisemnost'));
    }
}
