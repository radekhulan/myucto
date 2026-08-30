<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;

/**
 * Serializace datové věty NEMPRI25.
 *
 * Pořadí prvků není volba stylu. `CtDatovaVeta`, `CtDokument`, `CtZamestnani`
 * i `CtPotvrzeniZamestnavateleNem` jsou `xs:sequence`, takže prohozený
 * `druhDavky` a `kodOSSZ` neprojde XSD. U potvrzení zaměstnavatele je navíc
 * past: typ je `xs:extension` nad `CtPotvrzeniZamestnavateleBaseType`, což
 * znamená, že prvky základu (`pracoval`, `pocetOdpracovanychHodin`,
 * `pracovniDoba`, `prijemMalyRozsah`) jdou PŘED prvky rozšíření, ne za ně.
 *
 * `version` je na kořeni `use="required"`; hodnota se bere z připnutého
 * manifestu {@see CsszSchemaCatalog}, ne z konstanty tady — jinak by šlo
 * vyměnit XSD a nechat v podání starou verzi payloadu.
 *
 * `partialAccept` se vědomě NEnastavuje. Podávací a dotazovací protokol v1.47
 * u NEMPRI uvádí částečné přijetí jako „Ano (vždy)", tedy ne jako volbu;
 * posílat atribut, který nic nemění, je jen další místo, kde se dá lhát.
 */
final class NempriXmlSerializer
{
    public function serialize(NempriXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/nem/NEMPRI25';
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS($namespace, 'NEMPRI');
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
        // `ISDSreport="3"` = XML i HTML příloha odpovědi. Pro VREP se atribut
        // ignoruje; pro datovou schránku je to jediná varianta, ze které se dá
        // protokol strojově přečíst i ručně zkontrolovat.
        $sender->setAttribute('ISDSreport', '3');
        $root->appendChild($sender);

        $record = $document->createElementNS($namespace, 'datovaVeta');
        // Datová věta unese 1 až 1500 formulářů. Aplikace posílá právě jeden:
        // lhůta podle § 97 odst. 2 běží každému případu zvlášť a dávkové
        // podání by ji svázalo s cizím případem.
        $record->setAttribute('poradoveCislo', '1');
        $record->appendChild($this->dokument($document, $namespace, $payload));
        $record->appendChild($this->pojistenec($document, $namespace, $payload));
        $record->appendChild($this->zamestnani($document, $namespace, $payload));
        $record->appendChild($this->davka($document, $namespace, $payload));
        if ($payload->additionalNote !== null) {
            $this->text(
                $document,
                $namespace,
                $record,
                'dalsiSdeleni',
                $payload->additionalNote,
            );
        }
        $worker = $this->kontaktPracovnik($document, $namespace, $payload);
        if ($worker !== null) {
            $record->appendChild($worker);
        }
        $root->appendChild($record);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SicknessException(
                'nempri_xml_serialization_failed',
                'XML oznámení NEMPRI nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    private function dokument(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'dokument');
        $this->text($document, $namespace, $node, 'kodOSSZ', (string) $payload->osszCode);
        $this->text(
            $document,
            $namespace,
            $node,
            'druhDavky',
            $payload->benefitKind->value,
        );
        if ($payload->correction) {
            $this->bool($document, $namespace, $node, 'opravnePodani', true);
        }
        if ($payload->decisionNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'cisloRozhodnuti',
                $payload->decisionNumber,
            );
        }
        $this->bool($document, $namespace, $node, 'zahranicni', $payload->foreignCase);

        return $node;
    }

    private function pojistenec(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'pojistenec');
        $this->text($document, $namespace, $node, 'jmeno', $payload->insuredFirstName);
        $this->text($document, $namespace, $node, 'prijmeni', $payload->insuredLastName);
        $this->text(
            $document,
            $namespace,
            $node,
            'rodneCislo',
            $payload->insuredBirthNumber,
        );
        if ($payload->insuredPhone !== null || $payload->insuredEmail !== null) {
            $contact = $document->createElementNS($namespace, 'kontakt');
            if ($payload->insuredPhone !== null) {
                $this->text($document, $namespace, $contact, 'telefon', $payload->insuredPhone);
            }
            if ($payload->insuredEmail !== null) {
                $this->text($document, $namespace, $contact, 'email', $payload->insuredEmail);
            }
            $node->appendChild($contact);
        }

