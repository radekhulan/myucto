<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\PayrollEuFreeMovementCountries;
use MyInvoice\Service\Payroll\SocialInsurance\PayrollExpectedParticipation;

/**
 * Sbírací vrstva nad kmenovými daty osoby a pracovního vztahu: složí NÁVRH
 * profilu REGZEC A1 a u každé hodnoty řekne, odkud pochází.
 *
 * Builder je záměrně čistý (bez repozitáře a bez zápisu) — profil se ukládá
 * výhradně do payroll_registration_a1_profiles a tenhle návrh je jen
 * předvyplnění formuláře. Co se z kmenových dat odvodit nedá, se NEDOMÝŠLÍ;
 * hlásí se konkrétně, kde ten údaj v aplikaci chybí.
 */
final class PayrollRegistrationA1DraftBuilder
{
    private const CROSS_BORDER_COUNTRIES = ['AT', 'DE', 'PL', 'SK'];

    /** @var array<string,string> */
    private array $sources = [];

    /** @var list<array{field:string,message:string}> */
    private array $missing = [];

    /**
     * @param array<string,mixed> $sources
     * @param array<string,mixed>|null $identity
     * @param array<string,mixed>|null $stored
     * @return array<string,mixed>
     */
    public function build(
        array $sources,
        ?array $identity,
        ?string $identityError,
        ?string $foreignTaxIdentifier,
        string $effectiveOn,
        int $rowVersion,
        ?array $stored,
        bool $submitted = false,
    ): array {
        $this->sources = [];
        $this->missing = [];

        $citizenship = $identity === null
            ? null
            : $this->text($identity['citizenship_country_code'] ?? null);
        if ($identity === null) {
            $this->miss(
                'identity',
                $identityError ?? 'K rozhodnému dni chybí historická identita '
                    . 'osoby. Doplňte ji na kartě osoby → Identita a adresy → '
                    . 'Historie jména → Údaje pro registraci zaměstnance.',
            );
        } elseif ($citizenship === null) {
            $this->miss(
                'identity.citizenship_country_code',
                'Osoba nemá k rozhodnému dni státní občanství. Doplňte je na '
                . 'kartě osoby → Identita a adresy → Historie jména → Údaje pro '
                . 'registraci zaměstnance — rozhoduje o povinných skupinách A1.',
            );
        }
        $foreigner = $citizenship !== null && $citizenship !== 'CZ';

        $terms = $this->section($sources, 'terms');
        $employmentRow = $this->section($sources, 'employment');
        $activityCode = $terms === null
            ? null
            : $this->text($terms['activity_code'] ?? null);
        $relationshipDetailCode = $terms === null
            ? null
            : $this->text($terms['relationship_detail_code'] ?? null);

        $variant = null;
        $variantError = null;
        try {
            $variant = PayrollRegistrationBusinessMatrix::requireActionVariant(
                1,
                $activityCode,
                $relationshipDetailCode,
                true,
            );
        } catch (PayrollRegistrationXmlException $exception) {
            $variantError = $exception->getMessage();
        }

        $permanentAddress = $this->address(
            $this->section($sources, 'permanent_address'),
            'permanent_address',
            'Adresa trvalého pobytu osoby (karta osoby → Identita a adresy '
            . '→ Historie adres).',
        );
        if ($permanentAddress === null) {
            $this->miss(
                'permanent_address',
                'Osoba nemá k rozhodnému dni evidovanou adresu trvalého '
                . 'pobytu. Doplňte ji na '
                . PayrollRegistrationFieldVocabulary::WHERE_ADDRESSES . '.',
            );
        }
        $permanentCountry = $permanentAddress['country_code'] ?? null;

        $contactAddress = $variant
            === PayrollRegistrationBusinessMatrix::VARIANT_OST
                ? $this->address(
                    $this->section($sources, 'contact_address'),
                    'contact_address',
                    'Kontaktní adresa osoby (karta osoby → Identita a adresy '
                    . '→ Historie adres).',
                )
                : null;

        $czechResidenceRequired = is_string($permanentCountry)
            && $permanentCountry !== 'CZ'
            && !in_array($permanentCountry, self::CROSS_BORDER_COUNTRIES, true)
            && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10;
        if ($czechResidenceRequired) {
            $this->miss(
                'czech_residence_address',
                'Osoba má trvalý pobyt mimo ČR, takže A1 vyžaduje adresu '
                . 'pobytu v ČR. Aplikace ji nevede, vyplňte ji ručně.',
            );
        }

        $taxResidency = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->taxResidency(
                $this->section($sources, 'tax_residence'),
                $foreignTaxIdentifier,
                $permanentAddress,
            );

        $employment = $this->employment(
            $terms,
            $employmentRow,
            $effectiveOn,
            $variant,
            $foreigner,
        );

        $healthInsuranceCode = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->healthInsuranceCode(
                $this->section($sources, 'health_coverage'),
            );

        $facts = $variant === PayrollRegistrationBusinessMatrix::VARIANT_10
            ? null
            : $this->facts(
                $variant,
                PayrollRegistrationEducationRule::mustBeNotRelevant($citizenship, $activityCode),
            );

        $pension = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->pension()
            : null;

        $foreignLegislation = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->foreignLegislation($terms)
            : null;

        // EDV 1.4.0.6: u A1-10 jsou proofid a nocitizen zakázané.
        $foreignGroups = $foreigner
            && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10;
        $proofIdentity = $foreignGroups
            ? $this->proofIdentity($citizenship)
            : null;
        $foreignWorker = $foreignGroups
            ? $this->foreignWorker($this->section($sources, 'work_permit'), $citizenship)
            : null;

        $foreignInsurance = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? $this->foreignInsurance($activityCode)
            : null;

        $suggested = [
            'effective_on' => $effectiveOn,
            'row_version' => $rowVersion,
            'permanent_address' => $permanentAddress ?? $this->emptyAddress(),
            'tax_residency' => $taxResidency,
            'employment' => $employment,
            'pension' => $pension,
            'health_insurance_code' => $healthInsuranceCode,
            'facts' => $facts,
            'foreign_legislation' => $foreignLegislation,
            'proof_identity' => $proofIdentity,
            'foreign_worker' => $foreignWorker,
            'czech_residence_address' => null,
            'contact_address' => $contactAddress,
            'attachments' => [],
        ];
        if ($foreignInsurance !== null) {
            $suggested['foreign_insurance'] = $foreignInsurance;
        }

        $differences = $this->diverged($stored, $suggested);

        return [
            'effective_on' => $effectiveOn,
            'row_version' => $rowVersion,
            'citizenship_country_code' => $citizenship,
            'foreigner' => $foreigner,
            'variant' => $variant,
            'variant_error' => $variantError,
            'suggested' => $suggested,
            'sources' => $this->sources,
            'missing' => $this->missing,
            'submitted' => $submitted,
            /*
             * Co je ve formuláři jinak než v kmenových datech. Je to NABÍDKA
             * K ZÁPISU, ne výtka: u každé položky se dá hodnota z formuláře
             * přenést zpátky do evidence osoby nebo pracovního vztahu.
             */
            'writeback' => $differences,
            /*
             * Varování „úřad má jinou hodnotu" dává smysl JEN u odeslané
             * registrace. U rozpracovaného profilu žádný nahlášený stav
             * neexistuje, takže se nehlásí nic — dřív se tu hlásil rozdíl
             * proti vlastnímu staršímu konceptu, se kterým nešlo nic dělat.
             */
            'diverged' => $submitted ? $differences : [],
        ];
    }

