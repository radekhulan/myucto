<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use DOMElement;

/**
 * Serializace datové věty HZUPN20 v1.2.
 *
 * Dvě věci, na kterých se dá tiše rozbít podání:
 *
 * 1. **Logické hodnoty jsou `A`/`N`, ne `true`/`false`.** `hlasZamest`
 *    i `hlasOsoby` jsou `tns:simpleLType`, `zahranicni` a `opravnePodani`
 *    `tns:simpleLSType` — obojí je v `baseTypes.xsd` výčet písmen. Vložit sem
 *    `true` znamená XSD chybu, ne „skoro dobře".
 * 2. **Kořen se jmenuje `PodaniHZUPN`, formulář `FormularHZUPN`** — s velkým
 *    F, na rozdíl od zbytku datové věty, který je camelCase od malého písmene.
 *
 * `poradoveCislo` je `use="required"` a rozsah 1..1500. Aplikace posílá právě
 * jeden formulář: hlášení podle § 97 odst. 3 zák. č. 187/2006 Sb. se váže
 * k jedné ukončené neschopnosti a dávkové podání by ho svázalo s cizí.
 */
final class HzupnXmlSerializer
{
    public function serialize(HzupnXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/nem/HZUPN20';
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS($namespace, 'PodaniHZUPN');
        $root->setAttribute('version', $payload->payloadVersion);
        $document->appendChild($root);

        $vendor = $document->createElementNS($namespace, 'VENDOR');
        $vendor->setAttribute('productName', $payload->productName);
        $vendor->setAttribute('productVersion', $payload->productVersion);
        $root->appendChild($vendor);

        $sender = $document->createElementNS($namespace, 'SENDER');
        if ($payload->notificationEmail !== null) {
            $sender->setAttribute('EmailNotifikace', $payload->notificationEmail);
        }
        $sender->setAttribute('ISDSreport', '3');
        $root->appendChild($sender);

        $form = $document->createElementNS($namespace, 'FormularHZUPN');
        $form->setAttribute('poradoveCislo', '1');
        $form->appendChild($this->dokument($document, $namespace, $payload));
        $form->appendChild($this->pojistenec($document, $namespace, $payload));
        $form->appendChild($this->zamestnani($document, $namespace, $payload));
        $confirmation = $this->potvrzeni($document, $namespace, $payload);
        if ($confirmation !== null) {
            $form->appendChild($confirmation);
        }
        $workDays = $this->praceVeDnech($document, $namespace, $payload);
        if ($workDays !== null) {
            $form->appendChild($workDays);
        }
        $root->appendChild($form);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SicknessException(
                'hzupn_xml_serialization_failed',
                'XML hlášení HZUPN nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    private function dokument(
        DOMDocument $document,
        string $namespace,
        HzupnXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'dokument');
        $this->flag($document, $namespace, $node, 'hlasZamest', $payload->employerReport);
        $this->flag($document, $namespace, $node, 'hlasOsoby', $payload->personReport);
        $this->flag($document, $namespace, $node, 'zahranicni', $payload->foreignCase);
        if ($payload->confirmationNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'cisloPotvrzeni',
                $payload->confirmationNumber,
            );
        }
        $this->text($document, $namespace, $node, 'kodOSSZ', (string) $payload->osszCode);
        if ($payload->osszName !== null) {
            $this->text($document, $namespace, $node, 'nazevOSSZ', $payload->osszName);
        }
        $this->text($document, $namespace, $node, 'datumVystaveni', $payload->issuedOn);
        if ($payload->correction) {
            $this->flag($document, $namespace, $node, 'opravnePodani', true);
        }

        return $node;
    }

    private function pojistenec(
        DOMDocument $document,
        string $namespace,
        HzupnXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'pojistenec');
        $this->text($document, $namespace, $node, 'jmeno', $payload->insuredFirstName);
        $this->text($document, $namespace, $node, 'prijmeni', $payload->insuredLastName);
        if ($payload->insuredTitle !== null) {
            $this->text($document, $namespace, $node, 'titul', $payload->insuredTitle);
        }
        if ($payload->insuredBirthNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'rodCislo',
                $payload->insuredBirthNumber,
            );
        }
        if ($payload->insuredBirthDate !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'datumNar',
                $payload->insuredBirthDate,
            );
        }

        return $node;
    }

    private function zamestnani(
        DOMDocument $document,
        string $namespace,
        HzupnXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zamestnani');
        $this->text(
            $document,
            $namespace,
            $node,
            'nazevZamestnavatel',
            $payload->employerName,
        );
        if ($payload->employerIdentificationNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'ICZamestnavatel',
                $payload->employerIdentificationNumber,
            );
        }
        $this->text(
            $document,
            $namespace,
            $node,
            'variabilniSymbol',
            $payload->employerVariableSymbol,
        );

        return $node;
    }

    private function potvrzeni(
        DOMDocument $document,
        string $namespace,
        HzupnXmlPayload $payload,
    ): ?DOMElement {
        if ($payload->returnedToWork === null
            && $payload->returnReason === null
            && $payload->returnedOn === null
            && $payload->hoursWorkedLastDay === null
            && $payload->shiftHoursLastDay === null
        ) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'potvrzeniZamestnavatele');
        if ($payload->returnedToWork !== null) {
            $this->flag(
                $document,
                $namespace,
                $node,
                'navratDoPrace',
                $payload->returnedToWork,
            );
        }
        if ($payload->returnReason !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'duvodNavratDoPrace',
                $payload->returnReason,
            );
        }
        if ($payload->returnedOn !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'datumNavratDoPrace',
                $payload->returnedOn,
            );
        }
        if ($payload->hoursWorkedLastDay !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pocetOdpracHodinPoslDenPD',
                $payload->hoursWorkedLastDay,
            );
        }
        if ($payload->shiftHoursLastDay !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pracovniDobaPoslDenPD',
                $payload->shiftHoursLastDay,
            );
        }

        return $node;
    }

    private function praceVeDnech(
        DOMDocument $document,
        string $namespace,
        HzupnXmlPayload $payload,
    ): ?DOMElement {
        if ($payload->workIntervals === []) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'praceVeDnech');
        foreach ($payload->workIntervals as $interval) {
            $item = $document->createElementNS($namespace, 'interval');
            $this->text($document, $namespace, $item, 'pracovalOd', $interval['from']);
            $this->text($document, $namespace, $item, 'pracovalDo', $interval['to']);
            $node->appendChild($item);
        }

        return $node;
    }

    private function flag(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        bool $value,
    ): void {
        $this->text($document, $namespace, $parent, $name, $value ? 'A' : 'N');
    }

    private function text(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        string $value,
    ): void {
        $element = $document->createElementNS($namespace, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
