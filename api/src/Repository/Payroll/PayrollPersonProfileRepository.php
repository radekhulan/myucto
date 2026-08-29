<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * @phpstan-import-type IdentityInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-import-type AddressInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-import-type ContactInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-import-type IdentifierInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-import-type AccountInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-import-type ProfileInput from \MyInvoice\Service\Payroll\PayrollPersonProfileValidator
 * @phpstan-type ProfileView array{
 *   employee_id:int,
 *   full_name:string,
 *   profile_status:string,
 *   payout_method:string,
 *   partner_settlement_account_code:?string,
 *   cash_allocation_basis_points:int,
 *   payout_effective_on:?string,
 *   secure_delivery_channel:string,
 *   row_version:int,
 *   identity_history:list<array<string,mixed>>,
 *   addresses:list<array<string,mixed>>,
 *   contacts:list<array<string,mixed>>,
 *   identifiers:list<array<string,mixed>>,
 *   accounts:list<array<string,mixed>>,
 *   created_at:?string,
 *   updated_at:?string
 * }
 */
final class PayrollPersonProfileRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return ProfileView|null */
    public function get(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT employee.id AS employee_id, employee.full_name AS legacy_full_name,
                    profile.profile_status, profile.payout_method,
                    profile.partner_settlement_account_code,
                    profile.cash_allocation_basis_points,
                    profile.payout_effective_on, profile.secure_delivery_channel,
                    profile.row_version,
                    profile.created_at, profile.updated_at
               FROM payroll_employees employee
               LEFT JOIN payroll_employee_profiles profile
                 ON profile.supplier_id = employee.supplier_id
                AND profile.employee_id = employee.id
              WHERE employee.supplier_id = ? AND employee.id = ?"
        );
        $stmt->execute([$supplierId, $employeeId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            return null;
        }
        $row = $this->normalizeRow($fetched);

        $identity = $this->rows(
            'SELECT id, full_name, first_name, last_name,
                    title_prefix, title_suffix, birth_surname, birth_date,
                    birth_place, birth_country_code, citizenship_country_code, sex,
                    effective_from, effective_to, row_version
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY effective_from DESC, id DESC',
            $supplierId,
            $employeeId,
            fn (array $item): array => [
                'id' => (int) $item['id'],
                'full_name' => (string) $item['full_name'],
                'first_name' => (string) $item['first_name'],
                'last_name' => (string) $item['last_name'],
                'title_prefix' => $item['title_prefix'] === null
                    ? null
                    : (string) $item['title_prefix'],
                'title_suffix' => $item['title_suffix'] === null
                    ? null
                    : (string) $item['title_suffix'],
                'birth_surname_masked' => $item['birth_surname'] === null
                    ? null
                    : $this->maskName((string) $item['birth_surname']),
                'birth_date' => $item['birth_date'] === null
                    ? null
                    : (string) $item['birth_date'],
                'birth_place' => $item['birth_place'] === null
                    ? null
                    : (string) $item['birth_place'],
                'birth_country_code' => $item['birth_country_code'] === null
                    ? null
                    : (string) $item['birth_country_code'],
                'citizenship_country_code' => $item['citizenship_country_code'] === null
                    ? null
                    : (string) $item['citizenship_country_code'],
                'sex' => $item['sex'] === null ? null : (string) $item['sex'],
                'effective_from' => (string) $item['effective_from'],
                'effective_to' => $item['effective_to'] === null ? null : (string) $item['effective_to'],
                'row_version' => (int) $item['row_version'],
            ],
        );
        $addresses = $this->rows(
            'SELECT id, address_type, street_line, city, postal_code, country_code,
                    effective_from, effective_to, row_version
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY address_type ASC, effective_from DESC, id DESC',
            $supplierId,
            $employeeId,
            fn (array $item): array => [
                'id' => (int) $item['id'],
                'address_type' => (string) $item['address_type'],
                'address_masked' => $this->maskAddress(
                    (string) $item['city'],
                    (string) $item['country_code'],
                ),
                'effective_from' => (string) $item['effective_from'],
                'effective_to' => $item['effective_to'] === null ? null : (string) $item['effective_to'],
                'row_version' => (int) $item['row_version'],
            ],
        );
        $contacts = $this->rows(
            'SELECT id, contact_type, contact_value_masked, is_primary, is_active, row_version
               FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY is_active DESC, is_primary DESC, contact_type ASC, id ASC',
            $supplierId,
            $employeeId,
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'contact_type' => (string) $item['contact_type'],
                'value_masked' => (string) $item['contact_value_masked'],
                'is_primary' => (bool) $item['is_primary'],
                'is_active' => (bool) $item['is_active'],
                'row_version' => (int) $item['row_version'],
            ],
        );
        $identifiers = $this->rows(
            'SELECT id, identifier_type, value_masked, row_version
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY identifier_type ASC',
            $supplierId,
            $employeeId,
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'identifier_type' => (string) $item['identifier_type'],
                'value_masked' => self::narrowIdentifierMask((string) $item['value_masked']),
                'row_version' => (int) $item['row_version'],
            ],
        );
        $accounts = $this->rows(
            'SELECT id, label, bank_account_masked, allocation_basis_points,
                    effective_from, effective_to, is_active, row_version,
                    verification_source, verified_on, verified_by
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY is_active DESC, effective_from DESC, id ASC',
            $supplierId,
            $employeeId,
            static fn (array $item): array => [
                'id' => (int) $item['id'],
                'label' => (string) $item['label'],
                'bank_account_masked' => (string) $item['bank_account_masked'],
                'allocation_basis_points' => (int) $item['allocation_basis_points'],
                'effective_from' => (string) $item['effective_from'],
                'effective_to' => $item['effective_to'] === null ? null : (string) $item['effective_to'],
                'is_active' => (bool) $item['is_active'],
                'row_version' => (int) $item['row_version'],
                'verification_source' => $item['verification_source'] === null
                    ? null
                    : (string) $item['verification_source'],
                'verified_on' => $item['verified_on'] === null
                    ? null
                    : (string) $item['verified_on'],
                'verified_by' => $item['verified_by'] === null
                    ? null
                    : (int) $item['verified_by'],
            ],
        );

        return [
            'employee_id' => (int) $row['employee_id'],
            'full_name' => $this->effectiveName($identity, (string) $row['legacy_full_name']),
            'profile_status' => $row['profile_status'] === null ? 'missing' : (string) $row['profile_status'],
            'payout_method' => $row['payout_method'] === null ? 'cash' : (string) $row['payout_method'],
            'partner_settlement_account_code' =>
                $row['partner_settlement_account_code'] === null
                    ? null
                    : (string) $row['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $row['cash_allocation_basis_points'] === null
                ? 10000
                : (int) $row['cash_allocation_basis_points'],
            'payout_effective_on' => $row['payout_effective_on'] === null
                ? null
                : (string) $row['payout_effective_on'],
            'secure_delivery_channel' => $row['secure_delivery_channel'] === null
                ? 'portal'
                : (string) $row['secure_delivery_channel'],
            'row_version' => $row['row_version'] === null ? 0 : (int) $row['row_version'],
            'identity_history' => $identity,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'identifiers' => $identifiers,
            'accounts' => $accounts,
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }

    /**
     * @param ProfileInput $data
     * @return ProfileView
     */
    public function save(
        int $supplierId,
        int $employeeId,
        array $data,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_person_profile_save');
        }

        try {
            $this->lockEmployee($supplierId, $employeeId);
            $currentVersion = $this->lockProfile($supplierId, $employeeId);
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollPersonProfileConflictException($currentVersion);
            }

            $created = false;
            if ($currentVersion === 0) {
                $pdo->prepare(
                    'INSERT INTO payroll_employee_profiles
                        (supplier_id, employee_id, profile_status, payout_method,
                         partner_settlement_account_code,
                         cash_allocation_basis_points, payout_effective_on,
                         secure_delivery_channel, row_version)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                )->execute([
                    $supplierId,
                    $employeeId,
                    $data['profile_status'],
                    $data['payout_method'],
                    $data['partner_settlement_account_code'],
                    $data['cash_allocation_basis_points'],
                    $data['payout_effective_on'],
                    $data['secure_delivery_channel'],
                ]);
                $created = true;
            }

            $this->saveIdentityHistory($supplierId, $employeeId, $data['identity_history']);
            $this->saveAddresses($supplierId, $employeeId, $data['addresses']);
            $this->saveContacts($supplierId, $employeeId, $data['contacts']);
            $this->saveIdentifiers($supplierId, $employeeId, $data['identifiers']);
            $this->saveAccounts($supplierId, $employeeId, $data['accounts']);
            $this->assertStoredIntervals($supplierId, $employeeId);
            $this->assertStoredPrimaryContacts($supplierId, $employeeId);
            $this->assertPayoutAllocation(
                $supplierId,
                $employeeId,
                (string) $data['payout_method'],
                (int) $data['cash_allocation_basis_points'],
                (string) $data['payout_effective_on'],
            );
            $this->assertReadyProfile($supplierId, $employeeId, (string) $data['profile_status']);
            $this->synchronizeCurrentLegacyIdentity($supplierId, $employeeId);

            if (!$created) {
                $update = $pdo->prepare(
                    'UPDATE payroll_employee_profiles
                        SET profile_status = ?, payout_method = ?,
                            partner_settlement_account_code = ?,
                            cash_allocation_basis_points = ?,
                            payout_effective_on = ?,
                            secure_delivery_channel = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND row_version = ?'
                );
                $update->execute([
                    $data['profile_status'],
                    $data['payout_method'],
                    $data['partner_settlement_account_code'],
                    $data['cash_allocation_basis_points'],
                    $data['payout_effective_on'],
                    $data['secure_delivery_channel'],
                    $supplierId,
                    $employeeId,
                    $expectedVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollPersonProfileConflictException($this->lockProfile($supplierId, $employeeId));
                }
            }

            $saved = $this->get($supplierId, $employeeId)
                ?? throw new \RuntimeException('Uložená osobní karta nebyla nalezena.');
            $this->activityLogger->log(
                'payroll.person_profile.updated',
                $userId,
                'payroll_employee',
                $employeeId,
                [
                    'row_version' => $saved['row_version'],
                    'profile_status' => $saved['profile_status'],
                    'payout_method' => $saved['payout_method'],
                    'identity_history_count' => count($saved['identity_history']),
                    'address_count' => count($saved['addresses']),
                    'contact_count' => count($saved['contacts']),
                    'identifier_count' => count($saved['identifiers']),
                    'account_count' => count($saved['accounts']),
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_profile_save');
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->rollBackOwnedTransaction($pdo);
            } elseif (!$ownsTransaction) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_profile_save');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_profile_save');
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                // SQLSTATE 23000 nese víc různých chyb; jedna hláška pro všechny posílá
                // uživatele hledat duplicitu i tam, kde jen chybí povinná hodnota.
                $driverCode = (int) ($e->errorInfo[1] ?? 0);
                throw new \InvalidArgumentException(
                    $driverCode === 1062
                        ? 'Osobní karta obsahuje duplicitní záznam — stejná hodnota už je u zaměstnance vedená.'
                        : 'Osobní kartu se nepodařilo uložit — chybí povinná hodnota nebo vazba na neexistující záznam.',
                    0,
                    $e,
                );
            }
            throw $e;
        }

        return $saved;
    }

    private function lockEmployee(int $supplierId, int $employeeId): void
    {
        $tenant = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $tenant->execute([$supplierId]);
        if ($tenant->fetchColumn() === false) {
            throw new PayrollPersonNotFoundException();
        }

        $employee = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        if ($employee->fetchColumn() === false) {
            throw new PayrollPersonNotFoundException();
        }
    }

    private function lockProfile(int $supplierId, int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version
               FROM payroll_employee_profiles
              WHERE supplier_id = ? AND employee_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $version = $stmt->fetchColumn();

        return $version === false ? 0 : (int) $version;
    }

    private function earliestEmploymentStart(int $supplierId, int $employeeId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(start_date)
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasIdentityVersion(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param list<IdentityInput> $rows */
    private function saveIdentityHistory(int $supplierId, int $employeeId, array $rows): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 title_prefix, title_suffix, birth_surname, birth_date, birth_place,
                 birth_country_code, citizenship_country_code, sex,
                 effective_from, effective_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET full_name = ?,
                    first_name = ?,
                    last_name = ?,
                    title_prefix = CASE WHEN ? = 1 THEN ? ELSE title_prefix END,
                    title_suffix = CASE WHEN ? = 1 THEN ? ELSE title_suffix END,
                    birth_surname = CASE WHEN ? = 1 THEN ? ELSE birth_surname END,
                    birth_date = CASE WHEN ? = 1 THEN ? ELSE birth_date END,
                    birth_place = CASE WHEN ? = 1 THEN ? ELSE birth_place END,
                    birth_country_code = CASE WHEN ? = 1 THEN ? ELSE birth_country_code END,
                    citizenship_country_code = CASE WHEN ? = 1 THEN ? ELSE citizenship_country_code END,
                    sex = CASE WHEN ? = 1 THEN ? ELSE sex END,
                    effective_from = ?, effective_to = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $birthSurnameSource = $this->db->pdo()->prepare(
            'SELECT birth_surname
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ? AND id = ?
              FOR UPDATE'
        );
        $earliestEmploymentStart = $this->earliestEmploymentStart($supplierId, $employeeId);
        $hasExistingVersion = $this->hasIdentityVersion($supplierId, $employeeId);
        foreach ($rows as $row) {
            if ($row['id'] === null) {
                // ⚠️ PRVNÍ verze identity musí platit od NÁSTUPU, ne ode dne, kdy
                // se karta vyplnila. Prvotní registrace do registru pojištěnců se
                // podává k datu nástupu a bez identity k němu skončí na
                // `K rozhodnému datu chybí historická identita osoby.` — což
                // potká každého, kdo přejde z jiného systému. O jméně se nic
                // nedomýšlí, posouvá se jen datum, odkdy uložená verze platí.
                if (!$hasExistingVersion
                    && $earliestEmploymentStart !== null
                    && $row['effective_from'] > $earliestEmploymentStart
                ) {
                    $row['effective_from'] = $earliestEmploymentStart;
                }
                $hasExistingVersion = true;
                $birthSurname = $row['birth_surname'];
                if (!$row['birth_surname_present'] && $row['birth_surname_source_id'] !== null) {
                    $birthSurnameSource->execute([
                        $supplierId,
                        $employeeId,
                        $row['birth_surname_source_id'],
                    ]);
                    $source = $birthSurnameSource->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($source) || !array_key_exists('birth_surname', $source)) {
                        throw new \InvalidArgumentException(
                            'Zdroj rodného příjmení nepatří upravovanému zaměstnanci.'
                        );
                    }
                    $birthSurname = $source['birth_surname'];
                }
                $insert->execute([
                    $supplierId,
                    $employeeId,
                    $row['full_name'],
                    $row['first_name'],
                    $row['last_name'],
                    $row['title_prefix'],
                    $row['title_suffix'],
                    $birthSurname,
                    $row['birth_date'],
                    $row['birth_place'],
                    $row['birth_country_code'],
                    $row['citizenship_country_code'],
                    $row['sex'],
                    $row['effective_from'],
                    $row['effective_to'],
                ]);
                continue;
            }
            $update->execute([
                $row['full_name'],
                $row['first_name'],
                $row['last_name'],
                (int) $row['title_prefix_present'],
                $row['title_prefix'],
                (int) $row['title_suffix_present'],
                $row['title_suffix'],
                (int) $row['birth_surname_present'],
                $row['birth_surname'],
                (int) $row['birth_date_present'],
                $row['birth_date'],
                (int) $row['birth_place_present'],
                $row['birth_place'],
                (int) $row['birth_country_code_present'],
                $row['birth_country_code'],
                (int) $row['citizenship_country_code_present'],
                $row['citizenship_country_code'],
                (int) $row['sex_present'],
                $row['sex'],
                $row['effective_from'],
                $row['effective_to'],
                $supplierId,
                $employeeId,
                $row['id'],
            ]);
            $this->assertOwnedRowUpdated($update, 'Historie jména');
        }
    }

    /** @param list<AddressInput> $rows */
    private function saveAddresses(int $supplierId, int $employeeId, array $rows): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code,
                 country_code, effective_from, effective_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_person_addresses
                SET address_type = ?,
                    street_line = CASE WHEN ? = 1 THEN ? ELSE street_line END,
                    city = CASE WHEN ? = 1 THEN ? ELSE city END,
                    postal_code = CASE WHEN ? = 1 THEN ? ELSE postal_code END,
                    country_code = CASE WHEN ? = 1 THEN ? ELSE country_code END,
                    effective_from = ?, effective_to = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        foreach ($rows as $row) {
            if ($row['id'] === null) {
                $insert->execute([
                    $supplierId,
                    $employeeId,
                    $row['address_type'],
                    $row['street_line'],
                    $row['city'],
                    $row['postal_code'],
                    $row['country_code'],
                    $row['effective_from'],
                    $row['effective_to'],
                ]);
                continue;
            }
            $update->execute([
                $row['address_type'],
                (int) $row['address_present'],
                $row['street_line'],
                (int) $row['address_present'],
                $row['city'],
                (int) $row['address_present'],
                $row['postal_code'],
                (int) $row['address_present'],
                $row['country_code'],
                $row['effective_from'],
                $row['effective_to'],
                $supplierId,
                $employeeId,
                $row['id'],
            ]);
            $this->assertOwnedRowUpdated($update, 'Adresa');
        }
    }

    /**
     * Řádek kontaktu s TOUTÉŽ hodnotou (i deaktivovaný) — podklad pro recyklaci
     * místo vložení druhého řádku, který by porazil `uq_payroll_contact_value`.
     */
    private function findContactByValue(int $supplierId, int $employeeId, string $contactType, string $lookupHash): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ? AND contact_type = ? AND contact_value_hash = ?
              ORDER BY id LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId, $contactType, $lookupHash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param list<ContactInput> $rows */
    private function saveContacts(int $supplierId, int $employeeId, array $rows): void
    {
        foreach ($rows as $row) {
            $id = $row['id'];
            $field = $row['contact_type'] === 'email'
                ? PayrollSensitiveField::CONTACT_EMAIL
                : PayrollSensitiveField::CONTACT_PHONE;
            // Kontakt se stejnou hodnotou už u osoby JEDNOU existovat může — třeba
            // deaktivovaný z dřívějška. `uq_payroll_contact_value` je nad
            // (supplier, employee, contact_type, hash) a `is_active` v něm NENÍ, takže
            // druhý řádek s touž hodnotou skončí na 23000. Formulář „Běžné údaje“
            // přitom posílá e-mail i telefon jako náhradu vždy, když je pole vyplněné,
            // takže uložení karty s NEZMĚNĚNÝM kontaktem padalo pokaždé. Existující
            // řádek proto recyklujeme: hodnota se nemění, mění se jen příznaky.
            if ($id === null && $row['value'] !== null) {
                $existingId = $this->findContactByValue(
                    $supplierId,
                    $employeeId,
                    $row['contact_type'],
                    $this->sensitiveData->lookupHash($row['value'], $field, $supplierId),
                );
                if ($existingId !== null) {
                    $id = $existingId;
                }
            }
            if ($id === null) {
                $this->db->pdo()->prepare(
                    "INSERT INTO payroll_person_contacts
                        (supplier_id, employee_id, contact_type,
                         contact_value_ciphertext, contact_value_hash, contact_value_masked,
                         is_primary, is_active)
                     VALUES (?, ?, ?, '', ?, '', ?, ?)"
                )->execute([
                    $supplierId,
                    $employeeId,
                    $row['contact_type'],
                    str_repeat("\0", 32),
                    (int) $row['is_primary'],
                    (int) $row['is_active'],
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
            } else {
                $existing = $this->db->pdo()->prepare(
                    'SELECT contact_type
                       FROM payroll_person_contacts
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                      FOR UPDATE'
                );
                $existing->execute([$supplierId, $employeeId, $id]);
                $storedType = $existing->fetchColumn();
                if ($storedType === false) {
                    throw new \InvalidArgumentException('Kontakt nepatří tomuto zaměstnanci.');
                }
                if ((string) $storedType !== $row['contact_type']) {
                    throw new \InvalidArgumentException('Typ existujícího kontaktu nelze změnit.');
                }
                $metadata = $this->db->pdo()->prepare(
                    'UPDATE payroll_person_contacts
                        SET is_primary = ?, is_active = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?'
                );
                $metadata->execute([
                    (int) $row['is_primary'],
                    (int) $row['is_active'],
                    $supplierId,
                    $employeeId,
                    $id,
                ]);
                $this->assertOwnedRowUpdated($metadata, 'Kontakt');
            }

            if ($row['value'] === null) {
                continue;
            }
            $sealed = $this->sensitiveData->seal(
                $row['value'],
                $field,
                $supplierId,
                (int) $id,
            );
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_person_contacts
                    SET contact_value_ciphertext = ?, contact_value_hash = ?,
                        contact_value_masked = ?
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?'
            );
            $update->execute([
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
                $supplierId,
                $employeeId,
                $id,
            ]);
            $this->assertOwnedRowUpdated($update, 'Kontakt');
        }
    }

    /** @param list<IdentifierInput> $rows */
    private function saveIdentifiers(int $supplierId, int $employeeId, array $rows): void
    {
        foreach ($rows as $row) {
            $id = $row['id'];
            $type = (string) $row['identifier_type'];
            $created = false;
            $field = $type === 'foreign_tax_identifier'
                ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
                : PayrollSensitiveField::PERSONAL_IDENTIFIER;
            if ($id === null) {
                $this->db->pdo()->prepare(
                    "INSERT INTO payroll_person_identifiers
                        (supplier_id, employee_id, identifier_type,
                         value_ciphertext, value_hash, value_masked)
                     VALUES (?, ?, ?, '', ?, '')"
                )->execute([$supplierId, $employeeId, $type, str_repeat("\0", 32)]);
                $id = (int) $this->db->pdo()->lastInsertId();
                $created = true;
            } else {
                $existing = $this->db->pdo()->prepare(
                    'SELECT identifier_type
                       FROM payroll_person_identifiers
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                      FOR UPDATE'
                );
                $existing->execute([$supplierId, $employeeId, $id]);
                $storedType = $existing->fetchColumn();
                if ($storedType === false) {
                    throw new \InvalidArgumentException('Identifikátor nepatří tomuto zaměstnanci.');
                }
                if ((string) $storedType !== $type) {
                    throw new \InvalidArgumentException('Typ existujícího identifikátoru nelze změnit.');
                }
            }

            if ($row['value'] === null) {
                continue;
            }
            $sealed = $this->sensitiveData->seal(
                (string) $row['value'],
                $field,
                $supplierId,
                (int) $id,
            );
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_person_identifiers
                    SET value_ciphertext = ?, value_hash = ?, value_masked = ?,
                        row_version = row_version + ?
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?'
            );
            $update->execute([
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
                $created ? 0 : 1,
                $supplierId,
                $employeeId,
                $id,
            ]);
            $this->assertOwnedRowUpdated($update, 'Identifikátor');
        }
    }

    /** @param list<AccountInput> $rows */
    private function saveAccounts(int $supplierId, int $employeeId, array $rows): void
    {
        foreach ($rows as $row) {
            $id = $row['id'];
            if ($id === null) {
                $this->db->pdo()->prepare(
                    "INSERT INTO payroll_person_accounts
                        (supplier_id, employee_id, label, bank_account_ciphertext,
                         bank_account_hash, bank_account_masked, allocation_basis_points,
                         effective_from, effective_to, is_active)
                     VALUES (?, ?, ?, '', ?, '', ?, ?, ?, ?)"
                )->execute([
                    $supplierId,
                    $employeeId,
                    $row['label'],
                    str_repeat("\0", 32),
                    $row['allocation_basis_points'],
                    $row['effective_from'],
                    $row['effective_to'],
                    (int) $row['is_active'],
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
            } else {
                $existing = $this->db->pdo()->prepare(
                    'SELECT id
                       FROM payroll_person_accounts
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                      FOR UPDATE'
                );
                $existing->execute([$supplierId, $employeeId, $id]);
                if ($existing->fetchColumn() === false) {
                    throw new \InvalidArgumentException('Bankovní účet nepatří tomuto zaměstnanci.');
                }
                $metadata = $this->db->pdo()->prepare(
                    'UPDATE payroll_person_accounts
                        SET label = ?, allocation_basis_points = ?, effective_from = ?,
                            effective_to = ?, is_active = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?'
                );
                $metadata->execute([
                    $row['label'],
                    $row['allocation_basis_points'],
                    $row['effective_from'],
                    $row['effective_to'],
                    (int) $row['is_active'],
                    $supplierId,
                    $employeeId,
                    $id,
                ]);
                $this->assertOwnedRowUpdated($metadata, 'Bankovní účet');
            }

            if ($row['bank_account'] === null) {
                continue;
            }
            $sealed = $this->sensitiveData->seal(
                (string) $row['bank_account'],
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
                (int) $id,
            );
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_person_accounts
                    SET bank_account_ciphertext = ?, bank_account_hash = ?,
                        bank_account_masked = ?
                  WHERE supplier_id = ? AND employee_id = ? AND id = ?'
            );
            $update->execute([
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
                $supplierId,
                $employeeId,
                $id,
            ]);
            $this->assertOwnedRowUpdated($update, 'Bankovní účet');
        }
    }

    private function assertStoredIntervals(int $supplierId, int $employeeId): void
    {
        $identity = $this->db->pdo()->prepare(
            'SELECT effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY effective_from ASC, id ASC'
        );
        $identity->execute([$supplierId, $employeeId]);
        $this->assertNoOverlap($this->databaseRows($identity), 'Historie jména');

        $addresses = $this->db->pdo()->prepare(
            'SELECT address_type, effective_from, effective_to
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY address_type ASC, effective_from ASC, id ASC'
        );
        $addresses->execute([$supplierId, $employeeId]);
        $groups = [];
        foreach ($this->databaseRows($addresses) as $row) {
            $groups[$this->stringValue($row, 'address_type')][] = $row;
        }
        foreach ($groups as $type => $rows) {
            $this->assertNoOverlap($rows, "Historie adresy {$type}");
        }
    }

    /** @param list<array<string,string|int|bool|null>> $rows */
    private function assertNoOverlap(array $rows, string $label): void
    {
        $previousTo = null;
        foreach ($rows as $index => $row) {
            $from = $this->stringValue($row, 'effective_from');
            if ($index > 0 && ($previousTo === null || $from <= $previousTo)) {
                throw new \InvalidArgumentException("{$label} obsahuje překrývající se intervaly.");
            }
            $previousTo = $this->nullableStringValue($row, 'effective_to');
        }
    }

    private function assertStoredPrimaryContacts(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT contact_type, COUNT(*) AS primary_count
               FROM payroll_person_contacts
              WHERE supplier_id = ? AND employee_id = ?
                AND is_active = 1 AND is_primary = 1
              GROUP BY contact_type
             HAVING COUNT(*) > 1'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException('Každý typ kontaktu smí mít jen jednu aktivní primární hodnotu.');
        }
    }

    private function assertPayoutAllocation(
        int $supplierId,
        int $employeeId,
        string $method,
        int $cashBasisPoints,
        string $effectiveOn,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT allocation_basis_points, effective_from, effective_to
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
              ORDER BY effective_from ASC, id ASC'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $accounts = $this->databaseRows($stmt);
        $boundaries = [$effectiveOn => true];
        foreach ($accounts as $account) {
            $from = $this->stringValue($account, 'effective_from');
            if ($from >= $effectiveOn) {
                $boundaries[$from] = true;
            }
            $to = $this->nullableStringValue($account, 'effective_to');
            if ($to !== null) {
                $after = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
                if ($after >= $effectiveOn) {
                    $boundaries[$after] = true;
                }
            }
        }
        ksort($boundaries, SORT_STRING);

        foreach (array_keys($boundaries) as $boundary) {
            $accountBasisPoints = 0;
            foreach ($accounts as $account) {
                $from = $this->stringValue($account, 'effective_from');
                $to = $this->nullableStringValue($account, 'effective_to');
                if ($from <= $boundary && ($to === null || $to >= $boundary)) {
                    $accountBasisPoints += $this->intValue($account, 'allocation_basis_points');
                }
            }
            $valid = match ($method) {
                'cash' => $cashBasisPoints === 10000 && $accountBasisPoints === 0,
                'bank' => $cashBasisPoints === 0 && $accountBasisPoints === 10000,
                'mixed' => $cashBasisPoints > 0
                    && $cashBasisPoints < 10000
                    && $cashBasisPoints + $accountBasisPoints === 10000,
                // Zápočet na účet společníka není výplata — nesmí odejít ani
                // hotovost, ani platba na účet. Celá čistá mzda se přeúčtuje.
                PayrollPartnerSettlement::KIND =>
                    $cashBasisPoints === 0 && $accountBasisPoints === 0,
                default => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException(
                    "Rozdělení výplaty není k {$boundary} přesně 100 %."
                );
            }
        }

        if ($method === PayrollPartnerSettlement::KIND) {
            $this->assertPartnerSettlementEligible($supplierId, $employeeId);
        }
    }

    /**
     * Zápočet proti účtu společníka smí mít jen osoba s příjmem společníka nebo
     * odměnou za výkon funkce. Kontrola sedí tady, protože save() je jediný
     * zapisovací trychtýř payout_method (volá ho i PayrollPersonQuickEditService),
     * zatímco PayrollPersonProfileValidator vidí jen tělo požadavku a o vztazích
     * osoby neví.
     */
    private function assertPartnerSettlementEligible(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $relationTypes = [];
        foreach ($this->databaseRows($stmt) as $row) {
            $relationTypes[] = $this->stringValue($row, 'relation_type');
        }

        try {
            PayrollPartnerSettlement::assertEligible($relationTypes, $employeeId);
        } catch (\DomainException $e) {
            throw new \InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }

    private function assertReadyProfile(int $supplierId, int $employeeId, string $status): void
    {
        if ($status !== 'ready') {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                EXISTS(
                    SELECT 1 FROM payroll_person_identity_history
                     WHERE supplier_id = ? AND employee_id = ?
                       AND first_name IS NOT NULL AND first_name <> ''
                       AND last_name IS NOT NULL AND last_name <> ''
                       AND effective_from <= CURRENT_DATE
                       AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
                ) AS has_identity,
                EXISTS(
                    SELECT 1 FROM payroll_person_addresses
                     WHERE supplier_id = ? AND employee_id = ? AND address_type = 'residence'
                       AND effective_from <= CURRENT_DATE
                       AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
                ) AS has_residence,
                EXISTS(
                    SELECT 1 FROM payroll_person_contacts
                     WHERE supplier_id = ? AND employee_id = ?
                       AND is_active = 1 AND is_primary = 1
                ) AS has_contact,
                EXISTS(
                    SELECT 1 FROM payroll_person_identifiers
                     WHERE supplier_id = ? AND employee_id = ?
                       AND identifier_type IN ('birth_number', 'ecp', 'vcp')
                ) AS has_supported_identifier"
        );
        $stmt->execute([
            $supplierId, $employeeId,
            $supplierId, $employeeId,
            $supplierId, $employeeId,
            $supplierId, $employeeId,
        ]);
        $row = $this->normalizeRow($stmt->fetch(PDO::FETCH_ASSOC));
        foreach (['has_identity', 'has_residence', 'has_contact', 'has_supported_identifier'] as $key) {
            if ($this->intValue($row, $key) !== 1) {
                throw new \InvalidArgumentException(
                    'Profil nelze označit ready bez strukturovaného jména, adresy, kontaktu'
                    . ' a ověřeného osobního identifikátoru (RČ, EČP nebo VČP).'
                );
            }
        }
    }

    private function synchronizeCurrentLegacyIdentity(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT full_name
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= CURRENT_DATE
                AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $fullName = $stmt->fetchColumn();
        if ($fullName === false) {
            return;
        }
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_employees
                SET full_name = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $update->execute([(string) $fullName, $supplierId, $employeeId]);
        if ($update->rowCount() > 1) {
            throw new \UnexpectedValueException('Synchronizace legacy zaměstnance zasáhla více řádků.');
        }
    }

    /**
     * Zkrátí uloženou masku osobního identifikátoru na dvě viditelné číslice (W1/P-06).
     *
     * Maska se materializuje při zapečetění hodnoty, takže záznamy zapsané dřív
     * nesou ještě čtyřmístnou koncovku — a přepočítat ji z databáze nejde, protože
     * by se musel dešifrovat ciphertext. Zkrácení je proto i na čtení: v téže
     * odpovědi chodí `birth_date` a `sex`, ze kterých plyne prvních šest číslic
     * rodného čísla, takže čtyřmístná koncovka je celé rodné číslo. Operace je
     * idempotentní — nad už zkrácenou maskou nic nemění. Migrace 1611 dorovnává
     * totéž v datech, tohle je pojistka pro instalace, kde ještě neproběhla.
     */
    private static function narrowIdentifierMask(string $masked): string
    {
        $length = mb_strlen($masked, 'UTF-8');
        if ($length <= 2) {
            return $masked;
        }
        $visible = 0;
        while ($visible < $length && mb_substr($masked, -($visible + 1), 1, 'UTF-8') !== '•') {
            $visible++;
        }
        if ($visible <= 2) {
            return $masked;
        }

        return str_repeat('•', $length - 2) . mb_substr($masked, -2, null, 'UTF-8');
    }

    private function assertOwnedRowUpdated(\PDOStatement $statement, string $label): void
    {
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException("{$label} nepatří tomuto zaměstnanci.");
        }
    }

    private function rollBackOwnedTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @template T of array<string,mixed>
     * @param callable(array<string,string|int|bool|null>):T $mapper
     * @return list<T>
     */
    private function rows(
        string $sql,
        int $supplierId,
        int $employeeId,
        callable $mapper,
    ): array {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($this->databaseRows($stmt) as $row) {
            $result[] = $mapper($row);
        }

        return $result;
    }

    /**
     * @param list<array{
     *   id:int,full_name:string,first_name:string,last_name:string,
     *   birth_surname_masked:?string,effective_from:string,
     *   effective_to:?string,row_version:int
     * }> $identity
     */
    private function effectiveName(array $identity, string $fallback): string
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        foreach ($identity as $row) {
            if ($row['effective_from'] <= $today
                && ($row['effective_to'] === null || $row['effective_to'] >= $today)
            ) {
                return $row['full_name'];
            }
        }

        return $fallback;
    }

    private function maskAddress(string $city, string $countryCode): string
    {
        $first = mb_substr($city, 0, 1, 'UTF-8');

        return '••••••, ' . $first
            . str_repeat('•', max(3, mb_strlen($city, 'UTF-8') - 1))
            . ', ••• ••, ' . $countryCode;
    }

    private function maskName(string $value): string
    {
        return mb_substr($value, 0, 1, 'UTF-8')
            . str_repeat('•', max(3, mb_strlen($value, 'UTF-8') - 1));
    }

    /** @return list<array<string,string|int|bool|null>> */
    private function databaseRows(\PDOStatement $statement): array
    {
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->normalizeRow($row);
        }

        return $result;
    }

    /** @return array<string,string|int|bool|null> */
    private function normalizeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek osobní karty.');
        }
        $row = [];
        foreach ($value as $key => $cell) {
            if (!is_string($key)
                || (!is_string($cell) && !is_int($cell) && !is_bool($cell) && $cell !== null)
            ) {
                throw new \UnexpectedValueException('Databáze vrátila neplatnou hodnotu osobní karty.');
            }
            $row[$key] = $cell;
        }

        return $row;
    }

    /** @param array<string,string|int|bool|null> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_bool($value)) {
            return (string) (int) $value;
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
    }

    /** @param array<string,string|int|bool|null> $row */
    private function nullableStringValue(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null
            ? null
            : $this->stringValue($row, $key);
    }

    /** @param array<string,string|int|bool|null> $row */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není celé číslo.");
    }
}
