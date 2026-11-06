<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Sleva na studenta podle § 35ba odst. 1 písm. f) a § 35bc ZDP — jediné místo,
 * kde žijí její podmínky.
 *
 * Slevu znovu zavedl zákon č. 180/2026 Sb. s účinností od 1. 1. 2027 (do konce
 * roku 2023 existovala ve starší podobě a zákon č. 349/2023 Sb. ji zrušil).
 * Částku nese ruleset daně z příjmů (`credit.student.monthly`); ruleset, který
 * klíč nemá, slevu nezná a výpočet ji fail-closed odmítne. Rok 2026 ji proto
 * spočítat nemůže, ani kdyby v evidenci nárok byl.
 *
 * § 35bc odst. 2: slevu lze uplatnit, jen pokud se poplatník
 *   a) soustavně připravuje na budoucí povolání, až do dovršení věku 26 let, nebo
 *   b) studuje v prezenční formě v doktorském studijním programu, až do dovršení
 *      věku 28 let.
 *
 * Oba případy se evidují jako samostatný druh slevy ({@see TaxCreditKind::Student},
 * {@see TaxCreditKind::StudentDoctoral}): liší se věkovou hranicí a JMHZ je
 * vykazuje příznakem doktoranda (atribut 10566). Částka je stejná.
 *
 * Měsíc nároku: § 35ba odst. 3 (od 1. 1. 2027 i pro písm. f) dává dvanáctinu za
 * každý měsíc, NA JEHOŽ POČÁTKU byly podmínky splněny. Věk se dovršuje počátkem
 * dne narozenin, takže kdo má 26. narozeniny 1. dne měsíce, v tom měsíci už nárok
 * nemá; kdo je má později v měsíci, tento měsíc ještě má. Pokyn GFŘ, který by
 * okamžik dovršení věku vykládal jinak, k 28. 10. 2026 nebyl vydaný.
 *
 * Doložení: § 38l odst. 2 písm. f) — potvrzení školy (nebo jiný doklad
 * o soustavné přípravě). Nese ho `evidence_reference` řádku nároku jako u ostatních
 * slev; interval nároku je interval PODMÍNEK, nikdy otevřený, protože věková
 * hranice ho vždy ukončí.
 *
 * Nerezident: § 35ba odst. 2 jmenuje pro daňového nerezidenta slevy podle písm. b)
 * až e) a g), písmeno f) ne. Nerezident tedy na slevu na studenta nárok nemá.
 */
final class StudentTaxCredit
{
    /** Klíč měsíční částky v rulesetu daně z příjmů. */
    public const RULESET_KEY = 'credit.student.monthly';

    /** Účinnost § 35bc ZDP ve znění zákona č. 180/2026 Sb. */
    public const EFFECTIVE_FROM = '2027-01-01';

    public const AGE_LIMIT_YEARS = 26;

    public const DOCTORAL_AGE_LIMIT_YEARS = 28;

    public static function isStudent(TaxCreditKind $kind): bool
    {
        return $kind === TaxCreditKind::Student || $kind === TaxCreditKind::StudentDoctoral;
    }

    public static function ageLimitYears(TaxCreditKind $kind): int
    {
        return match ($kind) {
            TaxCreditKind::Student => self::AGE_LIMIT_YEARS,
            TaxCreditKind::StudentDoctoral => self::DOCTORAL_AGE_LIMIT_YEARS,
            default => throw new InvalidArgumentException('Druh slevy není sleva na studenta.'),
        };
    }

    /**
     * Den, kdy poplatník věkovou hranici dovrší. Kdo se narodil 29. února, dovrší
     * ji v nepřestupném roce posledním únorovým dnem; na měsících nároku to nic
     * nemění (březen nárok nemá tak i tak).
     */
    public static function ageLimitReachedOn(string $birthDate, TaxCreditKind $kind): string
    {
        $birth = self::date($birthDate);
        $year = (int) $birth->format('Y') + self::ageLimitYears($kind);
        $month = (int) $birth->format('n');
        $day = min(
            (int) $birth->format('j'),
            (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'),
        );

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Byly na počátku měsíce splněné věkové podmínky? */
    public static function isAgeEligibleMonth(string $birthDate, TaxCreditKind $kind, string $monthStart): bool
    {
        self::date($monthStart);

        return substr($monthStart, 0, 7) . '-01' < self::ageLimitReachedOn($birthDate, $kind);
    }

    /**
     * Nejzazší `effective_to` intervalu nároku: poslední den posledního měsíce,
     * na jehož počátku věková hranice ještě nebyla dovršena.
     */
    public static function latestEffectiveTo(string $birthDate, TaxCreditKind $kind): string
    {
        $reached = new DateTimeImmutable(self::ageLimitReachedOn($birthDate, $kind));
        $lastMonth = $reached->format('j') === '1'
            ? $reached->modify('first day of previous month')
            : $reached->modify('first day of this month');

        return $lastMonth->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Kontrola intervalu nároku při zápisu do evidence. Vrací českou hlášku pro
     * uživatele, nebo `null`, když je interval v pořádku.
     */
    public static function intervalProblem(
        TaxCreditKind $kind,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?string $birthDate,
    ): ?string {
        if (!self::isStudent($kind)) {
            return null;
        }
        if ($effectiveFrom < self::EFFECTIVE_FROM) {
            return 'Slevu na studenta lze uplatnit až od 1. 1. 2027 (§ 35bc ZDP ve znění zákona č. 180/2026 Sb.).';
        }
        if ($birthDate === null || $birthDate === '') {
            return 'Slevu na studenta nelze zaevidovat bez data narození zaměstnance — podle něj se '
                . 'posuzuje věková hranice § 35bc odst. 2 ZDP.';
        }
        $latest = self::latestEffectiveTo($birthDate, $kind);
        if ($effectiveTo === null) {
            return sprintf(
                'Nárok na slevu na studenta musí mít konec — nejpozději %s (dovršení %d let).',
                $latest,
                self::ageLimitYears($kind),
            );
        }
        if ($effectiveTo > $latest) {
            return sprintf(
                'Nárok na slevu na studenta může trvat nejvýš do %s: %s zaměstnanec dovrší %d let '
                . 'a od měsíce, na jehož počátku je hranice dovršena, nárok nemá (§ 35bc odst. 2 '
                . 'a § 35ba odst. 3 ZDP).',
                $latest,
                self::ageLimitReachedOn($birthDate, $kind),
                self::ageLimitYears($kind),
            );
        }

        return null;
    }

    /**
     * Měsíční sleva na studenta ze zmrazeného rozpadu nároku na slevy
     * (`claimed_non_refundable_credit_breakdown` výsledku daně). Odsud ji má
     * vzít JMHZ: 10565 = výše slevy, 10566 = doktorský studijní program.
     *
     * `null` = sleva se v měsíci neuplatnila. Souběh obou druhů výpočet odmítne
     * (`student-credit-conflict`), do zmrazeného výsledku se tedy nedostane.
     *
     * @param array<array-key,mixed> $breakdown
     * @return array{amount_minor_units:int,doctoral:bool}|null
     */
    public static function fromCreditBreakdown(array $breakdown): ?array
    {
        foreach ([TaxCreditKind::Student, TaxCreditKind::StudentDoctoral] as $kind) {
            $amount = $breakdown[$kind->value] ?? null;
            if (is_int($amount) && $amount > 0) {
                return [
                    'amount_minor_units' => $amount,
                    'doctoral' => $kind === TaxCreditKind::StudentDoctoral,
                ];
            }
        }

        return null;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Datum musí být ve tvaru YYYY-MM-DD.');
        }

        return $parsed;
    }
}
