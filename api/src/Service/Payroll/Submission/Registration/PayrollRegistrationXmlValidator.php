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
                'Připravený soubor pro ČSSZ neodpovídá uloženým údajům '
                    . 'registrace — údaje se mezitím změnily. Připravte podání '
                    . 'znovu, ať se soubor vytvoří z aktuálních dat.',
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
            // Popis od XSD je anglický a technický. Nechat ho tam musíme —
            // bez něj se nedá zjistit, které pole ČSSZ vadí — ale musí až za
            // vysvětlením, co se vlastně stalo a co s tím účetní udělá.
            $this->invalid(
                'registration_xsd_validation_failed',
                'Podání neprošlo kontrolou proti formuláři ČSSZ, takže by ho '
                    . 'ČSSZ odmítla. Zkontrolujte údaje registrace a připravte '
                    . 'podání znovu; pokud vada zůstane, pošlete podpoře tento '
                    . 'technický popis: '
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
        // Tři různé vady pod jedním kódem: společná hláška „nemá platná
        // metadata" neřekla, která z nich to je — a jen jedna z nich je
        // uživatelsky opravitelná (variabilní symbol), zbylé dvě znamenají
        // „připravte podání znovu".
        if ($payload->sequenceNumber < 1 || $payload->sequenceNumber > 1500) {
            $this->invalid(
                'registration_payload_invalid',
                'Pořadové číslo podání musí být 1 až 1500, teď je '
                    . $payload->sequenceNumber . '. Připravte podání znovu.',
            );
        }
        if (preg_match(
            '/^[0-9A-F]{8}(?:-[0-9A-F]{4}){3}-[0-9A-F]{12}$/D',
            $payload->formGuid,
        ) !== 1) {
            $this->invalid(
                'registration_payload_invalid',
                'Identifikátor formuláře nemá tvar, který ČSSZ přijímá. '
                    . 'Vzniká automaticky, takže stačí připravit podání znovu.',
            );
        }
        if (preg_match('/^\d{8,10}$/D', $payload->employerVariableSymbol)
            !== 1
        ) {
            $this->invalid(
                'registration_payload_invalid',
                PayrollRegistrationFieldVocabulary::label(
                    'employer_variable_symbol',
                ) . ' musí mít 8 až 10 číslic bez mezer a lomítek. '
                    . PayrollRegistrationFieldVocabulary::describe(
                        'employer_variable_symbol',
                    ),
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
                $start = $this->exactDate(
                    $payload->expectedStartOn,
                    'Předpokládané datum nástupu',
                );
                $prepared = $this->exactDate(
                    $payload->preparedOn,
                    'Datum vyhotovení podání',
                );
                $days = (int) $prepared->diff($start)->format('%r%a');
                if ($days < 0 || $days > 8) {
                    $this->invalid(
                        'registration_prezec_start_window_invalid',
                        'Částečné přihlášení před nástupem (PREZEC P1) jde '
                            . 'podat nejdřív osm dnů před nástupem a nejpozději '
                            . 'v den nástupu. Mezi vyhotovením ('
                            . $prepared->format('d.m.Y') . ') a nástupem ('
                            . $start->format('d.m.Y') . ') je ' . $days
                            . ' dnů. Buď s podáním počkejte, nebo podejte rovnou '
                            . 'plnou registraci REGZEC.',
                    );
                }
            }
        } elseif ($payload->employerName === null
            || $payload->csszWorkplaceCode === null
        ) {
            $missing = [];
            foreach ([
                'employer_name' => $payload->employerName,
                'cssz_workplace_code' => $payload->csszWorkplaceCode,
            ] as $path => $value) {
                if ($value === null) {
                    $missing[] = mb_lcfirst(
                        PayrollRegistrationFieldVocabulary::label($path),
                    );
                }
            }
            $this->invalid(
                'registration_regzec_full_payload_incomplete',
                mb_ucfirst(implode(' a ', $missing))
                    . ' chybí — podání REGZEC bez toho ČSSZ nepřijme. '
                    . PayrollRegistrationFieldVocabulary::describe(
                        'employer_name',
                    ),
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
                PayrollRegistrationFieldVocabulary::action(
                    $payload->interaction->documentType,
                    $payload->interaction->actionCode,
                ) . ' neodpovídá uloženému záznamu události — od chvíle, kdy se '
                    . 'podání připravilo, se záznam změnil nebo se zaměnil za '
                    . 'jiný. Otevřete oznámení znovu a připravte podání ještě '
                    . 'jednou.',
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
                PayrollRegistrationFieldVocabulary::label(
                    'person_external_identifier',
                ) . ' a ' . mb_lcfirst(
                    PayrollRegistrationFieldVocabulary::label(
                        'employment_external_identifier',
                    ),
                ) . ' chybí nebo nemají platný tvar — OIČ má přesně 10 číslic, '
                    . 'ID PPV 1 až 22 číslic. Obě čísla přiděluje ČSSZ '
                    . 'v odpovědi na první přihlášení zaměstnance; než odpověď '
                    . 'přijde a načte se, navazující oznámení podat nelze.',
            );
        }
        $this->exactDate(
            $effectiveOn,
            PayrollRegistrationFieldVocabulary::label('effective_on'),
        );
        $this->exactDate(
            $notificationTriggerOn,
            PayrollRegistrationFieldVocabulary::label(
                'notification_trigger_on',
            ),
        );
        $data = $event['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            $this->invalid(
                'registration_event_snapshot_invalid',
                PayrollRegistrationFieldVocabulary::action(
                    $payload->interaction->documentType,
                    $payload->interaction->actionCode,
                ) . ' nemá uložené žádné údaje ke hlášení. Otevřete oznámení, '
                    . 'vyplňte ho a připravte podání znovu.',
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
            // Společná hláška „neobsahuje povinná pole z matice" účetní
            // neřekla, KTERÉ pole u KTERÉHO oznámení. Každá akce má jiný
            // povinný údaj, takže si každá zaslouží vlastní věty.
            $this->invalid(
                'registration_event_required_fields_missing',
                PayrollRegistrationFieldVocabulary::action(
                    $payload->interaction->documentType,
                    $action,
                ) . ' nejde podat: ' . match ($action) {
                    2 => 'datum skončení pracovního vztahu chybí, nebo nesedí '
                        . 'na den, ke kterému se změna hlásí. Srovnejte obojí '
                        . 'na kartě pracovního vztahu.',
                    3, 4 => 'není vidět žádná změna proti tomu, co už ČSSZ ví. '
                        . 'Změňte aspoň jeden hlásitelný údaj na kartě osoby '
                        . 'nebo pracovního vztahu, nebo oznámení zrušte.',
                    5 => mb_lcfirst(
                        PayrollRegistrationFieldVocabulary::label(
                            'new_variable_symbol',
                        ),
                    ) . ' chybí nebo nemá 8 až 10 číslic. '
                        . PayrollRegistrationFieldVocabulary::describe(
                            'new_variable_symbol',
                        ),
                    6 => 'zaměstnanec podle uložených údajů českým předpisům '
                        . 'nepodléhá, takže není co oznamovat. Opravte '
                        . 'příslušnost k zahraničnímu pojištění na kartě osoby.',
                    7 => 'chybí údaj o tom, že příslušnost k českým předpisům '
                        . 'skončila, nebo identifikátor zahraničního pojištění. '
                        . 'Doplňte obojí na kartě osoby.',
                    8 => 'chybí potvrzení, že zaměstnanec skutečně nenastoupil. '
                        . 'Storno se podává jen k přihlášení, ke kterému '
                        . 'nástup nedošel.',
                    default => 'tenhle druh oznámení zatím neumíme připravit. '
                        . 'Podejte ho přes portál ČSSZ.',
                },
            );
        }
    }

    /**
     * @param string $label lidský název data, ať hláška nemluví o PREZEC P1
     *                      i tam, kde se kontroluje datum události A2–A8
     */
    private function exactDate(
        ?string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = $value === null
            ? false
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                'registration_prezec_start_date_invalid',
                $label . ' chybí nebo není platné datum. Zadejte ho ve tvaru '
                    . 'RRRR-MM-DD, například 2026-08-05.',
            );
        }

        return $date;
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationXmlException($code, $message);
    }
}
