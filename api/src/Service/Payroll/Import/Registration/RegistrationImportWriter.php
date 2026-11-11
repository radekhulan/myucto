<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentTerminationRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollHealthInsurerWriter;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Zápis jedné naplánované věty ({@see RegistrationImportPlanner}) do evidence.
 *
 * Každá skupina jde TOUTÉŽ cestou jako karta, na které se údaj běžně edituje
 * (stejně jako {@see \MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1MasterDataWriter}):
 * založení osoby, vztah, podmínky, identita, karta osoby, zákonná evidence,
 * identifikátory ČSSZ. Vlastní SQL do cizích tabulek tu není.
 *
 * Věta je jedna transakce. Hlavní krok (založení, ukončení) musí projít, jinak
 * se nezapíše nic. Doplňkové skupiny (adresa, podmínky, pojišťovna,
 * identifikátory…) mají vlastní savepoint: když jedna neprojde kontrolou karty,
 * zbytek se zapíše a důvod se vrátí ve zprávě. Opakovaný import pak dopíše
 * jen to, co chybí.
 */
final class RegistrationImportWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly LicenseCapacityGate $license,
        private readonly PayrollPersonCreateService $people,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollHealthInsurerWriter $healthInsurers,
        private readonly PayrollEmploymentTerminationRepository $terminationRecords,
    ) {}

    /**
     * @param array<string,mixed> $plan
     * @return array{status:string,message:?string,employee_id:?int,employment_id:?int,operations:list<string>}
     */
    public function apply(
        int $supplierId,
        string $environment,
        array $plan,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        /** @var RegistrationRecord $record */
        $record = $plan['_record'];
        /** @var array<string,mixed> $steps */
        $steps = $plan['_steps'];
        $employeeId = $plan['_employee_id'];
        $employmentId = $plan['_employment_id'];
        $operations = [];
        $notes = [];
        $officeId ??= $this->lookup->defaultOfficeId($supplierId);

        $sentence = function () use (
            $supplierId,
            $environment,
            $plan,
            $record,
            $steps,
            $officeId,
            $userId,
            $ip,
            $userAgent,
            &$employeeId,
            &$employmentId,
            &$operations,
            &$notes,
        ): void {
            $employeeId = $plan['_employee_id'];
            $employmentId = $plan['_employment_id'];
            $operations = [];
            $notes = [];
            if (is_array($steps['create_person'])) {
                $input = $steps['create_person'];
                $workload = $input['workload'] ?? null;
                unset($input['workload']);
                if (is_array($workload)) {
                    $input['weekly_hours'] = $workload['weekly_hours'];
                }
                $person = $this->license->mutatePayrollEmployees(
                    fn (): array => $this->people->create(
                        $supplierId,
                        $input + ['office_id' => $officeId],
                        $userId,
                        $ip,
                        $userAgent,
                    ),
                );
                $employeeId = (int) $person['id'];
                $employmentId = $this->lookup->employments($supplierId, $employeeId)[0]['id']
                    ?? throw new \LogicException('Nově založená osoba nemá pracovní vztah.');
                $operations[] = 'person_created';
            } elseif (is_array($steps['create_employment'])) {
                $employmentId = $this->createEmployment(
                    $supplierId,
                    (int) $employeeId,
                    $steps['create_employment'],
                    $officeId,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $operations[] = 'employment_created';
            }
            $employeeId = $employeeId === null ? null : (int) $employeeId;
            $employmentId = $employmentId === null ? null : (int) $employmentId;
            $decisive = $record->decisiveDate() ?? date('Y-m-d');

            // Nástup jde první: identifikátory i podmínky se pak zapisují už
            // k posunutému začátku vztahu.
            if (is_array($steps['correct_start'] ?? null) && $employmentId !== null) {
                $this->employments->correctStartEarlier(
                    $supplierId,
                    $employmentId,
                    (string) $steps['correct_start']['to'],
                    null,
                    match (true) {
                        $record->isCsszExport() => 'Nástup podle exportu zaměstnanců ČSSZ a měsíčních hlášení v téže dávce.',
                        $record->isJmhzDerived() => 'Nástup podle importovaných měsíčních hlášení JMHZ.',
                        default => 'Nástup podle importované registrace ČSSZ.',
                    },
                    $userId,
                    $ip,
                    $userAgent,
                );
                $operations[] = 'start_corrected';
            }
            // Nástup, který import právě zapsal, je buď doložený (datum nástupu,
            // export ČSSZ), nebo jen dolní odhad z nejstaršího hlášeného měsíce.
            // Odhad se eviduje trvale — hlídá podle něj mezeru roku přechodu
            // {@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage::estimatedStartGaps()}.
            if ($employmentId !== null && array_intersect(
                ['person_created', 'employment_created', 'start_corrected'],
                $operations,
            ) !== []) {
                $this->employments->markStartEstimated(
                    $supplierId,
                    $employmentId,
                    $record->startEstimated
                        || ($record->derivedStart !== null && CsszExportStartResolver::needsCheck($record->derivedStart)),
                );
            }

            if (is_array($steps['name'] ?? null) && $employeeId !== null) {
                $this->optional('Jméno', $notes, $operations, 'name', fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    [],
                    null,
                    $userId,
                    $ip,
                    $userAgent,
                    [],
                    $steps['name'],
                ));
            }
            if ($steps['terms'] !== [] && $employmentId !== null) {
                $this->optional('Podmínky vztahu', $notes, $operations, 'terms', fn () => $this->writeTerms(
                    $supplierId,
                    $employmentId,
                    $steps['terms'],
                    $decisive,
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if ($steps['identity_facts'] !== [] && $employeeId !== null) {
                $this->optional('Údaje o narození a občanství', $notes, $operations, 'identity_facts', fn () => $this->writeIdentityFacts(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['identity_facts'],
                ));
            }
            if (($steps['addresses'] !== [] || $steps['birth_surname'] !== null) && $employeeId !== null) {
                $this->optional('Adresa a rodné příjmení', $notes, $operations, 'address', fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['addresses'],
                    $steps['birth_surname'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            $personIdentifiers = array_filter([
                'birth_number' => $steps['birth_number'] ?? null,
                'ecp' => $steps['ecp'] ?? null,
                'vcp' => $steps['vcp'] ?? null,
                'foreign_tax_identifier' => $steps['foreign_tax_identifier'] ?? null,
            ], 'is_string');
            if ($personIdentifiers !== [] && $employeeId !== null) {
                $label = match (true) {
                    isset($personIdentifiers['ecp']) => 'Evidenční číslo pojištěnce',
                    isset($personIdentifiers['birth_number']) => 'Rodné číslo',
                    isset($personIdentifiers['vcp']) => 'Variabilní číslo pojištěnce',
                    default => 'Zahraniční daňový identifikátor',
                };
                $operation = match (true) {
                    isset($personIdentifiers['ecp']) => 'ecp',
                    isset($personIdentifiers['birth_number']) => 'birth_number',
                    isset($personIdentifiers['vcp']) => 'vcp',
                    default => 'foreign_tax_identifier',
                };
                $this->optional($label, $notes, $operations, $operation, fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    [],
                    null,
                    $userId,
                    $ip,
                    $userAgent,
                    $personIdentifiers,
                ));
            }
            if (is_array($steps['tax_residence'] ?? null) && $employeeId !== null) {
                $this->optional('Daňová rezidence', $notes, $operations, 'tax_residence', fn () => $this->writeTaxResidence(
                    $supplierId,
                    $employeeId,
                    $steps['tax_residence'],
                    'regzec:' . substr((string) $plan['_file_sha256'], 0, 16) . ':' . $record->sequence,
                    $userId,
                ));
            }
            if (is_array($steps['pension'] ?? null) && $employeeId !== null) {
                $this->optional('Pobíraný důchod', $notes, $operations, 'pension', fn () => $this->writePension(
                    $supplierId,
                    $employeeId,
                    $steps['pension'],
                    'regzec:' . substr((string) $plan['_file_sha256'], 0, 16) . ':' . $record->sequence,
                    $userId,
                ));
            }
            if (is_array($steps['health_insurer']) && $employeeId !== null) {
                $this->optional('Zdravotní pojišťovna', $notes, $operations, 'health_insurer', fn () => $this->writeHealthInsurer(
                    $supplierId,
                    $employeeId,
                    (string) $steps['health_insurer']['code'],
                    (string) $steps['health_insurer']['on'],
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_string($steps['activate_on']) && $employmentId !== null) {
                $this->optional('Nástup', $notes, $operations, 'activated', fn () => $this->transition(
                    $supplierId,
                    $employmentId,
                    'active',
                    $steps['activate_on'],
                    match (true) {
                        $record->isCsszExport() => 'Nástup podle exportu zaměstnanců ČSSZ a měsíčního hlášení v téže dávce.',
                        $record->isJmhzDerived() => 'Nástup podle importovaných měsíčních hlášení JMHZ.',
                        default => 'Nástup podle importované registrace ČSSZ.',
                    },
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['terminate']) && $employmentId !== null) {
                $target = (string) $steps['terminate']['target'];
                $this->transition(
                    $supplierId,
                    $employmentId,
                    $target,
                    (string) $steps['terminate']['on'],
                    match (true) {
                        $target !== 'ended' => 'Nenastoupení podle importované registrace ČSSZ.',
                        $record->isCsszExport() => 'Skončení podle konce pojistného vztahu v exportu zaměstnanců ČSSZ.',
                        default => 'Skončení podle importovaného odhlášení ČSSZ.',
                    },
                    $userId,
                    $ip,
                    $userAgent,
                );
                $operations[] = $target === 'ended' ? 'terminated' : 'no_show';
            }
            if (($steps['termination_reason'] ?? null) === 'death' && $employmentId !== null) {
                $this->optional('Způsob skončení', $notes, $operations, 'termination_reason', fn () => $this->terminationRecords->save(
                    $supplierId,
                    $employmentId,
                    [
                        'termination_method' => 'death',
                        'legal_ground' => 'none',
                        'employee_stated_reason' => null,
                        'severance_multiple_override' => null,
                        'severance_override_reason' => null,
                        'working_time_account_applies' => false,
                        'other_income_from' => null,
                        'other_payer_applies_protected_amount' => false,
                    ],
                    null,
                    $userId,
                ));
            }
            $identifiers = $steps['identifiers'];
            if (($identifiers['person'] !== null || $identifiers['employment'] !== null) && $employmentId !== null) {
                $this->optional('Identifikátory ČSSZ', $notes, $operations, 'identifiers', fn () => $this->writeIdentifiers(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $record,
                    (string) $plan['_file_sha256'],
                    $identifiers['person'],
                    $identifiers['employment'],
                    $userId,
                ));
            }
            // Profil A1 až nakonec: návrh formuláře se skládá z kmenových dat,
            // která předchozí kroky právě doplnily.
            if (is_array($steps['a1_profile'] ?? null) && $employmentId !== null) {
                $this->optional('Profil registrace A1', $notes, $operations, 'a1_profile', fn () => $this->writeA1Profile(
                    $supplierId,
                    $employmentId,
                    $steps['a1_profile'],
                    $userId,
                ));
            }
        };
        // Odvolanou transakci (1020/1213) zopakuje celou: rollback vrátil založení osoby
        // i všechny skupiny věty a každá skupina si stav čte znovu těsně před zápisem.
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->atomic('registration_import_record', $sentence));

        return [
            'status' => 'applied',
            'message' => $notes === [] ? null : 'Zapsáno, ale část údajů se zapsat nepodařila: ' . implode(' ', $notes),
            'employee_id' => $employeeId === null ? null : (int) $employeeId,
            'employment_id' => $employmentId === null ? null : (int) $employmentId,
            'operations' => $operations,
        ];
    }

    /**
     * Doplňkové skupiny věty nad osobou a vztahem, které už v evidenci jsou,
     * bez hlavního kroku (založení, ukončení). Volá ho import docházky, když
     * šablona nese údaje pro registraci ČSSZ: zapisuje se stejnými cestami
     * a se stejnými savepointy jako věta registrace, takže co jedna skupina
     * nezapíše, vrátí se v `notes` a zbytek projde.
     *
     * Volající předává jen to, co se má zapsat; adresu, občanství a podmínky
     * tahle cesta přepíše, proto je u existující osoby předem zúží na
     * chybějící údaje.
     *
     * @param array{
     *   terms?:array<string,string>,
     *   identity_facts?:array<string,string>,
     *   addresses?:array<string,array<string,string>>,
     *   tax_residence?:array{residence:string,country_code:string,identifier:?string},
     *   a1_profile?:array<string,mixed>
     * } $steps
     * @return array{operations:list<string>,notes:list<string>}
     */
    public function supplement(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $decisive,
        array $steps,
        string $reference,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $operations = [];
        $notes = [];
        $this->atomic('registration_import_supplement', function () use (
            $supplierId,
            $employeeId,
            $employmentId,
            $decisive,
            $steps,
            $reference,
            $userId,
            $ip,
            $userAgent,
            &$operations,
            &$notes,
        ): void {
            if (($steps['terms'] ?? []) !== []) {
                $this->optional('Podmínky vztahu', $notes, $operations, 'terms', fn () => $this->writeTerms(
                    $supplierId,
                    $employmentId,
                    $steps['terms'],
                    $decisive,
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (($steps['identity_facts'] ?? []) !== []) {
                $this->optional('Státní občanství', $notes, $operations, 'identity_facts', fn () => $this->writeIdentityFacts(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['identity_facts'],
                ));
            }
            if (($steps['addresses'] ?? []) !== []) {
                $this->optional('Adresa', $notes, $operations, 'address', fn () => $this->writePersonCard(
                    $supplierId,
                    $employeeId,
                    $decisive,
                    $steps['addresses'],
                    null,
                    $userId,
                    $ip,
                    $userAgent,
                ));
            }
            if (is_array($steps['tax_residence'] ?? null)) {
                $this->optional('Daňová rezidence', $notes, $operations, 'tax_residence', fn () => $this->writeTaxResidence(
                    $supplierId,
                    $employeeId,
                    $steps['tax_residence'],
                    $reference,
                    $userId,
                ));
            }
            // Profil A1 až nakonec: návrh se skládá z kmenových dat výše.
            if (($steps['a1_profile'] ?? []) !== []) {
                $this->optional('Profil registrace A1', $notes, $operations, 'a1_profile', fn () => $this->writeA1Profile(
                    $supplierId,
                    $employmentId,
                    $steps['a1_profile'],
                    $userId,
                ));
            }
        });

        return ['operations' => $operations, 'notes' => $notes];
    }

    /** @param array{relation_type:string,planned_start_on:string,workload?:?array{workload_basis_points:int,weekly_hours:string}} $input */
    private function createEmployment(
        int $supplierId,
        int $employeeId,
        array $input,
        ?int $officeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): int {
        $hasPrimary = false;
        foreach ($this->lookup->employments($supplierId, $employeeId) as $row) {
            if ($row['is_primary'] && in_array($row['status'], ['planned', 'preregistered', 'active', 'suspended'], true)) {
                $hasPrimary = true;
            }
        }
        [$activityCode, $detailCode] = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($input['relation_type']);
        $created = $this->employments->create(
            $supplierId,
            $employeeId,
            $this->employmentValidator->create([
                'code' => '',
                'relation_type' => $input['relation_type'],
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => $officeId,
                    'effective_from' => $input['planned_start_on'],
                    'planned_start_on' => $input['planned_start_on'],
                    'contract_signed_on' => null,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => null,
                    'weekly_hours' => $input['workload']['weekly_hours'] ?? '40.00',
                    'workload_basis_points' => $input['workload']['workload_basis_points'] ?? 10_000,
                    'work_place' => null,
                    'regular_workplace' => null,
                    'jmhz_workplace_municipality_code' => null,
                    'jmhz_workplace_country_code' => null,
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => null,
                    'activity_code' => $activityCode,
                    'jmhz_relationship_detail_code' => $detailCode,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => !$hasPrimary,
                    'change_reason' => 'Založeno importem registrace ČSSZ.',
                ],
            ]),
            $userId,
            $ip,
            $userAgent,
        );

        return (int) $created['id'];
    }

    /**
     * Podmínky z věty. Verze, která začíná před měsícem rozhodného dne, se
     * nepřepisuje zpětně: změna z věty platí od prvního dne toho měsíce jako
     * nová verze (stejně jako u měsíčního hlášení JMHZ). Přepis na místě zůstává
     * jen u verze, která začíná v témže měsíci nebo později - typicky vztah,
     * který import právě založil.
     *
     * @param array<string,string> $changes
     */
    private function writeTerms(
        int $supplierId,
        int $employmentId,
        array $changes,
        string $decisive,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek, do které by šlo zapsat.');
        $body = self::termsBody($current);
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        if (($body['jmhz_workplace_municipality_code'] ?? null) === null) {
            $body['jmhz_workplace_country_code'] = null;
        }
        $monthStart = substr($decisive, 0, 7) . '-01';
        $correct = (string) $current['effective_from'] >= $monthStart;
        $body['effective_from'] = $correct ? (string) $current['effective_from'] : $monthStart;
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');

        $terms = $this->employmentValidator->terms(
            $body,
            $this->employments->currentCzIscoCode($supplierId, $employmentId),
            $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
            $this->employments->currentRelationType($supplierId, $employmentId),
        );
        if ($correct) {
            $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, $ip, $userAgent);
        } else {
            $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, $ip, $userAgent);
        }
    }

    /**
     * Uložená verze podmínek jako vstup validátoru — `correctTerms()` bere
     * cílový stav, vynechané pole by se vyprázdnilo. Tvar je shodný
     * s PayrollRegistrationA1MasterDataWriter::termsBody(). Veřejné, aby ho
     * import měsíčních hlášení volal místo vlastní kopie.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function termsBody(
        array $current,
        string $changeReason = 'Údaje z importované registrace ČSSZ.',
    ): array {
        $keys = [
            'office_id', 'contract_signed_on', 'planned_start_on', 'actual_start_on',
            'fixed_term_end_on',
            'weekly_hours', 'workload_basis_points', 'work_place',
            'regular_workplace', 'jmhz_workplace_municipality_code', 'jmhz_workplace_country_code',
            'jmhz_external_codebook_overlay_key', 'jmhz_external_codebook_manifest_sha256',
            'jmhz_apz_contribution_status', 'jmhz_apz_instrument_code',
            'jmhz_functional_benefits_status', 'jmhz_temporary_assignment_status',
            'jmhz_orchard_discount_eligible', 'jmhz_specific_legal_fact_applies',
            'jmhz_ozp_employment_support_applies', 'jmhz_deep_mining_work_applies',
            'cz_isco_code', 'activity_code', 'jmhz_relationship_detail_code',
            'social_insurance_participation', 'health_insurance_participation',
            'health_association_member', 'tax_regime',
            'other_withholding_eligibility', 'foreign_legislation_country_code',
            'a1_certificate_until', 'risky_work', 'social_employer_rate_category',
            'social_employer_rate_category_evidence', 'social_part_time_discount_reason',
            'social_part_time_discount_evidence', 'social_part_time_discount_notified_on',
            'is_primary',
            'jmhz_assignment_user_kind', 'jmhz_assignment_user_ico',
            'jmhz_assignment_user_country_code', 'jmhz_assignment_user_foreign_id',
            'jmhz_assignment_user_name', 'jmhz_risk_categorization_code',
        ];
        $body = [];
        foreach ([...$keys, ...\MyInvoice\Service\Payroll\PayrollEmploymentTermsBody::REMUNERATION_KEYS] as $key) {
            $body[$key] = $current[$key] ?? null;
        }
        $override = $current['leave_entitlement_weeks_override'] ?? null;
        $body['leave_entitlement_weeks_override'] = $override === null ? null : (int) $override;
        $probable = $current['probable_hourly_earning_minor'] ?? null;
        $body['probable_hourly_earning_minor'] = $probable === null ? null : (int) $probable;
        $body['probable_earning_rationale'] = $current['probable_earning_rationale'] ?? null;
        $body['change_reason'] = $changeReason;

        return $body;
    }

    /** @param array<string,string> $facts */
    private function writeIdentityFacts(int $supplierId, int $employeeId, string $onDate, array $facts): void
    {
        $identity = $this->registrations->identityAt($supplierId, $employeeId, min($onDate, date('Y-m-d')))
            ?? $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'))
            ?? throw new \DomainException(
                'Osoba nemá evidovanou identitu, do které by šly údaje o narození zapsat. Doplňte je na kartě osoby.',
            );
        $merged = [];
        foreach ([
            'title_prefix', 'title_suffix', 'birth_date', 'birth_place',
            'birth_country_code', 'citizenship_country_code', 'sex',
        ] as $field) {
            $value = $facts[$field] ?? $identity[$field] ?? null;
            $merged[$field] = $value === null ? null : (string) $value;
        }
        $this->identities->saveIdentityFacts(
            $supplierId,
            $employeeId,
            (int) $identity['id'],
            (int) $identity['row_version'],
            $merged,
        );
    }

    /**
     * Rezidence platí od nejstaršího nástupu osoby: věta dokládá stav, ve
     * kterém osoba ve vztahu je. Neověřené řádky (výchozí doplnění) nahradí,
     * ověřené plánovač nepouští.
     *
     * @param array{residence:string,country_code:string,identifier:?string} $residence
     */
    private function writeTaxResidence(
        int $supplierId,
        int $employeeId,
        array $residence,
        string $reference,
        ?int $userId,
    ): void {
        $today = date('Y-m-d');
        $view = $this->statutory->editorView($supplierId, $employeeId, $today)
            ?? throw new \DomainException('Zákonná evidence zaměstnance nebyla nalezena.');
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        foreach ($sections['tax_residences'] ?? [] as $row) {
            if (($row['residence'] ?? null) !== 'unverified') {
                return;
            }
        }
        $from = null;
        foreach ($this->lookup->employments($supplierId, $employeeId) as $employment) {
            $start = $employment['actual_start_date'] ?? $employment['start_date'];
            if (is_string($start) && ($from === null || $start < $from)) {
                $from = $start;
            }
        }
        $sections['tax_residences'] = [[
            'residence' => $residence['residence'],
            'country_code' => $residence['country_code'],
            'evidence_reference' => $reference,
            'effective_from' => $from ?? $today,
            'effective_to' => null,
            'evidence_note' => null,
        ]];
        $this->statutory->save($supplierId, $employeeId, ['sections' => $sections], $today, $userId, null, null);
    }

    /**
     * Pobíraný důchod z věty do zákonné evidence osoby — jen do prázdné řady;
     * evidenci, kterou mezitím někdo vyplnil, import nepřepisuje.
     *
     * @param array{pension_type_code:string,early_retirement:bool,reduced_retirement_age:bool,effective_from:string} $pension
     */
    private function writePension(
        int $supplierId,
        int $employeeId,
        array $pension,
        string $reference,
        ?int $userId,
    ): void {
        $today = date('Y-m-d');
        $view = $this->statutory->editorView($supplierId, $employeeId, $today)
            ?? throw new \DomainException('Zákonná evidence zaměstnance nebyla nalezena.');
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        if (($sections['social_pensions'] ?? []) !== []) {
            return;
        }
        $sections['social_pensions'] = [[
            'pension_type_code' => $pension['pension_type_code'],
            'early_retirement' => $pension['early_retirement'] ? '1' : '0',
            'reduced_retirement_age' => $pension['reduced_retirement_age'] ? '1' : '0',
            'evidence_reference' => $reference,
            'effective_from' => $pension['effective_from'],
            'effective_to' => null,
            'evidence_note' => null,
        ]];
        $this->statutory->save($supplierId, $employeeId, ['sections' => $sections], $today, $userId, null, null);
    }

    /**
     * Údaje věty do profilu registrace A1: nad uloženým profilem (nebo návrhem
     * z kmenových dat) se přepíšou jen hodnoty, které věta uvádí.
     *
     * @param array<string,mixed> $overlay
     */
    private function writeA1Profile(int $supplierId, int $employmentId, array $overlay, ?int $userId): void
    {
        $view = $this->identities->a1ProfileView($supplierId, $employmentId);
        if (($view['draft']['submitted'] ?? false) === true) {
            return;
        }
        $base = is_array($view['profile']) ? $view['profile'] : (array) ($view['draft']['suggested'] ?? []);
        foreach (['status', 'reference_hash', 'created_at', 'created', 'problems'] as $meta) {
            unset($base[$meta]);
        }
        $input = self::overlay($base, $overlay);
        $input['row_version'] = (int) ($view['draft']['row_version'] ?? 0);
        $input['effective_on'] = (string) $view['draft']['effective_on'];
        $this->identities->saveA1Profile($supplierId, $employmentId, $input, $userId);
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    private static function overlay(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? self::overlay($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /**
     * Adresy a rodné příjmení jdou jedním uložením karty osoby — je to jeden
     * optimistický zámek. Adresa se historizuje stejně jako v zápisu z A1:
     * verze pokrývající rozhodný den se uzavře a od něj platí nová.
     *
     * Skutečné jméno přepíše zástupné na místě (není to změna jména, ale
     * doplnění). Zástupná verze vznikla k odhadnutému nástupu, takže se
     * nejstarší verze zároveň protáhne k nástupu, který věta mezitím doložila.
     *
     * @param array<string,array<string,string>> $addresses
     * @param array<string,string> $identifierValues typ => hodnota; typ, který karta už vede, se nepřepisuje
     * @param array{identity_id:int,first_name:string,last_name:string,full_name:string}|null $name
     */
    private function writePersonCard(
        int $supplierId,
        int $employeeId,
        string $onDate,
        array $addresses,
        ?string $birthSurname,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        array $identifierValues = [],
        ?array $name = null,
    ): void {
        $current = $this->profiles->get($supplierId, $employeeId)
            ?? throw new \DomainException('Osobní karta zaměstnance nebyla nalezena.');
        foreach ($current['identifiers'] as $existing) {
            unset($identifierValues[(string) ($existing['identifier_type'] ?? '')]);
        }
        $identifiers = [];
        foreach ($identifierValues as $type => $value) {
            $identifiers[] = ['id' => null, 'identifier_type' => $type, 'value' => $value];
        }
        $rows = [];
        foreach ($addresses as $type => $address) {
            $rows = array_merge($rows, $this->addressRows(
                $current['addresses'],
                $type,
                $address['on'] ?? $onDate,
                $address,
            ));
        }
        $identity = [];
        if ($birthSurname !== null) {
            $version = $this->covering($current['identity_history'], min($onDate, date('Y-m-d')), null)
                ?? ($current['identity_history'][0] ?? null);
            if ($version !== null) {
                $identity[] = [
                    'id' => $version['id'],
                    'full_name' => $version['full_name'],
                    'first_name' => $version['first_name'],
                    'last_name' => $version['last_name'],
                    'birth_surname' => $birthSurname,
                    'effective_from' => $version['effective_from'],
                    'effective_to' => $version['effective_to'],
                ];
            }
        }
        if ($name !== null) {
            $version = null;
            $earliest = null;
            foreach ($current['identity_history'] as $row) {
                if ((int) $row['id'] === $name['identity_id']) {
                    $version = $row;
                }
                if ($earliest === null || (string) $row['effective_from'] < (string) $earliest['effective_from']) {
                    $earliest = $row;
                }
            }
            if ($version === null) {
                throw new \DomainException('Verze identity se zástupným jménem se mezitím změnila. Obnovte náhled.');
            }
            $from = (string) $version['effective_from'];
            if ((int) $earliest['id'] === (int) $version['id']) {
                foreach ($this->lookup->employments($supplierId, $employeeId) as $employment) {
                    $start = $employment['actual_start_date'] ?? $employment['start_date'];
                    if (is_string($start) && $start < $from) {
                        $from = $start;
                    }
                }
            }
            $identity = [[
                'id' => $version['id'],
                'full_name' => $name['full_name'],
                'first_name' => $name['first_name'],
                'last_name' => $name['last_name'],
                'effective_from' => $from,
                'effective_to' => $version['effective_to'],
            ]];
        }
        if ($rows === [] && $identity === [] && $identifiers === []) {
            return;
        }

        $payload = [
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? min($onDate, date('Y-m-d')),
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $rows,
            'contacts' => [],
            'identifiers' => $identifiers,
            'accounts' => [],
        ];
        $this->profiles->save(
            $supplierId,
            $employeeId,
            $this->profileValidator->validate($payload),
            $current['row_version'],
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * @param list<array<string,mixed>> $stored
     * @param array<string,string> $address
     * @return list<array<string,mixed>>
     */
    private function addressRows(array $stored, string $type, string $onDate, array $address): array
    {
        $values = [
            'address_type' => $type,
            'street_line' => $address['street_line'],
            'city' => $address['city'],
            'postal_code' => $address['postal_code'],
            'country_code' => $address['country_code'],
        ];
        $covering = $this->covering($stored, $onDate, $type);
        if ($covering === null) {
            foreach ($stored as $row) {
                if (($row['address_type'] ?? null) === $type && (string) $row['effective_from'] > $onDate) {
                    throw new \DomainException(
                        'Osoba má evidovanou adresu platnou až od pozdějšího data, novou verzi před ní import '
                        . 'nezaloží. Opravte adresu na kartě osoby.',
                    );
                }
            }

            return [$values + ['id' => null, 'effective_from' => $onDate, 'effective_to' => null]];
        }
        if ((string) $covering['effective_from'] === $onDate) {
            return [$values + [
                'id' => $covering['id'],
                'effective_from' => $onDate,
                'effective_to' => $covering['effective_to'],
            ]];
        }

        return [
            [
                'id' => $covering['id'],
                'address_type' => $type,
                'effective_from' => $covering['effective_from'],
                'effective_to' => $this->previousDay($onDate),
            ],
            $values + ['id' => null, 'effective_from' => $onDate, 'effective_to' => $covering['effective_to']],
        ];
    }

    /**
     * Změna pojišťovny je nová verze zákonné evidence od prvního dne měsíce
     * (evidence se vede po celých měsících). Starší verze se uzavře koncem
     * předchozího měsíce, takže historie odvodů zůstává, jak byla.
     */
    private function writeHealthInsurer(
        int $supplierId,
        int $employeeId,
        string $insurerCode,
        string $onDate,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->healthInsurers->assign($supplierId, $employeeId, $insurerCode, $onDate, $userId, $ip, $userAgent);
    }

    private function transition(
        int $supplierId,
        int $employmentId,
        string $target,
        string $effectiveOn,
        string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $this->employments->transition(
            $supplierId,
            $employmentId,
            $target,
            (int) $row['row_version'],
            $effectiveOn,
            $note,
            $userId,
            $ip,
            $userAgent,
        );
    }

    /**
     * Identifikátory platí od nástupu vztahu. Ani den vyhotovení věty, ani její
     * účinnost (`employee@fro` u změnové věty A3) se nepoužívá: OIČ i ID PPV
     * označují vztah od jeho začátku a měsíce mezi nástupem a vyhotovením věty
     * by jinak zůstaly v měsíčním hlášení bez identifikátorů.
     */
    private function writeIdentifiers(
        int $supplierId,
        string $environment,
        int $employmentId,
        RegistrationRecord $record,
        string $fileSha256,
        ?string $personIdentifier,
        ?string $employmentIdentifier,
        ?int $userId,
    ): void {
        $row = $this->lookup->employment($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah v téhle firmě neexistuje.');
        $validFrom = (string) ($row['start_date'] ?? $record->decisiveDate() ?? date('Y-m-d'));
        if ($row['end_date'] !== null && $validFrom > $row['end_date']) {
            $validFrom = (string) $row['end_date'];
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            $environment,
            $personIdentifier,
            $employmentIdentifier,
            $validFrom,
            'jmhz-registration-import:' . $fileSha256 . ':' . $record->sequence,
            true,
            $userId,
            false,
            // Export zaměstnanců z ePortálu ČSSZ je Seznam zaměstnanců, ze
            // kterého smí odhláška A2 převzatého zaměstnance vycházet i bez
            // dohlášení A3; ostatní importy jsou opis z cizího programu.
            match (true) {
                $record->isCsszExport() => 'cssz_employee_export',
                $record->isJmhzDerived() => 'jmhz_import',
                default => 'registration_import',
            },
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function covering(array $rows, string $onDate, ?string $addressType): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($addressType !== null && ($row['address_type'] ?? null) !== $addressType) {
                continue;
            }
            $from = (string) $row['effective_from'];
            $to = ($row['effective_to'] ?? null) === null ? null : (string) $row['effective_to'];
            if ($from > $onDate || ($to !== null && $to < $onDate)) {
                continue;
            }
            if ($best === null || $from > (string) $best['effective_from']) {
                $best = $row;
            }
        }

        return $best;
    }

    private function previousDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param list<string> $notes
     * @param list<string> $operations
     * @param callable():void $work
     */
    private function optional(string $label, array &$notes, array &$operations, string $operation, callable $work): void
    {
        try {
            $this->atomic('registration_import_step', $work);
            $operations[] = $operation;
        } catch (\Exception $e) {
            // Odvolaná transakce nevrátila jen tuhle skupinu, ale celou větu včetně hlavního
            // kroku: další skupiny by se zapsaly v autocommitu k osobě, která nevznikla.
            if (TransactionFailure::aborted($e) || !$this->db->pdo()->inTransaction()) {
                throw $e;
            }
            $notes[] = "{$label}: {$e->getMessage()}";
        }
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function atomic(string $name, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $name);
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e) || !$pdo->inTransaction()) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
                throw $e;
            }
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            } catch (\PDOException) {
                // Savepoint zmizel s transakcí, kterou MariaDB odvolala pod přebalenou chybou.
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
