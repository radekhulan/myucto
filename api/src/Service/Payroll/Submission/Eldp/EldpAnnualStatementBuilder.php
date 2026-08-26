<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

/**
 * Sestavení evidenčního listu důchodového pojištění za kalendářní rok.
 *
 * Na rozdíl od `JmhzEldpEvidenceBuilder`, který zmrazuje ELDP atributy jednoho
 * měsíce jako součást měsíčního hlášení, je tohle **celý evidenční list jako
 * samostatná zákonná povinnost**: sečte odpracované doby a vyměřovací základy
 * napříč měsíci roku, rozdělí je na sekce podle kódu ELDP a doplní vyloučené
 * doby. Zdrojem je vždy zmrazená schválená mzdová revize, nikdy živá tabulka —
 * evidenční list musí být reprodukovatelný i za deset let.
 *
 * Stavební zásada: **co není doložené, blokuje**. Chybějící měsíc, absence,
 * kterou modul neumí zapsat, jiné datum nástupu mezi měsíci nebo krácení
 * ročním maximem nevede k odhadu, ale k blokátoru, který pojmenuje konkrétní
 * chybějící podklad. Zákonný rámec a lhůty popisuje `EldpDeadlinePolicy`.
 *
 * ## Co se do listu vědomě nezapisuje
 *
 * - **Doba uchování stejnopisu.** § 38 odst. 5 věta první zákona č. 582/1991 Sb.
 *   ve znění účinném do 31. 12. 2025 ukládá založit stejnopis do evidence
 *   zaměstnavatele a odkazuje přitom na § 35a odst. 4 písm. a) — tam, a ne
 *   v § 38, stojí lhůta **3 kalendářní roky**. Do listu se proto nezapisuje:
 *   uchovávací lhůty modul drží na jednom místě, v retenčním katalogu
 *   (kategorie `PENSION_EVIDENCE_SHEETS`), ne v jednotlivých sestavovačích.
 * - **Odečítané doby** (10375, 10462–10469) — týkají se dob po dosažení
 *   důchodového věku, který modul nezná. Nula je proto podmíněná výslovným
 *   potvrzením mzdové účetní, ne výpočtem.
 */
final class EldpAnnualStatementBuilder
{
    public const BUILDER_VERSION = 'eldp-annual-statement.v1';

    private const MONTH_NAMES = [
        1 => 'leden', 2 => 'únor', 3 => 'březen', 4 => 'duben',
        5 => 'květen', 6 => 'červen', 7 => 'červenec', 8 => 'srpen',
        9 => 'září', 10 => 'říjen', 11 => 'listopad', 12 => 'prosinec',
    ];

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $specManifest = null;

    public function __construct(
        private readonly EldpExcludedPeriodDeriver $excludedPeriods
            = new EldpExcludedPeriodDeriver(),
        private readonly EldpDeadlinePolicy $deadlines
            = new EldpDeadlinePolicy(),
    ) {}

