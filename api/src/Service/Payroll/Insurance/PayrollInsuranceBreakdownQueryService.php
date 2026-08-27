<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Insurance;

use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;

/**
 * MZ-10-W07 / MZ-11-W07 — „jak vzniklo sociální a zdravotní pojistné".
 *
 * Daň a čistá mzda svůj rozklad mají, pojistné do teď ne: účetní viděl jen
 * výslednou částku a neměl jak ověřit, odkud se vzala. Bez toho za výpočet
 * nemůže převzít odpovědnost a při kontrole nemá čím argumentovat.
 *
 * ODKUD DATA: výhradně z `payroll_statutory_results` (+ osoby a vztahy), tedy
 * z NEMĚNNÉHO výsledku, který uložil `PayrollRunStatutoryResultPersister` v témže
 * běhu, jaký vydal výslednou částku. Žádný přepočet, žádné čtení aktuální sady
 * pravidel: kdyby vysvětlení vznikalo znovu, dřív nebo později se s uloženým
 * výsledkem rozejde a začne lhát — a to je horší než žádné vysvětlení.
 *
 * Run snapshot revize (`payroll_run_revisions.result_snapshot_json`) tutéž osobu
 * nese taky, ale je to odvozená kopie pro obrazovky. Zdrojem pravdy je výsledková
 * tabulka — má vlastní hash, sadu pravidel i stav a je to ta, na kterou se odkazují
 * odvody a platby.
 *
 * FAIL-CLOSED: chybí-li revizi výsledková sada (spočtena starší verzí modulu),
 * vrací se `available:false` a důvod větou. Prázdná karta ani dopočet odhadem ne.
 * Zároveň platí kontrolní součet — mezikroky MUSÍ dát tutéž částku jako uložený
 * výsledek, jinak rozklad neprojde vůbec.
 *
 * DVĚ VÝJIMKY, obě přiznané v odpovědi:
 *
 *  1. Neuložený mezikrok zdravotního pojistného se smí ZREKONSTRUOVAT ze sady
 *     pravidel zmrazené v té revizi, a to jen proti důkazu shodou — otisk sady
 *     musí sedět bajt na bajt a zrekonstruovaný krok musí dát tutéž uloženou
 *     částku ({@see PayrollInsuranceStepReconstructor}). Původ se hlásí jako
 *     `rate_source: reconstructed`, nikdy jako `persisted`.
 *  2. Pojistné zaměstnavatele na sociální osobní veličina není (§ 5a odst. 1
 *     zákona č. 589/1992 Sb.), takže osobní číslo v odpovědi je ROZDĚLENÍ
 *     firemní částky ({@see EmployerSocialInsuranceAllocation}), pojmenované
 *     metodou a označené `is_statutory_personal_amount: false`.
 */
final class PayrollInsuranceBreakdownQueryService
{
    /**
     * Důvody, proč rozklad není k dispozici. Je to smluvní číselník s klientem
     * (`web/src/api/payrollInsurance.ts`) — každý důvod má na obrazovce vlastní
     * větu, takže přidání hodnoty tady bez věty tam je tichá regrese.
     *
     * @var list<string>
     */
    public const UNAVAILABLE_REASONS = [
        'result_set_missing',
        'schema_unsupported',
        'person_missing',
    ];

    /**
     * Odkud pochází sazba zdravotního pojistného. Taky smluvní číselník
     * s klientem — `reconstructed` musí být na obrazovce ODLIŠENÉ od `persisted`,
     * jinak by se doložená rekonstrukce vydávala za uložený mezikrok.
     *
     * @var list<string>
     */
    public const RATE_SOURCES = [
        'persisted',
        'reconstructed',
        'not_recorded',
        'not_applicable',
    ];

    /**
     * Metoda rozdělení pojistného zaměstnavatele na osobu. `not_allocatable`
     * není metoda, ale přiznání, že rozdělit nejde — proto k němu vždy patří
     * důvod z {@see self::EMPLOYER_ALLOCATION_BLOCKERS}.
     *
     * @var list<string>
     */
    public const EMPLOYER_ALLOCATION_METHODS = [
        EmployerSocialInsuranceAllocation::METHOD,
        'not_allocatable',
    ];

    /**
     * Proč rozdělení pojistného zaměstnavatele nevzniklo. Každý důvod má na
     * obrazovce vlastní větu — hodnota bez věty je tichá regrese.
     *
     * @var list<string>
     */
    public const EMPLOYER_ALLOCATION_BLOCKERS = [
        'amounts_missing',
        'assessment_base_missing',
        'company_total_mismatch',
        'discount_unattributable',
        'discount_exceeds_person_share',
    ];

    private const SOCIAL = 'social_insurance';
    private const HEALTH = 'health_insurance';

    public function __construct(
        private readonly PayrollRunRepository $runs,
        private readonly PayrollStatutoryResultRepository $results,
        private readonly PayrollInsuranceStepReconstructor $reconstructor,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function breakdown(int $supplierId, int $revisionId, int $employeeId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize i osoba musí mít kladná ID.',
            );
        }
        $revision = $this->runs->revision($supplierId, $revisionId);
        if ($revision === null) {
            throw new \OutOfBoundsException('Mzdová revize nebyla nalezena.');
        }
        $input = self::object($revision['input_snapshot'] ?? null, 'input_snapshot');
        $employee = $this->frozenEmployee($input, $employeeId);

