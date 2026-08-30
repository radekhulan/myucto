<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use DOMDocument;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;

/**
 * Validace datových vět NEMPRI25 a HZUPN20 proti PŘIPNUTÉMU XSD a proti těm
 * pravidlům, která XSD vyjádřit neumí.
 *
 * Tři vrstvy, každá chytá jinou třídu chyby:
 *
 * 1. **Obchodní hranice** — co XSD dovolí, ale ČSSZ odmítne až protokolem
 *    (opravné podání bez čísla rozhodnutí, pracovní volno bez období,
 *    interval práce mimo dobu neschopnosti).
 * 2. **Otisk snapshotu** — XML se přeserializuje z payloadu a porovná bajt po
 *    bajtu. Bez toho by šlo uložit artefakt, který neodpovídá datům, ze
 *    kterých vznikl, a nikdo by to nepoznal.
 * 3. **XSD** — proti souboru z {@see CsszSchemaCatalog}. Katalog ověřuje otisk
 *    SHA-256 vstupního schématu i jeho `baseTypes`; nesouhlasí-li, podání
 *    spadne. To je záměr: validovat proti jinému než ověřenému schématu
 *    znamená tvrdit shodu, kterou nikdo neprokázal.
 *
 * ## Past, kterou odhalilo až XSD
 *
 * `VSZamestnavatel` (NEMPRI) i `variabilniSymbol` (HZUPN) jsou
 * `tns:simpleNType_string` s `length=10`, tedy vzor `[1-9][0-9]*` na deseti
 * znacích. Variabilní symbol tudíž NESMÍ začínat nulou a NELZE ho doplnit
 * zleva nulami do desítky, jak to dělá OZUSPOJ u své vlastní datové věty.
 * Krátký nebo nulou začínající symbol je proto tvrdá chyba s vlastním
 * důvodovým kódem, ne tiché doplnění.
 */
