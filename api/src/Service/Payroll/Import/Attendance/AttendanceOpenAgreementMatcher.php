<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollImportLinkRepository;
use MyInvoice\Service\Payroll\Employment\PayrollOpenAgreements;

/**
 * Osoby, které import nenašel mezi vztahy v období a nabídne je založit.
 *
 * Párování na vztahy v období ({@see AttendancePersonMatcher}) zná jen vztahy,
 * které v měsíci platí. Brigádník, jehož dohoda minulý měsíc skončila, by tak
 * dostal novou kartu osoby a s ní duplicitu (u rodného čísla i chybu). Tady se
 * osoba hledá v celé evidenci podle uložené vazby, rodného čísla a osobního
 * čísla (`existing_person`); založení jí pak přidá jen nový vztah. Kdo má
 * otevřenou DPP, dostane i `open_agreement` ({@see PayrollOpenAgreements}),
 * u otevřené dohody se osoba hledá i podle jména. Rodné číslo z podkladů má
 * přednost a shoda podle vazby, osobního čísla nebo jména mu nesmí odporovat
 * ({@see AttendancePersonMatcher::birthNumberDisagreement()}); rozpor
 * s osobním číslem je `conflict`. Podklady bez rodného čísla dostanou shodu
 * jména a data narození jen jako návrh k potvrzení (`requires_confirmation`).
 */
final class AttendanceOpenAgreementMatcher
{
    public function __construct(
        private readonly PayrollOpenAgreements $openAgreements,
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollImportLinkRepository $links,
    ) {
    }