        return [
            'revision' => [
                'id' => (int) $revision['id'],
                'run_id' => (int) $revision['run_id'],
                'revision_no' => (int) $revision['revision_no'],
                'revision_kind' => (string) ($revision['revision_kind'] ?? ''),
                'status' => (string) ($revision['status'] ?? ''),
            ],
            'person' => [
                'employee_id' => $employeeId,
                'full_name' => (string) ($employee['full_name'] ?? ''),
            ],
            'social' => $this->social($supplierId, $revisionId, $employeeId),
            'health' => $this->health($supplierId, $revisionId, $employeeId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function social(int $supplierId, int $revisionId, int $employeeId): array
    {
        $set = $this->results->find($supplierId, $revisionId, self::SOCIAL);
        if ($set === null) {
            return self::unavailable('result_set_missing');
        }
        if ((string) ($set['schema_version'] ?? '') !== 'payroll-social-result.v1') {
            return self::unavailable('schema_unsupported');
        }
        $person = $this->personRow($set, $employeeId);
        if ($person === null) {
            return self::unavailable('person_missing');
        }
        $result = self::object($person['result_snapshot'] ?? null, 'social.person.result_snapshot');
        $month = self::object($set['result_snapshot'] ?? null, 'social.result_snapshot');

        $participating = self::nonNegativeInt($result, 'participating_assessment_base_minor_units');
        $capped = self::nonNegativeInt($result, 'capped_assessment_base_minor_units');
        $contributionStep = self::step($result['contribution_step'] ?? null, 'social.contribution_step');
        $discountStep = self::step($result['discount_step'] ?? null, 'social.discount_step');
        $beforeDiscount = self::optionalNonNegativeInt(
            $result,
            'employee_contribution_before_discount_minor_units',
        );
        $discount = self::optionalNonNegativeInt($result, 'working_pensioner_discount_minor_units');
        $contribution = self::optionalNonNegativeInt($result, 'employee_contribution_minor_units');
        $status = (string) ($person['result_status'] ?? 'manual_review');

        if ($status === 'calculated') {
            $this->assertEmployeeSocialReconciles(
                $capped,
                $contributionStep,
                $beforeDiscount,
                $discountStep,
                $discount,
                $contribution,
            );
        }

        $employerStep = self::step(
            $month['employer_contribution_step'] ?? null,
            'social.employer_contribution_step',
        );

        return [
            'available' => true,
            'unavailable_reason' => null,
            'status' => $status,
            'calculation_date' => (string) ($month['calculation_date'] ?? ''),
            'ruleset_id' => (string) ($set['ruleset_id'] ?? ''),
            'ruleset_hash' => (string) ($set['ruleset_hash'] ?? ''),
            'jurisdiction' => (string) ($result['jurisdiction'] ?? ''),
            'jurisdiction_evidence_reference' => self::nullableString(
                $result,
                'jurisdiction_evidence_reference',
            ),
            'working_pensioner_discount_evidence_reference' => self::nullableString(
                $result,
                'working_pensioner_discount_evidence_reference',
            ),
            'assessment_base' => [
                'participating_minor' => $participating,
                'capped_minor' => $capped,
                'year_to_date_before_month_minor' => self::nonNegativeInt(
                    $result,
                    'year_to_date_assessment_base_before_month_minor_units',
                ),
                'annual_maximum_reduction_minor' => max(0, $participating - $capped),
                'annual_maximum_applied' => $status === 'calculated' && $capped < $participating,
            ],
            'employee' => [
                'contribution_step' => $contributionStep,
                'before_discount_minor' => $beforeDiscount,
                'discount_step' => $discountStep,
                'working_pensioner_discount_minor' => $discount,
                'contribution_minor' => $contribution,
            ],
            /*
             * Pojistné zaměstnavatele NENÍ osobní veličina: § 5a odst. 1 zákona
             * č. 589/1992 Sb. dělá vyměřovacím základem zaměstnavatele „úhrn
             * vyměřovacích základů jeho zaměstnanců". Proto se vydává tak, jak
             * vznikl — za celou firmu a měsíc.
             *
             * Ty úhrny jsou tři: písm. a) ostatní, písm. b) zdravotničtí
             * záchranáři a jednotky HZS podniku, písm. c) rizikové zaměstnání,
             * každý s vlastní sazbou podle § 7 odst. 1 a vlastním zaokrouhlením
             * podle § 7 odst. 3. `categories` je proto rozpad, ze kterého se dá
             * firemní částka přepočítat; `contribution_step` zůstane prázdný,
             * jakmile kategorie není jediná, protože jedním krokem se ta částka
             * nespočítala.
             *
             * Účetní můstek a nákladová střediska ale číslo na osobu potřebují,
             * takže vedle firemní částky je i `allocation`: ROZDĚLENÍ, ne zákonná
             * částka. Že to zákonná částka není, říká odpověď sama
             * (`is_statutory_personal_amount: false`) a musí to říct i obrazovka.
             */
            'employer' => [
                'scope' => 'company_month',
                'allocation' => $this->employerSocialAllocation($set, $month, $employeeId),
                'categories' => $this->employerSocialCategories($month),
                'contribution_step' => $employerStep,
                'assessment_base_minor' => self::optionalNonNegativeInt(
                    $month,
                    'capped_assessment_base_minor_units',
                ),
                'contribution_before_discount_minor' => self::optionalNonNegativeInt(
                    $month,
                    'employer_contribution_before_discount_minor_units',
                ),
                'part_time_discount_base_minor' => self::optionalNonNegativeInt(
                    $month,
                    'part_time_discount_assessment_base_minor_units',
                ),
                'part_time_discount_step' => self::step(
                    $month['part_time_discount_step'] ?? null,
                    'social.part_time_discount_step',
                ),
                'part_time_discount_minor' => self::optionalNonNegativeInt(
                    $month,
                    'part_time_discount_minor_units',
                ),
                'contribution_minor' => self::optionalNonNegativeInt(
                    $month,
                    'employer_contribution_minor_units',
                ),
            ],
            'relationships' => $this->socialRelationships($person),
            'issues' => self::strings($result['issues'] ?? [], 'social.issues'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function health(int $supplierId, int $revisionId, int $employeeId): array
    {
        $set = $this->results->find($supplierId, $revisionId, self::HEALTH);
        if ($set === null) {
            return self::unavailable('result_set_missing');
        }
        if ((string) ($set['schema_version'] ?? '') !== 'payroll-health-result.v1') {
            return self::unavailable('schema_unsupported');
        }
        $person = $this->personRow($set, $employeeId);
        if ($person === null) {
            return self::unavailable('person_missing');
        }
        $result = self::object($person['result_snapshot'] ?? null, 'health.person.result_snapshot');
        $month = self::object($set['result_snapshot'] ?? null, 'health.result_snapshot');

        $assessmentBase = self::nonNegativeInt($result, 'assessment_base_minor_units');
        $effectiveMinimum = self::nonNegativeInt($result, 'effective_minimum_minor_units');
        $standardStep = self::step(
            $result['standard_contribution_step'] ?? null,
            'health.standard_contribution_step',
        );
        $minimumContributionStep = self::step(
            $result['minimum_contribution_step'] ?? null,
            'health.minimum_contribution_step',
        );
        $topUpStep = self::step($result['minimum_top_up_step'] ?? null, 'health.minimum_top_up_step');
        $standard = self::optionalNonNegativeInt($result, 'standard_contribution_minor_units');
        $employeeStandard = self::optionalNonNegativeInt(
            $result,
            'employee_standard_contribution_minor_units',
        );
        $employerStandard = self::optionalNonNegativeInt(
            $result,
            'employer_standard_contribution_minor_units',
        );
        $employeeTopUp = self::optionalNonNegativeInt($result, 'employee_minimum_top_up_minor_units');
        $employerTopUp = self::optionalNonNegativeInt($result, 'employer_minimum_top_up_minor_units');
        $employee = self::optionalNonNegativeInt($result, 'employee_contribution_minor_units');
        $employer = self::optionalNonNegativeInt($result, 'employer_contribution_minor_units');
        $total = self::optionalNonNegativeInt($result, 'total_contribution_minor_units');
        $status = (string) ($person['result_status'] ?? 'manual_review');

        $stepsRecorded = $standardStep !== null
            || $minimumContributionStep !== null
            || $topUpStep !== null;
        $reconstruction = null;
        if ($status === 'calculated' && !$stepsRecorded) {
            $reconstruction = $this->reconstructHealthSteps(
                $set,
                $assessmentBase,
                $effectiveMinimum,
                $standard,
                ($employeeTopUp ?? 0) + ($employerTopUp ?? 0),
            );
            $standardStep = $reconstruction['standard_step'] ?? null;
            $minimumContributionStep = $reconstruction['minimum_step'] ?? null;
            $topUpStep = $reconstruction['top_up_step'] ?? null;
            $reconstruction = $reconstruction['evidence'] ?? null;
        }

        if ($status === 'calculated') {
            $this->assertHealthReconciles(
                $standardStep,
                $minimumContributionStep,
                $standard,
                $employeeStandard,
                $employerStandard,
                $topUpStep,
                $employeeTopUp,
                $employerTopUp,
                $employee,
                $employer,
                $total,
                $stepsRecorded,
            );
        }

        $insurerCode = self::nullableString($result, 'insurer_code');

        return [
            'available' => true,
            'unavailable_reason' => null,
            'status' => $status,
            'calculation_date' => (string) ($month['calculation_date'] ?? ''),
            'ruleset_id' => (string) ($set['ruleset_id'] ?? ''),
            'ruleset_hash' => (string) ($set['ruleset_hash'] ?? ''),
            'jurisdiction' => (string) ($result['jurisdiction'] ?? ''),
            'jurisdiction_evidence_reference' => self::nullableString(
                $result,
                'jurisdiction_evidence_reference',
            ),
            'insurer' => [
                'status' => (string) ($result['insurer_status'] ?? ''),
                'code' => $insurerCode,
                'evidence_reference' => self::nullableString($result, 'insurer_evidence_reference'),
            ],
            'assessment_base' => [
                'this_employer_minor' => $assessmentBase,
                'other_employers_minor' => self::nonNegativeInt(
                    $result,
                    'other_employer_assessment_base_minor_units',
                ),
                'combined_minor' => self::nonNegativeInt(
                    $result,
                    'combined_assessment_base_minor_units',
                ),
            ],
            'minimum' => [
                'statutory_monthly_minor' => self::nonNegativeInt(
                    $result,
                    'statutory_monthly_minimum_minor_units',
                ),
                'effective_minor' => $effectiveMinimum,
                'employment_calendar_days' => self::nonNegativeInt(
                    $result,
                    'employment_calendar_days',
                ),
                'excluded_calendar_days' => self::nonNegativeInt(
                    $result,
                    'minimum_excluded_calendar_days',
                ),
                'applicable_calendar_days' => self::nonNegativeInt(
                    $result,
                    'minimum_applicable_calendar_days',
                ),
                /*
                 * Nesmí se odvozovat jen z existence mezikroku: revize bez
                 * uložených kroků by dopočet ZATAJILA, ačkoli ho v částkách nese.
                 * Základ dopočtu bez kroku se buď doloží rekonstrukcí, nebo
                 * zůstane null a obrazovka o něm mlčí, místo aby ukázala nulu
                 * jako by žádný nebyl.
                 */
                'top_up_applied' => $topUpStep !== null
                    || (($employeeTopUp ?? 0) + ($employerTopUp ?? 0)) > 0,
                'top_up_base_minor' => $topUpStep['input_minor_units'] ?? null,
                'top_up_responsibility' => (string) ($result['top_up_responsibility'] ?? ''),
                // Prázdný řetězec = revize spočtená dřív, než se původ hodnoty
                // začal ukládat. Nedopočítává se: tehdejší kód chybějící
                // evidenci odmítal, takže o původu netvrdil nic.
                'top_up_responsibility_source' => (string) (
                    $result['top_up_responsibility_source'] ?? ''
                ),
                'top_up_employer_selection' => (string) ($result['top_up_employer_selection'] ?? ''),
                'top_up_responsibility_evidence_reference' => self::nullableString(
                    $result,
                    'top_up_responsibility_evidence_reference',
                ),
                'selected_top_up_employer_evidence_reference' => self::nullableString(
                    $result,
                    'selected_top_up_employer_evidence_reference',
                ),
                'reduction_evidence' => self::rows(
                    $result['minimum_reduction_evidence'] ?? [],
                    'health.minimum_reduction_evidence',
                ),
                'ppz_counted' => (bool) ($result['ppz_counted'] ?? false),
            ],
            'contribution' => [
                /*
                 * `reconstructed` = mezikrok se neuložil, ale jde ho DOLOŽIT ze
                 * sady pravidel zmrazené v té revizi: otisk sady sedí bajt na bajt
                 * a zrekonstruovaný krok dá po zaokrouhlení tutéž uloženou částku
                 * ({@see PayrollInsuranceStepReconstructor}). Od `persisted` se
                 * to musí lišit i na obrazovce — je to důkaz, ne uložený záznam.
                 *
                 * `not_recorded` = mezikrok se neuložil a doložit ho nejde.
                 * Dopočítat ho z DNEŠNÍ sady pravidel nelze — popisovala by jiný
                 * výpočet než ten, který dal částku.
                 *
                 * `not_applicable` = pojistné nevzniklo (bez účasti, cizí režim).
                 * Krok chybí právem a tvrdit „neuložilo se" by byl planý poplach.
                 */
                'rate_source' => $this->rateSource(
                    $standardStep,
                    $standard,
                    $status,
                    $reconstruction !== null,
                ),
                'rate_reconstruction' => $reconstruction,
                'standard_step' => $standardStep,
                'minimum_total_step' => $minimumContributionStep,
                'standard_minor' => $standard,
                'employee_standard_minor' => $employeeStandard,
                'employer_standard_minor' => $employerStandard,
                'top_up_step' => $topUpStep,
                'employee_top_up_minor' => $employeeTopUp,
                'employer_top_up_minor' => $employerTopUp,
                'employee_minor' => $employee,
                'employer_minor' => $employer,
                'total_minor' => $total,
            ],
            'relationships' => $this->healthRelationships($person),
            'other_employer_evidence' => self::rows(
                $result['other_employer_evidence'] ?? [],
                'health.other_employer_evidence',
            ),
            /*
             * Rozpad podle pojišťoven je firemní veličina — právě podle něj se
             * odvádí a právě ten musí účetní odsouhlasit s přehledy. Osoba do něj
             * patří jedním kódem (`is_person_insurer`), víc pojišťoven u jedné
             * osoby v jednom měsíci model nezná.
             */
            'insurer_liabilities' => $this->insurerLiabilities($month, $insurerCode),
            'issues' => self::strings($result['issues'] ?? [], 'health.issues'),
        ];
    }

    /**
     * Doložená rekonstrukce mezikroků zdravotního pojistného u revize, která je
     * neuložila. Přijímá se jen proti důkazu shodou — mechanika a její důvody
     * jsou v {@see PayrollInsuranceStepReconstructor}.
     *
     * Vrací prázdné pole, když doložit nejde nic; `evidence` je v odpovědi
     * jediné místo, kde se rekonstruovaný původ přizná.
     *
     * @param array<string,mixed> $set
     * @return array{standard_step?:array<string,mixed>,minimum_step?:array<string,mixed>,top_up_step?:array<string,mixed>,evidence?:array<string,mixed>}
     */
    private function reconstructHealthSteps(
        array $set,
        int $assessmentBase,
        int $effectiveMinimum,
        ?int $standard,
        int $topUpTotal,
    ): array {
        $rulesetId = (string) ($set['ruleset_id'] ?? '');
        $rulesetHash = (string) ($set['ruleset_hash'] ?? '');
        $standardMatch = $this->reconstructor->healthStep(
            PayrollInsuranceStepReconstructor::HEALTH_STANDARD_LABEL,
            $rulesetId,
            $rulesetHash,
            $assessmentBase,
            $standard ?? 0,
        );
        $topUpMatch = $this->reconstructor->healthMinimumTopUpStep(
            $rulesetId,
            $rulesetHash,
            $assessmentBase,
            $effectiveMinimum,
            $standard ?? 0,
            $topUpTotal,
        );
        if ($standardMatch === null && $topUpMatch === null) {
            return [];
        }
        $version = ($standardMatch ?? $topUpMatch)['version'];
        $reconstructed = [
            'evidence' => [
                'ruleset_id' => $version->id,
                'ruleset_version' => $version->version,
                'ruleset_hash' => $version->canonicalHash,
                'parameter_key' => PayrollInsuranceStepReconstructor::HEALTH_RATE_PARAMETER,
                'proof' => 'ruleset_hash_and_amount_match',
                'standard_reconstructed' => $standardMatch !== null,
                'top_up_reconstructed' => $topUpMatch !== null,
                'top_up_rounding_method' => $topUpMatch['rounding_method'] ?? null,
            ],
        ];
        if ($standardMatch !== null) {
            $reconstructed['standard_step'] = self::step(
                $standardMatch['step'],
                'health.standard_contribution_step',
            );
        }
        if ($topUpMatch !== null) {
            $reconstructed['top_up_step'] = self::step(
                $topUpMatch['step'],
                'health.minimum_top_up_step',
            );
            if (($topUpMatch['minimum_step'] ?? null) !== null) {
                $reconstructed['minimum_step'] = self::step(
                    $topUpMatch['minimum_step'],
                    'health.minimum_contribution_step',
                );
            }
        }

        return $reconstructed;
    }

    /**
     * Rozdělení pojistného zaměstnavatele na sociální mezi osoby běhu.
     *
     * Metodu i zbytkové pravidlo drží {@see EmployerSocialInsuranceAllocation},
     * kterou používá i výplatní páska — dvě nezávislá rozdělení téže částky by
     * se rozešla a účetní by měl na pásce jiné číslo než v rozkladu.
     *
     * Rozděluje se ZVLÁŠŤ pojistné před slevou (poměrem vyměřovacích základů)
     * a sleva za částečné úvazky (poměrem základů vztahů, které ji doloženě
     * uplatnily). Rozpustit slevu poměrem všech základů by ji přiznala i lidem,
     * kterým nenáleží.
     *
     * Pojistné před slevou se dělí UVNITŘ kategorie § 5a odst. 1: každá vznikla
     * jinou sazbou, takže její podíl smí dostat jen ten, kdo do ní vstoupil.
     */

    /**
     * Rozpad firemního pojistného po písmenech § 5a odst. 1.
     *
     * Starší uložené revize kategorie nenesou — tam je seznam prázdný a karta
     * ukáže jediný krok tak jako dřív. Dopočítat rozpad zpětně nejde: kategorie
     * se ve zmrazeném vstupu nevyskytovala a odhad písmene by byl odhad sazby.
     *
     * @param array<string,mixed> $month
     * @return list<array<string,mixed>>
     */
    private function employerSocialCategories(array $month): array
    {
        $categories = [];
        foreach (self::rows($month['employer_categories'] ?? [], 'social.employer_categories') as $row) {
            $categories[] = [
                'category' => (string) ($row['category'] ?? ''),
                'paragraph5a_letter' => (string) ($row['paragraph5a_letter'] ?? ''),
                'assessment_base_minor' => self::nonNegativeInt(
                    $row,
                    'assessment_base_minor_units',
                ),
                'contribution_minor' => self::nonNegativeInt($row, 'contribution_minor_units'),
                'contribution_step' => self::step(
                    $row['contribution_step'] ?? null,
                    'social.employer_categories.contribution_step',
                ),
            ];
        }

        return $categories;
    }

    /**
     * @param array<string,mixed> $set
     * @param array<string,mixed> $month
     * @return array<string,mixed>
     */
    private function employerSocialAllocation(array $set, array $month, int $employeeId): array
    {
        $contribution = self::optionalNonNegativeInt($month, 'employer_contribution_minor_units');
        $beforeDiscount = self::optionalNonNegativeInt(
            $month,
            'employer_contribution_before_discount_minor_units',
        );
        $discount = self::optionalNonNegativeInt($month, 'part_time_discount_minor_units');

        $categoryAmounts = [];
        foreach (self::rows($month['employer_categories'] ?? [], 'social.employer_categories') as $row) {
            $categoryAmounts[(string) ($row['category'] ?? '')] =
                self::nonNegativeInt($row, 'contribution_minor_units');
        }

        $cappedBases = [];
        $discountBases = [];
        $categoryBases = array_fill_keys(array_keys($categoryAmounts), []);
        $blocker = null;
        try {
            foreach (self::rows($set['people'] ?? [], 'statutory_result.people') as $person) {
                $id = self::positiveInt($person, 'employee_id');
                $result = self::object(
                    $person['result_snapshot'] ?? null,
                    'social.person.result_snapshot',
                );
                $cappedBases[$id] = self::nonNegativeInt(
                    $result,
                    'capped_assessment_base_minor_units',
                );
                $discountBase = 0;
                foreach ($categoryBases as $category => $unused) {
                    $categoryBases[$category][$id] = 0;
                }
                foreach (self::rows($person['relationships'] ?? [], 'social.relationships') as $row) {
                    $snapshot = self::object(
                        $row['result_snapshot'] ?? null,
                        'social.relationship.result_snapshot',
                    );
                    $relationshipBase = self::nonNegativeInt(
                        $snapshot,
                        'capped_assessment_base_minor_units',
                    );
                    $discountOutcome = $snapshot['part_time_employer_discount_outcome'] ?? null;
                    if ((string) ($snapshot['part_time_employer_discount'] ?? '') === 'verified'
                        && ($discountOutcome === null || $discountOutcome === 'applied')
                    ) {
                        $discountBase += $relationshipBase;
                    }
                    $category = (string) ($snapshot['employer_rate_category'] ?? '');
                    if (array_key_exists($category, $categoryBases)) {
                        $categoryBases[$category][$id] += $relationshipBase;
                    } elseif ($categoryAmounts !== []) {
                        throw new \UnexpectedValueException(
                            'Vztah spadá do kategorie, kterou firemní výsledek nezná.',
                        );
                    }
                }
                $discountBases[$id] = $discountBase;
            }
        } catch (\UnexpectedValueException) {
            /*
             * Váhy se čtou i za OSTATNÍ osoby běhu. Kdyby na jedné z nich chyběl
             * vyměřovací základ, shodila by celou kartu i lidem, jejichž data
             * jsou v pořádku — rozdělení proto v takovém případě jen nevznikne.
             * Rozklad samotné osoby se čte jinde a fail-closed tam platí dál.
             */
            $blocker = 'amounts_missing';
        }
        ksort($cappedBases, SORT_NUMERIC);
        ksort($discountBases, SORT_NUMERIC);
        foreach ($categoryBases as $category => $weights) {
            ksort($weights, SORT_NUMERIC);
            $categoryBases[$category] = $weights;
        }
        $companyBase = array_sum($cappedBases);

        $blocker ??= match (true) {
            $contribution === null || $beforeDiscount === null || $discount === null
                => 'amounts_missing',
            $beforeDiscount - $discount !== $contribution => 'company_total_mismatch',
            $categoryAmounts !== [] && array_sum($categoryAmounts) !== $beforeDiscount
                => 'company_total_mismatch',
            $beforeDiscount > 0 && $companyBase === 0 => 'assessment_base_missing',
            $discount > 0 && array_sum($discountBases) === 0 => 'discount_unattributable',
            default => null,
        };

        $allocations = [];
        if ($blocker === null) {
            try {
                /*
                 * Revize uložené dřív, než výsledek nesl kategorie, žádné nemají.
                 * Tam se dělí po staru — jedinou kategorií, kterou tehdy uměly,
                 * byla běžná sazba, takže se poměr nemění a starý rozklad se
                 * čte dál stejně jako v den, kdy vznikl.
                 */
                $allocations = $categoryAmounts === []
                    ? EmployerSocialInsuranceAllocation::allocate(
                        $cappedBases,
                        $discountBases,
                        (int) $beforeDiscount,
                        (int) $discount,
                    )
                    : EmployerSocialInsuranceAllocation::allocateByCategory(
                        $categoryBases,
                        $categoryAmounts,
                        $discountBases,
                        (int) $discount,
                    );
            } catch (\DomainException) {
                $blocker = 'discount_exceeds_person_share';
            } catch (\InvalidArgumentException) {
                $blocker = 'amounts_missing';
            }
        }
        if ($blocker !== null) {
            if (!in_array($blocker, self::EMPLOYER_ALLOCATION_BLOCKERS, true)) {
                throw new \LogicException('Nepodporovaný důvod, proč rozdělení nevzniklo.');
            }

            return [
                'method' => 'not_allocatable',
                'not_allocatable_reason' => $blocker,
                'residual_rule' => null,
                'is_statutory_personal_amount' => false,
                'people_count' => count($cappedBases),
                'company_assessment_base_minor' => $companyBase,
                'company_contribution_minor' => $contribution,
                'person_assessment_base_minor' => $cappedBases[$employeeId] ?? null,
                'person_minor' => null,
            ];
        }

        return [
            'method' => EmployerSocialInsuranceAllocation::METHOD,
            'not_allocatable_reason' => null,
            'residual_rule' => EmployerSocialInsuranceAllocation::RESIDUAL_RULE,
            'is_statutory_personal_amount' => false,
            'people_count' => count($cappedBases),
            'company_assessment_base_minor' => $companyBase,
            'company_contribution_minor' => $contribution,
            'person_assessment_base_minor' => $cappedBases[$employeeId] ?? null,
            'person_minor' => $allocations[$employeeId] ?? null,
        ];
    }

    /**
     * Rozlišuje „krok se neuložil" od „krok nevznikl". Bez toho by osoba bez
     * účasti na pojištění hlásila chybějící sazbu, ačkoli žádná neexistuje —
     * planý poplach, který účetní naučí varování ignorovat.
     *
     * @param array<string,mixed>|null $standardStep
     */
    private function rateSource(
        ?array $standardStep,
        ?int $standard,
        string $status,
        bool $reconstructed,
    ): string {
        if ($reconstructed) {
            return 'reconstructed';
        }
        if ($standardStep !== null) {
            return 'persisted';
        }
        if ($status !== 'calculated') {
            return 'persisted';
        }

        return ($standard ?? 0) === 0 ? 'not_applicable' : 'not_recorded';
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array<string,mixed>>
     */
    private function socialRelationships(array $person): array
    {
        $result = [];
        foreach (self::rows($person['relationships'] ?? [], 'social.relationships') as $row) {
            $snapshot = self::object(
                $row['result_snapshot'] ?? null,
                'social.relationship.result_snapshot',
            );
            $participation = self::object(
                $snapshot['participation'] ?? null,
                'social.relationship.participation',
            );
            $result[] = [
                'employment_id' => self::positiveInt($row, 'employment_id'),
                'relationship_reference' => (string) ($snapshot['relationship_id'] ?? ''),
                'kind' => (string) ($snapshot['kind'] ?? ''),
                'result_status' => (string) ($row['result_status'] ?? ''),
                'participation_status' => (string) ($participation['status'] ?? ''),
                'participation_income_minor' => self::nonNegativeInt(
                    $participation,
                    'participation_income_minor_units',
                ),
                'group_income_minor' => self::nonNegativeInt(
                    $participation,
                    'group_income_minor_units',
                ),
                'threshold_minor' => self::optionalNonNegativeInt(
                    $participation,
                    'threshold_minor_units',
                ),
                'reason_codes' => self::strings(
                    $participation['reason_codes'] ?? [],
                    'social.relationship.reason_codes',
                ),
                'assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'assessment_base_minor_units',
                ),
                'capped_assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'capped_assessment_base_minor_units',
                ),
                'included_participation_components' => self::strings(
                    $snapshot['included_participation_components'] ?? [],
                    'social.relationship.included_participation_components',
                ),
                'excluded_participation_components' => self::strings(
                    $snapshot['excluded_participation_components'] ?? [],
                    'social.relationship.excluded_participation_components',
                ),
                'included_assessment_base_components' => self::strings(
                    $snapshot['included_assessment_base_components'] ?? [],
                    'social.relationship.included_assessment_base_components',
                ),
                'excluded_assessment_base_components' => self::strings(
                    $snapshot['excluded_assessment_base_components'] ?? [],
                    'social.relationship.excluded_assessment_base_components',
                ),
                'part_time_employer_discount' => (string) (
                    $snapshot['part_time_employer_discount'] ?? ''
                ),
                'employer_rate_category' => (string) ($snapshot['employer_rate_category'] ?? ''),
                'annual_maximum_allocation_order' => self::optionalPositiveInt(
                    $snapshot,
                    'annual_maximum_allocation_order',
                ),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array<string,mixed>>
     */
    private function healthRelationships(array $person): array
    {
        $result = [];
        foreach (self::rows($person['relationships'] ?? [], 'health.relationships') as $row) {
            $snapshot = self::object(
                $row['result_snapshot'] ?? null,
                'health.relationship.result_snapshot',
            );
            $participation = self::object(
                $snapshot['participation'] ?? null,
                'health.relationship.participation',
            );
            $result[] = [
                'employment_id' => self::positiveInt($row, 'employment_id'),
                'relationship_reference' => (string) ($snapshot['relationship_id'] ?? ''),
                'kind' => (string) ($snapshot['kind'] ?? ''),
                'result_status' => (string) ($row['result_status'] ?? ''),
                'participation_status' => (string) ($participation['status'] ?? ''),
                'relationship_income_minor' => self::nonNegativeInt(
                    $participation,
                    'relationship_income_minor_units',
                ),
                'group_income_minor' => self::nonNegativeInt(
                    $participation,
                    'group_income_minor_units',
                ),
                'threshold_minor' => self::optionalNonNegativeInt(
                    $participation,
                    'threshold_minor_units',
                ),
                'reason_codes' => self::strings(
                    $participation['reason_codes'] ?? [],
                    'health.relationship.reason_codes',
                ),
                'assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'assessment_base_minor_units',
                ),
                'participating_assessment_base_minor' => self::nonNegativeInt(
                    $snapshot,
                    'participating_assessment_base_minor_units',
                ),
                'included_participation_components' => self::strings(
                    $snapshot['included_participation_components'] ?? [],
                    'health.relationship.included_participation_components',
                ),
                'excluded_participation_components' => self::strings(
                    $snapshot['excluded_participation_components'] ?? [],
                    'health.relationship.excluded_participation_components',
                ),
                'included_assessment_base_components' => self::strings(
                    $snapshot['included_assessment_base_components'] ?? [],
                    'health.relationship.included_assessment_base_components',
                ),
                'excluded_assessment_base_components' => self::strings(
                    $snapshot['excluded_assessment_base_components'] ?? [],
                    'health.relationship.excluded_assessment_base_components',
                ),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $month
     * @return list<array<string,mixed>>
     */
    private function insurerLiabilities(array $month, ?string $personInsurerCode): array
    {
        $result = [];
        foreach (self::rows($month['insurer_liabilities'] ?? [], 'health.insurer_liabilities') as $row) {
            $code = (string) ($row['insurer_code'] ?? '');
            $result[] = [
                'insurer_code' => $code,
                'is_person_insurer' => $personInsurerCode !== null && $code === $personInsurerCode,
                'person_count' => self::nonNegativeInt($row, 'person_count'),
                'assessment_base_minor' => self::nonNegativeInt($row, 'assessment_base_minor_units'),
                'employee_minor' => self::nonNegativeInt($row, 'employee_contribution_minor_units'),
                'employer_minor' => self::nonNegativeInt($row, 'employer_contribution_minor_units'),
                'total_minor' => self::nonNegativeInt($row, 'total_contribution_minor_units'),
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int =>
                strcmp((string) $left['insurer_code'], (string) $right['insurer_code']),
        );

        return $result;
    }

    /**
     * Rozklad, který nedá výslednou částku, není vysvětlení — je to druhý, tichý
     * výpočet. Radši spadneme, než abychom účetnímu ukázali čísla, která nesedí.
     *
     * @param array<string,mixed>|null $contributionStep
     * @param array<string,mixed>|null $discountStep
     */
    private function assertEmployeeSocialReconciles(
        int $cappedBase,
        ?array $contributionStep,
        ?int $beforeDiscount,
        ?array $discountStep,
        ?int $discount,
        ?int $contribution,
    ): void {
        if ($beforeDiscount === null || $discount === null || $contribution === null) {
            throw new \DomainException(
                'Vypočtený sociální výsledek nemá všechny částky pojistného zaměstnance.',
            );
        }
        if ($beforeDiscount - $discount !== $contribution) {
            throw new \DomainException(
                'Rozklad sociálního pojištění nedává uloženou částku pojistného zaměstnance.',
            );
        }
        if ($contributionStep === null) {
            if ($beforeDiscount !== 0) {
                throw new \DomainException(
                    'Sociální pojistné bez mezikroku výpočtu nesmí být nenulové.',
                );
            }

            return;
        }
        self::assertStepRoundsTo($contributionStep, $cappedBase, $beforeDiscount, 'sociálního');
        if ($discountStep !== null) {
            self::assertStepInput($discountStep, $cappedBase, 'slevy pro pracujícího důchodce');
        } elseif ($discount !== 0) {
            throw new \DomainException(
                'Sleva pro pracujícího důchodce bez mezikroku výpočtu nesmí být nenulová.',
            );
        }
    }

    /**
     * @param array<string,mixed>|null $standardStep
     * @param array<string,mixed>|null $minimumContributionStep
     * @param array<string,mixed>|null $topUpStep
     */
    private function assertHealthReconciles(
        ?array $standardStep,
        ?array $minimumContributionStep,
        ?int $standard,
        ?int $employeeStandard,
        ?int $employerStandard,
        ?array $topUpStep,
        ?int $employeeTopUp,
        ?int $employerTopUp,
        ?int $employee,
        ?int $employer,
        ?int $total,
        bool $stepsRecorded,
    ): void {
        foreach ([
            $standard,
            $employeeStandard,
            $employerStandard,
            $employeeTopUp,
            $employerTopUp,
            $employee,
            $employer,
            $total,
        ] as $amount) {
            if ($amount === null) {
                throw new \DomainException(
                    'Vypočtený zdravotní výsledek nemá všechny částky pojistného.',
                );
            }
        }
        if ($employeeStandard + $employerStandard !== $standard) {
            throw new \DomainException(
                'Podíly zaměstnance a zaměstnavatele nedávají uložené zdravotní pojistné.',
            );
        }
        if ($employeeStandard + $employeeTopUp !== $employee
            || $employerStandard + $employerTopUp !== $employer
            || $employee + $employer !== $total
        ) {
            throw new \DomainException(
                'Rozklad zdravotního pojištění nedává uložené částky pojistného.',
            );
        }
        if ($standardStep !== null) {
            self::assertRounding($standardStep, $standard, 'zdravotního');
        } elseif ($standard !== 0) {
            /*
             * Starší revize krok neuchovala a doložit ho nešlo. To se nesmí
             * zamlčet ani dopočítat — konzument dostane `rate_source:
             * not_recorded` a řekne to větou.
             */
            return;
        }
        if ($minimumContributionStep !== null) {
            self::assertRounding($minimumContributionStep, $total, 'minimálního celkového zdravotního');
            if ($employeeTopUp + $employerTopUp !== $total - $standard) {
                throw new \DomainException(
                    'Dopočet do minima není rozdílem celkového a standardního pojistného.',
                );
            }
        } elseif ($topUpStep !== null) {
            self::assertHealthTopUpRounding(
                $standardStep,
                $topUpStep,
                $standard,
                $employeeTopUp + $employerTopUp,
            );
        } elseif ($employeeTopUp + $employerTopUp !== 0 && $stepsRecorded) {
            /*
             * Tvrdá podmínka platí jen revizi, která mezikroky ukládala: tam je
             * chybějící krok u nenulového dopočtu rozpor. Revize, která je
             * neukládala vůbec, ho po právu nemá a rekonstrukce zdravotní sazby
             * na ni nesmí uvalit přísnější pravidlo, než platilo předtím.
             */
            throw new \DomainException(
                'Dopočet do minimálního vyměřovacího základu bez mezikroku nesmí být nenulový.',
            );
        }
    }

    /**
     * @param array<string,mixed>|null $standardStep
     * @param array<string,mixed> $topUpStep
     */
    private static function assertHealthTopUpRounding(
        ?array $standardStep,
        array $topUpStep,
        int $standard,
        int $topUp,
    ): void {
        $fractions = [];
        if ($standardStep !== null) {
            $fractions[] = [
                'numerator' => self::nonNegativeInt($standardStep, 'unrounded_numerator'),
                'denominator' => self::positiveInt($standardStep, 'unrounded_denominator'),
            ];
        }
        $fractions[] = [
            'numerator' => self::nonNegativeInt($topUpStep, 'unrounded_numerator'),
            'denominator' => self::positiveInt($topUpStep, 'unrounded_denominator'),
        ];
        $expected = PayrollRounding::healthMinimumTopUp(
            $standard,
            PayrollRounding::ceilFractionSumToMultiple($fractions, 100),
        );
        $legacy = PayrollRounding::ceilToCzk(
            self::nonNegativeInt($topUpStep, 'output_minor_units'),
        );
        if ($topUp !== $expected && $topUp !== $legacy) {
            throw new \DomainException(
                'Zaokrouhlení dopočtu do minima neodpovídá uložené částce.',
            );
        }
    }

    /** @param array<string,mixed> $step */
    private static function assertStepRoundsTo(
        array $step,
        int $expectedInput,
        int $amount,
        string $context,
    ): void {
        self::assertStepInput($step, $expectedInput, $context);
        self::assertRounding($step, $amount, $context);
    }

    /** @param array<string,mixed> $step */
    private static function assertStepInput(array $step, int $expectedInput, string $context): void
    {
        if (self::nonNegativeInt($step, 'input_minor_units') !== $expectedInput) {
            throw new \DomainException(
                "Mezikrok {$context} pojistného nevychází z uloženého vyměřovacího základu.",
            );
        }
    }

    /**
     * Uložené pojistné je vždy krok zaokrouhlený nahoru na celé koruny. Kdyby to
     * neplatilo, rozklad by ukazoval jiné číslo než výsledek.
     *
     * @param array<string,mixed> $step
     */
    private static function assertRounding(array $step, int $amount, string $context): void
    {
        $raw = self::nonNegativeInt($step, 'output_minor_units');
        if ($amount % 100 !== 0 || $amount < $raw || $amount - $raw >= 100) {
            throw new \DomainException(
                "Zaokrouhlení {$context} pojistného neodpovídá uložené částce.",
            );
        }
    }

    /**
     * @param array<string,mixed> $set
     * @return array<string,mixed>|null
     */
    private function personRow(array $set, int $employeeId): ?array
    {
        foreach (self::rows($set['people'] ?? [], 'statutory_result.people') as $person) {
            if (self::positiveInt($person, 'employee_id') === $employeeId) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function frozenEmployee(array $input, int $employeeId): array
    {
        foreach (self::rows($input['people'] ?? null, 'input_snapshot.people') as $person) {
            $employee = self::object($person['employee'] ?? null, 'input_snapshot.person.employee');
            if (self::positiveInt($employee, 'id') === $employeeId) {
                return $employee;
            }
        }

        throw new \OutOfBoundsException('Osoba není součástí této mzdové revize.');
    }

    /** @return array<string,mixed> */
    private static function unavailable(string $reason): array
    {
        if (!in_array($reason, self::UNAVAILABLE_REASONS, true)) {
            throw new \LogicException('Nepodporovaný důvod nedostupnosti rozkladu.');
        }

        return ['available' => false, 'unavailable_reason' => $reason];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function step(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        $step = self::object($value, $field);
        $rate = self::object($step['rate'] ?? null, "{$field}.rate");

        return [
            'label' => (string) ($step['label'] ?? ''),
            'input_minor_units' => self::nonNegativeInt($step, 'input_minor_units'),
            'rate' => [
                'decimal' => (string) ($rate['decimal'] ?? ''),
                'numerator' => self::nonNegativeInt($rate, 'numerator'),
                'denominator' => self::positiveInt($rate, 'denominator'),
                'scale' => self::nonNegativeInt($rate, 'scale'),
            ],
            'unrounded_numerator' => self::nonNegativeInt($step, 'unrounded_numerator'),
            'unrounded_denominator' => self::positiveInt($step, 'unrounded_denominator'),
            'rounding_mode' => (string) ($step['rounding_mode'] ?? ''),
            'output_minor_units' => self::nonNegativeInt($step, 'output_minor_units'),
        ];
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException("{$field} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = self::object($row, "{$field}.{$index}");
        }

        return $result;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException("{$field} musí obsahovat jen texty.");
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$field} musí být text nebo null.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$field} musí být celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value < 0) {
            throw new \UnexpectedValueException("{$field} musí být nezáporné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function optionalNonNegativeInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::nonNegativeInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function optionalPositiveInt(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::positiveInt($row, $field);
    }
}
