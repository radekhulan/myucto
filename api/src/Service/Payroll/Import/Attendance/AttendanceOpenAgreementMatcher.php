<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollImportLinkRepository;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Employment\PayrollOpenAgreements;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/**
 * Otevřená DPP u osob, které import nenašel v období a nabídne je založit.
 *
 * Párování na vztahy v období ({@see AttendancePersonMatcher}) zná jen vztahy,
 * které v měsíci platí. Brigádník s otevřenou dohodou, která v měsíci ještě
 * nezačala, nebo zapsaný jinak než v evidenci, by tak dostal další DPP. Tady se
 * osoba hledá mezi neukončenými DPP firmy stejnými klíči (uložená vazba, osobní
 * číslo, rodné číslo, jméno) a náhled nabídne použít otevřenou dohodu
 * ({@see PayrollOpenAgreements}).
 */
final class AttendanceOpenAgreementMatcher
{
    public function __construct(
        private readonly PayrollOpenAgreements $openAgreements,
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollImportLinkRepository $links,
        private readonly PayrollSensitiveData $sensitive,
    ) {
    }

    /**
     * Doplní osobám bez vztahu v období `open_agreement` (popis dohody, viz
     * {@see PayrollOpenAgreements::describe()}) a varování. Jen u osob, které by
     * import zakládal jako DPP nebo u nichž druh vztahu podklady neuvádějí.
     *
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, string $periodStart, array $persons): array
    {
        $wanted = [];
        foreach ($persons as $index => $person) {
            $person['open_agreement'] = null;
            $persons[$index] = $person;
            if (($person['match']['status'] ?? null) === 'not_found' && self::mayBeDpp($person)) {
                $wanted[] = $index;
            }
        }
        if ($wanted === []) {
            return $persons;
        }
        $candidates = $this->openAgreements->candidates($supplierId);
        if ($candidates === []) {
            return $persons;
        }
        $byEmployment = [];
        $byEmployee = [];
        foreach ($candidates as $candidate) {
            $byEmployment[$candidate['employment_id']] = $candidate;
            $byEmployee[$candidate['employee_id']][] = $candidate;
        }
        $employeeIds = array_keys($byEmployee);
        $names = [];
        foreach ($this->imports->personNames($supplierId, $employeeIds) as $name) {
            $key = AttendanceText::personKey($name['name']);
            if ($key !== '') {
                $names[$key][$name['employee_id']] = true;
            }
        }
        $hashes = $this->imports->birthNumberHashes($supplierId, $employeeIds);
        $links = $this->links->all($supplierId, AttendanceMeaning::SOURCE_SYSTEM);

        foreach ($wanted as $index) {
            $person = $persons[$index];
            $employees = $this->identify($supplierId, $person, $byEmployment, $byEmployee, $names, $hashes, $links);
            if (count($employees) !== 1) {
                continue;
            }
            $start = is_string($person['start_on'] ?? null) ? $person['start_on'] : $periodStart;
            $end = is_string($person['agreed_end_on'] ?? null) ? $person['agreed_end_on'] : null;
            $open = array_values(array_filter(
                $byEmployee[$employees[0]],
                static fn (array $agreement): bool => PayrollOpenAgreements::isOpenFor($agreement, $start),
            ));
            if ($open === []) {
                continue;
            }
            usort($open, static fn (array $a, array $b): int => PayrollOpenAgreements::reusableFor($b, $start)
                <=> PayrollOpenAgreements::reusableFor($a, $start));
            $described = PayrollOpenAgreements::describe($open[0], $start, $end);
            $person['open_agreement'] = $described;
            $person['warnings'][] = $described['reusable']
                ? "Osoba {$described['employee_name']} má otevřenou DPP {$described['code']}. Import ji použije místo nové dohody"
                    . ($described['extend_to'] === null ? '' : ' a prodlouží jí konec do ' . PayrollOpenAgreements::czechDate($described['extend_to']))
                    . '. ' . PayrollOpenAgreements::GUIDANCE
                : "Osoba {$described['employee_name']} má otevřenou DPP {$described['code']}, která začíná až "
                    . PayrollOpenAgreements::czechDate((string) $described['start_date'])
                    . '. Jde-li o tutéž dohodu, posuňte její nástup na kartě vztahu a novou nezakládejte.';
            $persons[$index] = $person;
        }

        return $persons;
    }

    /** @param array<string,mixed> $person */
    private static function mayBeDpp(array $person): bool
    {
        $label = AttendanceText::normalize((string) ($person['relation_label'] ?? ''));

        return $label === '' || str_contains($label, 'dpp') || str_contains($label, 'provedeni');
    }

    /**
     * @param array<string,mixed> $person
     * @param array<int,array<string,mixed>> $byEmployment
     * @param array<int,list<array<string,mixed>>> $byEmployee
     * @param array<string,array<int,true>> $names
     * @param array<string,list<int>> $hashes
     * @param array<string,array{employee_id:int,employment_id:int}> $links
     * @return list<int>
     */
    private function identify(
        int $supplierId,
        array $person,
        array $byEmployment,
        array $byEmployee,
        array $names,
        array $hashes,
        array $links,
    ): array {
        $personalNumber = is_string($person['personal_number'] ?? null) ? trim($person['personal_number']) : '';
        $nameKey = (string) ($person['_name_key'] ?? '');
        foreach ([
            [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, $personalNumber],
            [PayrollImportLinkRepository::KIND_NAME, $nameKey],
        ] as [$kind, $key]) {
            $link = $key === '' ? null : ($links[PayrollImportLinkRepository::key($kind, $key)] ?? null);
            if ($link !== null && isset($byEmployment[$link['employment_id']])) {
                return [$byEmployment[$link['employment_id']]['employee_id']];
            }
        }
        $birthNumber = $person['_birth_number'] ?? null;
        if (is_string($birthNumber) && trim($birthNumber) !== '') {
            try {
                $hash = $this->sensitive->lookupHash(
                    CzechBirthNumber::normalize($birthNumber),
                    PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    $supplierId,
                );
                if (isset($hashes[$hash])) {
                    return array_values(array_unique($hashes[$hash]));
                }
            } catch (\InvalidArgumentException) {
                // Neplatné rodné číslo hlásí párování osob; tady se jen nepoužije.
            }
        }
        if ($personalNumber !== '') {
            $byCode = [];
            foreach ($byEmployee as $employeeId => $agreements) {
                foreach ($agreements as $agreement) {
                    if (mb_strtolower($agreement['code'], 'UTF-8') === mb_strtolower($personalNumber, 'UTF-8')) {
                        $byCode[$employeeId] = true;
                    }
                }
            }
            if ($byCode !== []) {
                return array_keys($byCode);
            }
        }

        return $nameKey === '' ? [] : array_keys($names[$nameKey] ?? []);
    }
}
