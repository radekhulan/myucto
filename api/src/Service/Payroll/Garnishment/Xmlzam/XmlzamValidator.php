<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DOMDocument;
use DomainException;

final readonly class XmlzamValidator
{
    public function __construct(private XmlzamSchemaCatalog $schemas) {}

    public function validateResponse(XmlzamCooperationResponse $response, string $xml): void
    {
        $canonical = (new XmlzamCooperationResponseSerializer())->serialize($response);
        if (!hash_equals(hash('sha256', $canonical), hash('sha256', $xml))) {
            throw new DomainException('XMLZAM odpověď neodpovídá schválenému datovému modelu.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
                || !$document->schemaValidate($this->schemas->schemaPath())
            ) {
                throw new DomainException('Odpověď na součinnost neodpovídá oficiálnímu schématu XMLZAM.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
