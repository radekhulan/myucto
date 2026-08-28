<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Evidence žádostí o roční zúčtování a rejstřík jeho výsledků.
 *
 * Snapshot výsledku tu NENÍ — ten žije v `payroll_annual_document_revisions`
 * (purpose `annual_settlement_result`), stejně jako mzdový list. Tady je jen
 * to, co se musí dát dotazovat: kdo požádal, co doložil, a co komu vyšlo.
 */
final class PayrollAnnualSettlementRepository
{
    public const LIST_DEFAULT_LIMIT = 25;
    // Strop se klampuje i tady, ne jen na HTTP hranici: repozitář volá i jiný
    // kód než akce a „vypiš všechny lidi firmy" nesmí jít objednat nikudy.
    public const LIST_MAX_LIMIT = 200;
    public const LIST_STATES = ['all', 'requested', 'settled', 'unsettled'];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function findRequest(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_settlement_requests
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId, $taxYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $request = self::castRequest($row);
        $request['other_household_caregivers'] = $this->otherCaregiversForRequest(
            $supplierId,
            (int) $request['id'],
            $forUpdate,
        );

        return $request;
    }

    /**
     * Založí nebo přepíše žádost. Optimistický zámek přes `row_version` —
     * dvě otevřené karty téhož zaměstnance si nesmí tiše přepsat odpovědi
     * o tom, co poplatník doložil.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function saveRequest(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $values,
        ?int $expectedRowVersion,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $existing = $this->findRequest($supplierId, $employeeId, $taxYear, true);
            $caregiverStatus = (string) (
                $values['other_household_caregiver_status']
                ?? $existing['other_household_caregiver_status']
                ?? 'unknown'
            );
            $caregivers = array_key_exists('other_household_caregivers', $values)
                ? (array) $values['other_household_caregivers']
                : (array) ($existing['other_household_caregivers'] ?? []);

            if ($existing === null) {
                if ($expectedRowVersion !== null) {
                    throw new PayrollAnnualSettlementConflictException(
                        'Žádost o roční zúčtování mezitím zanikla.',
                    );
                }
                $statement = $pdo->prepare(
                    'INSERT INTO payroll_annual_settlement_requests
                        (supplier_id, employee_id, tax_year, request_status,
                         requested_on, request_evidence_reference, prior_employers,
                         prior_documents_received_on, filing_obligation,
                         filing_obligation_reason, annual_claims, annual_claims_note,
                         other_household_caregiver_status,
                         note, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                try {
                    $statement->execute([
                        $supplierId,
                        $employeeId,
                        $taxYear,
                        $values['request_status'],
                        $values['requested_on'],
                        $values['request_evidence_reference'],
                        $values['prior_employers'],
                        $values['prior_documents_received_on'],
                        $values['filing_obligation'],
                        $values['filing_obligation_reason'],
                        $values['annual_claims'],
                        $values['annual_claims_note'],
                        $caregiverStatus,
                        $values['note'],
                        $actorUserId,
                        $actorUserId,
                    ]);
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                        throw new PayrollAnnualSettlementConflictException(
                            'Žádost o roční zúčtování mezitím založil někdo jiný.',
                            previous: $exception,
                        );
                    }
                    throw $exception;
                }
                $requestId = (int) $pdo->lastInsertId();
            } else {
                $statement = $pdo->prepare(
                    'UPDATE payroll_annual_settlement_requests
                        SET request_status = ?,
                            requested_on = ?,
                            request_evidence_reference = ?,
                            prior_employers = ?,
                            prior_documents_received_on = ?,
                            filing_obligation = ?,
                            filing_obligation_reason = ?,
                            annual_claims = ?,
                            annual_claims_note = ?,
                            other_household_caregiver_status = ?,
                            note = ?,
                            updated_by = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?
                        AND row_version = ?'
                );
                $statement->execute([
                    $values['request_status'],
                    $values['requested_on'],
                    $values['request_evidence_reference'],
                    $values['prior_employers'],
                    $values['prior_documents_received_on'],
                    $values['filing_obligation'],
                    $values['filing_obligation_reason'],
                    $values['annual_claims'],
                    $values['annual_claims_note'],
                    $caregiverStatus,
                    $values['note'],
                    $actorUserId,
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $expectedRowVersion ?? $existing['row_version'],
                ]);
                if ($statement->rowCount() === 0) {
                    throw new PayrollAnnualSettlementConflictException(
                        'Žádost o roční zúčtování mezitím změnil někdo jiný.',
                    );
                }
                $requestId = (int) $existing['id'];
            }

            $this->replaceOtherCaregivers(
                $supplierId,
                $requestId,
                $caregivers,
                $actorUserId,
            );
            $saved = $this->findRequest($supplierId, $employeeId, $taxYear)
                ?? throw new \RuntimeException('Žádost nelze načíst.');
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $saved;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function otherCaregiversForRequest(
        int $supplierId,
        int $requestId,
        bool $forUpdate = false,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, position, given_name, family_name, birth_date, months_mask
               FROM payroll_annual_settlement_other_caregivers
              WHERE supplier_id = ? AND request_id = ?
              ORDER BY position, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $requestId]);

        return array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['position'] = (int) $row['position'];

                return $row;
            },
            array_values($statement->fetchAll(PDO::FETCH_ASSOC)),
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function replaceOtherCaregivers(
        int $supplierId,
        int $requestId,
        array $rows,
        ?int $actorUserId,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Evidence jiného pečujícího se ukládá v transakci.');
        }
        $delete = $pdo->prepare(
            'DELETE FROM payroll_annual_settlement_other_caregivers
              WHERE supplier_id = ? AND request_id = ?'
        );
        $delete->execute([$supplierId, $requestId]);

        $insert = $pdo->prepare(
            'INSERT INTO payroll_annual_settlement_other_caregivers
                (supplier_id, request_id, position, given_name, family_name,
                 birth_date, months_mask, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($rows) as $index => $row) {
            $insert->execute([
                $supplierId,
                $requestId,
                $index + 1,
                $row['given_name'],
                $row['family_name'],
                $row['birth_date'],
                $row['months_mask'],
                $actorUserId,
                $actorUserId,
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    public function findOutcome(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_settlement_outcomes
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId, $taxYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::castOutcome($row);
    }

    /**
     * Zapíše výsledek. Kolize na unikátním klíči NENÍ chyba k opravě — je to
     * druhé spuštění téhož zúčtování a musí vrátit ten původní výsledek,
     * ne založit další (§ 38ch odst. 4: jednou za zdaňovací období).
     *
     * @param array<string,mixed> $values
     * @return array{outcome:array<string,mixed>,created:bool}
     */
    public function insertOutcome(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $values,
        ?int $actorUserId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_outcomes
                (supplier_id, employee_id, tax_year, annual_revision_id, outcome,
                 tax_difference_minor, bonus_difference_minor,
                 settlement_difference_minor, payable_minor,
                 payout_threshold_minor, settled_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $supplierId,
                $employeeId,
                $taxYear,
                $values['annual_revision_id'],
                $values['outcome'],
                $values['tax_difference_minor'],
                $values['bonus_difference_minor'],
                $values['settlement_difference_minor'],
                $values['payable_minor'],
                $values['payout_threshold_minor'],
                $values['settled_on'],
                $actorUserId,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $existing = $this->findOutcome($supplierId, $employeeId, $taxYear);
            if ($existing === null) {
                throw $exception;
            }

            return ['outcome' => $existing, 'created' => false];
        }

        $outcome = $this->findOutcome($supplierId, $employeeId, $taxYear)
            ?? throw new \RuntimeException('Výsledek ročního zúčtování nelze načíst.');

        return ['outcome' => $outcome, 'created' => true];
    }

