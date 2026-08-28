<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\ActivityLogger;
use PDO;

final class PayrollForeignPermitRepository
{
    public const STATUSES = ['future', 'valid', 'expiring', 'expired', 'superseded'];

    private const KINDS = ['residence', 'work'];
    private const WARNING_DAYS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentRepository $documents,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return array{employee_id:int,as_of:string,warning_days:int,history:list<array<string,mixed>>,alerts:list<array<string,mixed>>}|null */
    public function view(int $supplierId, int $employeeId, string $asOf): ?array
    {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, permit_kind, permit_label, issuing_country_code, effective_from,
                    valid_until, document_id, supersedes_permit_id,
                    recorded_at
               FROM payroll_person_foreign_permits
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY permit_kind, effective_from DESC, id DESC',
        );
        $stmt->execute([$supplierId, $employeeId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $successors = [];
        foreach ($history as $row) {
            $predecessor = $row['supersedes_permit_id'] ?? null;
            if (is_int($predecessor) || (is_string($predecessor) && ctype_digit($predecessor))) {
                $successors[(int) $predecessor] = $row;
            }
        }

        $today = new DateTimeImmutable($asOf);
        $alerts = [];
        foreach ($history as &$row) {
            $row['id'] = (int) $row['id'];
            $row['document_id'] = (int) $row['document_id'];
            $row['supersedes_permit_id'] = $row['supersedes_permit_id'] === null
                ? null
                : (int) $row['supersedes_permit_id'];
            $row['status'] = $this->status($row, $successors[(int) $row['id']] ?? null, $today);
            if (in_array($row['status'], ['expired', 'expiring'], true)) {
                $alerts[] = [
                    'permit_id' => $row['id'],
                    'permit_kind' => $row['permit_kind'],
                    'permit_label' => $row['permit_label'],
                    'valid_until' => $row['valid_until'],
                    'status' => $row['status'],
                    'days_remaining' => $this->daysRemaining((string) $row['valid_until'], $today),
                ];
            }
        }
        unset($row);

        usort($alerts, static fn (array $a, array $b): int => [$a['valid_until'], $a['permit_id']] <=> [$b['valid_until'], $b['permit_id']]);

        return [
            'employee_id' => $employeeId,
            'as_of' => $asOf,
            'warning_days' => self::WARNING_DAYS,
            'history' => $history,
            'alerts' => $alerts,
        ];
    }

    /** @param array<string,mixed> $input
     * @return array{employee_id:int,as_of:string,warning_days:int,history:list<array<string,mixed>>,alerts:list<array<string,mixed>>}
     */
    public function create(int $supplierId, int $employeeId, array $input, int $actorUserId, string $asOf): array
    {
        $permit = $this->normalize($input);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if (!$this->lockEmployee($supplierId, $employeeId)) {
                throw new PayrollForeignPermitException('person_not_found', 'Zaměstnanec nebyl v této firmě nalezen.', 404);
            }
            $document = $this->documents->findActiveReferenceForUpdate(
                $permit['document_id'],
                $supplierId,
                DocumentViewerContext::internalCompanyForeignPermit(),
            );
            if ($document === null) {
                throw new PayrollForeignPermitException(
                    'company_evidence_document_required',
                    'Oprávnění musí dokládat aktivní firemní dokument této firmy.',
                );
            }
            $sha256 = strtolower($document['sha256']);
            if (preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
                throw new \LogicException('Firemní dokument nemá platný SHA-256 otisk.');
            }
            $this->assertPredecessorAndOverlap($supplierId, $employeeId, $permit);

            $insert = $pdo->prepare(
                'INSERT INTO payroll_person_foreign_permits
                    (supplier_id, employee_id, permit_kind, permit_label,
                     issuing_country_code, effective_from, valid_until,
                     document_supplier_id, document_id, document_sha256, supersedes_permit_id, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $supplierId,
                $employeeId,
                $permit['permit_kind'],
                $permit['permit_label'],
                $permit['issuing_country_code'],
                $permit['effective_from'],
                $permit['valid_until'],
                $supplierId,
                $document['id'],
                $sha256,
                $permit['supersedes_permit_id'],
                $actorUserId,
            ]);
            $permitId = (int) $pdo->lastInsertId();
            $this->activityLogger->log(
                'payroll.person_foreign_permit.recorded',
                $actorUserId,
                'payroll_person_foreign_permit',
                $permitId,
                [
                    'employee_id' => $employeeId,
                    'permit_kind' => $permit['permit_kind'],
                    'effective_from' => $permit['effective_from'],
                    'valid_until' => $permit['valid_until'],
                    'document_id' => $document['id'],
                    'document_sha256' => $sha256,
                    'supersedes_permit_id' => $permit['supersedes_permit_id'],
                ],
                null,
                null,
                $supplierId,
            );
            $view = $this->view($supplierId, $employeeId, $asOf);
            if ($view === null) {
                throw new \LogicException('Uložené oprávnění nelze načíst.');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $view;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '45000') {
                throw new PayrollForeignPermitException('permit_storage_rejected', 'Oprávnění nebylo možné bezpečně uložit.', 422);
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $input
     * @return array{permit_kind:string,permit_label:string,issuing_country_code:string,effective_from:string,valid_until:string,document_id:int,supersedes_permit_id:?int}
     */
    private function normalize(array $input): array
    {
        $kind = $input['permit_kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new PayrollForeignPermitException('invalid_permit_kind', 'Druh oprávnění musí být pobytové nebo pracovní.');
        }
        $label = is_string($input['permit_label'] ?? null) ? trim($input['permit_label']) : '';
        if (mb_strlen($label) < 3 || mb_strlen($label) > 128) {
            throw new PayrollForeignPermitException('invalid_permit_label', 'Název oprávnění musí mít 3 až 128 znaků.');
        }
        $country = is_string($input['issuing_country_code'] ?? null) ? strtoupper(trim($input['issuing_country_code'])) : '';
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new PayrollForeignPermitException('invalid_issuing_country', 'Stát vydání musí mít dvoupísmenný kód země.');
        }
        $effectiveFrom = $this->date($input['effective_from'] ?? null, 'Datum účinnosti');
        $validUntil = $this->date($input['valid_until'] ?? null, 'Datum platnosti');
        if ($validUntil < $effectiveFrom) {
            throw new PayrollForeignPermitException('invalid_permit_dates', 'Platnost oprávnění nesmí skončit před jeho účinností.');
        }
        $documentId = $this->positiveInt($input['document_id'] ?? null, 'ID DMS dokumentu');
        $supersedes = $input['supersedes_permit_id'] ?? null;
        $supersedesId = $supersedes === null || $supersedes === '' ? null : $this->positiveInt($supersedes, 'Nahrazované oprávnění');

        return [
            'permit_kind' => $kind,
            'permit_label' => $label,
            'issuing_country_code' => $country,
            'effective_from' => $effectiveFrom,
            'valid_until' => $validUntil,
            'document_id' => $documentId,
            'supersedes_permit_id' => $supersedesId,
        ];
    }

    /** @param array{permit_kind:string,effective_from:string,valid_until:string,supersedes_permit_id:?int} $permit */
    private function assertPredecessorAndOverlap(int $supplierId, int $employeeId, array $permit): void
    {
        if ($permit['supersedes_permit_id'] !== null) {
            $predecessor = $this->db->pdo()->prepare(
                'SELECT id, effective_from FROM payroll_person_foreign_permits
                  WHERE id = ? AND supplier_id = ? AND employee_id = ? AND permit_kind = ? FOR UPDATE',
            );
            $predecessor->execute([$permit['supersedes_permit_id'], $supplierId, $employeeId, $permit['permit_kind']]);
            $predecessorRow = $predecessor->fetch(PDO::FETCH_ASSOC);
            if ($predecessorRow === false) {
                throw new PayrollForeignPermitException('invalid_permit_predecessor', 'Nahrazované oprávnění není evidováno u této osoby a druhu.');
            }
            if ($permit['effective_from'] <= (string) $predecessorRow['effective_from']) {
                throw new PayrollForeignPermitException(
                    'permit_successor_must_be_later',
                    'Obnovení musí začít později než nahrazované oprávnění.',
                );
            }
            $successor = $this->db->pdo()->prepare(
                'SELECT id FROM payroll_person_foreign_permits
                  WHERE supplier_id = ? AND supersedes_permit_id = ? FOR UPDATE',
            );
            $successor->execute([$supplierId, $permit['supersedes_permit_id']]);
            if ($successor->fetchColumn() !== false) {
                throw new PayrollForeignPermitException(
                    'permit_predecessor_already_superseded',
                    'Na nahrazované oprávnění už navazuje jiné obnovení.',
                );
            }
        }
        $overlap = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_person_foreign_permits
              WHERE supplier_id = ? AND employee_id = ? AND permit_kind = ?
                AND effective_from <= ? AND valid_until >= ?
                AND (? IS NULL OR id <> ?)
              FOR UPDATE',
        );
        $overlap->execute([
            $supplierId,
            $employeeId,
            $permit['permit_kind'],
            $permit['valid_until'],
            $permit['effective_from'],
            $permit['supersedes_permit_id'],
            $permit['supersedes_permit_id'],
        ]);
        if ($overlap->fetchColumn() !== false) {
            throw new PayrollForeignPermitException(
                'permit_overlap_requires_predecessor',
                'Překrývající se obnovení musí výslovně navázat na jedno předchozí oprávnění.',
            );
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $successor */
    private function status(array $row, ?array $successor, DateTimeImmutable $asOf): string
    {
        $from = new DateTimeImmutable((string) $row['effective_from']);
        if ($from > $asOf) {
            return 'future';
        }
        if ($successor !== null && new DateTimeImmutable((string) $successor['effective_from']) <= $asOf) {
            return 'superseded';
        }
        $until = new DateTimeImmutable((string) $row['valid_until']);
        if ($until < $asOf) {
            return 'expired';
        }
        return $this->daysRemaining((string) $row['valid_until'], $asOf) <= self::WARNING_DAYS ? 'expiring' : 'valid';
    }

    private function daysRemaining(string $validUntil, DateTimeImmutable $asOf): int
    {
        return (int) $asOf->diff(new DateTimeImmutable($validUntil))->format('%r%a');
    }

    private function employeeExists(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    private function lockEmployee(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    private function date(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new PayrollForeignPermitException('invalid_permit_date', "{$label} musí být datum YYYY-MM-DD.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new PayrollForeignPermitException('invalid_permit_date', "{$label} musí být datum YYYY-MM-DD.");
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new PayrollForeignPermitException('invalid_document_reference', "{$label} musí být kladné číslo.");
        }
        return (int) $result;
    }
}
