<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Net\DeductionAgreementCommand;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use PDO;
use PDOException;

/**
 * CRUD a lifecycle dohod o srážkách (MZ-13-W03).
 *
 * Řádek dohody je anchorem append-only ledgeru, takže se NEDUPLIKUJE do nových
 * identit; každá změna i přechod stavu místo toho zapíše novou účinnou verzi do
 * `payroll_deduction_agreement_versions`. Data, ze kterých se počítala schválená
 * mzda, žijí v neměnném `input_snapshot` revize — změna dohody je proto nemůže
 * přepsat ani zpětně změnit výsledek.
 */
final class PayrollDeductionAgreementRepository
{
    private const SAVEPOINT = 'payroll_deduction_agreement_repository';

    private const COLUMNS = 'agreement.id, agreement.supplier_id, agreement.employee_id,
        agreement.agreement_reference, agreement.title, agreement.deduction_kind,
        agreement.status, agreement.priority_no, agreement.requested_minor,
        agreement.basis_points, agreement.basis_amount_minor,
        agreement.total_limit_minor, agreement.withheld_total_minor,
        agreement.valid_from, agreement.valid_to, agreement.delivered_on,
        agreement.recipient_reference,
        agreement.note, agreement.row_version, agreement.version_no,
        agreement.created_at, agreement.updated_at';

    /**
     * Strop stránky seznamu dohod. Dohody o srážkách jsou pracovní agenda —
     * účetní prochází desítky živých dohod, ne všechny, co firma kdy uzavřela.
     * Sto řádků pokryje i velkou firmu s jednou stránkou navíc.
     */
    public const LIST_MAX_LIMIT = 100;

    public const LIST_DEFAULT_LIMIT = 50;

    public function __construct(private readonly Connection $db) {}