        return $node;
    }

    private function zamestnani(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'zamestnani');
        $this->text(
            $document,
            $namespace,
            $node,
            'VSZamestnavatel',
            $payload->employerVariableSymbol,
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
            'nazevZamestnavatel',
            $payload->employerName,
        );
        $this->text($document, $namespace, $node, 'zamestnanOd', $payload->employmentFrom);
        if ($payload->employmentTo !== null) {
            $this->text($document, $namespace, $node, 'zamestnanDo', $payload->employmentTo);
        }
        $this->text($document, $namespace, $node, 'druhCinnosti', $payload->activityCode);

        return $node;
    }

    private function davka(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'davka');
        $kind = $document->createElementNS(
            $namespace,
            $payload->benefitKind->elementName(),
        );
        $kind->appendChild($this->potvrzeni($document, $namespace, $payload));
        $node->appendChild($kind);

        return $node;
    }

    /**
     * `potvrzeniZamestnavatele` u NEM i VPM.
     *
     * Základní část (`pracoval` … `prijemMalyRozsah`) je z rozšiřovaného typu,
     * takže musí jít první. Vyrovnávací příspěvek nemá prvky
     * `volnoBezNahrady*` — u něj by je XSD odmítlo.
     */
    private function potvrzeni(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): DOMElement {
        $node = $document->createElementNS($namespace, 'potvrzeniZamestnavatele');
        $this->bool($document, $namespace, $node, 'pracoval', $payload->workedOnDecisiveDay);
        if ($payload->hoursWorked !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pocetOdpracovanychHodin',
                $payload->hoursWorked,
            );
        }
        if ($payload->dailyWorkingHours !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'pracovniDoba',
                $payload->dailyWorkingHours,
            );
        }
        if ($payload->smallScopeIncomeMinor !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'prijemMalyRozsah',
                (string) $payload->smallScopeIncomeMinor,
            );
        }
        $this->bool($document, $namespace, $node, 'pobiraDuchod', $payload->receivesPension);
        if ($payload->pensionKind !== null) {
            $this->text($document, $namespace, $node, 'druhDuchodu', $payload->pensionKind);
        }
        $this->bool($document, $namespace, $node, 'jeStudentem', $payload->isStudent);
        if ($payload->withinSchoolHolidays !== null) {
            $this->bool(
                $document,
                $namespace,
                $node,
                'spadaDoPrazdnin',
                $payload->withinSchoolHolidays,
            );
        }
        $this->bool(
            $document,
            $namespace,
            $node,
            'dobaVolnaPrvniZamestnani',
            $payload->firstEmploymentFreeTime,
        );
        if ($payload->benefitKind->hasUnpaidLeaveSection()) {
            $this->bool(
                $document,
                $namespace,
                $node,
                'volnoBezNahrady',
                $payload->unpaidLeave,
            );
            if ($payload->unpaidLeaveFrom !== null) {
                $this->text(
                    $document,
                    $namespace,
                    $node,
                    'volnoBezNahradyOd',
                    $payload->unpaidLeaveFrom,
                );
            }
            if ($payload->unpaidLeaveTo !== null) {
                $this->text(
                    $document,
                    $namespace,
                    $node,
                    'volnoBezNahradyDo',
                    $payload->unpaidLeaveTo,
                );
            }
        }
        if ($payload->startsMaternity !== null) {
            $this->bool(
                $document,
                $namespace,
                $node,
                'nastupujePPM',
                $payload->startsMaternity,
            );
        }
        if ($payload->childBirthDate !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'narozeniDitete',
                $payload->childBirthDate,
            );
        }
        $this->bool(
            $document,
            $namespace,
            $node,
            'prevedenaNaJinouPraci',
            $payload->transferredOtherWork,
        );
        if ($payload->transferredOn !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'datumNaJinouPraci',
                $payload->transferredOn,
            );
        }
        $this->bool($document, $namespace, $node, 'exekuce', $payload->enforcement);
        $this->bool($document, $namespace, $node, 'insolvence', $payload->insolvency);

        return $node;
    }

    /**
     * `CtKontaktniPracovnik` rozšiřuje `CtKontakt`, takže `telefon` a `email`
     * jdou před jménem pracovníka, ne za ním.
     */
    private function kontaktPracovnik(
        DOMDocument $document,
        string $namespace,
        NempriXmlPayload $payload,
    ): ?DOMElement {
        if ($payload->contactWorkerName === null
            && $payload->contactWorkerPhone === null
            && $payload->contactWorkerEmail === null
        ) {
            return null;
        }
        $node = $document->createElementNS($namespace, 'kontaktPracovnik');
        if ($payload->contactWorkerPhone !== null) {
            $this->text($document, $namespace, $node, 'telefon', $payload->contactWorkerPhone);
        }
        if ($payload->contactWorkerEmail !== null) {
            $this->text($document, $namespace, $node, 'email', $payload->contactWorkerEmail);
        }
        if ($payload->contactWorkerName !== null) {
            $this->text(
                $document,
                $namespace,
                $node,
                'kontaktniPracovnik',
                $payload->contactWorkerName,
            );
        }

        return $node;
    }

    private function bool(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        bool $value,
    ): void {
        $this->text($document, $namespace, $parent, $name, $value ? 'true' : 'false');
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
