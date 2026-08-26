<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * @phpstan-type TermsInput array{
 *   office_id:?int,
 *   effective_from:string,
 *   contract_signed_on:?string,
 *   planned_start_on:string,
 *   actual_start_on:?string,
 *   fixed_term_end_on:?string,
 *   weekly_hours:?string,
 *   leave_entitlement_weeks_override:?int,
 *   workload_basis_points:int,
 *   work_place:?string,
 *   regular_workplace:?string,
 *   jmhz_workplace_municipality_code:?string,
 *   jmhz_workplace_country_code:?string,
 *   jmhz_external_codebook_overlay_key:?string,
 *   jmhz_external_codebook_manifest_sha256:?string,
 *   jmhz_apz_contribution_status:string,
 *   jmhz_apz_instrument_code:?string,
 *   jmhz_functional_benefits_status:string,
 *   jmhz_temporary_assignment_status:string,
 *   jmhz_orchard_discount_eligible:bool,
 *   jmhz_specific_legal_fact_applies:bool,
 *   jmhz_ozp_employment_support_applies:bool,
 *   jmhz_deep_mining_work_applies:bool,
 *   cz_isco_code:?string,
 *   activity_code:?string,
 *   jmhz_relationship_detail_code:?string,
 *   social_insurance_participation:string,
 *   health_insurance_participation:string,
 *   tax_regime:string,
 *   other_withholding_eligibility:string,
 *   foreign_legislation_country_code:?string,
 *   a1_certificate_until:?string,
 *   risky_work:bool,
 *   social_employer_rate_category:string,
 *   social_employer_rate_category_evidence:?string,
 *   social_part_time_discount_reason:string,
 *   social_part_time_discount_evidence:?string,
 *   social_part_time_discount_notified_on:?string,
 *   tax_declaration_signed:bool,
 *   is_primary:bool,
 *   change_reason:?string
 * }
 * @phpstan-type EmploymentCreateInput array{
 *   code:string,
 *   relation_type:string,
 *   monthly_gross_minor:?int,
 *   terms:TermsInput
 * }
 */
final class PayrollEmploymentValidator
{
    private const RELATION_TYPES = [
        'employment',
        'small_scale_employment',
        'dpp',
        'dpc',
        'partner_dependent',
        'statutory_body',
    ];

    private const INSURANCE_MODES = ['automatic', 'included', 'excluded', 'foreign'];
    private const TAX_REGIMES = ['advance', 'withholding', 'foreign', 'manual_review'];
    /** Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP — viz migrace 1403. */
    private const OTHER_WITHHOLDING_ELIGIBILITIES = ['unverified', 'eligible', 'ineligible'];
    /** § 5a odst. 1 písm. a) až c) ZPSZ — viz migrace 1510. */
    private const SOCIAL_EMPLOYER_RATE_CATEGORIES = [
        'ordinary',
        'rescue_and_company_fire_service',
        'risk_employment',
    ];
    /** § 7a odst. 1 písm. a) až g) ZPSZ — viz migrace 1550. */
    private const SOCIAL_PART_TIME_DISCOUNT_REASONS = [
        'none',
        'age_55_plus',
        'child_care_under_10',
        'dependent_close_person_care',
        'study_under_26',
        'retraining_jobseeker',
        'disabled_person',
        'under_21',
    ];
    private const CHECKLIST_STATUSES = ['pending', 'completed', 'not_applicable'];
    private const VERIFIED_STATES = ['unverified', 'no', 'yes'];

    public function __construct(
        private readonly PayrollEmploymentJmhzEvidenceCatalog $jmhzEvidence,
        private readonly CzIscoCodebook $czIsco,
    ) {}

