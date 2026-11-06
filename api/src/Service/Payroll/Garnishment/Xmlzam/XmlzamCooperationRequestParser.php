<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DOMElement;
use DomainException;
use MyInvoice\Service\Xml\SafeXmlLoader;
use MyInvoice\Service\Xml\UnsafeXmlException;

final readonly class XmlzamCooperationRequestParser
{
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    /** Požadavek na součinnost je krátký dokument; stropy s velkou rezervou. */
    private const MAX_XML_BYTES = 5 * 1024 * 1024;
    private const MAX_XML_NODES = 100_000;

    public function __construct(private XmlzamSchemaCatalog $schemas) {}

    public function parse(string $xml): XmlzamCooperationRequest
    {
        // Rychlé odmítnutí čitelné deklarace (i nevalidní varianty `<!doctype`).
        // Regex nad surovými bajty ale DTD v UTF-16 nevidí; rozhoduje proto
        // sdílený loader podle toho, co skutečně čte parser, včetně počtu uzlů.
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new DomainException('XMLZAM nesmí obsahovat deklaraci DTD.');
        }
        try {
            $document = SafeXmlLoader::load($xml, self::MAX_XML_BYTES, 0, self::MAX_XML_NODES);
        } catch (UnsafeXmlException $e) {
            throw new DomainException(match ($e->reason) {
                UnsafeXmlException::DOCTYPE => 'XMLZAM nesmí obsahovat deklaraci DTD.',
                UnsafeXmlException::INVALID => 'XMLZAM není platný XML dokument.',
                default                     => 'XMLZAM je příliš rozsáhlý na zpracování.',
            });
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $root = $document->documentElement;
            if (!$root instanceof DOMElement || $root->localName !== 'dokument') {
                throw new DomainException('XMLZAM nemá kořenový element dokument.');
            }
            if ($root->getAttributeNS(self::XSI_NAMESPACE, 'type') !== 'soucinnost') {
                throw new DomainException('XMLZAM dokument není požadavek na součinnost.');
            }
            if (!$document->schemaValidate($this->schemas->schemaPath())) {
                throw new DomainException('Požadavek na součinnost neodpovídá oficiálnímu schématu XMLZAM.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $scopes = preg_split('/\s+/', $this->childText($root, 'druh_soucinnosti'));
        $debtor = $this->child($root, 'povinny');
        $executor = $this->child($root, 'exekutor');

        return new XmlzamCooperationRequest(
            identifier: $this->childText($root, 'identifikator'),
            caseReference: $this->childText($root, 'znacka_rizeni'),
            issuedOn: $this->childText($root, 'datum'),
            requestedScopes: array_values(array_filter($scopes ?: [], static fn (string $scope): bool => $scope !== '')),
            debtorGivenName: $this->childText($debtor, 'jmeno'),
            debtorFamilyName: $this->childText($debtor, 'prijmeni'),
            debtorBirthDate: $this->childText($debtor, 'narozen'),
            debtorBirthNumber: $this->childText($debtor, 'rc'),
            executorDataBoxId: $this->childText($executor, 'idds'),
        );
    }

    private function child(DOMElement $parent, string $name): DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        throw new DomainException("XMLZAM postrádá povinný element {$name}.");
    }

    private function childText(DOMElement $parent, string $name): string
    {
        return trim($this->child($parent, $name)->textContent);
    }
}
