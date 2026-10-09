<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;

final class PayrollRegistrationXmlSerializer
{
    /**
     * Atributy bloku `unemplcomp`, které v REGZEC25 stojí na typu
     * `t:simpleNType_string`. Ten má pattern `[1-9][0-9]*` a na rozdíl od
     * číselného `t:simpleNType` (`[1-9].*|0`) nemá pro nulu žádnou výjimku —
     * hodnota `"0"` tedy schématem NEPROJDE, i když je věcně smysluplná
     * („odstupné se nevyplácelo"). Všechny jsou `use="optional"`, takže
     * správné chování je atribut vynechat, ne poslat nulu.
     *
     * Seznam je pojmenovaný a společný schválně: kdyby zůstal jako výjimka
     * u jediného atributu, tichá past by u zbylých čtyř žila dál. Ověřeno
     * proti připnutému schématu `api/xsd/jmhz/regzec-1.4.0.4/REGZEC25.xsd`
     * (avgmonear :920, replacement :941, goldenhandshake :952,
     * severancepay :963, disposal :974) a `baseTypes2.xsd` :91-101.
     * `earlyterm` sem NEPATŘÍ — je na `t:simpleNType`, kde nula projde.
     *
     * @var list<string>
     */
    private const ZERO_FORBIDDEN_ATTRIBUTES = [
        'avgmonear',
        'replacement',
        'goldenhandshake',
        'severancepay',
        'disposal',
    ];

    /**
     * Kořenové atributy obou registračních vět.
     *
     * `partialAccept` rozhoduje, co ČSSZ udělá s podáním, ve kterém je jedna
     * věta chybná a druhá správná. Bez něj se zamítá CELÉ podání — doloženo
     * odpovědí vývojáře MPSV v diskusi k JMHZ („V XML podání chybí
     * partialAccept="A", proto bylo zamítnuto celé podání") i registracemi,
     * které ČSSZ přijala z cizího mzdového systému. Aplikace posílá jednu větu
     * na podání, takže se rozdíl dnes neprojeví; projevil by se až u prvního
     * dávkového podání, tedy na ostrých datech.
     *
     * `version` je verze datové věty, kterou odesílatel implementuje. Schéma
     * ji má jako `xs:string` bez výčtu, takže se nevaliduje. Drží se proto
     * major.minor PŘIPNUTÉHO balíčku ({@see PayrollRegistrationSchemaCatalog}),
     * ne atributu `xs:schema/@version` — ten je v obou schématech starší než
     * balíček sám (PREZEC26 1.2 nese 1.1, REGZEC25 1.4.0.4 nese 1.2).
     *
     * @var array<string,array<string,string>>
     */
    private const ROOT_ATTRIBUTES = [
        'PREZEC26' => ['version' => '1.2', 'partialAccept' => 'A'],
        'REGZEC25' => ['version' => '1.4', 'partialAccept' => 'A'],
    ];

    public function serialize(PayrollRegistrationXmlPayload $payload): string
    {
        // Výjimky v serializéru zůstávají: tady se skládá soubor, který půjde
        // na ČSSZ. Neúplný vstup sem nemá vůbec dojít (sbírá ho A1 builder
        // a kontrola profilu), a co projde až sem, nesmí odejít napůl hotové.
        if (!$payload->interaction->supported()) {
            throw new PayrollRegistrationXmlException(
                'registration_interaction_unsupported',
                PayrollRegistrationFieldVocabulary::action(
                    $payload->interaction->documentType,
                    $payload->interaction->actionCode,
                ) . ' se v této kombinaci nepodává — appka umí jen podání '
                    . 'z nabídky u pracovního vztahu. Vyberte druh podání znovu '
                    . 'z nabídky.',
            );
        }
        if ($payload->interaction->documentType === 'REGZEC25'
            && $payload->interaction->actionCode === 1
        ) {
            PayrollRegistrationBusinessMatrix::requireA1Variant(
                $payload->identity->regzecA1,
            );
        }
        $eventData = $payload->eventSnapshot['data'] ?? null;
        if ($payload->interaction->documentType === 'REGZEC25'
            && $payload->interaction->actionCode >= 2
            && is_array($eventData)
            && !array_is_list($eventData)
        ) {
            PayrollRegistrationBusinessMatrix::requireActionVariant(
                $payload->interaction->actionCode,
                is_string($eventData['activity_code'] ?? null)
                    ? $eventData['activity_code']
                    : null,
                is_string($eventData['relationship_detail_code'] ?? null)
                    ? $eventData['relationship_detail_code']
                    : null,
            );
            $forbidden = PayrollRegistrationEventVariantRule::forbiddenParts(
                $payload->interaction->actionCode,
                (string) $eventData['activity_code'],
                is_string($eventData['relationship_detail_code'] ?? null)
                    ? $eventData['relationship_detail_code']
                    : null,
                $eventData,
            );
            if ($forbidden !== []) {
                throw new PayrollRegistrationXmlException(
                    'registration_regzec_variant_part_forbidden',
                    PayrollRegistrationFieldVocabulary::action(
                        'REGZEC25',
                        $payload->interaction->actionCode,
                    ) . ' nese údaje, které ČSSZ u tohoto druhu činnosti '
                        . 'zamítne (' . implode(', ', $forbidden) . '). '
                        . 'Otevřete oznámení znovu a připravte podání ještě '
                        . 'jednou.',
                );
            }
        }

        return match ($payload->interaction->documentType) {
            'PREZEC26' => $this->prezec($payload),
            'REGZEC25' => $this->regzec($payload),
            default => throw new PayrollRegistrationXmlException(
                'registration_serializer_unavailable',
                "Registrační formulář ČSSZ „{$payload->interaction->documentType}“ "
                    . 'tahle verze neumí vyplnit. Podporované jsou REGZEC25 '
                    . 'a PREZEC26; ostatní podejte přes portál ČSSZ.',
            ),
        };
    }

