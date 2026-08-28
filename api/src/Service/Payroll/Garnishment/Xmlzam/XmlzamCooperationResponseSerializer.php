<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class XmlzamCooperationResponseSerializer
{
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public function serialize(XmlzamCooperationResponse $response): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElement('dokument');
        $root->setAttributeNS(self::XSI_NAMESPACE, 'xs:type', 'soucinnost_odpoved');
        $document->appendChild($root);

        $this->text($document, $root, 'identifikator', $response->identifier);
        $this->text($document, $root, 'reakce_na', $response->reactionTo);
        $this->text($document, $root, 'datum', $response->issuedOn);
        $this->text($document, $root, 'druh_dokumentu', 'soucinnost_odpoved');
        if ($response->note !== null) {
            $this->text($document, $root, 'poznamka', $response->note);
        }
        $this->contacts($document, $root, 'kontakt_povinny', $response->debtorContact, [
            'phone' => 'telefon',
            'email' => 'email',
            'address' => 'adresa',
            'wage_account' => 'mzda_ucet',
        ]);
        $this->contacts($document, $root, 'kontakt_zamestnavatel', $response->employerContact, [
            'phone' => 'telefon',
            'email' => 'email',
        ]);

        if ($response->priority !== null) {
            $priority = $this->text($document, $root, 'poradi_exekucniho_prikazu', (string) $response->priority);
            $priority->setAttribute('sdilene_poradi', $response->sharedPriority === true ? 'true' : 'false');
        }

        if ($response->employmentActive !== null
            || $response->wages !== null
            || $response->enforcements !== null
        ) {
            $employment = $document->createElement('pracovni_pomer');
            $root->appendChild($employment);
            if ($response->employmentActive !== null) {
                $this->text($document, $employment, 'aktivni', $response->employmentActive ? 'true' : 'false');
                $this->text($document, $employment, 'zamestnan_od', $response->employedFrom ?? '');
                $this->text($document, $employment, 'zamestnan_do', $response->employedTo ?? '');
            }
            if ($response->wages !== null) {
                $wages = $document->createElement('mzda_prehled');
                $employment->appendChild($wages);
                foreach ($response->wages as $row) {
                    $wage = $this->text($document, $wages, 'mzda', self::money($row['gross_minor']));
                    $wage->setAttribute('obdobi', $row['period']);
                    $wage->setAttribute('srazeno_celkem', self::money($row['withheld_minor']));
                    $wage->setAttribute('vyzivovane_osoby', (string) $row['dependants']);
                }
            }
            if ($response->enforcements !== null) {
                $enforcements = $document->createElement('exekuce_prehled');
                $employment->appendChild($enforcements);
                foreach ($response->enforcements as $row) {
                    $enforcement = $this->text($document, $enforcements, 'exekuce', self::money($row['outstanding_minor']));
                    $enforcement->setAttribute('poradi', (string) $row['priority']);
                    $enforcement->setAttribute('subjekt', $row['subject']);
                    $enforcement->setAttribute('senat', $row['chamber']);
                    $enforcement->setAttribute('spisova_znacka', $row['case_reference']);
                    $enforcement->setAttribute('druh_pohledavky', $row['claim_kind']);
                    $enforcement->setAttribute('datum_doruceni', $row['delivered_on']);
                    $enforcement->setAttribute('datum_poradi', $row['priority_on']);
                }
            }
        }

        if ($response->attachments !== []) {
            $attachments = $document->createElement('prilohy');
            $root->appendChild($attachments);
            foreach ($response->attachments as $row) {
                $attachment = $this->text($document, $attachments, 'priloha', $row['name']);
                $attachment->setAttribute('druh', $row['kind']);
            }
        }

        $xml = $document->saveXML();
        if (!is_string($xml)) {
            throw new RuntimeException('XMLZAM odpověď nelze serializovat.');
        }

        return $xml;
    }

    /**
     * @param array<string,list<string>>|null $contacts
     * @param array<string,string> $elementNames
     */
    private function contacts(
        DOMDocument $document,
        DOMElement $root,
        string $containerName,
        ?array $contacts,
        array $elementNames,
    ): void {
        if ($contacts === null) {
            return;
        }
        $container = $document->createElement($containerName);
        $root->appendChild($container);
        foreach ($elementNames as $key => $elementName) {
            foreach ($contacts[$key] ?? [] as $value) {
                $this->text($document, $container, $elementName, $value);
            }
        }
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private static function money(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
