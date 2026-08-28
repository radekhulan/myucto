<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;

final readonly class PayrollRegistrationXmlValidator
{
    public function __construct(
        private PayrollRegistrationSchemaCatalog $schemas,
    ) {}

    public function validate(
        PayrollRegistrationXmlPayload $payload,
        string $xml,
    ): void {
        $this->validateBusinessBoundary($payload);
        $expected = (new PayrollRegistrationXmlSerializer())
            ->serialize($payload);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $xml))) {
            $this->invalid(
                'registration_xml_snapshot_mismatch',
                'XML byteově neodpovídá zdrojovému snapshotu.',
            );
        }
        $schema = $this->schemas->schemaFor(
            $payload->interaction->documentType,
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOBLANKS,
        );
        // `schemaValidate()` žádný `LIBXML_NONET` nepřijímá (druhý parametr umí
        // jen `LIBXML_SCHEMA_CREATE`), takže síť tu vypnout nejde. Offline běh
        // drží jinde: jediné `xs:import` obou připnutých schémat míří na
        // relativní `baseTypes2.xsd` a oba soubory se ověřují SHA-256
        // v `PayrollRegistrationSchemaCatalog` — vzdálený `schemaLocation`
        // by musel projít změnou otisku, která validaci zavře.
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string =>
                    trim($error->message),
                $errors,
            );
            $this->invalid(
                'registration_xsd_validation_failed',
                'Registrační XML neprošlo připnutým XSD: '
                    . implode('; ', array_unique($messages)),
            );
        }
    }

    private function validateBusinessBoundary(
        PayrollRegistrationXmlPayload $payload,
    ): void {
        // XSD tuhle hranici neuhlídá: REGZEC25 povoluje `act` 1..99, takže
        // opravy a storna (A2–A8) by prošly. Allowlist interakcí je jediné
        // místo, kde se rozhoduje, co core vůbec umí vyrobit.
        //
        // Payload je volně sestavitelný, takže tady se přehrává CELÁ vazba na
        // zmrazený snapshot (agenda, způsobilost, identifikátor, allowlist)
        // ze stejné implementace, kterou používá resolver — jinak by ji stačilo
        // obejít tím, že se resolver prostě nezavolá.
        (new PayrollRegistrationInteractionResolver())->assertBoundToSnapshot(
            $payload->identity,
            $payload->interaction,
        );
        if ($payload->sequenceNumber < 1
            || $payload->sequenceNumber > 1500
            || preg_match(
                '/^[0-9A-F]{8}(?:-[0-9A-F]{4}){3}-[0-9A-F]{12}$/D',
                $payload->formGuid,
            ) !== 1
            || preg_match('/^\d{8,10}$/D', $payload->employerVariableSymbol)
                !== 1
        ) {
            $this->invalid(
                'registration_payload_invalid',
                'Registrační payload nemá platná metadata.',
            );
        }
        if ($payload->interaction->documentType === 'PREZEC26') {
            // Způsobilost je vázaná na STÁTNÍ OBČANSTVÍ, ne na držení RČ.
            // Je to vědomě přísnější fail-closed varianta: cizinec s trvalým
            // pobytem a přiděleným českým rodným číslem by PREZEC strukturálně
            // naplnil, ale rozhodnout, jestli na něj částečné přihlášení
            // vůbec dosáhne, umí jedině katalog kontrol MH na
            // developers.mpsv.cz (lokálně ho nemáme, viz otevřený bod
            // v `private/MZDY-EPICs.md` § „Čeká na rozhodnutí člověka").
            // Do té doby musí hláška uživateli přesně říct, co udělat.
            if (($payload->identity->identity['citizenship_country_code']
                    ?? null) !== 'CZ'
            ) {
                $this->invalid(
                    'registration_prezec_foreign_requires_full_registration',
                    'PREZEC (částečné přihlášení před nástupem) se tady podává jen zaměstnanci s českým státním občanstvím. U zaměstnance s jiným občanstvím — i když má přidělené české rodné číslo — podejte místo něj plnou registraci REGZEC, a to před zahájením práce.',
                );
            }
            if ($payload->interaction->actionCode === 9) {
                // Bez striktní kontroly by se `null`/prázdný řetězec propadl do
                // `new DateTimeImmutable('')`, tedy do systémového „dneška",
                // a okno by se počítalo proti času běhu; nesmyslný řetězec by
                // navíc vyhodil `DateMalformedStringException` mimo kontrakt.
                $start = $this->exactDate($payload->expectedStartOn);
                $prepared = $this->exactDate($payload->preparedOn);
                $days = (int) $prepared->diff($start)->format('%r%a');
                if ($days < 0 || $days > 8) {
                    $this->invalid(
                        'registration_prezec_start_window_invalid',
                        'PREZEC P1 lze podat nejvýše osm dnů před nástupem.',
                    );
                }
            }
        } elseif ($payload->employerName === null
            || $payload->csszWorkplaceCode === null
        ) {
            $this->invalid(
                'registration_regzec_full_payload_incomplete',
                'REGZEC nemá úplná povinná metadata zaměstnavatele.',
            );
        } elseif ($payload->interaction->actionCode === 1) {
            $a1 = $payload->identity->regzecA1;
            PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                $a1?->employment['activity_code'] ?? null,
                $a1?->employment['relationship_detail_code'] ?? null,
                $a1 !== null,
            );
        } elseif ($payload->interaction->actionCode >= 2) {
            $this->validateEventSnapshot($payload);
        }
    }

    private function validateEventSnapshot(
        PayrollRegistrationXmlPayload $payload,
    ): void {
        $event = $payload->eventSnapshot;
        if (!is_array($event)
            || ($event['schema_reference'] ?? null)
                !== PayrollRegistrationEventService::SCHEMA_REFERENCE
            || ($event['interaction'] ?? null)
                !== $payload->interaction->interaction
            || (int) ($event['action_code'] ?? 0)
                !== $payload->interaction->actionCode
        ) {
            $this->invalid(
                'registration_event_snapshot_invalid',
                'REGZEC A2–A8 neodpovídá schválenému neměnnému zdroji.',
            );
        }
        $person = $event['person_external_identifier'] ?? null;
        $employment = $event['employment_external_identifier'] ?? null;
        $effectiveOn = $event['effective_on'] ?? null;
        $notificationTriggerOn = $event['notification_trigger_on'] ?? null;
        if (!is_array($person) || !is_array($employment)
            || preg_match('/^\d{10}$/D', (string) ($person['value'] ?? '')) !== 1
            || preg_match('/^\d{1,22}$/D', (string) ($employment['value'] ?? '')) !== 1
            || !is_string($effectiveOn)
            || !is_string($notificationTriggerOn)
        ) {
            $this->invalid(
                'registration_event_identifiers_invalid',
                'Navazující REGZEC vyžaduje účinné OIČ / IK MPSV a ID PPV.',
            );
        }
        $this->exactDate($effectiveOn);
        $this->exactDate($notificationTriggerOn);
        $data = $event['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            $this->invalid(
                'registration_event_snapshot_invalid',
                'Navazující REGZEC nemá platná data události.',
            );
        }
        $action = $payload->interaction->actionCode;
        PayrollRegistrationBusinessMatrix::requireActionVariant(
            $action,
            is_string($data['activity_code'] ?? null)
                ? $data['activity_code']
                : null,
            is_string($data['relationship_detail_code'] ?? null)
                ? $data['relationship_detail_code']
                : null,
        );
        $valid = match ($action) {
            2 => ($data['end_on'] ?? null) === $effectiveOn
                && is_string($data['activity_code'] ?? null),
            3, 4 => is_array($data['delta'] ?? null)
                && ($data['delta'] ?? []) !== []
                && is_string($data['activity_code'] ?? null),
            5 => preg_match(
                '/^\d{8,10}$/D',
                (string) ($data['new_variable_symbol'] ?? ''),
            ) === 1,
            6 => ($data['foreign_insurance']['current'] ?? null) === 'P',
            7 => ($data['foreign_insurance']['current'] ?? null) === 'S'
                && is_string($data['foreign_insurance']['identifier'] ?? null),
            8 => ($data['not_started'] ?? null) === true,
            default => false,
        };
        if (!$valid) {
            $this->invalid(
                'registration_event_required_fields_missing',
                'Neměnný zdroj neobsahuje povinná pole z matice REGZEC A2–A8.',
            );
        }
    }

    private function exactDate(?string $value): \DateTimeImmutable
    {
        $date = $value === null
            ? false
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                'registration_prezec_start_date_invalid',
                'PREZEC P1 vyžaduje datum nástupu i datum vyhotovení ve tvaru RRRR-MM-DD.',
            );
        }

        return $date;
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}
