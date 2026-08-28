<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class JmhzEmployerAnnualEvidenceRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function latest(int $supplierId, int $reportYear, bool $lock = false): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_jmhz_employer_annual_evidence
              WHERE supplier_id = ? AND report_year = ?
              ORDER BY revision_no DESC
              LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$supplierId, $reportYear]);
        $row = self::fetchedRow($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array{id:int,code:string,name:string}> */
    public function activeOffices(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, code, name
               FROM payroll_offices
              WHERE supplier_id = ? AND is_active = 1
              ORDER BY code, id',
        );
        $statement->execute([$supplierId]);

        $result = [];
        while (($row = self::fetchedRow($statement->fetch(PDO::FETCH_ASSOC))) !== null) {
            $result[] = [
                'id' => self::integer($row, 'id'),
                'code' => self::string($row, 'code'),
                'name' => self::string($row, 'name'),
            ];
        }

        return $result;
    }

    public function officeBelongsToSupplier(int $supplierId, int $officeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_offices
              WHERE supplier_id = ? AND id = ? AND is_active = 1',
        );
        $statement->execute([$supplierId, $officeId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function append(
        int $supplierId,
        int $reportYear,
        array $data,
        ?int $expectedRevisionId,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $supplier = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
            $supplier->execute([$supplierId]);
            if ($supplier->fetchColumn() === false) {
                throw new \DomainException('Firma pro roční údaje JMHZ neexistuje.');
            }
            $current = $this->latest($supplierId, $reportYear, true);
            $currentId = $current === null ? null : self::integer($current, 'id');
            if ($current !== null
                && hash_equals(
                    self::string($current, 'payload_sha256'),
                    self::string($data, 'payload_sha256'),
                )
            ) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $current;
            }
            if ($currentId !== $expectedRevisionId) {
                throw new JmhzEmployerAnnualEvidenceConflictException($currentId);
            }
            $statement = $pdo->prepare(
                'INSERT INTO payroll_jmhz_employer_annual_evidence
                    (supplier_id, report_year, revision_no, previous_revision_id,
                     schema_reference, spec_manifest_sha256,
                     collective_agreement_types_json,
                     ownership_form, average_headcount_hundredths,
                     average_disabled_headcount_hundredths,
                     disabled_share_hundredths, ozp_reporting_office_id,
                     evidence_reference, payload_sha256, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $reportYear,
                $current === null ? 1 : self::integer($current, 'revision_no') + 1,
                $currentId,
                $data['schema_reference'],
                $data['spec_manifest_sha256'],
                $data['collective_agreement_types_json'],
                $data['ownership_form'],
                $data['average_headcount_hundredths'],
                $data['average_disabled_headcount_hundredths'],
                $data['disabled_share_hundredths'],
                $data['ozp_reporting_office_id'],
                $data['evidence_reference'],
                $data['payload_sha256'],
                $actorUserId,
            ]);
            $created = $this->latest($supplierId, $reportYear, false);
            if ($created === null) {
                throw new \RuntimeException('Uloženou roční revizi JMHZ nelze načíst.');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $created;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $types = json_decode(self::string($row, 'collective_agreement_types_json'), true);
        if (!is_array($types) || !array_is_list($types)) {
            throw new \UnexpectedValueException('Roční číselník kolektivních smluv je poškozený.');
        }
        $collectiveTypes = [];
        foreach ($types as $type) {
            if (!is_string($type)) {
                throw new \UnexpectedValueException('Roční číselník kolektivních smluv je poškozený.');
            }
            $collectiveTypes[] = $type;
        }
        return [
            'id' => self::integer($row, 'id'),
            'supplier_id' => self::integer($row, 'supplier_id'),
            'report_year' => self::integer($row, 'report_year'),
            'revision_no' => self::integer($row, 'revision_no'),
            'previous_revision_id' => self::nullableInteger($row, 'previous_revision_id'),
            'schema_reference' => self::string($row, 'schema_reference'),
            'spec_manifest_sha256' => self::string($row, 'spec_manifest_sha256'),
            'collective_agreement_types' => $collectiveTypes,
            'ownership_form' => self::string($row, 'ownership_form'),
            'average_headcount_hundredths' => self::integer($row, 'average_headcount_hundredths'),
            'average_disabled_headcount_hundredths' =>
                self::integer($row, 'average_disabled_headcount_hundredths'),
            'disabled_share_hundredths' => self::integer($row, 'disabled_share_hundredths'),
            'ozp_reporting_office_id' => self::nullableInteger($row, 'ozp_reporting_office_id'),
            'evidence_reference' => self::nullableString($row, 'evidence_reference'),
            'payload_sha256' => self::string($row, 'payload_sha256'),
            'created_by' => self::nullableInteger($row, 'created_by'),
            'created_at' => self::string($row, 'created_at'),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function fetchedRow(mixed $value): ?array
    {
        if ($value === false) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Databáze vrátila neplatný sloupec.');
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("Sloupec {$field} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Sloupec {$field} není text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        return ($row[$field] ?? null) === null ? null : self::string($row, $field);
    }
}
