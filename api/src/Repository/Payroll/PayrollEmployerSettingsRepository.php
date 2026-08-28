<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use PDO;

final class PayrollEmployerSettingsRepository
{
    private const ACCOUNT_COLUMNS = [
        'employment_gross_debit' => 'employment_gross_debit_account',
        'employment_gross_credit' => 'employment_gross_credit_account',
        'partner_gross_debit' => 'partner_gross_debit_account',
        'partner_gross_credit' => 'partner_gross_credit_account',
        'statutory_gross_debit' => 'statutory_gross_debit_account',
        'statutory_gross_credit' => 'statutory_gross_credit_account',
        'employer_insurance_debit' => 'employer_insurance_debit_account',
        'social_insurance_credit' => 'social_insurance_credit_account',
        'health_insurance_credit' => 'health_insurance_credit_account',
        'income_tax_credit' => 'income_tax_credit_account',
        'other_deductions_credit' => 'other_deductions_credit_account',
        'partner_settlement_credit' => 'partner_settlement_credit_account',
        'risky_savings_debit' => 'risky_savings_debit_account',
        'risky_savings_credit' => 'risky_savings_credit_account',
        'employee_receivable_debit' => 'employee_receivable_debit_account',
        'non_deductible_benefit_debit' => 'non_deductible_benefit_debit_account',
        'travel_expense_debit' => 'travel_expense_debit_account',
    ];

    private const STRING_COLUMNS = [
        'employer_registration_number',
        'social_security_office_code',
        'default_health_insurer_code',
        'payroll_contact_name',
        'payroll_contact_email',
        'payroll_contact_phone',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Výchozí sada předkontací pro firmu, která nastavení mezd ještě nemá.
     *
     * Není to prosté {@see PayrollAccountingDefaults::codes()}: od W7/Ú-08 je
     * výchozí kontace pojistného ANALYTICKÁ (336.100 / 336.200), aby se závazek
     * vůči ČSSZ a vůči zdravotním pojišťovnám na jednom účtu nevynetoval.
     * Analytiky ale v osnově má jen firma, které se osnova seedovala ze
     * šablony po migraci 1618 — nebo která si je založila sama.
     *
     * Nabídnout účet, který firma v osnově nemá, by mělo dva zlé následky:
     *  1. `PayrollEmployerSettingsValidator` uložení nastavení odmítne
     *     („Účet 336.100 neexistuje nebo není aktivní.“) — firma by po nasazení
     *     nemohla uložit nastavení mezd vůbec.
     *  2. `PayrollRunSnapshotBuilder` by tentýž účet zmrazil do snapshotu běhu
     *     a zaúčtování by spadlo na `unknown_account` v PostingService.
     *
     * Analytika se proto nabízí jen tehdy, když ji firma reálně má; jinak se
     * degraduje na svou syntetiku, tedy přesně na dosavadní chování. Nic se
     * tím nikomu tiše nemění — účty do osnovy stávajícím firmám VĚDOMĚ
     * nedoplňujeme, protože {@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingAccountResolver}
     * bere existenci 336.100/336.200 v osnově jako projev vůle účetní.
     *
     * @return array<string,string>
     */
    private function defaultAccounts(int $supplierId): array
    {
        $codes = PayrollAccountingDefaults::codes();
        $analytics = [];
        foreach ($codes as $code) {
            if (str_contains($code, '.')) {
                $analytics[$code] = true;
            }
        }
        if ($analytics === []) {
            return $codes;
        }

        $placeholders = implode(',', array_fill(0, count($analytics), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code
               FROM chart_of_accounts
              WHERE supplier_id = ?
                AND is_active = 1
                AND account_code IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...array_keys($analytics)]);
        $available = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $available[(string) $code] = true;
        }

        foreach ($codes as $key => $code) {
            if (isset($analytics[$code]) && !isset($available[$code])) {
                $codes[$key] = substr($code, 0, 3);
            }
        }

        return $codes;
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId): array
    {
        $columns = implode(', ', array_values(self::ACCOUNT_COLUMNS));
        $stmt = $this->db->pdo()->prepare(
            "SELECT settings.supplier_id, settings.default_office_id,
                    office.code AS default_office_code,
                    settings.employer_registration_number,
                    settings.social_security_office_code,
                    settings.default_health_insurer_code,
                    settings.payroll_contact_name,
                    settings.payroll_contact_email,
                    settings.payroll_contact_phone,
                    {$columns},
                    settings.row_version, settings.created_at, settings.updated_at
               FROM payroll_employer_settings settings
               JOIN payroll_offices office
                 ON office.supplier_id = settings.supplier_id
                AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'supplier_id' => $supplierId,
                'default_office_code' => null,
                'employer_registration_number' => null,
                'social_security_office_code' => null,
                'default_health_insurer_code' => null,
                'payroll_contact_name' => null,
                'payroll_contact_email' => null,
                'payroll_contact_phone' => null,
                'accounts' => $this->defaultAccounts($supplierId),
                'offices' => [],
                'row_version' => 0,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $result = [
            'supplier_id' => (int) $row['supplier_id'],
            'default_office_code' => (string) $row['default_office_code'],
        ];
        foreach (self::STRING_COLUMNS as $column) {
            $result[$column] = $row[$column] === null ? null : (string) $row[$column];
        }
        $result['accounts'] = [];
        foreach (self::ACCOUNT_COLUMNS as $key => $column) {
            $result['accounts'][$key] = (string) $row[$column];
        }
        $result['offices'] = $this->offices($supplierId);
        $result['row_version'] = (int) $row['row_version'];
        $result['created_at'] = (string) $row['created_at'];
        $result['updated_at'] = (string) $row['updated_at'];

        return $result;
    }

    /**
     * @param array{
     *   default_office_code:string,
     *   employer_registration_number:?string,
     *   social_security_office_code:?string,
     *   default_health_insurer_code:?string,
     *   payroll_contact_name:?string,
     *   payroll_contact_email:?string,
     *   payroll_contact_phone:?string,
     *   accounts:array<string,string>,
     *   offices:list<array{
     *     code:string,
     *     name:string,
     *     social_security_variable_symbol:?string,
     *     social_security_variable_symbol_provided:bool,
     *     is_active:bool
     *   }>
     * } $data
     * @return array<string,mixed>
     */
    public function save(int $supplierId, array $data, int $expectedVersion): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $tenantLock = $pdo->prepare(
                'SELECT id FROM supplier WHERE id = ? FOR UPDATE'
            );
            $tenantLock->execute([$supplierId]);
            if ($tenantLock->fetchColumn() === false) {
                throw new \RuntimeException('Firma pro nastavení mezd neexistuje.');
            }

            $lock = $pdo->prepare(
                'SELECT row_version
                   FROM payroll_employer_settings
                  WHERE supplier_id = ?
                  FOR UPDATE'
            );
            $lock->execute([$supplierId]);
            $current = $lock->fetchColumn();
            $currentVersion = $current === false ? 0 : (int) $current;
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEmployerSettingsConflictException($currentVersion);
            }

