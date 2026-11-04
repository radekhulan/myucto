<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpPensionAgeCode;
use MyInvoice\Service\Payroll\Time\PayrollJmhzEvidenceStateDays;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;

final class JmhzEldpEvidenceBuilder
{
    public const BUILDER_VERSION = 'jmhz-eldp-evidence.v1';

    private const ATTRIBUTE_IDS = [
        '10239', '10502', '10354', '10355', '10240', '10241', '10242',
        '10356', '10245', '10357', '10358', '10359', '10360', '10362',
        '10536', '10366', '10473', '10474', '10475', '10375', '10462',
        '10463', '10464', '10465', '10466', '10468', '10469',
    ];

    /**
     * Druh nepřítomnosti → atributy pracovního souhrnu, ve kterých se TÁŽ
     * nepřítomnost musí projevit.
     *
     * Seznam je zároveň výčtem toho, co ordinary řez umí odbavit bez účetní:
     * druh musí mít jednoznačné zacházení ve VYLOUČENÝCH DOBÁCH
     * ({@see EldpExcludedPeriodDeriver}) i v NEODPRACOVANÝCH HODINÁCH
     * ({@see \MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver}).
     * Jen tak jde jeden zmrazený zdroj ověřit druhým.
     *
     * Blokovat dál zůstává nerozlišené „jiné". Peněžitou pomoc v mateřství
     * umí až souhrn v3 (hodinový blok `maternity_millihours`), náhradní volno
     * za přesčas až souhrn v5 (viz absenceWorkSummaryFields()).
     */
    private const ABSENCE_WORK_SUMMARY_FIELDS = [
        'vacation' => ['vacation_millihours'],
        'dpn' => [
            'dpn_with_employer_compensation_millihours',
            'dpn_without_employer_compensation_millihours',
        ],
        'quarantine' => [
            'dpn_with_employer_compensation_millihours',
            'dpn_without_employer_compensation_millihours',
        ],
        'ocr' => ['care_millihours'],
        'long_term_care' => ['care_millihours'],
    ];

    /**
     * Druhy nepřítomnosti, které umí až souhrn `jmhz-work-month.v3`.
     *
     * Starší souhrn pro ně nemá hodinový blok, takže by se den nedal proti
     * ničemu ověřit — u v2 zůstávají fail-closed přesně jako dřív.
     */
    private const V3_ABSENCE_WORK_SUMMARY_FIELDS = [
        'ppm' => ['maternity_millihours'],
        'paternity' => ['paternity_millihours'],
        'parental' => ['parental_millihours'],
        'unpaid_leave' => ['unpaid_leave_millihours'],
        // Pracovní volno bez náhrady mzdy stojí v souhrnu na témž bloku jako
        // neplacené volno ({@see \MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver}).
        'public_function' => ['unpaid_leave_millihours'],
        'employee_obstacle_unpaid' => ['unpaid_leave_millihours'],
        'invalid_termination' => ['unpaid_leave_millihours'],
        'unexcused' => ['unexcused_millihours'],
        'employee_obstacle' => ['employee_obstacle_paid_millihours'],
        'employer_obstacle' => ['employer_obstacle_millihours'],
    ];

    /** Bloky 10277–10280 pokryté ordinary řezem, v pořadí pracovního souhrnu. */
    private const UNWORKED_FIELDS = [
        'dpn_without_employer_compensation_millihours',
        'dpn_with_employer_compensation_millihours',
        'vacation_millihours',
        'care_millihours',
    ];

    /**
     * Bloky navíc, které nese až souhrn `jmhz-work-month.v3`.
     *
     * 10471/10472 jsou překážky v práci (interakce IN08); zbytek jsou hodiny
     * bez atributu hlášení, které do úhrnu 10275 patří, ale vlastní rozpad
     * v hlášení nemají.
     */
    private const V3_UNWORKED_FIELDS = [
        'employee_obstacle_paid_millihours',
        'employer_obstacle_millihours',
        // PPM vyplácí ČSSZ, zaměstnavatel za ni nic neplatí, takže do 10276
        // nepatří, jen do úhrnu 10275.
        'maternity_millihours',
        'paternity_millihours',
        'parental_millihours',
        'unpaid_leave_millihours',
        'unexcused_millihours',
    ];

    /**
     * Neodpracované hodiny, za které platí náhradu ZAMĚSTNAVATEL
     * (`unworked_paid_millihours` pracovního souhrnu).
     *
     * Dovolená (§ 222 ZP) a nemoc uvnitř okna § 192 ZP. Nemoc za oknem platí
     * dávka ČSSZ a ošetřovné taky, proto tam nepatří — stejná definice, jakou
     * počítá {@see \MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver}.
     * Atribut 10276 je užší o nemoc, kterou hlášení vede v 10471; převádí
     * ji serializér ({@see JmhzScenario1XmlSerializer::reportedUnworkedHours()}).
     */
    private const PAID_UNWORKED_FIELDS = [
        'vacation_millihours',
        'dpn_with_employer_compensation_millihours',
        // Obě překážky v práci se v aplikaci evidují jen s náhradou mzdy;
        // 10276 je „hodiny s náhradou či nekrácením mzdy", takže tam patří.
        // Otcovskou, rodičovskou, neplacené volno ani neomluvenou absenci
        // zaměstnavatel neplatí, a proto do 10276 nevstupují.
        'employee_obstacle_paid_millihours',
        'employer_obstacle_millihours',
        // Svátek v jinak pracovní den: mzda se nekrátí nebo náleží náhrada
        // (pokyny MPSV k 10276). Nese ho až souhrn v7/v8.
        'holiday_millihours',
    ];

    /**
     * Atribut vyloučené doby → hodinové bloky téže nepřítomnosti.
     *
     * Slouží k příčné kontrole: den vyloučené doby smí vzniknout jen tam, kde
     * pracovní souhrn nezávisle vykazuje hodiny téhož druhu, a naopak.
     */
    private const EXCLUDED_ATTRIBUTE_FIELDS = [
        'docasNeschopnost' => [
            'dpn_with_employer_compensation_millihours',
            'dpn_without_employer_compensation_millihours',
        ],
        'osetrovaniClenaRodiny' => ['care_millihours'],
        'penezitaPomocMaterstvi' => ['maternity_millihours'],
        'otcovska' => ['paternity_millihours'],
    ];

    /**
     * Atributy, u kterých příčná kontrola platí jen jedním směrem: den ⇒ hodiny.
     *
     * 10359 nese jen PŘEDPORODNÍ část peněžité pomoci v mateřství, kdežto
     * hodinový blok pracovního souhrnu celou nepřítomnost. Měsíc po porodu
     * proto legitimně vykazuje hodiny PPM bez jediného vyloučeného dne.
     * Totéž ošetřovné: 10360 končí podpůrčí dobou (9, 16 nebo 90 dnů), hodiny
     * péče trvají celou nepřítomnost.
     */
    private const ONE_WAY_EXCLUDED_ATTRIBUTES = ['penezitaPomocMaterstvi', 'osetrovaniClenaRodiny'];

    /**
     * Druhy nepřítomnosti, u kterých neodpracované hodiny být NEMUSÍ.
     *
     * Doba trvání vztahu po neplatném skončení (§ 16 odst. 4 písm. j) zákona
     * č. 155/1995 Sb.) je právní stav, ne výpadek ze směn: u člena orgánu bez
     * pracovní doby (formulář `cinnostKS`) ani u vztahu, kterému se na tu dobu
     * směny nezveřejnily, žádné hodiny nevzniknou. Jsou-li, patří do bloku
     * neplaceného volna. Vyloučená doba 10536 má doložený původ přímo v té
     * nepřítomnosti, takže se příčně proti hodinám nekontroluje.
     */
    private const HOURS_OPTIONAL_ABSENCE_TYPES = ['invalid_termination'];

    /** Vyloučená doba, jejímž jediným zdrojem je druh z {@see HOURS_OPTIONAL_ABSENCE_TYPES}. */
    private const HOURS_OPTIONAL_EXCLUDED_ATTRIBUTE = 'vyloucenePar16';

    /**
     * Typy odloženého příjmu (10548), které se hlásí bez důchodových nároků:
     * roční zúčtování po skončení (typ 4). Pravidla podání JMHZ 1.4.5,
     * kap. 6 bod 3 c): „10356 … VŽDY JE vykázána 0", „10240 Kód ELDP
     * V těchto případech SE KÓD NEUVÁDÍ"; 10476, 10477 a 10286 jsou vždy 0.
     */
    private const DEFERRED_TYPES_WITHOUT_ELDP = ['4'];

    /**
     * Typy s příjmem po skončení, jejichž ELDP za měsíc zúčtování nese kód
     * s „P", 0 dnů a vyměřovací základ zúčtovaného příjmu (kap. 6 bod 1):
     * typ 1 a typ 5. Typ 5 je typ 1 spolu s ročním zúčtováním po skončení
     * (číselník 10548, „znak 1 + znak 4"); roční zúčtování se do ELDP
     * nepromítá, nese ho souhrn zaměstnance.
     */
    private const DEFERRED_TYPES_WITH_INCOME_ELDP = ['1', '5'];

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $specManifest = null;

    private ?JmhzScenarioSelectorResolver $scenarioSelector = null;

    private bool $sourceCatalogsVerified = false;

    /** @var array<string,array{json:string,hash:string,decoded:array<string,mixed>}> */
    private array $canonicalSnapshots = [];

