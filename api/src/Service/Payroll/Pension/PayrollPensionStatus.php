<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Pension;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;

/**
 * Důchodové údaje osoby ze zákonné evidence — jediné pravidlo, podle kterého
 * z nich roční evidenční list i měsíční hlášení JMHZ skládají kód D a odečtené
 * doby (`pension_status`: `pension_age_reached_on`, `early_pension_from`,
 * `full_pension_paid_from`).
 *
 * Evidence má dvě řady:
 *
 * - **den dosažení důchodového věku** (`age`, nejvýš jeden záznam),
 * - **pobíraný důchod** od-do (`pensions`) s druhem podle číselníku CIS Druh
 *   důchodu z registrace REGZEC (atribut 10113) a příznakem předčasného
 *   starobního důchodu (10115). Týž číselník nese profil registrace A1, takže
 *   evidence a přihláška nemluví dvěma jazyky.
 *
 * Odvození pro interval (měsíc hlášení nebo rok listu):
 *
 * - `early_pension_from` je začátek předčasného starobního důchodu, který
 *   s intervalem sdílí aspoň den,
 * - `full_pension_paid_from` je měsíc, od něhož náleží výplata starobního
 *   důchodu (druh 1 bez příznaku předčasnosti), který s intervalem sdílí aspoň
 *   den; u důchodu přiznaného uprostřed měsíce je to měsíc přiznání. List se
 *   vede „do měsíce, od něhož pojištěnci náleží výplata starobního důchodu
 *   v plné výši" (Všeobecné zásady ELDP, Hlavní zásady; Metodická pomůcka
 *   ELDP, příklad 8a) a tento měsíc ještě nese, takže doba pojištění před
 *   přiznáním se neztratí. Sleva na pojistném (§ 7d zákona č. 589/1992 Sb.)
 *   začíná až prvním celým měsícem výplaty, tedy měsícem, který list už nevede.
 *
 * Invalidní a cizí důchody (2, 8, A, B, C) kód D nezakládají; evidují se kvůli
 * registraci a dávkám nemocenského pojištění.
 */
final class PayrollPensionStatus
{
    /**
     * CIS Druh důchodu (REGZEC 10113). Shodu s připnutým číselníkem JMHZ hlídá
     * test, druhá kopie seznamu tak nemůže tiše zastarat.
     */
    public const PENSION_TYPE_CODES = ['1', '2', '8', 'A', 'B', 'C'];

    /** Starobní důchod (CIS Druh důchodu). */
    public const OLD_AGE = '1';

    /** Odkud účetní den dosažení důchodového věku zná. */
    public const AGE_BASES = ['statutory_table', 'cssz_information', 'employee_declaration'];

    /** @var list<string> */
    public const STATUS_KEYS = ['pension_age_reached_on', 'early_pension_from', 'full_pension_paid_from'];

    /**
     * Ověří a sjednotí obě řady. Čte je stejně editor (při uložení) i čtecí
     * cesty ELDP a JMHZ, takže pravidla nemohou mít dvě podoby.
     *
     * @param list<array<string,mixed>> $ageRows
     * @param list<array<string,mixed>> $pensionRows
     * @return array{
     *   age:?array{effective_from:string,basis:string},
     *   pensions:list<array{pension_type_code:string,early_retirement:bool,reduced_retirement_age:bool,effective_from:string,effective_to:?string}>
     * }
     */
    public static function normalize(array $ageRows, array $pensionRows): array
    {
        if (count($ageRows) > 1) {
            throw new InvalidArgumentException(
                'Den dosažení důchodového věku smí mít osoba zapsaný jen jednou.',
            );
        }
        $age = null;
        foreach ($ageRows as $row) {
            if (($row['effective_to'] ?? null) !== null && ($row['effective_to'] ?? '') !== '') {
                throw new InvalidArgumentException(
                    'Den dosažení důchodového věku nemá konec platnosti.',
                );
            }
            $basis = self::text($row['basis'] ?? null);
            if (!in_array($basis, self::AGE_BASES, true)) {
                throw new InvalidArgumentException(
                    'Zdroj dne dosažení důchodového věku musí být jedna z hodnot: '
                    . implode(', ', self::AGE_BASES) . '.',
                );
            }
            self::assertReference($row['evidence_reference'] ?? null);
            $age = [
                'effective_from' => self::date($row['effective_from'] ?? null, 'Den dosažení důchodového věku'),
                'basis' => (string) $basis,
            ];
        }

        $pensions = [];
        foreach ($pensionRows as $row) {
            $type = self::text($row['pension_type_code'] ?? null);
            if (!in_array($type, self::PENSION_TYPE_CODES, true)) {
                throw new InvalidArgumentException(
                    'Druh důchodu musí být kód z číselníku ČSSZ CIS Druh důchodu ('
                    . implode(', ', self::PENSION_TYPE_CODES) . ').',
                );
            }
            $early = self::flag($row['early_retirement'] ?? null, 'Předčasný starobní důchod');
            if ($early && $type !== self::OLD_AGE) {
                throw new InvalidArgumentException(
                    'Předčasný může být jen starobní důchod (druh 1).',
                );
            }
            $from = self::date($row['effective_from'] ?? null, 'Důchod pobírán od');
            $toValue = $row['effective_to'] ?? null;
            $to = $toValue === null || $toValue === '' ? null : self::date($toValue, 'Důchod pobírán do');
            if ($to !== null && $to < $from) {
                throw new InvalidArgumentException('Důchod nesmí končit dřív, než začal.');
            }
            self::assertReference($row['evidence_reference'] ?? null);
            $pensions[] = [
                'pension_type_code' => (string) $type,
                'early_retirement' => $early,
                'reduced_retirement_age' => self::flag($row['reduced_retirement_age'] ?? null, 'Snížený důchodový věk'),
                'effective_from' => $from,
                'effective_to' => $to,
            ];
        }
        usort(
            $pensions,
            static fn (array $left, array $right): int =>
                [$left['pension_type_code'], $left['effective_from']]
                <=> [$right['pension_type_code'], $right['effective_from']],
        );
        $previous = [];
        foreach ($pensions as $pension) {
            $type = $pension['pension_type_code'];
            if (array_key_exists($type, $previous)
                && ($previous[$type] === null || $previous[$type] >= $pension['effective_from'])
            ) {
                throw new InvalidArgumentException(
                    "Důchod druhu {$type} se v evidenci překrývá.",
                );
            }
            $previous[$type] = $pension['effective_to'];
        }

        return ['age' => $age, 'pensions' => $pensions];
    }

