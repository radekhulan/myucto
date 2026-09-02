<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Skládá a ověřuje autoritativní snímek REGZEC A1.
 *
 * Nad JEDNÍM souborem pravidel běží dva režimy:
 * - `build()` — přísně, první vada shodí celé sestavení výjimkou. Tudy jde
 *   podání, do kterého neúplný snímek pustit nesmíme.
 * - `problems()` — sběrně, vrátí VŠECHNY vady najednou i s cestou k poli.
 *   Tudy jde tlačítko „Kontrola" ve formuláři a odpověď na uložení konceptu.
 *
 * Druhá kopie pravidel v JS by se od serveru dřív nebo později rozešla, proto
 * je zdrojem pravdy jen tahle třída.
 */
final class PayrollRegistrationA1SnapshotBuilder
{
    /**
     * Sbírané vady; `null` znamená přísný režim, kde se místo sbírání hází.
     *
     * @var list<array{field:?string,code:string,message:string}>|null
     */
    private ?array $problems = null;

    /** Cesta k aktuálně zpracovávané sekci, např. `permanent_address.`. */
    private string $prefix = '';

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $scope
     */
    public function build(
        array $input,
        array $identity,
        array $scope,
    ): PayrollRegistrationA1Snapshot {
        $this->problems = null;
        $this->prefix = '';
        $snapshot = $this->assemble($input, $identity, $scope);
        if ($snapshot === null) {
            throw new \LogicException(
                'Přísné sestavení snímku REGZEC A1 nesmí skončit bez snímku.',
            );
        }

        return $snapshot;
    }

