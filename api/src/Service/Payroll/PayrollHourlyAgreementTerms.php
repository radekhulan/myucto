<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Http\IsoDate;

/** Doložené smluvní rozsahy; čerpání a otisky dopočítává server z evidence. */
final class PayrollHourlyAgreementTerms
{
    /** @return ?array<string,mixed> */
    public static function normalize(mixed $input, string $validFrom, string $overtimeMode): ?array
    {
        if ($input === null) return null;
        if (!is_array($input)) throw new \InvalidArgumentException('Podklady hodinového sjednání musí být objekt.');
        $overtime = $input['included_overtime'] ?? null;
        if ($overtime !== null) {
            if (!is_array($overtime) || $overtimeMode !== 'included_in_wage') {
                throw new \InvalidArgumentException('Rozsah zahrnutého přesčasu vyžaduje odpovídající režim sjednání.');
            }
            if (($overtime['agreement_kind'] ?? null) !== 'contract') {
                throw new \InvalidArgumentException('Přesčas lze zahrnout pouze smluvním sjednáním mzdy.');
            }
            $year = self::integer($overtime, 'calendar_year', 2000, 2100);
            if ($year !== (int) substr($validFrom, 0, 4)) {
                throw new \InvalidArgumentException('Roční rozsah přesčasu musí odpovídat roku účinnosti této verze sjednání.');
            }
            $scope = $overtime['scope_kind'] ?? null;
            if (!in_array($scope, ['annual_hours', 'manager_statutory'], true)) {
                throw new \InvalidArgumentException('Zvolte doložený roční rozsah zahrnutého přesčasu.');
            }
            $limit = self::integer($overtime, 'annual_limit_duration_units', 1, 26_352_000);
            $opening = self::integer($overtime, 'opening_used_duration_units', 0, $limit);
            $openingDate = self::date($overtime['opening_as_of'] ?? null);
            if ((int) substr($openingDate, 0, 4) !== $year || $openingDate < $year . '-01-01') {
                throw new \InvalidArgumentException('Počáteční čerpání přesčasů musí patřit do sjednaného kalendářního roku.');
            }
            $manager = self::text($overtime['manager_proof_reference'] ?? null, false);
            $lawful = null;
            $lawfulReference = null;
            if ($limit > 150 * 3000 || $scope === 'manager_statutory') {
                if ($manager === null) throw new \InvalidArgumentException('Vyšší rozsah přesčasu vyžaduje doložení vedoucího postavení.');
                $lawful = self::integer($overtime, 'manager_lawful_scope_duration_units', 1, 26_352_000);
                $lawfulReference = self::text($overtime['manager_lawful_scope_reference'] ?? null);
                if ($limit > $lawful) throw new \InvalidArgumentException('Sjednaný přesčas překračuje doložený zákonný rozsah.');
            }
            $overtime = ['agreement_kind' => 'contract', 'agreement_reference' => self::text($overtime['agreement_reference'] ?? null),
                'scope_kind' => $scope, 'calendar_year' => $year, 'annual_limit_duration_units' => $limit,
                'manager_proof_reference' => $manager, 'opening_used_duration_units' => $opening,
                'opening_as_of' => $openingDate, 'opening_reference' => self::text($overtime['opening_reference'] ?? null),
                'manager_lawful_scope_duration_units' => $lawful, 'manager_lawful_scope_reference' => $lawfulReference];
        }
        $premiums = $input['included_agreement_premiums'] ?? [];
        if (!is_array($premiums) || !array_is_list($premiums) || count($premiums) > 3) {
            throw new \InvalidArgumentException('Sjednat lze nejvýše tři druhy příplatků zahrnutých v odměně.');
        }
        $normalized = [];
        $kinds = [];
        foreach ($premiums as $premium) {
            if (!is_array($premium) || !in_array($premium['kind'] ?? null, ['night', 'weekend', 'difficult_environment'], true)) {
                throw new \InvalidArgumentException('Do odměny dohody lze zahrnout pouze noc, víkend a ztížené prostředí.');
            }
            $kind = $premium['kind'];
            if (isset($kinds[$kind])) throw new \InvalidArgumentException('Druh zahrnutého příplatku je uveden vícekrát.');
            $kinds[$kind] = true;
            $from = self::date($premium['date_from'] ?? null);
            $to = self::date($premium['date_to'] ?? null);
            $openingDate = self::date($premium['opening_as_of'] ?? null);
            if ($to < $from || $openingDate < $from || $openingDate > $to) {
                throw new \InvalidArgumentException('Rozsah zahrnutého příplatku a datum počátečního čerpání nejsou platné.');
            }
            $scope = self::integer($premium, 'scope_duration_units', 1, 26_352_000);
            $normalized[] = ['kind' => $kind, 'date_from' => $from, 'date_to' => $to,
                'scope_duration_units' => $scope,
                'included_hourly_minor' => self::integer($premium, 'included_hourly_minor', 1, 1_000_000_000),
                'agreement_reference' => self::text($premium['agreement_reference'] ?? null),
                'opening_used_duration_units' => self::integer($premium, 'opening_used_duration_units', 0, $scope),
                'opening_as_of' => $openingDate, 'opening_reference' => self::text($premium['opening_reference'] ?? null)];
        }
        $result = ['included_overtime' => $overtime, 'included_agreement_premiums' => $normalized];
        if (array_key_exists('minimum_wage', $input) && $input['minimum_wage'] !== null) {
            $minimum = $input['minimum_wage'];
            if (!is_array($minimum) || !in_array($minimum['assessment'] ?? null, ['hourly', 'monthly'], true)) {
                throw new \InvalidArgumentException('Zvolte hodinové nebo doložené měsíční posouzení minimální mzdy.');
            }
            $assessment = $minimum['assessment'];
            $reference = self::text($minimum['agreement_reference'] ?? null, false);
            $weekly = $minimum['statutory_weekly_minutes'] ?? null;
            $weeklyReference = self::text($minimum['statutory_weekly_reference'] ?? null, $weekly !== null);
            if ($weekly !== null) $weekly = self::integer($minimum, 'statutory_weekly_minutes', 1, 2400);
            $proof = $minimum['advance_proof'] ?? null;
            if ($assessment === 'monthly') {
                if (!is_array($proof)) throw new \InvalidArgumentException('Měsíční posouzení minimální mzdy vyžaduje předem doložené rozhodnutí.');
                $decidedOn = self::date($proof['decided_on'] ?? null);
                $from = self::date($proof['valid_from'] ?? null);
                $to = ($proof['valid_to'] ?? null) === null ? null : self::date($proof['valid_to']);
                if ($decidedOn > $from || ($to !== null && $to < $from)) throw new \InvalidArgumentException('Rozhodnutí o posouzení musí předcházet platnosti a mít platné období.');
                $proof = ['reference' => self::text($proof['reference'] ?? null), 'decided_on' => $decidedOn, 'valid_from' => $from, 'valid_to' => $to];
            } elseif ($proof !== null) {
                throw new \InvalidArgumentException('Rozhodnutí pro měsíční posouzení nelze uvést u hodinového posouzení.');
            }
            $result['minimum_wage'] = ['assessment' => $assessment, 'agreement_reference' => $reference,
                'statutory_weekly_minutes' => $weekly, 'statutory_weekly_reference' => $weeklyReference, 'advance_proof' => $proof];
        }
        return $result;
    }

    /** @param array<string,mixed> $input */
    private static function integer(array $input, string $key, int $minimum, int $maximum): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($value === false) throw new \InvalidArgumentException('Rozsah, sazba a počáteční čerpání musí být výslovně doložená celá čísla v povolených mezích.');
        return $value;
    }

    private static function date(mixed $input): string
    {
        if (!is_string($input) || !IsoDate::isValid($input)) throw new \InvalidArgumentException('Datum sjednání musí být platné datum YYYY-MM-DD.');
        return $input;
    }

    private static function text(mixed $input, bool $required = true): ?string
    {
        if ($input === null || $input === '') {
            if ($required) throw new \InvalidArgumentException('Doplňte označení dokladu sjednání nebo počátečního čerpání.');
            return null;
        }
        if (!is_string($input) || trim($input) === '' || mb_strlen(trim($input)) > 500) {
            throw new \InvalidArgumentException('Označení podkladu smí mít 1 až 500 znaků.');
        }
        return trim($input);
    }
}
