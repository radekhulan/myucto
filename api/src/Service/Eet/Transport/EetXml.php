<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Jmenné prostory a bezpečné načítání XML pro zprávy EET.
 *
 * Načítání odmítne DOCTYPE i entity (žádné DTD, žádné externí entity, žádná síť)
 * a hlídá velikost — odpověď je vstup z cizího serveru.
 */
final class EetXml
{
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    public const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    public const NS_EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    public const ALG_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    public const ALG_RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    public const VALUE_TYPE_X509 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    public const ENCODING_BASE64 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    public const MAX_RESPONSE_BYTES = 65_536;

    /**
     * @throws EetSignatureException s důvodem `xml_unsafe` / `xml_invalid` / `xml_too_large`
     */
    public static function loadSafe(string $xml, int $maxBytes = self::MAX_RESPONSE_BYTES): \DOMDocument
    {
        if (strlen($xml) > $maxBytes) {
            throw new EetSignatureException('xml_too_large', 'XML je větší, než připouští limit.');
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new EetSignatureException('xml_unsafe', 'XML obsahuje DOCTYPE nebo deklaraci entit; takové XML se nezpracovává.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || !$document->documentElement instanceof \DOMElement) {
            throw new EetSignatureException('xml_invalid', 'Odpověď není platné XML.');
        }
        if ($document->doctype !== null) {
            throw new EetSignatureException('xml_unsafe', 'XML obsahuje DOCTYPE; takové XML se nezpracovává.');
        }

        return $document;
    }

    public static function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('soapenv', self::NS_SOAP);
        $xpath->registerNamespace('wsse', self::NS_WSSE);
        $xpath->registerNamespace('wsu', self::NS_WSU);
        $xpath->registerNamespace('ds', self::NS_DS);
        $xpath->registerNamespace('ec', self::NS_EXC_C14N);
        $xpath->registerNamespace('eet', EetMessageBuilder::NAMESPACE);

        return $xpath;
    }

    /**
     * Přímí potomci elementu s daným jménem.
     *
     * @return list<\DOMElement>
     */
    public static function childElements(\DOMElement $parent, string $namespace, string $localName): array
    {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $namespace && $child->localName === $localName) {
                $result[] = $child;
            }
        }

        return $result;
    }
}
