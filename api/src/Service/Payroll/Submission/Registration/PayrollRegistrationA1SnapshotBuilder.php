<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationA1SnapshotBuilder
{
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
        $source = $this->source(
            $this->object($input, 'source'),
            $scope,
        );
        $employment = $this->employment(
            $this->object($input, 'employment'),
            (string) $scope['effective_on'],
        );
        try {
            $variant = PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                $employment['activity_code'],
                $employment['relationship_detail_code'],
                true,
            );
        } catch (PayrollRegistrationXmlException $exception) {
            $this->invalid($exception->validationCode, $exception->getMessage());
        }

        $permanentAddress = $this->address(
            $this->object($input, 'permanent_address'),
            'permanent_address',
        );
        $taxResidency = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->taxResidency($this->object($input, 'tax_residency'));
        $healthInsuranceCode = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->code($input, 'health_insurance_code', 3);
        $facts = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->facts($this->object($input, 'facts'), $variant);
        $pension = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->pension($this->object($input, 'pension'))
            : null;
        $foreignLegislation = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->foreignLegislation(
                $this->object($input, 'foreign_legislation'),
            )
            : null;
        $employment = $this->validateEmploymentVariant($employment, $variant);

        $citizenship = $this->country($identity, 'citizenship_country_code');
        $proofIdentity = $this->optionalObject($input, 'proof_identity');
        $foreignWorker = $this->optionalObject($input, 'foreign_worker');
        if ($citizenship !== 'CZ') {
            if ($proofIdentity === null || $foreignWorker === null) {
                $this->invalid(
                    'registration_regzec_a1_foreign_data_missing',
                    'REGZEC A1 cizince vyžaduje ověřený doklad totožnosti a rozhodnutí o přístupu na trh práce.',
                );
            }
            $proofIdentity = $this->proofIdentity($proofIdentity);
            $foreignWorker = $this->foreignWorker($foreignWorker);
        } elseif ($proofIdentity !== null || $foreignWorker !== null) {
            $this->invalid(
                'registration_regzec_a1_foreign_data_invalid',
                'Údaje cizince nelze zmrazit pro českého občana.',
            );
        }

        $czechResidence = $this->optionalAddress(
            $input,
            'czech_residence_address',
        );
        if ($permanentAddress['country_code'] !== 'CZ'
            && !in_array($permanentAddress['country_code'], ['AT', 'DE', 'PL', 'SK'], true)
            && $czechResidence === null
            && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10
        ) {
            $this->invalid(
                'registration_regzec_a1_czech_residence_missing',
                'Osoba s trvalým pobytem mimo ČR vyžaduje adresu pobytu v ČR, nejde-li o přeshraničního pracovníka.',
            );
        }

        if ($taxResidency !== null
            && $taxResidency['country_code'] !== 'CZ'
            && $taxResidency['residence_address'] === null
        ) {
            $this->invalid(
                'registration_regzec_a1_tax_residence_address_missing',
                'Daňový rezident jiného státu vyžaduje adresu bydliště ve státě rezidence.',
            );
        }

        $attachments = $this->attachments($input['attachments'] ?? null);

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
            );
        }
        foreach (['supplier_id', 'employee_id', 'employment_id'] as $field) {
            if ($this->positive($input, $field) !== ($scope[$field] ?? null)) {
                $this->invalid(
                    'registration_regzec_a1_source_scope_mismatch',
                    'Autoritativní zdroj REGZEC A1 patří jiné firmě, osobě nebo pracovnímu vztahu.',
                );
            }
        }
        if ($this->date($input, 'effective_on') !== ($scope['effective_on'] ?? null)) {
            $this->invalid(
                'registration_regzec_a1_source_scope_mismatch',
                'Autoritativní zdroj REGZEC A1 patří k jinému rozhodnému dni.',
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
        if ($actualStart !== $effectiveOn) {
            $this->invalid(
                'registration_regzec_a1_start_date_invalid',
                'Datum nástupu v REGZEC A1 musí odpovídat rozhodnému dni snapshotu.',
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
    private function address(array $input, string $field): array
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
            $this->missing('tax_residency.identifier_pair');
        }
        $residence = $input['residence_address'] ?? null;

        return [
            'country_code' => $country,
            'identifier_type' => $identifierType,
            'identifier' => $identifier,
            'residence_address' => $residence === null
                ? null
                : $this->address(
                    $this->object($input, 'residence_address'),
                    'tax_residency.residence_address',
                ),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function pension(array $input): array
    {
        $type = $this->optionalText($input, 'type_code', 3);
        $from = $this->optionalDate($input, 'received_from');
        if (($type === null) !== ($from === null)) {
            $this->missing('pension.type_and_received_from');
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
            $this->missing('facts.health_restrictions');
        }
        $normalized = [];
        foreach ($restrictions as $restriction) {
            if (!is_array($restriction) || array_is_list($restriction)) {
                $this->missing('facts.health_restrictions[]');
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
            $this->missing('foreign_legislation.country_code');
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
            $this->missing('foreign_worker.free_access_reason_code');
        }
        if (!$freeAccess
            && ($permitType === null || $permitId === null
                || $permitFrom === null || $permitTo === null)
        ) {
            $this->missing('foreign_worker.permit');
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
            );
        }
        $result = [];
        foreach ($value as $attachment) {
            if (!is_array($attachment) || array_is_list($attachment)) {
                $this->missing('attachments[]');
            }
            $data = $this->text($attachment, 'data_base64', 20_000_000);
            if (base64_decode($data, true) === false) {
                $this->invalid(
                    'registration_regzec_a1_attachments_invalid',
                    'Příloha REGZEC A1 není platně kódovaná v Base64.',
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

    /** @param array<string,mixed> $input */
    private function optionalAddress(array $input, string $key): ?array
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);
        }
        return $this->address($value, $key);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function object(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);
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
        }
        return trim($value);
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $max): ?string
    {
        if (($input[$key] ?? null) === null) {
            return null;
        }
        return $this->text($input, $key, $max);
    }

    /** @param array<string,mixed> $input */
    private function code(array $input, string $key, int $length): string
    {
        $value = $this->text($input, $key, $length);
        if (preg_match('/^\d{' . $length . '}$/D', $value) !== 1) {
            $this->missing($key);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function country(array $input, string $key): string
    {
        $value = strtoupper($this->text($input, $key, 2));
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            $this->missing($key);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (!is_bool($value)) {
            $this->missing($key);
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
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->missing($key);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalDate(array $input, string $key): ?string
    {
        if (($input[$key] ?? null) === null) {
            return null;
        }
        return $this->date($input, $key);
    }

    /** @param array<string,mixed> $input */
    private function positive(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            $this->missing($key);
        }
        return $value;
    }

    private function missing(string $field): never
    {
        $this->invalid(
            'registration_regzec_a1_required_field_missing',
            "Autoritativní zdroj REGZEC A1 nemá platné povinné pole {$field}.",
        );
    }

    private function invalid(string $code, string $message): never
    {
        throw new PayrollRegistrationIdentitySnapshotException($code, $message);
    }
}