    private function prezec(PayrollRegistrationXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/PREZEC/2026';
        $document = $this->document();
        $root = $this->root($document, $namespace, 'PREZEC', 'PREZEC26');
        $this->appendVendor($document, $namespace, $root, $payload);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, true);
        $client = $this->element($document, $namespace, 'client');
        $client->setAttribute('bno', $this->bno($payload));
        if ($payload->interaction->actionCode === 9) {
            $this->identityElements($document, $namespace, $client, $payload);
        }
        $employee->appendChild($client);
        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $employee->appendChild($comp);
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    private function regzec(PayrollRegistrationXmlPayload $payload): string
    {
        if ($payload->interaction->actionCode !== 1) {
            return $this->regzecEvent($payload);
        }
        $namespace = 'http://schemas.cssz.cz/REGZEC/2025';
        $a1 = $payload->identity->regzecA1;
        if ($a1 === null) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_a1_variant_data_incomplete',
                'Přihlášení zaměstnance (REGZEC A1) nemá uložený profil '
                    . 's údaji pro ČSSZ. Otevřete registraci u pracovního '
                    . 'vztahu, vyplňte profil, uložte ho a připravte podání '
                    . 'znovu.',
            );
        }
        if (($a1->employment['activity_code'] ?? null) === 'N'
            && $a1->variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            && $a1->foreignInsurance === null
        ) {
            // EDV 1.4.0.6, ID 10092 a 10099: u druhu činnosti „N" je cizozemský
            // nositel pojištění povinný. Bez něj ČSSZ přihlášku odmítne.
            throw new PayrollRegistrationXmlException(
                'registration_regzec_a1_foreign_insurance_missing',
                'Přihlášení zaměstnance s druhem činnosti „N" (smluvní '
                    . 'zaměstnanec) musí nést cizozemského nositele pojištění — '
                    . 'bez něj ho ČSSZ nepřijme. V profilu registrace vyplňte '
                    . 'oddíl Cizozemský nositel pojištění (specifikace a stát) '
                    . 'a uložte ho.'
                    . PayrollRegistrationFieldVocabulary::reference('foreign_insurance'),
            );
        }
        $document = $this->document();
        $root = $this->root($document, $namespace, 'REGZEC', 'REGZEC25');
        $this->appendVendor($document, $namespace, $root, $payload);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, false);
        $client = $this->element($document, $namespace, 'client');
        $bno = $this->nullableBno($payload);
        if ($bno !== null) {
            $client->setAttribute('bno', $bno);
        } elseif ($payload->identity->identifiers['vcp'] !== null
            // EDV 1.4.0.6, ID 10060: u A1-10 je VČP zakázané, ČSSZ by
            // přihlášku zamítla. Osobu bez RČ/EČP identifikuje datum narození.
            && $a1->variant !== PayrollRegistrationBusinessMatrix::VARIANT_10
        ) {
            $client->setAttribute(
                'vcp',
                $payload->identity->identifiers['vcp'],
            );
        }
        $this->identityElements($document, $namespace, $client, $payload);
        $this->appendA1Address(
            $document,
            $namespace,
            $client,
            'adr',
            $a1->permanentAddress,
            true,
        );
        if ($a1->czechResidenceAddress !== null) {
            $this->appendA1Address(
                $document,
                $namespace,
                $client,
                'fdr',
                $a1->czechResidenceAddress,
                false,
            );
        }
        if ($a1->contactAddress !== null) {
            $this->appendA1Address(
                $document,
                $namespace,
                $client,
                'cdr',
                $a1->contactAddress,
                true,
            );
        }
        if ($a1->taxResidency !== null
            && is_array($a1->taxResidency['residence_address'])
        ) {
            $this->appendA1Address(
                $document,
                $namespace,
                $client,
                'rdr',
                $a1->taxResidency['residence_address'],
                true,
            );
        }
        if ($a1->taxResidency !== null) {
            $tax = $this->element($document, $namespace, 'taxidrezid');
            $this->setMappedAttributes($tax, $a1->taxResidency, [
                'identifier_type' => 'type',
                'identifier' => 'num',
                'country_code' => 'stat',
            ]);
            $client->appendChild($tax);
        }
        if ($a1->proofIdentity !== null) {
            $proof = $this->element($document, $namespace, 'proofid');
            $this->setMappedAttributes($proof, $a1->proofIdentity, [
                'type_code' => 'type',
                'number' => 'num',
                'foreign_issuer' => 'foreigninst',
                'country_code' => 'stat',
            ]);
            $client->appendChild($proof);
        }
        $employee->appendChild($client);
        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $comp->setAttribute('nam', (string) $payload->employerName);
        $employee->appendChild($comp);
        $job = $this->element($document, $namespace, 'job');
        $this->setMappedAttributes($job, $a1->employment, [
            'actual_start_on' => 'fro',
            'activity_code' => 'rel',
            'relationship_detail_code' => 'relDetail',
        ]);
        // Zásady REGZEC, specifický postup č. 3: vztahy druhu 10 až 16 a výkon
        // trestu vzniklé před 1. 1. 2026 se hlásí s fiktivním nástupem.
        $job->setAttribute('fro', PayrollRegistrationSpecialStartDate::reported(
            self::textOrNull($a1->employment['activity_code'] ?? null),
            self::textOrNull($a1->employment['relationship_detail_code'] ?? null),
            (string) ($a1->employment['actual_start_on'] ?? ''),
        ));
        if ($a1->variant === PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            $this->setMappedAttributes($job, $a1->employment, [
                'small_scale' => 'sme',
                'contract_start_on' => 'contractfro',
                'employment_status_code' => 'relat',
                'work_mode_code' => 'workmode',
                'continuous_operation' => 'cont',
                'prevailing_workplace_code' => 'place',
                'expected_workplaces' => 'preplace',
                'contract_workplace' => 'contractplace',
                'workplace_city' => 'cit',
                'workplace_municipality_code' => 'municode',
            ]);
            $profession = $this->element($document, $namespace, 'prof');
            $this->setMappedAttributes($profession, $a1->employment, [
                'profession_code' => 'clas',
                'required_education_code' => 'edu',
            ]);
            $job->appendChild($profession);
            $position = $this->element($document, $namespace, 'position');
            $this->setMappedAttributes($position, $a1->employment, [
                'position_name' => 'name',
                'leadership' => 'lead',
            ]);
            $job->appendChild($position);
        } elseif ($a1->variant === PayrollRegistrationBusinessMatrix::VARIANT_SPEC) {
            $this->setMappedAttributes($job, $a1->employment, [
                'expected_workplaces' => 'preplace',
                'contract_workplace' => 'contractplace',
                'workplace_city' => 'cit',
                'workplace_municipality_code' => 'municode',
            ]);
        }
        $employee->appendChild($job);
        $this->appendA1EmployeeData($document, $namespace, $employee, $a1);
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    /** @param array<string,mixed> $address */
    private function appendA1Address(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        string $name,
        array $address,
        bool $includeCountry,
    ): void {
        $node = $this->element($document, $namespace, $name);
        $mapping = [
            'street' => 'str',
            'house_number' => 'num',
            'orientation_number' => 'onum',
            'postal_code' => 'pnu',
            'city' => 'cit',
            'ruian_point' => 'ruianpoint',
        ];
        if ($includeCountry) {
            $mapping['country_code'] = 'cnt';
        }
        $this->setMappedAttributes($node, $address, $mapping);
        $client->appendChild($node);
    }

    private function appendA1EmployeeData(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        PayrollRegistrationA1Snapshot $a1,
    ): void {
        if ($a1->foreignInsurance !== null) {
            $this->appendForin($document, $namespace, $employee, $a1->foreignInsurance);
        }
        if ($a1->pension !== null) {
            $pension = $this->element($document, $namespace, 'pens');
            $this->setMappedAttributes($pension, $a1->pension, [
                'type_code' => 'typ',
                'received_from' => 'tak',
                'early_retirement' => 'early',
                'reduced_retirement_age' => 'reducedAge',
            ]);
            $employee->appendChild($pension);
        }
        if ($a1->healthInsuranceCode !== null) {
            $insurance = $this->element($document, $namespace, 'insh');
            $insurance->setAttribute('cnr', $a1->healthInsuranceCode);
            $employee->appendChild($insurance);
        }
        if ($a1->facts !== null) {
            $fact = $this->element($document, $namespace, 'fact');
            foreach ($a1->facts['health_restrictions'] as $restriction) {
                $health = $this->element($document, $namespace, 'healtrest');
                $this->setMappedAttributes($health, $restriction, [
                    'type_code' => 'type', 'from' => 'fro', 'to' => 'to',
                ]);
                $fact->appendChild($health);
            }
            $this->setMappedAttributes($fact, $a1->facts, [
                'disability_card' => 'ztp',
                'highest_education_code' => 'highedu',
            ]);
            $employee->appendChild($fact);
        }
        if ($a1->foreignWorker !== null) {
            $foreign = $this->element($document, $namespace, 'nocitizen');
            $this->setMappedAttributes($foreign, $a1->foreignWorker, [
                'free_access' => 'freeacc',
                'free_access_reason_code' => 'perm',
                'permit_type_code' => 'permtype',
                'issuing_labour_office_code' => 'issue',
                'permit_identifier' => 'permid',
                'permit_from' => 'permfro',
                'permit_to' => 'permto',
            ]);
            $employee->appendChild($foreign);
        }
        if ($a1->foreignLegislation !== null) {
            $legislation = $this->element($document, $namespace, 'forinreg');
            $this->setMappedAttributes($legislation, $a1->foreignLegislation, [
                'applies' => 'juris', 'country_code' => 'state',
            ]);
            $employee->appendChild($legislation);
        }
        if ($a1->attachments !== []) {
            $attachments = $this->element($document, $namespace, 'attachs');
            foreach ($a1->attachments as $attachment) {
                $node = $this->element($document, $namespace, 'attach');
                $this->setMappedAttributes($node, $attachment, [
                    'name' => 'name',
                    'description' => 'desc',
                    'data_base64' => 'data',
                ]);
                $attachments->appendChild($node);
            }
            $employee->appendChild($attachments);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,string> $mapping
     */
    private function setMappedAttributes(
        DOMElement $element,
        array $source,
        array $mapping,
    ): void {
        foreach ($mapping as $key => $attribute) {
            $value = $source[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'A' : 'N';
            }
            $element->setAttribute($attribute, (string) $value);
        }
    }

    private function regzecEvent(PayrollRegistrationXmlPayload $payload): string
    {
        $event = $payload->eventSnapshot;
        if (!is_array($event)) {
            throw new PayrollRegistrationXmlException(
                'registration_event_snapshot_missing',
                PayrollRegistrationFieldVocabulary::action(
                    $payload->interaction->documentType,
                    $payload->interaction->actionCode,
                ) . ' nemá uložený záznam události, ze kterého se podání '
                    . 'skládá. Otevřete oznámení u pracovního vztahu, uložte '
                    . 'ho a připravte podání znovu.',
            );
        }
        $namespace = 'http://schemas.cssz.cz/REGZEC/2025';
        $document = $this->document();
        $root = $this->root($document, $namespace, 'REGZEC', 'REGZEC25');
        $this->appendVendor($document, $namespace, $root, $payload);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, false);
        if (in_array($payload->interaction->actionCode, [3, 4, 5, 6, 7], true)) {
            $employee->setAttribute('fro', $this->eventText($event, 'effective_on'));
        }

        $client = $this->element($document, $namespace, 'client');
        $personExternal = $this->eventObject($event, 'person_external_identifier');
        $client->setAttribute('ikmpsv', $this->eventText($personExternal, 'value'));
        $data = $this->eventObject($event, 'data');
        if (in_array($payload->interaction->actionCode, [3, 4], true)) {
            $this->regzecDeltaClient(
                $document,
                $namespace,
                $client,
                $data,
                PayrollRegistrationBusinessMatrix::allowsPreviousSurnames(
                    PayrollRegistrationBusinessMatrix::requireActionVariant(
                        $payload->interaction->actionCode,
                        $this->eventText($data, 'activity_code'),
                        $this->eventNullableText($data, 'relationship_detail_code'),
                    ),
                ),
            );
        }
        $employee->appendChild($client);

        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $comp->setAttribute('nam', (string) $payload->employerName);
        if ($payload->interaction->actionCode === 5) {
            $comp->setAttribute('nvs', $this->eventText($data, 'new_variable_symbol'));
        }
        $employee->appendChild($comp);

        $job = $this->element($document, $namespace, 'job');
        $employmentExternal = $this->eventObject($event, 'employment_external_identifier');
        $job->setAttribute('oid', $this->eventText($employmentExternal, 'value'));
        $action = $payload->interaction->actionCode;
        if ($action === 2) {
            $job->setAttribute('to', $this->eventText($data, 'end_on'));
            $job->setAttribute('rel', $this->eventText($data, 'activity_code'));
            $detail = $this->regzecRelationshipDetail(
                $this->eventText($data, 'activity_code'),
                $this->eventNullableText($data, 'relationship_detail_code'),
            );
            if ($detail !== null) {
                $job->setAttribute('relDetail', $detail);
            }
            $death = $data['ended_by_death'] ?? null;
            if (is_bool($death)) {
                $job->setAttribute('endbydeath', $death ? 'A' : 'N');
            }
        } elseif (in_array($action, [3, 4], true)) {
            $delta = $this->eventObject($data, 'delta');
            $job->setAttribute('rel', $this->eventText($data, 'activity_code'));
            $detail = $delta['relationship_detail_code']
                ?? ($data['relationship_detail_code'] ?? null);
            $detail = $this->regzecRelationshipDetail(
                $this->eventText($data, 'activity_code'),
                is_string($detail) && $detail !== '' ? $detail : null,
            );
            if ($detail !== null) {
                $job->setAttribute('relDetail', $detail);
            }
            if ($action === 4 && isset($delta['contract_start_on'])) {
                $job->setAttribute('contractfro', (string) $delta['contract_start_on']);
            }
            $this->regzecDeltaJob($document, $namespace, $job, $delta);
            if ($job->hasAttribute('fro')) {
                $job->setAttribute('fro', PayrollRegistrationSpecialStartDate::reported(
                    $this->eventText($data, 'activity_code'),
                    is_string($detail) ? $detail : null,
                    $job->getAttribute('fro'),
                ));
            }
        } elseif ($action === 8) {
            $job->setAttribute('notstart', ($data['not_started'] ?? false) ? 'A' : 'N');
        }
        $employee->appendChild($job);

        if (in_array($action, [6, 7], true)
            || (in_array($action, [3, 4], true) && is_array($data['foreign_insurance'] ?? null))
        ) {
            // A3/A4 u druhu „N" nesou nositele zmrazeného z profilu A1
            // (EDV 1.4.0.6, ID 10092).
            $this->appendForeignInsurance($document, $namespace, $employee, $data);
        }
        if ($action === 2 && is_array($data['unemployment'] ?? null)) {
            $this->appendUnemployment(
                $document,
                $namespace,
                $employee,
                $data['unemployment'],
            );
        }
        if (in_array($action, [3, 4], true)) {
            $this->regzecDeltaEmployeeData(
                $document,
                $namespace,
                $employee,
                $this->eventObject($data, 'delta'),
            );
        }
        if (in_array($action, [4, 8], true) && is_array($data['explanation_attachment'] ?? null)) {
            // Storno z jiného důvodu než nenastoupení (A8) a oprava dne
            // nástupu (A4) nesou písemné vysvětlení jako přílohu (zásady
            // REGZEC, kód akce 8 a specifický postup č. 10).
            $attachments = $this->element($document, $namespace, 'attachs');
            $node = $this->element($document, $namespace, 'attach');
            $this->setMappedAttributes($node, $data['explanation_attachment'], [
                'name' => 'name',
                'description' => 'desc',
                'data_base64' => 'data',
            ]);
            $attachments->appendChild($node);
            $employee->appendChild($attachments);
        }
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    /**
     * 10502 v navazujících akcích. Starší události u dohod nesou `null`
     * (evidence ho nevede), REGZEC ho ale chce jako „1" — rozhoduje politika,
     * ne uložená hodnota.
     */
    private function regzecRelationshipDetail(
        string $activityCode,
        ?string $detail,
    ): ?string {
        try {
            return PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                $activityCode,
                $detail,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_regzec_relationship_detail_invalid',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Klientská část změny (A3) a opravy (A4) v pořadí `clientType`:
     * name, birth, stat, adr, fdr, cdr, rdr, taxidrezid, proofid.
     *
     * Delta nese buď jednotlivé změněné údaje, nebo — u dohlášení údajů
     * zaměstnance přihlášeného dřív přes ONZ — celý profil. ČSSZ přijímá
     * obojí (Všeobecné zásady REGZEC, akce 3).
     *
     * @param array<string,mixed> $data
     */
    private function regzecDeltaClient(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        array $data,
        bool $allowsPreviousSurnames,
    ): void {
        $delta = $this->eventObject($data, 'delta');
        if (isset($delta['birth_number'])) {
            $client->setAttribute('bno', (string) $delta['birth_number']);
        } elseif (isset($data['birth_number'])) {
            // EDV 1.4.0.6, ID 10057: u českého občanství povinné i v A3/A4;
            // událost ho zmrazí z karty osoby.
            $client->setAttribute('bno', (string) $data['birth_number']);
        }
        $identity = is_array($delta['identity'] ?? null) ? $delta['identity'] : [];
        $nameAttributes = array_filter([
            'sur' => $identity['last_name'] ?? null,
            'fir' => $identity['first_name'] ?? null,
            'tit' => $this->title(
                self::textOrNull($delta['title_prefix'] ?? ($identity['title_prefix'] ?? null)),
                self::textOrNull($delta['title_suffix'] ?? ($identity['title_suffix'] ?? null)),
            ),
            // Kontrakt s balíčkem E2: delta identity nese `previous_surnames`
            // (čárkou oddělený řetězec) z historie jména.
            // EDV 1.4.0.6, ID 10064: u varianty 10 (A3-10, A4-10) se dřívější
            // příjmení neuvádí.
            'ona' => $allowsPreviousSurnames
                ? $this->previousSurnames(
                    self::textOrNull($identity['previous_surnames'] ?? null),
                )
                : null,
        ], static fn (mixed $value): bool => $value !== null);
        if ($nameAttributes !== []) {
            $name = $this->element($document, $namespace, 'name');
            foreach ($nameAttributes as $attribute => $value) {
                $name->setAttribute($attribute, (string) $value);
            }
            $client->appendChild($name);
        }
        if (isset($identity['birth_date'])) {
            $birth = $this->element($document, $namespace, 'birth');
            $birth->setAttribute('dat', (string) $identity['birth_date']);
            $client->appendChild($birth);
        }
        if (isset($identity['sex']) || isset($identity['citizenship_country_code'])) {
            $stat = $this->element($document, $namespace, 'stat');
            if (isset($identity['sex'])) {
                $stat->setAttribute('mal', match ($identity['sex']) {
                    'male' => 'M',
                    'female' => 'Ž',
                    default => throw new PayrollRegistrationXmlException(
                        'registration_identity_invalid',
                        PayrollRegistrationFieldVocabulary::label('sex')
                            . ' musí být muž, nebo žena — ČSSZ jinou hodnotu '
                            . 'nepřijímá. '
                            . PayrollRegistrationFieldVocabulary::describe('sex'),
                    ),
                });
            }
            if (isset($identity['citizenship_country_code'])) {
                $stat->setAttribute('cnt', (string) $identity['citizenship_country_code']);
            }
            $client->appendChild($stat);
        }
        foreach ([
            'permanent_address' => ['adr', true],
            'czech_residence_address' => ['fdr', false],
            'contact_address' => ['cdr', true],
        ] as $key => [$element, $withCountry]) {
            if (is_array($delta[$key] ?? null)) {
                $this->appendDeltaAddress(
                    $document,
                    $namespace,
                    $client,
                    $element,
                    $delta[$key],
                    $withCountry,
                );
            }
        }
        $residency = is_array($delta['tax_residency'] ?? null)
            ? $delta['tax_residency']
            : null;
        if (is_array($residency['residence_address'] ?? null)) {
            $this->appendDeltaAddress(
                $document,
                $namespace,
                $client,
                'rdr',
                $residency['residence_address'],
                true,
            );
        }
        if ($residency !== null) {
            $node = $this->element($document, $namespace, 'taxidrezid');
            $node->setAttribute('stat', (string) $residency['country_code']);
            $node->setAttribute('statchang', (string) $residency['changed_on']);
            $this->setMappedAttributes($node, $residency, [
                'identifier_type' => 'type',
                'identifier' => 'num',
            ]);
            $client->appendChild($node);
        }
        if (is_array($delta['proof_identity'] ?? null)) {
            $proof = $this->element($document, $namespace, 'proofid');
            $this->setMappedAttributes($proof, $delta['proof_identity'], [
                'type_code' => 'type',
                'number' => 'num',
                'foreign_issuer' => 'foreigninst',
                'country_code' => 'stat',
            ]);
            $client->appendChild($proof);
        }
    }

    /** @param array<string,mixed> $address */
    private function appendDeltaAddress(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        string $element,
        array $address,
        bool $withCountry,
    ): void {
        $node = $this->element($document, $namespace, $element);
        $mapping = [
            'street' => 'str', 'house_number' => 'num',
            'orientation_number' => 'onum', 'postal_code' => 'pnu',
            'city' => 'cit',
        ];
        if ($withCountry) {
            $mapping['country_code'] = 'cnt';
        }
        $mapping['ruian_point'] = 'ruianpoint';
        foreach ($mapping as $key => $attribute) {
            if (isset($address[$key])) {
                $node->setAttribute($attribute, (string) $address[$key]);
            }
        }
        $client->appendChild($node);
    }

    /**
     * Pracovní část změny: atributy `job` a vnořené `prof` a `position`.
     *
     * @param array<string,mixed> $delta
     */
    private function regzecDeltaJob(
        DOMDocument $document,
        string $namespace,
        DOMElement $job,
        array $delta,
    ): void {
        $employment = is_array($delta['employment'] ?? null)
            ? $delta['employment']
            : null;
        if ($employment === null) {
            return;
        }
        $this->setMappedAttributes($job, $employment, [
            'actual_start_on' => 'fro',
            'end_on' => 'to',
            'contract_start_on' => 'contractfro',
            'employment_status_code' => 'relat',
            'work_mode_code' => 'workmode',
            'continuous_operation' => 'cont',
            'prevailing_workplace_code' => 'place',
            'expected_workplaces' => 'preplace',
            'contract_workplace' => 'contractplace',
            'workplace_city' => 'cit',
            'workplace_municipality_code' => 'municode',
        ]);
        if (isset($employment['profession_code'])
            || isset($employment['required_education_code'])
        ) {
            $profession = $this->element($document, $namespace, 'prof');
            $this->setMappedAttributes($profession, $employment, [
                'profession_code' => 'clas',
                'required_education_code' => 'edu',
            ]);
            $job->appendChild($profession);
        }
        if (isset($employment['position_name']) || isset($employment['leadership'])) {
            $position = $this->element($document, $namespace, 'position');
            $this->setMappedAttributes($position, $employment, [
                'position_name' => 'name',
                'leadership' => 'lead',
            ]);
            $job->appendChild($position);
        }
    }

    /**
     * Části změny za `job` v pořadí `employeeType`: pens, insh, fact,
     * nocitizen, forinreg.
     *
     * @param array<string,mixed> $delta
     */
    private function regzecDeltaEmployeeData(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        array $delta,
    ): void {
        if (is_array($delta['pension'] ?? null)) {
            $pension = $this->element($document, $namespace, 'pens');
            $this->setMappedAttributes($pension, $delta['pension'], [
                'type_code' => 'typ',
                'received_from' => 'tak',
                'early_retirement' => 'early',
                'reduced_retirement_age' => 'reducedAge',
            ]);
            $employee->appendChild($pension);
        }
        if (isset($delta['health_insurance_code'])) {
            $insurance = $this->element($document, $namespace, 'insh');
            $insurance->setAttribute('cnr', (string) $delta['health_insurance_code']);
            $employee->appendChild($insurance);
        }
        $facts = is_array($delta['facts'] ?? null) ? $delta['facts'] : [];
        if (isset($delta['highest_education_code'])) {
            $facts['highest_education_code'] = $delta['highest_education_code'];
        }
        if ($facts !== []) {
            $fact = $this->element($document, $namespace, 'fact');
            foreach ($facts['health_restrictions'] ?? [] as $restriction) {
                if (!is_array($restriction)) {
                    continue;
                }
                $health = $this->element($document, $namespace, 'healtrest');
                $this->setMappedAttributes($health, $restriction, [
                    'type_code' => 'type', 'from' => 'fro', 'to' => 'to',
                ]);
                $fact->appendChild($health);
            }
            $this->setMappedAttributes($fact, $facts, [
                'disability_card' => 'ztp',
                'highest_education_code' => 'highedu',
            ]);
            $employee->appendChild($fact);
        }
        if (is_array($delta['foreign_worker'] ?? null)) {
            $foreign = $this->element($document, $namespace, 'nocitizen');
            $this->setMappedAttributes($foreign, $delta['foreign_worker'], [
                'free_access' => 'freeacc',
                'free_access_reason_code' => 'perm',
                'permit_type_code' => 'permtype',
                'issuing_labour_office_code' => 'issue',
                'permit_identifier' => 'permid',
                'permit_from' => 'permfro',
                'permit_to' => 'permto',
            ]);
            $employee->appendChild($foreign);
        }
        if (is_array($delta['foreign_legislation'] ?? null)) {
            $legislation = $this->element($document, $namespace, 'forinreg');
            $this->setMappedAttributes($legislation, $delta['foreign_legislation'], [
                'applies' => 'juris', 'country_code' => 'state',
            ]);
            $employee->appendChild($legislation);
        }
    }

    /** @param array<string,mixed> $data */
    private function appendForeignInsurance(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        array $data,
    ): void {
        $this->appendForin(
            $document,
            $namespace,
            $employee,
            $this->eventObject($data, 'foreign_insurance'),
        );
    }

    /**
     * `forin` (cizozemský nositel pojištění) je společný pro A1 (profil),
     * A6 a A7 (událost); v `employeeType` stojí hned za `job`.
     *
     * @param array<string,mixed> $foreign
     */
    private function appendForin(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        array $foreign,
    ): void {
        $node = $this->element($document, $namespace, 'forin');
        foreach ([
            'current' => 'cur', 'name' => 'nam', 'street' => 'str',
            'house_number' => 'num', 'orientation_number' => 'onum',
            'postal_code' => 'pnu', 'city' => 'cit',
            'country_code' => 'cnt', 'identifier' => 'id', 'sector' => 'sec',
        ] as $key => $attribute) {
            if (isset($foreign[$key])) {
                $node->setAttribute($attribute, (string) $foreign[$key]);
            }
        }
        $employee->appendChild($node);
    }

    /** @param array<string,mixed> $data */
    private function appendUnemployment(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        array $data,
    ): void {
        $node = $this->element($document, $namespace, 'unemplcomp');
        foreach ([
            'reason_not_provided' => 'rsn', 'employment_type' => 'typeempl',
            'entitlement' => 'belong', 'paid_in_full' => 'fullpay',
            'average_net_earnings' => 'avgmonear',
            'service_termination_reason' => 'rsnterrel',
            'termination_reason' => 'rsnterempl',
            'replacement' => 'replacement', 'golden_handshake' => 'goldenhandshake',
            'severance_pay' => 'severancepay', 'disposal' => 'disposal',
            'early_termination_reason' => 'earlyterm',
        ] as $key => $attribute) {
            if (!isset($data[$key])) {
                continue;
            }
            if (in_array($attribute, self::ZERO_FORBIDDEN_ATTRIBUTES, true)
                && (string) $data[$key] === '0'
            ) {
                continue;
            }
            $node->setAttribute($attribute, (string) $data[$key]);
        }
        foreach ($data['pension_periods'] ?? [] as $period) {
            if (!is_array($period)) {
                continue;
            }
            $child = $this->element($document, $namespace, 'pensionperiod');
            $child->setAttribute('fro', (string) ($period['from'] ?? ''));
            $child->setAttribute('to', (string) ($period['to'] ?? ''));
            $node->appendChild($child);
        }
        $employee->appendChild($node);
    }

    /**
     * `VENDOR` — identifikace odesílajícího programu.
     *
     * PREZEC26 i REGZEC25 ji mají hned prvním prvkem kořene, stejně jako
     * měsíční hlášení, příloha k žádosti o dávku i hlášení zaměstnavatele
     * o ukončení pojištění. Registrace byly jediné podání, kde se
     * nevyplňovala. Prvek je nepovinný, takže chybějící identifikace podání
     * neblokuje — jen se nezapíše.
     */
    private function appendVendor(
        DOMDocument $document,
        string $namespace,
        DOMElement $root,
        PayrollRegistrationXmlPayload $payload,
    ): void {
        if ($payload->productName === null || $payload->productVersion === null) {
            return;
        }
        $vendor = $this->element($document, $namespace, 'VENDOR');
        $vendor->setAttribute('productName', $payload->productName);
        $vendor->setAttribute('productVersion', $payload->productVersion);
        $root->appendChild($vendor);
    }

    private function employeeAttributes(
        DOMElement $employee,
        PayrollRegistrationXmlPayload $payload,
        bool $prezec,
    ): void {
        $employee->setAttribute('sqnr', (string) $payload->sequenceNumber);
        if (!$prezec) {
            $employee->setAttribute('dep', (string) $payload->csszWorkplaceCode);
        }
        $employee->setAttribute(
            'act',
            (string) $payload->interaction->actionCode,
        );
        if ($prezec) {
            $employee->setAttribute('idform', $this->prezecFormGuid($payload));
        }
        $employee->setAttribute('dat', $payload->preparedOn);
        if ($prezec && $payload->interaction->actionCode === 9) {
            $employee->setAttribute(
                'predat',
                (string) $payload->expectedStartOn,
            );
        }
    }

    /**
     * P1 nese vlastní GUID, P2 musí nést GUID původní přijaté P1 (PREZEC
     * Předregistrace 1.4, body 9 a 11, atribut 10012). P2 s čerstvým GUID
     * ČSSZ nespáruje a předregistrace zůstane otevřená, proto bez reference
     * soubor nevznikne.
     */
    private function prezecFormGuid(PayrollRegistrationXmlPayload $payload): string
    {
        if ($payload->interaction->actionCode !== 10) {
            return $payload->formGuid;
        }
        if ($payload->referencedFormGuid === null
            || $payload->referencedFormGuid === ''
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_prezec_p1_guid_missing',
                'Oznámení o nenastoupení (PREZEC P2) musí odkazovat na GUID '
                    . 'původního přijatého částečného přihlášení (P1). Bez '
                    . 'něj ho ČSSZ nezpracuje a předregistrace zůstane '
                    . 'otevřená.',
            );
        }

        return $payload->referencedFormGuid;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function eventObject(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new PayrollRegistrationXmlException(
                'registration_event_snapshot_invalid',
                PayrollRegistrationFieldVocabulary::label($key)
                    . ' v uloženém oznámení chybí. Otevřete oznámení, '
                    . 'zkontrolujte údaje a připravte podání znovu.'
                    . PayrollRegistrationFieldVocabulary::reference($key),
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $source */
    private function eventText(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PayrollRegistrationXmlException(
                'registration_event_snapshot_invalid',
                PayrollRegistrationFieldVocabulary::label($key)
                    . ' v uloženém oznámení chybí. '
                    . PayrollRegistrationFieldVocabulary::describe($key)
                    . PayrollRegistrationFieldVocabulary::reference($key),
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $source */
    private function eventNullableText(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        return $this->eventText($source, $key);
    }

    private function identityElements(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        PayrollRegistrationXmlPayload $payload,
    ): void {
        $identity = $payload->identity->identity;
        $name = $this->element($document, $namespace, 'name');
        $name->setAttribute(
            'sur',
            $this->requiredIdentityString($identity, 'last_name'),
        );
        $name->setAttribute(
            'fir',
            $this->requiredIdentityString($identity, 'first_name'),
        );
        // Tituly a dřívější příjmení nese jen REGZEC; PREZEC26 pro ně atributy
        // nemá (`nameType` v PREZEC26 1.2 má jen `sur` a `fir`).
        if ($payload->interaction->documentType === 'REGZEC25') {
            $title = $this->title(
                $this->nullableIdentityString($identity, 'title_prefix'),
                $this->nullableIdentityString($identity, 'title_suffix'),
            );
            if ($title !== null) {
                $name->setAttribute('tit', $title);
            }
            // EDV 1.4.0.6, ID 10064: u varianty 10 se dřívější příjmení
            // neuvádí (v matici „/") a ČSSZ podání s ním zamítne.
            $a1 = $payload->identity->regzecA1;
            $previous = $a1 !== null
                && !PayrollRegistrationBusinessMatrix::allowsPreviousSurnames($a1->variant)
                ? null
                : $this->previousSurnames(
                    $this->nullableIdentityString($identity, 'previous_surnames'),
                );
            if ($previous !== null) {
                $name->setAttribute('ona', $previous);
            }
        }
        $client->appendChild($name);
        $birth = $this->element($document, $namespace, 'birth');
        $birthDate = $this->nullableIdentityString(
            $identity,
            'birth_date',
        );
        if ($payload->interaction->documentType === 'REGZEC25'
            && $birthDate !== null
        ) {
            $birth->setAttribute('dat', $birthDate);
        }
        $birth->setAttribute(
            'nam',
            $this->requiredIdentityString($identity, 'birth_surname'),
        );
        $birthPlace = $this->requiredIdentityString($identity, 'birth_place');
        $birthCountry = $this->nullableIdentityString(
            $identity,
            'birth_country_code',
        );
        $birth->setAttribute(
            'cit',
            $payload->interaction->documentType === 'PREZEC26'
                // PREZEC nemá atribut pro stát narození, ten se píše za obec.
                ? PayrollRegistrationBirthPlace::forPrezec(
                    $birthPlace,
                    $this->requiredIdentityString($identity, 'birth_country_code'),
                    $payload->preparedOn,
                )
                : PayrollRegistrationBirthPlace::forRegzec($birthPlace),
        );
        if ($payload->interaction->documentType === 'REGZEC25'
            && $birthCountry !== null
        ) {
            $birth->setAttribute('stat', $birthCountry);
        }
        $client->appendChild($birth);
        $stat = $this->element($document, $namespace, 'stat');
        if ($payload->interaction->documentType === 'REGZEC25') {
            $sex = $this->requiredIdentityString($identity, 'sex');
            $stat->setAttribute('mal', match ($sex) {
                'male' => 'M',
                'female' => 'Ž',
                default => throw new PayrollRegistrationXmlException(
                    'registration_identity_invalid',
                    PayrollRegistrationFieldVocabulary::label('sex')
                        . ' musí být muž, nebo žena — ČSSZ jinou hodnotu '
                        . 'nepřijímá. '
                        . PayrollRegistrationFieldVocabulary::describe('sex'),
                ),
            });
        }
        $stat->setAttribute(
            'cnt',
            $this->requiredIdentityString(
                $identity,
                'citizenship_country_code',
            ),
        );
        $client->appendChild($stat);
    }

    /**
     * Titul (ID 10055, nejvýš 30 znaků): „všechny tituly v pořadí před a za
     * jménem", oddělené mezerou. Evidence drží tituly zvlášť, ČSSZ je chce
     * v jednom atributu.
     */
    private function title(?string $prefix, ?string $suffix): ?string
    {
        $parts = array_values(array_filter(
            [$prefix, $suffix],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));
        if ($parts === []) {
            return null;
        }
        $title = implode(' ', array_map('trim', $parts));
        if (mb_strlen($title) > 30) {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label('title_prefix')
                    . ' a ' . mb_lcfirst(PayrollRegistrationFieldVocabulary::label('title_suffix'))
                    . ' dohromady („' . $title . '") jsou delší, než ČSSZ přijme: '
                    . 'nejvýš 30 znaků, teď jich je ' . mb_strlen($title)
                    . '. Zkraťte tituly nebo ponechte jen ty, které ČSSZ potřebuje. '
                    . PayrollRegistrationFieldVocabulary::describe('title_prefix'),
            );
        }

        return $title;
    }

    private static function textOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Dřívější příjmení (ID 10064): čárkou oddělený seznam, nejvýš 100 znaků. */
    private function previousSurnames(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (mb_strlen($value) > 100) {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label('previous_surnames')
                    . ' jsou dohromady delší, než ČSSZ přijme: nejvýš 100 znaků, '
                    . 'teď jich je ' . mb_strlen($value) . '. '
                    . PayrollRegistrationFieldVocabulary::describe('previous_surnames'),
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function requiredIdentityString(
        array $identity,
        string $key,
    ): string {
        $value = $identity[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label($key)
                    . ' chybí v evidenci osoby, takže podání nejde vyplnit. '
                    . PayrollRegistrationFieldVocabulary::describe($key)
                    . PayrollRegistrationFieldVocabulary::reference($key),
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function nullableIdentityString(
        array $identity,
        string $key,
    ): ?string {
        $value = $identity[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new PayrollRegistrationXmlException(
                'registration_identity_invalid',
                PayrollRegistrationFieldVocabulary::label($key)
                    . ' je v evidenci osoby uložený prázdný. Buď ho doplňte, '
                    . 'nebo ho nechte úplně nevyplněný — prázdná hodnota '
                    . 'ČSSZ neprojde. '
                    . PayrollRegistrationFieldVocabulary::describe($key)
                    . PayrollRegistrationFieldVocabulary::reference($key),
            );
        }

        return $value;
    }

    /**
     * `client/@bno` je v obou připnutých schématech popsané jako
     * „Rodné číslo / EČP" (PREZEC26 ID 10057, REGZEC25 ID 10057/10058)
     * a `t:simpleNNType` délky 9–10 pojme obojí. Jediný zdroj pravdy pro
     * tuhle dvojici je `PayrollRegistrationIdentitySnapshotBuilder`, který
     * pro PREZEC26 vyžaduje rodné číslo NEBO EČP; serializér ho jen čte,
     * aby se obě vrstvy nerozešly.
     */
    private function bno(PayrollRegistrationXmlPayload $payload): string
    {
        $value = $this->nullableBno($payload);
        if ($value === null) {
            throw new PayrollRegistrationXmlException(
                'registration_prezec_identifier_required',
                'Rodné číslo ani evidenční číslo pojištěnce (EČP) nejsou '
                    . 'vyplněné. Částečné přihlášení před nástupem (PREZEC) '
                    . 'se bez jednoho z nich podat nedá — rodné číslo má 9 '
                    . 'nebo 10 číslic bez lomítka. Údaj doplňte na '
                    . PayrollRegistrationFieldVocabulary::WHERE_IDENTIFIERS
                    . '; pokud zaměstnanec ani jedno nemá, podejte místo toho '
                    . 'plnou registraci REGZEC.',
            );
        }

        return $value;
    }

    private function nullableBno(
        PayrollRegistrationXmlPayload $payload,
    ): ?string {
        return $payload->identity->identifiers['birth_number']
            ?? $payload->identity->identifiers['ecp'];
    }

    private function document(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        return $document;
    }

    private function element(
        DOMDocument $document,
        string $namespace,
        string $name,
    ): DOMElement {
        return $document->createElementNS($namespace, $name);
    }

    /**
     * Kořen věty i s atributy podle {@see self::ROOT_ATTRIBUTES}. Kdyby si je
     * každá ze tří větví stavěla sama, chybí zase jen v jedné z nich.
     */
    private function root(
        DOMDocument $document,
        string $namespace,
        string $name,
        string $documentType,
    ): DOMElement {
        $root = $document->createElementNS($namespace, $name);
        foreach (self::ROOT_ATTRIBUTES[$documentType] as $attribute => $value) {
            $root->setAttribute($attribute, $value);
        }
        $document->appendChild($root);

        return $root;
    }

    private function save(DOMDocument $document): string
    {
        $xml = $document->saveXML();
        if ($xml === false) {
            // Selhání rozšíření DOM, ne vstupu od uživatele.
            throw new \RuntimeException(
                'Soubor pro ČSSZ se nepodařilo vytvořit. Zkuste podání '
                    . 'připravit znovu; pokud to bude padat dál, obraťte se '
                    . 'na podporu.',
            );
        }

        return rtrim($xml, "\r\n");
    }
}
