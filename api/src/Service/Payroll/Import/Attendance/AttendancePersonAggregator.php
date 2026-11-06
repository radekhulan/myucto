<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Složí osoby ze všech listů a souborů a přiřadí jim hodnoty.
 *
 * Tvrdá pravidla:
 *  - osoba vzniká jen ze sloupce `person_name` (první takový sloupec listu);
 *  - stejná osoba napříč soubory = stejný normalizovaný klíč jména, osobní
 *    číslo a rodné číslo se připojí; různá osobní čísla u téhož jména se
 *    potichu NESLUČUJÍ;
 *  - stejný význam z více míst se NIKDY nesčítá: platí první podle pořadí
 *    pravidel, odlišné hodnoty jdou do `conflicts` a do varování;
 *  - chyba vzorce ani prázdná buňka nejsou nula.
 *
 * @phpstan-import-type AttendanceMappedSheet from AttendanceColumnMapper
 */
final class AttendancePersonAggregator
{
    private const TEXT_FIELDS = [
        'relation_label', 'department', 'cost_center', 'position', 'weekly_hours', 'start_end_note', 'monthly_wage',
        'hourly_rate', 'end_date',
    ];

    /** Slova, která odlišují otce a syna — jméno lišící se jen jimi je jiná osoba. */
    private const GENERATION_WORDS = ['ml', 'st', 'mladsi', 'starsi', 'jr', 'sr'];

    public function __construct(private readonly AttendanceColumnMapper $mapper)
    {
    }

