<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Syntetická firma o N osobách pro měření a ověření mzdového běhu.
 *
 * Dvě vlastnosti, na kterých testy stojí:
 *
 * 1. Tvar dat se s indexem osoby DETERMINISTICKY liší (druhý pracovní vztah,
 *    absence, docházka, srážky, výplatní pravidla, zákonná evidence, exekuce).
 *    Kdyby všechny osoby vypadaly stejně, prohozené skupiny by dávkové načtení
 *    protáhlo bez povšimnutí.
 * 2. Všechna ID i časová razítka jsou PŘIPNUTÁ, ne z auto_increment/NOW().
 *    Kanonický JSON snapshotu je pak mezi běhy bajtově shodný (až na id firmy),
 *    takže se dá porovnat otisk před optimalizací a po ní.
 */
final class PayrollRunScaleFixture
{
    public const PERIOD_START = '2026-06-01';
    public const PERIOD_END = '2026-06-30';
    public const PAYMENT_DATE = '2026-07-15';

    /**
     * Základ připnutých ID. Leží vysoko nad reálnými auto_increment hodnotami,
     * takže nemůže kolidovat s daty ostatních firem, a přitom se vejde do
     * `bigint unsigned`.
     */
    private const ID_BASE = 7_000_000_000;

    private const PINNED_AT = '2026-06-30 12:00:00';

    /** @var list<int> */
    public array $employeeIds = [];
    /** @var list<int> */
    public array $employmentIds = [];

    /**
     * `$idBase` posouvá celý blok připnutých ID. Mění se jen tehdy, když v jedné
     * transakci vzniká víc firem — primární klíče jsou globální, takže dvě firmy
     * se stejným základem by se srazily.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly int $supplierId,
        private readonly int $actorId,
        private readonly int $idBase = self::ID_BASE,
    ) {}

    public function seed(int $employeeCount): void
    {
        $componentId = $this->component();
        for ($index = 0; $index < $employeeCount; ++$index) {
            $employeeId = $this->employee($index);
            $this->employeeIds[] = $employeeId;
            $employments = $index % 3 === 0 ? 2 : 1;
            for ($slot = 0; $slot < $employments; ++$slot) {
                $employmentId = $this->employment($employeeId, $index, $slot);
                $this->employmentIds[] = $employmentId;
                $this->inputs($employeeId, $employmentId, $componentId, $index, $slot);
                if (($index + $slot) % 2 === 0) {
                    $this->absence($employmentId, $index, $slot);
                }
                if ($index % 4 === 0) {
                    $this->timeMonth($employmentId, $index, $slot);
                }
            }
            $this->openings($employeeId, $index);
            if ($index % 3 === 1) {
                $this->deductionAgreements($employeeId, $index);
            }
            if ($index % 2 === 0) {
                $this->payoutRule($employeeId, $index);
                $this->payoutAccount($employeeId, $index);
            }
            if ($index % 2 === 1) {
                $this->statutoryEvidence($employeeId, $index);
            }
            if ($index % 3 === 2) {
                $this->enforcement($employeeId, $index);
            }
        }
    }

    private function id(int $bucket, int $index, int $slot = 0): int
    {
        return $this->idBase + ($bucket * 1_000_000) + ($index * 100) + $slot;
    }

    private function component(): int
    {
        $id = $this->id(0, 0);
        $this->exec(
            'INSERT INTO payroll_component_definitions
                (id, supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, "SCALE-BASE", "Syntetická základní mzda", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included", "included",
                     "included", "521", "331", "2026-01-01")',
            [$id, $this->supplierId],
        );

        return $id;
    }

    private function employee(int $index): int
    {
        $id = $this->id(1, $index);
        $this->exec(
            'INSERT INTO payroll_employees
                (id, supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, ?, "employee", 1)',
            [$id, $this->supplierId, 'Syntetická osoba ' . ($index + 1)],
        );
        $this->exec(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")',
            [$this->supplierId, $id],
        );
        $this->completeProfile($id, $index);

        return $id;
    }

    /**
     * Strukturované jméno, bydliště, primární kontakt a osobní identifikátor.
     *
     * Bez nich osoba není hotová, i když má `profile_status = ready`: příznak
     * „vyžaduje doplnění" se odvozuje z těchto čtyř podmínek, ne z ručně
     * přepínaného stavu. Fixture, která seedne jen stav, by tedy tvrdila,
     * že je osoba kompletní, a přitom by jí chyběly všechny údaje.
     *
     * Šifrované sloupce nesou syntetickou hodnotu — nic z toho se nedešifruje,
     * kontroluje se jen existence řádku.
     *
     * Identita má schválně velmi staré `effective_from`: účinné jméno se čte
     * z NEJNOVĚJŠÍHO řádku historie, takže test, který osobu přejmenuje vlastním
     * řádkem, musí zůstat ten účinný.
     */
    private function completeProfile(int $employeeId, int $index): void
    {
        $this->exec(
            'INSERT INTO payroll_person_identity_history
                (id, supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, ?, ?, "Syntetická", ?, "2000-01-01")',
            [
                $this->id(17, $index),
                $this->supplierId,
                $employeeId,
                'Syntetická osoba ' . ($index + 1),
                'Osoba ' . ($index + 1),
            ],
        );
        $this->exec(
            'INSERT INTO payroll_person_addresses
                (id, supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, ?, "residence", ?, "Praha", "11000", "CZ", "2026-01-01")',
            [
                $this->id(18, $index),
                $this->supplierId,
                $employeeId,
                sprintf('Syntetická %d', $index + 1),
            ],
        );
        $this->exec(
            'INSERT INTO payroll_person_contacts
                (id, supplier_id, employee_id, contact_type,
                 contact_value_ciphertext, contact_value_hash,
                 contact_value_masked, is_primary, is_active)
             VALUES (?, ?, ?, "email", "synthetic-ciphertext", ?, ?, 1, 1)',
            [
                $this->id(19, $index),
                $this->supplierId,
                $employeeId,
                hash('sha256', "scale-contact:{$employeeId}", true),
                sprintf('s****%d@example.invalid', $index),
            ],
        );
        $this->exec(
            'INSERT INTO payroll_person_identifiers
                (id, supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, ?, "birth_number", "synthetic-ciphertext", ?, ?)',
            [
                $this->id(20, $index),
                $this->supplierId,
                $employeeId,
                hash('sha256', "scale-identifier:{$employeeId}", true),
                sprintf('******%04d', $index % 10_000),
            ],
        );
    }