            $officeIds = $this->saveOffices($supplierId, $data['offices']);
            $defaultOfficeId = $officeIds[$data['default_office_code']];
            if ($current === false) {
                $this->insertSettings($supplierId, $defaultOfficeId, $data);
            } else {
                $this->updateSettings($supplierId, $defaultOfficeId, $data, $expectedVersion);
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

        return $this->get($supplierId);
    }

    /**
     * @return list<array{
     *   id:int,
     *   code:string,
     *   name:string,
     *   social_security_variable_symbol:?string,
     *   is_active:bool,
     *   row_version:int
     * }>
     */
    private function offices(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name, social_security_variable_symbol,
                    is_active, row_version
               FROM payroll_offices
              WHERE supplier_id = ?
              ORDER BY is_active DESC, code ASC'
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'social_security_variable_symbol' =>
                    $row['social_security_variable_symbol'] === null
                        ? null
                        : (string) $row['social_security_variable_symbol'],
                'is_active' => (bool) $row['is_active'],
                'row_version' => (int) $row['row_version'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * @param list<array{
     *   code:string,
     *   name:string,
     *   social_security_variable_symbol:?string,
     *   social_security_variable_symbol_provided:bool,
     *   is_active:bool
     * }> $offices
     * @return array<string,int>
     */
    private function saveOffices(int $supplierId, array $offices): array
    {
        $pdo = $this->db->pdo();
        $codes = array_column($offices, 'code');
        if ($codes !== []) {
            $placeholders = implode(', ', array_fill(0, count($codes), '?'));
            $params = array_merge([$supplierId], $codes);
            $pdo->prepare(
                "UPDATE payroll_offices
                    SET is_active = 0, row_version = row_version + 1
                  WHERE supplier_id = ?
                    AND code NOT IN ({$placeholders})
                    AND is_active = 1"
            )->execute($params);
        }

        $upsert = $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                is_active = VALUES(is_active),
                row_version = row_version + 1'
        );
        foreach ($offices as $office) {
            $upsert->execute([
                $supplierId,
                $office['code'],
                $office['name'],
                (int) $office['is_active'],
            ]);
        }

        $select = $pdo->prepare(
            'SELECT id, code FROM payroll_offices WHERE supplier_id = ?'
        );
        $select->execute([$supplierId]);
        $ids = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    /** @param array<string,mixed> $data */
    private function insertSettings(int $supplierId, int $defaultOfficeId, array $data): void
    {
        $columns = array_merge(
            ['supplier_id', 'default_office_id'],
            self::STRING_COLUMNS,
            array_values(self::ACCOUNT_COLUMNS),
        );
        $values = [$supplierId, $defaultOfficeId];
        foreach (self::STRING_COLUMNS as $column) {
            $values[] = $data[$column];
        }
        foreach (self::ACCOUNT_COLUMNS as $key => $_column) {
            $values[] = $data['accounts'][$key];
        }

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        )->execute($values);
    }

    /** @param array<string,mixed> $data */
    private function updateSettings(
        int $supplierId,
        int $defaultOfficeId,
        array $data,
        int $expectedVersion,
    ): void {
        $assignments = ['default_office_id = ?'];
        $values = [$defaultOfficeId];
        foreach (self::STRING_COLUMNS as $column) {
            $assignments[] = "{$column} = ?";
            $values[] = $data[$column];
        }
        foreach (self::ACCOUNT_COLUMNS as $key => $column) {
            $assignments[] = "{$column} = ?";
            $values[] = $data['accounts'][$key];
        }
        $assignments[] = 'row_version = row_version + 1';
        $values[] = $supplierId;
        $values[] = $expectedVersion;

        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET ' . implode(', ', $assignments)
            . ' WHERE supplier_id = ? AND row_version = ?'
        );
        $update->execute($values);
        if ($update->rowCount() !== 1) {
            throw new PayrollEmployerSettingsConflictException($expectedVersion);
        }
    }
}
