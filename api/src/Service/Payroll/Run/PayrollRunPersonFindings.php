<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Které osoby běhu mají nález kontroly, pro příznak v přehledu osob.
 *
 * Vstupem jsou kontroly revize PO odečtení trvale skrytých varování
 * ({@see PayrollWarningSuppressionSet::filterRows()}), tedy přesně to, co
 * účetní vidí na kartě běhu. Informativní řádky a varování, u kterých už
 * někdo schválil výjimku, se nepočítají: není na nich co řešit.
 *
 * Kontrola míří na osobu buď přímo (`entity_type`), nebo seznamem dotčených
 * (`subject_ids` souhrnu „N osob…"). Vztah se převádí na osobu, která s ním
 * do revize vstoupila.
 */
final class PayrollRunPersonFindings
{
    private function __construct() {}

    /**
     * @param list<array<string,mixed>> $visibleValidations
     * @param array<int,int> $employmentOwners employment_id => employee_id
     * @return array<int,array{blockers:int,warnings:int}>
     */
    public static function byEmployee(array $visibleValidations, array $employmentOwners): array
    {
        $findings = [];
        foreach ($visibleValidations as $row) {
            $severity = (string) ($row['severity'] ?? '');
            if ($severity === 'blocker') {
                $key = 'blockers';
            } elseif ($severity === 'warning' && ($row['overridden_at'] ?? null) === null) {
                $key = 'warnings';
            } else {
                continue;
            }
            foreach (self::employees($row, $employmentOwners) as $employeeId) {
                $findings[$employeeId] ??= ['blockers' => 0, 'warnings' => 0];
                $findings[$employeeId][$key]++;
            }
        }

        return $findings;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,int> $employmentOwners
     * @return list<int>
     */
    private static function employees(array $row, array $employmentOwners): array
    {
        $subjectType = $row['subject_type'] ?? null;
        $subjectIds = is_array($row['subject_ids'] ?? null) ? $row['subject_ids'] : [];
        if (in_array($subjectType, ['employee', 'employment'], true) && $subjectIds !== []) {
            $type = $subjectType;
            $ids = array_map('intval', $subjectIds);
        } else {
            $type = $row['entity_type'] ?? null;
            $ids = ($row['entity_id'] ?? null) === null ? [] : [(int) $row['entity_id']];
        }
        $employees = [];
        foreach ($ids as $id) {
            if ($type === 'employee') {
                $employees[$id] = true;
            } elseif ($type === 'employment' && isset($employmentOwners[$id])) {
                $employees[$employmentOwners[$id]] = true;
            }
        }

        return array_keys($employees);
    }
}