    /**
     * Bez `$withOpenAgreements` jen `existing_person` (import dohod): otevřené
     * DPP ani upozornění k období se nehledají.
     *
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, string $periodStart, array $persons, bool $withOpenAgreements = true): array
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
        foreach ($existing as $index => $found) {
            if (isset($found['conflict'])) {
                $persons[$index]['match'] = ['conflict' => $found['conflict'], 'status' => 'conflict'] + $persons[$index]['match'];
                $persons[$index]['errors'] = [...($persons[$index]['errors'] ?? []), $found['conflict']];
                unset($existing[$index]);
                $wanted = array_values(array_diff($wanted, [$index]));
            }
        }
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
        $holderFacts = $this->imports->identityFacts($supplierId, array_keys($byEmployee));

        foreach ($wanted as $index) {
            $person = $persons[$index];
            $employeeId = $existing[$index]['employee_id'] ?? null;
            if (!$withOpenAgreements) {
                if ($employeeId !== null && isset($summaries[$employeeId])) {
                    $person['existing_person'] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $summaries[$employeeId]['employee_name'],
                        'matched_by' => $existing[$index]['matched_by'],
                        'relation_type' => $summaries[$employeeId]['relation_type'] ?? 'dpp',
                        'requires_confirmation' => $existing[$index]['matched_by'] === 'name_birth_date',
                    ];
                }
                $persons[$index] = $person;
                continue;
            }
            if ($employeeId !== null && isset($summaries[$employeeId])) {
                $confirm = $existing[$index]['matched_by'] === 'name_birth_date';
                $person['existing_person'] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $summaries[$employeeId]['employee_name'],
                    'matched_by' => $existing[$index]['matched_by'],
                    // Nová dohoda bez druhu v podkladech je stejného druhu jako poslední vztah osoby.
                    'relation_type' => $summaries[$employeeId]['relation_type'] ?? 'dpp',
                    'requires_confirmation' => $confirm,
                ];
            }
            if (self::mayBeDpp($person) && !($person['existing_person']['requires_confirmation'] ?? false)) {
                $holder = $employeeId;
                if ($holder === null) {
                    $byName = array_keys($names[(string) ($person['_name_key'] ?? '')] ?? []);
                    $holder = count($byName) === 1
                        && AttendancePersonMatcher::birthNumberDisagreement($person, (int) $byName[0], $holderFacts) === null
                        ? $byName[0]
                        : null;
                }
                $person = $this->withOpenAgreement($person, $holder === null ? [] : ($byEmployee[$holder] ?? []), $periodStart);
            }
            if ($person['existing_person']['requires_confirmation'] ?? false) {
                $person['warnings'][] = "V evidenci je osoba {$person['existing_person']['employee_name']} se stejným jménem a datem narození. "
                    . 'Potvrďte v kroku Osoby, že jde o ni: založení jí pak přidá novou dohodu a nová karta osoby nevznikne. '
                    . 'Bez potvrzení ji import nezaloží.';
            } elseif ($person['existing_person'] !== null && !($person['open_agreement']['reusable'] ?? false)) {
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
     * Osoba z evidence podle rodného čísla, uložené vazby, osobního čísla
     * (v tomto pořadí) a u podkladů bez rodného čísla podle jména a data
     * narození. Víc kandidátů = žádná: za uživatele se nevybírá. Osobní číslo,
     * které vede na osobu s jiným rodným číslem, je rozpor (`conflict`).
     *
     * @param list<array<string,mixed>> $persons
     * @param list<int> $indexes
     * @return array<int,array{employee_id:int,matched_by:string}|array{conflict:string}>
     */
    private function existingEmployees(int $supplierId, array $persons, array $indexes): array
    {
        $links = $this->links->all($supplierId, AttendanceMeaning::SOURCE_SYSTEM);
        $codes = [];
        $birthDates = [];
        foreach ($indexes as $index) {
            $person = $persons[$index];
            $number = is_string($person['personal_number'] ?? null) ? trim($person['personal_number']) : '';
            if ($number !== '' && !($person['_number_ambiguous'] ?? false)) {
                $codes[$index] = $number;
            }
            if (self::withoutBirthNumber($person) && is_string($person['_birth_date'] ?? null)) {
                $birthDates[$index] = $person['_birth_date'];
            }
        }
        $found = $this->imports->employeesByIdentifiers($supplierId, [], array_values($codes));
        $byBirthDate = $this->imports->employeesByBirthDates($supplierId, array_values($birthDates));
        $namesByEmployee = [];
        foreach ($this->imports->personNames($supplierId, array_merge([], ...array_values($byBirthDate))) as $name) {
            $namesByEmployee[$name['employee_id']][AttendanceText::personKey($name['name'])] = true;
        }
        $targets = [];
        foreach ($indexes as $index) {
            foreach ([
                [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, (string) ($codes[$index] ?? '')],
                [PayrollImportLinkRepository::KIND_NAME, (string) ($persons[$index]['_name_key'] ?? '')],
            ] as [$kind, $key]) {
                $link = $key === '' ? null : ($links[PayrollImportLinkRepository::key($kind, $key)] ?? null);
                if ($link !== null) {
                    $targets[] = (int) $link['employee_id'];
                }
            }
            if (isset($codes[$index])) {
                array_push($targets, ...($found['by_code'][mb_strtolower($codes[$index], 'UTF-8')] ?? []));
            }
        }
        $facts = $this->imports->identityFacts($supplierId, $targets);

        $result = [];
        foreach ($indexes as $index) {
            $person = $persons[$index];
            $birthEmployees = is_string($person['_birth_hash'] ?? null) ? ($person['_birth_employees'] ?? []) : [];
            if ($birthEmployees !== []) {
                if (count($birthEmployees) === 1) {
                    $result[$index] = ['employee_id' => (int) $birthEmployees[0], 'matched_by' => 'birth_number'];
                }
                continue;
            }
            $candidates = [];
            $number = (string) ($codes[$index] ?? '');
            foreach ([
                [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, $number],
                [PayrollImportLinkRepository::KIND_NAME, (string) ($person['_name_key'] ?? '')],
            ] as [$kind, $key]) {
                $link = $key === '' ? null : ($links[PayrollImportLinkRepository::key($kind, $key)] ?? null);
                if ($link === null || $candidates !== []) {
                    continue;
                }
                $disagreement = AttendancePersonMatcher::birthNumberDisagreement($person, (int) $link['employee_id'], $facts);
                if ($disagreement === null) {
                    $candidates = ['link' => [(int) $link['employee_id']]];
                } elseif ($kind === PayrollImportLinkRepository::KIND_PERSONAL_NUMBER) {
                    $result[$index] = ['conflict' => self::conflictMessage($number, $disagreement)];
                    continue 2;
                }
            }
            if ($candidates === [] && $number !== '' && isset($found['by_code'][mb_strtolower($number, 'UTF-8')])) {
                $employeeIds = array_values(array_unique($found['by_code'][mb_strtolower($number, 'UTF-8')]));
                foreach ($employeeIds as $employeeId) {
                    $disagreement = AttendancePersonMatcher::birthNumberDisagreement($person, (int) $employeeId, $facts);
                    if ($disagreement !== null) {
                        $result[$index] = ['conflict' => self::conflictMessage($number, $disagreement)];
                        continue 2;
                    }
                }
                $candidates = ['employment_code' => $employeeIds];
            }
            if ($candidates === [] && isset($birthDates[$index])) {
                $nameKey = (string) ($person['_name_key'] ?? '');
                $candidates = ['name_birth_date' => array_values(array_filter(
                    $byBirthDate[$birthDates[$index]] ?? [],
                    static fn (int $employeeId): bool => $nameKey !== '' && isset($namesByEmployee[$employeeId][$nameKey]),
                ))];
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
    private static function withoutBirthNumber(array $person): bool
    {
        $birthNumber = $person['_birth_number'] ?? null;

        return !is_string($birthNumber) || trim($birthNumber) === '';
    }

    private static function conflictMessage(string $number, string $disagreement): string
    {
        return "Rozpor v podkladech: osobní číslo „{$number}“ vede v evidenci na osobu, která {$disagreement}. "
            . 'Řádek se nepároval a osoba se nezaloží; opravte osobní nebo rodné číslo v podkladech.';
    }

    /** @param array<string,mixed> $person */
    private static function mayBeDpp(array $person): bool
    {
        $label = AttendanceText::normalize((string) ($person['relation_label'] ?? ''));

        return $label === '' || str_contains($label, 'dpp') || str_contains($label, 'provedeni');
    }
}