    private function employment(int $employeeId, int $index, int $slot): int
    {
        $id = $this->id(2, $index, $slot);
        $this->exec(
            'INSERT INTO payroll_employments
                (id, supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", ?)',
            [
                $id,
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-%05d-%d', $index, $slot),
                $slot === 0 ? 1 : 0,
            ],
        );
        // Dva termy: starší skončil v květnu, účinný je ten od 1. 6. Dávkové načtení
        // nesmí sáhnout po tom prvním jen proto, že je v setu dřív.
        $this->exec(
            'INSERT INTO payroll_employment_terms
                (id, supplier_id, employment_id, effective_from, effective_to,
                 planned_start_on, actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-05-31", "2026-01-01", "2026-01-01",
                     30, 7500, "automatic", "automatic", "advance", 0, ?)',
            [
                $this->id(3, $index, $slot * 10),
                $this->supplierId,
                $id,
                $slot === 0 ? 1 : 0,
            ],
        );
        $this->exec(
            'INSERT INTO payroll_employment_terms
                (id, supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-06-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, ?)',
            [
                $this->id(3, $index, $slot * 10 + 1),
                $this->supplierId,
                $id,
                $slot === 0 ? 1 : 0,
            ],
        );

        return $id;
    }

    private function inputs(
        int $employeeId,
        int $employmentId,
        int $componentId,
        int $index,
        int $slot,
    ): void {
        $json = CanonicalJson::encode([
            'code' => 'SCALE-BASE',
            'name' => 'Syntetická základní mzda',
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);
        $rows = $index % 5 === 0 ? 2 : 1;
        for ($row = 0; $row < $rows; ++$row) {
            $this->exec(
                'INSERT INTO payroll_inputs
                    (id, supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, status,
                     component_snapshot_json, component_snapshot_hash,
                     approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "manual", "approved", ?, ?, ?, ?)',
                [
                    $this->id(4, $index, $slot * 10 + $row),
                    $this->supplierId,
                    $employeeId,
                    $employmentId,
                    $componentId,
                    self::PERIOD_START,
                    120_000 + ($index * 100) + ($slot * 10) + $row,
                    $json,
                    hash('sha256', $json, true),
                    $this->actorId,
                    self::PINNED_AT,
                ],
            );
        }
    }

    private function absence(int $employmentId, int $index, int $slot): void
    {
        $day = 1 + (($index + $slot) % 20);
        $this->exec(
            'INSERT INTO payroll_absences
                (id, supplier_id, employment_id, absence_type, date_from, date_to,
                 status, timezone_name, compensation_policy, decided_at)
             VALUES (?, ?, ?, "vacation", ?, ?, "approved", "Europe/Prague",
                     "average_100", ?)',
            [
                $this->id(5, $index, $slot),
                $this->supplierId,
                $employmentId,
                sprintf('2026-06-%02d', $day),
                sprintf('2026-06-%02d', $day + 1),
                self::PINNED_AT,
            ],
        );
    }

    private function timeMonth(int $employmentId, int $index, int $slot): void
    {
        $this->exec(
            'INSERT INTO payroll_time_months
                (id, supplier_id, employment_id, period_start, status,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "approved", ?, ?)',
            [
                $this->id(6, $index, $slot),
                $this->supplierId,
                $employmentId,
                self::PERIOD_START,
                $this->actorId,
                self::PINNED_AT,
            ],
        );
    }

    /**
     * Opening balance se zapisuje syrovým SQL, ne přes repozitář: potřebujeme
     * připnuté `id` i `created_at`, protože obojí končí v kanonickém JSONu
     * snapshotu. Tvar řádku (values_json/evidence_json/record_hash) kopíruje
     * PayrollStatutoryAccumulatorRepository::appendOpeningBalance().
     */
    private function openings(int $employeeId, int $index): void
    {
        $kinds = [
            'social_insurance' => ['assessment_base_minor_units' => 0],
            'income_tax' => [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
        ];
        $slot = 0;
        foreach ($kinds as $calculationKind => $values) {
            $evidence = ['verified_zero' => true];
            $sourceReference = "synthetic:scale-{$calculationKind}-opening";
            $recordHash = hash('sha256', CanonicalJson::encode([
                'schema_version' => 'payroll-statutory-opening.v1',
                'supplier_id' => $this->supplierId,
                'employee_id' => $employeeId,
                'year' => 2026,
                'calculation_kind' => $calculationKind,
                'values' => $values,
                'source_reference' => $sourceReference,
                'evidence' => $evidence,
                'replaces_opening_id' => null,
            ]));
            $this->exec(
                'INSERT INTO payroll_statutory_accumulator_openings
                    (id, supplier_id, employee_id, tax_year, calculation_kind,
                     values_json, source_reference, evidence_json,
                     idempotency_key_hash, record_hash, created_by, created_at)
                 VALUES (?, ?, ?, 2026, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->id(16, $index, $slot),
                    $this->supplierId,
                    $employeeId,
                    $calculationKind,
                    CanonicalJson::encode($values),
                    $sourceReference,
                    CanonicalJson::encode($evidence),
                    hash(
                        'sha256',
                        "scale-{$calculationKind}:{$this->supplierId}:{$employeeId}",
                        true,
                    ),
                    $recordHash,
                    $this->actorId,
                    self::PINNED_AT,
                ],
            );
            ++$slot;
        }
    }

    private function deductionAgreements(int $employeeId, int $index): void
    {
        // Priorita klesá s pořadím vložení — dávkový dotaz musí držet
        // `ORDER BY priority_no, id`, ne pořadí zápisu.
        foreach ([2, 1] as $ordinal => $priority) {
            $this->exec(
                'INSERT INTO payroll_deduction_agreements
                    (id, supplier_id, employee_id, agreement_reference, title,
                     deduction_kind, priority_no, requested_minor, valid_from,
                     status)
                 VALUES (?, ?, ?, ?, ?, "meal", ?, ?, "2026-01-01", "active")',
                [
                    $this->id(7, $index, $ordinal),
                    $this->supplierId,
                    $employeeId,
                    sprintf('SCALE-DED-%05d-%d', $index, $ordinal),
                    'Syntetická srážka ' . ($ordinal + 1),
                    $priority,
                    1_000 + $index,
                ],
            );
        }
    }

    private function payoutRule(int $employeeId, int $index): void
    {
        $this->exec(
            'INSERT INTO payroll_payout_rules
                (id, supplier_id, employee_id, allocation_reference,
                 destination_kind, allocation_kind, priority_no, is_active)
             VALUES (?, ?, ?, ?, "bank", "remainder", 1, 1)',
            [
                $this->id(8, $index),
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-PAY-%05d', $index),
            ],
        );
    }

    private function payoutAccount(int $employeeId, int $index): void
    {
        $this->exec(
            'INSERT INTO payroll_person_accounts
                (id, supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, allocation_basis_points,
                 effective_from, is_active)
             VALUES (?, ?, ?, "Syntetický účet", "synthetic-ciphertext", ?, ?,
                     10000, "2026-01-01", 1)',
            [
                $this->id(9, $index),
                $this->supplierId,
                $employeeId,
                hash('sha256', "scale-account:{$employeeId}", true),
                sprintf('****%04d/0100', $index % 10_000),
            ],
        );
    }

    private function statutoryEvidence(int $employeeId, int $index): void
    {
        $this->exec(
            'INSERT INTO payroll_person_tax_declarations
                (id, supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, ?, "signed", "2026-01-01", ?)',
            [
                $this->id(10, $index),
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-DECL-%05d', $index),
            ],
        );
        $this->exec(
            'INSERT INTO payroll_person_tax_residences
                (id, supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, ?, "czech-resident", "CZ", "2026-01-01", ?)',
            [
                $this->id(11, $index),
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-RES-%05d', $index),
            ],
        );
    }

    private function enforcement(int $employeeId, int $index): void
    {
        $this->exec(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (id, supplier_id, employee_id, period_start,
                 claim_register_evidence_complete,
                 dependants_evidence_complete, spouse_evidence_complete,
                 pension_evidence, updated_by)
             VALUES (?, ?, ?, ?, 1, 1, 1, "none", ?)',
            [
                $this->id(12, $index),
                $this->supplierId,
                $employeeId,
                self::PERIOD_START,
                $this->actorId,
            ],
        );
        $this->exec(
            'INSERT INTO payroll_enforcement_dependants
                (id, supplier_id, employee_id, dependant_key, dependant_kind,
                 valid_from, eligibility_verified)
             VALUES (?, ?, ?, ?, "dependant", "2026-01-01", 1)',
            [
                $this->id(13, $index),
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-DEP-%05d', $index),
            ],
        );
        $caseId = $this->id(14, $index);
        $this->exec(
            'INSERT INTO payroll_enforcement_cases
                (id, supplier_id, employee_id, case_key, case_kind, effective_from,
                 status, evidence_complete)
             VALUES (?, ?, ?, ?, "enforcement", "2026-01-01", "withhold_and_hold", 1)',
            [
                $caseId,
                $this->supplierId,
                $employeeId,
                sprintf('SCALE-CASE-%05d', $index),
            ],
        );
        // Novější datum doručení vložíme první — dávkový dotaz musí výsledek
        // seřadit podle odvozeného `priority_date, id`, ne podle pořadí zápisu.
        foreach ([['2026-03-01', 50_000], ['2026-02-01', 40_000]] as $ordinal => $claim) {
            $this->exec(
                'INSERT INTO payroll_enforcement_claims
                    (id, supplier_id, case_id, claim_key, legal_basis, category,
                     outstanding_minor_units, priority_date, first_payer_delivered_on, is_active)
                 VALUES (?, ?, ?, ?, "statutory", "non_priority", ?, ?, ?, 1)',
                [
                    $this->id(15, $index, $ordinal),
                    $this->supplierId,
                    $caseId,
                    sprintf('SCALE-CLAIM-%05d-%d', $index, $ordinal),
                    $claim[1],
                    $claim[0],
                    $claim[0],
                ],
            );
        }
    }

    /** @param list<mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
    }

    /**
     * Počet prepared-statement round-tripů aktuální session.
     *
     * `Com_stmt_*` jsou session-scoped a `SHOW SESSION STATUS` se do nich sám
     * nezapočítá (jde přes COM_QUERY, ne přes prepared statement), takže měření
     * neovlivňuje to, co měří.
     */
    public static function statementRoundTrips(PDO $pdo): int
    {
        $statement = $pdo->query(
            'SHOW SESSION STATUS WHERE Variable_name IN'
            . " ('Com_stmt_prepare', 'Com_stmt_execute')"
        );
        if ($statement === false) {
            throw new \RuntimeException('Stavové proměnné session nelze načíst.');
        }
        $total = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total += (int) $row['Value'];
        }

        return $total;
    }
}
