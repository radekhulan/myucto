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
    /** Kód „povolení k zaměstnání" v číselníku CIS Druh pracovního oprávnění. */
    private const PERMIT_TYPE_EMPLOYMENT_PERMIT = '1';

    /** Druhy činnosti dohody o provedení práce (T až ZC), kde `sme` být nesmí. */
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    /**
     * Sbírané vady; `null` znamená přísný režim, kde se místo sbírání hází.
     *
     * @var list<array{field:?string,code:string,message:string,message_key:?string,params:array<string,int|string>}>|null
     */
    private ?array $problems = null;

    /** Cesta k aktuálně zpracovávané sekci, např. `permanent_address.`. */
    private string $prefix = '';

    /**
     * Zaměstnavatel uznaný na chráněném trhu práce (ID 10211). Jen u něj se
     * vyplňuje „práce probíhá převážně" (10258), a to u zaměstnance se
     * zdravotním omezením; jinde je údaj podle EDV zakázaný.
     */
    private bool $protectedLaborMarket = false;

    /**
     * Osobní údaje, které sestavovaný snímek povinně nese. U A1 jsou to
     * všechny z EDV 10053–10066; dohlášení A3 jich nese jen část.
     *
     * @var list<string>
     */
    private array $identityKeys = PayrollRegistrationIdentityRequirements::A1_IDENTITY_FIELDS;

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $scope
     * @param list<string>|null $identityKeys povinné osobní údaje; `null` = přihláška A1
     */
    public function build(
        array $input,
        array $identity,
        array $scope,
        bool $protectedLaborMarket = false,
        ?array $identityKeys = null,
    ): PayrollRegistrationA1Snapshot {
        $this->problems = null;
        $this->prefix = '';
        $this->protectedLaborMarket = $protectedLaborMarket;
        $this->identityKeys = $identityKeys
            ?? PayrollRegistrationIdentityRequirements::A1_IDENTITY_FIELDS;
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
     * @return list<array{field:?string,code:string,message:string,message_key:?string,params:array<string,int|string>}>
     */
    public function problems(
        array $input,
        array $identity,
        array $scope,
        bool $protectedLaborMarket = false,
    ): array {
        $this->problems = [];
        $this->prefix = '';
        $this->protectedLaborMarket = $protectedLaborMarket;
        $this->identityKeys = PayrollRegistrationIdentityRequirements::A1_IDENTITY_FIELDS;
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
        // U dohod evidence bližší určení nevede, REGZEC ho ale chce jako „1".
        $employment['relationship_detail_code'] =
            PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                $employment['activity_code'],
                $employment['relationship_detail_code'],
            );
        // EDV 1.4.0.6, ID 10223: u druhu činnosti 10 až 16 a u výkonu trestu
        // (10502 = 2) nesmí být nástup dřív než 1. 1. 2026. Skutečné datum
        // zůstává v evidenci i ve snímku, fiktivní 1. 1. 2026 (zásady REGZEC,
        // specifický postup č. 3) se dosazuje až do věty, viz
        // {@see PayrollRegistrationSpecialStartDate}.
        $this->underageCheck($identity, $employment);

        // Státní občanství rozhoduje o tom, které údaje jsou povinné (cizinec)
        // a které zakázané (občan ČR) — EDV 1.4.0.6, ID 10071, 10248, 10526.
        $citizenship = $this->country($identity, 'citizenship_country_code');

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
            // Vzdělání „Z" u občana ČR s dohodou sestavení neblokuje, jen se
            // na ně upozorní, viz PayrollRegistrationEducationRule::warning().
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
        $employment = $this->workplaceProgress($employment, $variant, $facts);
        $employment = $this->citizenshipDependentEmployment(
            $employment,
            $variant,
            $citizenship,
        );
        $foreignInsurance = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->foreignInsurance(
                $input['foreign_insurance'] ?? null,
                (string) $employment['activity_code'],
            )
            : null;

        $this->identityPresent($identity);
        // EDV 1.4.0.6: u A1-10 jsou elementy proofid a nocitizen zakázané, takže
        // je varianta 10 nevyžaduje a nenese (hodnota ze zdroje se zahodí).
        $foreignGroups = $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10;
        $proofIdentity = $foreignGroups
            ? $this->optionalObject($input, 'proof_identity')
            : null;
        $foreignWorker = $foreignGroups
            ? $this->optionalObject($input, 'foreign_worker')
            : null;
        // Prázdné občanství je už nahlášené výš; brát ho jako cizinu by k tomu
        // přisypalo dvě vymyšlené vady o dokladech, které se nikoho netýkají.
        if (!$foreignGroups) {
            // Zakázané skupiny varianty 10, není co kontrolovat.
        } elseif ($citizenship !== '' && $citizenship !== 'CZ') {
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
                    => $this->proofIdentity(
                        $proofIdentity,
                        $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10,
                    ));
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

        // EDV 1.4.0.6, ID 10514 a další: pobyt v ČR (`fdr`) je zakázaný při
        // trvalém pobytu v ČR i u varianty 10, hodnota se zahodí (a protože
        // se nepošle, ani se nekontroluje).
        $czechResidence = $permanentAddress['country_code'] === 'CZ'
            || $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
                ? null
                : $this->optionalAddress($input, 'czech_residence_address', true);
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
            && PayrollRegistrationTaxResidencyRule::requiresResidenceAddress(
                $taxResidency['country_code'],
            )
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
            $foreignInsurance,
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
            'employment_status_code' => $this->employmentStatus($input),
            'work_mode_code' => $this->coded('work_mode_code', $this->optionalText($input, 'work_mode_code', 2), PayrollRegistrationCodebooks::WORK_MODE, 'Pracovní režim'),
            'continuous_operation' => $this->optionalBool($input, 'continuous_operation'),
            'prevailing_workplace_code' => $this->coded('prevailing_workplace_code', $this->optionalText($input, 'prevailing_workplace_code', 2), PayrollRegistrationCodebooks::WORK_PLACE, 'Průběh práce'),
            'expected_workplaces' => $this->optionalText($input, 'expected_workplaces', 500),
            'contract_workplace' => $this->optionalText($input, 'contract_workplace', 255),
            'workplace_city' => $this->optionalText($input, 'workplace_city', 255),
            'workplace_municipality_code' => $this->optionalText($input, 'workplace_municipality_code', 12),
            'profession_code' => $this->optionalText($input, 'profession_code', 12),
            'required_education_code' => $this->coded('required_education_code', $this->optionalText($input, 'required_education_code', 4), PayrollRegistrationCodebooks::EDUCATION, 'KKOV'),
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
                'contract_workplace',
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
        // EDV 1.4.0.6, ID 10243: zaměstnání malého rozsahu (sme = A) se u dohod
        // o provedení práce (T až ZC) nepoužívá.
        if ($employment['small_scale'] === true
            && in_array($employment['activity_code'], self::DPP_ACTIVITY_CODES, true)
        ) {
            $this->malformed(
                'employment.small_scale',
                'nesmí být u druhu činnosti „' . $employment['activity_code']
                    . '" (dohoda o provedení práce) zapnuté — zaměstnání malého '
                    . 'rozsahu se u dohod nepoužívá.',
                'small_scale_dpp',
                ['activity' => (string) $employment['activity_code']],
            );
        }
        if ($employment['profession_code'] !== null
            && !PayrollRegistrationProfessionCode::isRegistrable((string) $employment['profession_code'])
        ) {
            $this->malformed(
                'employment.profession_code',
                'musí být pětimístný kód CZ-ISCO (kategorie); čtyřmístnou podskupinu ČSSZ'
                    . ' v registraci nepřijme, teď je „' . $employment['profession_code'] . '".',
                'profession_five_digits',
                ['value' => (string) $employment['profession_code']],
            );
        }
        $allowed = PayrollRegistrationEmploymentStatusCodebook::restrictedFor(
            $employment['activity_code'],
        );
        if ($allowed !== null
            && $employment['employment_status_code'] !== null
            && !in_array($employment['employment_status_code'], $allowed, true)
        ) {
            $this->malformed(
                'employment.employment_status_code',
                'u druhu činnosti „' . $employment['activity_code'] . '" smí být '
                    . 'jen ' . implode(' nebo ', $allowed) . ', teď je „'
                    . $employment['employment_status_code'] . '".',
                'status_for_activity',
                [
                    'activity' => (string) $employment['activity_code'],
                    'allowed' => implode(', ', $allowed),
                    'value' => (string) $employment['employment_status_code'],
                ],
            );
        }

        return $employment;
    }

    /**
     * Kontrola „Datum narození x Datum nástupu" (EDV 1.4.0.6, ID 10056
     * a 10223): zaměstnanci mladšímu 14 let k nástupu ČSSZ podání zamítne
     * na vstupu, bez výjimky pro dohody.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $employment
     */
    private function underageCheck(array $identity, array $employment): void
    {
        $birthDate = $identity['birth_date'] ?? null;
        $start = $employment['actual_start_on'];
        if (!is_string($birthDate) || $birthDate === '' || $start === '') {
            return;
        }
        if (PayrollRegistrationMinimumAge::isUnderage($birthDate, $start)) {
            $this->invalid(
                'registration_regzec_a1_underage',
                'Zaměstnanci je k datu nástupu ' . $start . ' méně než '
                    . PayrollRegistrationMinimumAge::YEARS . ' let (narozen '
                    . $birthDate . ') a ČSSZ takové podání zamítne na vstupu. '
                    . 'Zkontrolujte datum narození na kartě osoby a datum '
                    . 'nástupu na kartě pracovního vztahu.',
                'employment.actual_start_on',
                'underage',
                ['min_age' => PayrollRegistrationMinimumAge::YEARS],
            );
        }
    }

    /**
     * Osobní údaje, které přihláška A1 povinně nese (EDV 10053–10066, 10059).
     *
     * Dřív je hlídal až serializér při přípravě podání, takže „Kontrola"
     * profilu prošla a účetní se o chybějícím místě narození dozvěděla až
     * výjimkou. Stejnou chybu vracela ČSSZ cizímu programu u ONZ. Cesta
     * `identity.*` vede formulář na kartu osoby, kde se údaj zadává.
     *
     * @param array<string,mixed> $identity
     */
    private function identityPresent(array $identity): void
    {
        foreach ($this->identityKeys as $key) {
            $value = $identity[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                continue;
            }
            $this->fail(
                'registration_regzec_a1_required_field_missing',
                PayrollRegistrationFieldVocabulary::label($key)
                    . ' chybí — registraci na ČSSZ (REGZEC A1) bez toho podat '
                    . 'nejde. ' . PayrollRegistrationFieldVocabulary::describe($key),
                'identity.' . $key,
                'missing',
            );
        }
    }

    /**
     * „Práce probíhá převážně" (10258) je podle EDV 1.4.0.6 povinná jen
     * u zaměstnavatele uznaného na chráněném trhu práce (10211) a zároveň
     * u zaměstnance s vyplněným typem zdravotního omezení (10085). Jinde je
     * vyplnění ZAKÁZANÉ a podání by ČSSZ odmítla — proto se hodnota ze
     * snímku zahodí, ne jen přestane vyžadovat. Cizí programy ji u běžných
     * zaměstnanců neposílají vůbec.
     *
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $facts
     * @return array<string,mixed>
     */
    private function workplaceProgress(
        array $employment,
        string $variant,
        ?array $facts,
    ): array {
        $applies = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            && $this->protectedLaborMarket
            && $facts !== null
            && ($facts['health_restrictions'] ?? []) !== [];
        if (!$applies) {
            $employment['prevailing_workplace_code'] = null;

            return $employment;
        }
        if ($employment['prevailing_workplace_code'] === null) {
            $this->missing('employment.prevailing_workplace_code');
        }

        return $employment;
    }

    /**
     * Pracovní údaje, jejichž povinnost se mění podle státního občanství
     * (EDV 1.4.0.6): „předpokládaná místa výkonu práce" (ID 10526, OST i SPEC)
     * a „vzdělání požadované pro výkon profese" (ID 10248, jen OST) jsou
     * povinné u cizince a u občana ČR ZAKÁZANÉ. Zakázaná hodnota se ze snímku
     * zahodí, ne pošle — ČSSZ by podání odmítla.
     *
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function citizenshipDependentEmployment(
        array $employment,
        string $variant,
        string $citizenship,
    ): array {
        $expectedApplies = $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10;
        $educationApplies = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST;
        $foreigner = $citizenship !== '' && $citizenship !== 'CZ';
        if ($citizenship === '') {
            // Chybějící občanství je už nahlášené; dohadovat se o povinnosti
            // by přisypalo vady, které se mohou ukázat jako vymyšlené.
            return $employment;
        }
        if (!$expectedApplies || !$foreigner) {
            $employment['expected_workplaces'] = null;
        } elseif ($employment['expected_workplaces'] === null) {
            $this->missing('employment.expected_workplaces');
        }
        if (!$educationApplies || !$foreigner) {
            $employment['required_education_code'] = null;
        } elseif ($employment['required_education_code'] === null) {
            $this->missing('employment.required_education_code');
        }

        return $employment;
    }

    /**
     * Cizozemský nositel pojištění (`forin`, ID 10092–10101) v přihlášce A1-OST.
     *
     * Povinný je u druhu činnosti „N" (smluvní zaměstnanec) a jen se
     * specifikací P (poslední) nebo S (současný); jinde je nepovinný. Kdykoli
     * je vyplněný kterýkoli údaj, je povinný stát (10099), a kdykoli je
     * vyplněná kterákoli část adresy, jsou povinné číslo popisné, PSČ i obec
     * (10095, 10098, 10097). Hodnotu „N" (není) EDV vylučuje.
     *
     * @return array<string,string>|null `null` = oddíl není vyplněný
     */
    private function foreignInsurance(mixed $value, string $activityCode): ?array
    {
        $required = $activityCode === 'N';
        $input = null;
        if (is_array($value) && !array_is_list($value)) {
            $input = $value;
        } elseif ($value !== null && $value !== []) {
            $this->missing('foreign_insurance');

            return null;
        }
        $filled = [];
        foreach ($input ?? [] as $key => $item) {
            if (is_string($item) ? trim($item) !== '' : $item !== null) {
                $filled[] = $key;
            }
        }
        if ($filled === []) {
            if ($required) {
                $this->within('foreign_insurance', function (): void {
                    $this->missing('current');
                    $this->missing('country_code');
                });
            }

            return null;
        }

        return $this->within('foreign_insurance', function () use ($input, $required): array {
            $input ??= [];
            $current = $this->optionalText($input, 'current', 16);
            if ($current !== null && !in_array($current, ['P', 'S'], true)) {
                $this->malformed(
                    'current',
                    'musí být P (poslední nositel), nebo S (současný nositel), '
                        . 'teď je „' . $current . '".',
                    'foreign_insurance_current',
                    ['value' => $current],
                );
                $current = null;
            } elseif ($current === null && $required) {
                $this->missing('current');
            }
            $result = [
                'current' => $current,
                'name' => $this->optionalText($input, 'name', 100),
                'street' => $this->optionalText($input, 'street', 50),
                'house_number' => $this->optionalText($input, 'house_number', 12),
                'orientation_number' => $this->optionalText($input, 'orientation_number', 12),
                'postal_code' => $this->optionalText($input, 'postal_code', 11),
                'city' => $this->optionalText($input, 'city', 50),
                'country_code' => $this->optionalCountry($input, 'country_code'),
                'identifier' => $this->optionalText($input, 'identifier', 25),
                'sector' => $this->optionalText($input, 'sector', 2),
            ];
            if ($result['country_code'] === null) {
                $this->missing('country_code');
            }
            foreach (PayrollRegistrationForeignInsurerAddress::missing($result) as $key) {
                $this->missing($key);
            }
            if ($result['sector'] !== null
                && !PayrollRegistrationForeignInsurerSector::isKnown($result['sector'])
            ) {
                $this->malformed(
                    'sector',
                    'musí být kód z číselníku Sektor (01 až 08), teď je „'
                        . $result['sector'] . '".',
                    'foreign_insurance_sector',
                    ['value' => $result['sector']],
                );
                $result['sector'] = null;
            }

            return $result;
        });
    }

    /** @param array<string,mixed> $input */
    private function optionalCountry(array $input, string $key): ?string
    {
        if (($input[$key] ?? null) === null) {
            return null;
        }
        $value = $this->country($input, $key);

        return $value === '' ? null : $value;
    }

    /**
     * Postavení v zaměstnání: čtyřmístný kód NKPZ. Kratší kód (dřív se sem
     * vešly jen dva znaky) ČSSZ odmítá, takže se hlásí jako vadná hodnota.
     *
     * @param array<string,mixed> $input
     */
    private function employmentStatus(array $input): ?string
    {
        $value = $this->optionalText($input, 'employment_status_code', 16);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}$/D', $value) !== 1) {
            $this->malformed(
                'employment_status_code',
                'musí být čtyřmístný kód z číselníku Klasifikace postavení '
                    . 'v zaměstnání (NKPZ), například 1111 pro pracovní poměr '
                    . 'na dobu neurčitou; kratší kód ČSSZ nepřijme. Teď je „'
                    . $value . '".',
                'status_format',
                ['value' => $value],
            );

            return null;
        }
        if (!PayrollRegistrationEmploymentStatusCodebook::isKnown($value)) {
            $this->malformed(
                'employment_status_code',
                'není v číselníku Klasifikace postavení v zaměstnání (NKPZ), '
                    . 'teď je „' . $value . '". Vyberte kód z nabídky.',
                'status_unknown',
                ['value' => $value],
            );

            return null;
        }

        return $value;
    }

    /**
     * @param bool $czech adresa pobytu v ČR (`fdr`): schéma chce přesně pět číslic
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function address(array $input, bool $czech = false): array
    {
        // `fdr` (czAdrType) stát nemá a serializér ho nepíše; adresa pobytu
        // v ČR je z definice česká, takže chybějící stát není vada.
        $country = $czech && (
            !is_string($input['country_code'] ?? null)
            || trim($input['country_code']) === ''
        )
            ? 'CZ'
            : $this->country($input, 'country_code');
        // EDV 1.4.0.6: česká adresa (stát CZ a vždy pobyt v ČR) má číslo
        // popisné jen číselné a PSČ s první číslicí 1 až 7.
        $isCzech = $czech || $country === 'CZ';

        return [
            'street' => $this->optionalText($input, 'street', 255),
            'house_number' => $this->houseNumber($input, $isCzech, $czech),
            'orientation_number' => $this->orientationNumber($input, $isCzech, $czech),
            'city' => $this->text($input, 'city', 255),
            'postal_code' => $this->postalCode($input, 'postal_code', $isCzech),
            'country_code' => $country,
            'ruian_point' => $this->optionalText($input, 'ruian_point', 20),
        ];
    }

    /** @param array<string,mixed> $input */
    private function houseNumber(array $input, bool $isCzech, bool $czechResidence): string
    {
        $value = $this->text($input, 'house_number', 12);
        if ($value !== '' && $isCzech
            && !PayrollRegistrationHouseNumber::validDescriptive($value, $czechResidence)
        ) {
            $this->malformed(
                'house_number',
                'musí být u české adresy jen číslo o nejvýš čtyřech číslicích '
                    . '(bez písmene a lomítka), teď je „' . $value . '". '
                    . 'Orientační číslo patří do vlastního pole.',
                'house_number_cz',
                ['value' => $value],
            );

            return '';
        }

        return $value;
    }

    /**
     * EDV 1.4.0.6, ID 10079, 10508 a 10515: u české adresy (stát CZ i pobyt
     * v ČR) má orientační číslo nejvýš 4 znaky, jinak 12.
     *
     * @param array<string,mixed> $input
     */
    private function orientationNumber(array $input, bool $isCzech, bool $czechResidence): ?string
    {
        $value = $this->optionalText($input, 'orientation_number', 12);
        if ($value !== null && $isCzech
            && !PayrollRegistrationHouseNumber::validOrientation($value, true)
        ) {
            $this->malformed(
                'orientation_number',
                'smí mít u ' . ($czechResidence ? 'adresy pobytu v ČR' : 'české adresy')
                    . ' nejvýš ' . PayrollRegistrationHouseNumber::CZECH_ORIENTATION_MAX
                    . ' znaky, teď je „' . $value . '".',
                $czechResidence ? 'orientation_number_cz_residence' : 'orientation_number_cz',
                [
                    'max' => PayrollRegistrationHouseNumber::CZECH_ORIENTATION_MAX,
                    'value' => $value,
                ],
            );

            return null;
        }

        return $value;
    }

    /**
     * PSČ ve tvaru, který schéma ČSSZ přijme.
     *
     * Atribut `pnu` je ve všech adresách REGZEC25 na typu bez mezer
     * (`simpleA_NN_ZZType`, u `fdr` dokonce `\d{5}`), kdežto česká PSČ se píší
     * „602 00". Mezery se proto při sestavení snímku odstraní — za účetní, ne
     * hláškou — a profil se uloží už ve tvaru, který půjde na ČSSZ. Tvar PSČ
     * české adresy (pět číslic, první 1 až 7) se hlídá zvlášť.
     *
     * @param array<string,mixed> $input
     */
    private function postalCode(array $input, string $key, bool $czech): string
    {
        $value = $input[$key] ?? null;
        if (is_string($value)) {
            $input[$key] = preg_replace('/\s+/u', '', $value) ?? $value;
        }
        $postalCode = $this->text($input, $key, 11);
        if ($postalCode !== '' && $czech
            && PayrollRegistrationPostalCode::valid($postalCode, 'CZ') === null
        ) {
            $this->malformed(
                $key,
                'musí mít u adresy v ČR pět číslic a nesmí začínat 0, 8 ani 9 '
                    . '(například 60200), teď je „' . $postalCode . '".',
                'postal_code_cz',
                ['value' => $postalCode],
            );

            return '';
        }

        return $postalCode;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function taxResidency(array $input): array
    {
        $country = $this->country($input, 'country_code');
        $identifierType = $this->coded('identifier_type', $this->optionalText($input, 'identifier_type', 1), PayrollRegistrationCodebooks::TAX_IDENTIFIER_TYPE, 'Typ daňové identifikace');
        $identifier = $this->optionalText($input, 'identifier', 20);
        if ($country === 'CZ') {
            // EDV 1.4.0.6, ID 10061 a 10062: u rezidence v ČR je daňová
            // identifikace ZAKÁZANÁ. Hodnota ze zdroje se zahodí, ne pošle.
            $identifierType = null;
            $identifier = null;
        } elseif ($country !== '') {
            // U rezidence mimo ČR je typ i identifikátor POVINNÝ — obojí
            // zároveň, ne jedno z dvojice.
            if ($identifierType === null) {
                $this->missing('identifier_type');
            }
            if ($identifier === null) {
                $this->missing('identifier');
            }
        } elseif (($identifierType === null) !== ($identifier === null)) {
            $this->missing('identifier_pair');
        }
        $residence = $input['residence_address'] ?? null;
        $residenceAddress = null;
        // EDV 1.4.0.6, ID 10520 a další: u rezidence v ČR je adresa bydliště
        // ve státě rezidence ZAKÁZANÁ, hodnota ze zdroje se zahodí.
        if ($residence !== null && $country !== 'CZ') {
            $residenceInput = $this->object($input, 'residence_address');
            $residenceAddress = $this->within(
                'residence_address',
                fn (): array => $this->address($residenceInput),
            );
            // Stát bydliště (rdr/@cnt, 10524) je shodný se státem rezidence
            // (taxidrezid/@stat, 10068) a různý od CZ.
            if ($country !== ''
                && $residenceAddress['country_code'] !== ''
                && $residenceAddress['country_code'] !== $country
            ) {
                $this->malformed(
                    'residence_address.country_code',
                    'musí být shodný se státem daňové rezidence („' . $country
                        . '"), teď je „' . $residenceAddress['country_code'] . '".',
                    'residence_country_mismatch',
                    ['value' => $residenceAddress['country_code'], 'tax' => $country],
                );
            }
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
        $type = $this->coded('type_code', $this->optionalText($input, 'type_code', 3), PayrollRegistrationCodebooks::PENSION_TYPE, 'C_DUCH');
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
            $item = [
                'type_code' => (string) $this->coded('type_code', $this->text($restriction, 'type_code', 3), PayrollRegistrationCodebooks::HEALTH_RESTRICTION, 'Zdravotní omezení'),
                'from' => $this->date($restriction, 'from'),
                'to' => $this->optionalDate($restriction, 'to'),
            ];
            // EDV 1.4.0.6, ID 10086 a 10087: „přiznané do" musí být pozdější
            // než „přiznané od".
            $this->periodOrder('health_restrictions', $item['from'], $item['to']);
            $normalized[] = $item;
        }
        // REGZEC25.xsd (`fact/healtrest`) nemá `maxOccurs`, takže schéma
        // připouští jediné zdravotní omezení. Víc řádků by prošlo formulářem
        // a padlo by až na kontrole proti XSD technickou hláškou.
        if (count($normalized) > 1) {
            $this->malformed(
                'health_restrictions',
                'smí obsahovat jediné omezení — ČSSZ jich v jedné přihlášce '
                    . 'nepřijme víc, teď jich je ' . count($normalized)
                    . '. Ponechte omezení platné ke dni nástupu.',
                'health_restrictions_max',
                ['max' => 1, 'count' => count($normalized)],
            );
        }

        return [
            'highest_education_code' => $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
                ? (string) $this->coded('highest_education_code', $this->text($input, 'highest_education_code', 4), PayrollRegistrationCodebooks::EDUCATION, 'KKOV')
                : null,
            'disability_card' => $this->bool($input, 'disability_card'),
            'health_restrictions' => $normalized,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function foreignLegislation(array $input): array
    {
        $applies = $this->bool($input, 'applies');
        $country = $this->coded('country_code', $this->optionalText($input, 'country_code', 2), PayrollRegistrationCodebooks::COUNTRY, 'C_STAT');
        if ($applies && $country === null) {
            $this->missing('country_code');
        }
        // EDV 1.4.0.6, ID 10428: bez příslušnosti k cizím předpisům je kód
        // státu ZAKÁZANÝ; hodnota ze zdroje se zahodí.
        if (!$applies) {
            $country = null;
        }

        return ['applies' => $applies, 'country_code' => $country];
    }

    /**
     * @param bool $issuerRequired orgán, který doklad vydal (ID 10071), je
     *                             u cizince povinný v A1-OST i A1-SPEC
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function proofIdentity(array $input, bool $issuerRequired = true): array
    {
        return [
            'type_code' => (string) $this->coded('type_code', $this->text($input, 'type_code', 3), PayrollRegistrationCodebooks::PROOF_TYPE, 'Typ dokladu'),
            'number' => $this->text($input, 'number', 64),
            'foreign_issuer' => $issuerRequired
                ? $this->text($input, 'foreign_issuer', 100)
                : $this->optionalText($input, 'foreign_issuer', 100),
            'country_code' => $this->country($input, 'country_code'),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function foreignWorker(array $input): array
    {
        $freeAccess = $this->bool($input, 'free_access');
        $reason = $this->coded('free_access_reason_code', $this->optionalText($input, 'free_access_reason_code', 4), PayrollRegistrationCodebooks::FREE_ACCESS_REASON, 'Důvod pro volný přístup na trh práce');
        $permitType = $this->coded('permit_type_code', $this->optionalText($input, 'permit_type_code', 4), PayrollRegistrationCodebooks::PERMIT_TYPE, 'Druh pracovního oprávnění');
        $permitId = $this->optionalText($input, 'permit_identifier', 64);
        $permitFrom = $this->optionalDate($input, 'permit_from');
        $permitTo = $this->optionalDate($input, 'permit_to');
        $issuingOffice = $this->coded('issuing_labour_office_code', $this->optionalText($input, 'issuing_labour_office_code', 8), PayrollRegistrationCodebooks::LABOUR_OFFICE, 'Krajské pobočky ÚP ČR');
        if ($freeAccess && $reason === null) {
            $this->missing('free_access_reason_code');
        }
        // EDV 1.4.0.6, ID 10106 až 10110 a 10415: při volném přístupu jsou
        // údaje o pracovním oprávnění ZAKÁZANÉ, bez něj důvod volného
        // přístupu. Hodnota ze zdroje se zahodí, ne pošle.
        if ($freeAccess) {
            $permitType = null;
            $permitId = null;
            $permitFrom = null;
            $permitTo = null;
            $issuingOffice = null;
        } else {
            $reason = null;
        }
        $this->periodOrder('permit_to', $permitFrom, $permitTo);
        if (!$freeAccess
            && ($permitType === null || $permitId === null
                || $permitFrom === null || $permitTo === null)
        ) {
            $this->missing('permit');
        }
        // EDV 1.4.0.6, ID 10107: krajská pobočka ÚP ČR je povinná, je-li druh
        // oprávnění „povolení k zaměstnání", jinak ZAKÁZANÁ.
        if ($permitType === self::PERMIT_TYPE_EMPLOYMENT_PERMIT) {
            if (!$freeAccess && $issuingOffice === null) {
                $this->missing('issuing_labour_office_code');
            }
        } else {
            $issuingOffice = null;
        }

        return [
            'free_access' => $freeAccess,
            'free_access_reason_code' => $reason,
            'permit_type_code' => $permitType,
            'issuing_labour_office_code' => $issuingOffice,
            'permit_identifier' => $permitId,
            'permit_from' => $permitFrom,
            'permit_to' => $permitTo,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function attachments(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)
            || count($value) > PayrollRegistrationAttachmentRules::MAX_COUNT
        ) {
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
        foreach (PayrollRegistrationAttachmentRules::violations($result) as $violation) {
            $this->invalid(
                'registration_regzec_a1_attachments_invalid',
                PayrollRegistrationAttachmentRules::message($violation),
                'attachments',
                'attachment_' . $violation['kind'],
                ['name' => $violation['name']],
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function optionalAddress(
        array $input,
        string $key,
        bool $czech = false,
    ): ?array {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->missing($key);

            return null;
        }

        return $this->within(
            $key,
            fn (): array => $this->address($value, $czech),
        );
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
                'too_long',
                ['max' => $max, 'length' => $length],
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
                'digits',
                ['length' => $length],
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
                'country',
            );

            return '';
        }

        return (string) $this->coded($key, $value, PayrollRegistrationCodebooks::COUNTRY, 'C_STAT');
    }

    /**
     * Kód z číselníku EDV 1.4.0.6; prázdná hodnota projde (povinnost hlídá
     * volající), kód mimo číselník je vada pole a vrátí se `null`.
     *
     * @param list<string> $codebook
     */
    private function coded(string $field, ?string $value, array $codebook, string $name): ?string
    {
        if ($value === null || $value === '' || PayrollRegistrationCodebooks::contains($codebook, $value)) {
            return $value;
        }
        $this->malformed(
            $field,
            'není v číselníku ' . $name . ' (EDV REGZEC), teď je „' . $value . '". Vyberte kód z nabídky.',
            'codebook',
            ['value' => $value, 'codebook' => $name],
        );

        return null;
    }

    /** Konec období (`$to`) musí být pozdější než jeho začátek. */
    private function periodOrder(string $field, ?string $from, ?string $to): void
    {
        if ($from === null || $from === '' || $to === null || $to === '' || $to > $from) {
            return;
        }
        $this->malformed(
            $field,
            'musí končit později, než začíná: datum do (' . $to
                . ') není pozdější než datum od (' . $from . ').',
            'date_period',
            ['from' => $from, 'to' => $to],
        );
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
                'date',
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
            'missing',
        );
    }

    /**
     * Údaj vyplněný je, ale ve tvaru, který ČSSZ nepřijme.
     *
     * „Chybí" by tady lhalo — účetní by koukala na vyplněné pole a hledala
     * prázdné. `$expectation` proto musí říct, JAK má hodnota vypadat.
     */
    /** @param array<string,int|string> $params */
    private function malformed(
        string $field,
        string $expectation,
        string $messageKey,
        array $params = [],
    ): void {
        $path = $this->prefix . $field;
        $this->fail(
            'registration_regzec_a1_field_value_invalid',
            PayrollRegistrationFieldVocabulary::label($path) . ' ' . $expectation
                . ' ' . PayrollRegistrationFieldVocabulary::describe($path),
            $path,
            $messageKey,
            $params,
        );
    }

    /** @param array<string,int|string> $params */
    private function invalid(
        string $code,
        string $message,
        ?string $field = null,
        ?string $messageKey = null,
        array $params = [],
    ): void {
        $this->fail($code, $message, $field, $messageKey, $params);
    }

    /**
     * `message_key` + `params` nesou jazykově nezávislý tvar hlášky: frontend
     * ji podle nich přeloží (v anglickém UI se dřív ukazovala česká věta
     * serveru). Bez klíče se ukáže `message`.
     *
     * @param array<string,int|string> $params
     */
    private function fail(
        string $code,
        string $message,
        ?string $field,
        ?string $messageKey = null,
        array $params = [],
    ): void {
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
            'message_key' => $messageKey,
            'params' => $params,
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
