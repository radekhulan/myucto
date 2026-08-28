<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDecisionDocumentReference;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTransitionContext;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentAllocation;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollEnforcementStoredResultIntegrity;
use MyInvoice\Service\Payroll\Garnishment\PayrollInsolvencyPaymentInstructionService;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollEnforcementRepository implements
    EnforcementCaseSource,
    PayrollGarnishmentSnapshotWriter
{
    private const INSOLVENCY_ALLOCATION_KEY = 'insolvency-administrator';

    /** Velikost dávky pro množinové načtení nad zmrazenou sadou osob. */
    private const CHUNK_SIZE = 500;

    /** Alias skupinového klíče; po seskupení se z řádku zase odstraní. */
    private const GROUP_KEY = 'snapshot_group_employee_id';

    /**
     * Strop stránky seznamu případů. Exekuce je pracovní agenda — účetní řeší
     * desítky živých případů, ne celou historii firmy naráz. Sto řádků pokryje
     * i velkou firmu s jednou stránkou navíc.
     */
    public const LIST_MAX_LIMIT = 100;

    public const LIST_DEFAULT_LIMIT = 50;
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollInsolvencyPaymentInstructionService $insolvencyInstructions,
    ) {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /**
     * Seznam exekučních případů se stránkováním.
     *
     * Oba filtry jsou volitelné, takže volání bez parametrů četlo VŠECHNY případy,
     * které firma kdy vedla — objem roste s počtem zaměstnanců krát doba provozu.
     * Strop se proto uplatňuje už tady, ne až u volajícího.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listCases(
        int $supplierId,
        ?int $employeeId = null,
        ?EnforcementCaseStatus $status = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $where = ' WHERE c.supplier_id = ?';
        $params = [$supplierId];
        if ($employeeId !== null) {
            $where .= ' AND c.employee_id = ?';
            $params[] = $employeeId;
        }
        if ($status !== null) {
            $where .= ' AND c.status = ?';
            $params[] = $status->value;
        }

        // Počet se bere nad případy, ne nad pohledávkami — `LEFT JOIN` na claims
        // by řádky násobil. Filtry i povinný JOIN na zaměstnance jsou tytéž jako
        // ve stránkovaném dotazu, jinak by `total` neodpovídal seznamu.
        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_enforcement_cases c
               JOIN payroll_employees e
                 ON e.supplier_id = c.supplier_id AND e.id = c.employee_id'
            . $where
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = <<<'SQL'
            SELECT c.id, c.employee_id, c.case_kind, c.status, c.effective_from,
                   c.effective_to, c.evidence_complete, c.recipient_verified,
                   c.row_version, c.created_at, c.updated_at, e.full_name,
                   COUNT(cl.id) AS claim_count,
                   COALESCE(SUM(CASE WHEN cl.is_active = 1
                                    THEN cl.outstanding_minor_units ELSE 0 END), 0)
                       AS outstanding_minor_units
              FROM payroll_enforcement_cases c
              JOIN payroll_employees e
                ON e.supplier_id = c.supplier_id AND e.id = c.employee_id
              LEFT JOIN payroll_enforcement_claims cl
                ON cl.supplier_id = c.supplier_id AND cl.case_id = c.id
            SQL
            . $where
            . <<<'SQL'

             GROUP BY c.id, c.employee_id, c.case_kind, c.status, c.effective_from,
                      c.effective_to, c.evidence_complete, c.recipient_verified,
                      c.row_version, c.created_at, c.updated_at, e.full_name
             ORDER BY FIELD(c.status, 'received', 'withhold_and_hold', 'remit',
                            'deferred_hold', 'deferred_no_withholding', 'paid', 'stopped'),
                      c.effective_from, c.id
             LIMIT ? OFFSET ?
            SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(
            self::castCase(...),
            PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'enforcement_cases'),
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function findCase(int $supplierId, int $caseId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, e.full_name
               FROM payroll_enforcement_cases c
               JOIN payroll_employees e
                 ON e.supplier_id = c.supplier_id AND e.id = c.employee_id
              WHERE c.supplier_id = ? AND c.id = ?'
        );
        $stmt->execute([$supplierId, $caseId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($value === false) {
            return null;
        }
        $row = PayrollTimeValue::row($value, 'enforcement_case');
        $case = self::castCase($row);
        unset($case['case_key'], $case['created_by'], $case['updated_by']);
        $case['claims'] = $this->claimsForCase($supplierId, $caseId);
        $case['claim_count'] = count($case['claims']);
        $case['outstanding_minor_units'] = array_sum(array_map(
            static fn (array $claim): int => PayrollTimeValue::int(
                $claim['outstanding_minor_units'] ?? null,
                'outstanding_minor_units',
            ),
            $case['claims'],
        ));
        $case['events'] = $this->eventsForCase($supplierId, $caseId);
        $case['ledger'] = $this->ledgerForCase($supplierId, $caseId);
        $case['settlement'] = $this->settlementForCase($supplierId, $caseId);

        return $case;
    }

    /** @return array<string,mixed> */
    public function createCase(
        int $supplierId,
        int $employeeId,
        string $caseKind,
        string $effectiveFrom,
        ?int $userId,
    ): array {
        if (!in_array($caseKind, ['enforcement', 'voluntary_agreement'], true)) {
            throw new \InvalidArgumentException('Neplatný typ srážkového případu.');
        }
        self::assertDate($effectiveFrom, 'effective_from');
        $caseKey = 'case_' . bin2hex(random_bytes(16));
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange($supplierId, $effectiveFrom, $effectiveFrom);
            $this->assertEmployee($supplierId, $employeeId);
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_enforcement_cases
                    (supplier_id, employee_id, case_key, case_kind, effective_from,
                     created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId, $employeeId, $caseKey, $caseKind, $effectiveFrom,
                $userId, $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->findCase($supplierId, $id)
            ?? throw new \RuntimeException('Exekuční případ nebyl po vytvoření nalezen.');
    }

    /** @return array<string,mixed>|null */
    public function deleteUnusedCase(
        int $supplierId,
        int $caseId,
        int $expectedVersion,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM payroll_enforcement_cases
                  WHERE supplier_id = ? AND id = ? FOR UPDATE'
            );
            $stmt->execute([$supplierId, $caseId]);
            $value = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($value === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $case = PayrollTimeValue::row($value, 'enforcement_case');
            $currentVersion = PayrollTimeValue::int(
                $case['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            $effectiveFrom = PayrollTimeValue::string(
                $case['effective_from'] ?? null,
                'effective_from',
            );
            $this->yearClose->assertOpenForDateRange($supplierId, $effectiveFrom, $effectiveFrom);

            $this->assertCaseCanBeDeleted($supplierId, $caseId, $case);

            $delete = $pdo->prepare(
                "DELETE FROM payroll_enforcement_cases
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = 'received'"
            );
            try {
                $delete->execute([$supplierId, $caseId, $expectedVersion]);
            } catch (\PDOException $e) {
                $errorInfoState = $e->errorInfo[0] ?? null;
                $sqlState = is_string($errorInfoState)
                    ? $errorInfoState
                    : (string) $e->getCode();
                if (!in_array($sqlState, ['23000', '45000'], true)) {
                    throw $e;
                }
                throw new PayrollEnforcementDeletionBlockedException(
                    'concurrent_footprint_exists',
                    'Případ mezitím získal právní, mzdovou nebo platební návaznost. '
                    . 'Obnovte detail a případ zachovejte v historii.',
                );
            }
            if ($delete->rowCount() !== 1) {
                $this->throwConflictOrNotFound($supplierId, $caseId);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        $deleted = self::castCase($case);
        unset($deleted['case_key'], $deleted['created_by'], $deleted['updated_by']);
        $deleted['claim_count'] = 0;
        $deleted['outstanding_minor_units'] = 0;
        return $deleted;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function addClaim(
        int $supplierId,
        int $caseId,
        array $data,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $case = $this->lockCase($supplierId, $caseId);
            if (EnforcementCaseStatus::from(
                PayrollTimeValue::string($case['status'] ?? null, 'status'),
            ) !== EnforcementCaseStatus::Received) {
                throw new \DomainException(
                    'Pohledávku lze přidat pouze do dosud neaktivovaného případu.',
                );
            }
            $legalBasis = DeductionLegalBasis::from(self::requiredString($data, 'legal_basis'));
            $category = ClaimCategory::from(self::requiredString($data, 'category'));
            $this->assertClaimTypeMatchesCase($legalBasis, $category, $case);
            $outstanding = self::nonNegativeInt($data, 'outstanding_minor_units');
            $weight = self::nullablePositiveInt($data, 'maintenance_weight_minor_units');
            self::assertMaintenanceWeight($category, $weight);
            $orderIssuedOn = self::nullableDate($data, 'order_issued_on');
            $sameOrderClaimId = self::nullablePositiveInt($data, 'same_order_as_claim_id');
            $sameOrder = $sameOrderClaimId === null
                ? null
                : $this->orderForClaim($supplierId, $caseId, $sameOrderClaimId);
            $orderKey = $sameOrder === null
                ? 'order_' . bin2hex(random_bytes(16))
                : PayrollTimeValue::string($sameOrder['enforcement_order_key'] ?? null, 'enforcement_order_key');
            [$priorityDate, $firstPayerDeliveredOn] = $legalBasis === DeductionLegalBasis::Statutory
                ? $this->newStatutoryPriority($data, $sameOrder)
                : $this->voluntaryPriority($data);
            $this->assertFactDatesOpen(
                $supplierId,
                $priorityDate,
                $firstPayerDeliveredOn,
                $orderIssuedOn,
            );
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_enforcement_claims
                    (supplier_id, case_id, claim_key, enforcement_order_key, legal_basis,
                     category, outstanding_minor_units, maintenance_weight_minor_units,
                     priority_date, first_payer_delivered_on, order_issued_on, legal_title_verified,
                     order_or_notice_delivered, priority_classification_verified,
                     agreement_verified, due_monetary_claim_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $caseId,
                'claim_' . bin2hex(random_bytes(16)),
                $orderKey,
                $legalBasis->value,
                $category->value,
                $outstanding,
                $weight,
                $priorityDate,
                $firstPayerDeliveredOn,
                $orderIssuedOn,
                self::boolInt($data, 'legal_title_verified'),
                self::boolInt($data, 'order_or_notice_delivered'),
                self::boolInt($data, 'priority_classification_verified'),
                self::boolInt($data, 'agreement_verified'),
                self::boolInt($data, 'due_monetary_claim_verified'),
            ]);
            $id = (int) $pdo->lastInsertId();
            $invalidate = $pdo->prepare(
                'UPDATE payroll_enforcement_cases
                    SET evidence_complete = 0, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ?'
            );
            $invalidate->execute([$supplierId, $caseId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }
        foreach ($this->claimsForCase($supplierId, $caseId) as $claim) {
            if ($claim['id'] === $id) {
                return $claim;
            }
        }
        throw new \RuntimeException('Pohledávka nebyla po vytvoření nalezena.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function updateUnusedClaim(
        int $supplierId,
        int $caseId,
        int $claimId,
        array $data,
        int $expectedVersion,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $case = $this->lockOwnedCase($supplierId, $caseId);
            if ($case === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $claim = $this->lockOwnedClaim($supplierId, $caseId, $claimId);
            if ($claim === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $currentVersion = PayrollTimeValue::int(
                $claim['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            $this->assertClaimCanBeMutated($supplierId, $caseId, $claimId, $claim, $case);

            $legalBasis = DeductionLegalBasis::from(self::requiredString($data, 'legal_basis'));
            $category = ClaimCategory::from(self::requiredString($data, 'category'));
            $this->assertClaimTypeMatchesCase($legalBasis, $category, $case);
            $outstanding = self::nonNegativeInt($data, 'outstanding_minor_units');
            $weight = self::nullablePositiveInt($data, 'maintenance_weight_minor_units');
            self::assertMaintenanceWeight($category, $weight);
            $orderIssuedOn = self::nullableDate($data, 'order_issued_on');
            $storedOrderKey = $claim['enforcement_order_key'] ?? null;
            $orderKey = is_string($storedOrderKey) && $storedOrderKey !== ''
                ? $storedOrderKey
                : 'order_' . bin2hex(random_bytes(16));
            $sameOrder = null;
            if (array_key_exists('same_order_as_claim_id', $data)) {
                $sameOrderClaimId = self::nullablePositiveInt(
                    $data,
                    'same_order_as_claim_id',
                );
                if ($sameOrderClaimId === $claimId) {
                    throw new \InvalidArgumentException(
                        'Pohledávka nemůže odkazovat sama na sebe jako na stejný příkaz.',
                    );
                }
                $sameOrder = $sameOrderClaimId === null
                    ? null
                    : $this->orderForClaim($supplierId, $caseId, $sameOrderClaimId);
                $orderKey = $sameOrder === null
                    ? 'order_' . bin2hex(random_bytes(16))
                    : PayrollTimeValue::string(
                        $sameOrder['enforcement_order_key'] ?? null,
                        'enforcement_order_key',
                    );
            }
            [$priorityDate, $firstPayerDeliveredOn] = $legalBasis === DeductionLegalBasis::Statutory
                ? $this->existingStatutoryPriority($data, $claim, $sameOrder)
                : $this->voluntaryPriority($data);
            $this->assertFactDatesOpen(
                $supplierId,
                $priorityDate,
                $firstPayerDeliveredOn,
                $orderIssuedOn,
            );

            $update = $pdo->prepare(
                'UPDATE payroll_enforcement_claims
                    SET enforcement_order_key = ?, legal_basis = ?, category = ?,
                        outstanding_minor_units = ?, maintenance_weight_minor_units = ?,
                        priority_date = ?, first_payer_delivered_on = ?, order_issued_on = ?, legal_title_verified = ?,
                        order_or_notice_delivered = ?, priority_classification_verified = ?,
                        agreement_verified = ?, due_monetary_claim_verified = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND case_id = ? AND id = ? AND row_version = ?'
            );
            try {
                $update->execute([
                    $orderKey,
                    $legalBasis->value,
                    $category->value,
                    $outstanding,
                    $weight,
                    $priorityDate,
                    $firstPayerDeliveredOn,
                    $orderIssuedOn,
                    self::boolInt($data, 'legal_title_verified'),
                    self::boolInt($data, 'order_or_notice_delivered'),
                    self::boolInt($data, 'priority_classification_verified'),
                    self::boolInt($data, 'agreement_verified'),
                    self::boolInt($data, 'due_monetary_claim_verified'),
                    $supplierId,
                    $caseId,
                    $claimId,
                    $expectedVersion,
                ]);
            } catch (\PDOException $e) {
                $this->throwClaimMutationDatabaseFailure($e);
            }
            if ($update->rowCount() !== 1) {
                $this->throwClaimConflictOrNotFound($supplierId, $caseId, $claimId);
            }
            $caseVersion = $this->invalidateCaseAfterClaimMutation($supplierId, $caseId);
            $result = $this->claimForCase($supplierId, $caseId, $claimId)
                ?? throw new \RuntimeException('Pohledávka nebyla po opravě nalezena.');
            $result['case_row_version'] = $caseVersion;
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function deleteUnusedClaim(
        int $supplierId,
        int $caseId,
        int $claimId,
        int $expectedVersion,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $case = $this->lockOwnedCase($supplierId, $caseId);
            if ($case === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $claim = $this->lockOwnedClaim($supplierId, $caseId, $claimId);
            if ($claim === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $currentVersion = PayrollTimeValue::int(
                $claim['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            $this->assertClaimCanBeMutated($supplierId, $caseId, $claimId, $claim, $case);
            $this->assertFactDatesOpen(
                $supplierId,
                self::nullableStringValue($claim['priority_date'] ?? null, 'priority_date'),
                self::nullableStringValue(
                    $claim['first_payer_delivered_on'] ?? null,
                    'first_payer_delivered_on',
                ),
                self::nullableStringValue($claim['order_issued_on'] ?? null, 'order_issued_on'),
            );

            $delete = $pdo->prepare(
                'DELETE FROM payroll_enforcement_claims
                  WHERE supplier_id = ? AND case_id = ? AND id = ? AND row_version = ?'
            );
            try {
                $delete->execute([$supplierId, $caseId, $claimId, $expectedVersion]);
            } catch (\PDOException $e) {
                $this->throwClaimMutationDatabaseFailure($e);
            }
            if ($delete->rowCount() !== 1) {
                $this->throwClaimConflictOrNotFound($supplierId, $caseId, $claimId);
            }
            $claim['case_row_version'] = $this->invalidateCaseAfterClaimMutation(
                $supplierId,
                $caseId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return self::castBooleansAndIntegers(
                $claim,
                ['id', 'case_id', 'outstanding_minor_units',
                    'maintenance_weight_minor_units', 'row_version', 'case_row_version'],
                ['legal_title_verified', 'order_or_notice_delivered',
                    'priority_classification_verified', 'agreement_verified',
                    'due_monetary_claim_verified', 'is_active'],
            );
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function updateCaseEvidence(
        int $supplierId,
        int $caseId,
        bool $evidenceComplete,
        bool $recipientVerified,
        int $expectedVersion,
        ?int $userId,
        ?int $recipientInstitutionId = null,
        bool $updateRecipientInstitution = false,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $case = $this->lockCase($supplierId, $caseId);
            $currentVersion = PayrollTimeValue::int(
                $case['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            if (
                !$recipientVerified
                && EnforcementCaseStatus::from(
                    PayrollTimeValue::string($case['status'] ?? null, 'status'),
                ) === EnforcementCaseStatus::Remit
            ) {
                throw new \DomainException(
                    'Před zrušením ověření příjemce přepněte případ zpět do deponování.',
                );
            }
            if (
                $evidenceComplete
                && !$this->caseEvidenceIsComplete($supplierId, $caseId)
            ) {
                throw new \DomainException(
                    'Případ nelze označit za úplný, dokud nejsou ověřeny všechny pohledávky.',
                );
            }
            if ($evidenceComplete && $recipientVerified) {
                (new PayrollEnforcementFactsRepository($this->db))
                    ->assertLegalRecipientReadyForActivation(
                        $supplierId,
                        $caseId,
                        PayrollTimeValue::string(
                            $case['effective_from'] ?? null,
                            'effective_from',
                        ),
                    );
            }
            if ($updateRecipientInstitution && $recipientInstitutionId !== null) {
                $this->assertPaymentRecipientInstitution(
                    $supplierId,
                    $recipientInstitutionId,
                );
            }
            $stmt = $pdo->prepare(
                $updateRecipientInstitution
                    ? 'UPDATE payroll_enforcement_cases
                          SET evidence_complete = ?, recipient_verified = ?,
                              recipient_institution_id = ?,
                              row_version = row_version + 1, updated_by = ?
                        WHERE supplier_id = ? AND id = ? AND row_version = ?'
                    : 'UPDATE payroll_enforcement_cases
                          SET evidence_complete = ?, recipient_verified = ?,
                              row_version = row_version + 1, updated_by = ?
                        WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute($updateRecipientInstitution
                ? [
                    (int) $evidenceComplete, (int) $recipientVerified,
                    $recipientInstitutionId, $userId,
                    $supplierId, $caseId, $expectedVersion,
                ]
                : [
                    (int) $evidenceComplete, (int) $recipientVerified, $userId,
                    $supplierId, $caseId, $expectedVersion,
                ]);
            if ($stmt->rowCount() !== 1) {
                $this->throwConflictOrNotFound($supplierId, $caseId);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->findCase($supplierId, $caseId)
            ?? throw new \RuntimeException('Exekuční případ nebyl po změně nalezen.');
    }

    /** @return array<string,mixed> */
    public function transition(
        int $supplierId,
        int $caseId,
        EnforcementCaseCommand $command,
        int $expectedVersion,
        ?string $reason,
        ?EnforcementDecisionDocumentReference $decisionDocument,
        ?int $userId,
        EnforcementCaseLifecycle $lifecycle,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $case = $this->lockCase($supplierId, $caseId);
            $currentVersion = PayrollTimeValue::int(
                $case['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            // MZ-14-W02: zůstatek pohledávky snižuje až POTVRZENÁ úhrada
            // příjemci (platební ledger MZ-17), nikoli samotné sražení ze mzdy.
            // Sražená, ale dosud neodeslaná či nespárovaná částka drží případ
            // otevřený — `mark-paid` proto projde teprve po skutečné platbě.
            $outstandingStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(GREATEST(0,
                          claim.outstanding_minor_units
                          - COALESCE((
                              SELECT SUM(
                                CASE WHEN liability.direction = 'outgoing'
                                  THEN payment_match.amount_minor
                                  ELSE -payment_match.amount_minor END
                              )
                                FROM payroll_payment_liabilities liability
                                JOIN payroll_payment_allocations allocation
                                  ON allocation.supplier_id =
                                     liability.supplier_id
                                 AND allocation.liability_id = liability.id
                                JOIN payroll_payment_matches payment_match
                                  ON payment_match.supplier_id =
                                     allocation.supplier_id
                                 AND payment_match.allocation_id =
                                     allocation.id
                               WHERE liability.supplier_id = claim.supplier_id
                                 AND liability.liability_kind = 'enforcement'
                                 AND liability.liability_reference = CONCAT(
                                   'enforcement:c', claim.case_id,
                                   ':cl', claim.id
                                 )
                          ), 0)
                        )), 0)
                   FROM payroll_enforcement_claims claim
                  WHERE claim.supplier_id = ? AND claim.case_id = ?
                    AND claim.is_active = 1"
            );
            $outstandingStmt->execute([$supplierId, $caseId]);
            $from = EnforcementCaseStatus::from(
                PayrollTimeValue::string($case['status'] ?? null, 'status'),
            );
            if (in_array($command, [
                EnforcementCaseCommand::MarkFinal,
                EnforcementCaseCommand::AuthorizeRemittance,
                EnforcementCaseCommand::ResumeRemittance,
            ], true)) {
                (new PayrollEnforcementFactsRepository($this->db))
                    ->assertLegalRecipientReadyForActivation(
                        $supplierId,
                        $caseId,
                        PayrollTimeValue::string(
                            $case['effective_from'] ?? null,
                            'effective_from',
                        ),
                    );
            }
            $decisionDocumentId = $decisionDocument?->documentId;
            $decisionEvidenceHash = $decisionDocument?->sha256;
            $to = $lifecycle->transition($from, $command, new EnforcementTransitionContext(
                PayrollTimeValue::bool($case['evidence_complete'] ?? null, 'evidence_complete'),
                PayrollTimeValue::bool($case['recipient_verified'] ?? null, 'recipient_verified'),
                (int) $outstandingStmt->fetchColumn(),
                $decisionEvidenceHash !== null,
                $reason,
            ));
            $update = $pdo->prepare(
                'UPDATE payroll_enforcement_cases
                    SET status = ?, row_version = row_version + 1, updated_by = ?
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([
                $to->value, $userId, $supplierId, $caseId, $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEnforcementConflictException($expectedVersion);
            }
            $caseDocumentId = $this->storeDecisionDocumentLink(
                $supplierId,
                $caseId,
                $command,
                $decisionDocument,
                $userId,
            );
            $event = $pdo->prepare(
                'INSERT INTO payroll_enforcement_events
                    (supplier_id, case_id, command_name, from_status, to_status,
                     reason, decision_evidence_hash, decision_document_id,
                     decision_case_document_id,
                     actor_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $event->execute([
                $supplierId, $caseId, $command->value, $from->value, $to->value,
                $reason === null ? null : trim($reason),
                $decisionEvidenceHash,
                $decisionDocumentId,
                $caseDocumentId,
                $userId,
            ]);
            $eventId = (int) $pdo->lastInsertId();
            if (in_array($command, [
                EnforcementCaseCommand::AuthorizeRemittance,
                EnforcementCaseCommand::ResumeRemittance,
            ], true)) {
                $this->releaseHeldForRemittance(
                    $supplierId,
                    $caseId,
                    $eventId,
                    $userId,
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->findCase($supplierId, $caseId)
            ?? throw new \RuntimeException('Exekuční případ nebyl po přechodu nalezen.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function saveMonthEvidence(
        int $supplierId,
        int $employeeId,
        string $period,
        array $data,
        ?int $userId,
        ?int $expectedVersion,
    ): array {
        $periodStart = self::periodStart($period);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
            $this->assertEmployee($supplierId, $employeeId);
            $pension = PensionEvidence::from(self::requiredString($data, 'pension_evidence'));
            $insolvency = InsolvencyMode::from(self::requiredString($data, 'insolvency_mode'));
            $override = self::nullableNonNegativeInt(
                $data,
                'protected_amount_override_minor_units',
            );
            $courtAmount = self::nullablePositiveInt($data, 'court_determined_amount_minor_units');
            $versionStmt = $pdo->prepare(
                'SELECT row_version, insolvency_mode,
                        insolvency_payment_instruction_id
                   FROM payroll_enforcement_person_month_evidence
                  WHERE supplier_id = ? AND employee_id = ? AND period_start = ?
                  FOR UPDATE'
            );
            $versionStmt->execute([$supplierId, $employeeId, $periodStart]);
            $currentRowValue = $versionStmt->fetch(PDO::FETCH_ASSOC);
            $currentRow = is_array($currentRowValue) ? $currentRowValue : null;
            $currentVersion = $currentRow === null
                ? false
                : PayrollTimeValue::int($currentRow['row_version'] ?? null, 'row_version');
            $currentInsolvencyMode = $currentRow['insolvency_mode'] ?? null;
            $currentInstructionId = self::nullableIntValue(
                $currentRow['insolvency_payment_instruction_id'] ?? null,
            );
            if ($currentInsolvencyMode === InsolvencyMode::ApprovedStandard->value
                && $insolvency !== InsolvencyMode::ApprovedStandard
            ) {
                throw new \DomainException(
                    'Schválené standardní oddlužení lze ukončit jen '
                    . 'výslovným zrušením před použitím platebního pokynu.',
                );
            }
            $instruction = null;
            if ($insolvency === InsolvencyMode::ApprovedStandard) {
                if (!self::boolValue($data, 'insolvency_decision_verified')
                    || !self::boolValue($data, 'insolvency_recipient_verified')
                ) {
                    throw new \DomainException(
                        'Standardní oddlužení vyžaduje ověřené rozhodnutí '
                        . 'i příjemce platby.',
                    );
                }
                if ($userId === null || $userId <= 0) {
                    throw new \DomainException(
                        'Platební pokyn oddlužení musí vytvořit konkrétní uživatel.',
                    );
                }
                $requestedInstructionId = self::nullablePositiveInt(
                    $data,
                    'insolvency_payment_instruction_id',
                );
                if ($currentInstructionId !== null
                    && $requestedInstructionId !== $currentInstructionId
                    && $this->insolvencyInstructionWasUsed(
                        $supplierId,
                        $currentInstructionId,
                    )
                ) {
                    throw new \DomainException(
                        'Použitý platební pokyn oddlužení nelze změnit ani zrušit '
                        . 'v měsíční evidenci; použijte opravnou revizi.',
                    );
                }
                $instruction = $this->insolvencyInstructions->resolve(
                    $supplierId,
                    $employeeId,
                    $periodStart,
                    $data,
                    $userId,
                );
            } elseif ($this->hasInsolvencyPaymentTarget($data)) {
                throw new \DomainException(
                    'Platební pokyn lze připnout jen ke standardnímu '
                    . 'schválenému oddlužení.',
                );
            }
            $values = [
                self::boolInt($data, 'claim_register_evidence_complete'),
                self::boolInt($data, 'dependants_evidence_complete'),
                self::boolInt($data, 'spouse_evidence_complete'),
                $pension->value,
                self::boolInt($data, 'has_multiple_payers'),
                $override,
                self::boolInt($data, 'protected_amount_override_verified'),
                $insolvency->value,
                self::boolInt($data, 'insolvency_decision_verified'),
                self::boolInt($data, 'insolvency_recipient_verified'),
                $instruction === null
                    ? null
                    : PayrollTimeValue::int(
                        $instruction['id'] ?? null,
                        'insolvency_payment_instruction.id',
                    ),
                $courtAmount,
                $userId,
            ];
            if ($currentVersion === false) {
                if ($expectedVersion !== null) {
                    throw new PayrollEnforcementConflictException(null);
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO payroll_enforcement_person_month_evidence
                        (supplier_id, employee_id, period_start,
                         claim_register_evidence_complete, dependants_evidence_complete,
                         spouse_evidence_complete, pension_evidence, has_multiple_payers,
                         protected_amount_override_minor_units,
                         protected_amount_override_verified, insolvency_mode,
                         insolvency_decision_verified, insolvency_recipient_verified,
                         insolvency_payment_instruction_id,
                         court_determined_amount_minor_units, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$supplierId, $employeeId, $periodStart, ...$values]);
            } else {
                $version = (int) $currentVersion;
                if ($expectedVersion !== $version) {
                    throw new PayrollEnforcementConflictException($version);
                }
                $stmt = $pdo->prepare(
                    'UPDATE payroll_enforcement_person_month_evidence
                        SET claim_register_evidence_complete = ?,
                            dependants_evidence_complete = ?,
                            spouse_evidence_complete = ?,
                            pension_evidence = ?,
                            has_multiple_payers = ?,
                            protected_amount_override_minor_units = ?,
                            protected_amount_override_verified = ?,
                            insolvency_mode = ?,
                            insolvency_decision_verified = ?,
                            insolvency_recipient_verified = ?,
                            insolvency_payment_instruction_id = ?,
                            court_determined_amount_minor_units = ?,
                            updated_by = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND period_start = ?
                        AND row_version = ?'
                );
                $stmt->execute([...$values, $supplierId, $employeeId, $periodStart, $version]);
                if ($stmt->rowCount() !== 1) {
                    throw new PayrollEnforcementConflictException($version);
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->monthEvidenceRow($supplierId, $employeeId, $periodStart)
            ?? throw new \RuntimeException('Měsíční podklady nebyly po uložení nalezeny.');
    }

    /** @return array<string,mixed> */
    public function cancelInsolvency(
        int $supplierId,
        int $employeeId,
        string $period,
        int $expectedVersion,
        ?int $userId,
    ): array {
        $periodStart = self::periodStart($period);
        if ($expectedVersion <= 0) {
            throw new \InvalidArgumentException('row_version musí být kladné celé číslo.');
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
            $this->assertEmployee($supplierId, $employeeId);
            $statement = $pdo->prepare(
                'SELECT row_version, insolvency_mode,
                        insolvency_payment_instruction_id
                   FROM payroll_enforcement_person_month_evidence
                  WHERE supplier_id = ? AND employee_id = ? AND period_start = ?
                  FOR UPDATE',
            );
            $statement->execute([$supplierId, $employeeId, $periodStart]);
            $rowValue = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rowValue)) {
                throw new \DomainException(
                    'Pro tento měsíc není schválené oddlužení ke zrušení.',
                );
            }
            $row = PayrollTimeValue::row($rowValue, 'insolvency_evidence');
            $currentVersion = PayrollTimeValue::int(
                $row['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            $instructionId = self::nullableIntValue(
                $row['insolvency_payment_instruction_id'] ?? null,
            );
            if (($row['insolvency_mode'] ?? null)
                    !== InsolvencyMode::ApprovedStandard->value
                || $instructionId === null
            ) {
                throw new \DomainException(
                    'Pro tento měsíc není schválené oddlužení ke zrušení.',
                );
            }
            if ($this->insolvencyInstructionWasUsed($supplierId, $instructionId)) {
                throw new \DomainException(
                    'Použitý platební pokyn oddlužení nelze změnit ani zrušit '
                    . 'v měsíční evidenci; použijte opravnou revizi.',
                );
            }
            $update = $pdo->prepare(
                'UPDATE payroll_enforcement_person_month_evidence
                    SET insolvency_mode = "none",
                        insolvency_decision_verified = 0,
                        insolvency_recipient_verified = 0,
                        insolvency_payment_instruction_id = NULL,
                        court_determined_amount_minor_units = NULL,
                        updated_by = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND employee_id = ? AND period_start = ?
                    AND row_version = ?',
            );
            $update->execute([
                $userId,
                $supplierId,
                $employeeId,
                $periodStart,
                $currentVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEnforcementConflictException($currentVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->monthEvidenceRow($supplierId, $employeeId, $periodStart)
            ?? throw new \RuntimeException('Oddlužení nebylo po zrušení nalezeno.');
    }

    /** @return array<string,mixed> */
    public function monthEvidence(
        int $supplierId,
        int $employeeId,
        string $period,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        $periodStart = self::periodStart($period);
        return $this->monthEvidenceRow($supplierId, $employeeId, $periodStart) ?? [
            'id' => null,
            'employee_id' => $employeeId,
            'period_start' => $periodStart,
            'claim_register_evidence_complete' => false,
            'dependants_evidence_complete' => false,
            'spouse_evidence_complete' => false,
            'pension_evidence' => PensionEvidence::Unknown->value,
            'has_multiple_payers' => false,
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'insolvency_mode' => InsolvencyMode::None->value,
            'insolvency_decision_verified' => false,
            'insolvency_recipient_verified' => false,
            'insolvency_payment_instruction_id' => null,
            'insolvency_employment_id' => null,
            'insolvency_institution_account_id' => null,
            'insolvency_decision_document_id' => null,
            'insolvency_payment_instruction_hash' => null,
            'court_determined_amount_minor_units' => null,
            'row_version' => null,
        ];
    }

    /**
     * @return array{
     *   employments:list<array<string,mixed>>,
     *   recipient_accounts:list<array<string,mixed>>
     * }
     */
    public function insolvencyOptions(
        int $supplierId,
        int $employeeId,
        string $period,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        return $this->insolvencyInstructions->options(
            $supplierId,
            $employeeId,
            self::periodStart($period),
        );
    }

    /** @return list<array<string,mixed>> */
    public function dependantsForEmployee(int $supplierId, int $employeeId): array
    {
        $this->assertEmployee($supplierId, $employeeId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, dependant_kind, valid_from, valid_to,
                    eligibility_verified, excluded_for_maintenance,
                    quarter_pension_evidence, quarter_pension_holder,
                    quarter_pension_kind, quarter_pension_documented_on,
                    row_version
               FROM payroll_enforcement_dependants
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY valid_from DESC, id DESC'
        );
        $stmt->execute([$supplierId, $employeeId]);
        return array_map(
            static fn (array $row): array => self::castBooleansAndIntegers(
                PayrollTimeValue::row($row, 'enforcement_dependant'),
                ['id', 'employee_id', 'row_version'],
                ['eligibility_verified', 'excluded_for_maintenance'],
            ),
            PayrollTimeValue::rows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'enforcement_dependants',
            ),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function addDependant(
        int $supplierId,
        int $employeeId,
        array $data,
    ): array {
        $kind = self::requiredString($data, 'dependant_kind');
        if (!in_array($kind, ['dependant', 'spouse_partner'], true)) {
            throw new \InvalidArgumentException('Neplatný druh vyživované osoby.');
        }
        $validFrom = self::requiredString($data, 'valid_from');
        self::assertDate($validFrom, 'valid_from');
        $validTo = self::nullableDate($data, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }
        $pension = self::quarterPensionFromInput($kind, $data);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange(
                $supplierId,
                $validFrom,
                $validTo ?? $validFrom,
            );
            $this->assertEmployee($supplierId, $employeeId);
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_enforcement_dependants
                    (supplier_id, employee_id, dependant_key, dependant_kind,
                     valid_from, valid_to, eligibility_verified,
                     excluded_for_maintenance, quarter_pension_evidence,
                     quarter_pension_holder, quarter_pension_kind,
                     quarter_pension_documented_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId, $employeeId, 'dependant_' . bin2hex(random_bytes(16)),
                $kind, $validFrom, $validTo,
                self::boolInt($data, 'eligibility_verified'),
                self::boolInt($data, 'excluded_for_maintenance'),
                $pension['evidence'],
                $pension['holder'],
                $pension['kind'],
                $pension['documented_on'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $evidenceColumn = $kind === 'spouse_partner'
                ? 'spouse_evidence_complete'
                : 'dependants_evidence_complete';
            $invalidate = $pdo->prepare(
                "UPDATE payroll_enforcement_person_month_evidence
                    SET {$evidenceColumn} = 0, row_version = row_version + 1
                  WHERE supplier_id = ? AND employee_id = ?
                    AND LAST_DAY(period_start) >= ?
                    AND period_start <= COALESCE(?, '9999-12-31')"
            );
            $invalidate->execute([
                $supplierId,
                $employeeId,
                $validFrom,
                $validTo,
            ]);
            $find = $pdo->prepare(
                'SELECT id, employee_id, dependant_kind, valid_from, valid_to,
                        eligibility_verified, excluded_for_maintenance,
                        quarter_pension_evidence, quarter_pension_holder,
                        quarter_pension_kind, quarter_pension_documented_on,
                        row_version
                   FROM payroll_enforcement_dependants
                  WHERE supplier_id = ? AND id = ?'
            );
            $find->execute([$supplierId, $id]);
            $value = $find->fetch(PDO::FETCH_ASSOC);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $value !== false
            ? self::castBooleansAndIntegers(PayrollTimeValue::row(
                $value,
                'enforcement_dependant',
            ), [
                'id', 'employee_id', 'row_version',
            ], ['eligibility_verified', 'excluded_for_maintenance'])
            : throw new \RuntimeException('Vyživovaná osoba nebyla po vytvoření nalezena.');
    }

    /**
     * Doložení důchodu podle nař. vlády č. 441/2024 Sb. u nového záznamu.
     *
     * Chybějící hodnota u manžela/partnera je záměrně `unknown`, ne
     * `not_documented`: klient, který pole neposílá, o novém právním stavu
     * neví a nesmí jeho jménem tvrdit, že důchod doložen není. `unknown`
     * čtvrtinu nezaloží a zároveň měsíc se srážkou shodí do ručního posouzení,
     * takže se stav nedá přehlédnout ani v jednom směru. U vyživovaných dětí
     * se podmínka neuplatní vůbec.
     *
     * @param array<string,mixed> $data
     * @return array{evidence:string,holder:?string,kind:?string,documented_on:?string}
     */
    private static function quarterPensionFromInput(string $kind, array $data): array
    {
        if ($kind !== 'spouse_partner') {
            return [
                'evidence' => SpousePensionEvidence::NotDocumented->value,
                'holder' => null,
                'kind' => null,
                'documented_on' => null,
            ];
        }

        $raw = $data['quarter_pension_evidence'] ?? null;
        if ($raw === null) {
            $evidence = SpousePensionEvidence::Unknown;
        } else {
            if (!is_string($raw)) {
                throw new \InvalidArgumentException(
                    'Neplatné doložení důchodu manžela/partnera.',
                );
            }
            $evidence = SpousePensionEvidence::tryFrom($raw)
                ?? throw new \InvalidArgumentException(
                    'Neplatné doložení důchodu manžela/partnera.',
                );
        }

        if ($evidence !== SpousePensionEvidence::Documented) {
            return [
                'evidence' => $evidence->value,
                'holder' => null,
                'kind' => null,
                'documented_on' => null,
            ];
        }

        $holder = self::requiredString($data, 'quarter_pension_holder');
        if (!in_array($holder, ['debtor', 'spouse_partner'], true)) {
            throw new \InvalidArgumentException(
                'Důchod musí být přiznán povinnému nebo jeho manželovi/partnerovi.',
            );
        }
        $pensionKind = self::requiredString($data, 'quarter_pension_kind');
        if (!in_array($pensionKind, [
            'old_age',
            'invalidity_second_degree',
            'invalidity_third_degree',
            'orphan',
        ], true)) {
            throw new \InvalidArgumentException(
                'Čtvrtinu zakládá jen starobní, invalidní 2./3. stupně nebo sirotčí důchod.',
            );
        }
        $documentedOn = self::nullableDate($data, 'quarter_pension_documented_on')
            ?? throw new \InvalidArgumentException(
                'U doloženého důchodu chybí datum doložení.',
            );

        return [
            'evidence' => $evidence->value,
            'holder' => $holder,
            'kind' => $pensionKind,
            'documented_on' => $documentedOn,
        ];
    }

    public function evidenceFor(
        int $supplierId,
        int $employeeId,
        string $period,
        string $paymentDate,
    ): EnforcementPersonMonthEvidence {
        return $this->evidenceForMany(
            $supplierId,
            [$employeeId],
            $period,
            $paymentDate,
        )[$employeeId];
    }

    /**
     * Exekuční evidence pro celou množinu osob — tři dotazy místo tří na osobu.
     *
     * Jediná cesta k evidenci: evidenceFor() volá tuhle metodu s jednoprvkovým
     * polem, takže se dávkový a jednotlivý výsledek nemůžou rozejít. Ve výsledku
     * je záznam pro KAŽDÉ požadované ID; osoba bez podkladů dostane tutéž prázdnou
     * evidenci, jakou vracela dotazovaná cesta.
     *
     * @param list<int> $employeeIds
     * @return array<int,EnforcementPersonMonthEvidence>
     */
    public function evidenceForMany(
        int $supplierId,
        array $employeeIds,
        string $period,
        string $paymentDate,
    ): array {
        $periodStart = self::periodStart($period);
        self::assertDate($paymentDate, 'payment_date');
        $unique = array_values(array_unique($employeeIds));
        if ($unique === []) {
            return [];
        }
        $evidenceRows = $this->monthEvidenceRows($supplierId, $unique, $periodStart);
        $claimRows = $this->activeClaimRows($supplierId, $unique, $periodStart, $paymentDate);
        $dependantRows = $this->dependantRows($supplierId, $unique, $paymentDate);

        $result = [];
        foreach ($unique as $employeeId) {
            $result[$employeeId] = self::assembleEvidence(
                $evidenceRows[$employeeId] ?? null,
                $claimRows[$employeeId] ?? [],
                $dependantRows[$employeeId] ?? [],
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed>|null $evidence
     * @param list<array<string,mixed>> $claimRows
     * @param list<array<string,mixed>> $dependantRows
     */
    private static function assembleEvidence(
        ?array $evidence,
        array $claimRows,
        array $dependantRows,
    ): EnforcementPersonMonthEvidence {
        $claims = [];
        $activeCaseEvidenceComplete = true;
        foreach ($claimRows as $row) {
            $activeCaseEvidenceComplete = $activeCaseEvidenceComplete
                && PayrollTimeValue::bool(
                    $row['case_evidence_complete'] ?? null,
                    'case_evidence_complete',
                );
            $claims[] = self::claimFromRow($row);
        }
        $dependants = 0;
        $spouse = false;
        $spousePension = SpousePensionEvidence::Documented;
        foreach ($dependantRows as $row) {
            if (
                !PayrollTimeValue::bool(
                    $row['eligibility_verified'] ?? null,
                    'eligibility_verified',
                )
                || PayrollTimeValue::bool(
                    $row['excluded_for_maintenance'] ?? null,
                    'excluded_for_maintenance',
                )
            ) {
                continue;
            }
            if (PayrollTimeValue::string(
                $row['dependant_kind'] ?? null,
                'dependant_kind',
            ) === 'spouse_partner') {
                $spouse = true;
                $spousePension = self::weakerSpousePension(
                    $spousePension,
                    SpousePensionEvidence::from(PayrollTimeValue::string(
                        $row['quarter_pension_evidence'] ?? null,
                        'quarter_pension_evidence',
                    )),
                );
            } else {
                ++$dependants;
            }
        }
        if (!$spouse) {
            $spousePension = SpousePensionEvidence::NotDocumented;
        }
        if ($evidence === null) {
            return new EnforcementPersonMonthEvidence(
                $claims,
                $dependants,
                false,
                $spouse,
                false,
                PensionEvidence::Unknown,
                false,
                null,
                false,
                false,
                InsolvencyInstruction::none(),
                $spousePension,
            );
        }

        return new EnforcementPersonMonthEvidence(
            $claims,
            $dependants,
            PayrollTimeValue::bool(
                $evidence['dependants_evidence_complete'] ?? null,
                'dependants_evidence_complete',
            ),
            $spouse,
            PayrollTimeValue::bool(
                $evidence['spouse_evidence_complete'] ?? null,
                'spouse_evidence_complete',
            ),
            PensionEvidence::from(PayrollTimeValue::string(
                $evidence['pension_evidence'] ?? null,
                'pension_evidence',
            )),
            PayrollTimeValue::bool(
                $evidence['has_multiple_payers'] ?? null,
                'has_multiple_payers',
            ),
            self::nullableIntValue($evidence['protected_amount_override_minor_units'] ?? null),
            PayrollTimeValue::bool(
                $evidence['protected_amount_override_verified'] ?? null,
                'protected_amount_override_verified',
            ),
            PayrollTimeValue::bool(
                $evidence['claim_register_evidence_complete'] ?? null,
                'claim_register_evidence_complete',
            ) && $activeCaseEvidenceComplete,
            new InsolvencyInstruction(
                InsolvencyMode::from(PayrollTimeValue::string(
                    $evidence['insolvency_mode'] ?? null,
                    'insolvency_mode',
                )),
                PayrollTimeValue::bool(
                    $evidence['insolvency_decision_verified'] ?? null,
                    'insolvency_decision_verified',
                ),
                PayrollTimeValue::bool(
                    $evidence['insolvency_recipient_verified'] ?? null,
                    'insolvency_recipient_verified',
                ),
                self::nullableIntValue($evidence['court_determined_amount_minor_units'] ?? null),
                self::nullableIntValue(
                    $evidence['insolvency_payment_instruction_id'] ?? null,
                ),
                self::nullableStringValue(
                    $evidence['insolvency_payment_instruction_hash'] ?? null,
                    'insolvency_payment_instruction_hash',
                ),
                self::nullableIntValue(
                    $evidence['insolvency_employment_id'] ?? null,
                ),
            ),
            $spousePension,
        );
    }

    /**
     * Souběh víc platných záznamů manžela/partnera je datová anomálie, ne
     * legitimní stav. Fail-closed: rozhoduje ta nejslabší evidence, aby se
     * čtvrtina nezaložila na jednom doloženém řádku vedle nedoloženého.
     */
    private static function weakerSpousePension(
        SpousePensionEvidence $left,
        SpousePensionEvidence $right,
    ): SpousePensionEvidence {
        $rank = [
            SpousePensionEvidence::Unknown->value => 0,
            SpousePensionEvidence::NotDocumented->value => 1,
            SpousePensionEvidence::Documented->value => 2,
        ];

        return $rank[$left->value] <= $rank[$right->value] ? $left : $right;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function activeClaimRows(
        int $supplierId,
        array $employeeIds,
        string $periodStart,
        string $paymentDate,
    ): array {
        $grouped = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $claimStmt = $this->db->pdo()->prepare(sprintf(
            "SELECT cl.id, cl.supplier_id, cl.case_id, cl.claim_key,
                    cl.enforcement_order_key, cl.legal_basis, cl.category,
                    GREATEST(
                        0,
                        cl.outstanding_minor_units - COALESCE((
                            SELECT SUM(
                                CASE
                                    WHEN ledger.entry_kind = 'withheld'
                                        THEN ledger.amount_minor_units
                                    WHEN ledger.entry_kind = 'released_to_employee'
                                        THEN -ledger.amount_minor_units
                                    WHEN ledger.entry_kind = 'adjustment'
                                        THEN ledger.amount_minor_units
                                    ELSE 0
                                END
                            )
                              FROM payroll_enforcement_ledger ledger
                              JOIN payroll_enforcement_month_results prior_result
                                ON prior_result.supplier_id = ledger.supplier_id
                               AND prior_result.id = ledger.month_result_id
                         LEFT JOIN payroll_run_revisions prior_revision
                                ON prior_revision.supplier_id = prior_result.supplier_id
                               AND prior_revision.id = prior_result.revision_id
                             WHERE ledger.supplier_id = cl.supplier_id
                               AND ledger.claim_id = cl.id
                               AND ledger.entry_kind IN (
                                   'withheld',
                                   'released_to_employee',
                                   'adjustment'
                               )
                               AND prior_result.period_start < ?
                               AND (
                                   prior_result.revision_id IS NULL
                                   OR prior_result.revision_id = (
                                       SELECT approved_revision.id
                                         FROM payroll_run_revisions approved_revision
                                        WHERE approved_revision.supplier_id =
                                              prior_result.supplier_id
                                          AND approved_revision.run_id =
                                              prior_revision.run_id
                                          AND approved_revision.status = 'approved'
                                        ORDER BY approved_revision.revision_no DESC
                                        LIMIT 1
                                   )
                               )
                        ), 0)
                    ) AS outstanding_minor_units,
                    cl.maintenance_weight_minor_units, cl.priority_date,
                    cl.order_issued_on, cl.legal_title_verified,
                    cl.order_or_notice_delivered,
                    cl.priority_classification_verified,
                    cl.agreement_verified, cl.due_monetary_claim_verified,
                    cl.is_active, cl.row_version, cl.created_at, cl.updated_at,
                    c.evidence_complete AS case_evidence_complete,
                    c.employee_id AS " . self::GROUP_KEY . "
               FROM payroll_enforcement_claims cl
               JOIN payroll_enforcement_cases c
                 ON c.supplier_id = cl.supplier_id AND c.id = cl.case_id
              WHERE cl.supplier_id = ? AND c.employee_id IN (%s) AND cl.is_active = 1
                AND c.status IN ('withhold_and_hold', 'remit', 'deferred_hold')
                AND c.effective_from <= ?
                AND (c.effective_to IS NULL OR c.effective_to >= ?)
                AND (
                    cl.legal_basis <> 'statutory'
                    OR cl.first_payer_delivered_on IS NULL
                    OR cl.first_payer_delivered_on <= ?
                )
              ORDER BY cl.priority_date, cl.id",
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $claimStmt->execute([
                $periodStart,
                $supplierId,
                ...$chunk,
                $paymentDate,
                $paymentDate,
                $paymentDate,
            ]);
            foreach (PayrollTimeValue::rows(
                $claimStmt->fetchAll(PDO::FETCH_ASSOC),
                'enforcement_claims',
            ) as $row) {
                $key = PayrollTimeValue::int(
                    $row[self::GROUP_KEY] ?? null,
                    self::GROUP_KEY,
                );
                unset($row[self::GROUP_KEY]);
                $grouped[$key][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function dependantRows(
        int $supplierId,
        array $employeeIds,
        string $paymentDate,
    ): array {
        $grouped = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $dependantStmt = $this->db->pdo()->prepare(sprintf(
                'SELECT dependant_kind, eligibility_verified, excluded_for_maintenance,
                        quarter_pension_evidence,
                        employee_id AS %s
                  FROM payroll_enforcement_dependants
                  WHERE supplier_id = ? AND employee_id IN (%s)
                    AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)',
                self::GROUP_KEY,
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $dependantStmt->execute([
                $supplierId,
                ...$chunk,
                $paymentDate,
                $paymentDate,
            ]);
            foreach (PayrollTimeValue::rows(
                $dependantStmt->fetchAll(PDO::FETCH_ASSOC),
                'enforcement_dependants',
            ) as $row) {
                $key = PayrollTimeValue::int(
                    $row[self::GROUP_KEY] ?? null,
                    self::GROUP_KEY,
                );
                unset($row[self::GROUP_KEY]);
                $grouped[$key][] = $row;
            }
        }

        return $grouped;
    }

    public function store(
        EnforcementPersonMonthRequest $request,
        PayrollGarnishmentCalculation $calculation,
        ?int $revisionId,
        string $idempotencyKey,
    ): int {
        $result = $calculation->result;
        if (
            $calculation->supplierId !== $request->supplierId
            || $calculation->employeeId !== $request->employeeId
            || $calculation->input->period !== $request->period
            || $calculation->input->paymentDate !== $request->paymentDate
            || trim($idempotencyKey) === ''
        ) {
            throw new \InvalidArgumentException('Výsledek srážek neodpovídá vstupu mzdového běhu.');
        }
        $periodStart = self::periodStart($request->period);
        $inputJson = CanonicalJson::encode($calculation->inputSnapshot());
        $resultJson = $result->toCanonicalJson();
        $inputHash = hash('sha256', $inputJson);
        $resultHash = hash('sha256', $resultJson);
        $idempotencyHash = hash('sha256', $idempotencyKey, true);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->yearClose->assertOpenForDateRange(
                $request->supplierId,
                $periodStart,
                $periodStart,
            );
            $existing = $pdo->prepare(
                'SELECT id, revision_id, input_snapshot_hash, result_snapshot_hash
                   FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND idempotency_key_hash = ? FOR UPDATE'
            );
            $existing->execute([$request->supplierId, $idempotencyHash]);
            $existingValue = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingValue !== false) {
                $existingRow = PayrollTimeValue::row(
                    $existingValue,
                    'enforcement_month_result',
                );
                if (
                    self::nullableIntValue($existingRow['revision_id'] ?? null)
                        !== $revisionId
                    || !hash_equals(
                        PayrollTimeValue::string(
                            $existingRow['input_snapshot_hash'] ?? null,
                            'input_snapshot_hash',
                        ),
                        $inputHash,
                    )
                    || !hash_equals(
                        PayrollTimeValue::string(
                            $existingRow['result_snapshot_hash'] ?? null,
                            'result_snapshot_hash',
                        ),
                        $resultHash,
                    )
                ) {
                    throw new \DomainException(
                        'Idempotency klíč už byl použit pro jiný výpočet srážek.',
                    );
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return PayrollTimeValue::int($existingRow['id'] ?? null, 'id');
            }
            $scope = $pdo->prepare(
                'SELECT id
                   FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND employee_id = ? AND period_start = ?
                    AND revision_id <=> ?
                  FOR UPDATE'
            );
            $scope->execute([
                $request->supplierId,
                $request->employeeId,
                $periodStart,
                $revisionId,
            ]);
            if ($scope->fetchColumn() !== false) {
                throw new \DomainException(
                    'Výsledek srážek pro zaměstnance, období a revizi už existuje.',
                );
            }
            $insert = $pdo->prepare(
                'INSERT INTO payroll_enforcement_month_results
                    (supplier_id, revision_id, employee_id,
                     insolvency_payment_instruction_id, period_start,
                     result_status, ruleset_id, ruleset_hash, input_snapshot_json,
                     input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                     total_withheld_minor_units, employee_payment_minor_units,
                     employer_fee_minor_units, idempotency_key_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $request->supplierId, $revisionId, $request->employeeId,
                $calculation->input->insolvency->paymentInstructionId,
                $periodStart,
                $result->status->value, $result->rulesetId, $result->rulesetHash,
                $inputJson, $inputHash, $resultJson, $resultHash,
                $result->totalWithheldMinorUnits, $result->employeePaymentMinorUnits,
                $result->employerFlatFeeMinorUnits, $idempotencyHash,
            ]);
            $resultId = (int) $pdo->lastInsertId();
            foreach ($result->allocations as $allocation) {
                $this->storeAllocation($request->supplierId, $resultId, $allocation);
            }
            $this->storeLedgerForResult(
                $request->supplierId,
                $request->employeeId,
                $resultId,
                $result,
                $calculation->input->insolvency,
                $idempotencyKey,
            );
            $this->assertStoredResultIntegrity(
                $request->supplierId,
                $resultId,
                $result,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $resultId;
        } catch (\Throwable $e) {
            self::rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    private function claimsForCase(int $supplierId, int $caseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, case_id, legal_basis, category, outstanding_minor_units,
                    maintenance_weight_minor_units, priority_date, first_payer_delivered_on, order_issued_on,
                    legal_title_verified, order_or_notice_delivered,
                    priority_classification_verified, agreement_verified,
                    due_monetary_claim_verified, is_active, row_version,
                    created_at, updated_at
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ?
              ORDER BY priority_date, id'
        );
        $stmt->execute([$supplierId, $caseId]);

        return array_map(static fn (array $row): array => self::castBooleansAndIntegers(
            $row,
            ['id', 'case_id', 'outstanding_minor_units',
                'maintenance_weight_minor_units', 'row_version'],
            ['legal_title_verified', 'order_or_notice_delivered',
                'priority_classification_verified', 'agreement_verified',
                'due_monetary_claim_verified', 'is_active'],
        ), PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'enforcement_claims',
        ));
    }

    /** @return array<string,mixed>|null */
    private function claimForCase(int $supplierId, int $caseId, int $claimId): ?array
    {
        foreach ($this->claimsForCase($supplierId, $caseId) as $claim) {
            if (PayrollTimeValue::int($claim['id'] ?? null, 'id') === $claimId) {
                return $claim;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function eventsForCase(int $supplierId, int $caseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, command_name, from_status, to_status, reason,
                    decision_document_id, actor_user_id, created_at
               FROM payroll_enforcement_events
              WHERE supplier_id = ? AND case_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$supplierId, $caseId]);
        return array_map(static fn (array $row): array => self::castBooleansAndIntegers(
            $row,
            ['id', 'decision_document_id', 'actor_user_id'],
            [],
        ), PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'enforcement_events',
        ));
    }

    /**
     * MZ-14-W08 — „sraženo / deponováno / odesláno / zbývá" pro jeden případ.
     * Odeslané peníze se nikdy neodvozují ze srážky, ale výhradně z potvrzených
     * úhrad platebního ledgeru.
     *
     * @return array{
     *   claims:list<array<string,mixed>>,
     *   withheld_minor:int,
     *   held_minor:int,
     *   liability_minor:int,
     *   settled_minor:int,
     *   original_minor:int,
     *   outstanding_minor:int,
     *   remaining_to_withhold_minor:int,
     *   remaining_minor:int
     * }
     */
    private function settlementForCase(int $supplierId, int $caseId): array
    {
        $claims = (new PayrollEnforcementPaymentRepository($this->db))
            ->settlementForCase($supplierId, $caseId);
        $totals = [
            'withheld_minor' => 0,
            'held_minor' => 0,
            'liability_minor' => 0,
            'settled_minor' => 0,
            'original_minor' => 0,
            'outstanding_minor' => 0,
            'remaining_to_withhold_minor' => 0,
            'remaining_minor' => 0,
        ];
        foreach ($claims as $claim) {
            if (!$claim['is_active']) {
                continue;
            }
            $totals['withheld_minor'] += $claim['withheld_minor'];
            $totals['held_minor'] += $claim['held_minor'];
            $totals['liability_minor'] += $claim['liability_minor'];
            $totals['settled_minor'] += $claim['settled_minor'];
            $totals['original_minor'] += $claim['original_minor'];
            $totals['outstanding_minor'] += $claim['outstanding_minor'];
            $totals['remaining_to_withhold_minor'] +=
                $claim['remaining_to_withhold_minor'];
            $totals['remaining_minor'] += $claim['remaining_minor'];
        }

        return ['claims' => $claims, ...$totals];
    }

    private function assertPaymentRecipientInstitution(
        int $supplierId,
        int $institutionId,
    ): void {
        if ($institutionId <= 0) {
            throw new \InvalidArgumentException(
                'Příjemce srážky musí být kladné číslo.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT institution_type FROM payroll_institutions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $institutionId]);
        $type = $stmt->fetchColumn();
        if ($type !== 'other_recipient') {
            throw new \InvalidArgumentException(
                'Příjemce srážky musí být záznam z katalogu platebních účtů '
                . 'institucí typu „ostatní příjemce".',
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function ledgerForCase(int $supplierId, int $caseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, claim_id, month_result_id, entry_kind, amount_minor_units,
                    actor_user_id, decision_event_id, created_at
               FROM payroll_enforcement_ledger
              WHERE supplier_id = ? AND case_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$supplierId, $caseId]);
        return array_map(static fn (array $row): array => self::castBooleansAndIntegers(
            $row,
            [
                'id', 'claim_id', 'month_result_id', 'amount_minor_units',
                'actor_user_id', 'decision_event_id',
            ],
            [],
        ), PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'enforcement_ledger',
        ));
    }

    /** @return array<string,mixed> */
    private function lockCase(int $supplierId, int $caseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $caseId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($value === false) {
            throw new \InvalidArgumentException('Exekuční případ nebyl nalezen.');
        }
        return PayrollTimeValue::row($value, 'enforcement_case');
    }

    /** @return array<string,mixed>|null */
    private function lockOwnedCase(int $supplierId, int $caseId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $caseId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        return $value === false
            ? null
            : PayrollTimeValue::row($value, 'enforcement_case');
    }

    /** @return array<string,mixed>|null */
    private function lockOwnedClaim(int $supplierId, int $caseId, int $claimId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $caseId, $claimId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        return $value === false
            ? null
            : PayrollTimeValue::row($value, 'enforcement_claim');
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $case
     */
    private function assertClaimCanBeMutated(
        int $supplierId,
        int $caseId,
        int $claimId,
        array $claim,
        array $case,
    ): void {
        if (PayrollTimeValue::string($case['status'] ?? null, 'status') !== 'received') {
            throw new PayrollEnforcementClaimMutationBlockedException(
                'case_started',
                'Pohledávku lze opravit nebo smazat jen před aktivací případu.',
            );
        }

        $footprints = [
            'payroll_enforcement_allocations' => 'allocation_exists',
            'payroll_enforcement_ledger' => 'ledger_exists',
        ];
        foreach ($footprints as $table => $code) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT 1 FROM {$table}
                  WHERE supplier_id = ? AND claim_id = ? LIMIT 1"
            );
            $stmt->execute([$supplierId, $claimId]);
            if ($stmt->fetchColumn() !== false) {
                throw new PayrollEnforcementClaimMutationBlockedException(
                    $code,
                    'Pohledávka už má mzdovou nebo účetní stopu a musí zůstat v historii.',
                );
            }
        }

        $claimKey = PayrollTimeValue::string($claim['claim_key'] ?? null, 'claim_key');
        $snapshot = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_enforcement_month_results result
              WHERE result.supplier_id = ?
                AND JSON_SEARCH(
                      result.input_snapshot_json,
                      'one',
                      ?,
                      NULL,
                      '$.claims[*].id'
                    ) IS NOT NULL
              LIMIT 1"
        );
        $snapshot->execute([$supplierId, $claimKey]);
        if ($snapshot->fetchColumn() !== false) {
            throw new PayrollEnforcementClaimMutationBlockedException(
                'payroll_result_exists',
                'Pohledávka už byla zmrazena ve mzdovém výsledku a musí zůstat v historii.',
            );
        }

        $liability = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND liability_kind = 'enforcement'
                AND liability_reference = ? LIMIT 1"
        );
        $liability->execute([
            $supplierId,
            "enforcement:c{$caseId}:cl{$claimId}",
        ]);
        if ($liability->fetchColumn() !== false) {
            throw new PayrollEnforcementClaimMutationBlockedException(
                'payment_footprint_exists',
                'Pohledávka už má platební stopu a musí zůstat v historii.',
            );
        }
    }

    /** @param array<string,mixed> $case */
    private function assertClaimTypeMatchesCase(
        DeductionLegalBasis $legalBasis,
        ClaimCategory $category,
        array $case,
    ): void {
        $expectedBasis = $case['case_kind'] === 'voluntary_agreement'
            ? DeductionLegalBasis::VoluntaryAgreement
            : DeductionLegalBasis::Statutory;
        if ($legalBasis !== $expectedBasis) {
            throw new \InvalidArgumentException(
                'Právní titul pohledávky neodpovídá typu případu.',
            );
        }
        if (
            $legalBasis === DeductionLegalBasis::VoluntaryAgreement
            && $category->isPriority()
        ) {
            throw new \InvalidArgumentException(
                'Dohoda o srážkách nemůže být vedena jako přednostní pohledávka.',
            );
        }
    }

    private static function assertMaintenanceWeight(
        ClaimCategory $category,
        ?int $weight,
    ): void {
        if ($category->requiresMaintenanceWeight() && $weight === null) {
            throw new \InvalidArgumentException(
                'Pohledávka výživného vyžaduje kladnou měsíční výši.',
            );
        }
    }

    private function invalidateCaseAfterClaimMutation(int $supplierId, int $caseId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_cases
                SET evidence_complete = 0, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $caseId]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollEnforcementClaimMutationBlockedException(
                'case_changed',
                'Exekuční případ se během opravy změnil.',
            );
        }
        $version = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ?'
        );
        $version->execute([$supplierId, $caseId]);
        return PayrollTimeValue::int($version->fetchColumn(), 'case_row_version');
    }

    private function throwClaimConflictOrNotFound(
        int $supplierId,
        int $caseId,
        int $claimId,
    ): never {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $caseId, $claimId]);
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new \InvalidArgumentException('Pohledávka nebyla nalezena.');
        }
        throw new PayrollEnforcementConflictException((int) $version);
    }

    private function throwClaimMutationDatabaseFailure(\PDOException $exception): never
    {
        $sqlStateValue = $exception->errorInfo[0] ?? $exception->getCode();
        $sqlState = is_string($sqlStateValue)
            ? $sqlStateValue
            : (is_int($sqlStateValue) ? (string) $sqlStateValue : '');
        if (!in_array($sqlState, ['23000', '45000'], true)) {
            throw $exception;
        }
        throw new PayrollEnforcementClaimMutationBlockedException(
            'concurrent_footprint_exists',
            'Pohledávka mezitím získala mzdovou, účetní nebo platební stopu.',
        );
    }

    /** @param array<string,mixed> $case */
    private function assertCaseCanBeDeleted(
        int $supplierId,
        int $caseId,
        array $case,
    ): void {
        $footprints = [
            'payroll_enforcement_claims' => [
                'claim_exists',
                'Případ nelze smazat, protože už obsahuje pohledávku. '
                . 'Zachovejte právní historii a případ případně zastavte.',
            ],
            'payroll_enforcement_events' => [
                'event_exists',
                'Případ nelze smazat, protože už má právně významnou změnu stavu. '
                . 'Zachovejte časovou osu a použijte zastavení případu.',
            ],
            'payroll_enforcement_case_documents' => [
                'document_exists',
                'Případ nelze smazat, protože už je propojený s rozhodnutím nebo dokladem. '
                . 'Zachovejte právní historii a použijte zastavení případu.',
            ],
            'payroll_enforcement_allocations' => [
                'allocation_exists',
                'Případ nelze smazat, protože už vstoupil do výpočtu a má alokaci srážky. '
                . 'Případ uzavřete standardním stavovým krokem.',
            ],
            'payroll_enforcement_ledger' => [
                'ledger_exists',
                'Případ nelze smazat, protože už obsahuje pohyb srážky. '
                . 'Případ uzavřete standardním stavovým krokem.',
            ],
        ];
        foreach ($footprints as $table => [$code, $message]) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT 1 FROM {$table}
                  WHERE supplier_id = ? AND case_id = ? LIMIT 1"
            );
            $stmt->execute([$supplierId, $caseId]);
            if ($stmt->fetchColumn() !== false) {
                throw new PayrollEnforcementDeletionBlockedException($code, $message);
            }
        }

        $liability = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND liability_kind = 'enforcement'
                AND liability_reference LIKE ? LIMIT 1"
        );
        $liability->execute([$supplierId, "enforcement:c{$caseId}:%"]);
        if ($liability->fetchColumn() !== false) {
            throw new PayrollEnforcementDeletionBlockedException(
                'payment_footprint_exists',
                'Případ nelze smazat, protože už z něj vznikl platební závazek '
                . 'nebo navazující platba. Případ uzavřete standardním stavovým krokem.',
            );
        }

        if (PayrollTimeValue::string($case['status'] ?? null, 'status') !== 'received') {
            throw new PayrollEnforcementDeletionBlockedException(
                'case_started',
                'Smazat lze jen případ, který je stále ve stavu „Přijato — čeká na ověření“. '
                . 'Tento případ už zachovejte v historii a případně jej zastavte.',
            );
        }
    }

    private function assertEmployee(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Zaměstnanec nebyl nalezen.');
        }
    }

    private function caseEvidenceIsComplete(int $supplierId, int $caseId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(
                      CASE
                        WHEN legal_basis = 'statutory'
                         AND priority_date IS NOT NULL
                         AND order_issued_on IS NOT NULL
                         AND legal_title_verified = 1
                         AND order_or_notice_delivered = 1
                         AND priority_classification_verified = 1
                         AND due_monetary_claim_verified = 1
                         AND enforcement_order_key IS NOT NULL
                        THEN 1
                        WHEN legal_basis = 'voluntary_agreement'
                         AND category = 'non_priority'
                         AND priority_date IS NOT NULL
                         AND priority_classification_verified = 1
                         AND agreement_verified = 1
                        THEN 1
                        ELSE 0
                      END
                    ) AS complete_count
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND is_active = 1"
        );
        $stmt->execute([$supplierId, $caseId]);
        $row = PayrollTimeValue::row(
            $stmt->fetch(PDO::FETCH_ASSOC),
            'enforcement_case_evidence',
        );
        $total = PayrollTimeValue::int($row['total'] ?? null, 'total');
        $complete = $row['complete_count'] === null
            ? 0
            : PayrollTimeValue::int($row['complete_count'], 'complete_count');
        return $total > 0 && $total === $complete;
    }

    private function throwConflictOrNotFound(int $supplierId, int $caseId): never
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $caseId]);
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new \InvalidArgumentException('Exekuční případ nebyl nalezen.');
        }
        throw new PayrollEnforcementConflictException((int) $version);
    }

    /** @return array<string,mixed> */
    private function orderForClaim(int $supplierId, int $caseId, int $claimId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT enforcement_order_key, priority_date, first_payer_delivered_on
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $caseId, $claimId]);
        $value = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($value === false) {
            throw new \InvalidArgumentException(
                'Referenční pohledávka stejného exekučního příkazu nebyla nalezena.',
            );
        }
        $reference = PayrollTimeValue::row($value, 'enforcement_order_reference');
        if (!is_string($reference['enforcement_order_key'] ?? null)
            || $reference['enforcement_order_key'] === '') {
            throw new \InvalidArgumentException(
                'Referenční pohledávka stejného exekučního příkazu nemá platný klíč.',
            );
        }
        return $reference;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $sameOrder
     * @return array{0:string,1:string}
     */
    private function newStatutoryPriority(array $data, ?array $sameOrder): array
    {
        $this->rejectClientPriorityDate($data);
        if ($sameOrder !== null) {
            $delivery = self::nullableStringValue(
                $sameOrder['first_payer_delivered_on'] ?? null,
                'first_payer_delivered_on',
            );
            if ($delivery === null) {
                throw new \InvalidArgumentException(
                    'Referenční pohledávka nemá datum doručení prvnímu plátci.',
                );
            }
            if (array_key_exists('first_payer_delivered_on', $data)
                && self::nullableDate($data, 'first_payer_delivered_on') !== $delivery) {
                throw new \InvalidArgumentException(
                    'Stejný exekuční příkaz musí převzít datum doručení prvnímu plátci.',
                );
            }
            return [$delivery, $delivery];
        }

        $delivery = self::nullableDate($data, 'first_payer_delivered_on');
        if ($delivery === null) {
            throw new \InvalidArgumentException(
                'Pole first_payer_delivered_on je pro zákonnou pohledávku povinné.',
            );
        }
        return [$delivery, $delivery];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $claim
     * @param array<string,mixed>|null $sameOrder
     * @return array{0:?string,1:?string}
     */
    private function existingStatutoryPriority(
        array $data,
        array $claim,
        ?array $sameOrder,
    ): array {
        $this->rejectClientPriorityDate($data);
        $storedDelivery = self::nullableStringValue(
            $claim['first_payer_delivered_on'] ?? null,
            'first_payer_delivered_on',
        );
        $storedPriority = self::nullableStringValue(
            $claim['priority_date'] ?? null,
            'priority_date',
        );
        $delivery = $storedDelivery;
        if (array_key_exists('first_payer_delivered_on', $data)) {
            $requestedDelivery = self::nullableDate($data, 'first_payer_delivered_on');
            if ($storedDelivery !== null && $requestedDelivery !== $storedDelivery) {
                throw new \InvalidArgumentException(
                    'Datum doručení prvnímu plátci nelze po založení pohledávky změnit.',
                );
            }
            if ($storedDelivery === null && $requestedDelivery !== null) {
                $delivery = $requestedDelivery;
            }
        }
        if ($sameOrder !== null) {
            $referenceDelivery = self::nullableStringValue(
                $sameOrder['first_payer_delivered_on'] ?? null,
                'first_payer_delivered_on',
            );
            if ($referenceDelivery === null
                || ($storedDelivery !== null && $referenceDelivery !== $storedDelivery)) {
                throw new \InvalidArgumentException(
                    'Stejný exekuční příkaz nemůže změnit datum doručení prvnímu plátci.',
                );
            }
            $delivery = $referenceDelivery;
        }
        return $delivery === null
            ? [$storedPriority, null]
            : [$delivery, $delivery];
    }

    /** @param array<string,mixed> $data */
    private function rejectClientPriorityDate(array $data): void
    {
        if (array_key_exists('priority_date', $data)) {
            throw new \InvalidArgumentException(
                'Priorita zákonné pohledávky se odvozuje z data doručení prvnímu plátci.',
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:?string,1:null}
     */
    private function voluntaryPriority(array $data): array
    {
        if (self::nullableDate($data, 'first_payer_delivered_on') !== null) {
            throw new \InvalidArgumentException(
                'Dobrovolná dohoda nemá datum doručení prvnímu plátci.',
            );
        }
        return [self::nullableDate($data, 'priority_date'), null];
    }

    /** @return array<string,mixed>|null */
    private function monthEvidenceRow(
        int $supplierId,
        int $employeeId,
        string $periodStart,
    ): ?array {
        return $this->monthEvidenceRows($supplierId, [$employeeId], $periodStart)[$employeeId]
            ?? null;
    }

    /**
     * Měsíční evidence pro celou množinu osob.
     *
     * `payroll_enforcement_person_month_evidence` má UNIQUE (supplier_id,
     * employee_id, period_start), takže na osobu připadá nejvýš jeden řádek —
     * stejně jako u původního fetch().
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>>
     */
    private function monthEvidenceRows(
        int $supplierId,
        array $employeeIds,
        string $periodStart,
    ): array {
        $rows = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT evidence.*,
                        instruction.employment_id AS insolvency_employment_id,
                        instruction.institution_account_id
                            AS insolvency_institution_account_id,
                        instruction.decision_document_id
                            AS insolvency_decision_document_id,
                        instruction.instruction_hash
                            AS insolvency_payment_instruction_hash
                   FROM payroll_enforcement_person_month_evidence evidence
              LEFT JOIN payroll_insolvency_payment_instructions instruction
                     ON instruction.supplier_id = evidence.supplier_id
                    AND instruction.id = evidence.insolvency_payment_instruction_id
                  WHERE evidence.supplier_id = ?
                    AND evidence.employee_id IN (%s)
                    AND evidence.period_start = ?',
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $stmt->execute([$supplierId, ...$chunk, $periodStart]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $value) {
                $row = PayrollTimeValue::row($value, 'enforcement_month_evidence');
                unset($row['updated_by']);
                $cast = self::castBooleansAndIntegers(
                    $row,
                    ['id', 'employee_id', 'protected_amount_override_minor_units',
                        'court_determined_amount_minor_units', 'row_version',
                        'insolvency_payment_instruction_id',
                        'insolvency_employment_id',
                        'insolvency_institution_account_id',
                        'insolvency_decision_document_id'],
                    ['claim_register_evidence_complete', 'dependants_evidence_complete',
                        'spouse_evidence_complete', 'has_multiple_payers',
                        'protected_amount_override_verified',
                        'insolvency_decision_verified', 'insolvency_recipient_verified'],
                );
                $employeeId = PayrollTimeValue::int(
                    $cast['employee_id'] ?? null,
                    'enforcement_month_evidence.employee_id',
                );
                $rows[$employeeId] ??= $cast;
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function claimFromRow(array $row): DeductionClaim
    {
        return new DeductionClaim(
            PayrollTimeValue::string($row['claim_key'] ?? null, 'claim_key'),
            DeductionLegalBasis::from(PayrollTimeValue::string(
                $row['legal_basis'] ?? null,
                'legal_basis',
            )),
            ClaimCategory::from(PayrollTimeValue::string(
                $row['category'] ?? null,
                'category',
            )),
            PayrollTimeValue::int(
                $row['outstanding_minor_units'] ?? null,
                'outstanding_minor_units',
            ),
            self::nullableStringValue($row['priority_date'] ?? null, 'priority_date'),
            PayrollTimeValue::bool(
                $row['legal_title_verified'] ?? null,
                'legal_title_verified',
            ),
            PayrollTimeValue::bool(
                $row['order_or_notice_delivered'] ?? null,
                'order_or_notice_delivered',
            ),
            self::nullableStringValue($row['order_issued_on'] ?? null, 'order_issued_on'),
            PayrollTimeValue::bool(
                $row['priority_classification_verified'] ?? null,
                'priority_classification_verified',
            ),
            PayrollTimeValue::bool(
                $row['agreement_verified'] ?? null,
                'agreement_verified',
            ),
            self::nullableIntValue($row['maintenance_weight_minor_units'] ?? null),
            PayrollTimeValue::bool(
                $row['due_monetary_claim_verified'] ?? null,
                'due_monetary_claim_verified',
            ),
            PayrollTimeValue::bool($row['is_active'] ?? null, 'is_active'),
            self::nullableStringValue(
                $row['enforcement_order_key'] ?? null,
                'enforcement_order_key',
            ),
        );
    }

    private function storeAllocation(
        int $supplierId,
        int $resultId,
        GarnishmentAllocation $allocation,
    ): void {
        $lookup = $this->db->pdo()->prepare(
            'SELECT id, case_id FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND claim_key = ?'
        );
        $lookup->execute([$supplierId, $allocation->claimId]);
        $claimValue = $lookup->fetch(PDO::FETCH_ASSOC);
        $claim = $claimValue === false
            ? null
            : PayrollTimeValue::row($claimValue, 'enforcement_claim');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_allocations
                (supplier_id, month_result_id, case_id, claim_id, allocation_key,
                 first_pool_minor_units, second_pool_minor_units, total_minor_units)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (
            $claim === null
            && $allocation->claimId !== self::INSOLVENCY_ALLOCATION_KEY
        ) {
            throw new \DomainException(
                'Výsledek odkazuje na pohledávku mimo evidenci zaměstnavatele.',
            );
        }
        $stmt->execute([
            $supplierId,
            $resultId,
            $claim === null
                ? null
                : PayrollTimeValue::int($claim['case_id'] ?? null, 'case_id'),
            $claim === null
                ? null
                : PayrollTimeValue::int($claim['id'] ?? null, 'claim_id'),
            $allocation->claimId,
            $allocation->firstPoolMinorUnits,
            $allocation->secondPoolMinorUnits,
            $allocation->totalMinorUnits,
        ]);
    }

    private function storeLedgerForResult(
        int $supplierId,
        int $employeeId,
        int $resultId,
        GarnishmentResult $result,
        InsolvencyInstruction $insolvency,
        string $idempotencyKey,
    ): void {
        foreach ($result->allocations as $allocation) {
            if ($allocation->totalMinorUnits <= 0) {
                continue;
            }
            $lookup = $this->db->pdo()->prepare(
                'SELECT cl.id AS claim_id, c.id AS case_id, c.status
                   FROM payroll_enforcement_claims cl
                   JOIN payroll_enforcement_cases c
                     ON c.supplier_id = cl.supplier_id AND c.id = cl.case_id
                  WHERE cl.supplier_id = ? AND c.employee_id = ?
                    AND cl.claim_key = ?'
            );
            $lookup->execute([$supplierId, $employeeId, $allocation->claimId]);
            $claimValue = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($claimValue === false) {
                if ($allocation->claimId !== self::INSOLVENCY_ALLOCATION_KEY) {
                    throw new \DomainException(
                        'Výsledek odkazuje na pohledávku mimo evidenci zaměstnavatele.',
                    );
                }
                $this->insertLedger(
                    $supplierId,
                    null,
                    null,
                    $resultId,
                    'withheld',
                    $allocation->totalMinorUnits,
                    "{$idempotencyKey}:withheld:{$allocation->claimId}",
                );
                if ($insolvency->hasImmutablePaymentInstruction()) {
                    continue;
                }
                $this->insertLedger(
                    $supplierId,
                    null,
                    null,
                    $resultId,
                    'held',
                    $allocation->totalMinorUnits,
                    "{$idempotencyKey}:held:{$allocation->claimId}",
                );
                continue;
            }
            $claim = PayrollTimeValue::row($claimValue, 'enforcement_claim');
            $this->insertLedger(
                $supplierId,
                PayrollTimeValue::int($claim['case_id'] ?? null, 'case_id'),
                PayrollTimeValue::int($claim['claim_id'] ?? null, 'claim_id'),
                $resultId,
                'withheld',
                $allocation->totalMinorUnits,
                "{$idempotencyKey}:withheld:{$allocation->claimId}",
            );
            if (PayrollTimeValue::string(
                $claim['status'] ?? null,
                'status',
            ) !== EnforcementCaseStatus::Remit->value) {
                $this->insertLedger(
                    $supplierId,
                    PayrollTimeValue::int($claim['case_id'] ?? null, 'case_id'),
                    PayrollTimeValue::int($claim['claim_id'] ?? null, 'claim_id'),
                    $resultId,
                    'held',
                    $allocation->totalMinorUnits,
                    "{$idempotencyKey}:held:{$allocation->claimId}",
                );
            }
        }
        if ($result->employerFlatFeeMinorUnits > 0) {
            $this->insertLedger(
                $supplierId,
                null,
                null,
                $resultId,
                'employer_fee',
                $result->employerFlatFeeMinorUnits,
                "{$idempotencyKey}:employer-fee",
            );
        }
    }

    private function insertLedger(
        int $supplierId,
        ?int $caseId,
        ?int $claimId,
        int $resultId,
        string $entryKind,
        int $amount,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?int $decisionEventId = null,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_ledger
                (supplier_id, case_id, claim_id, month_result_id, entry_kind,
                 amount_minor_units, idempotency_key_hash, actor_user_id,
                 decision_event_id)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $caseId, $claimId, $resultId, $entryKind, $amount,
            hash('sha256', $idempotencyKey, true),
            $actorUserId,
            $decisionEventId,
        ]);
    }

    private function releaseHeldForRemittance(
        int $supplierId,
        int $caseId,
        int $decisionEventId,
        ?int $actorUserId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            "SELECT ledger.month_result_id, ledger.claim_id, ledger.entry_kind,
                    ledger.amount_minor_units
               FROM payroll_enforcement_ledger ledger
               JOIN payroll_enforcement_month_results month_result
                 ON month_result.supplier_id = ledger.supplier_id
                AND month_result.id = ledger.month_result_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = month_result.supplier_id
                AND revision.id = month_result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE ledger.supplier_id = ? AND ledger.case_id = ?
                AND ledger.claim_id IS NOT NULL
                AND ledger.entry_kind IN (
                  'held','released_for_remittance','remitted','released_to_employee'
                )
                AND revision.status = 'approved'
                AND revision.revision_no = run.current_revision_no
              ORDER BY ledger.month_result_id, ledger.claim_id, ledger.id
              FOR UPDATE"
        );
        $statement->execute([$supplierId, $caseId]);
        $balances = [];
        foreach (PayrollTimeValue::rows(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'enforcement_held_ledger',
        ) as $row) {
            $resultId = PayrollTimeValue::int(
                $row['month_result_id'] ?? null,
                'month_result_id',
            );
            $claimId = PayrollTimeValue::int($row['claim_id'] ?? null, 'claim_id');
            $key = "{$resultId}:{$claimId}";
            $amount = PayrollTimeValue::int(
                $row['amount_minor_units'] ?? null,
                'amount_minor_units',
            );
            $balances[$key] ??= [
                'result_id' => $resultId,
                'claim_id' => $claimId,
                'held' => 0,
                'released' => 0,
                'remitted' => 0,
                'returned' => 0,
            ];
            $bucket = match (PayrollTimeValue::string(
                $row['entry_kind'] ?? null,
                'entry_kind',
            )) {
                'held' => 'held',
                'released_for_remittance' => 'released',
                'remitted' => 'remitted',
                'released_to_employee' => 'returned',
                default => throw new \UnexpectedValueException(
                    'Neznámý druh pohybu depozita exekuce.',
                ),
            };
            $balances[$key][$bucket] += $amount;
        }

        foreach ($balances as $balance) {
            $amount = $balance['held'] - $balance['returned']
                - max($balance['released'], $balance['remitted']);
            if ($amount <= 0) {
                continue;
            }
            $this->insertLedger(
                $supplierId,
                $caseId,
                $balance['claim_id'],
                $balance['result_id'],
                'released_for_remittance',
                $amount,
                "deposit-release:event:{$decisionEventId}:result:{$balance['result_id']}"
                    . ":claim:{$balance['claim_id']}",
                $actorUserId,
                $decisionEventId,
            );
        }
    }

    private function assertStoredResultIntegrity(
        int $supplierId,
        int $resultId,
        GarnishmentResult $result,
    ): void {
        $allocationStmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(total_minor_units), 0)
               FROM payroll_enforcement_allocations
              WHERE supplier_id = ? AND month_result_id = ? FOR UPDATE'
        );
        $allocationStmt->execute([$supplierId, $resultId]);
        $ledgerStmt = $this->db->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN entry_kind = 'withheld'
                    THEN amount_minor_units ELSE 0 END), 0) AS withheld_total,
                COALESCE(SUM(CASE WHEN entry_kind = 'employer_fee'
                    THEN amount_minor_units ELSE 0 END), 0) AS employer_fee_total,
                COALESCE(SUM(CASE WHEN entry_kind = 'held'
                    THEN amount_minor_units ELSE 0 END), 0) AS held_total
               FROM payroll_enforcement_ledger
              WHERE supplier_id = ? AND month_result_id = ?
              FOR UPDATE"
        );
        $ledgerStmt->execute([$supplierId, $resultId]);
        $ledger = PayrollTimeValue::row(
            $ledgerStmt->fetch(PDO::FETCH_ASSOC),
            'enforcement_result_ledger',
        );

        PayrollEnforcementStoredResultIntegrity::assertConsistent(
            $result->totalWithheldMinorUnits,
            $result->employerFlatFeeMinorUnits,
            PayrollTimeValue::int(
                $allocationStmt->fetchColumn(),
                'allocation_total',
            ),
            PayrollTimeValue::int(
                $ledger['withheld_total'] ?? null,
                'withheld_total',
            ),
            PayrollTimeValue::int(
                $ledger['employer_fee_total'] ?? null,
                'employer_fee_total',
            ),
            PayrollTimeValue::int(
                $ledger['held_total'] ?? null,
                'held_total',
            ),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castCase(array $row): array
    {
        return self::castBooleansAndIntegers(
            $row,
            ['id', 'employee_id', 'row_version', 'claim_count',
                'outstanding_minor_units', 'recipient_institution_id',
                'created_by', 'updated_by'],
            ['evidence_complete', 'recipient_verified'],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $integers
     * @param list<string> $booleans
     * @return array<string,mixed>
     */
    private static function castBooleansAndIntegers(
        array $row,
        array $integers,
        array $booleans,
    ): array {
        foreach ($integers as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null
                    ? null
                    : PayrollTimeValue::int($row[$key], $key);
            }
        }
        foreach ($booleans as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = PayrollTimeValue::bool($row[$key], $key);
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Pole {$key} je povinné.");
        }
        return trim($value);
    }

    /** @param array<string,mixed> $data */
    private static function nonNegativeInt(array $data, string $key): int
    {
        $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if (!is_int($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být nezáporné celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullablePositiveInt(array $data, string $key): ?int
    {
        $raw = $data[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být kladné celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableNonNegativeInt(array $data, string $key): ?int
    {
        $raw = $data[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                "Pole {$key} musí být nezáporné celé číslo.",
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function boolInt(array $data, string $key): int
    {
        $value = $data[$key] ?? false;
        if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            throw new \InvalidArgumentException("Pole {$key} musí být boolean.");
        }
        return (int) (bool) $value;
    }

    /** @param array<string,mixed> $data */
    private static function boolValue(array $data, string $key): bool
    {
        return self::boolInt($data, $key) === 1;
    }

    /** @param array<string,mixed> $data */
    private function hasInsolvencyPaymentTarget(array $data): bool
    {
        foreach ([
            'insolvency_payment_instruction_id',
            'insolvency_employment_id',
            'insolvency_institution_account_id',
            'insolvency_decision_document_id',
        ] as $field) {
            if (($data[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function insolvencyInstructionWasUsed(
        int $supplierId,
        int $instructionId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_enforcement_month_results
              WHERE supplier_id = ? AND insolvency_payment_instruction_id = ?
              LIMIT 1',
        );
        $statement->execute([$supplierId, $instructionId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $data */
    private static function nullableDate(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
        self::assertDate($value, $key);
        return $value;
    }

    private static function assertDate(string $value, string $key): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
    }

    private function assertFactDatesOpen(int $supplierId, ?string ...$dates): void
    {
        foreach ($dates as $date) {
            if ($date !== null) {
                $this->yearClose->assertOpenForDateRange($supplierId, $date, $date);
            }
        }
    }

    private static function periodStart(string $period): string
    {
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('Období musí mít formát YYYY-MM.');
        }
        return "{$period}-01";
    }

    private static function nullableIntValue(mixed $value): ?int
    {
        return $value === null ? null : PayrollTimeValue::int($value, 'nullable_integer');
    }

    private static function nullableStringValue(mixed $value, string $field): ?string
    {
        return $value === null ? null : PayrollTimeValue::string($value, $field);
    }

    private function storeDecisionDocumentLink(
        int $supplierId,
        int $caseId,
        EnforcementCaseCommand $command,
        ?EnforcementDecisionDocumentReference $document,
        ?int $userId,
    ): ?int {
        if ($document === null) {
            return null;
        }
        $evidenceKind = $command->evidenceKind();
        if ($evidenceKind === null) {
            throw new \InvalidArgumentException(
                'Tento přechod nepřijímá rozhodnutí z dokumentů.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_case_documents
                (supplier_id, case_id, dms_document_id, evidence_kind,
                 document_sha256, verified_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $caseId,
            $document->documentId,
            $evidenceKind,
            $document->sha256,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private static function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
