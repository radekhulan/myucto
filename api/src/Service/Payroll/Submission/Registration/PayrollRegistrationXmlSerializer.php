<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;

final class PayrollRegistrationXmlSerializer
{
    public function serialize(PayrollRegistrationXmlPayload $payload): string
    {
        if (!$payload->interaction->supported()) {
            throw new PayrollRegistrationXmlException(
                'registration_interaction_unsupported',
                'Registrační interakce není v podporovaném katalogu.',
            );
        }
        if ($payload->interaction->documentType === 'REGZEC25'
            && $payload->interaction->actionCode === 1
        ) {
            PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                null,
                null,
                false,
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
        }

        return match ($payload->interaction->documentType) {
            'PREZEC26' => $this->prezec($payload),
            'REGZEC25' => $this->regzec($payload),
            default => throw new PayrollRegistrationXmlException(
                'registration_serializer_unavailable',
                'Požadovaný registrační formulář nemá serializér.',
            ),
        };
    }

    private function prezec(PayrollRegistrationXmlPayload $payload): string
    {
        $namespace = 'http://schemas.cssz.cz/PREZEC/2026';
        $document = $this->document();
        $root = $document->createElementNS($namespace, 'PREZEC');
        $document->appendChild($root);
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
        $document = $this->document();
        $root = $document->createElementNS($namespace, 'REGZEC');
        $document->appendChild($root);
        $employees = $this->element($document, $namespace, 'employees');
        $employee = $this->element($document, $namespace, 'employee');
        $this->employeeAttributes($employee, $payload, false);
        $client = $this->element($document, $namespace, 'client');
        $bno = $this->nullableBno($payload);
        if ($bno !== null) {
            $client->setAttribute('bno', $bno);
        } elseif ($payload->identity->identifiers['vcp'] !== null) {
            $client->setAttribute(
                'vcp',
                $payload->identity->identifiers['vcp'],
            );
        }
        $this->identityElements($document, $namespace, $client, $payload);
        $employee->appendChild($client);
        $comp = $this->element($document, $namespace, 'comp');
        $comp->setAttribute('vs', $payload->employerVariableSymbol);
        $comp->setAttribute('nam', (string) $payload->employerName);
        $employee->appendChild($comp);
        $job = $this->element($document, $namespace, 'job');
        $job->setAttribute('fro', (string) $payload->actualStartOn);
        $employee->appendChild($job);
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    private function regzecEvent(PayrollRegistrationXmlPayload $payload): string
    {
        $event = $payload->eventSnapshot;
        if (!is_array($event)) {
            throw new PayrollRegistrationXmlException(
                'registration_event_snapshot_missing',
                'REGZEC A2–A8 vyžaduje neměnný zdroj události.',
            );
        }
        $namespace = 'http://schemas.cssz.cz/REGZEC/2025';
        $document = $this->document();
        $root = $document->createElementNS($namespace, 'REGZEC');
        $document->appendChild($root);
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
            $this->regzecDeltaClient($document, $namespace, $client, $data);
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
            $detail = $this->eventNullableText($data, 'relationship_detail_code');
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
            if (is_string($detail) && $detail !== '') {
                $job->setAttribute('relDetail', $detail);
            }
            if ($action === 4 && isset($delta['contract_start_on'])) {
                $job->setAttribute('contractfro', (string) $delta['contract_start_on']);
            }
        } elseif ($action === 8) {
            $job->setAttribute('notstart', ($data['not_started'] ?? false) ? 'A' : 'N');
        }
        $employee->appendChild($job);

        if (in_array($action, [6, 7], true)) {
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
        if ($action === 3) {
            $delta = $this->eventObject($data, 'delta');
            if (isset($delta['health_insurance_code'])) {
                $insurance = $this->element($document, $namespace, 'insh');
                $insurance->setAttribute('cnr', (string) $delta['health_insurance_code']);
                $employee->appendChild($insurance);
            }
        }
        if ($action === 4) {
            $delta = $this->eventObject($data, 'delta');
            if (isset($delta['highest_education_code'])) {
                $fact = $this->element($document, $namespace, 'fact');
                $fact->setAttribute('highedu', (string) $delta['highest_education_code']);
                $employee->appendChild($fact);
            }
        }
        $employees->appendChild($employee);
        $root->appendChild($employees);

        return $this->save($document);
    }

    /** @param array<string,mixed> $data */
    private function regzecDeltaClient(
        DOMDocument $document,
        string $namespace,
        DOMElement $client,
        array $data,
    ): void {
        $delta = $this->eventObject($data, 'delta');
        if (isset($delta['title_prefix'])) {
            $name = $this->element($document, $namespace, 'name');
            $name->setAttribute('tit', (string) $delta['title_prefix']);
            $client->appendChild($name);
        }
        if (is_array($delta['contact_address'] ?? null)) {
            $address = $delta['contact_address'];
            $node = $this->element($document, $namespace, 'cdr');
            foreach ([
                'street' => 'str', 'house_number' => 'num',
                'orientation_number' => 'onum', 'postal_code' => 'pnu',
                'city' => 'cit', 'country_code' => 'cnt',
                'ruian_point' => 'ruianpoint',
            ] as $key => $attribute) {
                if (isset($address[$key])) {
                    $node->setAttribute($attribute, (string) $address[$key]);
                }
            }
            $client->appendChild($node);
        }
        if (is_array($delta['tax_residency'] ?? null)) {
            $residency = $delta['tax_residency'];
            $node = $this->element($document, $namespace, 'taxidrezid');
            $node->setAttribute('stat', (string) $residency['country_code']);
            $node->setAttribute('statchang', (string) $residency['changed_on']);
            $client->appendChild($node);
        }
    }

    /** @param array<string,mixed> $data */
    private function appendForeignInsurance(
        DOMDocument $document,
        string $namespace,
        DOMElement $employee,
        array $data,
    ): void {
        $foreign = $this->eventObject($data, 'foreign_insurance');
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
            if (isset($data[$key])) {
                $node->setAttribute($attribute, (string) $data[$key]);
            }
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
            $employee->setAttribute('idform', $payload->formGuid);
        }
        $employee->setAttribute('dat', $payload->preparedOn);
        if ($prezec && $payload->interaction->actionCode === 9) {
            $employee->setAttribute(
                'predat',
                (string) $payload->expectedStartOn,
            );
        }
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function eventObject(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new PayrollRegistrationXmlException(
                'registration_event_snapshot_invalid',
                "Neměnný zdroj REGZEC nemá objekt {$key}.",
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
                "Neměnný zdroj REGZEC nemá text {$key}.",
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
        $title = $this->nullableIdentityString($identity, 'title_prefix');
        if ($payload->interaction->documentType === 'REGZEC25'
            && $title !== null
        ) {
            $name->setAttribute('tit', $title);
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
        $birth->setAttribute(
            'cit',
            $this->requiredIdentityString($identity, 'birth_place'),
        );
        $birthCountry = $this->nullableIdentityString(
            $identity,
            'birth_country_code',
        );
        if ($payload->interaction->documentType === 'REGZEC25'
            && $birthCountry !== null
        ) {
            $birth->setAttribute('stat', $birthCountry);
        }
        $client->appendChild($birth);
        $stat = $this->element($document, $namespace, 'stat');
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
                "Registrační identita nemá platné pole {$key}.",
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
                "Registrační identita nemá platné pole {$key}.",
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
                'PREZEC vyžaduje přidělené rodné číslo nebo EČP; bez osobního identifikátoru nelze částečné přihlášení podat.',
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

    private function save(DOMDocument $document): string
    {
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Registrační XML nelze serializovat.');
        }

        return rtrim($xml, "\r\n");
    }
}
