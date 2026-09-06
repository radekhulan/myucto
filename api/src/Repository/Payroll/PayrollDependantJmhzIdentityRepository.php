<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Identita vyživovaného dítěte pro měsíční blok JMHZ `zvyhodneniDetiMesic`.
 *
 * Nárok (`payroll_person_tax_child_claims`) nese jen neosobní `child_reference`,
 * protože do snímku mzdového běhu osobní údaje nepatří. XSD (`osobaType`) ale
 * u dítěte vyžaduje jméno a příjmení, takže se do zmrazené PŘÍPRAVY JMHZ
 * dohledávají až tady — a jen ty dva údaje. Datum narození ani rodné číslo
 * měsíční blok nevyžaduje (`minOccurs=0`), takže se nezmrazují: rodné číslo je
 * navíc v `payroll_dependants` šifrované a do podání nemá důvod jít.
 */
final readonly class PayrollDependantJmhzIdentityRepository
{
    public function __construct(private Connection $db) {}

    /**
     * @param list<int> $employeeIds
     * @return array<int,array<string,array{given_name:string,family_name:string}>>
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
            "SELECT employee_id, id, given_name, family_name
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
            $result[(int) $row['employee_id']]['dependant-' . (int) $row['id']] = [
                'given_name' => $givenName,
                'family_name' => $familyName,
            ];
        }

        return $result;
    }
}
