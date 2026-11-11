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
 * Osoby, které import nenašel mezi vztahy v období a nabídne je založit.
 *
 * Párování na vztahy v období ({@see AttendancePersonMatcher}) zná jen vztahy,
 * které v měsíci platí. Brigádník, jehož dohoda minulý měsíc skončila, by tak
 * dostal novou kartu osoby a s ní duplicitu (u rodného čísla i chybu). Tady se
 * osoba hledá v celé evidenci podle uložené vazby, rodného čísla a osobního
 * čísla (`existing_person`); založení jí pak přidá jen nový vztah. Kdo má
 * otevřenou DPP, dostane i `open_agreement` ({@see PayrollOpenAgreements}),
 * u otevřené dohody se osoba hledá i podle jména.
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
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, string $periodStart, array $persons): array
    {
        $wanted = [];
        foreach ($persons as $index => $person) {
            $persons[$index]['open_agreement'] = null;
            $persons[$index]['existing_person'] = null;
            if (($person['match']['status'] ?? null) === 'not_found') {
                $wanted[] = $index;
            }
        }
        if ($wanted === []) {
            return $persons;
        }

        $existing = $this->existingEmployees($supplierId, $persons, $wanted);
        $summaries = $this->imports->employeeSummaries($supplierId, array_values(array_map(
            static fn (array $found): int => $found['employee_id'],
            $existing,
        )));
        $candidates = $this->openAgreements->candidates($supplierId);
        $byEmployment = [];
        $byEmployee = [];
        foreach ($candidates as $candidate) {
            $byEmployment[$candidate['employment_id']] = $candidate;
            $byEmployee[$candidate['employee_id']][] = $candidate;
        }
        $names = [];
        foreach ($this->imports->personNames($supplierId, array_keys($byEmployee)) as $name) {
            $key = AttendanceText::personKey($name['name']);
            if ($key !== '') {
                $names[$key][$name['employee_id']] = true;
            }
        }

        foreach ($wanted as $index) {
            $person = $persons[$index];
            $employeeId = $existing[$index]['employee_id'] ?? null;
            if ($employeeId !== null && isset($summaries[$employeeId])) {
                $person['existing_person'] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $summaries[$employeeId]['employee_name'],
                    'matched_by' => $existing[$index]['matched_by'],
                    // Nová dohoda bez druhu v podkladech je stejného druhu jako poslední vztah osoby.
                    'relation_type' => $summaries[$employeeId]['relation_type'] ?? 'dpp',
                ];
            }
            if (self::mayBeDpp($person)) {
                $holder = $employeeId;
                if ($holder === null) {
                    $byName = array_keys($names[(string) ($person['_name_key'] ?? '')] ?? []);
                    $holder = count($byName) === 1 ? $byName[0] : null;
                }
                $person = $this->withOpenAgreement($person, $holder === null ? [] : ($byEmployee[$holder] ?? []), $periodStart);
            }
            if ($person['existing_person'] !== null && !($person['open_agreement']['reusable'] ?? false)) {
                $person['warnings'][] = "Osoba {$person['existing_person']['employee_name']} je v evidenci, ale v období nemá platný "
                    . 'pracovní vztah. Založení jí přidá novou dohodu, nová karta osoby nevznikne.';
            }
            $persons[$index] = $person;
        }

        return $persons;
    }

    /**
     * @param array<string,mixed> $person
     * @param list<array<string,mixed>> $agreements neukončené DPP osoby
     * @return array<string,mixed>
     */
    private function withOpenAgreement(array $person, array $agreements, string $periodStart): array
    {
        $start = is_string($person['start_on'] ?? null) ? $person['start_on'] : $periodStart;
        $end = is_string($person['agreed_end_on'] ?? null) ? $person['agreed_end_on'] : null;
        $open = array_values(array_filter(
            $agreements,
            static fn (array $agreement): bool => PayrollOpenAgreements::isOpenFor($agreement, $start),
        ));
        if ($open === []) {
            return $person;
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

        return $person;
    }

    /**
     * Osoba z evidence podle uložené vazby, rodného čísla a osobního čísla
     * (v tomto pořadí). Víc kandidátů = žádná: za uživatele se nevybírá.
     *
     * @param list<array<string,mixed>> $persons
     * @param list<int> $indexes
     * @return array<int,array{employee_id:int,matched_by:string}>
     */
    private function existingEmployees(int $supplierId, array $persons, array $indexes): array
    {
        $links = $this->links->all($supplierId, AttendanceMeaning::SOURCE_SYSTEM);
        $hashes = [];
        $codes = [];
        foreach ($indexes as $index) {
            $birthNumber = $persons[$index]['_birth_number'] ?? null;
            if (is_string($birthNumber) && trim($birthNumber) !== '') {
                try {
                    $hashes[$index] = $this->sensitive->lookupHash(
                        CzechBirthNumber::normalize($birthNumber),
                        PayrollSensitiveField::PERSONAL_IDENTIFIER,
                        $supplierId,
                    );
                } catch (\InvalidArgumentException) {
                    // Neplatné rodné číslo hlásí párování osob; tady se jen nepoužije.
                }
            }
            $number = is_string($persons[$index]['personal_number'] ?? null) ? trim($persons[$index]['personal_number']) : '';
            if ($number !== '') {
                $codes[$index] = $number;
            }
        }
        $found = $this->imports->employeesByIdentifiers($supplierId, array_values($hashes), array_values($codes));

        $result = [];
        foreach ($indexes as $index) {
            $person = $persons[$index];
            $candidates = [];
            foreach ([
                [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, (string) ($codes[$index] ?? '')],
                [PayrollImportLinkRepository::KIND_NAME, (string) ($person['_name_key'] ?? '')],
            ] as [$kind, $key]) {
                $link = $key === '' ? null : ($links[PayrollImportLinkRepository::key($kind, $key)] ?? null);
                if ($link !== null && $candidates === []) {
                    $candidates = ['link' => [$link['employee_id']]];
                }
            }
            if ($candidates === [] && isset($hashes[$index], $found['by_hash'][$hashes[$index]])) {
                $candidates = ['birth_number' => $found['by_hash'][$hashes[$index]]];
            }
            $code = isset($codes[$index]) ? mb_strtolower($codes[$index], 'UTF-8') : null;
            if ($candidates === [] && $code !== null && isset($found['by_code'][$code])) {
                $candidates = ['employment_code' => $found['by_code'][$code]];
            }
            foreach ($candidates as $matchedBy => $employeeIds) {
                $employeeIds = array_values(array_unique($employeeIds));
                if (count($employeeIds) === 1) {
                    $result[$index] = ['employee_id' => (int) $employeeIds[0], 'matched_by' => (string) $matchedBy];
                }
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $person */
    private static function mayBeDpp(array $person): bool
    {
        $label = AttendanceText::normalize((string) ($person['relation_label'] ?? ''));

        return $label === '' || str_contains($label, 'dpp') || str_contains($label, 'provedeni');
    }
}
