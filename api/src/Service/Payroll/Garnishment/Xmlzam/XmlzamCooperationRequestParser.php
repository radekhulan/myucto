<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DOMDocument;
use DOMElement;
use DomainException;

final readonly class XmlzamCooperationRequestParser
{
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public function __construct(private XmlzamSchemaCatalog $schemas) {}

    public function parse(string $xml): XmlzamCooperationRequest
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new DomainException('XMLZAM nesmí obsahovat deklaraci DTD.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new DomainException('XMLZAM není platný XML dokument.');
            }
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