    /**
     * Co všechno by přísnému sestavení vadilo. Nic nezakládá a nic neodmítá.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $scope
     * @return list<array{field:?string,code:string,message:string}>
     */
    public function problems(
        array $input,
        array $identity,
        array $scope,
    ): array {
        $this->problems = [];
        $this->prefix = '';
        $this->assemble($input, $identity, $scope);
        $problems = $this->problems;
        $this->problems = null;

        return $problems;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $scope
     */
    private function assemble(
        array $input,
        array $identity,
        array $scope,
    ): ?PayrollRegistrationA1Snapshot {
        $sourceInput = $this->object($input, 'source');
        $source = $this->within(
            'source',
            fn (): array => $this->source($sourceInput, $scope),
        );
        $employmentInput = $this->object($input, 'employment');
        $employment = $this->within('employment', fn (): array => $this->employment(
            $employmentInput,
            (string) $scope['effective_on'],
        ));
        $variant = null;
        try {
            $variant = PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                $employment['activity_code'],
                $employment['relationship_detail_code'],
                true,
            );
        } catch (PayrollRegistrationXmlException $exception) {
            $this->invalid(
                $exception->validationCode,
                $exception->getMessage(),
                'employment.activity_code',
            );
        }
        if ($variant === null) {
            // Bez varianty se nedá říct, která pole jsou povinná; dohadovat by
            // znamenalo označit červeně pole, která tahle A1 vůbec nemá.
            return null;
        }

        $permanentInput = $this->object($input, 'permanent_address');
        $permanentAddress = $this->within(
            'permanent_address',
            fn (): array => $this->address($permanentInput),
        );
        $taxResidency = null;
        if ($variant !== PayrollRegistrationBusinessMatrix::VARIANT_10) {
            $taxResidencyInput = $this->object($input, 'tax_residency');
            $taxResidency = $this->within(
                'tax_residency',
                fn (): array => $this->taxResidency($taxResidencyInput),
            );
        }
        $healthInsuranceCode = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->code($input, 'health_insurance_code', 3);
        $facts = null;
        if ($variant !== PayrollRegistrationBusinessMatrix::VARIANT_10) {
            $factsInput = $this->object($input, 'facts');
            $facts = $this->within(
                'facts',
                fn (): array => $this->facts($factsInput, $variant),
            );
        }
        $pension = null;
        $foreignLegislation = null;
        if ($variant === PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            $pensionInput = $this->object($input, 'pension');
            $pension = $this->within(
                'pension',
                fn (): array => $this->pension($pensionInput),
            );
            $legislationInput = $this->object($input, 'foreign_legislation');
            $foreignLegislation = $this->within(
                'foreign_legislation',
                fn (): array => $this->foreignLegislation($legislationInput),
            );
        }
        $employment = $this->validateEmploymentVariant($employment, $variant);

        $citizenship = $this->country($identity, 'citizenship_country_code');
        $proofIdentity = $this->optionalObject($input, 'proof_identity');
        $foreignWorker = $this->optionalObject($input, 'foreign_worker');
        // Prázdné občanství je už nahlášené výš; brát ho jako cizinu by k tomu
        // přisypalo dvě vymyšlené vady o dokladech, které se nikoho netýkají.
        if ($citizenship !== '' && $citizenship !== 'CZ') {
            if ($proofIdentity === null || $foreignWorker === null) {
                $this->invalid(
                    'registration_regzec_a1_foreign_data_missing',
                    'REGZEC A1 cizince vyžaduje ověřený doklad totožnosti a rozhodnutí o přístupu na trh práce.',
                    'proof_identity',
                );
            }
            $proofIdentity = $proofIdentity === null
                ? null
                : $this->within('proof_identity', fn (): array
                    => $this->proofIdentity($proofIdentity));
            $foreignWorker = $foreignWorker === null
                ? null
                : $this->within('foreign_worker', fn (): array
                    => $this->foreignWorker($foreignWorker));
        } elseif ($citizenship === 'CZ'
            && ($proofIdentity !== null || $foreignWorker !== null)
        ) {
            $this->invalid(
                'registration_regzec_a1_foreign_data_invalid',
                'Údaje cizince nelze zmrazit pro českého občana.',
                'proof_identity',
            );
        }

        $czechResidence = $this->optionalAddress(
            $input,
            'czech_residence_address',
        );
        if ($permanentAddress['country_code'] !== ''
            && $permanentAddress['country_code'] !== 'CZ'
            && !in_array($permanentAddress['country_code'], ['AT', 'DE', 'PL', 'SK'], true)
            && $czechResidence === null
            && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10
        ) {
            $this->invalid(
                'registration_regzec_a1_czech_residence_missing',
                'Osoba s trvalým pobytem mimo ČR vyžaduje adresu pobytu v ČR, nejde-li o přeshraničního pracovníka.',
                'czech_residence_address',
            );
        }

        if ($taxResidency !== null
            && $taxResidency['country_code'] !== ''
            && $taxResidency['country_code'] !== 'CZ'
            && $taxResidency['residence_address'] === null
        ) {
            $this->invalid(
                'registration_regzec_a1_tax_residence_address_missing',
                'Daňový rezident jiného státu vyžaduje adresu bydliště ve státě rezidence.',
                'tax_residency.residence_address',
            );
        }

        $attachments = $this->within(
            'attachments',
            fn (): array => $this->attachments($input['attachments'] ?? null),
        );

        return new PayrollRegistrationA1Snapshot(
            $variant,
            $source,
            $permanentAddress,
            $taxResidency,
            $employment,
            $pension,
            $healthInsuranceCode,
            $facts,
            $foreignLegislation,
            $proofIdentity,
            $foreignWorker,
            $czechResidence,
            $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
                ? $this->optionalAddress($input, 'contact_address')
                : null,
            $attachments,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function source(array $input, array $scope): array
    {
        $hash = strtolower($this->text($input, 'reference_hash', 64));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            $this->invalid(
                'registration_regzec_a1_source_invalid',
                'Autoritativní zdroj REGZEC A1 nemá platný otisk.',
                'source.reference_hash',
            );
        }
        foreach (['supplier_id', 'employee_id', 'employment_id'] as $field) {
            if ($this->positive($input, $field) !== ($scope[$field] ?? null)) {
                $this->invalid(
                    'registration_regzec_a1_source_scope_mismatch',
                    'Autoritativní zdroj REGZEC A1 patří jiné firmě, osobě nebo pracovnímu vztahu.',
                    "source.{$field}",
                );
            }
        }
        if ($this->date($input, 'effective_on') !== ($scope['effective_on'] ?? null)) {
            $this->invalid(
                'registration_regzec_a1_source_scope_mismatch',
                'Autoritativní zdroj REGZEC A1 patří k jinému rozhodnému dni.',
                'source.effective_on',
            );
        }

        return [
            'source_key' => $this->text($input, 'source_key', 96),
            'source_id' => $this->positive($input, 'source_id'),
            'row_version' => $this->positive($input, 'row_version'),
            'reference_hash' => $hash,
            'supplier_id' => $this->positive($input, 'supplier_id'),
            'employee_id' => $this->positive($input, 'employee_id'),
            'employment_id' => $this->positive($input, 'employment_id'),
            'effective_on' => $this->date($input, 'effective_on'),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function employment(array $input, string $effectiveOn): array
    {
        $actualStart = $this->date($input, 'actual_start_on');
        // Chybějící datum už hlásí `date()`; porovnáním s prázdnou hodnotou by
        // se k tomu přidala druhá věta o nesouhlasu s rozhodným dnem.
        if ($actualStart !== '' && $actualStart !== $effectiveOn) {
            $this->invalid(
                'registration_regzec_a1_start_date_invalid',
                'Datum nástupu v REGZEC A1 musí odpovídat rozhodnému dni snapshotu.',
                'employment.actual_start_on',
            );
        }

        return [
            'activity_code' => $this->text($input, 'activity_code', 3),
            'relationship_detail_code' => $this->optionalText(
                $input,
                'relationship_detail_code',
                1,
            ),
            'actual_start_on' => $actualStart,
            'contract_start_on' => $this->optionalDate($input, 'contract_start_on'),
            'small_scale' => $this->optionalBool($input, 'small_scale'),
            'employment_status_code' => $this->optionalText($input, 'employment_status_code', 2),
            'work_mode_code' => $this->optionalText($input, 'work_mode_code', 2),
            'continuous_operation' => $this->optionalBool($input, 'continuous_operation'),
            'prevailing_workplace_code' => $this->optionalText($input, 'prevailing_workplace_code', 2),
            'expected_workplaces' => $this->optionalText($input, 'expected_workplaces', 255),
            'contract_workplace' => $this->optionalText($input, 'contract_workplace', 255),
            'workplace_city' => $this->optionalText($input, 'workplace_city', 255),
            'workplace_municipality_code' => $this->optionalText($input, 'workplace_municipality_code', 12),
            'profession_code' => $this->optionalText($input, 'profession_code', 12),
            'required_education_code' => $this->optionalText($input, 'required_education_code', 4),
            'position_name' => $this->optionalText($input, 'position_name', 255),
            'leadership' => $this->optionalBool($input, 'leadership'),
        ];
    }

    /** @param array<string,mixed> $employment @return array<string,mixed> */
    private function validateEmploymentVariant(array $employment, string $variant): array
    {
        $required = match ($variant) {
            PayrollRegistrationBusinessMatrix::VARIANT_OST => [
                'contract_start_on', 'small_scale', 'employment_status_code',
                'work_mode_code', 'continuous_operation',
                'prevailing_workplace_code', 'contract_workplace',
                'workplace_city', 'workplace_municipality_code',
                'profession_code', 'position_name', 'leadership',
            ],
            PayrollRegistrationBusinessMatrix::VARIANT_SPEC => [
                'contract_workplace', 'workplace_city',
                'workplace_municipality_code',
            ],
            default => [],
        };
        foreach ($required as $field) {
            if ($employment[$field] === null) {
                $this->missing("employment.{$field}");
            }
        }

        return $employment;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function address(array $input): array
    {
        return [
            'street' => $this->optionalText($input, 'street', 255),
            'house_number' => $this->text($input, 'house_number', 12),
            'orientation_number' => $this->optionalText($input, 'orientation_number', 12),
            'city' => $this->text($input, 'city', 255),
            'postal_code' => $this->text($input, 'postal_code', 12),
            'country_code' => $this->country($input, 'country_code'),
            'ruian_point' => $this->optionalText($input, 'ruian_point', 20),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function taxResidency(array $input): array
    {
        $country = $this->country($input, 'country_code');
        $identifierType = $this->optionalText($input, 'identifier_type', 3);
        $identifier = $this->optionalText($input, 'identifier', 64);
        if (($identifierType === null) !== ($identifier === null)) {
            $this->missing('identifier_pair');
        }
        $residence = $input['residence_address'] ?? null;
        $residenceAddress = null;
        if ($residence !== null) {
            $residenceInput = $this->object($input, 'residence_address');
            $residenceAddress = $this->within(
                'residence_address',
                fn (): array => $this->address($residenceInput),
            );
        }

        return [
            'country_code' => $country,
            'identifier_type' => $identifierType,
            'identifier' => $identifier,
            'residence_address' => $residenceAddress,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function pension(array $input): array
    {
        $type = $this->optionalText($input, 'type_code', 3);
        $from = $this->optionalDate($input, 'received_from');
        if (($type === null) !== ($from === null)) {
            $this->missing('type_and_received_from');
        }

        return [
            'type_code' => $type,
            'received_from' => $from,
            'early_retirement' => $this->bool($input, 'early_retirement'),
            'reduced_retirement_age' => $this->bool($input, 'reduced_retirement_age'),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function facts(array $input, string $variant): array
    {
        $restrictions = $input['health_restrictions'] ?? null;
        if (!is_array($restrictions) || !array_is_list($restrictions)) {
            $this->missing('health_restrictions');
            $restrictions = [];
        }
        $normalized = [];
        foreach ($restrictions as $restriction) {
            if (!is_array($restriction) || array_is_list($restriction)) {
                $this->missing('health_restrictions[]');
                continue;
            }
            $normalized[] = [
                'type_code' => $this->text($restriction, 'type_code', 3),
                'from' => $this->date($restriction, 'from'),
                'to' => $this->optionalDate($restriction, 'to'),
            ];
        }

        return [
            'highest_education_code' => $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
                ? $this->text($input, 'highest_education_code', 4)
                : null,
            'disability_card' => $this->bool($input, 'disability_card'),
            'health_restrictions' => $normalized,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function foreignLegislation(array $input): array
    {
        $applies = $this->bool($input, 'applies');
        $country = $this->optionalText($input, 'country_code', 2);
        if ($applies && $country === null) {
            $this->missing('country_code');
        }

        return ['applies' => $applies, 'country_code' => $country];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function proofIdentity(array $input): array
    {
        return [
            'type_code' => $this->text($input, 'type_code', 3),
            'number' => $this->text($input, 'number', 64),
            'foreign_issuer' => $this->optionalText($input, 'foreign_issuer', 255),
            'country_code' => $this->country($input, 'country_code'),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function foreignWorker(array $input): array
    {
        $freeAccess = $this->bool($input, 'free_access');
        $reason = $this->optionalText($input, 'free_access_reason_code', 4);
        $permitType = $this->optionalText($input, 'permit_type_code', 4);
        $permitId = $this->optionalText($input, 'permit_identifier', 64);
        $permitFrom = $this->optionalDate($input, 'permit_from');
        $permitTo = $this->optionalDate($input, 'permit_to');
        if ($freeAccess && $reason === null) {
            $this->missing('free_access_reason_code');
        }
        if (!$freeAccess
            && ($permitType === null || $permitId === null
                || $permitFrom === null || $permitTo === null)
        ) {
            $this->missing('permit');
        }

        return [
            'free_access' => $freeAccess,
            'free_access_reason_code' => $reason,
            'permit_type_code' => $permitType,
            'issuing_labour_office_code' => $this->optionalText($input, 'issuing_labour_office_code', 8),
            'permit_identifier' => $permitId,
            'permit_from' => $permitFrom,
            'permit_to' => $permitTo,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function attachments(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 9) {
            $this->invalid(
                'registration_regzec_a1_attachments_invalid',
                'Přílohy REGZEC A1 musí být seznam nejvýše devíti úplných příloh.',
                'attachments',
            );

            return [];
        }
        $result = [];
        foreach ($value as $attachment) {
            if (!is_array($attachment) || array_is_list($attachment)) {
                $this->missing('[]');
                continue;
            }
            $data = $this->text($attachment, 'data_base64', 20_000_000);
            if ($data !== '' && base64_decode($data, true) === false) {
                $this->invalid(
                    'registration_regzec_a1_attachments_invalid',
                    'Příloha REGZEC A1 není platně kódovaná v Base64.',
                    'attachments.data_base64',
                );
            }
            $result[] = [
                'name' => $this->text($attachment, 'name', 255),
                'description' => $this->optionalText($attachment, 'description', 255),
                'data_base64' => $data,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function optionalAddress(array $input, string $key): ?array
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);

            return null;
        }

        return $this->within($key, fn (): array => $this->address($value));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function object(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);

            return [];
        }

        return $value;
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    private function optionalObject(array $input, string $key): ?array
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);

            return null;
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === ''
            || mb_strlen(trim($value)) > $max
        ) {
            $this->missing($key);

            return '';
        }

        return trim($value);
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $max): ?string
    {
        if (($input[$key] ?? null) === null) {
            return null;
        }
        $value = $this->text($input, $key, $max);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function code(array $input, string $key, int $length): string
    {
        $value = $this->text($input, $key, $length);
        if ($value !== '' && preg_match('/^\d{' . $length . '}$/D', $value) !== 1) {
            $this->missing($key);

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function country(array $input, string $key): string
    {
        $value = strtoupper($this->text($input, $key, 2));
        if ($value !== '' && preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            $this->missing($key);

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (!is_bool($value)) {
            $this->missing($key);

            return false;
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalBool(array $input, string $key): ?bool
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }

        return $this->bool($input, $key);
    }

    /** @param array<string,mixed> $input */
    private function date(array $input, string $key): string
    {
        $value = $this->text($input, $key, 10);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->missing($key);

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalDate(array $input, string $key): ?string
    {
        if (($input[$key] ?? null) === null) {
            return null;
        }
        $value = $this->date($input, $key);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function positive(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            $this->missing($key);

            return 0;
        }

        return $value;
    }

    /**
     * Kam se údaj zadává. `null` znamená „přímo v tomhle formuláři".
     *
     * ZÁLOŽKA PATŘÍ DO CESTY. Bez ní popis mířil na sekci, kterou jiná záložka
     * karty vůbec nevykresluje — účetní ji hledala a nenašla, protože stála na
     * „Kontaktech". Tlačítko u hlášky sice doskočí samo, ale text musí sedět
     * i pro toho, kdo si cestu proklikává ručně.
     */
    private const WHERE_IDENTITY = 'kartě osoby → Identita a adresy → '
        . 'Historie jména → Údaje pro registraci zaměstnance';
    private const WHERE_NAMES = 'kartě osoby → Identita a adresy → Historie jména';
    private const WHERE_ADDRESSES = 'kartě osoby → Identita a adresy → Historie adres';

    /**
     * Lidský název a MÍSTO, kde se údaj doplňuje.
     *
     * Why: hláška uměla jen technický název sloupce („nemá platné povinné pole
     * citizenship_country_code"). Účetní z něj nepozná ani co to je, ani kam
     * jít. Klíč bez překladu se vypíše tak, jak je; neúplný slovník nesmí
     * zamlčet, že něco chybí.
     *
     * @var array<string,array{0:string,1:?string}>
     */
    private const FIELD_LABELS = [
        'citizenship_country_code' => ['státní občanství', self::WHERE_IDENTITY],
        'birth_country_code' => ['stát narození', self::WHERE_IDENTITY],
        'birth_place' => ['místo narození', self::WHERE_IDENTITY],
        'birth_date' => ['datum narození', self::WHERE_IDENTITY],
        'sex' => ['pohlaví', self::WHERE_IDENTITY],
        'family_name' => ['příjmení', self::WHERE_NAMES],
        'given_name' => ['jméno', self::WHERE_NAMES],
        'permanent_address.street' => ['ulice trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.house_number' => ['číslo popisné trvalého pobytu', null],
        'permanent_address.city' => ['obec trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.postal_code' => ['PSČ trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.country_code' => ['stát trvalého pobytu', self::WHERE_ADDRESSES],
        'health_insurance_code' => [
            'kód zdravotní pojišťovny',
            'kartě osoby → Zákonná evidence → zdravotní pojištění',
        ],
        'tax_residency.country_code' => [
            'stát daňové rezidence',
            'kartě osoby → Zákonná evidence → daňová rezidence',
        ],
        'tax_residency.identifier_pair' => [
            'typ i hodnotu zahraničního daňového identifikátoru (buď obojí, nebo nic)',
            null,
        ],
        'employment.activity_code' => [
            'druh činnosti pro ČSSZ',
            'kartě pracovního vztahu → sjednané podmínky',
        ],
        'employment.actual_start_on' => [
            'skutečné datum nástupu',
            'kartě pracovního vztahu',
        ],
        'employment.contract_start_on' => ['sjednaný den nástupu', null],
        'employment.small_scale' => ['příznak zaměstnání malého rozsahu', null],
        'employment.employment_status_code' => ['postavení zaměstnance', null],
        'employment.work_mode_code' => ['režim práce', null],
        'employment.continuous_operation' => ['nepřetržitý provoz', null],
        'employment.prevailing_workplace_code' => ['převažující pracoviště', null],
        'employment.contract_workplace' => ['sjednané místo výkonu práce', null],
        'employment.workplace_city' => ['obec pracoviště', null],
        'employment.workplace_municipality_code' => ['kód obce pracoviště', null],
        'employment.profession_code' => ['profesi (CZ-ISCO)', null],
        'employment.position_name' => ['název pracovní pozice', null],
        'employment.leadership' => ['příznak vedoucí pozice', null],
        'facts.highest_education_code' => ['nejvyšší dosažené vzdělání', null],
        'facts.disability_card' => ['průkaz osoby se zdravotním postižením', null],
        'facts.health_restrictions' => ['seznam zdravotních omezení', null],
        'pension.type_and_received_from' => [
            'druh důchodu i datum přiznání (buď obojí, nebo nic)',
            null,
        ],
        'pension.early_retirement' => ['příznak předčasného důchodu', null],
        'pension.reduced_retirement_age' => ['příznak snížené důchodové hranice', null],
        'foreign_legislation.applies' => ['příznak zahraniční legislativy', null],
        'foreign_legislation.country_code' => ['stát zahraniční legislativy', null],
        'proof_identity.type_code' => ['typ dokladu totožnosti', null],
        'proof_identity.number' => ['číslo dokladu totožnosti', null],
        'proof_identity.country_code' => ['stát vydání dokladu totožnosti', null],
        'foreign_worker.free_access' => ['volný přístup na trh práce', null],
        'foreign_worker.free_access_reason_code' => ['důvod volného přístupu na trh práce', null],
        'foreign_worker.permit' => [
            'úplné povolení k zaměstnání (typ, číslo, platnost od a do)',
            null,
        ],
    ];

    /**
     * Adresní listy se opakují ve čtyřech sekcích. Trvalý pobyt má vlastní
     * záznamy výš (bere se z evidence osoby), zbytek se vyplňuje tady.
     *
     * @var array<string,string>
     */
    private const ADDRESS_LABELS = [
        'street' => 'ulice',
        'house_number' => 'číslo popisné',
        'orientation_number' => 'číslo orientační',
        'city' => 'obec',
        'postal_code' => 'PSČ',
        'country_code' => 'stát adresy',
    ];

    /** @return array{0:string,1:?string}|null */
    private function label(string $path): ?array
    {
        if (isset(self::FIELD_LABELS[$path])) {
            return self::FIELD_LABELS[$path];
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return null;
        }
        $leaf = substr($path, $dot + 1);
        $address = self::ADDRESS_LABELS[$leaf] ?? null;

        return $address === null ? null : [$address, null];
    }

    private function missing(string $field): void
    {
        $path = $this->prefix . $field;
        $label = $this->label($path);
        if ($label === null) {
            $this->fail(
                'registration_regzec_a1_required_field_missing',
                "Autoritativní zdroj REGZEC A1 nemá platné povinné pole {$path}.",
                $path,
            );

            return;
        }
        [$name, $where] = $label;
        $this->fail(
            'registration_regzec_a1_required_field_missing',
            $where === null
                ? "Pro REGZEC A1 chybí {$name} ({$path}). Vyplňte ho přímo"
                    . ' v tomhle formuláři.'
                : "Pro REGZEC A1 chybí {$name} ({$path}). Doplňte ho na"
                    . " {$where} — na tomhle formuláři se nezadává, bere se"
                    . ' z osobní evidence.',
            $path,
        );
    }

    private function invalid(
        string $code,
        string $message,
        ?string $field = null,
    ): void {
        $this->fail($code, $message, $field);
    }

    private function fail(string $code, string $message, ?string $field): void
    {
        if ($this->problems === null) {
            throw new PayrollRegistrationIdentitySnapshotException($code, $message);
        }
        $this->problems[] = [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    private function within(string $prefix, \Closure $work): mixed
    {
        $previous = $this->prefix;
        $this->prefix = $previous . $prefix . '.';
        try {
            return $work();
        } finally {
            $this->prefix = $previous;
        }
    }
}
