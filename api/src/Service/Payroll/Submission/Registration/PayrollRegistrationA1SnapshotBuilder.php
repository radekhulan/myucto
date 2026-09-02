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
        // Chybí-li celý podklad, nemá smysl rozepisovat jeho devět vnitřních
        // polí — účetní by dostala devět technických řádků o `source.*`, které
        // stejně nikde nevyplňuje. Jedna věta stačí.
        $sourceInput = $input['source'] ?? null;
        $source = [];
        if (!is_array($sourceInput) || array_is_list($sourceInput)) {
            // Podklad doplňuje aplikace při ukládání, ne účetní. Kdyby ji
            // `missing()` poslalo „vyplňte přímo v tomhle formuláři", hledala
            // by pole, které na obrazovce neexistuje.
            $this->invalid(
                'registration_regzec_a1_required_field_missing',
                'Podklad registrace chybí — formulář se neuložil celý. '
                    . 'Zavřete ho, otevřete registraci znovu a uložte ji ještě '
                    . 'jednou; pokud se hláška vrátí, jde o chybu aplikace.',
                'source',
            );
        } else {
            $source = $this->within(
                'source',
                fn (): array => $this->source($sourceInput, $scope),
            );
        }
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
                    'Doklad totožnosti a rozhodnutí o přístupu na trh práce chybí. '
                        . 'U zaměstnance bez českého státního občanství je ČSSZ '
                        . 'vyžaduje. Vyplňte obojí přímo v tomhle formuláři.',
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
                'Doklad totožnosti a rozhodnutí o přístupu na trh práce se '
                    . 'u zaměstnance s českým státním občanstvím nevyplňují. '
                    . 'Buď je z formuláře odeberte, nebo opravte státní '
                    . 'občanství na kartě osoby.',
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
                'Adresa pobytu v ČR chybí. Zaměstnanec s trvalým pobytem mimo '
                . 'ČR ji musí mít vyplněnou — výjimka platí jen pro dojíždějící '
                . 'z Německa, Rakouska, Polska a Slovenska. Vyplňte ji přímo '
                . 'v tomhle formuláři.',
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
                'Adresa bydliště ve státě daňové rezidence chybí. U daňového '
                . 'rezidenta jiného státu než ČR ji ČSSZ vyžaduje. Vyplňte ji '
                . 'přímo v tomhle formuláři.',
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
                'Kontrolní otisk uložených údajů registrace nesouhlasí — záznam '
                    . 'se mezitím změnil jinde. Zavřete formulář, otevřete ho '
                    . 'znovu a zkuste to zopakovat.',
                'source.reference_hash',
            );
        }
        foreach (['supplier_id', 'employee_id', 'employment_id'] as $field) {
            if ($this->positive($input, $field) !== ($scope[$field] ?? null)) {
                $this->invalid(
                    'registration_regzec_a1_source_scope_mismatch',
                    'Uložené údaje registrace patří jiné firmě, osobě nebo '
                        . 'pracovnímu vztahu. Zavřete formulář a otevřete '
                        . 'registraci znovu z karty toho správného pracovního '
                        . 'vztahu.',
                    "source.{$field}",
                );
            }
        }
        if ($this->date($input, 'effective_on') !== ($scope['effective_on'] ?? null)) {
            $this->invalid(
                'registration_regzec_a1_source_scope_mismatch',
                'Uložené údaje registrace patří k jinému dni nástupu, než se '
                    . 'právě registruje. Zavřete formulář a otevřete registraci '
                    . 'znovu z karty pracovního vztahu.',
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
                "Skutečné datum nástupu ({$actualStart}) se liší ode dne, ke "
                    . "kterému se registrace podává ({$effectiveOn}). Srovnejte "
                    . 'obojí na kartě pracovního vztahu.',
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
                'Přílohy registrace nejdou přijmout. Připojit jich lze nejvýše '
                    . 'devět a každá musí mít název i obsah. Odeberte přebytečné '
                    . 'a neúplné přílohy.',
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
                    'Obsah přílohy se nepodařilo přečíst — soubor se cestou porušil. '
                    . 'Odeberte přílohu a připojte ji znovu.',
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
        if (!is_string($value) || trim($value) === '') {
            $this->missing($key);

            return '';
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length > $max) {
            $this->malformed(
                $key,
                'je delší, než ČSSZ přijme: vejde se do ' . self::chars($max)
                    . ', teď jich má ' . $length . '. Zkraťte hodnotu.',
            );

            return '';
        }

        return $value;
    }

    /** Skloňování „znak / znaky / znaků", ať hláška nezní jako z automatu. */
    private static function chars(int $count): string
    {
        return match (true) {
            $count === 1 => '1 znak',
            $count < 5 => "{$count} znaky",
            default => "{$count} znaků",
        };
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
        // Volnější strop než přesná délka schválně: o jednu číslici delší kód
        // pak dostane hlášku o počtu číslic, ne matoucí „je delší, než ČSSZ
        // přijme" — účetní potřebuje vědět, kolik číslic tam patří.
        $value = $this->text($input, $key, $length + 8);
        if ($value !== '' && preg_match('/^\d{' . $length . '}$/D', $value) !== 1) {
            $this->malformed(
                $key,
                "musí být číselný kód o přesně {$length} číslicích.",
            );

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function country(array $input, string $key): string
    {
        // Stejný důvod jako u `code()`: „CZE" má dostat hlášku o dvoupísmenné
        // zkratce, ne o překročené délce.
        $value = strtoupper($this->text($input, $key, 16));
        if ($value !== '' && preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            $this->malformed(
                $key,
                'musí být dvoupísmenná zkratka státu, například CZ nebo SK.',
            );

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
        // Vyšší strop než deset znaků schválně: delší nesmysl pak dostane
        // hlášku o tvaru data, ne matoucí „je delší, než ČSSZ přijme".
        $value = $this->text($input, $key, 32);
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->malformed(
                $key,
                'musí být datum ve tvaru RRRR-MM-DD, například 2026-08-05.',
            );

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
     * Údaj úplně chybí.
     *
     * Věta začíná LIDSKÝM názvem údaje, ne názvem sloupce — ten se veze buď
     * v poli `field` (sběrný režim), nebo v závorce na konci (přísný režim,
     * kde žádné `field` není kam dát). Slovník je společný pro celý
     * registrační řetězec, viz {@see PayrollRegistrationFieldVocabulary}.
     */
    private function missing(string $field): void
    {
        $path = $this->prefix . $field;
        $this->fail(
            'registration_regzec_a1_required_field_missing',
            PayrollRegistrationFieldVocabulary::label($path)
                . ' chybí — registraci na ČSSZ (REGZEC A1) bez toho podat nejde. '
                . PayrollRegistrationFieldVocabulary::describe($path),
            $path,
        );
    }

    /**
     * Údaj vyplněný je, ale ve tvaru, který ČSSZ nepřijme.
     *
     * „Chybí" by tady lhalo — účetní by koukala na vyplněné pole a hledala
     * prázdné. `$expectation` proto musí říct, JAK má hodnota vypadat.
     */
    private function malformed(string $field, string $expectation): void
    {
        $path = $this->prefix . $field;
        $this->fail(
            'registration_regzec_a1_field_value_invalid',
            PayrollRegistrationFieldVocabulary::label($path) . ' ' . $expectation
                . ' ' . PayrollRegistrationFieldVocabulary::describe($path),
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
            // Přísný režim nemá kam dát `field`, takže technická cesta jde do
            // závorky na konec věty. Bez ní by podpora nedohledala pole.
            throw new PayrollRegistrationIdentitySnapshotException(
                $code,
                $message . PayrollRegistrationFieldVocabulary::reference($field),
            );
        }
        // Jedno pole = jedna hláška. Bez toho se u příliš dlouhé hodnoty
        // vypsalo „je delší, než ČSSZ přijme" a hned pod tím „chybí" (protože
        // vadnou hodnotu zahazujeme na `null`) — dvě věty, které si odporují.
        // První hláška je vždycky ta konkrétnější, tak si ji necháme.
        foreach ($this->problems as $problem) {
            if ($field !== null && $problem['field'] === $field) {
                return;
            }
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
