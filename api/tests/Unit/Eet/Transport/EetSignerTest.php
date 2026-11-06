<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetMessageBuilder;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetSignatureException;
use MyInvoice\Service\Eet\Transport\EetSigner;
use MyInvoice\Service\Eet\Transport\EetValidationException;
use MyInvoice\Service\Eet\Transport\EetXml;
use MyInvoice\Service\Eet\Transport\EetXmlSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EetSignerTest extends TestCase
{
    private static function signed(int $cents = 121000, string $sequence = 'A/1'): string
    {
        $data = new EetSaleData('CZ00000019', 11, 'MYUCTO-DEV-01', $sequence, new \DateTimeImmutable('2026-10-24T10:00:00Z'), $cents);

        return (new EetSigner())->sign((new EetMessageBuilder())->build($data, EetMessageHeader::create(true)), EetTestPki::credential());
    }

    /** @return iterable<string, array{string}> */
    public static function officialSamples(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/Fixtures/eet/CZ*.eet.v4.req.xml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    /** Vzory FS podepsané jejich nástrojem projdou stejným ověřením jako vlastní výstup. */
    #[DataProvider('officialSamples')]
    public function testOfficialSignedSamplesVerify(string $file): void
    {
        $result = EetXmlSignature::verify(EetXml::loadSafe((string) file_get_contents($file)));
        $cn = openssl_x509_parse($result['certificatePem'])['subject']['CN'] ?? null;
        self::assertStringStartsWith(substr(basename($file), 0, 10), (string) $cn);
    }

    public function testSignedMessageMirrorsOfficialStructureAndVerifies(): void
    {
        $xml = self::signed();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $document = EetXml::loadSafe($xml);
        $result = EetXmlSignature::verify($document);

        $xpath = EetXml::xpath($document);
        self::assertSame(1, $xpath->query('//wsse:BinarySecurityToken')->length);
        self::assertSame(1, $xpath->query('//ds:Reference')->length);
        self::assertSame('soapenv v4', $xpath->query('//ds:CanonicalizationMethod/ec:InclusiveNamespaces')->item(0)->getAttribute('PrefixList'));
        self::assertSame('v4', $xpath->query('//ds:Transform/ec:InclusiveNamespaces')->item(0)->getAttribute('PrefixList'));
        self::assertSame(0, $xpath->query('//*[local-name()="Timestamp"]')->length);
        self::assertSame('Body', $result['body']->localName);
        self::assertStringNotContainsString('PRIVATE', $xml);
    }

    public function testBodyChangedAfterSigningFailsDigest(): void
    {
        $tampered = str_replace('celk_trzba="1210.00"', 'celk_trzba="1211.00"', self::signed());
        $this->assertSignatureFailure($tampered, 'digest_mismatch');
    }

    public function testCorruptedSignatureValueFails(): void
    {
        $xml = self::signed();
        $tampered = preg_replace_callback('/(<ds:SignatureValue>)(.)/', static fn (array $m): string => $m[1] . ($m[2] === 'A' ? 'B' : 'A'), $xml, 1);
        $this->assertSignatureFailure((string) $tampered, 'signature_invalid');
    }

    public function testSignatureFromAnotherMessageDoesNotCoverForeignBody(): void
    {
        $first = EetXml::loadSafe(self::signed(121000, 'A/1'));
        $second = EetXml::loadSafe(self::signed(500, 'A/2'));
        $firstBody = EetXml::xpath($first)->query('/soapenv:Envelope/soapenv:Body')->item(0);
        $secondBody = EetXml::xpath($second)->query('/soapenv:Envelope/soapenv:Body')->item(0);
        $foreign = $first->importNode($secondBody, true);
        $foreign->setAttributeNS(EetXml::NS_WSU, 'wsu:Id', $firstBody->getAttributeNS(EetXml::NS_WSU, 'Id'));
        $firstBody->parentNode->replaceChild($foreign, $firstBody);

        $this->assertSignatureFailure((string) $first->saveXML(), 'digest_mismatch');
    }

    public function testWrappedOriginalBodyIsRejected(): void
    {
        $document = EetXml::loadSafe(self::signed());
        $xpath = EetXml::xpath($document);
        $body = $xpath->query('/soapenv:Envelope/soapenv:Body')->item(0);
        $wrapper = $document->createElement('Wrapper');
        $xpath->query('/soapenv:Envelope/soapenv:Header')->item(0)->appendChild($wrapper);
        $wrapper->appendChild($body->cloneNode(true));
        $fake = $document->createElementNS(EetXml::NS_SOAP, 'soapenv:Body');
        $fake->appendChild($document->createElementNS(EetMessageBuilder::NAMESPACE, 'v4:Trzba'));
        $body->parentNode->replaceChild($fake, $body);

        $this->assertSignatureFailure((string) $document->saveXML(), 'body_ambiguous');
    }

    public function testDuplicateIdIsRejected(): void
    {
        $document = EetXml::loadSafe(self::signed());
        $xpath = EetXml::xpath($document);
        $id = $xpath->query('/soapenv:Envelope/soapenv:Body')->item(0)->getAttributeNS(EetXml::NS_WSU, 'Id');
        $decoy = $document->createElementNS(EetXml::NS_WSSE, 'wsse:Decoy');
        $decoy->setAttribute('Id', $id);
        $xpath->query('//wsse:Security')->item(0)->appendChild($decoy);

        $this->assertSignatureFailure((string) $document->saveXML(), 'duplicate_id');
    }

    public function testDoctypeIsRefusedBeforeParsing(): void
    {
        $this->expectException(EetSignatureException::class);
        EetXml::loadSafe('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
    }

    public function testMessageOverSizeLimitIsRejected(): void
    {
        self::assertSame(12_000, EetSigner::MAX_MESSAGE_BYTES);
        $data = new EetSaleData('CZ00000019', 11, 'P1', 'A1', new \DateTimeImmutable(), 100);
        try {
            (new EetSigner(2_000))->sign((new EetMessageBuilder())->build($data, EetMessageHeader::create(true)), EetTestPki::credential());
            self::fail('Zpráva nad limit prošla.');
        } catch (EetValidationException $exception) {
            self::assertSame('size', $exception->field);
        }
    }

    private function assertSignatureFailure(string $xml, string $reason): void
    {
        try {
            EetXmlSignature::verify(EetXml::loadSafe($xml));
            self::fail('Pozměněná zpráva prošla ověřením podpisu.');
        } catch (EetSignatureException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
