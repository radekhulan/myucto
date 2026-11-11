<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCodebooks;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;

/**
 * @phpstan-type TermsInput array{
 *   office_id:?int,
 *   effective_from:string,
 *   contract_signed_on:?string,
 *   planned_start_on:string,
 *   actual_start_on:?string,
 *   fixed_term_end_on:?string,
 *   monthly_gross_minor:?int,
 *   remuneration_type:?string,
 *   hourly_rate_minor:?int,
 *   remuneration_basis_kind:?string,
 *   remuneration_basis:?string,
 *   weekly_hours:?string,
 *   leave_entitlement_weeks_override:?int,
 *   probable_hourly_earning_minor:?int,
 *   probable_earning_rationale:?string,
 *   workload_basis_points:int,
 *   work_place:?string,
 *   regular_workplace:?string,
 *   jmhz_workplace_municipality_code:?string,
 *   jmhz_workplace_country_code:?string,
 *   jmhz_workplace_address_point_code:?string,
 *   jmhz_workplace_street:?string,
 *   jmhz_workplace_house_number:?string,
 *   jmhz_workplace_orientation_number:?string,
 *   jmhz_workplace_postal_code:?string,
 *   jmhz_state_prosecutor:?bool,
 *   jmhz_external_codebook_overlay_key:?string,
 *   jmhz_external_codebook_manifest_sha256:?string,
 *   jmhz_apz_contribution_status:string,
 *   jmhz_apz_instrument_code:?string,
 *   jmhz_functional_benefits_status:string,
 *   jmhz_temporary_assignment_status:string,
 *   jmhz_assignment_user_kind:?string,
 *   jmhz_assignment_user_ico:?string,
 *   jmhz_assignment_user_country_code:?string,
 *   jmhz_assignment_user_foreign_id:?string,
 *   jmhz_assignment_user_name:?string,
 *   jmhz_orchard_discount_eligible:bool,
 *   jmhz_specific_legal_fact_applies:bool,
 *   jmhz_ozp_employment_support_applies:bool,
 *   jmhz_deep_mining_work_applies:bool,
 *   cz_isco_code:?string,
 *   activity_code:?string,
 *   jmhz_relationship_detail_code:?string,
 *   social_insurance_participation:string,
 *   health_insurance_participation:string,
 *   health_association_member:bool,
 *   tax_regime:string,
 *   other_withholding_eligibility:string,
 *   foreign_legislation_country_code:?string,
 *   a1_certificate_until:?string,
 *   risky_work:bool,
 *   social_employer_rate_category:string,
 *   social_employer_rate_category_evidence:?string,
 *   jmhz_risk_categorization_code:?string,
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
 *   meal_entitlement_basis:string,
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
    private const MEAL_ENTITLEMENT_BASES = ['shift', 'calendar_day'];
    public const REMUNERATION_BASIS_KINDS = ['wage_statement', 'contract', 'agreement', 'internal_regulation', 'collective_agreement'];
    public const MAX_HOURLY_RATE_MINOR = 1_000_000_000;

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
     *  @param ?list<array{field:string,code:string,message:string}> $warnings výstup, viz {@see terms()}
     *  @return EmploymentCreateInput
     */
    public function create(array $input, ?array &$warnings = null): array
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

        $terms = $this->terms($this->stringKeyed($input['terms']), relationType: $relationType, warnings: $warnings);
        if ($terms['remuneration_type'] === 'hourly' && $gross !== null) {
            throw new \InvalidArgumentException('Hodinové odměňování nesmí mít sjednanou měsíční mzdu.');
        }
        if ($terms['actual_start_on'] !== null) {
            throw new \InvalidArgumentException(
                'Skutečný nástup se zaznamenává přechodem pracovního vztahu do aktivního stavu.'
            );
        }

        return [
            'code' => $code,
            'relation_type' => $relationType,
            'meal_entitlement_basis' => $this->mealEntitlementBasis($input),
            'monthly_gross_minor' => $gross,
            'terms' => $terms,
        ];
    }

    /** @param array<string,mixed> $input */
    public function mealEntitlementBasis(array $input): string
    {
        $basis = $this->inputString($input['meal_entitlement_basis'] ?? 'shift');
        if (!in_array($basis, self::MEAL_ENTITLEMENT_BASES, true)) {
            throw new \InvalidArgumentException(
                'Režim nároku na příspěvek na stravování není podporován.',
            );
        }

        return $basis;
    }

    /** @param array<string,mixed> $input */
    public function requiredMealEntitlementBasis(array $input): string
    {
        if (!array_key_exists('meal_entitlement_basis', $input)) {
            throw new \InvalidArgumentException(
                'Vyberte režim nároku na příspěvek na stravování.',
            );
        }

        return $this->mealEntitlementBasis($input);
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
     * @param ?array<string,mixed> $stored Uložená verze podmínek, kterou požadavek
     *        mění ({@see \MyInvoice\Repository\Payroll\PayrollEmploymentRepository::currentTerms()}).
     *        Předává ji zápisová cesta, NIKDY klient. Číselníkové a konzistenční
     *        kontroly pole, které se proti ní nemění, uložení neblokují — skončí
     *        ve `$warnings` a tvrdě se hlídají až tam, kde se hodnota použije
     *        (příprava JMHZ a REGZEC, výpočet běhu). Typy, formáty, výčty
     *        a celé hodinové odměňování zůstávají tvrdé vždy.
     * @param ?list<array{field:string,code:string,message:string}> $warnings
     *        výstup: kontroly nezměněných polí, které neprošly, a nesoulad názvu
     *        místa výkonu práce s CISOB
     * @param-out list<array{field:string,code:string,message:string}> $warnings
     * @return TermsInput
     */
    public function terms(
        array $input,
        ?string $storedCzIscoCode = null,
        ?string $storedOtherWithholdingEligibility = null,
        ?string $relationType = null,
        ?array $stored = null,
        ?array &$warnings = null,
    ): array {
        $warnings ??= [];
        $effectiveFrom = $this->requiredDate($input, 'effective_from');
        $remunerationType = $input['remuneration_type'] ?? null;
        if ($remunerationType !== null && !in_array($remunerationType, ['monthly', 'hourly'], true)) {
            throw new PayrollFieldValidationException('Způsob odměňování není podporován.', 'remuneration_type');
        }
        $hourlyRate = $input['hourly_rate_minor'] ?? null;
        $basisKind = $input['remuneration_basis_kind'] ?? null;
        if ($basisKind !== null && !in_array($basisKind, self::REMUNERATION_BASIS_KINDS, true)) {
            throw new PayrollFieldValidationException('Druh podkladu odměňování není podporován.', 'remuneration_basis_kind');
        }
        if (isset($input['remuneration_basis']) && !is_string($input['remuneration_basis'])) {
            throw new PayrollFieldValidationException('Označení podkladu odměňování musí být text.', 'remuneration_basis');
        }
        $basis = $this->optionalText($input, 'remuneration_basis', 255);
        if ($remunerationType === 'hourly') {
            if ($relationType !== null && !in_array($relationType, ['employment', 'small_scale_employment', 'dpp', 'dpc'], true)) {
                throw new PayrollFieldValidationException('Hodinové odměňování není pro tento typ vztahu podporováno.', 'remuneration_type');
            }
            if (!is_int($hourlyRate) || $hourlyRate <= 0 || $hourlyRate > self::MAX_HOURLY_RATE_MINOR) {
                throw new PayrollFieldValidationException('Hodinové odměňování vyžaduje kladnou hodinovou sazbu v haléřích.', 'hourly_rate_minor');
            }
            // Podklad vyžaduje databázová podmínka chk_payroll_terms_remuneration.
            if ($basisKind === null) {
                throw new PayrollFieldValidationException('Hodinové odměňování vyžaduje druh podkladu odměňování (mzdový výměr, smlouva, dohoda…).', 'remuneration_basis_kind');
            }
            if ($basis === null) {
                throw new PayrollFieldValidationException('Hodinové odměňování vyžaduje označení podkladu odměňování, například „Mzdový výměr účinný od 1. 1. 2027".', 'remuneration_basis');
            }
            if ($this->optionalMonthlyGrossMinor($input) !== null) {
                throw new PayrollFieldValidationException('Hodinové odměňování nesmí mít sjednanou měsíční mzdu.', 'monthly_gross_minor');
            }
        } elseif ($hourlyRate !== null) {
            throw new PayrollFieldValidationException('Měsíční odměňování nesmí mít hodinovou sazbu.', 'hourly_rate_minor');
        }
        $plannedStart = $this->requiredDate($input, 'planned_start_on');
        $fixedEnd = $this->optionalDate($input, 'fixed_term_end_on');
        if ($fixedEnd !== null && $fixedEnd < $plannedStart) {
            throw new PayrollFieldValidationException('Konec doby určité nesmí předcházet plánovanému nástupu.', 'fixed_term_end_on');
        }

        $officeId = $input['office_id'] ?? null;
        if ($officeId !== null && (!is_int($officeId) || $officeId <= 0)) {
            throw new PayrollFieldValidationException('Mzdová účtárna není platná.', 'office_id');
        }
        $hours = $input['weekly_hours'] ?? null;
        if ($hours !== null) {
            if ((!is_string($hours) && !is_int($hours))
                || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $hours, $parts) !== 1) {
                throw new PayrollFieldValidationException('Týdenní pracovní doba není platná.', 'weekly_hours');
            }
            $whole = (int) $parts[1];
            $fraction = str_pad($parts[2] ?? '', 2, '0');
            $centiHours = ($whole * 100) + (int) $fraction;
            if ($centiHours <= 0 || $centiHours > 16800) {
                throw new PayrollFieldValidationException('Týdenní pracovní doba musí být větší než nula a nejvýše 168 hodin.', 'weekly_hours');
            }
            $hours = sprintf('%d.%02d', intdiv($centiHours, 100), $centiHours % 100);
        }
        $workload = $input['workload_basis_points'] ?? 10000;
        if (!is_int($workload) || $workload < 1 || $workload > 10000) {
            throw new PayrollFieldValidationException('Úvazek musí být od 0,01 % do 100 %.', 'workload_basis_points');
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
        if ($country !== null && !PayrollRegistrationCodebooks::isCountry($country)) {
            throw new PayrollFieldValidationException('Kód státu cizích právních předpisů není platný.', 'foreign_legislation_country_code');
        }
        if ($social === 'foreign' || $health === 'foreign' || $tax === 'foreign') {
            self::soft(
                self::unchanged($stored, [
                    'social_insurance_participation' => $social,
                    'health_insurance_participation' => $health,
                    'tax_regime' => $tax,
                    'foreign_legislation_country_code' => $country,
                ]),
                'foreign_legislation_country_code',
                'foreign_legislation_country_missing',
                static function () use ($country): void {
                    if ($country === null) {
                        throw new \InvalidArgumentException('Cizí režim vyžaduje kód státu právních předpisů.');
                    }
                },
                $warnings,
            );
        }

        $workPlace = $this->optionalText($input, 'work_place', 255);
        $municipalityCode = $this->optionalText($input, 'jmhz_workplace_municipality_code', 6);
        $workplaceCountry = strtoupper(
            $this->optionalText($input, 'jmhz_workplace_country_code', 2) ?? '',
        );
        $workplaceCountry = $workplaceCountry === '' ? null : $workplaceCountry;
        $workplaceVerified = false;
        if ($municipalityCode === null) {
            if ($workplaceCountry !== null) {
                throw new PayrollFieldValidationException(
                    'Pracoviště JMHZ vyžaduje k místu výkonu práce současně šestimístný kód obce a kód státu.',
                    'jmhz_workplace_municipality_code',
                );
            }
        } else {
            if ($workplaceCountry === null) {
                throw new PayrollFieldValidationException(
                    'Pracoviště JMHZ vyžaduje k místu výkonu práce současně šestimístný kód obce a kód státu.',
                    'jmhz_workplace_country_code',
                );
            }
            if (preg_match('/^[0-9]{6}$/', $municipalityCode) !== 1) {
                throw new PayrollFieldValidationException('Kód obce pracoviště JMHZ musí mít přesně šest číslic.', 'jmhz_workplace_municipality_code');
            }
            if (preg_match('/^[A-Z]{2}$/', $workplaceCountry) !== 1) {
                throw new PayrollFieldValidationException('Kód státu pracoviště JMHZ musí mít dvě velká písmena.', 'jmhz_workplace_country_code');
            }
            // Zdroj pravdy je kód obce; prázdné místo výkonu práce doplní
            // úřední název z CISOB, jinak by ho musel každý import opisovat.
            $workPlace ??= $this->jmhzEvidence->officialMunicipalityName($municipalityCode, $effectiveFrom)
                ?? throw new PayrollFieldValidationException(
                    'Pracoviště JMHZ vyžaduje k místu výkonu práce současně šestimístný kód obce a kód státu.',
                    'work_place',
                );
            $workplaceUnchanged = self::unchanged($stored, [
                'jmhz_workplace_municipality_code' => $municipalityCode,
                'jmhz_workplace_country_code' => $workplaceCountry,
            ]);
            $workplaceVerified = self::soft(
                $workplaceUnchanged,
                'jmhz_workplace_municipality_code',
                'workplace_municipality_unverified',
                fn () => $this->jmhzEvidence->requireWorkplaceMunicipality($municipalityCode, $effectiveFrom),
                $warnings,
            );
            $workplaceVerified = self::soft(
                $workplaceUnchanged,
                'jmhz_workplace_country_code',
                'workplace_country_unverified',
                fn () => $this->jmhzEvidence->requireWorkplaceCountry($workplaceCountry, $effectiveFrom),
                $warnings,
            ) && $workplaceVerified;
            $unpublished = $this->jmhzEvidence->workplaceUnpublishedPeriodNotice($effectiveFrom);
            if ($workplaceVerified && $unpublished !== null) {
                $warnings[] = [
                    'field' => 'jmhz_workplace_municipality_code',
                    'code' => 'workplace_codebook_not_published',
                    'message' => $unpublished,
                ];
            }
            /*
             * Název obce uložení neblokuje nikdy: `work_place` je volný text
             * smlouvy (mzdový výměr, A1). Do JMHZ ale jde jako název obce
             * (10229) a běh ho ověřuje proti CISOB, takže nesoulad se vrátí
             * jako varování a provenience číselníku se nezapíše.
             */
            if ($workplaceVerified) {
                $mismatch = $this->jmhzEvidence->workplaceNameMismatch($municipalityCode, $workPlace, $effectiveFrom);
                if ($mismatch !== null) {
                    $warnings[] = ['field' => 'work_place', 'code' => 'workplace_name_mismatch', 'message' => $mismatch];
                    $workplaceVerified = false;
                }
            }
        }
        // Provenience se zapisuje jen tam, kde připnuté číselníky na období
        // sahají a pracoviště jimi prošlo. U vztahu staršího než JMHZ žádná
        // neexistuje a vymýšlet ji by znamenalo tvrdit, že se hodnota ověřila
        // proti něčemu, co pro tu dobu není.
        $externalCodebook = !$workplaceVerified
            || !$this->jmhzEvidence->externalCodebooksCover($effectiveFrom)
            ? null
            : $this->jmhzEvidence->externalCodebookProvenance($effectiveFrom);
        $workplaceAddress = $this->workplaceAddress($input, $municipalityCode, $stored, $warnings);

        $apzStatus = $this->verifiedState($input, 'jmhz_apz_contribution_status');
        $apzCode = $this->optionalText($input, 'jmhz_apz_instrument_code', 8);
        if ($apzStatus === 'yes') {
            if ($apzCode === null) {
                throw new PayrollFieldValidationException('Příspěvek APZ vyžaduje kód nástroje APZ.', 'jmhz_apz_instrument_code');
            }
            self::soft(
                self::unchanged($stored, ['jmhz_apz_instrument_code' => $apzCode]),
                'jmhz_apz_instrument_code',
                'apz_instrument_unknown',
                fn () => $this->jmhzEvidence->requireApzInstrument($apzCode),
                $warnings,
            );
        } elseif ($apzCode !== null) {
            throw new PayrollFieldValidationException('Bez příspěvku APZ nesmí být kód nástroje APZ vyplněn.', 'jmhz_apz_instrument_code');
        }
        $functionalBenefits = $this->verifiedState($input, 'jmhz_functional_benefits_status');
        $temporaryAssignment = $this->verifiedState($input, 'jmhz_temporary_assignment_status');
        $assignmentUser = $this->temporaryAssignmentUser($input, $temporaryAssignment, $stored, $warnings);
        [$rateCategory, $rateCategoryEvidence] = $this->socialEmployerRateCategory($input);
        $riskCategorization = $this->riskCategorizationCode($input, $rateCategory);
        [$discountReason, $discountEvidence, $discountNotifiedOn] =
            $this->socialPartTimeDiscount($input);
        $activityCode = $this->optionalCode($input, 'activity_code', 32);
        $relationshipDetailCode = $this->optionalCode(
            $input,
            'jmhz_relationship_detail_code',
            1,
        );
        $activityUnchanged = self::unchanged($stored, [
            'activity_code' => $activityCode,
            'jmhz_relationship_detail_code' => $relationshipDetailCode,
        ]);
        $activityKnown = $activityCode === null || self::soft(
            $activityUnchanged,
            'activity_code',
            'activity_code_unknown',
            fn () => $this->jmhzEvidence->requireActivityCode($activityCode),
            $warnings,
        );
        if ($relationshipDetailCode !== null) {
            self::soft(
                $activityUnchanged,
                'jmhz_relationship_detail_code',
                'relationship_detail_code_unknown',
                fn () => $this->jmhzEvidence->requireRelationshipDetailCode($relationshipDetailCode),
                $warnings,
            );
        }
        if ($activityCode !== null && $activityKnown) {
            $normalizedDetail = $relationshipDetailCode;
            if (self::soft(
                $activityUnchanged,
                'jmhz_relationship_detail_code',
                'relationship_detail_code_invalid',
                static function () use (&$normalizedDetail, $activityCode, $relationshipDetailCode): void {
                    $normalizedDetail = PayrollRegistrationRelationshipDetailPolicy::requireForEvidence(
                        $activityCode,
                        $relationshipDetailCode,
                    );
                },
                $warnings,
            )) {
                $relationshipDetailCode = $normalizedDetail;
            }
        }
        if ($relationType !== null && $activityKnown) {
            self::soft(
                $activityUnchanged,
                'activity_code',
                'activity_family_mismatch',
                fn () => $this->assertRelationActivityFamily($relationType, $activityCode, $relationshipDetailCode),
                $warnings,
            );
        }

        $probableEarning = $this->probableEarning($input);
        $healthAssociationMember = $this->requiredBool($input, 'health_association_member', false);
        if ($healthAssociationMember && in_array($relationType, ['dpp', 'dpc'], true)) {
            throw new PayrollFieldValidationException(
                'Člen družstva nebo SVJ se pro zdravotní pojištění posuzuje jen u vztahu, '
                . 'který není dohodou. U DPP a DPČ platí vlastní hranice příjmu.',
                'health_association_member',
            );
        }
        $storedCzIscoCode ??= is_string($stored['cz_isco_code'] ?? null) ? $stored['cz_isco_code'] : null;
        $czIscoCode = $this->optionalCzIscoCode($input, 'cz_isco_code', $storedCzIscoCode, $warnings);
        $warnings = self::uniqueWarnings($warnings);

        return [
            'office_id' => $officeId,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => $this->optionalDate($input, 'contract_signed_on'),
            'planned_start_on' => $plannedStart,
            'actual_start_on' => $this->optionalDate($input, 'actual_start_on'),
            'fixed_term_end_on' => $fixedEnd,
            'monthly_gross_minor' => $this->optionalMonthlyGrossMinor($input),
            'remuneration_type' => $remunerationType,
            'hourly_rate_minor' => $hourlyRate,
            'remuneration_basis_kind' => $basisKind,
            'remuneration_basis' => $basis,
            'weekly_hours' => $hours === null ? null : (string) $hours,
            'leave_entitlement_weeks_override' => $this->leaveWeeksOverride(
                $input['leave_entitlement_weeks_override'] ?? null,
            ),
            'probable_hourly_earning_minor' => $probableEarning['hourly_minor'],
            'probable_earning_rationale' => $probableEarning['rationale'],
            'workload_basis_points' => $workload,
            'work_place' => $workPlace,
            'regular_workplace' => $this->optionalText($input, 'regular_workplace', 255),
            'jmhz_workplace_municipality_code' => $municipalityCode,
            'jmhz_workplace_country_code' => $workplaceCountry,
            ...$workplaceAddress,
            'jmhz_state_prosecutor' => $this->optionalBool($input, 'jmhz_state_prosecutor'),
            'jmhz_external_codebook_overlay_key' => $externalCodebook['overlay_key'] ?? null,
            'jmhz_external_codebook_manifest_sha256' => $externalCodebook['manifest_sha256'] ?? null,
            'jmhz_apz_contribution_status' => $apzStatus,
            'jmhz_apz_instrument_code' => $apzCode,
            'jmhz_functional_benefits_status' => $functionalBenefits,
            'jmhz_temporary_assignment_status' => $temporaryAssignment,
            ...$assignmentUser,
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
            'cz_isco_code' => $czIscoCode,
            'activity_code' => $activityCode,
            'jmhz_relationship_detail_code' => $relationshipDetailCode,
            'social_insurance_participation' => $social,
            'health_insurance_participation' => $health,
            'health_association_member' => $healthAssociationMember,
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
            'jmhz_risk_categorization_code' => $riskCategorization,
            'social_part_time_discount_reason' => $discountReason,
            'social_part_time_discount_evidence' => $discountEvidence,
            'social_part_time_discount_notified_on' => $discountNotifiedOn,
            'tax_declaration_signed' => $this->requiredBool($input, 'tax_declaration_signed', false),
            'is_primary' => $this->requiredBool($input, 'is_primary', false),
            'change_reason' => $this->optionalText($input, 'change_reason', 500),
        ];
    }

    /**
     * Shoduje se pole požadavku s uloženou verzí? Porovnává se po normalizaci
     * (ořez, prázdný text = null, čísla jako text). Bez uložené verze (založení,
     * import) je každé pole „změněné", takže kontrola zůstává tvrdá.
     *
     * @param ?array<string,mixed> $stored
     * @param array<string,mixed> $values
     */
    private static function unchanged(?array $stored, array $values): bool
    {
        if ($stored === null) {
            return false;
        }
        foreach ($values as $key => $value) {
            if (self::comparable($value) !== self::comparable($stored[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function comparable(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Číselníková nebo konzistenční kontrola, která u NEZMĚNĚNÉHO pole uložení
     * nezastaví. Změněné pole projde kontrolou tvrdě a chyba nese `field`.
     *
     * @param callable():void $check
     * @param list<array{field:string,code:string,message:string}> $warnings
     * @return bool prošla kontrola?
     */
    private static function soft(bool $unchanged, string $field, string $code, callable $check, array &$warnings): bool
    {
        try {
            $check();

            return true;
        } catch (\InvalidArgumentException $e) {
            $failedField = $e instanceof PayrollFieldValidationException ? $e->field : $field;
            if (!$unchanged) {
                throw $e instanceof PayrollFieldValidationException
                    ? $e
                    : new PayrollFieldValidationException($e->getMessage(), $failedField, null, $e);
            }
            $warnings[] = ['field' => $failedField, 'code' => $code, 'message' => $e->getMessage()];

            return false;
        }
    }

    /**
     * @param list<array{field:string,code:string,message:string}> $warnings
     * @return list<array{field:string,code:string,message:string}>
     */
    private static function uniqueWarnings(array $warnings): array
    {
        $unique = [];
        foreach ($warnings as $warning) {
            $unique[$warning['field'] . '|' . $warning['code']] ??= $warning;
        }

        return array_values($unique);
    }

    /**
     * Pravděpodobný výdělek podle § 355 zákoníku práce.
     *
     * „Jestliže zaměstnanec v rozhodném období neodpracoval alespoň 21 dnů,
     * použije se pravděpodobný výdělek" (§ 355 odst. 1 zákona č. 262/2006 Sb.);
     * podle odst. 2 ho zaměstnavatel stanoví z hrubé mzdy, které zaměstnanec
     * dosáhl, popřípadě které by zřejmě dosáhl, s přihlédnutím k obvyklé výši
     * složek mzdy nebo k odměně zaměstnanců vykonávajících stejnou práci.
     * Metodika MPSV to shrnuje v příručce pro personální agendu, kap. XXI.8
     * (https://ppropo.mpsv.cz/xxi8prumernyvydelek).
     *
     * Číslo se proto nezadává samo o sobě — bez odůvodnění ho nikdo neobhájí
     * a databázová podmínka `chk_payroll_employment_term_probable_earning` ho
     * odmítne. Validace je tu, aby účetní dostala větu, ne SQL chybu.
     *
     * @param array<string,mixed> $input
     * @return array{hourly_minor:?int,rationale:?string}
     */
    private function probableEarning(array $input): array
    {
        $raw = $input['probable_hourly_earning_minor'] ?? null;
        $rationale = $this->optionalText($input, 'probable_earning_rationale', 1000);
        if ($raw === null || $raw === '') {
            if ($rationale !== null) {
                throw new \InvalidArgumentException(
                    'Odůvodnění pravděpodobného výdělku se ukládá jen spolu s jeho částkou.',
                );
            }

            return ['hourly_minor' => null, 'rationale' => null];
        }
        if (!is_int($raw) || $raw <= 0) {
            throw new \InvalidArgumentException(
                'Pravděpodobný hodinový výdělek musí být kladná částka v haléřích.',
            );
        }
        if ($rationale === null) {
            throw new \InvalidArgumentException(
                'K pravděpodobnému výdělku doplňte odůvodnění — z čeho jste ho stanovili'
                . ' (§ 355 odst. 2 zákoníku práce).',
            );
        }

        return ['hourly_minor' => $raw, 'rationale' => $rationale];
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
            // Hláška musí vypsat, co JDE poslat — jinak zbývá hádat mezi
            // `done`, `ok`, `completed` a podobnými.
            throw new \InvalidArgumentException(
                'Stav položky checklistu není platný. Přípustné hodnoty: '
                . implode(', ', self::CHECKLIST_STATUSES) . '.',
            );
        }
        return [
            'row_version' => $version,
            'status' => $status,
            'note' => $this->optionalText($input, 'note', 500),
            // Den žádosti zaměstnance — jen u potvrzení o zdanitelných
            // příjmech; od něj běží lhůta § 38j odst. 3 ZDP.
            'requested_on' => $this->optionalDate($input, 'requested_on'),
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

    /**
     * Pravidelná hrubá mzda z těla požadavku, je-li vůbec poslaná.
     *
     * Mzdu drží verzované podmínky vztahu. Obrazovka, která ji nenabízí, klíč
     * neposílá — a `null` z chybějícího klíče se nesmí splést s vědomým
     * „mzda není sjednaná". Rozlišuje to volající přes `array_key_exists()`;
     * tady jde jen o tvar hodnoty.
     *
     * @param array<string,mixed> $input
     */
    public function optionalMonthlyGrossMinor(array $input): ?int
    {
        $value = $input['monthly_gross_minor'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException(
                'Pravidelná hrubá mzda musí být nezáporná částka v haléřích.',
            );
        }
        return $value;
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
            ?? throw new PayrollFieldValidationException("Pole {$key} je povinné.", $key);
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
            throw new PayrollFieldValidationException("Pole {$key} musí být datum YYYY-MM-DD.", $key);
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
     * Kategorizace rizika pro JMHZ (10274) u zdravotnického záchranáře a člena
     * jednotky HZS podniku. Obojí je § 5a odst. 1 písm. b) ZPSZ, ale hlášení
     * je rozlišuje kódem 6 a 7 číselníku ČSSZ „Kategorizace rizika". U písm.
     * c) je kód jednoznačný (1 = práce kategorie 4) a neukládá se.
     *
     * Chybějící kód NEBLOKUJE uložení (účetní smí vztah zařadit dřív, než ví,
     * o který případ jde); hlášení ho pak vyžádá nálezem s odkazem na kartu.
     *
     * @param array<string,mixed> $input
     */
    private function riskCategorizationCode(array $input, string $rateCategory): ?string
    {
        $code = trim($this->inputString($input['jmhz_risk_categorization_code'] ?? ''));
        if ($rateCategory !== 'rescue_and_company_fire_service') {
            return null;
        }
        if ($code === '') {
            return null;
        }
        if (!in_array($code, ['6', '7'], true)) {
            throw new PayrollFieldValidationException(
                'Kategorizace rizika pro JMHZ musí být 6 (zdravotnický záchranář)'
                . ' nebo 7 (člen jednotky HZS podniku).',
                'jmhz_risk_categorization_code',
            );
        }

        return $code;
    }

    /**
     * Uživatel, ke kterému agentura práce zaměstnance dočasně přidělila
     * (§ 43a ZP), JMHZ 10252, resp. 10492 až 10494.
     *
     * Kontrola 103 ČSSZ chce u 10251 = ANO buď IČO uživatele, nebo zahraniční
     * osobu se státem, identifikací a názvem. Bez přidělení se údaje mažou,
     * aby v podmínkách nezůstal osiřelý uživatel. Nevyplněný uživatel
     * uložení nebrání, hlášení ho vyžádá nálezem.
     *
     * IČO s kontrolním součtem a tvar zahraniční identifikace hlídá kontrola
     * 103 ČSSZ, ne databáze: u nezměněného uživatele uložení nezastaví a
     * hlášení je vyžádá samo. Druh uživatele a úplnost údajů hlídá
     * `chk_payroll_employment_term_assignment_user`, ty zůstávají tvrdé.
     *
     * @param array<string,mixed> $input
     * @param ?array<string,mixed> $stored
     * @param list<array{field:string,code:string,message:string}> $warnings
     * @return array{
     *   jmhz_assignment_user_kind:?string,
     *   jmhz_assignment_user_ico:?string,
     *   jmhz_assignment_user_country_code:?string,
     *   jmhz_assignment_user_foreign_id:?string,
     *   jmhz_assignment_user_name:?string
     * }
     */
    private function temporaryAssignmentUser(array $input, string $status, ?array $stored, array &$warnings): array
    {
        $empty = [
            'jmhz_assignment_user_kind' => null,
            'jmhz_assignment_user_ico' => null,
            'jmhz_assignment_user_country_code' => null,
            'jmhz_assignment_user_foreign_id' => null,
            'jmhz_assignment_user_name' => null,
        ];
        $kind = trim($this->inputString($input['jmhz_assignment_user_kind'] ?? ''));
        if ($status !== 'yes' || $kind === '') {
            return $empty;
        }
        if ($kind === 'ico') {
            $ico = preg_replace('/\s+/', '', $this->inputString($input['jmhz_assignment_user_ico'] ?? ''));
            $ico = is_string($ico) ? $ico : '';
            if ($ico === '') {
                throw new PayrollFieldValidationException(
                    'IČO uživatele u dočasného přidělení musí mít osm číslic a platný kontrolní součet.',
                    'jmhz_assignment_user_ico',
                );
            }
            self::soft(
                self::unchanged($stored, ['jmhz_assignment_user_kind' => 'ico', 'jmhz_assignment_user_ico' => $ico]),
                'jmhz_assignment_user_ico',
                'assignment_user_ico_invalid',
                static function () use ($ico): void {
                    if (!self::validIco($ico)) {
                        throw new \InvalidArgumentException(
                            'IČO uživatele u dočasného přidělení musí mít osm číslic a platný kontrolní součet.',
                        );
                    }
                },
                $warnings,
            );

            return ['jmhz_assignment_user_kind' => 'ico', 'jmhz_assignment_user_ico' => $ico] + $empty;
        }
        if ($kind !== 'foreign') {
            throw new PayrollFieldValidationException('Druh uživatele u dočasného přidělení není podporován.', 'jmhz_assignment_user_kind');
        }
        $country = strtoupper(trim($this->inputString($input['jmhz_assignment_user_country_code'] ?? '')));
        $foreignId = preg_replace('/\s+/', '', $this->inputString($input['jmhz_assignment_user_foreign_id'] ?? ''));
        $foreignId = is_string($foreignId) ? $foreignId : '';
        $name = $this->optionalText($input, 'jmhz_assignment_user_name', 100);
        if ($country === '' || $foreignId === '') {
            throw new PayrollFieldValidationException(
                'Zahraniční uživatel u dočasného přidělení potřebuje kód cizího státu a registrační číslo.',
                $country === '' ? 'jmhz_assignment_user_country_code' : 'jmhz_assignment_user_foreign_id',
            );
        }
        if ($name === null) {
            throw new PayrollFieldValidationException('Doplňte název zahraničního uživatele.', 'jmhz_assignment_user_name');
        }
        $unchanged = self::unchanged($stored, [
            'jmhz_assignment_user_kind' => 'foreign',
            'jmhz_assignment_user_country_code' => $country,
            'jmhz_assignment_user_foreign_id' => $foreignId,
        ]);
        self::soft(
            $unchanged,
            'jmhz_assignment_user_country_code',
            'assignment_user_country_invalid',
            static function () use ($country): void {
                if (!PayrollRegistrationCodebooks::isCountry($country) || $country === 'CZ') {
                    throw new \InvalidArgumentException(
                        'Zahraniční uživatel u dočasného přidělení potřebuje dvoupísmenný kód cizího státu.',
                    );
                }
            },
            $warnings,
        );
        self::soft(
            $unchanged,
            'jmhz_assignment_user_foreign_id',
            'assignment_user_foreign_id_invalid',
            static function () use ($foreignId): void {
                if (preg_match('/^[0-9]{8}$/', $foreignId) !== 1) {
                    throw new \InvalidArgumentException(
                        'Registrační číslo zahraničního uživatele musí mít pro JMHZ přesně osm číslic.',
                    );
                }
            },
            $warnings,
        );

        return [
            'jmhz_assignment_user_kind' => 'foreign',
            'jmhz_assignment_user_ico' => null,
            'jmhz_assignment_user_country_code' => $country,
            'jmhz_assignment_user_foreign_id' => $foreignId,
            'jmhz_assignment_user_name' => $name,
        ];
    }

    private static function validIco(string $ico): bool
    {
        return preg_match('/^[0-9]{8}$/', $ico) === 1
            && \MyInvoice\Service\Validation::icChecksumValid($ico);
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
            throw new PayrollFieldValidationException("Pole {$key} je příliš dlouhé.", $key);
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalCode(array $input, string $key, int $maxLength): ?string
    {
        $value = $this->optionalText($input, $key, $maxLength);
        if ($value !== null && !preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
            throw new PayrollFieldValidationException("Pole {$key} není platný kód.", $key);
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
     *      Uložení takového kódu vrátí varování, aby ho účetní mohla opravit.
     *
     * @param array<string,mixed> $input
     * @param list<array{field:string,code:string,message:string}> $warnings
     */
    private function optionalCzIscoCode(array $input, string $key, ?string $storedCode = null, ?array &$warnings = null): ?string
    {
        $value = $this->optionalText($input, $key, 16);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[0-9]{4,5}$/D', $value) !== 1) {
            throw new PayrollFieldValidationException(
                "Kód CZ-ISCO {$value} nemá platný tvar. Zadejte čtyř- nebo pětimístné číslo, například 25120.",
                $key,
            );
        }
        $status = $this->czIsco->status($value);
        if ($status === CzIscoCodebook::STATUS_ACTIVE) {
            return $value;
        }
        if ($status === CzIscoCodebook::STATUS_RETIRED) {
            // Vyřazený kód ČSSZ v přihlášce A1 ani v dohlášení nepřijme. Nově
            // zadat ho nejde; uložený historický kód uložení podmínek
            // nezablokuje, jen varuje (přihláška ho odmítne s kotvou na pole).
            $retired = sprintf(
                'Kód CZ-ISCO %s je v klasifikaci zaměstnání ČSÚ (verze %s) vyřazený. '
                    . 'Vyberte platný kód z našeptávače.',
                $value,
                CzIscoCodebook::CLASSIFICATION_VERSION,
            );
            if ($storedCode !== null && $storedCode === $value) {
                $warnings[] = ['field' => $key, 'code' => 'cz_isco_code_retired', 'message' => $retired];

                return $value;
            }
            throw new PayrollFieldValidationException($retired, $key);
        }
        $message = sprintf(
            'Kód CZ-ISCO %s v klasifikaci zaměstnání ČSÚ (verze %s) neexistuje. '
                . 'Vyberte kód z našeptávače — stačí napsat část názvu profese, '
                . 'třeba „účetní" najde 43111 Účetní všeobecní.',
            $value,
            CzIscoCodebook::CLASSIFICATION_VERSION,
        );
        if ($storedCode !== null && $storedCode === $value) {
            $warnings[] = ['field' => $key, 'code' => 'cz_isco_code_unknown', 'message' => $message];

            return $value;
        }
        throw new PayrollFieldValidationException($message, $key);
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

    /**
     * Ano/ne, na které účetní ještě nemusela odpovědět (`null`). Uložený
     * řádek vrací TINYINT jako 0/1.
     *
     * @param array<string,mixed> $input
     */
    private function optionalBool(array $input, string $key): ?bool
    {
        $value = $input[$key] ?? null;
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) (int) $value;
        }
        if ($value !== null && !is_bool($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být boolean nebo null.");
        }

        return $value;
    }

    /**
     * Plná adresa místa výkonu práce pro JMHZ 2027 (10550 až 10554, datový
     * slovník 1.5.0.3; tvar podle XSD 1.5.0.3 `adresaType`). Všechny části
     * jsou nepovinné; u místa výkonu mimo ČR (kód obce 111111, IN41) ani bez
     * kódu obce se adresa v ČR uvést nesmí. Tvar části a vazbu na kód obce
     * ověřuje až XSD hlášení, takže nezměněná uložená adresa uložení
     * nezastaví; délku hlídá šířka sloupce a ta zůstává tvrdá.
     *
     * @param array<string,mixed> $input
     * @param ?array<string,mixed> $stored
     * @param list<array{field:string,code:string,message:string}> $warnings
     * @return array<string,?string>
     */
    private function workplaceAddress(array $input, ?string $municipalityCode, ?array $stored, array &$warnings): array
    {
        $fields = [
            'jmhz_workplace_address_point_code' => [9, '/^[1-9][0-9]{0,8}$/D', 'Kód adresního místa má nejvýše devět číslic.'],
            'jmhz_workplace_street' => [50, null, ''],
            'jmhz_workplace_house_number' => [4, '/^[1-9][0-9]{0,3}$/D', 'Číslo popisné má jednu až čtyři číslice.'],
            'jmhz_workplace_orientation_number' => [12, '/^[0-9A-Za-z]{1,12}$/D', 'Číslo orientační smí obsahovat jen číslice a písmena.'],
            'jmhz_workplace_postal_code' => [5, '/^[0-9]{5}$/D', 'PSČ místa výkonu práce má pět číslic.'],
        ];
        $address = [];
        foreach ($fields as $key => [$length, $pattern, $message]) {
            $raw = $input[$key] ?? null;
            if (is_int($raw)) {
                $raw = (string) $raw;
            }
            $value = $key === 'jmhz_workplace_postal_code' && is_string($raw)
                ? str_replace(' ', '', $raw)
                : $raw;
            $value = $this->optionalText([$key => $value], $key, $length);
            if ($value !== null && $pattern !== null) {
                self::soft(
                    self::unchanged($stored, [$key => $value]),
                    $key,
                    'workplace_address_invalid',
                    static function () use ($pattern, $value, $message): void {
                        if (preg_match($pattern, $value) !== 1) {
                            throw new \InvalidArgumentException($message);
                        }
                    },
                    $warnings,
                );
            }
            $address[$key] = $value;
        }
        $filled = array_filter($address, static fn (?string $value): bool => $value !== null);
        if ($filled !== []) {
            self::soft(
                self::unchanged($stored, [...$address, 'jmhz_workplace_municipality_code' => $municipalityCode]),
                (string) array_key_first($filled),
                'workplace_address_without_municipality',
                static function () use ($municipalityCode): void {
                    if ($municipalityCode === null || $municipalityCode === '111111') {
                        throw new \InvalidArgumentException(
                            'Adresu místa výkonu práce v ČR lze uvést jen s kódem obce v ČR; u místa výkonu'
                                . ' mimo ČR (kód obce 111111) se nevyplňuje.',
                        );
                    }
                },
                $warnings,
            );
        }

        return $address;
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
