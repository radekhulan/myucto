<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollRegzelRepository
{
    /**
     * Tvrdý strop stránky snímků REGZEL. Snímek vzniká při každé přípravě
     * podání, takže jich za pár let přibude víc, než se dá projít.
     */
    public const LIST_MAX_LIMIT = 100;

    public const LIST_DEFAULT_LIMIT = 50;

    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_regzel_' . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:?string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   evidence_confirmed_by:int,
     *   evidence_confirmed_at:string,row_version:int,updated_at:string
     * }|null
     */
    public function profile(int $supplierId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier_id, social_enterprise, employment_agency,
                    protected_labor_market, tax_office_code,
                    tax_office_workplace_code, payer_reference_number,
                    evidence_confirmed_by,
                    evidence_confirmed_at, row_version, updated_at
               FROM payroll_regzel_employer_profiles
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));
        if ($row === null) {
            return null;
        }

        return [
            'supplier_id' => self::requiredInt($row, 'supplier_id'),
            'social_enterprise' =>
                self::requiredBool($row, 'social_enterprise'),
            'employment_agency' =>
                self::requiredBool($row, 'employment_agency'),
            'protected_labor_market' =>
                self::requiredBool($row, 'protected_labor_market'),
            'tax_office_code' =>
                self::nullableString($row, 'tax_office_code'),
            'tax_office_workplace_code' =>
                self::nullableString($row, 'tax_office_workplace_code'),
            'payer_reference_number' =>
                self::nullableString($row, 'payer_reference_number'),
            'evidence_confirmed_by' =>
                self::requiredInt($row, 'evidence_confirmed_by'),
            'evidence_confirmed_at' =>
                self::requiredString($row, 'evidence_confirmed_at'),
            'row_version' => self::requiredInt($row, 'row_version'),
            'updated_at' => self::requiredString($row, 'updated_at'),
        ];
    }

    /**
     * @return array{
     *   supplier_id:int,social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,tax_office_code:string,
     *   tax_office_workplace_code:?string,payer_reference_number:?string,
     *   evidence_confirmed_by:int,
     *   evidence_confirmed_at:string,row_version:int,updated_at:string
     * }
     */
    public function saveProfile(
        int $supplierId,
        bool $socialEnterprise,
        bool $employmentAgency,
        bool $protectedLaborMarket,
        string $taxOfficeCode,
        ?string $taxOfficeWorkplaceCode,
        ?string $payerReferenceNumber,
        int $confirmedBy,
        int $expectedVersion,
    ): array {
        $current = $this->profile($supplierId);
        if ($current === null) {
            if ($expectedVersion !== 0) {
                throw new PayrollRegzelProfileConflictException(0);
            }
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_regzel_employer_profiles
                    (supplier_id, social_enterprise, employment_agency,
                     protected_labor_market, tax_office_code,
                     tax_office_workplace_code, payer_reference_number,
                     evidence_confirmed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $socialEnterprise ? 1 : 0,
                $employmentAgency ? 1 : 0,
                $protectedLaborMarket ? 1 : 0,
                $taxOfficeCode,
                $taxOfficeWorkplaceCode,
                $payerReferenceNumber,
                $confirmedBy,
            ]);
        } else {
            $statement = $this->db->pdo()->prepare(
                'UPDATE payroll_regzel_employer_profiles
                    SET social_enterprise = ?,
                        employment_agency = ?,
                        protected_labor_market = ?,
                        tax_office_code = ?,
                        tax_office_workplace_code = ?,
                        payer_reference_number = ?,
                        evidence_confirmed_by = ?,
                        evidence_confirmed_at = CURRENT_TIMESTAMP,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND row_version = ?',
            );
            $statement->execute([
                $socialEnterprise ? 1 : 0,
                $employmentAgency ? 1 : 0,
                $protectedLaborMarket ? 1 : 0,
                $taxOfficeCode,
                $taxOfficeWorkplaceCode,
                $payerReferenceNumber,
                $confirmedBy,
                $supplierId,
                $expectedVersion,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new PayrollRegzelProfileConflictException(
                    $current['row_version'],
                );
            }
        }

        return $this->profile($supplierId)
            ?? throw new \RuntimeException('REGZEL profil se nepodařilo načíst.');
    }

    public function taxOfficeWorkplaceSuggestion(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT workplace_code FROM supplier WHERE id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @return array{
     *   supplier_id:int,supplier_updated_at:string,
     *   regzel_tax_office_code:?string,regzel_tax_office_workplace_code:?string,
     *   regzel_payer_reference_number:?string,data_box_id:?string,
     *   social_security_office_code:?string,
     *   employer_settings_row_version:int,office_id:int,
     *   social_security_variable_symbol:?string,office_is_active:bool,
     *   office_row_version:int,
     *   social_enterprise:bool,employment_agency:bool,
     *   protected_labor_market:bool,profile_row_version:int
     * }|null
     */
    public function source(int $supplierId, int $officeId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT supplier.id AS supplier_id,
                    supplier.updated_at AS supplier_updated_at,
                    supplier.data_box_id,
                    settings.social_security_office_code,
                    profile.tax_office_code AS regzel_tax_office_code,
                    profile.tax_office_workplace_code
                        AS regzel_tax_office_workplace_code,
                    profile.payer_reference_number
                        AS regzel_payer_reference_number,
                    settings.row_version AS employer_settings_row_version,
                    office.id AS office_id,
                    office.social_security_variable_symbol,
                    office.is_active AS office_is_active,
                    office.row_version AS office_row_version,
                    profile.social_enterprise,
                    profile.employment_agency,
                    profile.protected_labor_market,
                    profile.row_version AS profile_row_version
               FROM supplier
               JOIN payroll_employer_settings settings
                 ON settings.supplier_id = supplier.id
               JOIN payroll_offices office
                 ON office.supplier_id = supplier.id
                AND office.id = ?
               JOIN payroll_regzel_employer_profiles profile
                 ON profile.supplier_id = supplier.id
              WHERE supplier.id = ?',
        );
        $statement->execute([$officeId, $supplierId]);
        $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));
        if ($row === null) {
            return null;
        }

        return [
            'supplier_id' => self::requiredInt($row, 'supplier_id'),
            'supplier_updated_at' =>
                self::requiredString($row, 'supplier_updated_at'),
            'regzel_tax_office_code' =>
                self::nullableString($row, 'regzel_tax_office_code'),
            'regzel_tax_office_workplace_code' =>
                self::nullableString($row, 'regzel_tax_office_workplace_code'),
            'data_box_id' => self::nullableString($row, 'data_box_id'),
            'regzel_payer_reference_number' =>
                self::nullableString($row, 'regzel_payer_reference_number'),
            'social_security_office_code' =>
                self::nullableString($row, 'social_security_office_code'),
            'employer_settings_row_version' =>
                self::requiredInt($row, 'employer_settings_row_version'),
            'office_id' => self::requiredInt($row, 'office_id'),
            'social_security_variable_symbol' =>
                self::nullableString($row, 'social_security_variable_symbol'),
            'office_is_active' =>
                self::requiredBool($row, 'office_is_active'),
            'office_row_version' =>
                self::requiredInt($row, 'office_row_version'),
            'social_enterprise' =>
                self::requiredBool($row, 'social_enterprise'),
            'employment_agency' =>
                self::requiredBool($row, 'employment_agency'),
            'protected_labor_market' =>
                self::requiredBool($row, 'protected_labor_market'),
            'profile_row_version' =>
                self::requiredInt($row, 'profile_row_version'),
        ];
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int,created_at:string
     * }|null
     */
    public function findByIdempotencyForUpdate(
        int $supplierId,
        string $environment,
        string $idempotencyKeyHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_regzel_payload_snapshots
              WHERE supplier_id = ?
                AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $idempotencyKeyHash,
        ]);

        $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : self::snapshotRow($row);
    }

    /**
     * @param array{
     *   supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int
     * } $record
     */
    public function insertSnapshot(array $record): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_regzel_payload_snapshots
                (supplier_id, environment, office_id, document_type,
                 interaction_code, mapping_version, xsd_version,
                 source_manifest_json, snapshot_ciphertext,
                 source_snapshot_hash, xml_sha256, xml_byte_size,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $record['supplier_id'],
            $record['environment'],
            $record['office_id'],
            $record['document_type'],
            $record['interaction_code'],
            $record['mapping_version'],
            $record['xsd_version'],
            $record['source_manifest_json'],
            $record['snapshot_ciphertext'],
            $record['source_snapshot_hash'],
            $record['xml_sha256'],
            $record['xml_byte_size'],
            $record['request_fingerprint'],
            $record['idempotency_key_hash'],
            $record['created_by'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int,created_at:string
     * }|null
     */
    public function findSnapshot(
        int $supplierId,
        int $snapshotId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_regzel_payload_snapshots
              WHERE supplier_id = ? AND environment = ? AND id = ?',
        );
        $statement->execute([$supplierId, $environment, $snapshotId]);

        $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));

        return $row === null ? null : self::snapshotRow($row);
    }

    /**
     * @return list<array{
     *   id:int,supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int,created_at:string
     * }>
     */
    public function listSnapshots(
        int $supplierId,
        string $environment,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici. Limit i offset se
        // vkládají do SQL jako celá čísla — MariaDB v LIMIT vázané parametry
        // nepřijímá.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_regzel_payload_snapshots
              WHERE supplier_id = ? AND environment = ?
              ORDER BY id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        $statement->execute([$supplierId, $environment]);
        $rows = [];
        while (true) {
            $row = self::associativeRow($statement->fetch(PDO::FETCH_ASSOC));
            if ($row === null) {
                break;
            }
            $rows[] = self::snapshotRow($row);
        }
        return $rows;
    }

    /**
     * Kolik snímků firma v daném prostředí vůbec má.
     *
     * Bez tohohle čísla seznam mlčky usekl na stovce a uživatel neměl jak
     * poznat, že starší snímky existují — což je horší než dlouhý seznam.
     */
    public function countSnapshots(int $supplierId, string $environment): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_regzel_payload_snapshots
              WHERE supplier_id = ? AND environment = ?',
        );
        $statement->execute([$supplierId, $environment]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function associativeRow(mixed $row): ?array
    {
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new \RuntimeException('REGZEL databázový řádek nemá očekávaný tvar.');
        }
        $normalized = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    'REGZEL databázový řádek nemá pojmenované sloupce.',
                );
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function field(array $row, string $key): mixed
    {
        if (!array_key_exists($key, $row)) {
            throw new \RuntimeException(
                'REGZEL databázový řádek neobsahuje sloupec ' . $key . '.',
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private static function requiredInt(array $row, string $key): int
    {
        $value = self::field($row, $key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \RuntimeException(
            'REGZEL databázový sloupec ' . $key . ' není celé číslo.',
        );
    }

    /** @param array<string,mixed> $row */
    private static function requiredBool(array $row, string $key): bool
    {
        $value = self::field($row, $key);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new \RuntimeException(
            'REGZEL databázový sloupec ' . $key . ' není pravdivostní hodnota.',
        );
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $key): string
    {
        $value = self::field($row, $key);
        if (!is_string($value)) {
            throw new \RuntimeException(
                'REGZEL databázový sloupec ' . $key . ' není text.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = self::field($row, $key);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \RuntimeException(
                'REGZEL databázový sloupec ' . $key . ' není text.',
            );
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,supplier_id:int,environment:string,office_id:int,
     *   document_type:string,interaction_code:string,mapping_version:string,
     *   xsd_version:string,source_manifest_json:string,
     *   snapshot_ciphertext:string,source_snapshot_hash:string,
     *   xml_sha256:string,xml_byte_size:int,request_fingerprint:string,
     *   idempotency_key_hash:string,created_by:?int,created_at:string
     * }
     */
    private static function snapshotRow(array $row): array
    {
        $createdBy = self::field($row, 'created_by');

        return [
            'id' => self::requiredInt($row, 'id'),
            'supplier_id' => self::requiredInt($row, 'supplier_id'),
            'environment' => self::requiredString($row, 'environment'),
            'office_id' => self::requiredInt($row, 'office_id'),
            'document_type' => self::requiredString($row, 'document_type'),
            'interaction_code' =>
                self::requiredString($row, 'interaction_code'),
            'mapping_version' => self::requiredString($row, 'mapping_version'),
            'xsd_version' => self::requiredString($row, 'xsd_version'),
            'source_manifest_json' =>
                self::requiredString($row, 'source_manifest_json'),
            'snapshot_ciphertext' =>
                self::requiredString($row, 'snapshot_ciphertext'),
            'source_snapshot_hash' =>
                self::requiredString($row, 'source_snapshot_hash'),
            'xml_sha256' => self::requiredString($row, 'xml_sha256'),
            'xml_byte_size' => self::requiredInt($row, 'xml_byte_size'),
            'request_fingerprint' =>
                self::requiredString($row, 'request_fingerprint'),
            'idempotency_key_hash' =>
                self::requiredString($row, 'idempotency_key_hash'),
            'created_by' => $createdBy === null
                ? null
                : self::requiredInt($row, 'created_by'),
            'created_at' => self::requiredString($row, 'created_at'),
        ];
    }
}
