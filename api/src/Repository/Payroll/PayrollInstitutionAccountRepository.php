<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

final class PayrollInstitutionAccountRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollInstitutionAccountDeletionRepository $deletion,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, ?string $effectiveOn = null): array
    {
        $where = 'account.supplier_id = ?';
        $params = [$supplierId];
        if ($effectiveOn !== null) {
            $where .= ' AND account.valid_from <= ?'
                . ' AND (account.valid_to IS NULL OR account.valid_to >= ?)';
            $params[] = $effectiveOn;
            $params[] = $effectiveOn;
        }

        $stmt = $this->db->pdo()->prepare(
            $this->selectSql()
            . " WHERE {$where}"
            . ' ORDER BY institution.institution_type, institution.institution_code,'
            . ' account.currency_code, account.valid_from DESC, account.id DESC'
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->withBankAccount($supplierId, self::cast(self::databaseRow($row)));
        }
        return $this->deletion->decorate($supplierId, $result);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            $this->selectSql() . ' WHERE account.supplier_id = ? AND account.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : $this->deletion->decorateOne(
                $supplierId,
                $this->withBankAccount($supplierId, self::cast(self::databaseRow($row))),
            );
    }

    /** @return array{variable_symbol:?string,specific_symbol:?string,constant_symbol:?string}|null */
    public function findEffectivePaymentIdentifiers(
        int $supplierId,
        string $institutionType,
        string $institutionCode,
        string $currencyCode,
        string $effectiveOn,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.variable_symbol, account.specific_symbol, account.constant_symbol
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ?
                AND institution.institution_type = ?
                AND institution.institution_code = ?
                AND account.currency_code = ?
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
              ORDER BY account.id
              LIMIT 2'
        );
        $statement->execute([
            $supplierId,
            $institutionType,
            $institutionCode,
            $currencyCode,
            $effectiveOn,
            $effectiveOn,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new \RuntimeException('Pro instituci je účinných více platebních účtů.');
        }
        $row = self::databaseRow($rows[0]);

        return [
            'variable_symbol' => self::nullableString($row, 'variable_symbol'),
            'specific_symbol' => self::nullableString($row, 'specific_symbol'),
            'constant_symbol' => self::nullableString($row, 'constant_symbol'),
        ];
    }

    /**
     * @return list<array{
     *   id:int,
     *   institution_id:int,
     *   institution_type:string,
     *   institution_code:string,
     *   institution_name:string,
     *   bank_account_ciphertext:string,
     *   bank_account_hash:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string,
     *   verified_by:?int,
     *   row_version:int
     * }>
     */
    public function lockEffectivePaymentTargets(
        int $supplierId,
        string $institutionType,
        string $institutionCode,
        string $currencyCode,
        string $effectiveOn,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.id, account.institution_id,
                    institution.institution_type,
                    institution.institution_code,
                    account.institution_name,
                    account.bank_account_ciphertext,
                    LOWER(HEX(account.bank_account_hash)) AS bank_account_hash,
                    account.currency_code, account.variable_symbol,
                    account.specific_symbol, account.constant_symbol,
                    account.valid_from, account.valid_to,
                    account.source_kind, account.source_reference,
                    account.verified_on, account.verified_by,
                    account.row_version
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ?
                AND institution.institution_type = ?
                AND institution.institution_code = ?
                AND account.currency_code = ?
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
              ORDER BY account.id
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $institutionType,
            $institutionCode,
            $currencyCode,
            $effectiveOn,
            $effectiveOn,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $value) {
            $row = self::databaseRow($value);
            $result[] = [
                'id' => self::requiredInt($row, 'id'),
                'institution_id' => self::requiredInt(
                    $row,
                    'institution_id',
                ),
                'institution_type' => self::requiredString(
                    $row,
                    'institution_type',
                ),
                'institution_code' => self::requiredString(
                    $row,
                    'institution_code',
                ),
                'institution_name' => self::requiredString(
                    $row,
                    'institution_name',
                ),
                'bank_account_ciphertext' => self::requiredString(
                    $row,
                    'bank_account_ciphertext',
                ),
                'bank_account_hash' => self::requiredString(
                    $row,
                    'bank_account_hash',
                ),
                'currency_code' => self::requiredString(
                    $row,
                    'currency_code',
                ),
                'variable_symbol' => self::nullableString(
                    $row,
                    'variable_symbol',
                ),
                'specific_symbol' => self::nullableString(
                    $row,
                    'specific_symbol',
                ),
                'constant_symbol' => self::nullableString(
                    $row,
                    'constant_symbol',
                ),
                'valid_from' => self::requiredString($row, 'valid_from'),
                'valid_to' => self::nullableString($row, 'valid_to'),
                'source_kind' => self::requiredString($row, 'source_kind'),
                'source_reference' => self::requiredString(
                    $row,
                    'source_reference',
                ),
                'verified_on' => self::requiredString($row, 'verified_on'),
                'verified_by' => self::nullableInt($row, 'verified_by'),
                'row_version' => self::requiredInt($row, 'row_version'),
            ];
        }

        return $result;
    }

    /**
     * @param array{
     *   institution_type:string,
     *   institution_code:string,
     *   institution_name:string,
     *   bank_account:string,
     *   currency_code:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string
     * } $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $institutionId = $this->institutionId(
                $supplierId,
                $data['institution_type'],
                $data['institution_code'],
            );
            $this->assertNoOverlap(
                $supplierId,
                $institutionId,
                $data['currency_code'],
                $data['valid_from'],
                $data['valid_to'],
            );

            $insert = $pdo->prepare(
                'INSERT INTO payroll_institution_accounts
                    (supplier_id, institution_id, institution_name,
                     bank_account_ciphertext, bank_account_hash, bank_account_masked,
                     currency_code, variable_symbol, specific_symbol, constant_symbol,
                     valid_from, valid_to, source_kind, source_reference, verified_on,
                     verified_by, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $institutionId,
                $data['institution_name'],
                'pending:v1',
                random_bytes(32),
                '••••',
                $data['currency_code'],
                $data['variable_symbol'],
                $data['specific_symbol'],
                $data['constant_symbol'],
                $data['valid_from'],
                $data['valid_to'],
                $data['source_kind'],
                $data['source_reference'],
                $data['verified_on'],
                $userId,
                $userId,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException('Účet instituce se nepodařilo založit.');
            }

            $sealed = $this->sensitiveData->seal(
                $data['bank_account'],
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
                $id,
            );
            $secureUpdate = $pdo->prepare(
                'UPDATE payroll_institution_accounts
                    SET bank_account_ciphertext = ?,
                        bank_account_hash = ?,
                        bank_account_masked = ?
                  WHERE supplier_id = ? AND id = ?'
            );
            $secureUpdate->execute([
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
                $supplierId,
                $id,
            ]);
            if ($secureUpdate->rowCount() !== 1) {
                throw new \RuntimeException('Citlivé údaje účtu se nepodařilo bezpečně uložit.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Uložený účet instituce se nepodařilo načíst.');
    }

    /**
     * @param array{
     *   institution_name:string,
     *   variable_symbol:?string,
     *   specific_symbol:?string,
     *   constant_symbol:?string,
     *   valid_to:?string,
     *   source_kind:string,
     *   source_reference:string,
     *   verified_on:string
     * } $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $lock = $pdo->prepare(
                'SELECT institution_id, currency_code, valid_from, row_version
                   FROM payroll_institution_accounts
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE'
            );
            $lock->execute([$supplierId, $id]);
            $fetched = $lock->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $current = self::databaseRow($fetched);
            $currentVersion = self::requiredInt($current, 'row_version');
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollInstitutionAccountConflictException($currentVersion);
            }
            $currentValidFrom = self::requiredString($current, 'valid_from');
            if ($data['valid_to'] !== null && $data['valid_to'] < $currentValidFrom) {
                throw new \InvalidArgumentException(
                    'Konec platnosti nesmí předcházet začátku.'
                );
            }

            $this->assertNoOverlap(
                $supplierId,
                self::requiredInt($current, 'institution_id'),
                self::requiredString($current, 'currency_code'),
                $currentValidFrom,
                $data['valid_to'],
                $id,
            );

            $update = $pdo->prepare(
                'UPDATE payroll_institution_accounts
                    SET institution_name = ?,
                        variable_symbol = ?,
                        specific_symbol = ?,
                        constant_symbol = ?,
                        valid_to = ?,
                        source_kind = ?,
                        source_reference = ?,
                        verified_on = ?,
                        verified_by = ?,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([
                $data['institution_name'],
                $data['variable_symbol'],
                $data['specific_symbol'],
                $data['constant_symbol'],
                $data['valid_to'],
                $data['source_kind'],
                $data['source_reference'],
                $data['verified_on'],
                $userId,
                $userId,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollInstitutionAccountConflictException($currentVersion);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    private function lockTenant(int $supplierId): void
    {
        $lock = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $lock->execute([$supplierId]);
        if ($lock->fetchColumn() === false) {
            throw new \RuntimeException('Firma pro nastavení mezd neexistuje.');
        }
    }

    private function institutionId(int $supplierId, string $type, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        )->execute([$supplierId, $type, $code]);

        $select = $pdo->prepare(
            'SELECT id
               FROM payroll_institutions
              WHERE supplier_id = ? AND institution_type = ? AND institution_code = ?'
        );
        $select->execute([$supplierId, $type, $code]);
        $id = (int) ($select->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new \RuntimeException('Instituci se nepodařilo založit.');
        }
        return $id;
    }

    private function assertNoOverlap(
        int $supplierId,
        int $institutionId,
        string $currencyCode,
        string $validFrom,
        ?string $validTo,
        ?int $exceptId = null,
    ): void {
        $sql = 'SELECT id
                  FROM payroll_institution_accounts
                 WHERE supplier_id = ?
                   AND institution_id = ?
                   AND currency_code = ?
                   AND valid_from <= COALESCE(?, \'9999-12-31\')
                   AND COALESCE(valid_to, \'9999-12-31\') >= ?';
        $params = [$supplierId, $institutionId, $currencyCode, $validTo, $validFrom];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';

        $check = $this->db->pdo()->prepare($sql);
        $check->execute($params);
        if ($check->fetchColumn() !== false) {
            throw new PayrollInstitutionAccountOverlapException();
        }
    }

    /**
     * Doplní do DTO čitelné číslo účtu a zahodí ciphertext.
     *
     * Účet instituce NENÍ osobní údaj — je to veřejně publikovaný účet ČSSZ,
     * finančního úřadu nebo zdravotní pojišťovny, který do aplikace zadal sám
     * uživatel. Maskovaná podoba (`••••` + 6 znaků) mu znemožňovala zkontrolovat,
     * kam se posílají odvody, což je horší riziko než zobrazení veřejného čísla.
     * Šifrování v úložišti zůstává beze změny; čte se jen v této jediné cestě,
     * která je za relací a právem `payroll.settings`.
     *
     * Účty zaměstnanců (`payroll_person_accounts`) tímhle NEJSOU dotčené a dál
     * chodí ven pouze maskované.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withBankAccount(int $supplierId, array $row): array
    {
        $ciphertext = self::requiredString($row, 'bank_account_ciphertext');
        unset($row['bank_account_ciphertext']);

        $bankAccount = null;
        if (str_starts_with($ciphertext, 'enc:v2:')) {
            try {
                $bankAccount = $this->sensitiveData->reveal(
                    $ciphertext,
                    PayrollSensitiveField::BANK_ACCOUNT,
                    $supplierId,
                    self::requiredInt($row, 'id'),
                    PayrollRevealPurpose::PAYMENT_INSTITUTION_ACCOUNT,
                );
            } catch (\Throwable) {
                // Cizí klíč, poškozený záznam nebo nedokončený zápis — přehled
                // musí zůstat čitelný, uživateli zbude maskovaná podoba.
                $bankAccount = null;
            }
        }
        $row['bank_account'] = $bankAccount;

        return $row;
    }

    private function selectSql(): string
    {
        return 'SELECT account.id, account.supplier_id, account.institution_id,
                       institution.institution_type, institution.institution_code,
                       account.institution_name, account.bank_account_masked,
                       account.bank_account_ciphertext,
                       account.currency_code, account.variable_symbol,
                       account.specific_symbol, account.constant_symbol,
                       account.valid_from, account.valid_to, account.source_kind,
                       account.source_reference, account.verified_on,
                       account.verified_by, account.row_version,
                       account.created_at, account.updated_at
                  FROM payroll_institution_accounts account
                  JOIN payroll_institutions institution
                    ON institution.supplier_id = account.supplier_id
                   AND institution.id = account.institution_id';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => self::requiredInt($row, 'id'),
            'supplier_id' => self::requiredInt($row, 'supplier_id'),
            'institution_id' => self::requiredInt($row, 'institution_id'),
            'institution_type' => self::requiredString($row, 'institution_type'),
            'institution_code' => self::requiredString($row, 'institution_code'),
            'institution_name' => self::requiredString($row, 'institution_name'),
            'bank_account_masked' => self::requiredString($row, 'bank_account_masked'),
            'bank_account_ciphertext' => self::requiredString($row, 'bank_account_ciphertext'),
            'currency_code' => self::requiredString($row, 'currency_code'),
            'variable_symbol' => self::nullableString($row, 'variable_symbol'),
            'specific_symbol' => self::nullableString($row, 'specific_symbol'),
            'constant_symbol' => self::nullableString($row, 'constant_symbol'),
            'valid_from' => self::requiredString($row, 'valid_from'),
            'valid_to' => self::nullableString($row, 'valid_to'),
            'source_kind' => self::requiredString($row, 'source_kind'),
            'source_reference' => self::requiredString($row, 'source_reference'),
            'verified_on' => self::requiredString($row, 'verified_on'),
            'verified_by' => self::nullableInt($row, 'verified_by'),
            'row_version' => self::requiredInt($row, 'row_version'),
            'created_at' => self::requiredString($row, 'created_at'),
            'updated_at' => self::requiredString($row, 'updated_at'),
        ];
    }

    /** @return array<string,mixed> */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek účtu instituce.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč řádku účtu instituce.'
                );
            }
            $row[$key] = $item;
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function requiredInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \UnexpectedValueException("Databázové pole {$key} není celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \UnexpectedValueException("Databázové pole {$key} není celé číslo.");
        }
        return (int) $value;
    }
}