    /**
     * Co se v daném mzdovém období vyplácí (§ 38ch odst. 5, § 35d odst. 8).
     *
     * Okno je dané zákonem z obou stran: dřív než v měsíci provedení zúčtování
     * vyplácet není co, a „nejpozději při zúčtování mzdy za březen po uplynutí
     * zdaňovacího období" je poslední možnost. Výsledek navázaný na JINÝ běh se
     * nevrací — jinak by se přeplatek vyplatil dvakrát. Vlastní běh se vrací
     * dál, aby přepočet revize dal totéž číslo.
     *
     * @return array<int,int> employee_id => částka v haléřích
     */
    public function payableOutcomesForPeriod(
        int $supplierId,
        int $runId,
        string $periodStart,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, SUM(payable_minor) AS payable_minor
               FROM payroll_annual_settlement_outcomes
              WHERE supplier_id = ?
                AND payable_minor > 0
                AND (payout_run_id IS NULL OR payout_run_id = ?)
                AND ? >= DATE_FORMAT(settled_on, \'%Y-%m-01\')
                AND ? <= CONCAT(tax_year + 1, \'-03-01\')
              GROUP BY employee_id'
        );
        $statement->execute([$supplierId, $runId, $periodStart, $periodStart]);
        $payouts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payouts[(int) $row['employee_id']] = (int) $row['payable_minor'];
        }
        ksort($payouts, SORT_NUMERIC);

        return $payouts;
    }

