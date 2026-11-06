<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollStatutoryResultRepository
{
    private const SAVEPOINT = 'payroll_statutory_result_repository';
    private const CALCULATION_KINDS = [
        'social_insurance',
        'health_insurance',
        'income_tax',
        'net_pay',
    ];
    private const RESULT_STATUSES = ['calculated', 'manual_review', 'error'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $resultSnapshot
     * @param list<array<string,mixed>> $people
     */
    public function store(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
        string $schemaVersion,
        string $resultStatus,
        string $rulesetId,
        string $rulesetHash,
        array $inputSnapshot,
        array $resultSnapshot,
        array $people,
        ?int $createdBy,
    ): int {
        $this->validateHeader(
            $supplierId,
            $revisionId,
            $calculationKind,
            $schemaVersion,
            $resultStatus,
            $rulesetId,
            $rulesetHash,
            $createdBy,
        );
        $normalizedPeople = $this->normalizePeople($people);
        $this->validateStatusHierarchy($resultStatus, $normalizedPeople);
        $inputJson = CanonicalJson::encode($inputSnapshot);
        $resultJson = CanonicalJson::encode($resultSnapshot);
        $resultSetHash = $this->resultSetHash(
            $calculationKind,
            $schemaVersion,
            $resultStatus,
            $rulesetId,
            $rulesetHash,
            $inputSnapshot,
            $resultSnapshot,
            $normalizedPeople,
        );

        return $this->transactional(function () use (
            $supplierId,
            $revisionId,
            $calculationKind,
            $schemaVersion,
            $resultStatus,
            $rulesetId,
            $rulesetHash,
            $inputJson,
            $resultJson,
            $resultSetHash,
            $normalizedPeople,
            $createdBy,
        ): int {
            $existing = $this->findHeaderForUpdate(
                $supplierId,
                $revisionId,
                $calculationKind,
            );
            if ($existing !== null) {
                return $this->replayedId($existing, $resultSetHash);
            }

            try {
                $insert = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_statutory_results
                        (supplier_id, revision_id, calculation_kind,
                         schema_version, result_status, ruleset_id, ruleset_hash,
                         input_snapshot_json, input_snapshot_hash,
                         result_snapshot_json, result_snapshot_hash,
                         result_set_hash, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $supplierId,
                    $revisionId,
                    $calculationKind,
                    $schemaVersion,
                    $resultStatus,
                    $rulesetId,
                    $rulesetHash,
                    $inputJson,
                    hash('sha256', $inputJson),
                    $resultJson,
                    hash('sha256', $resultJson),
                    $resultSetHash,
                    $createdBy,
                ]);
                $resultId = (int) $this->db->pdo()->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                $concurrent = $this->findHeaderForUpdate(
                    $supplierId,
                    $revisionId,
                    $calculationKind,
                );
                if ($concurrent === null) {
                    throw $e;
                }
                return $this->replayedId($concurrent, $resultSetHash, $e);
            }

            $this->insertPeople(
                $supplierId,
                $revisionId,
                $calculationKind,
                $resultId,
                $normalizedPeople,
            );

            return $resultId;
        });
    }

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
    ): ?array {
        $this->validateCalculationKind($calculationKind);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_statutory_results
              WHERE supplier_id = ?
                AND revision_id = ?
                AND calculation_kind = ?'
        );
        $stmt->execute([$supplierId, $revisionId, $calculationKind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $result = $this->castResultRow(
            PayrollTimeValue::row($row, 'statutory_result'),
        );

        $personStmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_statutory_person_results
              WHERE supplier_id = ? AND statutory_result_id = ?
              ORDER BY employee_id, id'
        );
        $personStmt->execute([$supplierId, $result['id']]);
        $people = PayrollTimeValue::rows(
            $personStmt->fetchAll(PDO::FETCH_ASSOC),
            'statutory_person_results',
        );

        $relationshipStmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_statutory_relationship_results
              WHERE supplier_id = ? AND statutory_result_id = ?
              ORDER BY person_result_id, employment_id, id'
        );
        $relationshipStmt->execute([$supplierId, $result['id']]);
        $relationshipsByPerson = [];
        foreach (PayrollTimeValue::rows(
            $relationshipStmt->fetchAll(PDO::FETCH_ASSOC),
            'statutory_relationship_results',
        ) as $relationship) {
            $cast = $this->castRelationshipRow($relationship);
            $relationshipsByPerson[$cast['person_result_id']][] = $cast;
        }

        $result['people'] = [];
        foreach ($people as $person) {
            $cast = $this->castPersonRow($person);
            $cast['relationships'] = $relationshipsByPerson[$cast['id']] ?? [];
            $result['people'][] = $cast;
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $people
     */
    public function resultSetHash(
        string $calculationKind,
        string $schemaVersion,
        string $resultStatus,
        string $rulesetId,
        string $rulesetHash,
        array $inputSnapshot,
        array $resultSnapshot,
        array $people,
    ): string {
        $normalizedPeople = $this->normalizePeople($people);
        $this->validateStatusHierarchy($resultStatus, $normalizedPeople);
        return hash('sha256', CanonicalJson::encode([
            'calculation_kind' => $calculationKind,
            'input_snapshot' => $inputSnapshot,
            'people' => $normalizedPeople,
            'result_snapshot' => $resultSnapshot,
            'result_status' => $resultStatus,
            'ruleset_hash' => $rulesetHash,
            'ruleset_id' => $rulesetId,
            'schema_version' => $schemaVersion,
        ]));
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return list<array<string,mixed>>
     */
    private function normalizePeople(array $people): array
    {
        $normalized = [];
        $employeeIds = [];
        foreach ($people as $index => $person) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                "people.{$index}.employee_id",
            );
            if (isset($employeeIds[$employeeId])) {
                throw new \InvalidArgumentException('Výsledek obsahuje osobu vícekrát.');
            }
            $employeeIds[$employeeId] = true;
            $status = $this->status(
                $person['result_status'] ?? null,
                "people.{$index}.result_status",
            );
            $input = $this->snapshot(
                $person['input_snapshot'] ?? null,
                "people.{$index}.input_snapshot",
            );
            $result = $this->snapshot(
                $person['result_snapshot'] ?? null,
                "people.{$index}.result_snapshot",
            );
            $relationships = $person['relationships'] ?? [];
            if (!is_array($relationships) || !array_is_list($relationships)) {
                throw new \InvalidArgumentException(
                    "people.{$index}.relationships musí být seznam.",
                );
            }
            $normalizedRelationships = [];
            $employmentIds = [];
            foreach ($relationships as $relationshipIndex => $relationship) {
                if (!is_array($relationship)) {
                    throw new \InvalidArgumentException(
                        "Výsledek vztahu {$index}.{$relationshipIndex} není objekt.",
                    );
                }
                $employmentId = $this->positiveInt(
                    $relationship['employment_id'] ?? null,
                    "people.{$index}.relationships.{$relationshipIndex}.employment_id",
                );
                if (isset($employmentIds[$employmentId])) {
                    throw new \InvalidArgumentException(
                        'Výsledek osoby obsahuje pracovní vztah vícekrát.',
                    );
                }
                $employmentIds[$employmentId] = true;
                $normalizedRelationships[] = [
                    'employment_id' => $employmentId,
                    'input_snapshot' => $this->snapshot(
                        $relationship['input_snapshot'] ?? null,
                        "people.{$index}.relationships.{$relationshipIndex}.input_snapshot",
                    ),
                    'result_snapshot' => $this->snapshot(
                        $relationship['result_snapshot'] ?? null,
                        "people.{$index}.relationships.{$relationshipIndex}.result_snapshot",
                    ),
                    'result_status' => $this->status(
                        $relationship['result_status'] ?? null,
                        "people.{$index}.relationships.{$relationshipIndex}.result_status",
                    ),
                ];
            }
            usort(
                $normalizedRelationships,
                static fn (array $left, array $right): int =>
                    $left['employment_id'] <=> $right['employment_id'],
            );
            $normalized[] = [
                'employee_id' => $employeeId,
                'input_snapshot' => $input,
                'relationships' => $normalizedRelationships,
                'result_snapshot' => $result,
                'result_status' => $status,
            ];
        }
        usort(
            $normalized,
            static fn (array $left, array $right): int =>
                $left['employee_id'] <=> $right['employee_id'],
        );

        return $normalized;
    }

    /** @param list<array<string,mixed>> $people */
    private function validateStatusHierarchy(string $rootStatus, array $people): void
    {
        $rootRank = $this->statusRank($rootStatus);
        foreach ($people as $person) {
            $personStatus = PayrollTimeValue::string(
                $person['result_status'] ?? null,
                'person.result_status',
            );
            $personRank = $this->statusRank($personStatus);
            if ($personRank > $rootRank) {
                throw new \InvalidArgumentException(
                    'Stav souhrnu nesmí skrýt závažnější stav osoby.',
                );
            }
            foreach ($person['relationships'] as $relationship) {
                $relationshipStatus = PayrollTimeValue::string(
                    $relationship['result_status'] ?? null,
                    'relationship.result_status',
                );
                if ($this->statusRank($relationshipStatus) > $personRank) {
                    throw new \InvalidArgumentException(
                        'Stav osoby nesmí skrýt závažnější stav pracovního vztahu.',
                    );
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $people
     */
    private function insertPeople(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
        int $resultId,
        array $people,
    ): void {
        $personInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_statutory_person_results
                (supplier_id, statutory_result_id, revision_id,
                 calculation_kind, employee_id, result_status,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $relationshipInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_statutory_relationship_results
                (supplier_id, statutory_result_id, person_result_id,
                 revision_id, calculation_kind, employee_id, employment_id,
                 result_status, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($people as $person) {
            $personInputJson = CanonicalJson::encode($person['input_snapshot']);
            $personResultJson = CanonicalJson::encode($person['result_snapshot']);
            $personInsert->execute([
                $supplierId,
                $resultId,
                $revisionId,
                $calculationKind,
                $person['employee_id'],
                $person['result_status'],
                $personInputJson,
                hash('sha256', $personInputJson),
                $personResultJson,
                hash('sha256', $personResultJson),
            ]);
            $personResultId = (int) $this->db->pdo()->lastInsertId();
            foreach ($person['relationships'] as $relationship) {
                $relationshipInputJson = CanonicalJson::encode(
                    $relationship['input_snapshot'],
                );
                $relationshipResultJson = CanonicalJson::encode(
                    $relationship['result_snapshot'],
                );
                $relationshipInsert->execute([
                    $supplierId,
                    $resultId,
                    $personResultId,
                    $revisionId,
                    $calculationKind,
                    $person['employee_id'],
                    $relationship['employment_id'],
                    $relationship['result_status'],
                    $relationshipInputJson,
                    hash('sha256', $relationshipInputJson),
                    $relationshipResultJson,
                    hash('sha256', $relationshipResultJson),
                ]);
            }
        }
    }

    /**
     * @return array{id:mixed,result_set_hash:mixed}|null
     */
    private function findHeaderForUpdate(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, result_set_hash
               FROM payroll_statutory_results
              WHERE supplier_id = ?
                AND revision_id = ?
                AND calculation_kind = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $revisionId, $calculationKind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{id:mixed,result_set_hash:mixed} $existing
     */
    private function replayedId(
        array $existing,
        string $resultSetHash,
        ?PDOException $previous = null,
    ): int {
        $existingHash = PayrollTimeValue::string(
            $existing['result_set_hash'] ?? null,
            'result_set_hash',
        );
        if (!hash_equals($existingHash, $resultSetHash)) {
            throw new \DomainException(
                'Revize už obsahuje jiný zákonný výsledek stejného druhu.',
                previous: $previous,
            );
        }

        return PayrollTimeValue::int($existing['id'] ?? null, 'result.id');
    }

    private function validateHeader(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
        string $schemaVersion,
        string $resultStatus,
        string $rulesetId,
        string $rulesetHash,
        ?int $createdBy,
    ): void {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException('Firma a revize musí být kladná čísla.');
        }
        $this->validateCalculationKind($calculationKind);
        $this->status($resultStatus, 'result_status');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $schemaVersion) !== 1) {
            throw new \InvalidArgumentException('Neplatná verze schématu výsledku.');
        }
        if ($rulesetId === '' || mb_strlen($rulesetId) > 96) {
            throw new \InvalidArgumentException('Neplatný identifikátor sady pravidel.');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $rulesetHash) !== 1) {
            throw new \InvalidArgumentException('Neplatný hash sady pravidel.');
        }
        if ($createdBy !== null && $createdBy <= 0) {
            throw new \InvalidArgumentException('Autor výsledku musí být kladné číslo.');
        }
    }

    private function validateCalculationKind(string $calculationKind): void
    {
        if (!in_array($calculationKind, self::CALCULATION_KINDS, true)) {
            throw new \InvalidArgumentException('Nepodporovaný druh zákonného výsledku.');
        }
    }

    private function status(mixed $status, string $field): string
    {
        if (!is_string($status) || !in_array($status, self::RESULT_STATUSES, true)) {
            throw new \InvalidArgumentException("{$field} nemá podporovaný stav.");
        }

        return $status;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'calculated' => 0,
            'manual_review' => 1,
            'error' => 2,
            default => throw new \InvalidArgumentException(
                'Nepodporovaný stav zákonného výsledku.',
            ),
        };
    }

    /** @return array<string,mixed> */
    private function snapshot(mixed $snapshot, string $field): array
    {
        if (!is_array($snapshot)) {
            throw new \InvalidArgumentException("{$field} musí být objekt.");
        }

        return $snapshot;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castResultRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'revision_id'] as $field) {
            $row[$field] = PayrollTimeValue::int($row[$field] ?? null, $field);
        }
        $row['created_by'] = $row['created_by'] === null
            ? null
            : PayrollTimeValue::int($row['created_by'], 'created_by');
        $this->decodeSnapshots($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castPersonRow(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'statutory_result_id',
            'revision_id',
            'employee_id',
        ] as $field) {
            $row[$field] = PayrollTimeValue::int($row[$field] ?? null, $field);
        }
        $this->decodeSnapshots($row);

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castRelationshipRow(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'statutory_result_id',
            'person_result_id',
            'revision_id',
            'employee_id',
            'employment_id',
        ] as $field) {
            $row[$field] = PayrollTimeValue::int($row[$field] ?? null, $field);
        }
        $this->decodeSnapshots($row);

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function decodeSnapshots(array &$row): void
    {
        foreach (['input_snapshot_json', 'result_snapshot_json'] as $field) {
            $json = PayrollTimeValue::string($row[$field] ?? null, $field);
            $row[str_replace('_json', '', $field)] = json_decode(
                $json,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            unset($row[$field]);
        }
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
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === 1062 || $driverCode === '1062';
    }
}