    /**
     * Označení vztahu při přejmenování. Na rozdíl od zakládání je tady povinné —
     * „vygeneruj mi nové číslo" není akce, kterou by kdo hledal.
     *
     * @param array<string,mixed> $input
     */
    public function code(array $input): string
    {
        $code = trim($this->inputString($input['code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $code)) {
            throw new \InvalidArgumentException('Označení pracovního vztahu není platné.');
        }

        return $code;
    }

    /** @param array<string,mixed> $input
     *  @return EmploymentCreateInput
     */
    public function create(array $input): array
    {
        /*
         * Kód je nepovinný a prázdný znamená „vygeneruj".
         *
         * Býval povinný a uživatel ho vymýšlel jako první pole formuláře, přestože
         * ho nepotřebuje žádný zákonný výstup — neobjeví se v registraci ČSSZ,
         * v ELDP, v JMHZ, v zápočtovém listu ani v PDF. Je to interní popisek
         * a párovací klíč CSV importu docházky. Průvodce založením osoby si ho
         * ostatně generoval odjakživa sám.
         */
        $code = trim($this->inputString($input['code'] ?? ''));
        if ($code !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $code)) {
            throw new \InvalidArgumentException('Kód pracovního vztahu není platný.');
        }
        $relationType = $this->inputString($input['relation_type'] ?? '');
        if (!in_array($relationType, self::RELATION_TYPES, true)) {
            throw new \InvalidArgumentException('Typ pracovního vztahu není podporován.');
        }
        $gross = $input['monthly_gross_minor'] ?? null;
        if ($gross !== null && (!is_int($gross) || $gross < 0)) {
            throw new \InvalidArgumentException('Pravidelná hrubá mzda musí být nezáporná částka v haléřích.');
        }
        if (!is_array($input['terms'] ?? null)) {
            throw new \InvalidArgumentException('Chybí počáteční smluvní podmínky.');
        }

        $terms = $this->terms($this->stringKeyed($input['terms']), relationType: $relationType);
        if ($terms['actual_start_on'] !== null) {
            throw new \InvalidArgumentException(
                'Skutečný nástup se zaznamenává přechodem pracovního vztahu do aktivního stavu.'
            );
        }

        return [
            'code' => $code,
            'relation_type' => $relationType,
            'monthly_gross_minor' => $gross,
            'terms' => $terms,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param ?string $storedCzIscoCode Kód CZ-ISCO, který u tohoto vztahu už
     *        v databázi je. Předává ho zápisová cesta, nikdy klient — je to
     *        jediný důvod, proč smí projít kód mimo číselník (viz
     *        {@see optionalCzIscoCode()}).
     * @param ?string $storedOtherWithholdingEligibility Prohlášení plátce podle
     *        § 6 odst. 4 písm. b) ZDP, které u vztahu už platí. Taky ho předává
     *        zápisová cesta: obrazovky, které o poli nevědí (rychlá editace,
     *        založení vztahu ze seznamu), posílají podmínky bez něj a nesmí ho
     *        tím zahodit — viz {@see otherWithholdingEligibility()}.
     * @param ?string $relationType Druh vztahu načtený serverem nebo právě
     *        validovaný při založení; váže rodinu činností JMHZ k právnímu typu.
     * @return TermsInput
     */
    public function terms(
        array $input,
        ?string $storedCzIscoCode = null,
        ?string $storedOtherWithholdingEligibility = null,
        ?string $relationType = null,
    ): array {
        $effectiveFrom = $this->requiredDate($input, 'effective_from');
        $plannedStart = $this->requiredDate($input, 'planned_start_on');
        $fixedEnd = $this->optionalDate($input, 'fixed_term_end_on');
        if ($fixedEnd !== null && $fixedEnd < $plannedStart) {
            throw new \InvalidArgumentException('Konec doby určité nesmí předcházet plánovanému nástupu.');
        }

        $officeId = $input['office_id'] ?? null;
        if ($officeId !== null && (!is_int($officeId) || $officeId <= 0)) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }
        $hours = $input['weekly_hours'] ?? null;
        if ($hours !== null) {
            if ((!is_string($hours) && !is_int($hours))
                || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $hours, $parts) !== 1) {
                throw new \InvalidArgumentException('Týdenní pracovní doba není platná.');
            }
            $whole = (int) $parts[1];
            $fraction = str_pad($parts[2] ?? '', 2, '0');
            $centiHours = ($whole * 100) + (int) $fraction;
            if ($centiHours <= 0 || $centiHours > 16800) {
                throw new \InvalidArgumentException('Týdenní pracovní doba musí být větší než nula a nejvýše 168 hodin.');
            }
            $hours = sprintf('%d.%02d', intdiv($centiHours, 100), $centiHours % 100);
        }
        $workload = $input['workload_basis_points'] ?? 10000;
        if (!is_int($workload) || $workload < 1 || $workload > 10000) {
            throw new \InvalidArgumentException('Úvazek musí být od 0,01 % do 100 %.');
        }

        $social = $this->inputString($input['social_insurance_participation'] ?? 'automatic');
        $health = $this->inputString($input['health_insurance_participation'] ?? 'automatic');
        $tax = $this->inputString($input['tax_regime'] ?? 'advance');
        if (!in_array($social, self::INSURANCE_MODES, true)
            || !in_array($health, self::INSURANCE_MODES, true)
            || !in_array($tax, self::TAX_REGIMES, true)) {
            throw new \InvalidArgumentException('Pojistný nebo daňový režim není podporován.');
        }
        $country = strtoupper(trim($this->inputString($input['foreign_legislation_country_code'] ?? '')));
        $country = $country === '' ? null : $country;
        if ($country !== null && !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('Kód státu cizích právních předpisů není platný.');
        }
        if (($social === 'foreign' || $health === 'foreign' || $tax === 'foreign') && $country === null) {
            throw new \InvalidArgumentException('Cizí režim vyžaduje kód státu právních předpisů.');
        }

        $workPlace = $this->optionalText($input, 'work_place', 255);
        $municipalityCode = $this->optionalText($input, 'jmhz_workplace_municipality_code', 6);
        $workplaceCountry = strtoupper(
            $this->optionalText($input, 'jmhz_workplace_country_code', 2) ?? '',
        );
        $workplaceCountry = $workplaceCountry === '' ? null : $workplaceCountry;
        if ($municipalityCode === null) {
            if ($workplaceCountry !== null) {
                throw new \InvalidArgumentException(
                    'Pracoviště JMHZ vyžaduje k místu výkonu práce současně šestimístný kód obce a kód státu.',
                );
            }
        } else {
            if ($workplaceCountry === null || $workPlace === null) {
                throw new \InvalidArgumentException(
                    'Pracoviště JMHZ vyžaduje k místu výkonu práce současně šestimístný kód obce a kód státu.',
                );
            }
            if (preg_match('/^[0-9]{6}$/', $municipalityCode) !== 1) {
                throw new \InvalidArgumentException('Kód obce pracoviště JMHZ musí mít přesně šest číslic.');
            }
            if (preg_match('/^[A-Z]{2}$/', $workplaceCountry) !== 1) {
                throw new \InvalidArgumentException('Kód státu pracoviště JMHZ musí mít dvě velká písmena.');
            }
            $this->jmhzEvidence->requireWorkplace(
                $municipalityCode,
                $workPlace,
                $workplaceCountry,
                $effectiveFrom,
            );
        }
        $externalCodebook = $municipalityCode === null
            ? null
            : $this->jmhzEvidence->externalCodebookProvenance($effectiveFrom);

        $apzStatus = $this->verifiedState($input, 'jmhz_apz_contribution_status');
        $apzCode = $this->optionalText($input, 'jmhz_apz_instrument_code', 8);
        if ($apzStatus === 'yes') {
            if ($apzCode === null) {
                throw new \InvalidArgumentException('Příspěvek APZ vyžaduje kód nástroje APZ.');
            }
            $this->jmhzEvidence->requireApzInstrument($apzCode);
        } elseif ($apzCode !== null) {
            throw new \InvalidArgumentException('Bez příspěvku APZ nesmí být kód nástroje APZ vyplněn.');
        }
        $functionalBenefits = $this->verifiedState($input, 'jmhz_functional_benefits_status');
        $temporaryAssignment = $this->verifiedState($input, 'jmhz_temporary_assignment_status');
        [$rateCategory, $rateCategoryEvidence] = $this->socialEmployerRateCategory($input);
        [$discountReason, $discountEvidence, $discountNotifiedOn] =
            $this->socialPartTimeDiscount($input);
        $activityCode = $this->optionalCode($input, 'activity_code', 32);
        $relationshipDetailCode = $this->optionalCode(
            $input,
            'jmhz_relationship_detail_code',
            1,
        );
        if ($activityCode !== null) {
            $this->jmhzEvidence->requireActivityCode($activityCode);
        }
        if ($relationshipDetailCode !== null) {
            $this->jmhzEvidence->requireRelationshipDetailCode($relationshipDetailCode);
            if ($activityCode === null || preg_match('/^[1-9]$/D', $activityCode) !== 1) {
                throw new \InvalidArgumentException(
                    'Bližší určení pracovněprávního vztahu lze vyplnit jen pro druh činnosti 1 až 9.',
                );
            }
        }
        if ($relationType !== null) {
            $this->assertRelationActivityFamily($relationType, $activityCode, $relationshipDetailCode);
        }

        return [
            'office_id' => $officeId,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => $this->optionalDate($input, 'contract_signed_on'),
            'planned_start_on' => $plannedStart,
            'actual_start_on' => $this->optionalDate($input, 'actual_start_on'),
            'fixed_term_end_on' => $fixedEnd,
            'weekly_hours' => $hours === null ? null : (string) $hours,
            'leave_entitlement_weeks_override' => $this->leaveWeeksOverride(
                $input['leave_entitlement_weeks_override'] ?? null,
            ),
            'workload_basis_points' => $workload,
            'work_place' => $workPlace,
            'regular_workplace' => $this->optionalText($input, 'regular_workplace', 255),
            'jmhz_workplace_municipality_code' => $municipalityCode,
            'jmhz_workplace_country_code' => $workplaceCountry,
            'jmhz_external_codebook_overlay_key' => $externalCodebook['overlay_key'] ?? null,
            'jmhz_external_codebook_manifest_sha256' => $externalCodebook['manifest_sha256'] ?? null,
            'jmhz_apz_contribution_status' => $apzStatus,
            'jmhz_apz_instrument_code' => $apzCode,
            'jmhz_functional_benefits_status' => $functionalBenefits,
            'jmhz_temporary_assignment_status' => $temporaryAssignment,
            'jmhz_orchard_discount_eligible' => $this->requiredBool(
                $input,
                'jmhz_orchard_discount_eligible',
                false,
            ),
            'jmhz_specific_legal_fact_applies' => $this->requiredBool(
                $input,
                'jmhz_specific_legal_fact_applies',
                false,
            ),
            'jmhz_ozp_employment_support_applies' => $this->requiredBool(
                $input,
                'jmhz_ozp_employment_support_applies',
                false,
            ),
            'jmhz_deep_mining_work_applies' => $this->requiredBool(
                $input,
                'jmhz_deep_mining_work_applies',
                false,
            ),
            'cz_isco_code' => $this->optionalCzIscoCode($input, 'cz_isco_code', $storedCzIscoCode),
            'activity_code' => $activityCode,
            'jmhz_relationship_detail_code' => $relationshipDetailCode,
            'social_insurance_participation' => $social,
            'health_insurance_participation' => $health,
            'tax_regime' => $tax,
            'other_withholding_eligibility' => $this->otherWithholdingEligibility(
                $input,
                $storedOtherWithholdingEligibility,
            ),
            'foreign_legislation_country_code' => $country,
            'a1_certificate_until' => $this->optionalDate($input, 'a1_certificate_until'),
            'risky_work' => $rateCategory === 'risk_employment',
            'social_employer_rate_category' => $rateCategory,
            'social_employer_rate_category_evidence' => $rateCategoryEvidence,
            'social_part_time_discount_reason' => $discountReason,
            'social_part_time_discount_evidence' => $discountEvidence,
            'social_part_time_discount_notified_on' => $discountNotifiedOn,
            'tax_declaration_signed' => $this->requiredBool($input, 'tax_declaration_signed', false),
            'is_primary' => $this->requiredBool($input, 'is_primary', false),
            'change_reason' => $this->optionalText($input, 'change_reason', 500),
        ];
    }