    /**
     * Rozdíl mezi uloženým snímkem a dnešními kmenovými daty.
     *
     * Slouží dvěma věcem, které volající rozlišuje: nabídce zápisu zpátky do
     * kmenových dat (vždy) a varování, že ODESLANÁ registrace nese jinou
     * hodnotu, než jaká dnes platí (jen po odeslání).
     *
     * Snímek se nikdy nepřepisuje sám — doložitelnost toho, co odešlo, je
     * přednější než pohodlí. Zapsat se dá opačným směrem (hodnota ze snímku
     * do kmenových dat), viz {@see PayrollRegistrationA1MasterDataFields};
     * `writable` a `reason` říkají, u kterého údaje to jde.
     *
     * @param array<string,mixed>|null $stored
     * @param array<string,mixed> $suggested
     * @return list<array{
     *   field:string,label:string,stored:?string,suggested:?string,
     *   writable:bool,reason:?string
     * }>
     */
    private function diverged(?array $stored, array $suggested): array
    {
        if ($stored === null) {
            return [];
        }
        $result = [];
        foreach (array_keys($this->sources) as $path) {
            $storedValue = $this->scalar($this->at($stored, $path));
            $suggestedValue = $this->scalar($this->at($suggested, $path));
            if ($storedValue === $suggestedValue) {
                continue;
            }
            $result[] = [
                'field' => $path,
                'label' => PayrollRegistrationFieldVocabulary::label($path),
                'stored' => $storedValue,
                'suggested' => $suggestedValue,
                'writable' => PayrollRegistrationA1MasterDataFields::writable(
                    $path,
                ),
                'reason' => PayrollRegistrationA1MasterDataFields::blockedReason(
                    $path,
                ),
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $root */
    private function at(array $root, string $path): mixed
    {
        $node = $root;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function address(?array $row, string $path, string $label): ?array
    {
        if ($row === null) {
            return null;
        }
        $street = $this->text($row['street_line'] ?? null);
        $city = $this->text($row['city'] ?? null);
        // PSČ jde na ČSSZ bez mezer (viz A1 builder snímku); návrh ho proto
        // nabízí ve stejném tvaru, ať se „602 00" v evidenci nehlásí jako
        // rozdíl proti uloženému „60200".
        $postalCode = $this->text($row['postal_code'] ?? null);
        if ($postalCode !== null) {
            $postalCode = preg_replace('/\s+/u', '', $postalCode) ?? $postalCode;
        }
        $country = $this->text($row['country_code'] ?? null);
        if ($street !== null) {
            $this->source("{$path}.street", $label);
        }
        if ($city !== null) {
            $this->source("{$path}.city", $label);
        }
        if ($postalCode !== null) {
            $this->source("{$path}.postal_code", $label);
        }
        if ($country !== null) {
            $this->source("{$path}.country_code", $label);
        }
        $this->miss(
            "{$path}.house_number",
            'Aplikace vede adresu jedním řádkem včetně čísla, samostatné '
            . 'číslo popisné nezná. Opište je z personálního podkladu.',
        );

        return [
            'street' => $street,
            'house_number' => null,
            'orientation_number' => null,
            'city' => $city,
            'postal_code' => $postalCode,
            'country_code' => $country,
            'ruian_point' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyAddress(): array
    {
        return [
            'street' => null,
            'house_number' => null,
            'orientation_number' => null,
            'city' => null,
            'postal_code' => null,
            'country_code' => null,
            'ruian_point' => null,
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed>|null $permanentAddress
     * @return array<string,mixed>
     */
    private function taxResidency(
        ?array $row,
        ?string $foreignTaxIdentifier,
        ?array $permanentAddress,
    ): array {
        $residence = $row === null
            ? null
            : $this->text($row['residence'] ?? null);
        $country = null;
        if ($residence === 'czech-resident') {
            $country = 'CZ';
        } elseif ($residence === 'non-resident') {
            $country = $row === null
                ? null
                : $this->text($row['country_code'] ?? null);
        }
        if ($country === null) {
            $this->miss(
                'tax_residency.country_code',
                'Daňová rezidence osoby není k rozhodnému dni ověřená. '
                . 'Doplňte ji na kartě osoby (Zákonná evidence → daňová '
                . 'rezidence).',
            );
        } else {
            $this->source(
                'tax_residency.country_code',
                'Daňová rezidence osoby (karta osoby → Zákonná evidence).',
            );
        }

        $identifier = null;
        if ($country !== null && $country !== 'CZ'
            && $foreignTaxIdentifier !== null
        ) {
            $identifier = $foreignTaxIdentifier;
            $this->source(
                'tax_residency.identifier',
                'Zahraniční daňový identifikátor osoby (karta osoby → '
                . 'Kontakty a identifikátory).',
            );
            $this->miss(
                'tax_residency.identifier_type',
                'Aplikace nevede typ zahraničního daňového identifikátoru. '
                . 'Vyberte jej ručně — u daňového rezidenta jiného státu '
                . 'než ČR je typ i identifikátor povinný.',
            );
        } elseif ($country !== null && $country !== 'CZ') {
            $this->miss(
                'tax_residency.identifier',
                'Daňový rezident jiného státu než ČR musí mít zahraniční '
                . 'daňový identifikátor i jeho typ. Identifikátor doplňte na '
                . 'kartě osoby (Kontakty a identifikátory), typ vyberte ručně.',
            );
        }

        $residenceAddress = null;
        if ($country !== null && $country !== 'CZ') {
            if ($permanentAddress !== null
                && ($permanentAddress['country_code'] ?? null) === $country
            ) {
                $residenceAddress = $permanentAddress;
            } else {
                $this->miss(
                    'tax_residency.residence_address',
                    'Daňový rezident jiného státu vyžaduje adresu bydliště ve '
                    . 'státě rezidence. Aplikace ji odděleně nevede, vyplňte '
                    . 'ji ručně.',
                );
            }
        }

        return [
            'country_code' => $country,
            'identifier_type' => null,
            'identifier' => $identifier,
            'residence_address' => $residenceAddress,
        ];
    }

    /**
     * @param array<string,mixed>|null $terms
     * @param array<string,mixed>|null $employmentRow
     * @return array<string,mixed>
     */
    private function employment(
        ?array $terms,
        ?array $employmentRow,
        string $effectiveOn,
        ?string $variant,
        bool $foreigner = false,
    ): array {
        if ($terms === null) {
            $this->miss(
                'employment',
                'Pracovní vztah nemá k rozhodnému dni platné sjednané '
                . 'podmínky. Doplňte je na kartě pracovního vztahu.',
            );
        }
        $activityCode = $terms === null
            ? null
            : $this->text($terms['activity_code'] ?? null);
        if ($activityCode === null) {
            $this->miss(
                'employment.activity_code',
                'Pracovní vztah nemá druh činnosti pro ČSSZ. Doplňte jej na '
                . 'kartě pracovního vztahu (JMHZ / registrace).',
            );
        } else {
            $this->source(
                'employment.activity_code',
                'Sjednané podmínky pracovního vztahu (druh činnosti).',
            );
        }
        $relationshipDetailCode = $terms === null
            ? null
            : $this->text($terms['relationship_detail_code'] ?? null);
        if ($relationshipDetailCode !== null) {
            $this->source(
                'employment.relationship_detail_code',
                'Sjednané podmínky pracovního vztahu (bližší určení PPV).',
            );
        }
        $this->source(
            'employment.actual_start_on',
            'Skutečné datum nástupu pracovního vztahu.',
        );
        $contractStartOn = $terms === null
            ? null
            : $this->text($terms['planned_start_on'] ?? null);
        if ($contractStartOn !== null) {
            $this->source(
                'employment.contract_start_on',
                'Sjednané podmínky pracovního vztahu (sjednaný den nástupu).',
            );
        }
        $smallScale = null;
        $relationType = $employmentRow === null
            ? null
            : $this->text($employmentRow['relation_type'] ?? null);
        if ($relationType === 'dpc') {
            // DPČ se sjednanou odměnou pod rozhodným příjmem je zaměstnáním
            // malého rozsahu (matice REGZEC25 job.zmr-01, job.sme-05); tutéž
            // hranici používá výpočet účasti na pojištění.
            $meets = PayrollExpectedParticipation::agreedIncomeMeetsThreshold(
                $terms === null || !is_numeric($terms['monthly_gross_minor'] ?? null)
                    ? null
                    : (int) $terms['monthly_gross_minor'],
                $terms === null || !is_numeric($terms['small_scale_threshold_minor'] ?? null)
                    ? null
                    : (int) $terms['small_scale_threshold_minor'],
            );
            // Bez sjednané odměny (typicky převzatý vztah) zůstává dosavadní
            // „ne": dohlášení A3 příznak nenese a jeho vynucení by zastavilo
            // registraci, kterou ČSSZ přijme. Zdroj to řekne, ať ho účetní
            // u A1 zkontroluje.
            $smallScale = $meets === null ? false : !$meets;
            $this->source(
                'employment.small_scale',
                $meets === null
                    ? 'Sjednaná měsíční odměna DPČ chybí, navrženo „ne". U odměny '
                        . 'pod rozhodným příjmem jde o zaměstnání malého rozsahu — '
                        . 'doplňte odměnu v sjednaných podmínkách nebo příznak opravte.'
                    : 'Sjednaná měsíční odměna DPČ proti rozhodnému příjmu.',
            );
        } elseif ($relationType !== null) {
            $smallScale = $relationType === 'small_scale_employment';
            $this->source(
                'employment.small_scale',
                'Druh pracovního vztahu v evidenci.',
            );
        }
        $contractWorkplace = $terms === null
            ? null
            : $this->text($terms['work_place'] ?? null);
        if ($contractWorkplace !== null) {
            $this->source(
                'employment.contract_workplace',
                'Sjednané podmínky pracovního vztahu (místo výkonu práce).',
            );
        }
        $municipalityCode = $terms === null
            ? null
            : $this->text($terms['workplace_municipality_code'] ?? null);
        if ($municipalityCode !== null) {
            $this->source(
                'employment.workplace_municipality_code',
                'Sjednané podmínky pracovního vztahu (kód obce pracoviště).',
            );
        }
        $professionCode = $terms === null
            ? null
            : $this->text($terms['cz_isco_code'] ?? null);
        if ($professionCode !== null) {
            $this->source(
                'employment.profession_code',
                'Sjednané podmínky pracovního vztahu (CZ-ISCO).',
            );
        }

        $employmentStatusCode = $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            ? PayrollRegistrationEmploymentStatusCodebook::suggest(
                $relationType,
                $activityCode,
                $terms !== null && $this->text($terms['fixed_term_end_on'] ?? null) !== null,
            )
            : null;
        if ($employmentStatusCode !== null) {
            $this->source(
                'employment.employment_status_code',
                'Návrh podle druhu pracovního vztahu a doby určité v evidenci — '
                . 'zkontrolujte ho proti smlouvě.',
            );
        }

        $untracked = match ($variant) {
            PayrollRegistrationBusinessMatrix::VARIANT_OST => [
                'employment_status_code' => 'postavení v zaměstnání',
                'work_mode_code' => 'režim práce',
                'continuous_operation' => 'nepřetržitý provoz',
                'workplace_city' => 'obec pracoviště',
                'position_name' => 'název pracovní pozice',
                'leadership' => 'vedoucí pozice',
            ],
            PayrollRegistrationBusinessMatrix::VARIANT_SPEC => [
                'workplace_city' => 'obec pracoviště',
            ],
            default => [],
        };
        if ($employmentStatusCode !== null) {
            unset($untracked['employment_status_code']);
        }
        foreach ($untracked as $field => $label) {
            $this->miss(
                "employment.{$field}",
                "Varianta A1-{$variant} vyžaduje {$label}; aplikace tento "
                . 'údaj nevede. Vyplňte jej ručně z personálního podkladu.',
            );
        }
        // EDV 1.4.0.6, ID 10526 a 10248: u zaměstnance bez českého občanství
        // povinné, u občana ČR zakázané (z profilu se vyřadí).
        if ($foreigner && $variant !== PayrollRegistrationBusinessMatrix::VARIANT_10) {
            $this->miss(
                'employment.expected_workplaces',
                'Zaměstnanec bez českého státního občanství musí mít '
                . 'předpokládaná místa výkonu práce (sídlo zaměstnavatele, '
                . 'provozovna, obec…). Aplikace je nevede, vyplňte je ručně.',
            );
        }
        if ($foreigner && $variant === PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            $this->miss(
                'employment.required_education_code',
                'Zaměstnanec bez českého státního občanství musí mít '
                . 'vzdělání požadované pro výkon profese (podle volného '
                . 'pracovního místa). Aplikace je nevede, vyberte je ručně.',
            );
        }

        return [
            'activity_code' => $activityCode,
            'relationship_detail_code' => $relationshipDetailCode,
            'actual_start_on' => $effectiveOn,
            'contract_start_on' => $contractStartOn,
            'small_scale' => $smallScale,
            'employment_status_code' => $employmentStatusCode,
            'work_mode_code' => null,
            'continuous_operation' => null,
            'prevailing_workplace_code' => null,
            'expected_workplaces' => null,
            'contract_workplace' => $contractWorkplace,
            'workplace_city' => null,
            'workplace_municipality_code' => $municipalityCode,
            'profession_code' => $professionCode,
            'required_education_code' => null,
            'position_name' => null,
            'leadership' => null,
        ];
    }

    /** @param array<string,mixed>|null $row */
    private function healthInsuranceCode(?array $row): ?string
    {
        $status = $row === null
            ? null
            : $this->text($row['insurer_status'] ?? null);
        $code = $row === null
            ? null
            : $this->text($row['insurer_code'] ?? null);
        if ($status !== 'verified' || $code === null) {
            $this->miss(
                'health_insurance_code',
                'Zdravotní pojišťovna osoby není k rozhodnému dni ověřená. '
                . 'Doplňte ji na kartě osoby (Zákonná evidence → zdravotní '
                . 'pojištění).',
            );

            return null;
        }
        $this->source(
            'health_insurance_code',
            'Ověřená zdravotní pojišťovna osoby (karta osoby → Zákonná '
            . 'evidence).',
        );

        return $code;
    }

    /** @return array<string,mixed> */
    private function facts(?string $variant, bool $educationNotRelevant): array
    {
        $education = null;
        if ($variant === PayrollRegistrationBusinessMatrix::VARIANT_OST
            && $educationNotRelevant
        ) {
            $education = PayrollRegistrationEducationRule::NOT_RELEVANT;
            $this->source(
                'facts.highest_education_code',
                'U občana ČR s dohodou se vzdělání nesleduje, uvádí se „Z" '
                . '(nerelevantní) podle Zásad REGZEC, atribut 10091.',
            );
        } elseif ($variant === PayrollRegistrationBusinessMatrix::VARIANT_OST) {
            $this->miss(
                'facts.highest_education_code',
                'Aplikace nevede nejvyšší dosažené vzdělání. Vyberte je ručně '
                . 'z personálního podkladu.',
            );
        }
        $this->miss(
            'facts.disability_card',
            'Aplikace nevede průkaz osoby se zdravotním postižením pro účely '
            . 'REGZEC (sleva ZTP/P je jiný údaj). Potvrďte jej ručně.',
        );

        return [
            'highest_education_code' => $education,
            'disability_card' => false,
            'health_restrictions' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function pension(): array
    {
        $this->miss(
            'pension',
            'Aplikace nevede důchodové údaje osoby. Potvrďte je ručně; '
            . 'nevyplněný důchod A1 chápe jako „nepobírá".',
        );

        return [
            'type_code' => null,
            'received_from' => null,
            'early_retirement' => false,
            'reduced_retirement_age' => false,
        ];
    }

    /**
     * @param array<string,mixed>|null $terms
     * @return array<string,mixed>
     */
    private function foreignLegislation(?array $terms): array
    {
        $country = $terms === null
            ? null
            : $this->text($terms['foreign_legislation_country_code'] ?? null);
        $this->source(
            'foreign_legislation.applies',
            'Sjednané podmínky pracovního vztahu (zahraniční legislativa).',
        );
        if ($country !== null) {
            $this->source(
                'foreign_legislation.country_code',
                'Sjednané podmínky pracovního vztahu (stát zahraniční '
                . 'legislativy).',
            );
        }

        return [
            'applies' => $country !== null,
            'country_code' => $country,
        ];
    }

    /**
     * Cizozemský nositel pojištění (`forin`): povinný u druhu činnosti „N",
     * jinak nepovinný. Aplikace údaje o nositeli nevede, takže návrh je prázdný.
     *
     * @return array<string,mixed>
     */
    private function foreignInsurance(?string $activityCode): array
    {
        if ($activityCode === 'N') {
            $this->miss(
                'foreign_insurance.current',
                'Druh činnosti „N" (smluvní zaměstnanec) vyžaduje cizozemského '
                . 'nositele pojištění: vyberte specifikaci (poslední, nebo '
                . 'současný) a vyplňte stát. Aplikace údaje o nositeli nevede.',
            );
        }

        return [
            'current' => null,
            'name' => null,
            'street' => null,
            'house_number' => null,
            'orientation_number' => null,
            'postal_code' => null,
            'city' => null,
            'country_code' => null,
            'identifier' => null,
            'sector' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function proofIdentity(?string $citizenship): array
    {
        $this->miss(
            'proof_identity.type_code',
            'Aplikace nevede typ dokladu totožnosti. U cizince je ověřený '
            . 'doklad v A1 povinný — vyberte typ ručně.',
        );
        $this->miss(
            'proof_identity.number',
            'Aplikace nevede číslo dokladu totožnosti. U cizince je ověřený '
            . 'doklad v A1 povinný — opište číslo z dokladu.',
        );
        $this->miss(
            'proof_identity.foreign_issuer',
            'Aplikace nevede orgán, který doklad vydal. U cizince je povinný '
            . '(název a obec úřadu, např. „Municipal office, Preston"; není-li '
            . 'obec známa, stát). Opište jej z dokladu.',
        );
        if ($citizenship !== null) {
            $this->source(
                'proof_identity.country_code',
                'Státní občanství osoby (karta osoby → Identita a adresy → '
                . 'Historie jména → Údaje pro registraci zaměstnance).',
            );
        }

        return [
            'type_code' => null,
            'number' => null,
            'foreign_issuer' => null,
            'country_code' => $citizenship,
        ];
    }

    /**
     * @param array<string,mixed>|null $permit
     * @return array<string,mixed>
     */
    private function foreignWorker(?array $permit, ?string $citizenship = null): array
    {
        // Občan EU/EHP/Švýcarska má volný přístup na trh práce ze zákona
        // (§ 87 zákona o zaměstnanosti) — povolení nemá a nepotřebuje, takže
        // se návrh nesmí dožadovat „platného pracovního oprávnění".
        if (PayrollEuFreeMovementCountries::contains($citizenship)) {
            $this->source(
                'foreign_worker.free_access',
                'Státní občanství v EU/EHP nebo Švýcarsku — volný přístup na '
                . 'trh práce ze zákona.',
            );
            $this->source(
                'foreign_worker.free_access_reason_code',
                'Státní občanství v EU/EHP nebo Švýcarsku (důvod 1).',
            );

            return [
                'free_access' => true,
                'free_access_reason_code' => PayrollEuFreeMovementCountries::FREE_ACCESS_REASON_CODE,
                'permit_type_code' => null,
                'issuing_labour_office_code' => null,
                'permit_identifier' => null,
                'permit_from' => null,
                'permit_to' => null,
            ];
        }
        $this->miss(
            'foreign_worker.free_access',
            'Aplikace nevede volný přístup na trh práce. Potvrďte jej ručně; '
            . 'bez něj A1 vyžaduje úplné povolení k zaměstnání.',
        );
        $this->miss(
            'foreign_worker.issuing_labour_office_code',
            'Je-li druhem oprávnění povolení k zaměstnání, je povinná krajská '
            . 'pobočka ÚP ČR, která ho vydala; u jiných druhů oprávnění se '
            . 'neuvádí. Aplikace pobočku nevede, vyberte ji ručně.',
        );
        $from = $permit === null
            ? null
            : $this->text($permit['effective_from'] ?? null);
        $to = $permit === null
            ? null
            : $this->text($permit['valid_until'] ?? null);
        if ($from !== null && $to !== null) {
            $this->source(
                'foreign_worker.permit_from',
                'Pracovní oprávnění cizince (karta osoby → Oprávnění).',
            );
            $this->source(
                'foreign_worker.permit_to',
                'Pracovní oprávnění cizince (karta osoby → Oprávnění).',
            );
            $this->miss(
                'foreign_worker.permit_identifier',
                'Aplikace vede u pracovního oprávnění jen jeho označení, ne '
                . 'číslo rozhodnutí ani kód typu. Opište je z rozhodnutí.',
            );
        } else {
            $this->miss(
                'foreign_worker.permit',
                'Osoba nemá k rozhodnému dni platné pracovní oprávnění. '
                . 'Doplňte je na kartě osoby (Oprávnění), nebo potvrďte volný '
                . 'přístup na trh práce.',
            );
        }

        return [
            'free_access' => null,
            'free_access_reason_code' => null,
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => $from,
            'permit_to' => $to,
        ];
    }

    /**
     * @param array<string,mixed> $sources
     * @return array<string,mixed>|null
     */
    private function section(array $sources, string $key): ?array
    {
        $value = $sources[$key] ?? null;

        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function source(string $path, string $label): void
    {
        $this->sources[$path] = $label;
    }

    private function miss(string $field, string $message): void
    {
        $this->missing[] = ['field' => $field, 'message' => $message];
    }
}