final readonly class SicknessXmlValidator
{
    public function __construct(
        private CsszSchemaCatalog $schemas,
        private NempriXmlSerializer $nempri,
        private HzupnXmlSerializer $hzupn,
    ) {}

    public function validateNempri(NempriXmlPayload $payload, string $xml): void
    {
        if (!$payload->benefitKind->isSerializable()) {
            $this->invalid(
                $payload->benefitKind->unsupportedReasonCode(),
                $payload->benefitKind->unsupportedReason(),
            );
        }
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1) {
            $this->invalid(
                'nempri_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic. '
                . 'NEMPRI ho vyžaduje vždy — bez něj ČSSZ případ nespáruje.',
            );
        }
        foreach ([
            'nempri_insured_first_name_missing' => $payload->insuredFirstName,
            'nempri_insured_last_name_missing' => $payload->insuredLastName,
            'nempri_employer_name_missing' => $payload->employerName,
        ] as $code => $value) {
            if (trim($value) === '') {
                $this->invalid(
                    $code,
                    'Oznámení nemá vyplněné povinné identifikační údaje.',
                );
            }
        }
        if (preg_match('/^[0-9A-Z]{1,3}$/D', $payload->activityCode) !== 1) {
            $this->invalid(
                'nempri_activity_code_invalid',
                'Druh činnosti musí být kód z číselníku ČSSZ (1 až 3 znaky 0-9 a A-Z). '
                . 'Doplňte ho v podmínkách pracovního vztahu.',
            );
        }
        if ($payload->correction && $payload->decisionNumber === null) {
            $this->invalid(
                'nempri_correction_without_decision_number',
                'Opravné podání se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové podání.',
            );
        }
        if ($payload->benefitKind->hasUnpaidLeaveSection()) {
            $this->unpaidLeave($payload);
        } elseif ($payload->unpaidLeave
            || $payload->unpaidLeaveFrom !== null
            || $payload->unpaidLeaveTo !== null
        ) {
            $this->invalid(
                'nempri_unpaid_leave_not_in_benefit_kind',
                'Potvrzení zaměstnavatele u vyrovnávacího příspěvku prvek pracovního volna '
                . 'bez náhrady příjmu nemá; vyplněné volno by datovou větu shodilo.',
            );
        }
        if ($payload->transferredOtherWork !== ($payload->transferredOn !== null)) {
            $this->invalid(
                'nempri_transfer_date_mismatch',
                'Převedení na jinou práci musí mít datum a datum nesmí být bez převedení.',
            );
        }
        $this->exactDate($payload->employmentFrom, 'nempri_date_invalid');
        if ($payload->employmentTo !== null) {
            $this->exactDate($payload->employmentTo, 'nempri_date_invalid');
            if ($payload->employmentTo < $payload->employmentFrom) {
                $this->invalid(
                    'nempri_employment_period_invalid',
                    'Den skončení zaměstnání nesmí předcházet dni jeho vzniku.',
                );
            }
        }
        foreach ([
            $payload->unpaidLeaveFrom,
            $payload->unpaidLeaveTo,
            $payload->childBirthDate,
            $payload->transferredOn,
        ] as $date) {
            if ($date !== null) {
                $this->exactDate($date, 'nempri_date_invalid');
            }
        }
        $this->assertSnapshot(
            $this->nempri->serialize($payload),
            $xml,
            'nempri_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu NEMPRI.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::NEMPRI25,
            'nempri_xsd_validation_failed',
            'XML NEMPRI neprošlo připnutým XSD: ',
        );
    }

    /**
     * @param string $incapacityFrom První den dočasné pracovní neschopnosti;
     *        intervaly práce ani návrat do práce nesmí být dřív.
     */
    public function validateHzupn(
        HzupnXmlPayload $payload,
        string $xml,
        string $incapacityFrom,
    ): void {
        $this->osszCode($payload->osszCode);
        $this->variableSymbol($payload->employerVariableSymbol);
        if (!$payload->employerReport) {
            $this->invalid(
                'hzupn_employer_report_required',
                'Hlášení, které podává zaměstnavatel, musí mít příznak hlášení zaměstnavatele. '
                . 'Hlášení osoby dobrovolně nemocensky pojištěné podává pojištěnec sám.',
            );
        }
        if ($payload->personReport) {
            $this->invalid(
                'hzupn_person_report_not_supported',
                'Hlášení osoby dobrovolně nemocensky pojištěné aplikace nesestavuje — '
                . 'není to podání zaměstnavatele.',
            );
        }
        if ($payload->insuredBirthNumber === null
            && $payload->insuredBirthDate === null
        ) {
            $this->invalid(
                'hzupn_insured_identifier_missing',
                'Hlášení musí nést rodné číslo pojištěnce nebo alespoň datum narození, '
                . 'jinak ho ČSSZ nespáruje s neschopenkou.',
            );
        }
        if ($payload->insuredBirthNumber !== null
            && preg_match('/^\d{9,10}$/D', $payload->insuredBirthNumber) !== 1
        ) {
            $this->invalid(
                'hzupn_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic.',
            );
        }
        if ($payload->correction && $payload->confirmationNumber === null) {
            $this->invalid(
                'hzupn_correction_without_confirmation_number',
                'Opravné hlášení se páruje podle čísla rozhodnutí. Bez něj by ho ČSSZ '
                . 'zpracovala jako nové hlášení.',
            );
        }
        if ($payload->returnedToWork === true && $payload->returnedOn === null) {
            $this->invalid(
                'hzupn_return_date_missing',
                'Návrat do práce musí mít datum; z něj ČSSZ počítá poslední dávku.',
            );
        }
        if ($payload->returnedToWork !== true && $payload->returnedOn !== null) {
            $this->invalid(
                'hzupn_return_date_without_return',
                'Datum návratu do práce nesmí být vyplněné bez příznaku návratu.',
            );
        }
        $this->exactDate($payload->issuedOn, 'hzupn_date_invalid');
        $this->exactDate($incapacityFrom, 'hzupn_date_invalid');
        if ($payload->returnedOn !== null) {
            $this->exactDate($payload->returnedOn, 'hzupn_date_invalid');
            if ($payload->returnedOn < $incapacityFrom) {
                $this->invalid(
                    'hzupn_return_before_incapacity',
                    'Návrat do práce nemůže předcházet vzniku pracovní neschopnosti.',
                );
            }
        }
        $previousTo = null;
        foreach ($payload->workIntervals as $interval) {
            $this->exactDate($interval['from'], 'hzupn_date_invalid');
            $this->exactDate($interval['to'], 'hzupn_date_invalid');
            if ($interval['to'] < $interval['from']) {
                $this->invalid(
                    'hzupn_work_interval_invalid',
                    'Interval práce v době neschopnosti musí končit nejdřív dnem, kterým začíná.',
                );
            }
            if ($interval['from'] < $incapacityFrom) {
                $this->invalid(
                    'hzupn_work_interval_before_incapacity',
                    'Práce v době neschopnosti nemůže spadat před její vznik.',
                );
            }
            if ($previousTo !== null && $interval['from'] <= $previousTo) {
                $this->invalid(
                    'hzupn_work_intervals_overlap',
                    'Intervaly práce v době neschopnosti se nesmí překrývat ani navazovat '
                    . 've stejný den; ČSSZ z nich počítá vyloučené dny.',
                );
            }
            $previousTo = $interval['to'];
        }
        $this->assertSnapshot(
            $this->hzupn->serialize($payload),
            $xml,
            'hzupn_xml_snapshot_mismatch',
            'XML byteově neodpovídá zdrojovému payloadu HZUPN.',
        );
        $this->assertSchema(
            $xml,
            CsszSchemaCatalog::HZUPN20,
            'hzupn_xsd_validation_failed',
            'XML HZUPN neprošlo připnutým XSD: ',
        );
    }

    private function unpaidLeave(NempriXmlPayload $payload): void
    {
        if ($payload->unpaidLeave && $payload->unpaidLeaveFrom === null) {
            $this->invalid(
                'nempri_unpaid_leave_period_missing',
                'Pracovní volno bez náhrady příjmu musí mít den, od kterého trvalo — '
                . 'z něj se posuzují vyloučené dny.',
            );
        }
        if (!$payload->unpaidLeave
            && ($payload->unpaidLeaveFrom !== null || $payload->unpaidLeaveTo !== null)
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_without_flag',
                'Období pracovního volna bez náhrady příjmu nesmí být vyplněné, '
                . 'když volno nebylo čerpáno.',
            );
        }
        if ($payload->unpaidLeaveFrom !== null
            && $payload->unpaidLeaveTo !== null
            && $payload->unpaidLeaveTo < $payload->unpaidLeaveFrom
        ) {
            $this->invalid(
                'nempri_unpaid_leave_period_invalid',
                'Konec pracovního volna bez náhrady příjmu nesmí předcházet jeho začátku.',
            );
        }
    }

    private function osszCode(int $code): void
    {
        if ($code < 100 || $code > 999) {
            $this->invalid(
                'sickness_ossz_code_invalid',
                'Kód OSSZ musí být tříciferný podle číselníku pracovišť ČSSZ. '
                . 'Doplňte ho v Nastavení mezd → Zaměstnavatel.',
            );
        }
    }

    private function variableSymbol(string $symbol): void
    {
        if (preg_match('/^[1-9][0-9]{9}$/D', $symbol) !== 1) {
            $this->invalid(
                'sickness_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít deset číslic a nesmí začínat nulou — '
                . 'obě XSD ho mají jako typ N s pevnou délkou 10. Doplnit ho zleva nulami nelze; '
                . 'opravte ho v Nastavení mezd → Účtárny.',
            );
        }
    }

    private function assertSnapshot(
        string $expected,
        string $actual,
        string $code,
        string $message,
    ): void {
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
            $this->invalid($code, $message);
        }
    }

    private function assertSchema(
        string $xml,
        string $documentType,
        string $code,
        string $messagePrefix,
    ): void {
        try {
            $schema = $this->schemas->schemaFor($documentType);
        } catch (\RuntimeException $exception) {
            $this->invalid(
                'sickness_schema_integrity_failed',
                'Připnutý XSD balíček ČSSZ chybí nebo má jiný otisk, než jaký byl ověřen: '
                . $exception->getMessage(),
            );
        }
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
                $code,
                $messagePrefix . implode('; ', array_unique($messages)),
            );
        }
    }

    private function exactDate(string $value, string $code): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                $code,
                'Datum v podání musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new SicknessException($code, $message);
    }
}