    /**
     * Zapíše, kterou revizí se doplatek ze zúčtování vyplatil.
     *
     * Nejdřív se vazby toho běhu uvolní. Přepočet revize může seznam zúžit
     * (zaměstnanec z běhu vypadl) a zůstalá vazba by tvrdila, že se vyplatilo
     * něco, co ve výsledku není.
     *
     * @param array<int,int> $payouts employee_id => částka v haléřích
     */
    public function linkPayout(
        int $supplierId,
        int $runId,
        int $revisionId,
        string $periodStart,
        array $payouts,
    ): void {
        $release = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_settlement_outcomes
                SET payout_run_id = NULL,
                    payout_revision_id = NULL,
                    payout_period_start = NULL
              WHERE supplier_id = ? AND payout_run_id = ?'
        );
        $release->execute([$supplierId, $runId]);
        if ($payouts === []) {
            return;
        }
        $link = $this->db->pdo()->prepare(
            'UPDATE payroll_annual_settlement_outcomes
                SET payout_run_id = ?,
                    payout_revision_id = ?,
                    payout_period_start = ?
              WHERE supplier_id = ?
                AND employee_id = ?
                AND payable_minor > 0
                AND payout_run_id IS NULL
                AND ? >= DATE_FORMAT(settled_on, \'%Y-%m-01\')
                AND ? <= CONCAT(tax_year + 1, \'-03-01\')'
        );
        foreach (array_keys($payouts) as $employeeId) {
            $link->execute([
                $runId,
                $revisionId,
                $periodStart,
                $supplierId,
                $employeeId,
                $periodStart,
                $periodStart,
            ]);
        }
    }

    /**
     * Přehled za rok: zaměstnanci s jejich žádostí a výsledkem.
     *
     * Zaměstnanec BEZ žádosti v seznamu zůstává — prázdný stav je informace
     * („nikdo nepožádal"), ne důvod ho schovat.
     *
     * Stránkuje SERVER. Firma se stovkou lidí posílala celý seznam najednou
     * a obrazovka z něj zobrazila všechno — u ročního zúčtování je to navíc
     * seznam, ve kterém se pracuje adresně (kdo požádal, komu co chybí),
     * takže zúžení a stránka jsou tu užitečnější než úplný výpis.
     *
     * `state` je pojmenované zúžení, ne odvozený součet:
     *  - `requested`   — požádal podle § 38ch odst. 1 a nemá výsledek
     *  - `settled`     — zúčtování je provedené (existuje výsledek)
     *  - `unsettled`   — výsledek neexistuje, ať už požádal, nebo ne
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listForYear(
        int $supplierId,
        int $taxYear,
        int $limit,
        int $offset,
        string $search = '',
        string $state = 'all',
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $search = trim($search);
        if (!in_array($state, self::LIST_STATES, true)) {
            throw new \InvalidArgumentException('Zúžení přehledu ročního zúčtování není platné.');
        }
        $conditions = '';
        $filterParams = [];
        if ($search !== '') {
            $conditions .= ' AND employee.full_name LIKE ?';
            $filterParams[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
        }
        $conditions .= match ($state) {
            'requested' => ' AND request.request_status = "requested" AND outcome.id IS NULL',
            'settled' => ' AND outcome.id IS NOT NULL',
            'unsettled' => ' AND outcome.id IS NULL',
            default => '',
        };

        $joins =
            '  FROM payroll_employees employee
               LEFT JOIN payroll_annual_settlement_requests request
                 ON request.supplier_id = employee.supplier_id
                AND request.employee_id = employee.id
                AND request.tax_year = ?
               LEFT JOIN payroll_annual_settlement_outcomes outcome
                 ON outcome.supplier_id = employee.supplier_id
                AND outcome.employee_id = employee.id
                AND outcome.tax_year = ?
              WHERE employee.supplier_id = ?' . $conditions;

        $countStatement = $this->db->pdo()->prepare('SELECT COUNT(*) ' . $joins);
        $countStatement->execute([$taxYear, $taxYear, $supplierId, ...$filterParams]);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->db->pdo()->prepare(
            'SELECT employee.id AS employee_id,
                    employee.full_name AS employee_name,
                    request.request_status,
                    request.requested_on,
                    request.prior_employers,
                    request.filing_obligation,
                    request.annual_claims,
                    request.row_version,
                    outcome.id AS outcome_id,
                    outcome.outcome,
                    outcome.tax_difference_minor,
                    outcome.bonus_difference_minor,
                    outcome.settlement_difference_minor,
                    outcome.payable_minor,
                    outcome.settled_on,
                    outcome.payout_run_id,
                    outcome.payout_revision_id,
                    outcome.payout_period_start,
                    outcome.annual_revision_id
             ' . $joins . '
              ORDER BY employee.full_name, employee.id
              LIMIT ? OFFSET ?'
        );
        $position = 0;
        foreach ([$taxYear, $taxYear, $supplierId] as $value) {
            $statement->bindValue(++$position, $value, PDO::PARAM_INT);
        }
        foreach ($filterParams as $value) {
            $statement->bindValue(++$position, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(++$position, $limit, PDO::PARAM_INT);
        $statement->bindValue(++$position, $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_values(array_map(
                self::castListRow(...),
                $statement->fetchAll(PDO::FETCH_ASSOC),
            )),
            'total' => $total,
        ];
    }

    /**
     * Nároky na slevy podle § 35ba účinné kdykoli během roku.
     *
     * Vrací syrové intervaly; měsíce se počítají v doméně, protože podmínka
     * „na jehož počátku byly splněny podmínky" (§ 35ba odst. 3) je pravidlo,
     * ne dotaz — a v SQL by se nedala otestovat bez databáze.
     *
     * @return list<array<string,mixed>>
     */
    public function creditClaimsForYear(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT credit_kind, evidence_status, effective_from, effective_to
               FROM payroll_person_tax_credit_claims
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY credit_kind, effective_from, id'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            sprintf('%04d-12-31', $taxYear),
            sprintf('%04d-01-01', $taxYear),
        ]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nároky na daňové zvýhodnění účinné kdykoli během roku.
     *
     * @return list<array<string,mixed>>
     */
    public function childClaimsForYear(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT child_reference, child_order, ztp_p, evidence_status,
                    shared_household_confirmed, other_claimant_excluded,
                    effective_from, effective_to
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY child_reference, effective_from, id'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            sprintf('%04d-12-31', $taxYear),
            sprintf('%04d-01-01', $taxYear),
        ]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $childReferences */
    public function childJmhzEvidenceIsComplete(
        int $supplierId,
        int $employeeId,
        array $childReferences,
        array $request,
    ): bool {
        if ($childReferences === []) {
            return true;
        }
        $status = (string) ($request['other_household_caregiver_status'] ?? 'unknown');
        $caregivers = $request['other_household_caregivers'] ?? [];
        if (!is_array($caregivers)
            || ($status === 'none' && $caregivers !== [])
            || ($status === 'present' && $caregivers === [])
            || !in_array($status, ['none', 'present'], true)
        ) {
            return false;
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT given_name, family_name, birth_date
               FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        foreach (array_values(array_unique($childReferences)) as $reference) {
            if (preg_match('/^dependant-([1-9][0-9]*)$/D', $reference, $match) !== 1) {
                return false;
            }
            $statement->execute([$supplierId, $employeeId, (int) $match[1]]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || trim((string) ($row['given_name'] ?? '')) === ''
                || trim((string) ($row['family_name'] ?? '')) === ''
                || !is_string($row['birth_date'] ?? null)
                || $row['birth_date'] === ''
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Potvrzení od předchozích plátců daně za daný rok (§ 38ch odst. 3).
     *
     * @return list<array<string,mixed>>
     */
    public function certificatesForYear(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_settlement_certificates
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?
              ORDER BY certificate_reference, id'
        );
        $statement->execute([$supplierId, $employeeId, $taxYear]);

        return array_map(
            self::castCertificate(...),
            array_values($statement->fetchAll(PDO::FETCH_ASSOC)),
        );
    }

    /**
     * Přepíše celý seznam potvrzení za rok.
     *
     * Celý seznam schválně, ne po řádcích: potvrzení dávají smysl jen jako
     * úplná sada za rok (§ 38ch odst. 3 mluví o dokladech od VŠECH předchozích
     * plátců), takže se ukládají jedním úkonem stejně, jako se jedním úkonem
     * zadávají. Uložení po jednom by dovolilo stav, kdy je půlka roku doložená
     * a druhá zmizela.
     *
     * Volá se uvnitř transakce volajícího — smazání a vložení nesmí být vidět
     * odděleně.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function replaceCertificates(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $rows,
        ?int $actorUserId,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Přepis potvrzení od jiných plátců musí běžet v transakci.',
            );
        }

        $delete = $pdo->prepare(
            'DELETE FROM payroll_annual_settlement_certificates
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?'
        );
        $delete->execute([$supplierId, $employeeId, $taxYear]);

        if ($rows === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO payroll_annual_settlement_certificates
                (supplier_id, employee_id, tax_year, certificate_reference,
                 payer_name, payer_tax_identification, received_on,
                 gross_income_minor, advance_base_minor, advance_tax_minor,
                 credit_35ba_minor, credit_35c_minor, tax_bonus_minor,
                 evidence_status, evidence_reference, note,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            try {
                $insert->execute([
                    $supplierId,
                    $employeeId,
                    $taxYear,
                    $row['certificate_reference'],
                    $row['payer_name'] ?? null,
                    $row['payer_tax_identification'] ?? null,
                    $row['received_on'] ?? null,
                    $row['gross_income_minor'] ?? null,
                    $row['advance_base_minor'] ?? null,
                    $row['advance_tax_minor'] ?? null,
                    $row['credit_35ba_minor'] ?? null,
                    $row['credit_35c_minor'] ?? null,
                    $row['tax_bonus_minor'] ?? null,
                    $row['evidence_status'] ?? 'unverified',
                    $row['evidence_reference'] ?? null,
                    $row['note'] ?? null,
                    $actorUserId,
                    $actorUserId,
                ]);
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    throw new PayrollAnnualSettlementConflictException(
                        'Potvrzení se stejným označením je v seznamu dvakrát.',
                        previous: $exception,
                    );
                }
                throw $exception;
            }
        }
    }

    /**
     * Intervaly prohlášení k dani a rezidentství zasahující do roku.
     *
     * Vrací ŘÁDKY, ne vyhodnocený stav: co z nich plyne, rozhoduje
     * {@see \MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementEvidenceMonths}.
     * Posouzení po měsících je pravidlo, ne dotaz — v SQL by se nedalo otestovat
     * bez databáze, stejně jako u nároků na slevy.
     *
     * @return array{
     *   declarations:list<array<string,mixed>>,
     *   residences:list<array<string,mixed>>
     * }
     */
    public function statutoryEvidenceForYear(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $yearStart = sprintf('%04d-01-01', $taxYear);
        $yearEnd = sprintf('%04d-12-31', $taxYear);

        $declarations = $this->db->pdo()->prepare(
            'SELECT status, effective_from, effective_to
               FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id'
        );
        $declarations->execute([$supplierId, $employeeId, $yearEnd, $yearStart]);

        $residences = $this->db->pdo()->prepare(
            'SELECT residence, effective_from, effective_to
               FROM payroll_person_tax_residences
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id'
        );
        $residences->execute([$supplierId, $employeeId, $yearEnd, $yearStart]);

        return [
            'declarations' => array_values($declarations->fetchAll(PDO::FETCH_ASSOC)),
            'residences' => array_values($residences->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Měsíce roku, ve kterých u plátce trval pracovní vztah.
     *
     * Bere se PŘEKRYV s měsícem, ne jeho počátek: kdo nastoupil 15. ledna, má
     * leden ve mzdách i v zúčtování. (Počátek měsíce je test nároku na slevu
     * podle § 35ba odst. 3, což je jiná otázka než „za které měsíce se vůbec
     * zúčtovává".)
     *
     * Rozpracované a stornované vztahy se nepočítají — mzda z nich nevznikla.
     * Souběh více vztahů u téhož plátce se slévá do jedné množiny měsíců;
     * prohlášení k dani se v modulu vede na zaměstnance, ne na vztah.
     *
     * @return list<int>
     */
    public function employmentMonths(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
                AND status IN (\'active\', \'ended\')
                AND (start_date IS NULL OR start_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            sprintf('%04d-12-31', $taxYear),
            sprintf('%04d-01-01', $taxYear),
        ]);

        $months = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = is_string($row['start_date'] ?? null) && $row['start_date'] !== ''
                ? $row['start_date']
                : sprintf('%04d-01-01', $taxYear);
            $end = is_string($row['end_date'] ?? null) && $row['end_date'] !== ''
                ? $row['end_date']
                : sprintf('%04d-12-31', $taxYear);
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = sprintf('%04d-%02d-01', $taxYear, $month);
                $monthEnd = date('Y-m-t', (int) strtotime($monthStart));
                if ($start <= $monthEnd && $end >= $monthStart) {
                    $months[$month] = true;
                }
            }
        }
        ksort($months);

        return array_map('intval', array_keys($months));
    }

    /**
     * Prohlášení k dani a rezidentství účinné k danému dni.
     *
     * ⚠️ Pro roční zúčtování se NEPOUŽÍVÁ. Čtení k jedinému dni (typicky
     * k 31. 12.) dělá z každého, kdo v průběhu roku odešel, nedoloženého
     * nerezidenta. Roční větev proto jde přes {@see statutoryEvidenceForYear()}
     * a posouzení po měsících.
     *
     * @return array{declaration:?string,residence:?string}
     */
    public function statutoryEvidenceOn(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): array {
        $declaration = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $declaration->execute([$supplierId, $employeeId, $effectiveOn, $effectiveOn]);
        $declarationStatus = $declaration->fetchColumn();

        $residence = $this->db->pdo()->prepare(
            'SELECT residence FROM payroll_person_tax_residences
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $residence->execute([$supplierId, $employeeId, $effectiveOn, $effectiveOn]);
        $residenceValue = $residence->fetchColumn();

        return [
            'declaration' => is_string($declarationStatus) ? $declarationStatus : null,
            'residence' => is_string($residenceValue) ? $residenceValue : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRequest(array $row): array
    {
        foreach (['id', 'supplier_id', 'employee_id', 'tax_year', 'row_version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /**
     * Částky zůstávají `null`, když je potvrzení nenese.
     *
     * Přetypovat je na nulu by znamenalo, že se chybějící údaj tváří jako
     * doložená nula — a přesně z toho by vyšel přeplatek, který poplatníkovi
     * nenáleží. Viz `ExternalEmployerTaxCertificate`.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castCertificate(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employee_id', 'tax_year', 'row_version',
            'gross_income_minor', 'advance_base_minor', 'advance_tax_minor',
            'credit_35ba_minor', 'credit_35c_minor', 'tax_bonus_minor',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castOutcome(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employee_id', 'tax_year', 'annual_revision_id',
            'tax_difference_minor', 'bonus_difference_minor',
            'settlement_difference_minor', 'payable_minor',
            'payout_threshold_minor', 'payout_run_id', 'payout_revision_id',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castListRow(array $row): array
    {
        foreach ([
            'employee_id', 'row_version', 'outcome_id', 'annual_revision_id',
            'tax_difference_minor', 'bonus_difference_minor',
            'settlement_difference_minor', 'payable_minor',
            'payout_run_id', 'payout_revision_id',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