    /**
     * @param list<mixed> $revisions zmrazené mzdové revize roku
     * @param array<string,mixed>       $confirmation výslovné potvrzení účetní
     */
    public function build(
        int $supplierId,
        int $employmentId,
        int $year,
        array $revisions,
        array $confirmation,
    ): EldpAnnualStatement {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a pracovní vztah musí být kladná čísla.',
            );
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException(
                'Rok evidenčního listu musí být v rozsahu 2000 až 2100.',
            );
        }
        $requestedByAuthority = $confirmation['requested_by_authority'] ?? null;
        if (!is_bool($requestedByAuthority)) {
            throw new EldpValidationException(
                'eldp_confirmation_invalid',
                'Potvrzení musí výslovně určit, zda jde o evidenční list na výzvu ČSSZ/ÚSSZ.',
            );
        }
        if (($confirmation['excluded_days_confirmed'] ?? null) !== true) {
            throw new EldpValidationException(
                'eldp_excluded_days_not_confirmed',
                'Vyloučené doby evidenčního listu musí mzdová účetní výslovně potvrdit.',
            );
        }
        if (($confirmation['deducted_days_none'] ?? null) !== true) {
            throw new EldpValidationException(
                'eldp_deducted_days_unsupported',
                'Modul neumí odvodit odečítané doby po dosažení důchodového věku; '
                    . 'evidenční list lze sestavit jen s výslovným potvrzením, že žádné nejsou.',
            );
        }
        $note = $confirmation['note'] ?? null;
        if (!is_string($note)
            || mb_strlen(trim($note), 'UTF-8') < 5
            || mb_strlen(trim($note), 'UTF-8') > 500
        ) {
            throw new EldpValidationException(
                'eldp_confirmation_note_invalid',
                'Potvrzení evidenčního listu musí mít 5 až 500 znaků.',
            );
        }

        $blockers = [];
        $months = $this->readMonths(
            $supplierId,
            $year,
            $revisions,
            $blockers,
        );
        if ($months === []) {
            $blockers[] = [
                'code' => 'eldp_no_source_revision',
                'message' => "Za rok {$year} není k pracovnímu vztahu žádná schválená mzdová revize.",
                'detail' => ['year' => $year, 'employment_id' => $employmentId],
            ];
            throw EldpValidationException::blocked($blockers);
        }
        ksort($months, SORT_STRING);

        $employment = $this->resolveEmployment($months, $employmentId, $blockers);
        $requiredMonths = self::requiredMonths(
            $year,
            $employment['start'],
            $employment['end'],
        );
        foreach ($requiredMonths as $periodStart) {
            if (!isset($months[$periodStart])) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_month_source_missing',
                    'message' => "Chybí schválená mzdová revize za {$label} — "
                        . 'bez ní nelze doložit dobu pojištění ani vyměřovací základ.',
                    'detail' => ['period_start' => $periodStart, 'employment_id' => $employmentId],
                ];
            }
        }
        foreach (array_keys($months) as $periodStart) {
            if (!in_array($periodStart, $requiredMonths, true)) {
                $label = self::monthLabel((string) $periodStart);
                $blockers[] = [
                    'code' => 'eldp_month_outside_employment',
                    'message' => "Mzdová revize za {$label} leží mimo trvání pracovního vztahu.",
                    'detail' => ['period_start' => $periodStart],
                ];
            }
        }
        if ($blockers !== []) {
            throw EldpValidationException::blocked($blockers);
        }

        $lines = [];
        foreach ($requiredMonths as $periodStart) {
            $line = $this->monthLine(
                $employmentId,
                $months[$periodStart],
                $employment,
                $blockers,
            );
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($blockers !== []) {
            throw EldpValidationException::blocked($blockers);
        }
        if ($lines === []) {
            throw new EldpValidationException(
                'eldp_no_insurance_period',
                'Za vykazovaný rok nevznikla doba důchodového pojištění.',
            );
        }

        $sections = $this->sections($lines);
        $codebook = $this->codebook();
        $codeEvidence = [];
        foreach ($sections as $section) {
            $codeEvidence[] = [
                'code' => $section['code'],
                'row_sha256' => $codebook->requireValue('kod_eldp', $section['code'])['row_hash'],
            ];
        }

        $participationEnd = $employment['end'] !== null
            && $employment['end'] <= sprintf('%04d-12-31', $year)
                ? $employment['end']
                : null;
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            $year,
            $participationEnd,
            $requestedByAuthority,
        );
        if (!$eligibility['allowed']) {
            throw new EldpValidationException(
                'eldp_standalone_statement_not_applicable',
                $eligibility['reason'],
            );
        }
        $lastLine = $lines[count($lines) - 1];
        // Skončí-li vztah přesně posledním dnem roku, evidenční list pokrývá
        // celý rok a platí řádná lhůta do 30. dubna. Mimořádná lhůta „do
        // jednoho měsíce po konečném vyúčtování“ patří jen skončení v průběhu
        // roku.
        $window = $participationEnd !== null
            && $participationEnd < sprintf('%04d-12-31', $year)
                ? $this->deadlines->forTermination(
                    $year,
                    $participationEnd,
                    $lastLine['period_end'],
                )
                : $this->deadlines->forYear($year);

        $spec = $this->specManifest();
        $payload = [
            'schema_reference' => EldpAnnualStatement::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'employee_id' => $employment['employee_id'],
                'employment_id' => $employmentId,
                'year' => $year,
                'statement_kind' => $window->statementKind,
                'period_from' => $lines[0]['insurance_from'],
                'period_to' => $lastLine['insurance_to'],
            ],
            'eligibility' => [
                'rule' => $eligibility['rule'],
                'reason' => $eligibility['reason'],
                'requested_by_authority' => $requestedByAuthority,
            ],
            'deadline' => [
                'ruleset_id' => $window->rulesetId,
                'ruleset_hash' => $window->rulesetHash,
                'earliest_submission_on' => $window->earliestSubmissionOn,
                'due_on' => $window->dueOn,
                'calendar_basis' => $window->calendarBasis,
                'legal_basis' => $window->legalBasis,
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => $spec['manifest_sha256'],
                'eldp_code_evidence' => $codeEvidence,
            ],
            'source_revisions' => array_map(
                static fn (array $line): array => [
                    'period_start' => $line['period_start'],
                    'revision_id' => $line['revision_id'],
                    'run_id' => $line['run_id'],
                    'input_snapshot_hash' => $line['input_snapshot_hash'],
                    'result_snapshot_hash' => $line['result_snapshot_hash'],
                ],
                $lines,
            ),
            'monthly_lines' => array_map(
                static fn (array $line): array => [
                    'period_start' => $line['period_start'],
                    'insurance_from' => $line['insurance_from'],
                    'insurance_to' => $line['insurance_to'],
                    'insurance_days' => $line['insurance_days'],
                    'assessment_base_czk' => $line['assessment_base_czk'],
                    'code' => $line['code'],
                    'excluded_days' => $line['excluded']['components'],
                    'excluded_days_total' => $line['excluded']['total'],
                    'excluded_days_provenance' => $line['excluded']['provenance'],
                ],
                $lines,
            ),
            'eldp_sections' => $sections,
            'confirmation' => [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'requested_by_authority' => $requestedByAuthority,
                'note' => trim($note),
            ],
        ];

        return new EldpAnnualStatement($payload);
    }

    /**
     * @param list<mixed> $revisions
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,array<string,mixed>> klíčem je `period_start`
     */
    private function readMonths(
        int $supplierId,
        int $year,
        array $revisions,
        array &$blockers,
    ): array {
        $months = [];
        foreach ($revisions as $revision) {
            if (!is_array($revision) || array_is_list($revision)) {
                throw new EldpValidationException(
                    'eldp_source_invalid',
                    'Zdrojová revize evidenčního listu není objekt.',
                );
            }
            $periodStart = $revision['period_start'] ?? null;
            if (!is_string($periodStart)
                || !self::isDate($periodStart)
                || !str_ends_with($periodStart, '-01')
            ) {
                throw new EldpValidationException(
                    'eldp_source_invalid',
                    'Zdrojová revize evidenčního listu nemá platné období.',
                );
            }
            if (substr($periodStart, 0, 4) !== sprintf('%04d', $year)) {
                continue;
            }
            if (isset($months[$periodStart])) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_month_source_ambiguous',
                    'message' => "Za {$label} přišly dvě schválené revize; evidenční list "
                        . 'nesmí stát na nejednoznačném podkladu.',
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $revisionNo = $revision['revision_no'] ?? null;
            if (($revision['status'] ?? null) !== 'approved'
                || !in_array(
                    $revision['revision_kind'] ?? null,
                    ['regular', 'correction'],
                    true,
                )
                || !is_int($revisionNo)
                || ($revision['current_revision_no'] ?? null) !== $revisionNo
            ) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_revision_not_current_approved',
                    'message' => "Revize za {$label} není aktuální schválená revize.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $input = $this->canonicalSnapshot(
                $revision['input_snapshot_json'] ?? null,
                $revision['input_snapshot_hash'] ?? null,
                'vstupního',
            );
            $result = $this->canonicalSnapshot(
                $revision['result_snapshot_json'] ?? null,
                $revision['result_snapshot_hash'] ?? null,
                'výsledkového',
            );
            if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
                || ($input['supplier_id'] ?? null) !== $supplierId
                || ($input['period_start'] ?? null) !== $periodStart
                || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
                || ($result['source_snapshot_hash'] ?? null)
                    !== ($revision['input_snapshot_hash'] ?? null)
            ) {
                $label = self::monthLabel($periodStart);
                $blockers[] = [
                    'code' => 'eldp_source_mismatch',
                    'message' => "Podklad za {$label} neodpovídá firmě, období nebo výsledku revize.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $months[$periodStart] = [
                'period_start' => $periodStart,
                'revision_id' => self::positiveInt($revision['id'] ?? null, 'revision.id'),
                'run_id' => self::positiveInt($revision['run_id'] ?? null, 'revision.run_id'),
                'input_snapshot_hash' => (string) $revision['input_snapshot_hash'],
                'result_snapshot_hash' => (string) $revision['result_snapshot_hash'],
                'input' => $input,
                'result' => $result,
            ];
        }

        return $months;
    }

    /**
     * @param array<string,array<string,mixed>> $months
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array{employee_id:int,start:string,end:?string}
     */
    private function resolveEmployment(
        array $months,
        int $employmentId,
        array &$blockers,
    ): array {
        $resolved = null;
        foreach ($months as $periodStart => $month) {
            $input = $month['input'];
            if (!is_array($input)) {
                continue;
            }
            $entry = $this->findEmploymentEntry($input, $employmentId);
            if ($entry === null) {
                $label = self::monthLabel((string) $periodStart);
                $blockers[] = [
                    'code' => 'eldp_employment_not_in_revision',
                    'message' => "Pracovní vztah není ve zmrazené revizi za {$label}.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            [$employeeId, $data] = $entry;
            $employment = $data['employment'];
            $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
            $end = $employment['end_date'] ?? null;
            if (!is_string($start) || !self::isDate($start)
                || ($end !== null && (!is_string($end) || !self::isDate($end)))
            ) {
                $label = self::monthLabel((string) $periodStart);
                $blockers[] = [
                    'code' => 'eldp_employment_dates_missing',
                    'message' => "Pracovní vztah nemá v revizi za {$label} zmrazené datum nástupu nebo skončení.",
                    'detail' => ['period_start' => $periodStart],
                ];
                continue;
            }
            $candidate = ['employee_id' => $employeeId, 'start' => $start, 'end' => $end];
            if ($resolved === null) {
                $resolved = $candidate;
                continue;
            }
            if ($resolved !== $candidate) {
                $label = self::monthLabel((string) $periodStart);
                $blockers[] = [
                    'code' => 'eldp_employment_dates_inconsistent',
                    'message' => "Revize za {$label} eviduje jiné trvání pracovního vztahu než dřívější měsíce; "
                        . 'evidenční list nesmí sečíst nesourodé podklady.',
                    'detail' => ['period_start' => $periodStart],
                ];
            }
        }
        if ($resolved === null) {
            throw EldpValidationException::blocked(
                $blockers === []
                    ? [[
                        'code' => 'eldp_employment_not_in_revision',
                        'message' => 'Pracovní vztah není v žádné zmrazené revizi vykazovaného roku.',
                        'detail' => [],
                    ]]
                    : $blockers,
            );
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $month
     * @param array{employee_id:int,start:string,end:string|null} $employment
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function monthLine(
        int $employmentId,
        array $month,
        array $employment,
        array &$blockers,
    ): ?array {
        $periodStart = (string) $month['period_start'];
        $label = self::monthLabel($periodStart);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        $input = $month['input'];
        $entry = is_array($input)
            ? $this->findEmploymentEntry($input, $employmentId)
            : null;
        if ($entry === null) {
            $blockers[] = [
                'code' => 'eldp_employment_not_in_revision',
                'message' => "Pracovní vztah není ve zmrazené revizi za {$label}.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        [$employeeId, $data] = $entry;
        $relation = $data['employment']['relation_type'] ?? null;
        if ($relation !== 'employment') {
            $blockers[] = [
                'code' => 'eldp_relationship_kind_unsupported',
                'message' => "Evidenční list zatím podporuje jen pracovní poměr; za {$label} je vztah typu "
                    . (is_string($relation) ? $relation : 'neznámý') . '.',
                'detail' => ['period_start' => $periodStart, 'relation_type' => $relation],
            ];

            return null;
        }
        $term = $data['term'] ?? null;
        $activityCode = is_array($term) ? ($term['activity_code'] ?? null) : null;
        $detailCode = is_array($term) ? ($term['jmhz_relationship_detail_code'] ?? null) : null;
        if (!is_string($activityCode)
            || preg_match('/^[1-9]$/D', $activityCode) !== 1
            || $detailCode !== '1'
        ) {
            $blockers[] = [
                'code' => 'eldp_activity_unsupported',
                'message' => "Za {$label} není druh činnosti 1–9 s bližším určením Žádné; "
                    . 'kód ELDP by se musel odvodit jinak, než modul umí.',
                'detail' => ['period_start' => $periodStart, 'activity_code' => $activityCode],
            ];

            return null;
        }
        $relationship = $this->socialRelationship(
            is_array($month['result'] ?? null) ? $month['result'] : [],
            $employeeId,
            $employmentId,
            $label,
            $periodStart,
            $blockers,
        );
        if ($relationship === null) {
            return null;
        }
        $uncapped = $relationship['assessment_base_minor_units'] ?? null;
        $capped = $relationship['capped_assessment_base_minor_units'] ?? null;
        if (!is_int($uncapped) || $uncapped < 0 || !is_int($capped) || $capped < 0) {
            $blockers[] = [
                'code' => 'eldp_assessment_base_missing',
                'message' => "Za {$label} chybí vyměřovací základ sociálního pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        if ($uncapped !== $capped) {
            $blockers[] = [
                'code' => 'eldp_capped_base_unsupported',
                'message' => "Za {$label} došlo ke krácení ročním maximem vyměřovacího základu; "
                    . 'zápis takového měsíce do evidenčního listu modul neumí doložit.',
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        if ($uncapped % 100 !== 0 || intdiv($uncapped, 100) > 9_999_999_999) {
            $blockers[] = [
                'code' => 'eldp_assessment_base_not_whole_czk',
                'message' => "Vyměřovací základ za {$label} není celé Kč v rozsahu datové věty.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        $insuranceFrom = max($periodStart, $employment['start']);
        $insuranceTo = $employment['end'] === null
            ? $periodEnd
            : min($periodEnd, $employment['end']);
        if ($insuranceFrom > $insuranceTo) {
            return null;
        }
        $absences = $data['absences'] ?? null;
        if (!is_array($absences) || !array_is_list($absences)) {
            $blockers[] = [
                'code' => 'eldp_absences_invalid',
                'message' => "Absence za {$label} nejsou ve zmrazené revizi seznam.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        /** @var list<array<string,mixed>> $absences */
        $excluded = $this->excludedPeriods->derive(
            $absences,
            $insuranceFrom,
            $insuranceTo,
            $label,
        );
        foreach ($excluded['blockers'] as $blocker) {
            $blockers[] = $blocker;
        }
        if ($excluded['blockers'] !== []) {
            return null;
        }
        $days = EldpExcludedPeriodDeriver::inclusiveDays($insuranceFrom, $insuranceTo);
        if ($excluded['total'] > $days) {
            $blockers[] = [
                'code' => 'eldp_excluded_days_exceed_period',
                'message' => "Vyloučené doby za {$label} přesahují dobu pojištění v měsíci.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'revision_id' => $month['revision_id'],
            'run_id' => $month['run_id'],
            'input_snapshot_hash' => $month['input_snapshot_hash'],
            'result_snapshot_hash' => $month['result_snapshot_hash'],
            'insurance_from' => $insuranceFrom,
            'insurance_to' => $insuranceTo,
            'insurance_days' => $days,
            'assessment_base_czk' => intdiv($uncapped, 100),
            'code' => $activityCode . '++',
            'excluded' => $excluded,
        ];
    }

    /**
     * Sekce evidenčního listu: souvislé měsíce se stejným kódem ELDP.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function sections(array $lines): array
    {
        $sections = [];
        $current = null;
        foreach ($lines as $line) {
            $continues = $current !== null
                && $current['code'] === $line['code']
                && (new \DateTimeImmutable($current['valid_to']))
                    ->modify('+1 day')->format('Y-m-d') === $line['insurance_from'];
            if (!$continues) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = [
                    'ordinal' => count($sections) + 1,
                    'code' => $line['code'],
                    'valid_from' => $line['insurance_from'],
                    'valid_to' => $line['insurance_to'],
                    'insurance_days' => 0,
                    'assessment_base_czk' => 0,
                    'excluded_days' => array_fill_keys(
                        EldpExcludedPeriodDeriver::COMPONENTS,
                        0,
                    ),
                    'excluded_days_total' => 0,
                    'deducted_days_total' => 0,
                    'excluded_days_provenance' => [],
                ];
            }
            /** @var array<string,mixed> $current */
            $current['valid_to'] = $line['insurance_to'];
            $current['insurance_days'] += $line['insurance_days'];
            $current['assessment_base_czk'] += $line['assessment_base_czk'];
            foreach ($line['excluded']['components'] as $key => $value) {
                $current['excluded_days'][$key] += $value;
            }
            $current['excluded_days_total'] += $line['excluded']['total'];
            foreach ($line['excluded']['provenance'] as $item) {
                $current['excluded_days_provenance'][] = $item + [
                    'period_start' => $line['period_start'],
                ];
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        foreach ($sections as $section) {
            $componentSum = array_sum($section['excluded_days']);
            if ($componentSum !== $section['excluded_days_total']) {
                throw new EldpValidationException(
                    'eldp_excluded_days_sum_mismatch',
                    'Součet vyloučených dob neodpovídá rozpadu podle § 16 odst. 4 '
                        . 'písm. a) a j) zákona č. 155/1995 Sb.',
                );
            }
            if ($section['excluded_days_total'] > $section['insurance_days']) {
                throw new EldpValidationException(
                    'eldp_excluded_days_exceed_period',
                    'Vyloučené doby sekce přesahují dobu pojištění.',
                );
            }
        }

        return $sections;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}|null
     */
    private function findEmploymentEntry(array $input, int $employmentId): ?array
    {
        $match = null;
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            return null;
        }
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            $employeeId = is_array($employee) ? ($employee['id'] ?? null) : null;
            $employments = $person['employments'] ?? null;
            if (!is_int($employeeId) || !is_array($employments)
                || !array_is_list($employments)
            ) {
                continue;
            }
            foreach ($employments as $entry) {
                if (!is_array($entry) || !is_array($entry['employment'] ?? null)) {
                    continue;
                }
                if (($entry['employment']['id'] ?? null) !== $employmentId) {
                    continue;
                }
                if ($match !== null
                    || ($entry['employment']['employee_id'] ?? null) !== $employeeId
                ) {
                    throw new EldpValidationException(
                        'eldp_employment_scope_mismatch',
                        'Pracovní vztah není ve zmrazené revizi jednoznačný.',
                    );
                }
                $match = [$employeeId, $entry];
            }
        }

        return $match;
    }

    /**
     * @param array<string,mixed> $result
     * @param list<array{code:string,message:string,detail:array<string,mixed>}> $blockers
     * @return array<string,mixed>|null
     */
    private function socialRelationship(
        array $result,
        int $employeeId,
        int $employmentId,
        string $label,
        string $periodStart,
        array &$blockers,
    ): ?array {
        $people = $result['people'] ?? null;
        $matched = null;
        if (is_array($people) && array_is_list($people)) {
            foreach ($people as $person) {
                if (is_array($person) && ($person['employee_id'] ?? null) === $employeeId) {
                    $matched = $person;
                }
            }
        }
        $statutory = is_array($matched) ? ($matched['statutory'] ?? null) : null;
        $social = is_array($statutory) ? ($statutory['social_insurance'] ?? null) : null;
        if (!is_array($social) || ($social['status'] ?? null) !== 'calculated') {
            $blockers[] = [
                'code' => 'eldp_social_not_calculated',
                'message' => "Za {$label} není vypočtené sociální pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }
        $relationships = $social['relationships'] ?? null;
        $match = null;
        if (is_array($relationships) && array_is_list($relationships)) {
            foreach ($relationships as $relationship) {
                if (is_array($relationship)
                    && ($relationship['relationship_id'] ?? null) === "employment:{$employmentId}"
                ) {
                    if ($match !== null) {
                        $blockers[] = [
                            'code' => 'eldp_social_relationship_ambiguous',
                            'message' => "Výsledek za {$label} obsahuje pracovní vztah vícekrát.",
                            'detail' => ['period_start' => $periodStart],
                        ];

                        return null;
                    }
                    $match = $relationship;
                }
            }
        }
        $participation = is_array($match) ? ($match['participation'] ?? null) : null;
        if (!is_array($match)
            || ($match['kind'] ?? null) !== 'employment'
            || !is_array($participation)
            || ($participation['status'] ?? null) !== 'participates'
        ) {
            $blockers[] = [
                'code' => 'eldp_social_participation_missing',
                'message' => "Za {$label} není doložena účast pracovního vztahu na sociálním pojištění.",
                'detail' => ['period_start' => $periodStart],
            ];

            return null;
        }

        return $match;
    }

    /** @return list<string> */
    private static function requiredMonths(int $year, string $start, ?string $end): array
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $periodStart = sprintf('%04d-%02d-01', $year, $month);
            $periodEnd = (new \DateTimeImmutable($periodStart))
                ->modify('last day of this month')->format('Y-m-d');
            $from = max($periodStart, $start);
            $to = $end === null ? $periodEnd : min($periodEnd, $end);
            if ($from <= $to) {
                $months[] = $periodStart;
            }
        }

        return $months;
    }

    private static function monthLabel(string $periodStart): string
    {
        $month = (int) substr($periodStart, 5, 2);
        $year = substr($periodStart, 0, 4);

        return (self::MONTH_NAMES[$month] ?? $periodStart) . ' ' . $year;
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $label): array
    {
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            throw new EldpValidationException(
                'eldp_source_hash_mismatch',
                "Otisk {$label} snapshotu evidenčního listu nesouhlasí.",
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)
            || CanonicalJson::encode($decoded) !== $json
        ) {
            throw new EldpValidationException(
                'eldp_source_invalid',
                "Snapshot {$label} evidenčního listu není kanonický objekt.",
            );
        }

        return $decoded;
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

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new EldpValidationException(
                'eldp_source_invalid',
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }
}
