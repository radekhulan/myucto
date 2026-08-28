<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\PayrollVcp;

final class PayrollRegistrationIdentitySnapshotBuilder
{
    private const IDENTIFIER_TYPES = [
        'birth_number',
        'ecp',
        'vcp',
        'foreign_tax_identifier',
    ];

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $source
     */
    public function build(
        array $scope,
        array $source,
    ): PayrollRegistrationIdentitySnapshot {
        $normalizedScope = $this->scope($scope);
        $identity = $this->identity(
            $this->object($source['identity'] ?? null, 'identity'),
            $normalizedScope,
        );
        $identifiers = $this->identifiers(
            $this->object($source['identifiers'] ?? null, 'identifiers'),
        );
        $this->validateIdentifierFacets($identifiers);
        $identifierSources = $this->identifierSources(
            $this->object(
                $source['identifier_sources'] ?? null,
                'identifier_sources',
            ),
            $identifiers,
        );
        $external = $this->externalIdentifier(
            $source['employment_external_identifier'] ?? null,
            $normalizedScope,
        );
        $resolution = $this->object(
            $source['resolution'] ?? null,
            'resolution',
        );
        if (($resolution['person_identity'] ?? null) !== 'resolved'
            || !in_array(
                $resolution['employment_external_id'] ?? null,
                ['resolved', 'not_assigned'],
                true,
            )
        ) {
            $this->invalid(
                'registration_identity_unresolved',
                'Registrační identita obsahuje nevyřešený identitní úkol.',
            );
        }
        if (($external === null)
            !== ($resolution['employment_external_id'] === 'not_assigned')
        ) {
            $this->invalid(
                'registration_identity_unresolved',
                'Stav ztotožnění ID PPV neodpovídá zmrazenému zdroji.',
            );
        }

        if ($normalizedScope['agenda_code'] === 'PREZEC26'
            && $identifiers['birth_number'] === null
            && $identifiers['ecp'] === null
        ) {
            $this->invalid(
                'registration_identity_prezec_bno_missing',
                'PREZEC vyžaduje rodné číslo nebo EČP; rodné číslo samo o sobě povinné není.',
            );
        }
        if ($normalizedScope['agenda_code'] === 'REGZEC25'
            && !$this->hasRegistrationIdentifier($identifiers)
            && !$this->hasAlternativeForeignIdentity($identity)
        ) {
            $this->invalid(
                'registration_identity_regzec_identity_incomplete',
                'REGZEC nemá osobní identifikátor ani úplnou zahraniční identitu.',
            );
        }
        $registrationEligibility = $this->registrationEligibility(
            $normalizedScope['agenda_code'],
            $identity,
        );
        $regzecA1 = null;
        if ($normalizedScope['agenda_code'] === 'REGZEC25'
            && array_key_exists('regzec_a1', $source)
        ) {
            $regzecA1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
                $this->object($source['regzec_a1'], 'regzec_a1'),
                $identity,
                $normalizedScope,
            );
        }

        $sourceVersions = [
            'identity' => [
                'id' => $identity['source_identity_id'],
                'row_version' => $identity['source_row_version'],
            ],
            'identifiers' => $identifierSources,
            'employment_external_identifier' => $external === null
                ? null
                : [
                    'id' => $external['source_external_id'],
                    'row_version' => $external['source_row_version'],
                    'source_reference_hash' =>
                        $external['source_reference_hash'],
                ],
        ];
        if ($regzecA1 !== null) {
            $sourceVersions['regzec_a1'] = $regzecA1->source;
        }

