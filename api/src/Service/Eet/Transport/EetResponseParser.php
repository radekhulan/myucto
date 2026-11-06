<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Bezpečný rozbor odpovědi: bez DTD a entit ({@see EetXml::loadSafe()}), jen
 * obálka SOAP 1.1, `Odpoved` musí být jediným elementem v jediném `Body` a musí
 * projít připnutým XSD. SOAP Fault se vrátí jako fault, ne jako výjimka.
 */
final class EetResponseParser
{
    public function __construct(
        private readonly EetXmlValidator $validator = new EetXmlValidator(),
    ) {}

    /**
     * @throws EetSignatureException když odpověď není bezpečné a srozumitelné SOAP XML
     */
    public function parse(string $xml): EetParsedResponse
    {
        $document = EetXml::loadSafe($xml);
        $envelope = $document->documentElement;
        if (!$envelope instanceof \DOMElement || $envelope->namespaceURI !== EetXml::NS_SOAP || $envelope->localName !== 'Envelope') {
            throw new EetSignatureException('envelope_missing', 'Odpověď nemá obálku SOAP 1.1.');
        }
        $bodies = EetXml::childElements($envelope, EetXml::NS_SOAP, 'Body');
        if (count($bodies) !== 1 || $document->getElementsByTagNameNS(EetXml::NS_SOAP, 'Body')->length !== 1) {
            throw new EetSignatureException('body_ambiguous', 'Odpověď nemá právě jedno tělo SOAP.');
        }
        $body = $bodies[0];
        $elements = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                throw new EetSignatureException('response_invalid', 'Tělo odpovědi obsahuje neočekávaný text.');
            }
        }
        if (count($elements) !== 1) {
            throw new EetSignatureException('response_invalid', 'Tělo odpovědi nemá právě jeden element.');
        }
        $root = $elements[0];
        if ($root->namespaceURI === EetXml::NS_SOAP && $root->localName === 'Fault') {
            return new EetParsedResponse(
                document: $document,
                body: $body,
                faultCode: self::childText($root, 'faultcode') ?? '',
                faultString: self::childText($root, 'faultstring') ?? '',
            );
        }
        if ($root->namespaceURI !== EetMessageBuilder::NAMESPACE || $root->localName !== 'Odpoved') {
            throw new EetSignatureException('response_invalid', 'Tělo odpovědi neobsahuje element Odpoved.');
        }
        $this->validateSchema($root);

        $hlavicka = self::only($root, 'Hlavicka');
        $potvrzeni = EetXml::childElements($root, EetMessageBuilder::NAMESPACE, 'Potvrzeni')[0] ?? null;
        $chyba = EetXml::childElements($root, EetMessageBuilder::NAMESPACE, 'Chyba')[0] ?? null;
        $warnings = [];
        foreach (EetXml::childElements($root, EetMessageBuilder::NAMESPACE, 'Varovani') as $warning) {
            $warnings[] = [
                'code' => (int) $warning->getAttribute('kod_varov'),
                'message' => self::normalizeText($warning->textContent),
            ];
        }

        return new EetParsedResponse(
            document: $document,
            body: $body,
            uuid: self::attribute($hlavicka, 'uuid_zpravy'),
            receivedAt: self::attribute($hlavicka, 'dat_prij'),
            rejectedAt: self::attribute($hlavicka, 'dat_odmit'),
            pok: $potvrzeni !== null ? self::attribute($potvrzeni, 'pok') : null,
            testFlag: self::boolean(($potvrzeni ?? $chyba)?->getAttribute('test') ?? ''),
            errorCode: $chyba !== null ? (int) $chyba->getAttribute('kod') : null,
            errorText: $chyba !== null ? self::normalizeText($chyba->textContent) : null,
            warnings: $warnings,
        );
    }

    private function validateSchema(\DOMElement $odpoved): void
    {
        $standalone = new \DOMDocument('1.0', 'UTF-8');
        $standalone->appendChild($standalone->importNode($odpoved, true));
        try {
            $this->validator->validate($standalone);
        } catch (EetValidationException $exception) {
            throw new EetSignatureException('response_schema_invalid', 'Odpověď nevyhověla schématu EET: ' . $exception->getMessage());
        }
    }

    private static function only(\DOMElement $parent, string $name): \DOMElement
    {
        $elements = EetXml::childElements($parent, EetMessageBuilder::NAMESPACE, $name);
        if (count($elements) !== 1) {
            throw new EetSignatureException('response_invalid', sprintf('Odpověď nemá právě jeden element %s.', $name));
        }

        return $elements[0];
    }

    private static function attribute(\DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? trim($element->getAttribute($name)) : null;
    }

    private static function boolean(string $value): ?bool
    {
        return match (trim($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    private static function childText(\DOMElement $fault, string $name): ?string
    {
        foreach ($fault->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                return self::normalizeText($child->textContent);
            }
        }

        return null;
    }

    private static function normalizeText(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 500);
    }
}