    /**
     * Důchodové údaje pro interval, v tvaru, který čtou sestavovač ročního
     * listu i měsíční ELDP řez.
     *
     * @param array{age:?array{effective_from:string},pensions:list<array<string,mixed>>} $evidence
     * @return array{pension_age_reached_on:?string,early_pension_from:?string,full_pension_paid_from:?string}
     */
    public static function forInterval(array $evidence, string $from, string $to): array
    {
        $early = null;
        $full = null;
        foreach ($evidence['pensions'] as $pension) {
            if ($pension['pension_type_code'] !== self::OLD_AGE
                || $pension['effective_from'] > $to
                || ($pension['effective_to'] !== null && $pension['effective_to'] < $from)
            ) {
                continue;
            }
            if ($pension['early_retirement'] === true) {
                $early = $early === null ? $pension['effective_from'] : min($early, $pension['effective_from']);
                continue;
            }
            $month = substr($pension['effective_from'], 0, 7);
            $full = $full === null ? $month : min($full, $month);
        }

        return [
            'pension_age_reached_on' => $evidence['age']['effective_from'] ?? null,
            'early_pension_from' => $early,
            'full_pension_paid_from' => $full,
        ];
    }

    /**
     * Vede se za měsíc ještě údaje ELDP, nebo už je osoba poživatelem
     * starobního důchodu v plné výši, za kterého se nevedou?
     *
     * ELDP se vyplňuje „pouze do měsíce, od něhož náleží výplata starobního
     * důchodu v plné výši" včetně tohoto měsíce (Metodická pomůcka ELDP,
     * příklad 8a; odpověď MPSV/ČSSZ v diskusi JMHZ #363 ze 16. 4. 2026).
     * Platí to stejně pro důchod přiznaný od prvního dne měsíce jako uprostřed
     * měsíce: první měsíc bez ELDP je až následující. Jediné pravidlo pro
     * roční evidenční list, měsíční hlášení JMHZ i odečtené doby.
     *
     * @param ?string $fullPensionPaidFrom měsíc RRRR-MM (`full_pension_paid_from`)
     * @param string $periodStart libovolný den vykazovaného měsíce RRRR-MM-DD
     */
    public static function eldpKeptInMonth(?string $fullPensionPaidFrom, string $periodStart): bool
    {
        return $fullPensionPaidFrom === null || substr($periodStart, 0, 7) <= $fullPensionPaidFrom;
    }

    /**
     * První den prvního měsíce, za který se ELDP kvůli starobnímu důchodu
     * v plné výši už nevede ({@see eldpKeptInMonth()}); `null` bez důchodu.
     *
     * @param ?string $fullPensionPaidFrom měsíc RRRR-MM
     */
    public static function eldpNotKeptFrom(?string $fullPensionPaidFrom): ?string
    {
        if ($fullPensionPaidFrom === null) {
            return null;
        }

        return (new DateTimeImmutable($fullPensionPaidFrom . '-01'))
            ->modify('first day of next month')
            ->format('Y-m-d');
    }

    /** @param array{age:?array<string,mixed>,pensions:list<array<string,mixed>>} $evidence */
    public static function isEmpty(array $evidence): bool
    {
        return $evidence['age'] === null && $evidence['pensions'] === [];
    }

    /**
     * Klíče, ve kterých se výslovné potvrzení účetní rozchází s evidencí.
     *
     * Potvrzení je kontrola, ne druhý zdroj: prázdná hodnota znamená „podle
     * evidence", vyplněná musí s evidencí souhlasit.
     *
     * @param array<string,mixed> $confirmed
     * @param array{pension_age_reached_on:?string,early_pension_from:?string,full_pension_paid_from:?string} $fromEvidence
     * @return list<string>
     */
    public static function mismatches(array $confirmed, array $fromEvidence): array
    {
        $mismatched = [];
        foreach (self::STATUS_KEYS as $key) {
            $value = $confirmed[$key] ?? null;
            if ($value !== null && $value !== $fromEvidence[$key]) {
                $mismatched[] = $key;
            }
        }

        return $mismatched;
    }

    private static function assertReference(mixed $value): void
    {
        $reference = self::text($value);
        if ($reference !== null && !PayrollPersonStatutoryEvidenceValidator::isCanonicalReference($reference)) {
            throw new InvalidArgumentException(
                'Označení dokladu k důchodovým údajům smí obsahovat jen písmena bez diakritiky,'
                . ' číslice a znaky . : / _ - , musí začínat písmenem nebo číslicí'
                . ' a být nejvýše 500 znaků dlouhé.',
            );
        }
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function flag(mixed $value, string $label): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0', $value === null => false,
            default => throw new InvalidArgumentException("{$label} musí být ano, nebo ne."),
        };
    }

    private static function date(mixed $value, string $label): string
    {
        $text = self::text($value);
        $date = $text === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException("{$label} musí být datum RRRR-MM-DD.");
        }

        return $text;
    }
}
