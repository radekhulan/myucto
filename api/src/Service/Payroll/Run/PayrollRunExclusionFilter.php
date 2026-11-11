<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Vyřadí ze snímku běhu osoby, které účetní z běhu vědomě vyřadila.
 *
 * Zákonný výpočet ukládá výsledky jen za úplnou množinu osob snímku, takže
 * jedna osoba v ruční kontrole zastavila celý běh. Vyřazená osoba do snímku
 * nevstoupí vůbec: nemá vstupy, výsledek, pásku ani zámek vstupů, a její
 * schválené vstupy zůstanou schválené pro opravnou revizi téhož období.
 *
 * Snímek staví {@see PayrollRunSnapshotBuilder} beze změny; tady se jen
 * odeberou osoby a nálezy, které se jich týkají, a znovu spočítá otisk.
 */
final class PayrollRunExclusionFilter
{
    /**
     * @param list<int> $excludedEmployeeIds
     */
    public static function apply(PayrollRunInputSnapshot $snapshot, array $excludedEmployeeIds): PayrollRunInputSnapshot
    {
        if ($excludedEmployeeIds === []) {
            return $snapshot;
        }
        $excluded = array_fill_keys($excludedEmployeeIds, true);
        $data = $snapshot->data;
        $people = [];
        $excludedEmployments = [];
        foreach (is_array($data['people'] ?? null) ? $data['people'] : [] as $person) {
            $employeeId = is_array($person) && is_array($person['employee'] ?? null)
                ? (int) ($person['employee']['id'] ?? 0)
                : 0;
            if (!isset($excluded[$employeeId])) {
                $people[] = $person;
                continue;
            }
            foreach (is_array($person['employments'] ?? null) ? $person['employments'] : [] as $employment) {
                if (is_array($employment) && is_array($employment['employment'] ?? null)) {
                    $excludedEmployments[(int) ($employment['employment']['id'] ?? 0)] = true;
                }
            }
        }
        if (count($people) === count(is_array($data['people'] ?? null) ? $data['people'] : [])) {
            return $snapshot;
        }
        $data['people'] = $people;
        $json = CanonicalJson::encode($data);

        $validations = [];
        foreach ($snapshot->validations as $validation) {
            if (($validation->entityType === 'employee' && isset($excluded[(int) $validation->entityId]))
                || ($validation->entityType === 'employment' && isset($excludedEmployments[(int) $validation->entityId]))
            ) {
                continue;
            }
            $validations[] = $validation;
        }

        return new PayrollRunInputSnapshot(
            $data,
            $json,
            hash('sha256', $json),
            $snapshot->rulesetManifestHash,
            $validations,
        );
    }

    /**
     * @param array<string,mixed> $snapshotData
     * @return list<int>
     */
    public static function employeeIds(array $snapshotData): array
    {
        $ids = [];
        foreach (is_array($snapshotData['people'] ?? null) ? $snapshotData['people'] : [] as $person) {
            if (is_array($person) && is_array($person['employee'] ?? null)) {
                $ids[] = (int) ($person['employee']['id'] ?? 0);
            }
        }

        return $ids;
    }
}
