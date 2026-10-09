<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollImportLinkRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Páruje osoby z podkladů na pracovní vztahy platné v období.
 *
 * Pořadí: 1) uložená vazba (osobní číslo, pak jméno), 2) rodné číslo přes
 * blind-index, 3) kód vztahu = osobní číslo, 4) normalizované jméno.
 * Víc kandidátů = `ambiguous`; nic se nevybírá za uživatele.
 */
final class AttendancePersonMatcher
{
    private const RELATION_LABELS = [
        'employment' => 'pracovní poměr',
        'small_scale_employment' => 'zaměstnání malého rozsahu',
        'dpp' => 'DPP',
        'dpc' => 'DPČ',
        'partner_dependent' => 'příjem společníka',
        'statutory_body' => 'statutární orgán',
    ];

    public function __construct(
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollImportLinkRepository $links,
        private readonly PayrollSensitiveData $sensitive,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $persons
     * @return array{persons:list<array<string,mixed>>,options:list<array{employment_id:int,employee_id:int,label:string,code:string,status:string}>}
     */
    public function match(int $supplierId, string $periodStart, string $periodEnd, array $persons): array
    {
        $employments = $this->imports->employmentsInPeriod($supplierId, $periodStart, $periodEnd);
        $byEmployment = [];
        $byEmployee = [];
        foreach ($employments as $employment) {
            $byEmployment[$employment['employment_id']] = $employment;
            $byEmployee[$employment['employee_id']][] = $employment;
        }
        $nameIndex = [];
        foreach ($this->imports->personNames($supplierId, array_keys($byEmployee)) as $name) {
            $key = AttendanceText::personKey($name['name']);
            if ($key !== '') {
                $nameIndex[$key][$name['employee_id']] = true;
            }
        }
        $links = $this->links->all($supplierId, AttendanceMeaning::SOURCE_SYSTEM);
        $period = substr($periodStart, 0, 7);

        foreach ($persons as &$person) {
            $warnings = [];
            $person['match'] = $this->matchOne(
                $supplierId,
                $person,
                $byEmployment,
                $byEmployee,
                $nameIndex,
                $links,
                $period,
                $warnings,
            );
            $person['warnings'] = array_values(array_unique([...$person['warnings'], ...$warnings]));
        }
        unset($person);

        return [
            'persons' => $persons,
            'options' => array_map(fn (array $employment): array => [
                'employment_id' => $employment['employment_id'],
                'employee_id' => $employment['employee_id'],
                'label' => $this->label($employment),
                'code' => $employment['code'],
                'status' => $employment['status'],
            ], $employments),
        ];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<int,array<string,mixed>> $byEmployment
     * @param array<int,list<array<string,mixed>>> $byEmployee
     * @param array<string,array<int,true>> $nameIndex
     * @param array<string,array{employee_id:int,employment_id:int}> $links
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function matchOne(
        int $supplierId,
        array $person,
        array $byEmployment,
        array $byEmployee,
        array $nameIndex,
        array $links,
        string $period,
        array &$warnings,
    ): array {
        $personalNumber = is_string($person['personal_number'] ?? null) ? trim($person['personal_number']) : '';
        $nameKey = (string) ($person['_name_key'] ?? '');
        $relationTypes = self::relationTypes((string) ($person['relation_label'] ?? ''));

        foreach ([
            [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, $personalNumber],
            [PayrollImportLinkRepository::KIND_NAME, $nameKey],
        ] as [$kind, $key]) {
            if ($key === '') {
                continue;
            }
            $link = $links[PayrollImportLinkRepository::key($kind, $key)] ?? null;
            if ($link === null) {
                continue;
            }
            if (isset($byEmployment[$link['employment_id']])) {
                return $this->result('linked', 'link', [$byEmployment[$link['employment_id']]]);
            }
            $warnings[] = "Uložená vazba osoby míří na pracovní vztah, který v období {$period} neplatí; nepoužila se.";
        }

        $birthNumber = $person['_birth_number'] ?? null;
        if (is_string($birthNumber) && trim($birthNumber) !== '') {
            try {
                $normalized = CzechBirthNumber::normalize($birthNumber);
                $employeeIds = $this->imports->employeeIdsByBirthNumberHash(
                    $supplierId,
                    $this->sensitive->lookupHash($normalized, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId),
                );
                $candidates = $this->employmentsOf($employeeIds, $byEmployee);
                if ($candidates !== []) {
                    return $this->resolved('birth_number', self::narrow($candidates, $relationTypes, $personalNumber));
                }
                if ($employeeIds !== []) {
                    $warnings[] = "Osoba s tímto rodným číslem je v evidenci, ale nemá pracovní vztah platný v období {$period}.";
                }
            } catch (\InvalidArgumentException) {
                $warnings[] = 'Rodné číslo osoby v podkladech není platné, k párování se nepoužilo.';
            }
        }

        if ($personalNumber !== '') {
            $candidates = array_values(array_filter(
                $byEmployment,
                static fn (array $employment): bool => mb_strtolower($employment['code'], 'UTF-8')
                    === mb_strtolower($personalNumber, 'UTF-8'),
            ));
            if ($candidates !== []) {
                return $this->resolved('employment_code', self::narrow($candidates, $relationTypes, $personalNumber));
            }
        }

        $candidates = $this->employmentsOf(array_keys($nameIndex[$nameKey] ?? []), $byEmployee);
        if ($candidates !== []) {
            return $this->resolved('name', self::narrow($candidates, $relationTypes, $personalNumber));
        }

        return $this->result('not_found', null, []);
    }

    /**
     * @param list<int> $employeeIds
     * @param array<int,list<array<string,mixed>>> $byEmployee
     * @return list<array<string,mixed>>
     */
    private function employmentsOf(array $employeeIds, array $byEmployee): array
    {
        $result = [];
        foreach (array_unique($employeeIds) as $employeeId) {
            foreach ($byEmployee[$employeeId] ?? [] as $employment) {
                $result[] = $employment;
            }
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function resolved(string $matchedBy, array $candidates): array
    {
        return count($candidates) === 1
            ? $this->result('matched', $matchedBy, $candidates)
            : $this->result('ambiguous', $matchedBy, $candidates);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function result(string $status, ?string $matchedBy, array $candidates): array
    {
        $single = count($candidates) === 1 && $status !== 'ambiguous' ? $candidates[0] : null;

        return [
            'status' => $status,
            'matched_by' => $matchedBy,
            'employment_id' => $single['employment_id'] ?? null,
            'employee_id' => $single['employee_id'] ?? null,
            'employee_name' => $single['employee_name'] ?? null,
            'employment_code' => $single['code'] ?? null,
            'candidates' => $status === 'ambiguous'
                ? array_map(fn (array $employment): array => [
                    'employment_id' => $employment['employment_id'],
                    'employee_id' => $employment['employee_id'],
                    'label' => $this->label($employment),
                ], $candidates)
                : [],
        ];
    }

    /**
     * Souběh vztahů téže osoby: druh poměru z podkladů smí mezi kandidáty
     * rozhodnout, jen když zbude právě jeden.
     *
     * @param list<array<string,mixed>> $candidates
     * @param list<string> $relationTypes
     * @return list<array<string,mixed>>
     */
    private static function narrow(array $candidates, array $relationTypes, string $personalNumber = ''): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }
        // Osobní číslo z podkladů, které je přesně kódem jednoho z vztahů osoby, rozhodne
        // dřív než druh poměru (souběh dvou pracovních poměrů téže osoby).
        if ($personalNumber !== '') {
            $byCode = array_values(array_filter(
                $candidates,
                static fn (array $employment): bool => mb_strtolower($employment['code'], 'UTF-8') === mb_strtolower($personalNumber, 'UTF-8'),
            ));
            if (count($byCode) === 1) {
                return $byCode;
            }
        }
        if ($relationTypes === []) {
            return $candidates;
        }
        $narrowed = array_values(array_filter(
            $candidates,
            static fn (array $employment): bool => in_array($employment['relation_type'], $relationTypes, true),
        ));

        return count($narrowed) === 1 ? $narrowed : $candidates;
    }

    /** @return list<string> */
    private static function relationTypes(string $label): array
    {
        $label = AttendanceText::normalize($label);
        if ($label === '') {
            return [];
        }

        return match (true) {
            str_contains($label, 'dpp') || str_contains($label, 'provedeni') => ['dpp'],
            str_contains($label, 'dpc') || str_contains($label, 'pracovni cinnost') => ['dpc'],
            str_contains($label, 'jednatel') || str_contains($label, 'statutar') => ['statutory_body'],
            str_contains($label, 'maleho rozsahu') => ['small_scale_employment'],
            str_contains($label, 'pomer') || str_contains($label, 'hpp') || str_contains($label, 'smlouv') =>
                ['employment', 'small_scale_employment'],
            default => [],
        };
    }

    /** @param array<string,mixed> $employment */
    private function label(array $employment): string
    {
        return implode(' · ', array_filter([
            (string) $employment['employee_name'],
            (string) $employment['code'],
            self::RELATION_LABELS[$employment['relation_type']] ?? (string) $employment['relation_type'],
        ], static fn (string $part): bool => $part !== ''));
    }
}