    private function leaveWeeksOverride(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) || $value < 4 || $value > 12) {
            throw new \InvalidArgumentException(
                'Výjimka výměry dovolené musí mít nejméně 4 týdny a nejvýše 12 týdnů.',
            );
        }

        return $value;
    }

    private function assertRelationActivityFamily(
        string $relationType,
        ?string $activityCode,
        ?string $relationshipDetailCode,
    ): void {
        if ($activityCode === null) {
            return;
        }
        if (PayrollEmploymentJmhzActivityFamily::appliesTo($relationType)
            && !PayrollEmploymentJmhzActivityFamily::matches(
                $relationType,
                $activityCode,
                $relationshipDetailCode,
            )
        ) {
            throw new \InvalidArgumentException(
                'Druh činnosti nebo bližší určení neodpovídá druhu pracovního vztahu.',
            );
        }
    }

    /** @param array<string,mixed> $input
     *  @return array{row_version:int,status:string,note:?string}
     */
    public function checklist(array $input): array
    {
        $version = $this->rowVersion($input);
        $status = $this->inputString($input['status'] ?? '');
        if (!in_array($status, self::CHECKLIST_STATUSES, true)) {
            throw new \InvalidArgumentException('Stav položky checklistu není platný.');
        }
        return [
            'row_version' => $version,
            'status' => $status,
            'note' => $this->optionalText($input, 'note', 500),
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array{row_version:int,effective_on:string,note:?string}
     */
    public function transition(array $input): array
    {
        return [
            'row_version' => $this->rowVersion($input),
            'effective_on' => $this->requiredDate($input, 'effective_on'),
            'note' => $this->optionalText($input, 'note', 500),
        ];
    }

    /** @param array<string,mixed> $input */
    public function rowVersion(array $input): int
    {
        $value = $input['row_version'] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('row_version musí být kladné celé číslo.');
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredDate(array $input, string $key): string
    {
        return $this->date($this->inputString($input[$key] ?? ''), $key)
            ?? throw new \InvalidArgumentException("Pole {$key} je povinné.");
    }

    /** @param array<string,mixed> $input */
    private function optionalDate(array $input, string $key): ?string
    {
        return $this->date(trim($this->inputString($input[$key] ?? '')), $key);
    }

    private function date(string $value, string $key): ?string
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
        return $value;
    }

    /**
     * Sazbová kategorie zaměstnavatele podle § 5a odst. 1 ZPSZ a odkaz na podklad.
     *
     * Kategorie je zdroj pravdy a `risky_work` se z ní odvozuje, ne naopak —
     * dva zapisovatelné údaje o téže věci by se rozešly. Starší klient, který
     * kategorii ještě neposílá, se pozná podle prázdné hodnoty a jeho boolean
     * se na kategorii přeloží; pošle-li obojí a odporují si, je to chyba, ne
     * tichá volba jednoho z nich.
     *
     * Podklad je nepovinný ZÁMĚRNĚ: účetní smí zaměstnance zařadit dřív, než
     * má doklad po ruce. Do výpočtu se ale takový vztah nedostane — bez
     * podkladu z něj {@see \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler}
     * udělá nedoložené zařazení a mzdový běh skončí na ručním posouzení.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:?string}
     */
    private function socialEmployerRateCategory(array $input): array
    {
        $category = trim($this->inputString($input['social_employer_rate_category'] ?? ''));
        if ($category === '') {
            $category = $this->requiredBool($input, 'risky_work', false)
                ? 'risk_employment'
                : 'ordinary';
        } elseif (
            array_key_exists('risky_work', $input)
            && $this->requiredBool($input, 'risky_work', false)
                !== ($category === 'risk_employment')
        ) {
            throw new \InvalidArgumentException(
                'Riziková práce a sazbová kategorie zaměstnavatele si odporují.',
            );
        }
        if (!in_array($category, self::SOCIAL_EMPLOYER_RATE_CATEGORIES, true)) {
            throw new \InvalidArgumentException(
                'Sazbová kategorie zaměstnavatele není podporována.',
            );
        }
        $evidence = $this->optionalText($input, 'social_employer_rate_category_evidence', 190);

        return [$category, $category === 'ordinary' ? null : $evidence];
    }

    /**
     * Nárok na slevu zaměstnavatele podle § 7a odst. 1 ZPSZ.
     *
     * Odkaz na podklad ani datum oznámení ČSSZ nejsou povinné vstupy: účetní
     * smí důvod zapsat dřív, než oznámení odešle. O uplatnění ve výpočtu
     * rozhoduje přijatý záměr OZUSPOJ; bez něj z něj
     * {@see \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler}
     * udělá nedoložený nárok a měsíc skončí na ručním posouzení. Tichý pád na
     * uplatněnou slevu by z ní podle § 7c odst. 3 udělal dluh na pojistném.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:?string,2:?string}
     */
    private function socialPartTimeDiscount(array $input): array
    {
        $reason = trim($this->inputString($input['social_part_time_discount_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'none';
        }
        if (!in_array($reason, self::SOCIAL_PART_TIME_DISCOUNT_REASONS, true)) {
            throw new \InvalidArgumentException(
                'Důvod slevy zaměstnavatele na kratší úvazek není podporován.',
            );
        }
        if ($reason === 'none') {
            return ['none', null, null];
        }

        return [
            $reason,
            $this->optionalText($input, 'social_part_time_discount_evidence', 190),
            $this->optionalDate($input, 'social_part_time_discount_notified_on'),
        ];
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $maxLength): ?string
    {
        $value = trim($this->inputString($input[$key] ?? ''));
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("Pole {$key} je příliš dlouhé.");
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalCode(array $input, string $key, int $maxLength): ?string
    {
        $value = $this->optionalText($input, $key, $maxLength);
        if ($value !== null && !preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
            throw new \InvalidArgumentException("Pole {$key} není platný kód.");
        }
        return $value;
    }

    /**
     * CZ-ISCO je čistě číselný kód o čtyřech nebo pěti číslicích (skupina /
     * podskupina zaměstnání). Kontroluje se POUZE tvar — úplný číselník ČSÚ
     * v projektu není a nestahujeme ho, takže špatný, ale správně tvarovaný
     * kód (třeba 99999) touhle validací projde.
     *
     * @param array<string,mixed> $input
     */
    /**
     * Kód CZ-ISCO se ověřuje proti připnuté klasifikaci ČSÚ, ne jen tvarem.
     * Dobře tvarovaný nesmysl (12345) do dneška prošel až do podání JMHZ a
     * vrátil se jako odmítnutí ČSSZ — to je nejdražší možné místo, kde to zjistit.
     *
     * Zpětná kompatibilita má dvě úrovně, protože v databázi jsou data z doby,
     * kdy pole bylo volný text:
     *   1. **Vyřazený kód** (platil v některém starším vydání CZ-ISCO) projde vždy.
     *      Byla to v době zadání legitimní hodnota a přepisovat historii při
     *      revizi klasifikace by bylo horší než ji nechat být.
     *   2. **Kód, který v CZ-ISCO nikdy nebyl**, projde jen tehdy, když je
     *      shodný s tím, co u vztahu už uložené je. Uživatel tak může uložit
     *      formulář, ve kterém mění dovolenou nebo mzdu, aniž by ho blokovalo
     *      cizí historické pole — ale jakmile na CZ-ISCO sáhne, musí trefit
     *      číselník. Nové hodnoty jsou tím vynucené, existující nezablokované.
     *
     * @param array<string,mixed> $input
     */
    private function optionalCzIscoCode(array $input, string $key, ?string $storedCode = null): ?string
    {
        $value = $this->optionalText($input, $key, 16);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[0-9]{4,5}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                "Kód CZ-ISCO {$value} nemá platný tvar. Zadejte čtyř- nebo pětimístné číslo, například 25120.",
            );
        }
        $status = $this->czIsco->status($value);
        if ($status === CzIscoCodebook::STATUS_ACTIVE || $status === CzIscoCodebook::STATUS_RETIRED) {
            return $value;
        }
        if ($storedCode !== null && $storedCode === $value) {
            return $value;
        }
        throw new \InvalidArgumentException(sprintf(
            'Kód CZ-ISCO %s v klasifikaci zaměstnání ČSÚ (verze %s) neexistuje. '
                . 'Vyberte kód z našeptávače — stačí napsat část názvu profese, '
                . 'třeba „účetní" najde 43111 Účetní všeobecní.',
            $value,
            CzIscoCodebook::CLASSIFICATION_VERSION,
        ));
    }

    /**
     * Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP.
     *
     * Chybějící klíč znamená „na tohle pole nikdo nesahal", ne „vynuluj ho".
     * Podmínky se ukládají celé, takže obrazovka, která pole nezná, by jinak
     * daňové zařazení jednatele shodila zpátky na `unverified` — a příští běh
     * by skončil ručním posouzením kvůli uložení nesouvisející změny. Stejná
     * úvaha jako u uloženého kódu CZ-ISCO, jen opačným směrem: tam se přebírá,
     * aby historická hodnota nezablokovala uložení, tady aby se neztratila.
     *
     * @param array<string,mixed> $input
     */
    private function otherWithholdingEligibility(array $input, ?string $stored): string
    {
        if (($input['other_withholding_eligibility'] ?? null) === null) {
            return in_array($stored, self::OTHER_WITHHOLDING_ELIGIBILITIES, true)
                ? $stored
                : 'unverified';
        }
        $value = $this->inputString($input['other_withholding_eligibility']);
        if (!in_array($value, self::OTHER_WITHHOLDING_ELIGIBILITIES, true)) {
            throw new \InvalidArgumentException(
                'Zařazení pro srážkovou daň z ostatních příjmů není podporováno.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredBool(array $input, string $key, bool $default): bool
    {
        $value = $input[$key] ?? $default;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být boolean.");
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function verifiedState(array $input, string $key): string
    {
        if (!array_key_exists($key, $input)) {
            throw new \InvalidArgumentException("Pole {$key} musí být zadáno explicitně.");
        }
        $value = $this->inputString($input[$key]);
        if (!in_array($value, self::VERIFIED_STATES, true)) {
            throw new \InvalidArgumentException("Pole {$key} má neplatný stav ověření.");
        }
        return $value;
    }

    private function inputString(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        throw new \InvalidArgumentException('Textové pole má neplatný typ.');
    }

    /** @param array<mixed> $value
     *  @return array<string,mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Objekt obsahuje neplatný klíč.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