    /**
     * Seznam dohod o srážkách se stránkováním.
     *
     * Oba filtry jsou volitelné, takže volání bez parametrů četlo VŠECHNY dohody,
     * které firma kdy uzavřela — objem roste s počtem zaměstnanců krát doba
     * provozu. Strop se proto uplatňuje už tady, ne až u volajícího.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listAgreements(
        int $supplierId,
        ?int $employeeId = null,
        ?DeductionAgreementStatus $status = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $where = ' WHERE agreement.supplier_id = ?';
        $params = [$supplierId];
        if ($employeeId !== null) {
            $where .= ' AND agreement.employee_id = ?';
            $params[] = $employeeId;
        }
        if ($status !== null) {
            $where .= ' AND agreement.status = ?';
            $params[] = $status->value;
        }

        // Tytéž filtry i tentýž povinný JOIN na zaměstnance jako ve stránkovaném
        // dotazu, jinak by `total` neodpovídal seznamu.
        $from = ' FROM payroll_deduction_agreements agreement
                  JOIN payroll_employees employee
                    ON employee.supplier_id = agreement.supplier_id
                   AND employee.id = agreement.employee_id';
        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNS . ', employee.full_name'
            . $from
            . $where
            . ' ORDER BY employee.full_name, agreement.priority_no, agreement.id
                LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_values(array_map(
            self::present(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', employee.full_name
               FROM payroll_deduction_agreements agreement
               JOIN payroll_employees employee
                 ON employee.supplier_id = agreement.supplier_id
                AND employee.id = agreement.employee_id
              WHERE agreement.supplier_id = ? AND agreement.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $agreement = self::present($row);
        $agreement['versions'] = $this->versions($supplierId, $id);
        $agreement['ledger'] = $this->ledger($supplierId, $id);

        return $agreement;
    }

    /**
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        int $employeeId,
        DeductionAgreementTerms $terms,
        DeductionAgreementStatus $status,
        ?int $userId,
    ): array {
        if ($status !== DeductionAgreementStatus::Draft
            && $status !== DeductionAgreementStatus::Active
        ) {
            throw new \InvalidArgumentException(
                'Novou dohodu lze založit jen jako návrh nebo aktivní.',
            );
        }

        return $this->transactional(function () use (
            $supplierId,
            $employeeId,
            $terms,
            $status,
            $userId,
        ): array {
            $this->assertEmployee($supplierId, $employeeId);
            try {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_deduction_agreements
                        (supplier_id, employee_id, agreement_reference, title,
                         deduction_kind, status, priority_no, requested_minor,
                         basis_points, basis_amount_minor, total_limit_minor,
                         valid_from, valid_to, delivered_on, recipient_reference,
                         note, version_no, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $employeeId,
                    $terms->agreementReference,
                    $terms->title,
                    $terms->deductionKind,
                    $status->value,
                    $terms->priorityNo,
                    $terms->requestedMinor,
                    $terms->basisPoints,
                    $terms->basisAmountMinor,
                    $terms->totalLimitMinor,
                    $terms->validFrom,
                    $terms->validTo,
                    $terms->deliveredOn,
                    $terms->recipientReference,
                    $terms->note,
                    $userId,
                    $userId,
                ]);
            } catch (PDOException $e) {
                if (self::isDuplicateKey($e)) {
                    throw new \DomainException(
                        'Zaměstnanec už má dohodu se stejným identifikátorem.',
                        previous: $e,
                    );
                }
                throw $e;
            }
            $id = (int) $this->db->pdo()->lastInsertId();
            $this->appendVersion($supplierId, $id, 'created', $terms->validFrom, null, $userId);

            return $this->requireAgreement($supplierId, $id);
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        int $id,
        DeductionAgreementTerms $terms,
        int $rowVersion,
        ?string $effectiveFrom,
        ?string $reason,
        ?int $userId,
    ): array {
        return $this->transactional(function () use (
            $supplierId,
            $id,
            $terms,
            $rowVersion,
            $effectiveFrom,
            $reason,
            $userId,
        ): array {
            $current = $this->lock($supplierId, $id);
            $status = DeductionAgreementStatus::from((string) $current['status']);
            if ($status->isTerminal()) {
                throw new \DomainException(
                    'Ukončenou ani zrušenou dohodu už nelze měnit.',
                );
            }
            $withheld = (int) $current['withheld_total_minor'];
            $currentDeliveredOn = $current['delivered_on'] === null
                ? null
                : (string) $current['delivered_on'];
            if ($withheld > 0
                && ($terms->deductionKind !== (string) $current['deduction_kind']
                    || $terms->validFrom !== (string) $current['valid_from'])
            ) {
                throw new \DomainException(
                    'Dohoda už byla použita ve schválené mzdě — titul srážky ani '
                    . 'začátek účinnosti už měnit nelze.',
                );
            }
            // Den doručení určuje POŘADÍ dohody vůči exekucím (§ 280 odst. 5
            // o. s. ř.). Jakmile podle něj proběhla schválená srážka, změna by
            // zpětně přepsala rozvrh, který už dostali věřitelé — doplnit ho
            // proto jde jen do dohody, která ještě nic nesrazila. Doplnění
            // chybějícího data u nesražené dohody je naopak vítané: legacy
            // dohody bez něj se řadí až za všechny exekuce.
            if ($withheld > 0 && $terms->deliveredOn !== $currentDeliveredOn) {
                throw new \DomainException(
                    'Dohoda už byla použita ve schválené mzdě — den doručení '
                    . 'plátci mzdy už měnit nelze.',
                );
            }
            if ($terms->totalLimitMinor !== null && $terms->totalLimitMinor < $withheld) {
                throw new \DomainException(
                    'Limit dohody nesmí klesnout pod již sražené částky.',
                );
            }
            if ($terms->validTo !== null && $terms->validTo < $terms->validFrom) {
                throw new \DomainException('Konec účinnosti nesmí předcházet začátku.');
            }

            // `agreement_reference` je stabilní identita dohody vůči ledgeru
            // a idempotenci schvalování — update ji záměrně nemění.
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_deduction_agreements
                    SET title = ?, deduction_kind = ?,
                        priority_no = ?, requested_minor = ?, basis_points = ?,
                        basis_amount_minor = ?, total_limit_minor = ?,
                        valid_from = ?, valid_to = ?, delivered_on = ?,
                        recipient_reference = ?,
                        note = ?, row_version = row_version + 1,
                        version_no = version_no + 1, updated_by = ?
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([
                $terms->title,
                $terms->deductionKind,
                $terms->priorityNo,
                $terms->requestedMinor,
                $terms->basisPoints,
                $terms->basisAmountMinor,
                $terms->totalLimitMinor,
                $terms->validFrom,
                $terms->validTo,
                $terms->deliveredOn,
                $terms->recipientReference,
                $terms->note,
                $userId,
                $supplierId,
                $id,
                $rowVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollDeductionAgreementConflictException(
                    (int) $current['row_version'],
                );
            }
            $this->appendVersion(
                $supplierId,
                $id,
                'updated',
                $effectiveFrom ?? $terms->validFrom,
                $reason,
                $userId,
            );

            return $this->requireAgreement($supplierId, $id);
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function transition(
        int $supplierId,
        int $id,
        DeductionAgreementCommand $command,
        int $rowVersion,
        ?string $effectiveOn,
        ?string $reason,
        ?int $userId,
    ): array {
        return $this->transactional(function () use (
            $supplierId,
            $id,
            $command,
            $rowVersion,
            $effectiveOn,
            $reason,
            $userId,
        ): array {
            $current = $this->lock($supplierId, $id);
            $status = DeductionAgreementStatus::from((string) $current['status']);
            if (!in_array($status, $command->allowedFrom(), true)) {
                throw new \DomainException(
                    'Dohoda o srážce není ve stavu, ze kterého lze tento krok provést.',
                );
            }
            if ($command->requiresEmptyLedger()
                && ((int) $current['withheld_total_minor'] > 0
                    || $this->ledgerCount($supplierId, $id) > 0)
            ) {
                throw new \DomainException(
                    'Dohodu s historií srážek nelze zrušit — lze ji jen ukončit.',
                );
            }

            $validFrom = (string) $current['valid_from'];
            $validTo = $current['valid_to'] === null ? null : (string) $current['valid_to'];
            $effective = $effectiveOn ?? date('Y-m-d');
            if ($command->closesValidity()) {
                if ($effective < $validFrom) {
                    throw new \DomainException(
                        'Ukončení nesmí předcházet začátku účinnosti dohody.',
                    );
                }
                $validTo = $effective;
            }

            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_deduction_agreements
                    SET status = ?, valid_to = ?, row_version = row_version + 1,
                        version_no = version_no + 1, updated_by = ?
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([
                $command->target()->value,
                $validTo,
                $userId,
                $supplierId,
                $id,
                $rowVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollDeductionAgreementConflictException(
                    (int) $current['row_version'],
                );
            }
            $this->appendVersion(
                $supplierId,
                $id,
                $command->changeKind(),
                $effective,
                $reason,
                $userId,
            );

            return $this->requireAgreement($supplierId, $id);
        });
    }

    /** @return list<array<string,mixed>> */
    public function versions(int $supplierId, int $agreementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, version_no, change_kind, title, deduction_kind, status,
                    priority_no, requested_minor, basis_points, basis_amount_minor,
                    total_limit_minor, withheld_total_minor, valid_from, valid_to,
                    delivered_on, recipient_reference, note, effective_from, reason,
                    actor_user_id, created_at
               FROM payroll_deduction_agreement_versions
              WHERE supplier_id = ? AND agreement_id = ?
              ORDER BY version_no'
        );
        $stmt->execute([$supplierId, $agreementId]);

