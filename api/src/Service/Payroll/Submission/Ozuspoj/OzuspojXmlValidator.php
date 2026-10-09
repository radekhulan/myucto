<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use DOMDocument;
use MyInvoice\Service\Payroll\PayrollSubmissionPersonName;
use MyInvoice\Service\Payroll\Submission\Sickness\CsszWorkplaceCatalog;

/**
 * Validace datové věty OZUSPOJ proti připnutému XSD a proti těm pravidlům
 * popisu datové věty, která XSD vyjádřit neumí.
 *
 * XSD dovoluje `datumOd` i `datumDo` u kteréhokoli typu podání — obojí je
 * `minOccurs="0"`. Popis datové věty OZUSPOJ23 je ale váže na `typPodani`:
 * `datumOd` je povinné pro 1 a 3 a nesmí být vyplněné pro 2, `datumDo` je
 * povinné pro 2 a musí být >= `datumOd`. Bez téhle vrstvy by prošlo oznámení
 * skončení bez data skončení, které ČSSZ odmítne až protokolem.
 */
final readonly class OzuspojXmlValidator
{
    public function __construct(
        private OzuspojSchemaCatalog $schemas,
    ) {}

    public function validate(OzuspojXmlPayload $payload, string $xml): void
    {
        $this->validateBusinessBoundary($payload);
        $expected = (new OzuspojXmlSerializer())->serialize($payload);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $xml))) {
            $this->invalid(
                'ozuspoj_xml_snapshot_mismatch',
                'XML byteově neodpovídá zdrojovému payloadu OZUSPOJ.',
            );
        }
        $schema = $this->schemas->schemaFor(
            OzuspojSchemaCatalog::DOCUMENT_TYPE,
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            );
            $this->invalid(
                'ozuspoj_xsd_validation_failed',
                'XML OZUSPOJ neprošlo připnutým XSD: '
                    . implode('; ', array_unique($messages)),
            );
        }
    }

    private function validateBusinessBoundary(OzuspojXmlPayload $payload): void
    {
        if ($payload->kind->requiresIntentFrom()
            && $payload->intentFrom === null
        ) {
            $this->invalid(
                'ozuspoj_intent_from_required',
                'Oznámení i storno záměru musí uvádět den, od kterého se sleva uplatňuje.',
            );
        }
        if (!$payload->kind->requiresIntentFrom()
            && $payload->intentFrom !== null
        ) {
            $this->invalid(
                'ozuspoj_intent_from_forbidden',
                'Oznámení skončení uplatňování slevy nesmí uvádět den zahájení.',
            );
        }
        if ($payload->kind->requiresIntentTo() && $payload->intentTo === null) {
            $this->invalid(
                'ozuspoj_intent_to_required',
                'Oznámení skončení uplatňování slevy musí uvádět den skončení.',
            );
        }
        if ($payload->intentFrom !== null
            && $payload->intentTo !== null
            && $payload->intentTo < $payload->intentFrom
        ) {
            $this->invalid(
                'ozuspoj_intent_period_invalid',
                'Den skončení záměru nesmí předcházet dni jeho zahájení.',
            );
        }
        if ($payload->osszCode < 100 || $payload->osszCode > 999) {
            $this->invalid(
                'ozuspoj_ossz_code_invalid',
                'Kód OSSZ musí být tříciferný podle číselníku pracovišť ČSSZ. Doplňte ho v Nastavení mezd → Zaměstnavatel.',
            );
        }
        if (!CsszWorkplaceCatalog::acceptsSubmission($payload->osszCode)) {
            $this->invalid(
                'ozuspoj_ossz_code_not_in_codebook',
                'Kód OSSZ ' . $payload->osszCode . ' není v číselníku okresů ČSSZ pro e-podání (kód 101, ústředí, se pro e-podání nepoužívá). Opravte ho v Nastavení mezd → Zaměstnavatel na kód místně příslušné OSSZ.',
            );
        }
        if (preg_match('/^\d{10}$/D', $payload->employerVariableSymbol) !== 1) {
            $this->invalid(
                'ozuspoj_variable_symbol_invalid',
                'OZUSPOJ vyžaduje desetimístný variabilní symbol zaměstnavatele. Doplňte ho v Nastavení mezd → Účtárny.',
            );
        }
        // XSD nechává `rodneCislo` nepovinné, ale oznámení záměru i skončení
        // nese rodné číslo zaměstnance (§ 23e odst. 1 a 2, § 23f odst. 3
        // písm. a) a ČSSZ podle něj (nebo podle EČP) osobu identifikuje.
        // Bez něj by podání prošlo schématem a záměr by nešlo přiřadit.
        if ($payload->employeeBirthNumber === null
            || trim($payload->employeeBirthNumber) === ''
        ) {
            $this->invalid(
                'ozuspoj_birth_number_missing',
                'Zaměstnanec nemá k rozhodnému dni vyplněné rodné číslo ani evidenční číslo pojištěnce (EČP). ČSSZ podle něj záměr přiřazuje osobě, takže ho doplňte v Osobách u identifikátorů zaměstnance a oznámení připravte znovu.',
            );
        }
        if (preg_match('/^\d{9,10}$/D', $payload->employeeBirthNumber) !== 1) {
            $this->invalid(
                'ozuspoj_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic.',
            );
        }
        foreach ([
            'jméno' => $payload->employeeFirstName,
            'příjmení' => $payload->employeeLastName,
        ] as $label => $name) {
            $this->assertNameCharacters($label, $name);
        }
        foreach ([
            'ozuspoj_employer_name_missing' => $payload->employerName,
            'ozuspoj_employee_first_name_missing' => $payload->employeeFirstName,
            'ozuspoj_employee_last_name_missing' => $payload->employeeLastName,
        ] as $code => $value) {
            if (trim($value) === '') {
                $this->invalid(
                    $code,
                    'Oznámení záměru nemá vyplněné povinné identifikační údaje.',
                );
            }
        }
        // Popis datové věty: VENDOR/@productName 0 až 64 znaků,
        // @productVersion 0 až 16, SENDER/@EmailNotifikace 0 až 250 ve tvaru
        // e-mailové adresy. XSD je má jako volný xs:string, takže to neuhlídá.
        if (mb_strlen($payload->productName) > 64 || mb_strlen($payload->productVersion) > 16) {
            $this->invalid(
                'ozuspoj_vendor_invalid',
                'Název programu smí mít nejvýš 64 znaků a jeho verze 16 znaků.',
            );
        }
        if ($payload->notificationEmail !== null
            && (mb_strlen($payload->notificationEmail) > 250
                || preg_match('/^[^@\s]+@[^.@\s]+\..+$/uD', $payload->notificationEmail) !== 1)
        ) {
            $this->invalid(
                'ozuspoj_notification_email_invalid',
                'E-mail pro oznámení o výsledku zpracování nemá tvar e-mailové adresy nebo je delší než 250 znaků.',
            );
        }
        $this->exactDate($payload->employeeBirthDate);
        if ($payload->intentFrom !== null) {
            $this->exactDate($payload->intentFrom);
        }
        if ($payload->intentTo !== null) {
            $this->exactDate($payload->intentTo);
        }
    }

    /**
     * Jméno a příjmení smí mít jen znaky z `simpleA_ZX_SP_Type` v baseTypes2.xsd
     * (latinka s diakritikou, `-`, `,`, `.`, `'` a mezera). Jiný znak (číslice,
     * závorka, cyrilice, tabulátor) XSD zamítne, ale hláškou knihovny, ze které
     * uživatel nepozná, co a kde opravit. Jméno se samo nepřepisuje: změna
     * zapsaného jména osoby by byla změnou její identity v podání.
     */
    private function assertNameCharacters(string $label, string $value): void
    {
        $characters = PayrollSubmissionPersonName::disallowedCharacters($value);
        if ($characters === []) {
            return;
        }
        $this->invalid(
            'ozuspoj_employee_name_characters_invalid',
            'Ve ' . ($label === 'jméno' ? 'jméně' : 'příjmení') . ' zaměstnance jsou znaky, které datová věta OZUSPOJ nepřipouští: '
                . PayrollSubmissionPersonName::shown($characters) . '. Opravte ' . $label
                . ' v Osobách u zaměstnance (povolená je latinka, pomlčka, čárka, tečka, apostrof a mezera) a oznámení připravte znovu.',
        );
    }

    private function exactDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                'ozuspoj_date_invalid',
                'Datum v oznámení záměru musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new OzuspojException($code, $message);
    }
}
