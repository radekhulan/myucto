<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Identita vyživovaného dítěte pro měsíční blok JMHZ `zvyhodneniDetiMesic`.
 *
 * Nárok (`payroll_person_tax_child_claims`) nese jen neosobní `child_reference`,
 * protože do snímku mzdového běhu osobní údaje nepatří. XSD (`osobaType`) ale
 * u dítěte vyžaduje jméno a příjmení. Kontroly 114, 128 a 215 navíc vyžadují
 * identifikaci pro ověření věku, proto se do přípravy dohledává také datum
 * narození. Rodné číslo (10438) se odhaluje jen pro podání: podle něj ČSSZ
 * a správce daně párují dítě mezi poplatníky a uvádějí ho i ostatní mzdové
 * programy. Snímek přípravy hlášení je šifrovaný.
 */
final readonly class PayrollDependantJmhzIdentityRepository
{
    public function __construct(
        private Connection $db,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * @param list<int> $employeeIds
     * @return array<int,array<string,array{given_name:string,family_name:string,birth_date:string,birth_number?:string}>>
     *         zaměstnanec → `child_reference` → identita
     */
    public function identitiesFor(int $supplierId, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $employeeIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($supplierId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT employee_id, id, given_name, family_name, birth_date, birth_number_ciphertext
               FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id IN ({$placeholders})
              ORDER BY employee_id, id"
        );
        $statement->execute([$supplierId, ...$ids]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $givenName = trim((string) ($row['given_name'] ?? ''));
            $familyName = trim((string) ($row['family_name'] ?? ''));
            if ($givenName === '' || $familyName === '') {
                // Rozdělené jméno je nepovinné (`full_name` se záměrně
                // nerozděluje automaticky). Neúplnou identitu radši vůbec
                // nezmrazíme, ať resolver hlásí chybějící údaj, a ne půlku.
                continue;
            }
            $identity = [
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => (string) ($row['birth_date'] ?? ''),
            ];
            $birthNumber = $this->birthNumber($supplierId, (int) $row['id'], $row['birth_number_ciphertext'] ?? null);
            if ($birthNumber !== null) {
                $identity['birth_number'] = $birthNumber;
            }
            $result[(int) $row['employee_id']]['dependant-' . (int) $row['id']] = $identity;
        }

        return $result;
    }

    /**
     * Rodné číslo jiné osoby vyživující tytéž děti (10434) podle nároku. Zmrazená
     * evidence mzdové revize ho nenese (je šifrované u nároku), snímek přípravy si
     * ho proto dohledá podle `id` nároku.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<int,string>> zaměstnanec → `id` nároku → rodné číslo číslicemi
     */
    public function caregiverBirthNumbersFor(int $supplierId, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($supplierId <= 0 || $ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT employee_id, id, other_caregiver_birth_number_ciphertext
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id IN ({$placeholders})
                AND other_household_caregiver_status = 'present'
                AND other_caregiver_birth_number_ciphertext IS NOT NULL
              ORDER BY employee_id, id"
        );
        $statement->execute([$supplierId, ...$ids]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $birthNumber = $this->birthNumber($supplierId, (int) $row['id'], $row['other_caregiver_birth_number_ciphertext']);
            if ($birthNumber !== null) {
                $result[(int) $row['employee_id']][(int) $row['id']] = $birthNumber;
            }
        }

        return $result;
    }

    /** Rodné číslo jen číslicemi (XSD `rodneCislo`), nebo null, když ho evidence nemá. */
    private function birthNumber(int $supplierId, int $entityId, mixed $ciphertext): ?string
    {
        if (!is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        return CzechBirthNumber::forSubmission($this->sensitiveData->reveal(
            $ciphertext,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
            $entityId,
            PayrollRevealPurpose::SUBMISSION_CSSZ_MONTHLY_REPORT,
        ));
    }
}
