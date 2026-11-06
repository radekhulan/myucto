<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Obalí `Trzba` do SOAP 1.1 obálky a podepíše ji podle kap. 5.2 popisu rozhraní:
 * WS-Security s `BinarySecurityToken` (X509v3), jediná reference na `soapenv:Body`
 * přes `wsu:Id`, Exclusive C14N, otisk SHA-256 a podpis RSA-SHA256. Struktura
 * (prefixy, `InclusiveNamespaces`) kopíruje oficiální podepsané vzory FS.
 *
 * Žádné další hlavičky (Timestamp, WS-Addressing) — správce daně je může
 * považovat za útok. Celá zpráva nesmí přesáhnout 12 kB.
 */
final class EetSigner
{
    /** Limit 12 kB z kap. 2.2.3 počítaný konzervativně jako 12 000 bajtů. */
    public const MAX_MESSAGE_BYTES = 12_000;

    public function __construct(
        private readonly int $maxMessageBytes = self::MAX_MESSAGE_BYTES,
    ) {}

    public function sign(\DOMDocument $trzba, EetSigningCredential $credential): string
    {
        $sale = $trzba->documentElement;
        if (!$sale instanceof \DOMElement || $sale->namespaceURI !== EetMessageBuilder::NAMESPACE || $sale->localName !== 'Trzba') {
            throw new \InvalidArgumentException('Podepsat lze jen element Trzba.');
        }
        $suffix = strtoupper(bin2hex(random_bytes(12)));
        $bodyId = 'id-' . $suffix;
        $tokenId = 'X509-' . $suffix;

        $document = new \DOMDocument('1.0', 'UTF-8');
        $envelope = $document->createElementNS(EetXml::NS_SOAP, 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . EetMessageBuilder::PREFIX, EetMessageBuilder::NAMESPACE);
        $document->appendChild($envelope);

        $header = $document->createElementNS(EetXml::NS_SOAP, 'soapenv:Header');
        $envelope->appendChild($header);
        $security = $document->createElementNS(EetXml::NS_WSSE, 'wsse:Security');
        $security->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wsu', EetXml::NS_WSU);
        $header->appendChild($security);

        $token = $document->createElementNS(EetXml::NS_WSSE, 'wsse:BinarySecurityToken', $credential->certificateBase64());
        $token->setAttribute('EncodingType', EetXml::ENCODING_BASE64);
        $token->setAttribute('ValueType', EetXml::VALUE_TYPE_X509);
        $token->setAttributeNS(EetXml::NS_WSU, 'wsu:Id', $tokenId);
        $security->appendChild($token);

        $signature = $document->createElementNS(EetXml::NS_DS, 'ds:Signature');
        $signature->setAttribute('Id', 'SIG-' . $suffix);
        $security->appendChild($signature);
        $signedInfo = $document->createElementNS(EetXml::NS_DS, 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        $signedInfo->appendChild($this->c14nMethod($document, 'ds:CanonicalizationMethod', 'soapenv ' . EetMessageBuilder::PREFIX));
        $signatureMethod = $document->createElementNS(EetXml::NS_DS, 'ds:SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', EetXml::ALG_RSA_SHA256);
        $signedInfo->appendChild($signatureMethod);
        $reference = $document->createElementNS(EetXml::NS_DS, 'ds:Reference');
        $reference->setAttribute('URI', '#' . $bodyId);
        $signedInfo->appendChild($reference);
        $transforms = $document->createElementNS(EetXml::NS_DS, 'ds:Transforms');
        $reference->appendChild($transforms);
        $transforms->appendChild($this->c14nMethod($document, 'ds:Transform', EetMessageBuilder::PREFIX));
        $digestMethod = $document->createElementNS(EetXml::NS_DS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', EetXml::ALG_SHA256);
        $reference->appendChild($digestMethod);
        $reference->appendChild($document->createElementNS(EetXml::NS_DS, 'ds:DigestValue'));
        $signature->appendChild($document->createElementNS(EetXml::NS_DS, 'ds:SignatureValue'));
        $keyInfo = $document->createElementNS(EetXml::NS_DS, 'ds:KeyInfo');
        $keyInfo->setAttribute('Id', 'KI-' . $suffix);
        $signature->appendChild($keyInfo);
        $tokenReference = $document->createElementNS(EetXml::NS_WSSE, 'wsse:SecurityTokenReference');
        $tokenReference->setAttributeNS(EetXml::NS_WSU, 'wsu:Id', 'STR-' . $suffix);
        $keyInfo->appendChild($tokenReference);
        $tokenLink = $document->createElementNS(EetXml::NS_WSSE, 'wsse:Reference');
        $tokenLink->setAttribute('URI', '#' . $tokenId);
        $tokenLink->setAttribute('ValueType', EetXml::VALUE_TYPE_X509);
        $tokenReference->appendChild($tokenLink);

        $body = $document->createElementNS(EetXml::NS_SOAP, 'soapenv:Body');
        $body->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wsu', EetXml::NS_WSU);
        $body->setAttributeNS(EetXml::NS_WSU, 'wsu:Id', $bodyId);
        $envelope->appendChild($body);
        $body->appendChild($document->importNode($sale, true));

        // Kanonizuje se až znovu načtený dokument: u uzlů sestavených přes DOM API
        // nemusí libxml deklarace jmenných prostorů kanonizovat stejně jako příjemce,
        // který zprávu parsuje z bajtů.
        $parsed = EetXml::loadSafe((string) $document->saveXML(), PHP_INT_MAX);
        $xpath = EetXml::xpath($parsed);
        $parsedBody = $xpath->query('/soapenv:Envelope/soapenv:Body')->item(0);
        $parsedSignedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $digestValue = $xpath->query('//ds:SignedInfo/ds:Reference/ds:DigestValue')->item(0);
        $signatureValue = $xpath->query('//ds:Signature/ds:SignatureValue')->item(0);
        if (!$parsedBody instanceof \DOMElement || !$parsedSignedInfo instanceof \DOMElement
            || !$digestValue instanceof \DOMElement || !$signatureValue instanceof \DOMElement
        ) {
            throw new \LogicException('Sestavená obálka EET nemá očekávanou strukturu.');
        }
        $digestValue->textContent = base64_encode(hash('sha256', EetXmlSignature::canonicalize($parsedBody, [EetMessageBuilder::PREFIX]), true));
        $signatureValue->textContent = base64_encode($credential->sign(
            EetXmlSignature::canonicalize($parsedSignedInfo, ['soapenv', EetMessageBuilder::PREFIX]),
        ));

        $xml = (string) $parsed->saveXML();
        if (strlen($xml) > $this->maxMessageBytes) {
            throw new EetValidationException(
                'size',
                sprintf('Podepsaná datová zpráva má %d bajtů a překračuje limit 12 kB.', strlen($xml)),
            );
        }

        return $xml;
    }

    private function c14nMethod(\DOMDocument $document, string $name, string $prefixList): \DOMElement
    {
        $method = $document->createElementNS(EetXml::NS_DS, $name);
        $method->setAttribute('Algorithm', EetXml::NS_EXC_C14N);
        $inclusive = $document->createElementNS(EetXml::NS_EXC_C14N, 'ec:InclusiveNamespaces');
        $inclusive->setAttribute('PrefixList', $prefixList);
        $method->appendChild($inclusive);

        return $method;
    }
}
