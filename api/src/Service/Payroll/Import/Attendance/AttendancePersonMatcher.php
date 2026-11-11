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
 * Pořadí: 1) rodné číslo přes blind-index (rozhoduje vždy, když ho evidence
 * zná), 2) uložená vazba (osobní číslo, pak jméno), 3) kód vztahu = osobní
 * číslo, 4) normalizované jméno. Osobní číslo, které ve firmě nebo
 * v podkladech nese víc osob, se k párování nepoužije. Shoda podle vazby,
 * kódu nebo jména nesmí odporovat rodnému číslu z podkladů: osoba v evidenci
 * s jiným rodným číslem nebo jiným datem narození se nepáruje a rozpor
 * s osobním číslem je `conflict`. Víc kandidátů = `ambiguous`; nic se
 * nevybírá za uživatele.
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
     * @return array{persons:list<array<string,mixed>>,options:list<array{employment_id:int,employee_id:int,label:string,code:string,status:string,relation_type:string}>}
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
        $persons = $this->withIdentityKeys($supplierId, $persons);
        $facts = $this->imports->identityFacts($supplierId, array_keys($byEmployee));

        foreach ($persons as &$person) {
            $warnings = [];
            $invalidBirthNumber = (bool) ($person['_birth_number_invalid'] ?? false);
            if ($person['_number_ambiguous'] ?? false) {
                $warnings[] = self::ambiguousNumberWarning((string) $person['personal_number']);
            }
            $person['match'] = $this->matchOne(
                $person,
                $byEmployment,
                $byEmployee,
                $nameIndex,
                $links,
                $facts,
                $period,
                $warnings,
            );
            // Neplatné RČ zastaví založení osoby (kontrola modulo 11). U osoby,
            // kterou import nenašel a musel by ji založit, je to chyba řádku,
            // ne jen upozornění: účetní ji má vidět v náhledu, ne až ve výsledku.
            $person['errors'] = [];
            $birthError = $person['_birth_number_error'] ?? null;
            if ($person['match']['status'] === 'conflict') {
                $person['errors'][] = (string) $person['match']['conflict'];
            } elseif (is_string($birthError) && $person['match']['status'] === 'not_found') {
                $person['errors'][] = $birthError;
            } elseif (is_string($birthError)) {
                $warnings[] = $birthError;
            } elseif ($invalidBirthNumber && $person['match']['status'] === 'not_found') {
                $person['errors'][] = 'Rodné číslo: v podkladech není platné (nesedí kontrolní číslice), '
                    . 'novou osobu s ním aplikace nezaloží. Opravte ho v podkladech a nahrajte je znovu, '
                    . 'nebo osobu přiřaďte ke vztahu v evidenci.';
            } elseif ($invalidBirthNumber) {
                $warnings[] = 'Rodné číslo osoby v podkladech není platné, k párování se nepoužilo.';
            }
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
                'relation_type' => $employment['relation_type'],
            ], $employments),
        ];
    }

    /**
     * Klíče pro párování spočítané jednou pro celé podklady: otisk rodného
     * čísla (HMAC, nic se nedešifruje), osoby celé evidence s tímto otiskem
     * a příznak osobního čísla, které nese víc osob ve firmě nebo v souboru.
     * Výsledek nesou interní pole osoby, ať ho použije i hledání v evidenci
     * ({@see AttendanceOpenAgreementMatcher}) a ukládání vazeb.
     *
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    public function withIdentityKeys(int $supplierId, array $persons): array
    {
        $hashes = [];
        $numberKeys = [];
        foreach ($persons as $index => $person) {
            $persons[$index]['_birth_hash'] = null;
            $persons[$index]['_birth_employees'] = [];
            $persons[$index]['_birth_date_from_number'] = null;
            $persons[$index]['_birth_number_invalid'] = false;
            $birthNumber = $person['_birth_number'] ?? null;
            if (is_string($birthNumber) && trim($birthNumber) !== '' && !is_string($person['_birth_number_error'] ?? null)) {
                try {
                    $normalized = CzechBirthNumber::normalize($birthNumber);
                    $hash = $this->sensitive->lookupHash($normalized, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId);
                    $persons[$index]['_birth_hash'] = $hash;
                    $persons[$index]['_birth_date_from_number'] = CzechBirthNumber::birthDate($normalized);
                    $hashes[] = $hash;
                } catch (\InvalidArgumentException) {
                    $persons[$index]['_birth_number_invalid'] = true;
                }
            }
            $number = self::numberKey($person['personal_number'] ?? null);
            if ($number !== '') {
                $numberKeys[$number][(string) ($person['key'] ?? $index)] = true;
            }
        }
        $found = $this->imports->employeesByIdentifiers($supplierId, $hashes, array_keys($numberKeys));
        foreach ($persons as $index => $person) {
            $hash = $persons[$index]['_birth_hash'];
            if (is_string($hash)) {
                $persons[$index]['_birth_employees'] = array_values(array_unique($found['by_hash'][$hash] ?? []));
            }
            $number = self::numberKey($person['personal_number'] ?? null);
            $persons[$index]['_number_ambiguous'] = $number !== '' && (
                count($numberKeys[$number] ?? []) > 1
                || count(array_unique($found['by_code'][$number] ?? [])) > 1
            );
        }

        return $persons;
    }

    public static function numberKey(mixed $personalNumber): string
    {
        return is_string($personalNumber) ? mb_strtolower(trim($personalNumber), 'UTF-8') : '';
    }

    public static function ambiguousNumberWarning(string $personalNumber): string
    {
        return "Osobní číslo „{$personalNumber}“ má ve firmě nebo v podkladech víc osob, k párování se nepoužilo "
            . 'a vazba podle něj se neuloží. Osobu párujte podle rodného čísla, nebo jí dejte jedinečné osobní číslo.';
    }

    /**
     * Osoba z evidence nesmí odporovat rodnému číslu z podkladů, které
     * evidence nezná: s jiným uloženým rodným číslem je to jiný člověk,
     * s jiným datem narození taky. Null = shoda je přípustná.
     *
     * @param array<string,mixed> $person
     * @param array<int,array{has_birth_number:bool,birth_date:?string}> $facts
     */
    public static function birthNumberDisagreement(array $person, int $employeeId, array $facts): ?string
    {
        if (!is_string($person['_birth_hash'] ?? null)) {
            return null;
        }
        if (in_array($employeeId, $person['_birth_employees'] ?? [], true)) {
            return null;
        }
        $fact = $facts[$employeeId] ?? null;
        if ($fact === null) {
            return null;
        }
        if ($fact['has_birth_number']) {
            return 'má v evidenci jiné rodné číslo, než je v podkladech';
        }
        $birthDate = $person['_birth_date_from_number'] ?? null;
        if ($fact['birth_date'] !== null && is_string($birthDate) && $fact['birth_date'] !== $birthDate) {
            return 'má v evidenci jiné datum narození, než udává rodné číslo z podkladů';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $person
     * @param array<int,array<string,mixed>> $byEmployment
     * @param array<int,list<array<string,mixed>>> $byEmployee
     * @param array<string,array<int,true>> $nameIndex
     * @param array<string,array{employee_id:int,employment_id:int}> $links
     * @param array<int,array{has_birth_number:bool,birth_date:?string}> $facts
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function matchOne(
        array $person,
        array $byEmployment,
        array $byEmployee,
        array $nameIndex,
        array $links,
        array $facts,
        string $period,
        array &$warnings,
    ): array {
        $personalNumber = is_string($person['personal_number'] ?? null) ? trim($person['personal_number']) : '';
        $numberUsable = $personalNumber !== '' && !($person['_number_ambiguous'] ?? false);
        $nameKey = (string) ($person['_name_key'] ?? '');
        $relationTypes = self::relationTypes((string) ($person['relation_label'] ?? ''));
        $numberLink = $numberUsable
            ? ($links[PayrollImportLinkRepository::key(PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, $personalNumber)] ?? null)
            : null;
        $byCode = $numberUsable
            ? array_values(array_filter(
                $byEmployment,
                static fn (array $employment): bool => mb_strtolower($employment['code'], 'UTF-8')
                    === mb_strtolower($personalNumber, 'UTF-8'),
            ))
            : [];

        $birthEmployees = is_string($person['_birth_hash'] ?? null) ? ($person['_birth_employees'] ?? []) : [];
        if ($birthEmployees !== []) {
            $numberTargets = array_values(array_unique(array_filter([
                $numberLink['employee_id'] ?? null,
                ...array_column($byCode, 'employee_id'),
            ], static fn (mixed $id): bool => is_int($id))));
            if ($numberTargets !== [] && array_intersect($numberTargets, $birthEmployees) === []) {
                return $this->conflict(
                    "Rozpor v podkladech: rodné číslo patří v evidenci jiné osobě než osobní číslo „{$personalNumber}“. "
                    . 'Řádek se nepároval; opravte osobní nebo rodné číslo v podkladech, nebo vyberte vztah ručně.',
                );
            }
            $candidates = $this->employmentsOf($birthEmployees, $byEmployee);
            if ($candidates !== []) {
                return $this->resolved('birth_number', self::narrow($candidates, $relationTypes, $numberUsable ? $personalNumber : ''));
            }
            $warnings[] = "Osoba s tímto rodným číslem je v evidenci, ale nemá pracovní vztah platný v období {$period}.";

            return $this->result('not_found', null, []);
        }

        foreach ([
            [PayrollImportLinkRepository::KIND_PERSONAL_NUMBER, $numberUsable ? $personalNumber : ''],
            [PayrollImportLinkRepository::KIND_NAME, $nameKey],
        ] as [$kind, $key]) {
            if ($key === '') {
                continue;
            }
            $link = $links[PayrollImportLinkRepository::key($kind, $key)] ?? null;
            if ($link === null) {
                continue;
            }
            if (!isset($byEmployment[$link['employment_id']])) {
                $warnings[] = "Uložená vazba osoby míří na pracovní vztah, který v období {$period} neplatí; nepoužila se.";
                continue;
            }
            $disagreement = self::birthNumberDisagreement($person, (int) $link['employee_id'], $facts);
            if ($disagreement === null) {
                return $this->result('linked', 'link', [$byEmployment[$link['employment_id']]]);
            }
            $employee = (string) $byEmployment[$link['employment_id']]['employee_name'];
            if ($kind === PayrollImportLinkRepository::KIND_PERSONAL_NUMBER) {
                return $this->conflict(
                    "Rozpor v podkladech: osobní číslo „{$personalNumber}“ vede na osobu {$employee}, která {$disagreement}. "
                    . 'Řádek se nepároval; opravte podklady, nebo vyberte vztah ručně.',
                );
            }
            $warnings[] = "Uložená vazba podle jména vede na osobu {$employee}, která {$disagreement}; nepoužila se.";
        }

        if ($byCode !== []) {
            $allowed = [];
            foreach ($byCode as $employment) {
                $disagreement = self::birthNumberDisagreement($person, (int) $employment['employee_id'], $facts);
                if ($disagreement === null) {
                    $allowed[] = $employment;
                    continue;
                }
                return $this->conflict(
                    "Rozpor v podkladech: osobní číslo „{$personalNumber}“ je kódem vztahu osoby {$employment['employee_name']}, "
                    . "která {$disagreement}. Řádek se nepároval; opravte podklady, nebo vyberte vztah ručně.",
                );
            }
            if ($allowed !== []) {
                return $this->resolved('employment_code', self::narrow($allowed, $relationTypes, $personalNumber));
            }
        }

        $candidates = [];
        foreach ($this->employmentsOf(array_keys($nameIndex[$nameKey] ?? []), $byEmployee) as $employment) {
            $disagreement = self::birthNumberDisagreement($person, (int) $employment['employee_id'], $facts);
            if ($disagreement === null) {
                $candidates[] = $employment;
                continue;
            }
            $warnings[] = "Osoba stejného jména v evidenci ({$employment['employee_name']}) {$disagreement}; "
                . 'nespárovala se, jde nejspíš o jiného člověka.';
        }
        if ($candidates !== []) {
            return $this->resolved('name', self::narrow($candidates, $relationTypes, $numberUsable ? $personalNumber : ''));
        }

        return $this->result('not_found', null, []);
    }

    /** @return array<string,mixed> */
    private function conflict(string $message): array
    {
        return ['conflict' => $message] + $this->result('conflict', null, []);
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
            'conflict' => null,
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