    /**
     * Odvodí potvrzení běžného řezu. Vyloučené doby se dopočítají ze zmrazených
     * absencí týmž modulem jako u ročního evidenčního listu, u kódu D bez
     * starobního důchodu i odečtené doby (dny pojištění pak krátí a zapínají
     * IN04, viz {@see monthDeductedDays()}). Výsledný
     * kandidát vždy projde stejnou úplnou validací jako ručně dodané potvrzení.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public function deriveOrdinaryConfirmation(
        int $supplierId,
        int $employmentId,
        array $source,
    ): array {
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'input',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'result',
        );
        [$employeeId, $entry] = $this->findEmployment($input, $employmentId);
        $source = self::withEmployeePensionStatus($source, $employeeId);
        $employment = $this->object($entry['employment'] ?? null, 'employment');
        $term = $this->object($entry['term'] ?? null, 'term');
        $deferredType = self::deferredIncomeType($entry, $employment, $periodStart);
        if (in_array($deferredType, self::DEFERRED_TYPES_WITHOUT_ELDP, true)) {
            return self::deferredAnnualConfirmation();
        }
        if ($deferredType !== null) {
            $this->assertDeferredIncomeRelation((string) ($employment['relation_type'] ?? ''));

            return $this->deferredConfirmation(
                $result,
                $employeeId,
                $employmentId,
                $term,
                $periodStart,
                $periodEnd,
            );
        }
        $employmentFrom = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        if (!is_string($employmentFrom)) {
            $this->invalid('jmhz_eldp_interval_outside_employment', 'Pracovní vztah nemá zmrazené datum nástupu.');
        }
        $employmentTo = $employment['end_date'] ?? null;
        $insuranceFrom = max($periodStart, $employmentFrom);
        $insuranceTo = is_string($employmentTo) ? min($periodEnd, $employmentTo) : $periodEnd;
        if ($insuranceFrom > $insuranceTo) {
            $this->invalid('jmhz_eldp_interval_invalid', 'Pracovní vztah nemá ve vykazovaném měsíci platný interval ELDP.');
        }
        $activityCode = $term['activity_code'] ?? null;
        if (!is_string($activityCode)) {
            $this->invalid('jmhz_eldp_ordinary_activity_unsupported', 'Pracovní vztah nemá zmrazený druh činnosti pro ELDP.');
        }
        $relationType = $employment['relation_type'] ?? null;
        if (!is_string($relationType)) {
            $this->invalid('jmhz_eldp_relationship_kind_unsupported', 'Pracovní vztah nemá podporovaný druh.');
        }
        $this->assertRelationActivityFamily(
            $relationType,
            $activityCode,
            $term['jmhz_relationship_detail_code'] ?? null,
        );
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        $participates = $this->participationMode($relationType, $relationship, $employmentId);
        $eldpReported = $participates && !self::pensionerWithoutEldp($input, $employeeId, $source, $periodStart);
        $assessmentBaseMinor = $this->nonNegativeInt(
            $relationship['assessment_base_minor_units'] ?? null,
            'assessment_base_minor_units',
        );
        $insuranceDays = (new \DateTimeImmutable($insuranceFrom))
            ->diff(new \DateTimeImmutable($insuranceTo))->days + 1;
        $absences = $entry['absences'] ?? null;
        $outsideInsurance = is_array($absences) && array_is_list($absences)
            && $this->monthOutsideInsurancePeriod(
                $absences,
                $eldpReported,
                $assessmentBaseMinor,
                $insuranceFrom,
                $insuranceTo,
            );
        $code = $eldpReported
            ? $this->eldpCode($activityCode, $source, $insuranceFrom, $insuranceTo)
            : null;
        // Odečtené doby (kód D bez starobního důchodu) odvozuje tentýž kód
        // jako build(), který potvrzení ověří; účetní je nepotvrzuje.
        $deducted = null;
        if ($eldpReported && is_array($absences) && array_is_list($absences)
            && $this->pensionAgeWithoutPension($source, $insuranceFrom, $insuranceTo)
        ) {
            $deducted = $this->monthDeductedDays(
                $absences,
                $insuranceFrom,
                $insuranceTo,
                $this->excludedPeriods(
                    $absences,
                    $insuranceFrom,
                    $insuranceTo,
                    $outsideInsurance ? 0 : $insuranceDays,
                    $assessmentBaseMinor,
                    EldpExcludedPeriodDeriver::concurrentIncomeDays(
                        $input,
                        $result,
                        $employeeId,
                        $employmentId,
                        $insuranceFrom,
                        $insuranceTo,
                    ),
                ),
                $outsideInsurance,
            );
        }
        $deductedTotal = $deducted['total'] ?? 0;
        $confirmation = [
            'insurance_from' => $insuranceFrom,
            'insurance_to' => $insuranceTo,
            'valid_from' => $eldpReported ? $insuranceFrom : null,
            'valid_to' => $eldpReported ? $insuranceTo : null,
            'insurance_days' => $eldpReported && !$outsideInsurance ? $insuranceDays - $deductedTotal : 0,
            'code' => $code,
            'assessment_base_czk' => $eldpReported ? intdiv($assessmentBaseMinor, 100) : null,
            'in03_active' => false,
            'in04_active' => $deductedTotal > 0,
            'confirmation_note' => '',
        ];

        $this->build($supplierId, $employmentId, $source, $confirmation);
        return $confirmation;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $confirmation
     */
    public function build(
        int $supplierId,
        int $employmentId,
        array $source,
        array $confirmation,
    ): JmhzEldpEvidenceSnapshot {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma a pracovní vztah musí být kladná čísla.');
        }
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || !in_array($revision['revision_kind'] ?? null, ['regular', 'correction'], true)
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid('jmhz_eldp_revision_not_current_approved', 'ELDP vyžaduje aktuální schválenou řádnou nebo opravnou revizi.');
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_eldp_period_invalid', 'Období ELDP nezačíná prvním dnem měsíce.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'input',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'result',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
            || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null) !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid('jmhz_eldp_source_mismatch', 'ELDP zdroj neodpovídá firmě, období nebo výsledku revize.');
        }

        [$employeeId, $entry] = $this->findEmployment($input, $employmentId);
        $source = self::withEmployeePensionStatus($source, $employeeId);
        $employment = $this->object($entry['employment'] ?? null, 'employment');
        $term = $this->object($entry['term'] ?? null, 'term');
        $relationType = $employment['relation_type'] ?? null;
        $activityCode = $term['activity_code'] ?? null;
        if (!is_string($relationType) || !is_string($activityCode)) {
            $this->invalid('jmhz_eldp_relationship_kind_unsupported', 'Pracovní vztah nemá podporovaný druh a činnost.');
        }
        $relationshipDetailCode = $term['jmhz_relationship_detail_code'] ?? null;
        $this->assertRelationActivityFamily($relationType, $activityCode, $relationshipDetailCode);
        $selectorRelationshipDetailCode = is_string($relationshipDetailCode) ? $relationshipDetailCode : null;
        $deferredType = self::deferredIncomeType($entry, $employment, $periodStart);
        if ($deferredType !== null) {
            return $this->buildDeferred(
                $supplierId,
                $runId,
                $revisionId,
                $revision,
                $employeeId,
                $employmentId,
                $periodStart,
                $periodEnd,
                $result,
                $term,
                $relationType,
                $activityCode,
                $selectorRelationshipDetailCode,
                $deferredType,
                $confirmation,
            );
        }
        $selection = ($this->scenarioSelector ??= JmhzScenarioSelectorResolver::load())
            ->resolve($activityCode, $selectorRelationshipDetailCode);
        if (!$selection['supported']) {
            $this->invalid('jmhz_eldp_scenario_unsupported', 'Pracovní vztah nepatří do podporovaného scénáře.');
        }
        $scenarioResolution = $selection['evidence'] ?? null;
        $scenarioKey = is_array($scenarioResolution) ? ($scenarioResolution['scenario_key'] ?? null) : null;
        if (!is_string($scenarioKey)) {
            throw new \UnexpectedValueException('Resolver scénáře JMHZ nevrátil klíč scénáře.');
        }
        $absences = $entry['absences'] ?? null;
        if (!is_array($absences) || !array_is_list($absences)) {
            $this->invalid('jmhz_eldp_source_invalid', 'Absence ELDP musí být seznam.');
        }
        $workSummary = is_array($entry['time_month'] ?? null)
            ? ($entry['time_month']['jmhz_work_summary'] ?? null)
            : null;
        if (!is_array($workSummary)
            || !in_array(
                $workSummary['derivation_version'] ?? null,
                PayrollJmhzWorkMonthSummaryBuilder::CONDITIONAL_VERSIONS,
                true,
            )
            || !is_int($workSummary['id'] ?? null)
            || $workSummary['id'] <= 0
            || !is_string($workSummary['summary_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $workSummary['summary_sha256']) !== 1
        ) {
            $this->invalid('jmhz_eldp_work_summary_missing', 'ELDP vyžaduje zmrazený pracovní souhrn JMHZ v2 nebo v3.');
        }
        $summaryVersion = (string) $workSummary['derivation_version'];
        $this->assertOrdinaryAbsenceTypes($absences, $summaryVersion);
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        $participates = $this->participationMode($relationType, $relationship, $employmentId);
        $uncappedBase = $this->nonNegativeInt($relationship['assessment_base_minor_units'] ?? null, 'assessment_base_minor_units');
        $cappedBase = $this->nonNegativeInt($relationship['capped_assessment_base_minor_units'] ?? null, 'capped_assessment_base_minor_units');
        if ($uncappedBase % 100 !== 0 || intdiv($uncappedBase, 100) > 9_999_999_999) {
            $this->invalid('jmhz_eldp_assessment_base_not_whole_czk', 'Vyměřovací základ ELDP musí být celé Kč v rozsahu XSD.');
        }
        /*
         * Neúčastný vztah se poměřuje ZASTROPOVANÝM základem, ne surovým.
         *
         * Why: u dohody pod hranicí účasti kalkulátor legitimně vyplní
         * `assessment_base` zúčtovaným příjmem (8 000 Kč u DPP) a vynuluje jen
         * `capped_assessment_base` — surový základ nese informaci „tolik se
         * posuzovalo", zastropovaný „tolik vstoupilo do pojištění". Kontrola
         * na obojí proto podlimitní dohodu odmítla a shodila ELDP, potažmo
         * celé měsíční hlášení: jedna DPP za 8 000 Kč znamenala, že se JMHZ
         * nesestaví NIKOMU ve firmě.
         *
         * U účastného vztahu se obě čísla rovnají (ověřeno na HPP i DPČ), takže
         * se zúžením kontroly nic neztrácí.
         */
        if (!$participates && $cappedBase !== 0) {
            $this->invalid('jmhz_eldp_social_relationship_unsupported', 'Neúčastný vztah nesmí mít vyměřovací základ sociálního pojištění.');
        }

        /*
         * Poživatel starobního důchodu (ověřená sleva pracujícího důchodce,
         * § 7d ZPSZ, tedy důchod po celý měsíc): údaje třídy ELDP se za něj
         * nehlásí. Metodika MPSV/ČSSZ (7. setkání HRIS a veřejná diskuze
         * k JMHZ): „na měsíčním hlášení se vyplňuje jen interval Pojištěn od
         * (10354) – Pojištěn do (10355) a počet kalendářních dnů trvání
         * pojištění (10356), který se vykáže jako 0". Nemocensky pojištěný
         * zůstává, takže vyloučené dny § 18 odst. 7 se vykazují dál. Přijatá
         * hlášení dvou jiných mzdových systémů to tak mají. Vyměřovací základ
         * a pojistné (10477, 10370) nejsou třída ELDP a zůstávají. Měsíc,
         * od kterého výplata důchodu náleží, ještě třídu ELDP nese
         * ({@see pensionerWithoutEldp()}).
         */
        $eldpReported = $participates && !self::pensionerWithoutEldp($input, $employeeId, $source, $periodStart);
        $insuranceFrom = $this->date($confirmation['insurance_from'] ?? null, 'insurance_from');
        $insuranceTo = $this->date($confirmation['insurance_to'] ?? null, 'insurance_to');
        $validFrom = $eldpReported ? $this->date($confirmation['valid_from'] ?? null, 'valid_from') : null;
        $validTo = $eldpReported ? $this->date($confirmation['valid_to'] ?? null, 'valid_to') : null;
        $employmentFrom = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        $employmentTo = $employment['end_date'] ?? null;
        if (!is_string($employmentFrom)) {
            $this->invalid('jmhz_eldp_interval_outside_employment', 'Pracovní vztah nemá zmrazené datum nástupu.');
        }
        $expectedFrom = max($periodStart, $employmentFrom);
        $expectedTo = is_string($employmentTo) ? min($periodEnd, $employmentTo) : $periodEnd;
        if ($expectedFrom > $expectedTo
            || $insuranceFrom !== $expectedFrom
            || $insuranceTo !== $expectedTo
            || ($eldpReported && ($validFrom !== $insuranceFrom || $validTo !== $insuranceTo))
        ) {
            $this->invalid('jmhz_eldp_interval_invalid', 'Interval ELDP musí přesně odpovídat průniku pracovního vztahu s vykazovaným měsícem.');
        }
        // Až nad ověřeným intervalem: u PPM rozhoduje, které dny měsíce leží
        // před porodem, a to se dá říct jen o intervalu, který odpovídá vztahu.
        $outsideInsurance = $this->monthOutsideInsurancePeriod(
            $absences,
            $eldpReported,
            $uncappedBase,
            $insuranceFrom,
            $insuranceTo,
        );
        $insured = $eldpReported && !$outsideInsurance;
        /*
         * Celý měsíc po dovršení důchodového věku bez starobního důchodu (kód
         * D, IN04): dny pojištění jsou interval minus odečtené doby, takže je
         * nejde poměřit s intervalem dřív, než se odečtené doby odvodí.
         */
        $deductionApplies = $eldpReported
            && $this->pensionAgeWithoutPension($source, $insuranceFrom, $insuranceTo);
        $days = $insured && !$deductionApplies
            ? $this->positiveInt($confirmation['insurance_days'] ?? null, 'insurance_days')
            : $this->nonNegativeInt($confirmation['insurance_days'] ?? null, 'insurance_days');
        $inclusiveDays = (new \DateTimeImmutable($insuranceFrom))
            ->diff(new \DateTimeImmutable($insuranceTo))->days + 1;
        if (!$deductionApplies
            && (($insured && $days !== $inclusiveDays) || (!$insured && $days !== 0))
        ) {
            $this->invalid('jmhz_eldp_days_mismatch', 'Počet dnů ELDP neodpovídá inkluzivnímu intervalu.');
        }
        // Sekce bez třídy ELDP vyloučené doby nevykazuje; odvozují se jen
        // kvůli kontrole nepřítomností proti pracovnímu souhrnu, takže se
        // poměřují s intervalem vztahu, ne s nulou dnů pojištění. U kódu D
        // leží vyloučené doby v odečtených a dny pojištění přesáhnout smějí
        // (logický test ELDP12 č. 37 se na kód D nevztahuje).
        $excluded = $this->excludedPeriods(
            $absences,
            $insuranceFrom,
            $insuranceTo,
            match (true) {
                !$eldpReported => $inclusiveDays,
                $deductionApplies => $insured ? $inclusiveDays : 0,
                default => $days,
            },
            $uncappedBase,
            EldpExcludedPeriodDeriver::concurrentIncomeDays(
                $input,
                $result,
                $employeeId,
                $employmentId,
                $insuranceFrom,
                $insuranceTo,
            ),
        );
        $deducted = null;
        if ($deductionApplies) {
            $deducted = $this->monthDeductedDays($absences, $insuranceFrom, $insuranceTo, $excluded, $outsideInsurance);
            if ($days !== $inclusiveDays - $deducted['total']) {
                $this->invalid(
                    'jmhz_eldp_days_mismatch',
                    'Počet dnů ELDP neodpovídá intervalu zmenšenému o odečtené doby (kontrola 309).',
                );
            }
        }
        // Měsíc mimo dobu pojištění vztah a jeho nemocenské pojištění neruší,
        // takže vyloučené dny § 18 odst. 7 se v něm vykazují dál — přijatá
        // hlášení mají u celého měsíce neplaceného volna 31 dnů při nule dnů
        // pojištění. U neúčastného vztahu naopak není co vylučovat.
        $section18 = $this->section18Periods(
            $absences,
            $insuranceFrom,
            $insuranceTo,
            $participates ? $inclusiveDays : 0,
            $eldpReported ? $excluded['total'] : 0,
        );
        $this->assertWorkSummaryConsistency(
            $workSummary,
            $days,
            $relationType,
            $absences,
            $excluded,
            // Evidenční stav (10265) je trvání vztahu, odečtené doby ho nekrátí.
            $insured && !$deductionApplies ? $days : $inclusiveDays,
            $summaryVersion,
            $insuranceFrom,
            $insuranceTo,
        );
        $code = $confirmation['code'] ?? null;
        $confirmedBase = $confirmation['assessment_base_czk'] ?? null;
        $entryMetadata = null;
        $splitFrom = null;
        if ($eldpReported) {
            if (!is_string($code)
                || $code !== $this->eldpCode($activityCode, $source, $insuranceFrom, $insuranceTo)
            ) {
                $this->invalid('jmhz_eldp_code_activity_mismatch', 'Kód ELDP neodpovídá činnosti pracovního vztahu.');
            }
            $entryMetadata = $this->codebook()->requireValue('kod_eldp', $code);
            $splitFrom = $this->pensionAgeSplitFrom($source, $insuranceFrom, $insuranceTo);
            if ($splitFrom !== null && !$insured) {
                $this->invalid(
                    'eldp_pension_age_mid_month_unsupported',
                    "Kód ELDP se mění uprostřed měsíce (od {$splitFrom}) v měsíci mimo dobu pojištění;"
                    . ' takový měsíc aplikace na dvě sekce nerozdělí.',
                );
            }
            // Nulový základ u účastného vztahu je legitimní: měsíc mimo dobu
            // pojištění, nebo měsíc celý v omluvné nepřítomnosti (nemoc,
            // ošetřovné), který dobou pojištění zůstává. Který z nich nastal,
            // rozhodl `monthOutsideInsurancePeriod()`; nula bez vysvětlující
            // nepřítomnosti tam už zastavila.
            $confirmedBase = $uncappedBase === 0
                ? $this->nonNegativeInt($confirmedBase, 'assessment_base_czk')
                : $this->positiveInt($confirmedBase, 'assessment_base_czk');
            if ($confirmedBase * 100 !== $uncappedBase) {
                $this->invalid('jmhz_eldp_assessment_base_mismatch', 'Potvrzený základ ELDP neodpovídá zákonnému výsledku.');
            }
            if ($deducted !== null) {
                // Kontrola 59 část 4: kód D bez započtených dnů, jehož odečtené
                // doby jsou celé vyloučenou dobou, nese základ 0.
                if ($days === 0 && $deducted['total'] === $excluded['total'] && $uncappedBase > 0) {
                    $this->invalid(
                        'jmhz_eldp_base_with_fully_excluded_section',
                        'Měsíc s kódem D nemá započtené dny a jeho odečtené doby jsou celé vyloučenou '
                            . 'dobou, ale nese vyměřovací základ; ČSSZ takové hlášení odmítne (kontrola 59).',
                    );
                }
            } elseif ($splitFrom !== null
                && ($code[strlen($code) - 2] ?? '') === 'D'
                && $this->pensionAgeWithoutPension($source, $splitFrom, $insuranceTo)
            ) {
                $this->assertSplitMonthWithoutDeductedDays($absences, $splitFrom, $insuranceTo, $code);
            } elseif ($days > 0 && $days === $excluded['total'] && $uncappedBase > 0) {
                // Kontrola 59 část 2: celý měsíc vyloučená doba a základ. Krytí
                // příjmem takový měsíc nedovolí; kdyby vznikl, nesmí odejít.
                $this->invalid(
                    'jmhz_eldp_base_with_fully_excluded_section',
                    'Všechny dny pojištění měsíce jsou vyloučenou dobou, ale měsíc nese vyměřovací základ; '
                        . 'ČSSZ takové hlášení odmítne (kontrola 59).',
                );
            }
        } elseif ($code !== null
            || ($confirmation['valid_from'] ?? null) !== null
            || ($confirmation['valid_to'] ?? null) !== null
            || $confirmedBase !== null
        ) {
            $this->invalid(
                'jmhz_eldp_nonparticipation_section_invalid',
                'Neúčastný vztah i poživatel starobního důchodu mají bezkódovou ELDP sekci s nulou dnů a bez základu.',
            );
        }
        // IN04 se neodvozuje od účetní: je aktivní právě tehdy, když měsíc
        // nese odečtené doby (Pokyny MH k 10375, „nadřazený řídicí atribut").
        $reportedDeducted = $deducted !== null && $deducted['total'] > 0 ? $deducted : null;
        $in04Active = $reportedDeducted !== null;
        if (($confirmation['in03_active'] ?? null) !== false
            || ($confirmation['in04_active'] ?? null) !== $in04Active
        ) {
            $this->invalid(
                'jmhz_eldp_interaction_unsupported',
                'ELDP řez vyžaduje explicitní Ne pro IN03 a pro IN04 hodnotu odvozenou z odečtených dob.',
            );
        }
        $note = $confirmation['confirmation_note'] ?? '';
        if (!is_string($note) || mb_strlen(trim($note), 'UTF-8') > 500) {
            $this->invalid('jmhz_eldp_confirmation_note_invalid', 'Volitelná poznámka ELDP smí mít nejvýše 500 znaků.');
        }

        $spec = $this->specManifest();
        $codebook = $this->findCodebook($spec['payload'], 'kod_eldp');
        $payload = [
            'schema_reference' => JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'scenario_key' => $scenarioKey,
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'eldp_codebook_content_sha256' => $codebook['content_hash'],
                'eldp_code_row_sha256' => $entryMetadata['row_hash'] ?? null,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'source_evidence' => [
                'term_id' => $term['id'] ?? null,
                'term_row_version' => $term['row_version'] ?? null,
                'work_summary_id' => $workSummary['id'],
                'work_summary_sha256' => $workSummary['summary_sha256'],
                'social_relationship' => $relationship,
                'scenario_resolution' => $scenarioResolution,
                'attribute_ids' => self::ATTRIBUTE_IDS,
                // Účastný vztah bez třídy ELDP: poživatel starobního důchodu.
                'working_pensioner' => $participates && !$eldpReported,
                // Klíč jen tam, kde kód nese D: otisk ostatních snapshotů zůstává stejný.
                ...($eldpReported && is_string($code) && ($code[strlen($code) - 2] ?? '') === 'D'
                    ? ['pension_age_code_from' => $this->pensionAgeCodeFrom($source)]
                    : []),
                // Důchodové údaje ze zákonné evidence, ze kterých řez vyšel.
                // Jen u osoby, která nějaké má: otisk ostatních se nemění.
                ...(is_array($source['pension_status'] ?? null)
                    ? ['pension_status' => $source['pension_status']]
                    : []),
            ],
            'insurance_interval' => [
                'insurance_from' => $insuranceFrom,
                'insurance_to' => $insuranceTo,
            ],
            'eldp_sections' => $splitFrom !== null && is_string($code) && is_int($confirmedBase)
                ? $this->pensionAgeSections(
                    $absences,
                    $activityCode,
                    $code,
                    $insuranceFrom,
                    $insuranceTo,
                    $splitFrom,
                    $confirmedBase,
                    $excluded,
                    $participates,
                )
                : [[
                'ordinal' => 1,
                'code' => $code,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'insurance_days' => $days,
                'assessment_base_czk' => $confirmedBase,
                // Vyloučené doby § 16 jsou třída ELDP; bez kódu je nesmí nést
                // žádná sekce (kontrola 307), `null` = NEUVEDENO.
                'excluded_days' => $eldpReported ? $excluded['components'] : null,
                'excluded_days_total' => $eldpReported ? $excluded['total'] : null,
                'excluded_days_provenance' => $eldpReported ? $excluded['provenance'] : [],
                /*
                 * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb.
                 * (10366 a rozpad 10473–10475). Jiná veličina než vyloučené
                 * DOBY výš: ty krátí osobní vyměřovací základ důchodu, tyhle
                 * rozhodné období denního vyměřovacího základu nemocenských
                 * dávek. `null` znamená NEUVEDENO — viz
                 * {@see section18Periods()}.
                 */
                'section18_days' => $section18['components'] ?? null,
                'section18_days_total' => $section18['total'] ?? null,
                'section18_days_provenance' => $section18['provenance'] ?? [],
                /*
                 * Odečítané doby (10375, 10462–10469) se týkají VÝHRADNĚ dob
                 * po dosažení důchodového věku bez starobního důchodu (kód D).
                 * `null` znamená NEUVEDENO: sekce bez odečtených dob element
                 * `odecitaneDny` nenese (Pravidla podání kap. 4: při nule se
                 * atributy nezobrazují). Rozpad `deducted_days` (jen nenulové
                 * složky v pořadí `odecitaneDnyType`) je podmíněný klíč, který
                 * vznikne jen u sekce s odečtenými dobami, takže otisk všech
                 * ostatních řezů zůstává stejný.
                 */
                'deducted_days_total' => $reportedDeducted['total'] ?? null,
                ...($reportedDeducted !== null
                    ? ['deducted_days' => array_filter(
                        $reportedDeducted['components'],
                        static fn (int $value): bool => $value > 0,
                    )]
                    : []),
            ]],
            'confirmation' => [
                'in03_active' => false,
                'in04_active' => $in04Active,
                'note' => trim($note),
            ],
        ];
        return new JmhzEldpEvidenceSnapshot($payload);
    }

    /**
     * Kód ELDP měsíčního hlášení: druh činnosti, „+" a „+", a druhý znak „D"
     * od dne dovršení důchodového věku (předčasný starobní důchod kód D
     * nezakládá). Pravidlo je společné s ročním evidenčním listem
     * ({@see EldpPensionAgeCode}), takže se osoba v listu a v hlášení nemůže
     * rozejít.
     *
     * Důchodové údaje čte z `$source['pension_status']`
     * (`pension_age_reached_on`); bez nich zůstává „++". Zdroj řezu
     * ({@see \MyInvoice\Repository\Payroll\JmhzEldpEvidenceSnapshotRepository::lockSource()})
     * je plní ze zákonné evidence osoby (sekce Důchod) k vykazovanému měsíci
     * týmž pravidlem jako roční evidenční list
     * ({@see \MyInvoice\Service\Payroll\Pension\PayrollPensionStatus}); už
     * zmrazené řezy se tím nemění. Dovršení věku uprostřed měsíce dělí měsíc
     * na dvě sekce ({@see self::pensionAgeSplitFrom()}).
     *
     * @param array<string,mixed> $source
     */
    private function eldpCode(
        string $activityCode,
        array $source,
        string $insuranceFrom,
        string $insuranceTo,
    ): string {
        $code = $activityCode . '++';
        $codeFrom = $this->pensionAgeCodeFrom($source);
        $placement = EldpPensionAgeCode::placement($codeFrom, $insuranceFrom, $insuranceTo);

        // Měsíc, ve kterém kód D začíná dovršením důchodového věku, potvrzuje
        // účetní kódem D; sekci před tím dnem odvodí řez sám.
        return $placement === EldpPensionAgeCode::PLAIN
            ? $code
            : EldpPensionAgeCode::withPensionAge($code);
    }

    /**
     * Den dovršení důchodového věku uvnitř intervalu pojištění, od kterého se
     * měsíc dělí na dvě sekce ELDP; jinak `null`.
     *
     * Pravidla podání JMHZ 1.4.5, kap. 4: změní-li se kód ELDP v měsíci, má
     * každý kód samostatný záznam s vlastními dny a vyměřovacím základem.
     * Kontrola 59 (3. část) určuje rozdělení základu: navazuje-li na sekci
     * před dovršením věku sekce s kódem D téže činnosti a se započtenými dny,
     * má sekce před dovršením základ 0 a celý základ měsíce nese sekce D.
     * Kód D začíná jen dovršením věku ({@see EldpPensionAgeCode::codeFrom()}),
     * takže se tak dělí i měsíc poživatele předčasného starobního důchodu.
     *
     * @param array<string,mixed> $source
     */
    private function pensionAgeSplitFrom(array $source, string $intervalFrom, string $intervalTo): ?string
    {
        $codeFrom = $this->pensionAgeCodeFrom($source);

        return EldpPensionAgeCode::placement($codeFrom, $intervalFrom, $intervalTo)
            === EldpPensionAgeCode::MID_INTERVAL
            ? $codeFrom
            : null;
    }

    /**
     * Dvě sekce ELDP měsíce, ve kterém zaměstnanec dovršil důchodový věk:
     * do dne před dovršením kód bez D a základ 0, od dovršení kód D a celý
     * základ měsíce (kontrola 59, 3. část). Vyloučené doby i vyloučené dny
     * § 18 odst. 7 se odvozují pro každou sekci zvlášť týmž modulem jako
     * u celého měsíce; jejich součet musí dát úhrn měsíce.
     *
     * @param list<array<string,mixed>> $absences
     * @param array{components:array<string,int>,total:int,provenance:list<array<string,mixed>>} $excluded
     * @return list<array<string,mixed>>
     */
    private function pensionAgeSections(
        array $absences,
        string $activityCode,
        string $code,
        string $insuranceFrom,
        string $insuranceTo,
        string $splitFrom,
        int $assessmentBaseCzk,
        array $excluded,
        bool $participates,
    ): array {
        $beforeTo = (new \DateTimeImmutable($splitFrom))->modify('-1 day')->format('Y-m-d');
        $parts = [
            [$activityCode . '++', $insuranceFrom, $beforeTo, 0],
            [$code, $splitFrom, $insuranceTo, $assessmentBaseCzk],
        ];
        $sections = [];
        $excludedTotal = 0;
        foreach ($parts as $index => [$partCode, $partFrom, $partTo, $partBase]) {
            $this->codebook()->requireValue('kod_eldp', $partCode);
            $partDays = EldpExcludedPeriodDeriver::inclusiveDays($partFrom, $partTo);
            $partExcluded = (new EldpExcludedPeriodDeriver())->derive(
                $absences,
                $partFrom,
                $partTo,
                substr($partFrom, 0, 7),
            );
            if ($partExcluded['blockers'] !== []
                || array_sum($partExcluded['components']) !== $partExcluded['total']
                || $partExcluded['total'] > $partDays
            ) {
                $this->invalid(
                    'jmhz_eldp_absences_unsupported',
                    'Nepřítomnost nelze bezpečně rozdělit na vyloučené doby sekcí před dovršením'
                        . ' důchodového věku a po něm.',
                );
            }
            $excludedTotal += $partExcluded['total'];
            $partSection18 = $this->section18Periods(
                $absences,
                $partFrom,
                $partTo,
                $participates ? $partDays : 0,
                $partExcluded['total'],
            );
            $sections[] = [
                'ordinal' => $index + 1,
                'code' => $partCode,
                'valid_from' => $partFrom,
                'valid_to' => $partTo,
                'insurance_days' => $partDays,
                'assessment_base_czk' => $partBase,
                'excluded_days' => $partExcluded['components'],
                'excluded_days_total' => $partExcluded['total'],
                'excluded_days_provenance' => $partExcluded['provenance'],
                'section18_days' => $partSection18['components'] ?? null,
                'section18_days_total' => $partSection18['total'] ?? null,
                'section18_days_provenance' => $partSection18['provenance'] ?? [],
                'deducted_days_total' => null,
            ];
        }
        if ($excludedTotal !== $excluded['total']) {
            $this->invalid(
                'jmhz_eldp_excluded_days_sum_mismatch',
                'Vyloučené doby sekcí před dovršením důchodového věku a po něm nedávají úhrn měsíce.',
            );
        }

        return $sections;
    }

    /**
     * Důchodové údaje té osoby, jejíž vztah se řeže.
     *
     * Zdroj z repozitáře je společný celé revizi (příprava ho zamyká jednou za
     * všechny vztahy), a proto nese údaje po osobách v `pension_statuses`.
     * Osoba bez záznamu má `pension_status` = `null`, tedy „nic se neví".
     * Zdroj bez mapy (sestavení mimo repozitář) zůstává, jak přišel.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function withEmployeePensionStatus(array $source, int $employeeId): array
    {
        if (!array_key_exists('pension_statuses', $source)) {
            return $source;
        }
        $statuses = is_array($source['pension_statuses']) ? $source['pension_statuses'] : [];
        $source['pension_status'] = $statuses[$employeeId] ?? null;

        return $source;
    }

    /**
     * Den, od kterého nese činnost kód D, z potvrzených důchodových údajů zdroje.
     *
     * @param array<string,mixed> $source
     */
    private function pensionAgeCodeFrom(array $source): ?string
    {
        $status = $source['pension_status'] ?? null;
        if ($status === null) {
            return null;
        }
        $status = $this->object($status, 'pension_status');
        $dates = [];
        foreach (['pension_age_reached_on', 'early_pension_from'] as $key) {
            $dates[$key] = ($status[$key] ?? null) === null
                ? null
                : $this->date($status[$key], "pension_status.{$key}");
        }

        return EldpPensionAgeCode::codeFrom($dates);
    }

    /**
     * Dovršila osoba důchodový věk před intervalem a nepobírá v něm starobní
     * důchod? Jen pro ni se vedou odečítané doby (datový slovník JMHZ 1.4.1.6
     * u 10375: „pojištěnec, který … nepobírá starobní důchod a je výdělečně
     * činný po dovršení důchodového věku"). Pravidlo sdílí s ročním listem
     * {@see EldpPensionAgeCode::deductsPeriods()}; tady se jen ověří tvar dat.
     *
     * @param array<string,mixed> $source
     */
    private function pensionAgeWithoutPension(array $source, string $intervalFrom, string $intervalTo): bool
    {
        $status = $source['pension_status'] ?? null;
        if ($status === null) {
            return false;
        }
        $status = $this->object($status, 'pension_status');
        $pension = [];
        foreach (['pension_age_reached_on', 'early_pension_from'] as $key) {
            $pension[$key] = ($status[$key] ?? null) === null
                ? null
                : $this->date($status[$key], "pension_status.{$key}");
        }
        $fullPaidFrom = $status['full_pension_paid_from'] ?? null;
        $pension['full_pension_paid_from'] = is_string($fullPaidFrom) ? $fullPaidFrom : null;

        return EldpPensionAgeCode::deductsPeriods($pension, $intervalFrom, $intervalTo);
    }

    /**
     * Odečtené doby měsíce, který celý leží po dovršení důchodového věku
     * a ve kterém osoba nepobírá starobní důchod (kód D, interakce IN04).
     *
     * Počítá je TÝŽ modul jako roční evidenční list
     * ({@see EldpExcludedPeriodDeriver::deriveDeducted()}) nad týmž intervalem
     * a týmiž vyloučenými dobami, takže se měsíční hlášení a list nerozejdou.
     * Dny pojištění jsou interval minus odečtené doby (Pokyny MH k 10356
     * a 10375, kontrola 309).
     *
     * Zastaví se případy, které hlášení podle pravidel zapsat nejde:
     * - doba § 16 odst. 4 písm. j) (10536): žádná složka 10462–10469 ji
     *   nenese, a vyloučené doby přitom musí ležet v odečtených (logické
     *   testy ELDP12 č. 39 a 48);
     * - odečtené dny bez složky (dny měsíce bez účasti „X", které nevysvětluje
     *   žádná nepřítomnost se složkou): Pravidla podání kap. 4 žádají součet
     *   složek rovný 10375 a Pokyny MH při 10375 > 0 aspoň jednu vyplněnou
     *   složku, jenže pro „X" složka není;
     * - vyloučené doby nad odečtenými (logický test ELDP12 č. 39).
     *
     * @param list<array<string,mixed>> $absences
     * @param array{components:array<string,int>,total:int,provenance:list<array<string,mixed>>} $excluded
     * @return array{components:array<string,int>,total:int}
     */
    private function monthDeductedDays(
        array $absences,
        string $intervalFrom,
        string $intervalTo,
        array $excluded,
        bool $outsideInsurance,
    ): array {
        if (($excluded['components'][self::HOURS_OPTIONAL_EXCLUDED_ATTRIBUTE] ?? 0) > 0) {
            $this->invalid(
                'jmhz_eldp_deducted_days_invalid_termination_unsupported',
                'Měsíc po dovršení důchodového věku obsahuje dobu po neplatném skončení vztahu '
                    . '(§ 16 odst. 4 písm. j) zákona č. 155/1995 Sb.). Odečítané doby pro ni složku '
                    . 'nemají a vyloučená doba musí ležet v odečtených (logické testy ELDP12 č. 39 a 48); '
                    . 'hlášení zaměstnance podejte mimo aplikaci.',
            );
        }
        $deducted = (new EldpExcludedPeriodDeriver())->deriveDeducted(
            $absences,
            $intervalFrom,
            $intervalTo,
            $excluded,
            $outsideInsurance,
        );
        $withComponent = array_sum($deducted['components']);
        if ($withComponent !== $deducted['total']) {
            $this->invalid(
                'jmhz_eldp_deducted_days_without_component_unsupported',
                "Měsíc po dovršení důchodového věku má {$deducted['total']} dnů odečtených dob, ale jen "
                    . "{$withComponent} z nich má složku 10462–10469 (zbytek je měsíc bez účasti na "
                    . 'pojištění). Pravidla podání žádají součet složek rovný 10375 a pro takové dny '
                    . 'složka není; hlášení zaměstnance podejte mimo aplikaci.',
            );
        }
        if ($excluded['total'] > $deducted['total']) {
            $this->invalid(
                'jmhz_eldp_excluded_days_exceed_period',
                'Vyloučené doby měsíce s kódem D přesahují odečtené doby (logický test ELDP12 č. 39).',
            );
        }

        return $deducted;
    }

    /**
     * Měsíc, uvnitř kterého zaměstnanec dovršil důchodový věk, se dělí na dvě
     * sekce ELDP. Odečtené doby sekce D v něm hlášení zatím nevykáže: kontrola
     * 309 poměřuje dny pojištění s intervalem celého formuláře (10354–10355),
     * ne s platností sekce, a katalog pro dvě sekce výpočet nestanoví. Měsíc
     * s nenulovými odečtenými dobami v sekci D proto zastaví; bez nich projde
     * jako dřív.
     *
     * @param list<array<string,mixed>> $absences
     */
    private function assertSplitMonthWithoutDeductedDays(
        array $absences,
        string $splitFrom,
        string $intervalTo,
        string $code,
    ): void {
        $deriver = new EldpExcludedPeriodDeriver();
        $partExcluded = $deriver->derive($absences, $splitFrom, $intervalTo, substr($splitFrom, 0, 7));
        $deducted = $deriver->deriveDeducted($absences, $splitFrom, $intervalTo, $partExcluded, false);
        if ($deducted['total'] > 0
            || ($partExcluded['components'][self::HOURS_OPTIONAL_EXCLUDED_ATTRIBUTE] ?? 0) > 0
        ) {
            $this->invalid(
                'jmhz_eldp_deducted_days_split_month_unsupported',
                "Sekce {$code} od dovršení důchodového věku ({$splitFrom}) má {$deducted['total']} dnů "
                    . 'odečtených dob. Pro měsíc rozdělený na dvě sekce katalog kontrol výpočet dnů '
                    . 'pojištění s odečtenými dobami nestanoví; hlášení zaměstnance podejte mimo aplikaci.',
            );
        }
    }

    /**
     * Je osoba ve vykazovaném měsíci poživatelem starobního důchodu?
     *
     * Rozhoduje ověřený nárok na slevu pracujícího důchodce ze zmrazené zákonné
     * evidence osoby: sleva podle § 7d ZPSZ náleží za měsíc, ve kterém je
     * zaměstnanec poživatelem starobního důchodu po celý měsíc, a to je přesně
     * podmínka, za které se třída ELDP nehlásí. Důchodce, který si slevu
     * neuplatnil, dostane ELDP dál; metodika to nepovažuje za chybu („Pokud by
     * zaměstnavatel … vyplnil kompletně údaje třídy ELDP i pro osobu, která
     * již je poživatelem starobního důchodu, nebude to považováno za chybu").
     * Opačná chyba — vynechat ELDP někomu, kdo důchod nepobírá — by byla
     * ztráta doby pojištění, proto se z ničeho jiného nevyvozuje.
     *
     * @param array<string,mixed> $input
     */
    public static function workingPensioner(array $input, int $employeeId): bool
    {
        foreach ((array) ($input['people'] ?? []) as $person) {
            if (!is_array($person) || (($person['employee'] ?? [])['id'] ?? null) !== $employeeId) {
                continue;
            }
            $evidence = $person['statutory_evidence'] ?? null;
            $discount = is_array($evidence)
                ? (($evidence['social'] ?? [])['working_pensioner_discount'] ?? null)
                : null;

            return is_array($discount) && ($discount['status'] ?? null) === 'verified';
        }

        return false;
    }

    /**
     * Hlásí se za osobu ve vykazovaném měsíci místo třídy ELDP jen interval
     * 10354 a 10355 s 10356 = 0?
     *
     * Ověřená sleva pracujícího důchodce ({@see workingPensioner()}) náleží
     * i za měsíc, od jehož prvního dne se důchod vyplácí. Ten ale ELDP ještě
     * nese: vyplňuje se „pouze do měsíce, od něhož náleží výplata starobního
     * důchodu v plné výši" včetně něj (diskuse JMHZ #363, Metodická pomůcka
     * ELDP, příklad 8a), stejně jako roční evidenční list
     * ({@see PayrollPensionStatus::eldpKeptInMonth()}). Bez starobního důchodu
     * v zákonné evidenci osoby rozhoduje sleva sama, jako dřív.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $source
     */
    private static function pensionerWithoutEldp(
        array $input,
        int $employeeId,
        array $source,
        string $periodStart,
    ): bool {
        if (!self::workingPensioner($input, $employeeId)) {
            return false;
        }
        $status = $source['pension_status'] ?? null;
        $fullPaidFrom = is_array($status) ? ($status['full_pension_paid_from'] ?? null) : null;

        return !is_string($fullPaidFrom)
            || !PayrollPensionStatus::eldpKeptInMonth($fullPaidFrom, $periodStart);
    }

    /**
     * Typ odloženého příjmu (JMHZ 10548), je-li vztah skončený před
     * vykazovaným měsícem a účetní odložený příjem potvrdila; jinak `null`.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $employment
     */
    private static function deferredIncomeType(array $entry, array $employment, string $periodStart): ?string
    {
        $deferred = $entry['deferred_income'] ?? null;
        $type = is_array($deferred) ? ($deferred['deferred_type'] ?? null) : null;
        $endDate = $employment['end_date'] ?? null;
        if (!is_string($type) || !is_string($endDate) || $endDate >= $periodStart) {
            return null;
        }

        return $type;
    }

    /**
     * Potvrzení ELDP pro odložený příjem typu 1 (pravidla podání JMHZ,
     * kap. 6 bod 1): za měsíc zúčtování 0 dnů pojištění, kód ELDP vztahu
     * s „P" na druhé pozici (dodatečné zúčtování příjmů po skončení výdělečné
     * činnosti, kontrola 338), platnost od–do přes měsíc zúčtování
     * a vyměřovací základ zúčtovaného příjmu.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $term
     * @return array<string,mixed>
     */
    private function deferredConfirmation(
        array $result,
        int $employeeId,
        int $employmentId,
        array $term,
        string $periodStart,
        string $periodEnd,
    ): array {
        $activityCode = $term['activity_code'] ?? null;
        if (!is_string($activityCode)) {
            $this->invalid('jmhz_eldp_ordinary_activity_unsupported', 'Pracovní vztah nemá zmrazený druh činnosti pro ELDP.');
        }
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        $base = $this->nonNegativeInt(
            $relationship['assessment_base_minor_units'] ?? null,
            'assessment_base_minor_units',
        );
        if ($base % 100 !== 0) {
            $this->invalid('jmhz_eldp_assessment_base_not_whole_czk', 'Vyměřovací základ ELDP musí být celé Kč v rozsahu XSD.');
        }

        return [
            'insurance_from' => null,
            'insurance_to' => null,
            'valid_from' => $periodStart,
            'valid_to' => $periodEnd,
            'insurance_days' => 0,
            'code' => $activityCode . 'P+',
            'assessment_base_czk' => intdiv($base, 100),
            'in03_active' => false,
            'in04_active' => false,
            'confirmation_note' => '',
        ];
    }

    /**
     * Potvrzení ELDP pro roční zúčtování po skončení (typ 4): sekce bez kódu,
     * bez platnosti a bez vyměřovacího základu, 0 dnů pojištění (Pravidla
     * podání JMHZ 1.4.5, kap. 6 bod 3 c). 10245 a 10241/10242 jsou podmíněné
     * kódem ELDP (interakce IN35), bez kódu se neuvádějí.
     *
     * @return array<string,mixed>
     */
    private static function deferredAnnualConfirmation(): array
    {
        return [
            'insurance_from' => null,
            'insurance_to' => null,
            'valid_from' => null,
            'valid_to' => null,
            'insurance_days' => 0,
            'code' => null,
            'assessment_base_czk' => null,
            'in03_active' => false,
            'in04_active' => false,
            'confirmation_note' => '',
        ];
    }

    /**
     * ELDP řez odloženého příjmu. Pracovní souhrn ani interval pojištění
     * nemá (vztah v měsíci netrvá), zbytek zmrazeného snímku je stejný jako
     * u běžného řezu, aby ho resolver hlášení četl jednou cestou.
     *
     * @param array<string,mixed> $revision
     * @param array<string,mixed> $result
     * @param array<string,mixed> $term
     * @param array<string,mixed> $confirmation
     */
    private function buildDeferred(
        int $supplierId,
        int $runId,
        int $revisionId,
        array $revision,
        int $employeeId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        array $result,
        array $term,
        string $relationType,
        string $activityCode,
        ?string $relationshipDetailCode,
        string $deferredType,
        array $confirmation,
    ): JmhzEldpEvidenceSnapshot {
        $withoutEldp = in_array($deferredType, self::DEFERRED_TYPES_WITHOUT_ELDP, true);
        // Typy 2, 3 a 6 zůstávají zastavené; důvody u
        // {@see \MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository::INVALID_TERMINATION_TYPES}.
        if (!$withoutEldp && !in_array($deferredType, self::DEFERRED_TYPES_WITH_INCOME_ELDP, true)) {
            $this->invalid(
                'jmhz_deferred_income_type_unsupported',
                'Aplikace zpracuje sama jen odložený příjem typu 1, 4 a 5. Náhradu mzdy při neplatném'
                    . ' skončení (typy 2, 3 a 6) podejte ručně přes ePortál ČSSZ.',
            );
        }
        if (in_array($deferredType, ['4', '5'], true)
            && !in_array(substr($periodStart, 5, 2), ['01', '02', '03'], true)
        ) {
            $this->invalid(
                'jmhz_deferred_annual_settlement_period_invalid',
                'Roční zúčtování po skončení se hlásí jen za leden, únor nebo březen.',
            );
        }
        if (!$withoutEldp) {
            $this->assertDeferredIncomeRelation($relationType);
        }
        $selection = ($this->scenarioSelector ??= JmhzScenarioSelectorResolver::load())
            ->resolve($activityCode, $relationshipDetailCode, 'scenario_8');
        if (!$selection['supported'] || !is_array($selection['evidence'] ?? null)) {
            $this->invalid('jmhz_eldp_scenario_unsupported', 'Pracovní vztah nepatří do podporovaného scénáře.');
        }
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        if ($withoutEldp) {
            return $this->buildDeferredAnnual(
                $supplierId,
                $runId,
                $revisionId,
                $revision,
                $employeeId,
                $employmentId,
                $periodStart,
                $term,
                $relationship,
                $selection['evidence'],
                $deferredType,
                $confirmation,
            );
        }
        if (!$this->participationMode($relationType, $relationship, $employmentId)) {
            $this->invalid(
                'jmhz_eldp_social_relationship_unsupported',
                'Odložený příjem vztahu, který nezakládá účast na pojištění, se automaticky nevykazuje.',
            );
        }
        $base = $this->nonNegativeInt($relationship['assessment_base_minor_units'] ?? null, 'assessment_base_minor_units');
        $code = $confirmation['code'] ?? null;
        if ($code !== $activityCode . 'P+'
            || ($confirmation['valid_from'] ?? null) !== $periodStart
            || ($confirmation['valid_to'] ?? null) !== $periodEnd
            || ($confirmation['insurance_days'] ?? null) !== 0
            || ($confirmation['assessment_base_czk'] ?? null) !== intdiv($base, 100)
            || $base % 100 !== 0
        ) {
            $this->invalid('jmhz_eldp_deferred_section_invalid', 'ELDP odloženého příjmu neodpovídá zmrazenému výsledku.');
        }
        $entryMetadata = $this->codebook()->requireValue('kod_eldp', $code);
        $spec = $this->specManifest();
        $codebook = $this->findCodebook($spec['payload'], 'kod_eldp');
        $year = (int) substr($periodStart, 0, 4);
        $month = (int) substr($periodStart, 5, 2);

        return new JmhzEldpEvidenceSnapshot([
            'schema_reference' => JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'scenario_key' => 'scenario_8',
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'eldp_codebook_content_sha256' => $codebook['content_hash'],
                'eldp_code_row_sha256' => $entryMetadata['row_hash'] ?? null,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'source_evidence' => [
                'term_id' => $term['id'] ?? null,
                'term_row_version' => $term['row_version'] ?? null,
                'work_summary_id' => null,
                'work_summary_sha256' => null,
                'social_relationship' => $relationship,
                'scenario_resolution' => $selection['evidence'],
                'attribute_ids' => self::ATTRIBUTE_IDS,
            ],
            'insurance_interval' => null,
            // Odložený příjem vykazuje ELDP po obdobích (10537/10538). Typ 1
            // nese jediné období, měsíc zúčtování.
            'deferred_income' => [
                'type' => $deferredType,
                'periods' => [['month' => $month, 'year' => $year]],
            ],
            'eldp_sections' => [[
                'ordinal' => 1,
                'code' => $code,
                'valid_from' => $periodStart,
                'valid_to' => $periodEnd,
                'insurance_days' => 0,
                'assessment_base_czk' => intdiv($base, 100),
                'excluded_days' => null,
                'excluded_days_total' => null,
                'excluded_days_provenance' => [],
                'section18_days' => null,
                'section18_days_total' => null,
                'section18_days_provenance' => [],
                'deducted_days_total' => null,
            ]],
            'confirmation' => [
                'in03_active' => false,
                'in04_active' => false,
                'note' => '',
            ],
        ]);
    }

    /**
     * ELDP řez ročního zúčtování po skončení (typ 4). Pravidla podání JMHZ
     * 1.4.5, kap. 6 bod 3: navazuje na skončený vztah, hlásí se za leden až
     * březen a bez zúčtovaného příjmu; důchodové nároky jsou jediná bezkódová
     * sekce s 10356 = 0 a období (10537/10538) je měsíc hlášení (vzorové
     * hlášení MPSV `TEST_odložený příjem_1.1.2026.xlsx`, list SLOVNÍK,
     * sloupce O a P). Příjem v měsíci by patřil do typu 5, proto ho řez
     * odmítne; o účasti na pojištění se tu nerozhoduje.
     *
     * @param array<string,mixed> $revision
     * @param array<string,mixed> $term
     * @param array<string,mixed> $relationship
     * @param array<string,mixed> $scenarioResolution
     * @param array<string,mixed> $confirmation
     */
    private function buildDeferredAnnual(
        int $supplierId,
        int $runId,
        int $revisionId,
        array $revision,
        int $employeeId,
        int $employmentId,
        string $periodStart,
        array $term,
        array $relationship,
        array $scenarioResolution,
        string $deferredType,
        array $confirmation,
    ): JmhzEldpEvidenceSnapshot {
        $month = (int) substr($periodStart, 5, 2);
        foreach (['assessment_base_minor_units', 'capped_assessment_base_minor_units'] as $key) {
            if ($this->nonNegativeInt($relationship[$key] ?? null, $key) !== 0) {
                $this->invalid(
                    'jmhz_deferred_annual_settlement_income_present',
                    'Roční zúčtování po skončení se hlásí bez zúčtovaného příjmu. Příjem zúčtovaný'
                        . ' v tomtéž měsíci potvrďte jako odložený příjem typu 5.',
                );
            }
        }
        if ($confirmation !== self::deferredAnnualConfirmation()) {
            $this->invalid('jmhz_eldp_deferred_section_invalid', 'ELDP odloženého příjmu neodpovídá zmrazenému výsledku.');
        }
        $spec = $this->specManifest();
        $codebook = $this->findCodebook($spec['payload'], 'kod_eldp');

        return new JmhzEldpEvidenceSnapshot([
            'schema_reference' => JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'scenario_key' => 'scenario_8',
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'eldp_codebook_content_sha256' => $codebook['content_hash'],
                'eldp_code_row_sha256' => null,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'source_evidence' => [
                'term_id' => $term['id'] ?? null,
                'term_row_version' => $term['row_version'] ?? null,
                'work_summary_id' => null,
                'work_summary_sha256' => null,
                'social_relationship' => $relationship,
                'scenario_resolution' => $scenarioResolution,
                'attribute_ids' => self::ATTRIBUTE_IDS,
            ],
            'insurance_interval' => null,
            'deferred_income' => [
                'type' => $deferredType,
                'periods' => [['month' => $month, 'year' => (int) substr($periodStart, 0, 4)]],
            ],
            'eldp_sections' => [[
                'ordinal' => 1,
                'code' => null,
                'valid_from' => null,
                'valid_to' => null,
                'insurance_days' => 0,
                'assessment_base_czk' => null,
                'excluded_days' => null,
                'excluded_days_total' => null,
                'excluded_days_provenance' => [],
                'section18_days' => null,
                'section18_days_total' => null,
                'section18_days_provenance' => [],
                'deducted_days_total' => null,
            ]],
            'confirmation' => [
                'in03_active' => false,
                'in04_active' => false,
                'note' => '',
            ],
        ]);
    }

    /**
     * Kód ELDP s druhým znakem P nesmí mít zaměstnání malého rozsahu
     * (kontrola 133 část 3, Malý rozsah = A) a DPP (kódy T–ZC) ho v číselníku
     * nemá. Příjem zúčtovaný po skončení se u nich považuje za příjem
     * posledního měsíce výkonu (§ 7 odst. 3 a § 7a odst. 2 zákona č. 187/2006
     * Sb.) a může zpětně založit účast; Pravidla podání JMHZ (kap. 6 bod 1c)
     * proto žádají opravu hlášení za poslední měsíc výkonu (10356, 10245), ne
     * formulář odloženého příjmu. Kontrolu 133 z XML vyhodnotit nejde (10243
     * nemá mapování na XSD), proto stojí tady nad druhem vztahu ve zdroji.
     */
    private function assertDeferredIncomeRelation(string $relationType): void
    {
        if (in_array($relationType, ['small_scale_employment', 'dpp'], true)) {
            $this->invalid(
                'jmhz_eldp_deferred_small_scale_unsupported',
                'Příjem zúčtovaný po skončení '
                    . ($relationType === 'dpp' ? 'dohody o provedení práce' : 'zaměstnání malého rozsahu')
                    . ' se nevykazuje jako odložený příjem s kódem P: patří do posledního měsíce '
                    . 'výkonu a může zpětně založit účast. Přepočtěte poslední měsíc a podejte za něj '
                    . 'opravné hlášení (počet dnů a vyměřovací základ ELDP).',
            );
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}
     */
    private function findEmployment(array $input, int $employmentId): array
    {
        $match = null;
        foreach ($this->rows($input['people'] ?? null, 'input.people') as $person) {
            $employee = $this->object($person['employee'] ?? null, 'employee');
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            foreach ($this->rows($person['employments'] ?? null, 'person.employments') as $entry) {
                $employment = $this->object($entry['employment'] ?? null, 'employment');
                if (($employment['id'] ?? null) === $employmentId) {
                    if ($match !== null || ($employment['employee_id'] ?? null) !== $employeeId) {
                        $this->invalid('jmhz_eldp_employment_scope_mismatch', 'Pracovní vztah není ve snapshotu jednoznačný.');
                    }
                    $match = [$employeeId, $entry];
                }
            }
        }
        if ($match === null) {
            $this->invalid('jmhz_eldp_employment_not_found', 'Pracovní vztah není ve zdrojové revizi.');
        }
        return $match;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function socialRelationship(array $result, int $employeeId, int $employmentId): array
    {
        $matchedPerson = null;
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            if (($person['employee_id'] ?? null) !== $employeeId) {
                continue;
            }
            if ($matchedPerson !== null) {
                $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek obsahuje osobu vícekrát.');
            }
            $matchedPerson = $person;
        }
        if ($matchedPerson === null) {
            $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek vztahu chybí.');
        }
        $calculationMatches = 0;
        foreach ($this->rows($matchedPerson['employments'] ?? null, 'result.employments') as $employment) {
            if (($employment['employment_id'] ?? null) === $employmentId) {
                ++$calculationMatches;
            }
        }
        if ($calculationMatches !== 1) {
            $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Výsledek výpočtu nepokrývá pracovní vztah právě jednou.');
        }
        $person = $matchedPerson;
            $statutory = $this->object($person['statutory'] ?? null, 'statutory');
            $social = $this->object($statutory['social_insurance'] ?? null, 'social_insurance');
            if (($social['status'] ?? null) !== 'calculated') {
                $this->invalid('jmhz_eldp_social_not_calculated', 'Sociální pojištění není vypočtené.');
            }
            $match = null;
            foreach ($this->rows($social['relationships'] ?? null, 'social.relationships') as $relationship) {
                if (($relationship['relationship_id'] ?? null) === "employment:{$employmentId}") {
                    if ($match !== null) {
                        $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek obsahuje vztah vícekrát.');
                    }
                    $match = $relationship;
                }
            }
            $participation = is_array($match)
                && is_array($match['participation'] ?? null)
                ? $match['participation']
                : null;
            if (!is_array($match)
                || !is_array($participation)
                || ($participation['relationship_id'] ?? null) !== "employment:{$employmentId}"
            ) {
                $this->invalid('jmhz_eldp_social_relationship_unsupported', 'Vztah nemá jednoznačný výsledek účasti na sociálním pojištění.');
            }
            return $match;
    }

    /** @param array<string,mixed> $relationship */
    private function participationMode(string $relationType, array $relationship, int $employmentId): bool
    {
        $participation = $this->object($relationship['participation'] ?? null, 'participation');
        $expectedKind = match ($relationType) {
            // Zaměstnání malého rozsahu je pro sociální pojištění pracovní
            // poměr; liší se jen agregační skupinou účasti, ne druhem vztahu
            // (viz `SocialRelationshipKindMapper`).
            'employment', 'small_scale_employment' => 'employment',
            'dpc' => 'dpc',
            'dpp' => 'dpp',
            'partner_dependent', 'statutory_body' => 'corporate_body',
            default => $this->invalid(
                'jmhz_eldp_relationship_kind_unsupported',
                'ELDP podporuje pracovní poměr, zaměstnání malého rozsahu, DPČ, DPP a člena statutárního orgánu.',
            ),
        };
        $status = $participation['status'] ?? null;
        if (($relationship['kind'] ?? null) !== $expectedKind
            || ($participation['relationship_id'] ?? null) !== "employment:{$employmentId}"
            || !is_string($status)
        ) {
            $this->invalid('jmhz_eldp_social_relationship_unsupported', 'Druh vztahu a výsledek sociální účasti si odporují.');
        }
        /*
         * Účast na nemocenském pojištění rozhoduje o ELDP bez ohledu na druh
         * vztahu: účastný měsíc má kód a dny pojištění, neúčastný je bezkódová
         * sekce s nulou dnů. Dřív se neúčast připouštěla jen u DPP, takže
         * jednatel nebo DPČ pod rozhodnou částkou (3 000 Kč) shodily hlášení
         * CELÉ firmy — a to je nejběžnější stav malé s. r. o.
         */
        return match ($status) {
            'participates' => true,
            'does_not_participate' => false,
            default => $this->invalid(
                'jmhz_eldp_social_relationship_unsupported',
                'Výsledek účasti na sociálním pojištění není jednoznačný.',
            ),
        };
    }

    private function assertRelationActivityFamily(
        string $relationType,
        string $activityCode,
        mixed $relationshipDetailCode,
    ): void {
        if (($relationshipDetailCode !== null && !is_string($relationshipDetailCode))
            || !PayrollEmploymentJmhzActivityFamily::matches(
                $relationType,
                $activityCode,
                $relationshipDetailCode,
            )) {
            $this->invalid(
                'jmhz_eldp_relation_activity_mismatch',
                'Druh činnosti nebo bližší určení neodpovídá druhu pracovního vztahu.',
            );
        }
    }

    /**
     * Fail-closed ověření, že katalogy scénářů a kontrol JMHZ jdou načíst
     * a sedí na připnuté otisky. Zmrazení ELDP ho dřív dělalo za každý vztah
     * znovu (u přípravy pro 226 lidí desítky sekund čtení a hashování týchž
     * souborů); stačí jednou za životnost builderu.
     */
    public function verifySourceCatalogs(): void
    {
        if ($this->sourceCatalogsVerified) {
            return;
        }
        JmhzScenarioRequirementSourceCatalog::load();
        JmhzControlSourceCatalog::load();
        $this->sourceCatalogsVerified = true;
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function specManifest(): array
    {
        return $this->specManifest ??= (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
    }

    private function codebook(): JmhzCodebookCatalog
    {
        return new JmhzCodebookCatalog($this->specManifest());
    }

    /**
     * @param array<string,mixed> $workSummary
     * @param list<array<string,mixed>> $absences
     * @param array{components:array<string,int>,total:int} $excluded
     */
    private function assertWorkSummaryConsistency(
        array $workSummary,
        int $insuranceDays,
        string $relationType,
        array $absences,
        array $excluded,
        int $expectedRelationshipDays,
        string $summaryVersion,
        string $intervalFrom,
        string $intervalTo,
    ): void
    {
        $values = $this->object($workSummary['values'] ?? null, 'work_summary.values');
        $interactions = $this->object($workSummary['interactions'] ?? null, 'work_summary.interactions');
        /*
         * Dny evidenčního stavu (10265) a dny pojištění jsou dva různé údaje:
         * vztah může trvat celý měsíc a přitom nebýt účastný (jednatel nebo
         * DPČ pod rozhodnou částkou). Porovnávat 10265 s počtem dnů pojištění
         * proto jde jen u účastného vztahu; u neúčastného se očekává délka
         * trvání vztahu. U DPČ a DPP zůstává 0 (viz `evidenceInterval`).
         *
         * Pracovní poměr navíc není v evidenčním stavu po dny mateřské,
         * rodičovské a otcovské (PayrollJmhzEvidenceStateDays). Souhrn
         * potvrzený dřív, než se ty dny odečítaly, nese celé trvání vztahu;
         * ten se přijme tak, jak byl potvrzen, aby se kvůli statistickému
         * údaji nemusel znovu otevírat měsíc se schváleným během. Nový souhrn
         * hodnotu nepřebírá od účetní, ale z náhledu, takže jinou než
         * sníženou mít nemůže.
         */
        $expectedEvidenceDays = in_array($relationType, ['dpc', 'dpp'], true)
            ? [0]
            : array_values(array_unique([
                $expectedRelationshipDays
                    - PayrollJmhzEvidenceStateDays::outsideDays($relationType, $intervalFrom, $intervalTo, $absences),
                $expectedRelationshipDays,
            ]));
        if (($workSummary['conditional_blocks_confirmed'] ?? null) !== true
            || !in_array($values['evidence_days'] ?? null, $expectedEvidenceDays, true)
        ) {
            $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn nepotvrzuje běžný bezabsenční ELDP interval.');
        }
        /*
         * IN08 (překážky v práci) smí být aktivní jen tam, kde měsíc opravdu
         * překážku eviduje. Souhrn v2 pro ni nemá doložený zápis do
         * evidenčního listu, takže tam zůstává zakázaná přesně jako dřív.
         */
        if (self::fromImportSummary($summaryVersion)) {
            $this->assertImportSummaryAbsenceDates($values, $absences, $summaryVersion);
        }
        /*
         * Souhrn z importu docházky dokládá překážky měsíčními hodinami i bez
         * evidované nepřítomnosti: vyloučenou dobu ani vyloučený den netvoří,
         * takže ELDP k nim data od–do nepotřebuje.
         */
        $obstacleAbsences = (self::hasObstacleAbsence($absences)
            && self::carriesV3Blocks($summaryVersion))
            || (self::fromImportSummary($summaryVersion) && self::hasObstacleHours($values));
        if (($interactions['IN08'] ?? null) !== $obstacleAbsences) {
            $this->invalid(
                'jmhz_eldp_work_summary_mismatch',
                'Interakce IN08 pracovního souhrnu neodpovídá evidovaným překážkám v práci.',
            );
        }
        // Svátek v jinak pracovní den je neodpracovaná hodina i bez evidované
        // nepřítomnosti, takže měsíc se svátkem prochází stejnou kontrolou
        // úhrnů jako měsíc s nepřítomností.
        $holidayHours = self::carriesHolidays($summaryVersion)
            && is_int($values['holiday_millihours'] ?? null)
            && $values['holiday_millihours'] > 0;
        // Měsíc jen s nepřítomností bez povinných hodin (§ 16 odst. 4 písm. j))
        // a bez neodpracovaných hodin se souhrnem dokládá jako bezabsenční;
        // 10536 má původ přímo v evidované nepřítomnosti.
        $hoursOptionalOnly = $absences !== []
            && array_filter(
                $absences,
                static fn (mixed $absence): bool => !in_array(
                    is_array($absence) ? ($absence['absence_type'] ?? null) : null,
                    self::HOURS_OPTIONAL_ABSENCE_TYPES,
                    true,
                ),
            ) === []
            && ($interactions['IN07'] ?? null) === false;
        if (($absences !== [] && !$hoursOptionalOnly)
            || $holidayHours
            || (self::fromImportSummary($summaryVersion) && ($interactions['IN07'] ?? null) === true)
        ) {
            $this->assertAbsenceSliceWorkSummary(
                $values,
                $interactions,
                $absences,
                $excluded,
                $summaryVersion,
            );
            return;
        }
        if (($interactions['IN07'] ?? null) !== false) {
            $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn nepotvrzuje běžný bezabsenční ELDP interval.');
        }
        foreach (self::unworkedFields($summaryVersion) as $field) {
            if (!array_key_exists($field, $values) || $values[$field] !== null) {
                $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn obsahuje neodpracované hodiny mimo ordinary ELDP řez.');
            }
        }
        foreach (['unworked_total_millihours', 'unworked_paid_millihours'] as $field) {
            if (!array_key_exists($field, $values) || $values[$field] !== null) {
                $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn obsahuje neodpracované hodiny mimo ordinary ELDP řez.');
            }
        }
    }

    /**
     * Hodinové bloky, které souhrn dané verze zná.
     *
     * @return list<string>
     */
    private static function unworkedFields(string $summaryVersion): array
    {
        if (self::carriesCompensatoryTimeOff($summaryVersion)) {
            // Náhradní volno nemá vlastní blok hlášení a do 10276 nepatří
            // (mzda za ně nepřísluší), vstupuje jen do úhrnu 10275.
            return array_merge(
                self::UNWORKED_FIELDS,
                self::V3_UNWORKED_FIELDS,
                ['compensatory_time_off_millihours'],
                self::carriesHolidays($summaryVersion)
                    ? PayrollJmhzWorkMonthSummaryBuilder::holidayFields()
                    : [],
                // Svátek uvnitř nepřítomnosti bez mzdy: jen do úhrnu 10275,
                // do 10276 ne (není v PAID_UNWORKED_FIELDS). Nese ho až v9.
                in_array($summaryVersion, PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_UNPAID_HOLIDAYS, true)
                    ? PayrollJmhzWorkMonthSummaryBuilder::unpaidHolidayFields()
                    : [],
            );
        }

        return self::carriesV3Blocks($summaryVersion)
            ? array_merge(self::UNWORKED_FIELDS, self::V3_UNWORKED_FIELDS)
            : array_merge(self::UNWORKED_FIELDS, [
                'employee_obstacle_paid_millihours',
                'employer_obstacle_millihours',
            ]);
    }

    /**
     * Souhrn z importu docházky (v6) nese neodpracované hodiny jako měsíční
     * součty, bez dat od–do.
     *
     * Dovolená a překážky v práci se dají převzít i tak: nejsou vyloučenou
     * dobou (§ 16 odst. 4 zákona č. 155/1995 Sb.) ani vyloučeným dnem
     * (§ 18 odst. 7 zákona č. 187/2006 Sb.). Nemoc, ošetřovné, PPM, otcovská,
     * rodičovská, neplacené a náhradní volno i neomluvená absence ale dny
     * evidenčního listu tvoří — a z hodin je spočítat nejde. Bez evidované
     * nepřítomnosti s daty by řez vykázal nula vyloučených dnů, tedy tichou
     * nulu místo pravdy. Proto zastaví vlastním kódem, který říká, CO chybí.
     *
     * @param array<string,mixed> $values
     * @param list<array<string,mixed>> $absences
     */
    private function assertImportSummaryAbsenceDates(
        array $values,
        array $absences,
        string $summaryVersion,
    ): void {
        $supported = self::absenceWorkSummaryFields($summaryVersion);
        $documented = array_fill_keys([
            ...PayrollJmhzWorkMonthSummaryBuilder::importDateFreeSummaryFields(),
            ...PayrollJmhzWorkMonthSummaryBuilder::holidayFields(),
        ], true);
        foreach ($absences as $absence) {
            foreach ($supported[(string) ($absence['absence_type'] ?? '')] ?? [] as $field) {
                $documented[$field] = true;
            }
        }
        foreach (self::unworkedFields($summaryVersion) as $field) {
            $value = $values[$field] ?? null;
            if (!isset($documented[$field]) && is_int($value) && $value > 0) {
                $this->invalid(
                    'jmhz_eldp_import_absence_dates_missing',
                    'Pracovní souhrn převzatý z importu docházky uvádí neodpracované hodiny,'
                        . ' ke kterým evidenční list potřebuje data nepřítomnosti od–do,'
                        . ' ale evidence nepřítomností je nemá. Vyloučené doby ani vyloučené'
                        . ' dny se z měsíčního součtu hodin odvodit nedají.',
                );
            }
        }
    }

    /** @param array<string,mixed> $values */
    private static function hasObstacleHours(array $values): bool
    {
        foreach (['employee_obstacle_paid_millihours', 'employer_obstacle_millihours'] as $field) {
            if (is_int($values[$field] ?? null) && $values[$field] > 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $absences */
    private static function hasObstacleAbsence(array $absences): bool
    {
        foreach ($absences as $absence) {
            if (in_array(
                $absence['absence_type'] ?? null,
                ['employee_obstacle', 'employer_obstacle'],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $absences */
    private static function hasHoursOptionalAbsence(array $absences): bool
    {
        foreach ($absences as $absence) {
            if (in_array($absence['absence_type'] ?? null, self::HOURS_OPTIONAL_ABSENCE_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Měsíc bez započitatelného příjmu není dobou pojištění.
     *
     * § 11 odst. 2 zákona č. 155/1995 Sb.: „za dobu pojištění … se nepovažuje
     * kalendářní měsíc, ve kterém nebyly dosaženy příjmy započitatelné do
     * vyměřovacího základu pojištěnce proto, že tyto osoby nevykonávaly činnost
     * zakládající účast na pojištění, pokud nešlo o omluvné důvody; za omluvné
     * důvody se považují skutečnosti uvedené v § 16 odst. 4 větě třetí
     * písm. a)". V ELDP se takový měsíc značí znakem „X" a jeho dny se do
     * úhrnu „Dny" nezapočítávají.
     *
     * Měsíční hlášení to vyjadřuje sekcí s kódem a intervalem vztahu, ale
     * s nulou dnů a nulovým základem. Tak ho vykázala přijatá hlášení dvou
     * různých mzdových systémů (celý měsíc neplaceného volna u HPP).
     *
     * Nemoc, karanténa ani ošetřovné sem nepatří — jsou omluvným důvodem podle
     * § 16 odst. 4 věty třetí písm. a), takže měsíc dobou pojištění zůstává
     * i bez příjmu, s plným počtem dnů a nulovým základem.
     *
     * Zastaví jen měsíc, který podklady nevysvětlí: nula bez jakékoli
     * nepřítomnosti (chybí důvod, proč příjem nevznikl). Souběh omluvného
     * důvodu s neplaceným volnem je pojištěný měsíc (Metodická pomůcka ČSSZ
     * k ELDP, příklad 5), viz
     * {@see EldpExcludedPeriodDeriver::insuranceMonthStatus()}.
     *
     * @param list<array<string,mixed>> $absences
     */
    private function monthOutsideInsurancePeriod(
        array $absences,
        bool $participates,
        int $uncappedBase,
        string $intervalFrom,
        string $intervalTo,
    ): bool {
        if (!$participates) {
            return false;
        }
        $status = EldpExcludedPeriodDeriver::insuranceMonthStatus(
            $absences,
            $uncappedBase,
            $intervalFrom,
            $intervalTo,
        );
        if ($status === EldpExcludedPeriodDeriver::MONTH_UNEXPLAINED) {
            $this->invalid(
                'jmhz_eldp_insurance_month_without_income',
                'Účastný vztah nemá v měsíci započitatelný příjem ani'
                    . ' evidovanou nepřítomnost, která by to vysvětlila.',
            );
        }

        return $status === EldpExcludedPeriodDeriver::MONTH_OUTSIDE_INSURANCE;
    }

    /**
     * Druh nepřítomnosti musí být takový, který ordinary řez umí doložit
     * z obou zmrazených zdrojů zároveň. Kontrola stojí PŘED pracovním
     * souhrnem záměrně: u nedoloženého druhu („jiné", nebo druh, pro který
     * starší verze souhrnu nemá hodinový blok) má účetní vidět, že vadí DRUH
     * nepřítomnosti, ne až rozpor v hodinách, který je jen jeho následkem.
     *
     * @param list<array<string,mixed>> $absences
     */
    private function assertOrdinaryAbsenceTypes(array $absences, string $summaryVersion): void
    {
        $supported = self::absenceWorkSummaryFields($summaryVersion);
        foreach ($absences as $absence) {
            $type = is_array($absence) && !array_is_list($absence)
                ? ($absence['absence_type'] ?? null)
                : null;
            if (!is_string($type) || !array_key_exists($type, $supported)) {
                $this->invalid(
                    'jmhz_eldp_absences_unsupported',
                    'Ordinary ELDP automaticky podporuje jen nepřítomnost doloženou'
                        . ' zároveň vyloučenými dobami i pracovním souhrnem:'
                        . ' dovolenou, nemoc, karanténu, ošetřovné, peněžitou pomoc'
                        . ' v mateřství, otcovskou, rodičovskou, neplacené volno,'
                        . ' neomluvenou absenci, překážky v práci a náhradní volno'
                        . ' za přesčas (to až v pracovním souhrnu schváleném po jeho'
                        . ' zavedení).',
                );
            }
        }
    }

    /**
     * Nese souhrn hodinové bloky, které přibyly ve verzi v3?
     *
     * Verze se přidávají shora: v4 je v3 plus počet odpracovaných dnů a
     * přesčas pro měsíční hlášení, hodinové bloky má stejné. Porovnávat na
     * rovnost s v3 by proto každou další verzi tiše shodilo zpátky na chování
     * v2 a fail-closed by zablokovalo nepřítomnosti, které souhrn ve
     * skutečnosti doloží.
     */
    private static function carriesV3Blocks(string $summaryVersion): bool
    {
        return in_array(
            $summaryVersion,
            PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_LOCAL_EVIDENCE,
            true,
        );
    }

    /** Nese souhrn hodiny náhradního volna za přesčas (od v5)? */
    private static function carriesCompensatoryTimeOff(string $summaryVersion): bool
    {
        return in_array(
            $summaryVersion,
            PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_COMPENSATORY_TIME_OFF,
            true,
        );
    }

    /** Bere souhrn odpracovanou dobu ze souhrnu importu docházky (v6, v8)? */
    private static function fromImportSummary(string $summaryVersion): bool
    {
        return in_array(
            $summaryVersion,
            PayrollJmhzWorkMonthSummaryBuilder::IMPORT_SUMMARY_VERSIONS,
            true,
        );
    }

    /** Nese souhrn hodiny svátků v jinak pracovní dny (od v7)? */
    private static function carriesHolidays(string $summaryVersion): bool
    {
        return in_array(
            $summaryVersion,
            PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_HOLIDAYS,
            true,
        );
    }

    /**
     * Druh nepřítomnosti → hodinové bloky, podle verze pracovního souhrnu.
     *
     * @return array<string,list<string>>
     */
    private static function absenceWorkSummaryFields(string $summaryVersion): array
    {
        $fields = self::carriesV3Blocks($summaryVersion)
            ? self::ABSENCE_WORK_SUMMARY_FIELDS + self::V3_ABSENCE_WORK_SUMMARY_FIELDS
            : self::ABSENCE_WORK_SUMMARY_FIELDS;
        if (self::carriesCompensatoryTimeOff($summaryVersion)) {
            $fields['compensatory_time_off'] = ['compensatory_time_off_millihours'];
        }

        return $fields;
    }

    /**
     * Vyloučené doby řezu podle § 16 odst. 4 písm. a) zákona č. 155/1995 Sb.
     *
     * Počítá je TÝŽ modul jako u ročního evidenčního listu, nad týmž
     * intervalem (průnik pracovního vztahu s měsícem). Měsíční a roční ELDP
     * proto u téže nepřítomnosti vykážou tentýž počet dnů — kdyby se tu
     * počítalo vlastní logikou, rozdíl by se ukázal až na ČSSZ.
     *
     * Krytí vyloučené doby příjmem (Příloha č. 3 Všeobecných zásad ELDP) se
     * rozhoduje v témž odvození: příjem měsíce a dny kryté souběžným vztahem.
     *
     * @param list<array<string,mixed>> $absences
     * @param array<string,true> $concurrentIncomeDays
     * @return array{components:array<string,int>,total:int,provenance:list<array<string,mixed>>,covered:list<array<string,mixed>>}
     */
    private function excludedPeriods(
        array $absences,
        string $insuranceFrom,
        string $insuranceTo,
        int $insuranceDays,
        int $incomeMinor,
        array $concurrentIncomeDays,
    ): array {
        $derived = (new EldpExcludedPeriodDeriver())->derive(
            $absences,
            $insuranceFrom,
            $insuranceTo,
            substr($insuranceFrom, 0, 7),
            $incomeMinor,
            $concurrentIncomeDays,
        );
        if ($derived['blockers'] !== []) {
            // Chybějící den porodu u mateřské má konkrétní nápravu; obecný
            // nález by účetní poslal „zpracovat individuálně".
            if (in_array('eldp_ppm_childbirth_missing', array_column($derived['blockers'], 'code'), true)) {
                $this->invalid(
                    'jmhz_eldp_ppm_childbirth_missing',
                    'Peněžitá pomoc v mateřství nemá doplněný den porodu; doplňte ho u nepřítomnosti.',
                );
            }
            $this->invalid(
                'jmhz_eldp_absences_unsupported',
                'Nepřítomnost nelze bezpečně převést na vyloučené doby evidenčního listu.',
            );
        }
        if (array_sum($derived['components']) !== $derived['total']) {
            $this->invalid(
                'jmhz_eldp_excluded_days_sum_mismatch',
                'Úhrn vyloučených dob neodpovídá rozpadu podle § 16 odst. 4 zákona č. 155/1995 Sb.',
            );
        }
        // Vyloučená doba je vždy podmnožinou doby pojištění: den, ve kterém
        // pojištění netrvalo, nemá z čeho být vyloučený. Kontrola 98 ČSSZ to
        // hlídá proti délce měsíce, tady se poměřuje s intervalem řezu, který
        // je stejný nebo kratší.
        if ($derived['total'] > $insuranceDays) {
            $this->invalid(
                'jmhz_eldp_excluded_days_exceed_period',
                'Vyloučené doby přesahují dobu pojištění vykázanou v ELDP řezu.',
            );
        }
        foreach ($absences as $absence) {
            $from = $absence['date_from'] ?? null;
            $to = $absence['date_to'] ?? null;
            if (!is_string($from) || !is_string($to)
                || $from > $insuranceTo || $to < $insuranceFrom
            ) {
                // Nepřítomnost mimo interval řezu se do vyloučených dob
                // nezapočítá, ale do neodpracovaných hodin měsíce ano —
                // příčná kontrola níž by pak porovnávala dva různé rozsahy.
                $this->invalid(
                    'jmhz_eldp_absences_unsupported',
                    'Nepřítomnost nezasahuje do intervalu ELDP řezu a nelze ji proti němu doložit.',
                );
            }
        }

        return $derived;
    }

    /**
     * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. (10366 a rozpad
     * 10473–10475) pro jeden ELDP řez.
     *
     * Vrací `null`, když se rozpad ze zmrazeného snapshotu odvodit nedá
     * ({@see EldpExcludedPeriodDeriver::deriveSection18()}). `null` znamená
     * NEUVEDENO, ne nulu; serializér pak celý blok vynechá. Legální je to jen
     * tehdy, když sekce vykazuje vyloučené doby (10357 > 0): matice povinností
     * vede 10366 jako „nepovinné, pokud je vyplněn 10357 > 0". V sekci bez
     * vyloučených dob (měsíc bez dnů pojištění, poživatel starobního důchodu)
     * výjimka neplatí a nerozhodnutelný rozpad hlášení zastaví.
     *
     * @param list<array<string,mixed>> $absences
     * @return array{components:array<string,int>,total:int,provenance:list<array<string,mixed>>}|null
     */
    private function section18Periods(
        array $absences,
        string $insuranceFrom,
        string $insuranceTo,
        int $insuranceDays,
        int $excludedPeriodsTotal,
    ): ?array {
        if ($insuranceDays === 0) {
            // Neúčastný vztah (dohoda pod hranicí) nemocensky pojištěný není,
            // takže z jeho rozhodného období není co vyloučit. Volající sem
            // u účastného vztahu posílá dny intervalu, ne dny pojištění.
            return null;
        }
        $derived = (new EldpExcludedPeriodDeriver())->deriveSection18(
            $absences,
            $insuranceFrom,
            $insuranceTo,
        );
        if ($derived['derivable'] !== true) {
            if ($excludedPeriodsTotal > 0) {
                return null;
            }
            $this->invalid(
                'jmhz_eldp_section18_days_unresolved',
                'Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. nejde z nepřítomností'
                    . ' měsíce odvodit (' . implode(', ', $derived['undecidable_types']) . ') a bez'
                    . ' vyloučených dob je hlášení musí nést. U nemoci znovu schvalte mzdový'
                    . ' běh, aby se zmrazilo okno náhrady mzdy.',
            );
        }
        if (array_sum($derived['components']) !== $derived['total']) {
            $this->invalid(
                'jmhz_eldp_section18_days_sum_mismatch',
                'Úhrn vyloučených dnů neodpovídá rozpadu podle § 18 odst. 7 zákona č. 187/2006 Sb.',
            );
        }
        // Kontrola 98 ČSSZ poměřuje 10366 i jeho složky s počtem kalendářních
        // dnů měsíce. Interval řezu je stejný nebo kratší, takže je přísnější;
        // u neúčastného vztahu je `insuranceDays` nula, a pak nesmí být
        // vyloučený den žádný — bez doby pojištění není co vylučovat.
        if ($derived['total'] > $insuranceDays) {
            $this->invalid(
                'jmhz_eldp_section18_days_exceed_period',
                'Vyloučené dny podle § 18 odst. 7 přesahují dobu pojištění vykázanou v ELDP řezu.',
            );
        }

        return [
            'components' => $derived['components'],
            'total' => $derived['total'],
            'provenance' => $derived['provenance'],
        ];
    }

    /**
     * Pracovní souhrn a vyloučené doby musí popisovat TUTÉŽ nepřítomnost.
     *
     * Jsou to dva nezávisle zmrazené zdroje: hodiny vznikly z publikovaných
     * směn při schvalování náhrady mzdy, dny z evidence absencí. Ordinary řez
     * projde jen tehdy, když se shodnou — na tom, které druhy nepřítomnosti
     * v měsíci byly, i na tom, že u každého druhu s vyloučenou dobou opravdu
     * nějaké hodiny odpadly.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $interactions
     * @param list<array<string,mixed>> $absences
     * @param array{components:array<string,int>,total:int} $excluded
     */
    private function assertAbsenceSliceWorkSummary(
        array $values,
        array $interactions,
        array $absences,
        array $excluded,
        string $summaryVersion,
    ): void {
        if (($interactions['IN07'] ?? null) !== true) {
            $this->invalid(
                'jmhz_eldp_work_summary_mismatch',
                'Měsíc s nepřítomností vyžaduje aktivní interakci IN07 v pracovním souhrnu.',
            );
        }
        $supported = self::absenceWorkSummaryFields($summaryVersion);
        $expected = [];
        foreach ($absences as $absence) {
            $type = (string) $absence['absence_type'];
            $expected[$type] = $supported[$type];
        }
        $filled = [];
        foreach ($expected as $fields) {
            foreach ($fields as $field) {
                $filled[$field] = true;
            }
        }
        if (self::fromImportSummary($summaryVersion)) {
            foreach (PayrollJmhzWorkMonthSummaryBuilder::importDateFreeSummaryFields() as $field) {
                $filled[$field] = true;
            }
        }
        if (self::carriesHolidays($summaryVersion)) {
            foreach (PayrollJmhzWorkMonthSummaryBuilder::holidayFields() as $field) {
                $filled[$field] = true;
            }
        }
        // Svátek uvnitř nepřítomnosti bez mzdy dokládá sama ta nepřítomnost
        // (souhrn ho odvozuje jen uvnitř ní).
        if ($absences !== [] && in_array($summaryVersion, PayrollJmhzWorkMonthSummaryBuilder::VERSIONS_WITH_UNPAID_HOLIDAYS, true)) {
            foreach (PayrollJmhzWorkMonthSummaryBuilder::unpaidHolidayFields() as $field) {
                $filled[$field] = true;
            }
        }
        $total = 0;
        $paid = 0;
        foreach (self::unworkedFields($summaryVersion) as $field) {
            if (!array_key_exists($field, $values)) {
                $this->invalid(
                    'jmhz_eldp_work_summary_mismatch',
                    "Pracovní souhrn neuvádí blok {$field}.",
                );
            }
            $value = $values[$field];
            if ($value !== null && (!is_int($value) || $value < 0)) {
                $this->invalid(
                    'jmhz_eldp_work_summary_mismatch',
                    "Blok {$field} pracovního souhrnu není nezáporné celé číslo.",
                );
            }
            if (!isset($filled[$field]) && $value !== null) {
                $this->invalid(
                    'jmhz_eldp_work_summary_mismatch',
                    "Pracovní souhrn vykazuje hodiny v bloku {$field}, ke kterému"
                        . ' v řezu není žádná evidovaná nepřítomnost.',
                );
            }
            $total += $value ?? 0;
            if (in_array($field, self::PAID_UNWORKED_FIELDS, true)) {
                $paid += $value ?? 0;
            }
        }
        foreach ($expected as $type => $fields) {
            if (in_array($type, self::HOURS_OPTIONAL_ABSENCE_TYPES, true)) {
                continue;
            }
            $sum = 0;
            foreach ($fields as $field) {
                $sum += $values[$field] ?? 0;
            }
            if ($sum <= 0) {
                $this->invalid(
                    'jmhz_eldp_work_summary_mismatch',
                    "Pracovní souhrn nedokládá žádné neodpracované hodiny k nepřítomnosti druhu {$type}.",
                );
            }
        }
        /*
         * 10276 je v XSD nepovinné a souhrn nulu navrhuje jako nevyplněnou
         * (viz PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions()).
         * Měsíc jen s nepřítomností bez náhrady mzdy (náhradní volno,
         * neplacené volno, PPM) proto nese 10276 prázdné, ne nulu.
         */
        $reportedPaid = $values['unworked_paid_millihours'] ?? null;
        if ($total <= 0
            || ($values['unworked_total_millihours'] ?? null) !== $total
            || ($paid === 0 ? !in_array($reportedPaid, [null, 0], true) : $reportedPaid !== $paid)
        ) {
            $this->invalid(
                'jmhz_eldp_work_summary_mismatch',
                'Úhrny neodpracovaných hodin neodpovídají rozpadu podle druhů nepřítomnosti.',
            );
        }
        // Den, který z vyloučených dob vyřadilo krytí příjmem, má hodiny bez
        // vyloučeného dne doloženě (Příloha č. 3 Všeobecných zásad ELDP).
        $coveredAttributes = array_flip(array_column($excluded['covered'] ?? [], 'attribute'));
        foreach (EldpExcludedPeriodDeriver::COMPONENTS as $attribute) {
            $days = $excluded['components'][$attribute] ?? 0;
            $fields = self::EXCLUDED_ATTRIBUTE_FIELDS[$attribute] ?? null;
            if ($fields === null) {
                // 10536 § 16 odst. 4 písm. j) přitéká jen z nepřítomnosti, u které
                // hodiny nejsou povinné (HOURS_OPTIONAL_ABSENCE_TYPES); bez ní by
                // nenulová hodnota znamenala, že se výčty druhů rozešly.
                if ($attribute === self::HOURS_OPTIONAL_EXCLUDED_ATTRIBUTE
                    && self::hasHoursOptionalAbsence($absences)
                ) {
                    continue;
                }
                if ($days !== 0) {
                    $this->invalid(
                        'jmhz_eldp_excluded_days_unsupported',
                        "Vyloučená doba {$attribute} nemá v ordinary ELDP řezu doložený původ.",
                    );
                }
                continue;
            }
            $hours = 0;
            foreach ($fields as $field) {
                $hours += $values[$field] ?? 0;
            }
            $mismatch = in_array($attribute, self::ONE_WAY_EXCLUDED_ATTRIBUTES, true)
                ? $days > 0 && $hours <= 0
                : ($days > 0) !== ($hours > 0)
                    && !($days === 0 && isset($coveredAttributes[$attribute]));
            if ($mismatch) {
                $this->invalid(
                    'jmhz_eldp_excluded_days_unsupported',
                    "Vyloučená doba {$attribute} ({$days} dnů) neodpovídá neodpracovaným"
                        . ' hodinám téhož druhu v pracovním souhrnu.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function findCodebook(array $payload, string $key): array
    {
        foreach ($this->rows($payload['codebooks'] ?? null, 'codebooks') as $codebook) {
            if (($codebook['codebook_key'] ?? null) === $key) {
                return $codebook;
            }
        }
        throw new \UnexpectedValueException("Číselník {$key} chybí.");
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $field): array
    {
        // Stejný důvod jako v JmhzOrdinaryEvidenceBuilder::$canonicalSnapshots:
        // příprava JMHZ volá builder za každý vztah nad týmž zdrojem revize.
        $cached = $this->canonicalSnapshots[$field] ?? null;
        if ($cached !== null && $cached['hash'] === $hash && $cached['json'] === $json) {
            return $cached['decoded'];
        }
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            $this->invalid('jmhz_eldp_source_hash_mismatch', "Otisk {$field} snapshotu nesouhlasí.");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $json) {
            $this->invalid('jmhz_eldp_source_invalid', "Snapshot {$field} není kanonický objekt.");
        }
        $this->canonicalSnapshots[$field] = ['json' => $json, 'hash' => $hash, 'decoded' => $decoded];
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->invalid('jmhz_eldp_source_invalid', "{$field} obsahuje neplatný řádek.");
            }
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být nezáporné celé číslo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} není platné datum.");
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzEldpEvidenceException($code, $message);
    }
}