        return array_values(array_map(
            static function (array $row): array {
                foreach ([
                    'id', 'version_no', 'priority_no', 'requested_minor',
                    'withheld_total_minor',
                ] as $field) {
                    $row[$field] = (int) $row[$field];
                }
                foreach ([
                    'basis_points', 'basis_amount_minor', 'total_limit_minor',
                    'actor_user_id',
                ] as $field) {
                    $row[$field] = $row[$field] === null ? null : (int) $row[$field];
                }

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function ledger(int $supplierId, int $agreementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.id, ledger.revision_id, ledger.event_kind,
                    ledger.amount_minor, ledger.source_ledger_id, ledger.created_at
               FROM payroll_deduction_ledger ledger
              WHERE ledger.supplier_id = ? AND ledger.agreement_id = ?
              ORDER BY ledger.id'
        );
        $stmt->execute([$supplierId, $agreementId]);

        return array_values(array_map(
            static function (array $row): array {
                foreach (['id', 'revision_id', 'amount_minor'] as $field) {
                    $row[$field] = (int) $row[$field];
                }
                $row['source_ledger_id'] = $row['source_ledger_id'] === null
                    ? null
                    : (int) $row['source_ledger_id'];

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed> */
    private function requireAgreement(int $supplierId, int $id): array
    {
        return $this->find($supplierId, $id)
            ?? throw new \OutOfBoundsException('Dohoda o srážce nebyla nalezena.');
    }

    /** @return array<string,mixed> */
    private function lock(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_deduction_agreements
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Dohoda o srážce nebyla nalezena.');
        }

        return $row;
    }

    private function assertEmployee(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \OutOfBoundsException('Zaměstnanec nebyl nalezen.');
        }
    }

    private function ledgerCount(int $supplierId, int $agreementId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_deduction_ledger
              WHERE supplier_id = ? AND agreement_id = ?'
        );
        $stmt->execute([$supplierId, $agreementId]);

        return (int) $stmt->fetchColumn();
    }

    private function appendVersion(
        int $supplierId,
        int $agreementId,
        string $changeKind,
        string $effectiveFrom,
        ?string $reason,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_deduction_agreement_versions
                (supplier_id, agreement_id, employee_id, version_no, change_kind,
                 title, deduction_kind, status, priority_no, requested_minor,
                 basis_points, basis_amount_minor, total_limit_minor,
                 withheld_total_minor, valid_from, valid_to, delivered_on,
                 recipient_reference, note, effective_from, reason, actor_user_id)
             SELECT supplier_id, id, employee_id, version_no, ?, title,
                    deduction_kind, status, priority_no, requested_minor,
                    basis_points, basis_amount_minor, total_limit_minor,
                    withheld_total_minor, valid_from, valid_to, delivered_on,
                    recipient_reference, note, ?, ?, ?
               FROM payroll_deduction_agreements
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([
            $changeKind,
            $effectiveFrom,
            $reason,
            $userId,
            $supplierId,
            $agreementId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \LogicException('Verzi dohody o srážce se nepodařilo zapsat.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'employee_id', 'priority_no', 'requested_minor',
            'withheld_total_minor', 'row_version', 'version_no',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['basis_points', 'basis_amount_minor', 'total_limit_minor'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $row['remaining_limit_minor'] = $row['total_limit_minor'] === null
            ? null
            : max(0, $row['total_limit_minor'] - $row['withheld_total_minor']);
        $row['enters_payroll_run'] = DeductionAgreementStatus::from(
            (string) $row['status'],
        )->entersPayrollRun();

        return $row;
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === 1062 || $driverCode === '1062';
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