    /**
     * @param list<AttendanceMappedSheet> $sheets
     * @return list<array<string,mixed>>
     */
    public function aggregate(array $sheets): array
    {
        $observations = [];
        foreach ($sheets as $mapped) {
            if ($mapped['person_column'] === null) {
                continue;
            }
            $sheet = $mapped['sheet'];
            $rows = $this->mapper->personRows($sheet, $mapped['layout'], $mapped['person_column']);
            foreach ($rows as $row => $name) {
                $observations[] = $this->observe($sheet, $mapped['columns'], $row, $name);
            }
        }

        $groups = [];
        foreach ($observations as $observation) {
            $groups[$observation['name_key']][] = $observation;
        }
        [$groups, $mergeWarnings] = self::mergeNameVariants($groups);

        $persons = [];
        foreach ($groups as $nameKey => $group) {
            $merged = $mergeWarnings[$nameKey] ?? [];
            $numbers = [];
            foreach ($group as $observation) {
                if ($observation['personal_number'] !== null) {
                    $numbers[mb_strtoupper($observation['personal_number'], 'UTF-8')] = $observation['personal_number'];
                }
            }
            if (count($numbers) <= 1) {
                $persons[] = $this->person((string) $nameKey, (string) $nameKey, $group, $merged);
                continue;
            }
            $bySplit = [];
            $unassigned = [];
            foreach ($group as $observation) {
                if ($observation['personal_number'] === null) {
                    $unassigned[] = $observation;
                    continue;
                }
                $bySplit[mb_strtoupper($observation['personal_number'], 'UTF-8')][] = $observation;
            }
            $warning = 'Jméno „' . $group[0]['name'] . '“ je v podkladech s více osobními čísly ('
                . implode(', ', array_values($numbers)) . '). Osoby se nesloučily; zkontrolujte, zda jde o různé lidi.';
            foreach ($bySplit as $number => $subgroup) {
                $persons[] = $this->person((string) $nameKey . '#' . $number, (string) $nameKey, $subgroup, [$warning, ...$merged]);
            }
            if ($unassigned !== []) {
                $persons[] = $this->person((string) $nameKey, (string) $nameKey, $unassigned, [
                    $warning . ' Řádky bez osobního čísla nejde přiřadit ani k jedné z nich.',
                    ...$merged,
                ]);
            }
        }
        usort(
            $persons,
            static fn (array $a, array $b): int => strcmp((string) $a['_sort'], (string) $b['_sort']),
        );

        return $persons;
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @return array<string,mixed>
     */
    private function observe(AttendanceSheet $sheet, array $columns, int $row, string $name): array
    {
        $identity = [];
        $values = [];
        foreach ($columns as $column => $binding) {
            $binding = self::applyCondition($sheet, $binding, $row);
            $meaning = (string) $binding['meaning'];
            if ($meaning === AttendanceMeaning::IGNORE || $meaning === AttendanceMeaning::PERSON_NAME) {
                continue;
            }
            $cell = $sheet->cell($row, $column);
            if ($cell->isEmpty()) {
                continue;
            }
            $priority = [
                $binding['rule_index'] ?? PHP_INT_MAX,
                $sheet->fileIndex,
                $sheet->sheetIndex,
                $column,
                $row,
            ];
            if (AttendanceMeaning::isIdentity($meaning)) {
                $text = $this->identityText($cell, $meaning);
                if ($text !== '' && (!isset($identity[$meaning]) || $priority < $identity[$meaning]['priority'])) {
                    $identity[$meaning] = ['value' => $text, 'priority' => $priority];
                }
                continue;
            }
            $values[] = [
                'meaning' => $meaning,
                'component_code' => $binding['component_code'],
                'unit' => $binding['unit'],
                'header' => $binding['header'],
                'priority' => $priority,
                'cell' => $cell,
                'source' => $sheet->source($row, $column),
            ];
        }
        $personalNumber = $identity['personal_number']['value'] ?? null;

        return [
            'name' => $name,
            'name_key' => AttendanceText::personKey($name),
            'personal_number' => $personalNumber,
            'identity' => array_map(static fn (array $item): string => $item['value'], $identity),
            'identity_priority' => array_map(static fn (array $item): array => $item['priority'], $identity),
            'values' => $values,
            'sheet_id' => $sheet->id(),
            'row' => $row,
            'order' => [$sheet->fileIndex, $sheet->sheetIndex, $row],
        ];
    }

    /**
     * @param list<array<string,mixed>> $group
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function person(string $key, string $nameKey, array $group, array $warnings): array
    {
        usort($group, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $identity = [];
        $identityPriority = [];
        $sources = [];
        $candidates = [];
        $seenRows = [];
        foreach ($group as $observation) {
            $sources[] = ['sheet_id' => $observation['sheet_id'], 'row' => $observation['row']];
            $seenRows[$observation['sheet_id']][] = $observation['row'];
            foreach ($observation['identity'] as $meaning => $value) {
                $priority = $observation['identity_priority'][$meaning];
                if (!isset($identity[$meaning])) {
                    $identity[$meaning] = $value;
                    $identityPriority[$meaning] = $priority;
                    continue;
                }
                if ($meaning === 'birth_number'
                    && self::digits($identity[$meaning]) !== self::digits($value)) {
                    $warnings[] = 'Osoba má v podkladech dvě různá rodná čísla; k párování se rodné číslo nepoužije.';
                    $identity['_birth_conflict'] = '1';
                }
                if ($priority < $identityPriority[$meaning]) {
                    $identity[$meaning] = $value;
                    $identityPriority[$meaning] = $priority;
                }
            }
            foreach ($observation['values'] as $value) {
                $candidates[] = $value;
            }
        }
        foreach ($seenRows as $sheetId => $rows) {
            if (count($rows) > 1) {
                $warnings[] = "Osoba je v listu {$sheetId} vícekrát (řádky " . implode(', ', $rows)
                    . '). Hodnoty se nesčítají, platí první řádek.';
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $metrics = [];
        $components = [];
        $deductions = [];
        $reference = ['gross_minor' => null, 'net_minor' => null, 'hours' => null];
        $internalMetrics = [];
        $internalComponents = [];
        $internalDeductions = [];
        $grouped = [];
        foreach ($candidates as $candidate) {
            $groupKey = $candidate['meaning'] === AttendanceMeaning::COMPONENT
                ? 'component:' . $candidate['component_code']
                : $candidate['meaning'];
            $grouped[$groupKey][] = $candidate;
        }
        foreach ($grouped as $groupKey => $items) {
            $meaning = (string) $items[0]['meaning'];
            $money = AttendanceMeaning::isMoney($meaning);
            $primary = null;
            $conflicts = [];
            foreach ($items as $item) {
                $parsed = $money
                    ? self::amountMinor($item['cell'])
                    : self::millihours($item['cell'], (string) ($item['unit'] ?? AttendanceMeaning::UNIT_HOURS));
                if (isset($parsed['error'])) {
                    $warnings[] = "Buňka {$item['source']} ({$item['header']}): {$parsed['error']} Hodnota se nepoužila.";
                    continue;
                }
                $value = (int) $parsed['value'];
                if ($primary === null) {
                    $primary = ['value' => $value, 'source' => $item['source'], 'header' => $item['header']];
                    continue;
                }
                if ($value !== $primary['value'] && !in_array($value, array_column($conflicts, 'value'), true)) {
                    $conflicts[] = ['value' => $value, 'source' => $item['source']];
                }
            }
            if ($primary === null) {
                continue;
            }
            $format = static fn (int $v): string => $money
                ? AttendanceDecimal::formatMinor($v) . ' Kč'
                : AttendanceDecimal::formatMillihours($v) . ' h';
            if ($conflicts !== []) {
                $warnings[] = "„{$primary['header']}“ má v podkladech rozdílné hodnoty: "
                    . $format($primary['value']) . " ({$primary['source']}), "
                    . implode(', ', array_map(
                        static fn (array $c): string => $format($c['value']) . " ({$c['source']})",
                        $conflicts,
                    ))
                    . '. Hodnoty se nesčítají, použila se první podle pořadí pravidel.';
            }
            if ($meaning === AttendanceMeaning::COMPONENT) {
                if ($primary['value'] === 0) {
                    continue;
                }
                $code = (string) $items[0]['component_code'];
                $components[] = [
                    'component_code' => $code,
                    'amount' => AttendanceDecimal::formatMinor($primary['value']),
                    'amount_minor' => $primary['value'],
                    'source' => $primary['source'],
                    'conflicts' => array_map(static fn (array $c): array => [
                        'amount' => AttendanceDecimal::formatMinor($c['value']),
                        'amount_minor' => $c['value'],
                        'source' => $c['source'],
                    ], $conflicts),
                ];
                $internalComponents[$code] = ['amount_minor' => $primary['value'], 'source' => $primary['source']];
                continue;
            }
            if (AttendanceMeaning::isDeduction($meaning)) {
                if ($primary['value'] < 0) {
                    $warnings[] = "Srážka „{$primary['header']}“ ({$primary['source']}) je záporná; nepoužila se.";
                }
                if ($primary['value'] <= 0) {
                    continue;
                }
                $deductions[] = [
                    'meaning' => $meaning,
                    'amount' => AttendanceDecimal::formatMinor($primary['value']),
                    'amount_minor' => $primary['value'],
                    'source' => $primary['source'],
                    'conflicts' => array_map(static fn (array $c): array => [
                        'amount' => AttendanceDecimal::formatMinor($c['value']),
                        'amount_minor' => $c['value'],
                        'source' => $c['source'],
                    ], $conflicts),
                ];
                $internalDeductions[$meaning] = ['amount_minor' => $primary['value'], 'source' => $primary['source']];
                continue;
            }
            if ($meaning === 'reference_gross' || $meaning === 'reference_net') {
                $reference[$meaning === 'reference_gross' ? 'gross_minor' : 'net_minor'] = $primary['value'];
                $internalMetrics[$meaning] = ['amount_minor' => $primary['value'], 'source' => $primary['source']];
                continue;
            }
            if ($meaning === 'reference_hours') {
                $reference['hours'] = AttendanceDecimal::formatMillihours($primary['value']);
                $internalMetrics[$meaning] = ['millihours' => $primary['value'], 'source' => $primary['source']];
                continue;
            }
            $metrics[] = [
                'meaning' => $meaning,
                'hours' => AttendanceDecimal::formatMillihours($primary['value']),
                'source' => $primary['source'],
                'conflicts' => array_map(static fn (array $c): array => [
                    'hours' => AttendanceDecimal::formatMillihours($c['value']),
                    'source' => $c['source'],
                ], $conflicts),
            ];
            $internalMetrics[$meaning] = ['millihours' => $primary['value'], 'source' => $primary['source']];
        }
        usort(
            $metrics,
            static fn (array $a, array $b): int => array_search($a['meaning'], AttendanceMeaning::HOURS, true)
                <=> array_search($b['meaning'], AttendanceMeaning::HOURS, true),
        );

        $birthNumber = isset($identity['_birth_conflict']) ? null : ($identity['birth_number'] ?? null);
        $birthDate = self::isoDate((string) ($identity['birth_date'] ?? ''));
        $insurerCode = trim((string) ($identity['health_insurer_code'] ?? ''));
        $person = [
            'key' => $key,
            'display_name' => (string) $group[0]['name'],
            'personal_number' => $identity['personal_number'] ?? null,
            'birth_number_masked' => null,
            'bank_account_masked' => null,
        ];
        foreach (self::TEXT_FIELDS as $field) {
            $person[$field] = $identity[$field] ?? null;
        }
        $person += AttendanceText::noteDates((string) ($identity['start_end_note'] ?? ''));
        // Samostatný sloupec s datem nástupu má přednost před datem z poznámky.
        $startDate = trim((string) ($identity['start_date'] ?? ''));
        if ($startDate !== '') {
            $start = self::isoDate($startDate);
            if ($start === null) {
                $warnings[] = "Datum nástupu „{$startDate}“ nejde přečíst. Zapište ho jako den. měsíc. rok.";
            } else {
                $person['start_on'] = $start;
            }
        }
        // Sjednaný konec dohody nebo doby určité není ukončení z poznámky
        // (`end_on`): vztah k němu teprve skončí, varování o ukončení nepatří.
        $person['agreed_end_on'] = null;
        $endDate = trim((string) ($identity['end_date'] ?? ''));
        if ($endDate !== '') {
            $person['agreed_end_on'] = self::isoDate($endDate);
            if ($person['agreed_end_on'] === null) {
                $warnings[] = "Konec dohody „{$endDate}“ nejde přečíst. Zapište ho jako den. měsíc. rok.";
            }
        }
        $bankAccount = trim((string) ($identity['bank_account'] ?? ''));

        return $person + [
            'sources' => $sources,
            'match' => null,
            'metrics' => $metrics,
            'components' => $components,
            'deductions' => $deductions,
            'reference' => $reference,
            'warnings' => array_values(array_unique($warnings)),
            '_name_key' => $nameKey,
            '_birth_number' => $birthNumber,
            '_birth_date' => $birthDate,
            '_health_insurer_code' => $insurerCode === '' ? null : $insurerCode,
            '_bank_account' => $bankAccount === '' ? null : $bankAccount,
            '_metrics' => $internalMetrics,
            '_components' => $internalComponents,
            '_deductions' => $internalDeductions,
            '_sort' => AttendanceText::normalize((string) $group[0]['name']) . "\0" . $key,
        ];
    }

    /**
     * Pravidlo s podmínkou: platí první, jehož podmínka v tomto řádku sedí;
     * jinak zůstává bezpodmínečné pravidlo sloupce.
     *
     * @param array<string,mixed> $binding
     * @return array<string,mixed>
     */
    private static function applyCondition(AttendanceSheet $sheet, array $binding, int $row): array
    {
        foreach ($binding['conditions'] ?? [] as $condition) {
            if ($condition['when_column'] === null) {
                continue;
            }
            $cell = $sheet->cell($row, (int) $condition['when_column']);
            if ($cell->isEmpty()
                || !AttendanceRules::like((string) $condition['when_value'], AttendanceText::normalize($cell->textValue()))) {
                continue;
            }

            return [
                'meaning' => $condition['meaning'],
                'unit' => $condition['unit'],
                'component_code' => $condition['component_code'],
                'rule_index' => $condition['rule_index'],
            ] + $binding;
        }

        return $binding;
    }

    /**
     * Totéž jméno zapsané s dalším jménem navíc („Nováková Jana Marie" proti
     * „Nováková Jana") je v podkladech jedna osoba. Spojí se jen řádky bez
     * osobního i rodného čísla, a jen s jedinou jinou osobou, se kterou sdílí
     * aspoň dvě slova a liší se jen slovy navíc; spojení se vždy ohlásí.
     * Řetězení variant se za uživatele nerozhoduje.
     *
     * @param array<string,list<array<string,mixed>>> $groups
     * @return array{0:array<string,list<array<string,mixed>>>,1:array<string,list<string>>}
     */
    private static function mergeNameVariants(array $groups): array
    {
        $words = [];
        foreach (array_keys($groups) as $key) {
            $words[(string) $key] = explode(' ', (string) $key);
        }
        $targets = [];
        foreach ($words as $key => $set) {
            if (count($set) < 2 || self::identified($groups[$key])) {
                continue;
            }
            $candidates = [];
            foreach ($words as $other => $otherSet) {
                if ($other === $key || count(array_intersect($set, $otherSet)) < 2) {
                    continue;
                }
                $missing = array_diff($set, $otherSet);
                $extra = array_diff($otherSet, $set);
                if (($missing !== [] && $extra !== [])
                    || array_intersect([...$missing, ...$extra], self::GENERATION_WORDS) !== []) {
                    continue;
                }
                $candidates[] = (string) $other;
            }
            if (count($candidates) === 1) {
                $targets[$key] = $candidates[0];
            }
        }

        $warnings = [];
        foreach ($targets as $source => $target) {
            if (!isset($groups[$source], $groups[$target])) {
                continue;
            }
            $back = $targets[$target] ?? null;
            // Dvě neoznačené varianty mířící na sebe: kratší jméno se spojí do delšího.
            if ($back === $source && count($words[$source]) > count($words[$target])) {
                continue;
            }
            if ($back !== null && $back !== $source) {
                continue;
            }
            $warnings[$target][] = 'Jméno „' . $groups[$source][0]['name'] . '“ je v podkladech i jako „'
                . $groups[$target][0]['name'] . '“. Řádky se spojily do jedné osoby; jde-li o dva lidi, upravte jméno v souboru.';
            $groups[$target] = [...$groups[$target], ...$groups[$source]];
            unset($groups[$source]);
        }

        return [$groups, $warnings];
    }

    /** @param list<array<string,mixed>> $group */
    private static function identified(array $group): bool
    {
        foreach ($group as $observation) {
            if ($observation['personal_number'] !== null || isset($observation['identity']['birth_number'])) {
                return true;
            }
        }

        return false;
    }

    private function identityText(AttendanceCell $cell, string $meaning): string
    {
        if ($meaning === 'weekly_hours') {
            if ($cell->kind === AttendanceCell::NUMBER && $cell->hasDurationFormat()) {
                return AttendanceDecimal::formatMillihours(AttendanceDecimal::durationMillihours((float) $cell->number));
            }
            if ($cell->kind === AttendanceCell::STRING) {
                $clock = AttendanceDecimal::parseClockMillihours($cell->textValue());
                if ($clock !== null) {
                    return AttendanceDecimal::formatMillihours($clock);
                }
                $hours = AttendanceDecimal::weeklyHours($cell->textValue());
                if ($hours !== null) {
                    return $hours;
                }
            }
        }
        if (in_array($meaning, ['birth_date', 'start_date', 'end_date'], true) && $cell->kind === AttendanceCell::NUMBER) {
            return self::excelDate((float) $cell->number) ?? '';
        }
        if ($cell->kind === AttendanceCell::ERROR) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $cell->textValue()));
    }

    /** Pořadové číslo dne z Excelu (1900 systém) jako YYYY-MM-DD. */
    private static function excelDate(float $serial): ?string
    {
        $days = (int) floor($serial);
        if ($days < 1 || $days > 2958465) {
            return null;
        }

        return (new \DateTimeImmutable('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
    }

    /**
     * Datum z textu podkladů: „1. 5. 1990", „01.05.1990" nebo „1990-05-01".
     * Nečitelné nebo neexistující datum se nepoužije.
     */
    public static function isoDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/D', $value, $match) === 1) {
            [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } elseif (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:\s.*)?$/D', $value, $match) === 1) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** @return array{value:int}|array{error:string} */
    public static function millihours(AttendanceCell $cell, string $unit): array
    {
        if ($cell->kind === AttendanceCell::ERROR) {
            return ['error' => self::errorMessage($cell)];
        }
        if ($cell->kind === AttendanceCell::NUMBER) {
            $value = $unit === AttendanceMeaning::UNIT_DURATION
                ? AttendanceDecimal::durationMillihours((float) $cell->number)
                : AttendanceDecimal::scaled(AttendanceDecimal::fromFloat((float) $cell->number, 6), 3);
        } elseif ($cell->kind === AttendanceCell::STRING) {
            $text = $cell->textValue();
            $value = AttendanceDecimal::parseClockMillihours($text);
            if ($value === null) {
                $number = AttendanceDecimal::parseNumber($text);
                if ($number === null) {
                    return ['error' => "hodnotu „{$text}“ nejde přečíst jako hodiny."];
                }
                $value = AttendanceDecimal::scaled($number, 3);
            }
        } else {
            return ['error' => 'hodnota není číslo ani čas.'];
        }
        if ($value < 0) {
            return ['error' => 'hodiny nesmí být záporné.'];
        }

        return ['value' => $value];
    }

    /** @return array{value:int}|array{error:string} */
    public static function amountMinor(AttendanceCell $cell): array
    {
        if ($cell->kind === AttendanceCell::ERROR) {
            return ['error' => self::errorMessage($cell)];
        }
        if ($cell->kind === AttendanceCell::NUMBER) {
            return ['value' => AttendanceDecimal::scaled(AttendanceDecimal::fromFloat((float) $cell->number, 4), 2)];
        }
        if ($cell->kind === AttendanceCell::STRING) {
            $number = AttendanceDecimal::parseNumber($cell->textValue());
            if ($number === null) {
                return ['error' => "hodnotu „{$cell->textValue()}“ nejde přečíst jako částku."];
            }

            return ['value' => AttendanceDecimal::scaled($number, 2)];
        }

        return ['error' => 'hodnota není částka.'];
    }

    private static function errorMessage(AttendanceCell $cell): string
    {
        if ($cell->text === AttendanceCell::NO_CACHED_VALUE) {
            return 'vzorec nemá uloženou hodnotu — otevřete sešit v Excelu, nechte ho přepočítat, uložte a nahrajte znovu.';
        }

        return "vzorec končí chybou {$cell->text} — opravte ho v sešitu.";
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
