<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use DOMDocument;
use DOMElement;

final class RegzelXmlGenerator
{
    private const XML_NAMESPACE =
        'http://schemas.cssz.cz/REGZELDOPL/2025';

    public function generate(RegzelPayloadSnapshot $snapshot): string
    {
        if ($snapshot->interaction !== 'supplemental_information') {
            throw new RegzelValidationException(
                'regzel_schema_unavailable',
                'Generátor nepodporuje REGZEL interakci bez připnutého XSD.',
            );
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(
            self::XML_NAMESPACE,
            'REGZELDOPL',
        );
        $root->setAttribute('version', RegzelPayloadSnapshot::XSD_VERSION);
        $root->setAttribute('partialAccept', 'N');
        $document->appendChild($root);

        $vendor = $document->createElementNS(
            self::XML_NAMESPACE,
            'VENDOR',
        );
        $vendor->setAttribute('productName', 'MyÚčto.cz');
        $vendor->setAttribute(
            'productVersion',
            $snapshot->mappingVersion,
        );
        $root->appendChild($vendor);

        $form = $this->element($document, 'formular');
        $header = $this->element($document, 'hlavicka');
        $this->text(
            $document,
            $header,
            'kodPracovisteCSSZ',
            $snapshot->csszWorkplaceCode,
        );
        $this->text(
            $document,
            $header,
            'kodFU',
            $snapshot->taxOfficeCode,
        );
        if ($snapshot->taxOfficeWorkplaceCode !== null) {
            $this->text(
                $document,
                $header,
                'kodPracovisteFU',
                $snapshot->taxOfficeWorkplaceCode,
            );
        }
        $form->appendChild($header);

        $employer = $this->element($document, 'zamestnavatel');
        $this->text(
            $document,
            $employer,
            'vs',
            $snapshot->socialSecurityVariableSymbol,
        );
        if ($snapshot->payerReferenceNumber !== null) {
            $this->text(
                $document,
                $employer,
                'vcp',
                $snapshot->payerReferenceNumber,
            );
        }
        if ($snapshot->notificationDataBoxId !== null) {
            $this->text(
                $document,
                $employer,
                'datovaSchranka',
                $snapshot->notificationDataBoxId,
            );
        }
        $information = $this->element($document, 'doplnInformace');
        $this->text(
            $document,
            $information,
            'socialniPodnik',
            $snapshot->socialEnterprise ? 'true' : 'false',
        );
        $this->text(
            $document,
            $information,
            'agenturaPrace',
            $snapshot->employmentAgency ? 'true' : 'false',
        );
        $this->text(
            $document,
            $information,
            'chranenyTrh',
            $snapshot->protectedLaborMarket ? 'true' : 'false',
        );
        $employer->appendChild($information);
        $form->appendChild($employer);
        $root->appendChild($form);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('REGZEL XML se nepodařilo serializovat.');
        }

        return rtrim($xml, "\r\n");
    }

    private function element(DOMDocument $document, string $name): DOMElement
    {
        return $document->createElementNS(self::XML_NAMESPACE, $name);
    }

    private function text(
        DOMDocument $document,
        DOMElement $parent,
        string $name,
        string $value,
    ): void {
        $element = $this->element($document, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