        return new PayrollRegistrationIdentitySnapshot(
            $normalizedScope,
            $identity,
            $identifiers,
            $external,
            $registrationEligibility,
            $sourceVersions,
            $regzecA1,
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array{
     *   supplier_id:int,submission_id:int,source_revision_id:?int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * }
     */
    private function scope(array $source): array
    {
        $environment = $this->text($source, 'environment', 16);
        if (!in_array($environment, ['production', 'test'], true)) {
            $this->invalid(
                'registration_identity_environment_invalid',
                'Prostředí registrační identity není platné.',
            );
        }
        $agenda = $this->text($source, 'agenda_code', 48);
        if (!in_array($agenda, ['PREZEC26', 'REGZEC25'], true)) {
            $this->invalid(
                'registration_identity_agenda_invalid',
                'Snapshot identity podporuje pouze PREZEC26 a REGZEC25.',
            );
        }

        return [
            'supplier_id' => $this->positive($source, 'supplier_id'),
            'submission_id' => $this->positive($source, 'submission_id'),
            // Registrace pracovního vztahu NEVZNIKÁ z mzdového běhu: přihláška
            // se podává před zahájením práce, tedy dřív, než vůbec může
            // existovat revize běhu. Vynucovat tu kladné číslo by znamenalo
            // buď registraci odložit za první běh (a zmeškat zákonnou lhůtu),
            // nebo do zmrazeného snapshotu zapsat vymyšlenou revizi. JMHZ
            // cestu to nemění — ta posílá skutečné id dál, takže kanonické
            // JSON i otisky už zmrazených snapshotů zůstávají beze změny.
            'source_revision_id' =>
                $this->optionalPositive($source, 'source_revision_id'),
            'employee_id' => $this->positive($source, 'employee_id'),
            'employment_id' => $this->positive($source, 'employment_id'),
            'environment' => $environment,
            'agenda_code' => $agenda,
            'effective_on' => $this->date(
                $source['effective_on'] ?? null,
                'scope.effective_on',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array{
     *   supplier_id:int,submission_id:int,source_revision_id:int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * } $scope
     * @return array<string,mixed>
     */
    private function identity(array $source, array $scope): array
    {
        if ($this->positive($source, 'employee_id') !== $scope['employee_id']) {
            $this->invalid(
                'registration_identity_person_scope_mismatch',
                'Historická identita patří jiné osobě.',
            );
        }
        $effectiveFrom = $this->date(
            $source['effective_from'] ?? null,
            'identity.effective_from',
        );
        $effectiveTo = $this->nullableDate(
            $source['effective_to'] ?? null,
            'identity.effective_to',
        );
        if ($effectiveFrom > $scope['effective_on']
            || ($effectiveTo !== null
                && $effectiveTo < $scope['effective_on'])
        ) {
            $this->invalid(
                'registration_identity_person_scope_mismatch',
                'Historická identita není účinná k rozhodnému datu.',
            );
        }
        $firstName = $this->text($source, 'first_name', 191);
        $lastName = $this->text($source, 'last_name', 191);
        $birthDate = $this->nullableDate(
            $source['birth_date'] ?? null,
            'identity.birth_date',
        );
        $birthCountry = $this->nullableCountry(
            $source['birth_country_code'] ?? null,
            'identity.birth_country_code',
        );
        $citizenship = $this->nullableCountry(
            $source['citizenship_country_code'] ?? null,
            'identity.citizenship_country_code',
        );
        $sex = $this->nullableText($source['sex'] ?? null, 16, 'identity.sex');
        if ($sex !== null
            && !in_array($sex, ['female', 'male', 'unspecified'], true)
        ) {
            $this->invalid(
                'registration_identity_person_invalid',
                'Pohlaví v historické identitě není platné.',
            );
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'title_prefix' => $this->nullableText(
                $source['title_prefix'] ?? null,
                64,
                'identity.title_prefix',
            ),
            'title_suffix' => $this->nullableText(
                $source['title_suffix'] ?? null,
                64,
                'identity.title_suffix',
            ),
            'birth_surname' => $this->nullableText(
                $source['birth_surname'] ?? null,
                191,
                'identity.birth_surname',
            ),
            'birth_date' => $birthDate,
            'birth_place' => $this->nullableText(
                $source['birth_place'] ?? null,
                128,
                'identity.birth_place',
            ),
            'birth_country_code' => $birthCountry,
            'citizenship_country_code' => $citizenship,
            'sex' => $sex,
            'source_identity_id' => $this->positive($source, 'id'),
            'source_row_version' => $this->positive(
                $source,
                'row_version',
            ),
            'source_effective_from' => $effectiveFrom,
            'source_effective_to' => $effectiveTo,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * }
     */
    private function identifiers(array $source): array
    {
        $result = [];
        foreach (self::IDENTIFIER_TYPES as $type) {
            if (!array_key_exists($type, $source)) {
                $this->invalid(
                    'registration_identity_person_invalid',
                    "Snapshot identity neobsahuje klíč {$type}.",
                );
            }
            $result[$type] = $this->nullableText(
                $source[$type],
                191,
                "identifiers.{$type}",
            );
        }

        return $result;
    }

    /**
     * @param array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * } $identifiers
     */
    private function validateIdentifierFacets(array $identifiers): void
    {
        foreach (['birth_number', 'ecp'] as $type) {
            $value = $identifiers[$type];
            if ($value !== null
                && preg_match('/^[0-9]{9,10}$/D', $value) !== 1
            ) {
                $this->invalid(
                    'registration_identity_identifier_invalid',
                    "{$type} neodpovídá číselnému typu bno 9 až 10 číslic.",
                );
            }
        }
        if ($identifiers['vcp'] !== null
            && !PayrollVcp::isValid($identifiers['vcp'])
        ) {
            $this->invalid(
                'registration_identity_identifier_invalid',
                'VČP musí obsahovat přesně devět číslic a začínat číslicí 6.',
            );
        }
    }

    /**
     * @param array<string,mixed> $sources
     * @param array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * } $identifiers
     * @return array<string,array{id:int,row_version:int}>
     */
    private function identifierSources(
        array $sources,
        array $identifiers,
    ): array {
        $result = [];
        foreach (self::IDENTIFIER_TYPES as $type) {
            $hasValue = $identifiers[$type] !== null;
            if (!$hasValue) {
                if (array_key_exists($type, $sources)) {
                    $this->invalid(
                        'registration_identity_person_invalid',
                        "Prázdný identifikátor {$type} nesmí mít zdrojovou verzi.",
                    );
                }
                continue;
            }
            $source = $this->object(
                $sources[$type] ?? null,
                "identifier_sources.{$type}",
            );
            $result[$type] = [
                'id' => $this->positive($source, 'id'),
                'row_version' => $this->positive(
                    $source,
                    'row_version',
                ),
            ];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param array{
     *   supplier_id:int,submission_id:int,source_revision_id:int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * } $scope
     * @return array<string,mixed>|null
     */
    private function externalIdentifier(
        mixed $source,
        array $scope,
    ): ?array {
        if ($source === null) {
            return null;
        }
        $external = $this->object(
            $source,
            'employment_external_identifier',
        );
        if ($this->positive($external, 'employee_id') !== $scope['employee_id']
            || $this->positive(
                $external,
                'employment_id',
            ) !== $scope['employment_id']
            || ($external['environment'] ?? null) !== $scope['environment']
            || ($external['identifier_type'] ?? null) !== 'id_ppv'
        ) {
            $this->invalid(
                'registration_identity_id_ppv_scope_mismatch',
                'ID PPV nepatří stejné osobě, vztahu a prostředí.',
            );
        }
        $validFrom = $this->date(
            $external['valid_from'] ?? null,
            'employment_external_identifier.valid_from',
        );
        $validTo = $this->nullableDate(
            $external['valid_to'] ?? null,
            'employment_external_identifier.valid_to',
        );
        if ($validFrom > $scope['effective_on']
            || ($validTo !== null && $validTo < $scope['effective_on'])
        ) {
            $this->invalid(
                'registration_identity_id_ppv_scope_mismatch',
                'ID PPV není účinné k rozhodnému datu.',
            );
        }
        $sourceKind = $this->text($external, 'source_kind', 32);
        if (!in_array(
            $sourceKind,
            ['trusted_receipt', 'verified_manual_import'],
            true,
        )) {
            $this->invalid(
                'registration_identity_id_ppv_invalid',
                'ID PPV nemá důvěryhodný zdroj.',
            );
        }
        $sourceReceiptId = $this->nullablePositive(
            $external['source_receipt_id'] ?? null,
            'employment_external_identifier.source_receipt_id',
        );
        if (($sourceKind === 'trusted_receipt')
            !== ($sourceReceiptId !== null)
        ) {
            $this->invalid(
                'registration_identity_id_ppv_invalid',
                'Zdroj ID PPV neodpovídá vazbě na protokol.',
            );
        }
        $sourceReferenceHash = $this->hash(
            $external['source_reference_hash'] ?? null,
            'employment_external_identifier.source_reference_hash',
        );

        return [
            'identifier_type' => 'id_ppv',
            'value' => $this->text($external, 'value', 191),
            'environment' => $scope['environment'],
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_kind' => $sourceKind,
            'source_receipt_id' => $sourceReceiptId,
            'source_reference_hash' => $sourceReferenceHash,
            'source_external_id' => $this->positive($external, 'id'),
            'source_row_version' => $this->positive(
                $external,
                'row_version',
            ),
        ];
    }

    /**
     * @param array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * } $identifiers
     */
    private function hasRegistrationIdentifier(array $identifiers): bool
    {
        foreach (['birth_number', 'ecp', 'vcp'] as $type) {
            $value = $identifiers[$type];
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    private function registrationEligibility(
        string $agendaCode,
        array $identity,
    ): array {
        if ($agendaCode !== 'PREZEC26') {
            return [
                'status' => 'not_applicable',
                'basis' => 'agenda_not_prezec',
            ];
        }
        // Zákon č. 323/2025 Sb.: částečné přihlášení před nástupem je vyhrazeno
        // tuzemské osobě, zahraniční zaměstnanec vyžaduje plnou registraci
        // (REGZEC) před výkonem práce. PREZEC26 tomu odpovídá i strukturálně —
        // povinné `client/@bno` (RČ/EČP) a žádný blok pro zahraniční identitu,
        // doklad totožnosti ani daňovou rezidenci.
        //
        // Kritériem je STÁTNÍ OBČANSTVÍ, ne držení přiděleného RČ/EČP. Cizinec
        // s trvalým pobytem a českým rodným číslem by PREZEC strukturálně
        // naplnil, přesto ho tahle podmínka pošle na REGZEC — vědomě
        // fail-closed. Rozhodnout to umí jedině katalog kontrol MH na
        // developers.mpsv.cz, který lokálně nemáme (otevřený bod
        // „PREZEC a cizinec s českým RČ" v `private/MZDY-EPICs.md`).
        $citizenship = $identity['citizenship_country_code'] ?? null;
        if (!is_string($citizenship)) {
            $this->invalid(
                'registration_identity_prezec_citizenship_unverified',
                'Bez vyplněného státního občanství nelze částečné přihlášení PREZEC založit. Doplňte v kartě zaměstnance státní občanství a snapshot založte znovu.',
            );
        }
        if ($citizenship !== 'CZ') {
            $this->invalid(
                'registration_identity_prezec_foreign_requires_full_registration',
                'PREZEC (částečné přihlášení před nástupem) se tady zakládá jen zaměstnanci s českým státním občanstvím. U zaměstnance s jiným občanstvím — i když má přidělené české rodné číslo — založte podání v agendě REGZEC25 (plná registrace) a podejte je před zahájením práce.',
            );
        }

        return [
            'status' => 'verified',
            'basis' => 'domestic_citizenship_country_code',
            'citizenship_country_code' => $citizenship,
        ];
    }

    /** @param array<string,mixed> $identity */
    private function hasAlternativeForeignIdentity(array $identity): bool
    {
        return ($identity['birth_date'] ?? null) !== null
            && ($identity['birth_place'] ?? null) !== null
            && ($identity['birth_country_code'] ?? null) !== null
            && ($identity['citizenship_country_code'] ?? null) !== null
            && ($identity['sex'] ?? null) !== null;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} musí být objekt.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $this->invalid(
                    'registration_identity_source_invalid',
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $source */
    private function optionalPositive(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return $this->positive($source, $key);
    }

    /** @param array<string,mixed> $source */
    private function positive(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$key} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function text(array $source, string $key, int $maxLength): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)
            || trim($value) === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$key} musí být neprázdný text.",
            );
        }

        return trim($value);
    }

    private function nullableText(
        mixed $value,
        int $maxLength,
        string $field,
    ): ?string {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || trim($value) === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} není platný volitelný text.",
            );
        }

        return trim($value);
    }

    private function nullableCountry(mixed $value, string $field): ?string
    {
        $country = $this->nullableText($value, 2, $field);
        if ($country === null) {
            return null;
        }
        $country = mb_strtoupper($country, 'UTF-8');
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} není platný kód země.",
            );
        }

        return $country;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} není platné datum.",
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} není platné datum.",
            );
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        return $value === null ? null : $this->date($value, $field);
    }

    private function nullablePositive(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1
        ) {
            $this->invalid(
                'registration_identity_source_invalid',
                "{$field} není platný SHA-256.",
            );
        }

        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationIdentitySnapshotException($code, $message);
    }
}
