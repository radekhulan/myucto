<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;

/**
 * Jediná odpověď na otázku „pro který rok smí mzdový modul počítat".
 *
 * Podporovaný rok NENÍ literál v kódu — je to rok, pro který registry drží
 * účinný (nezneplatněný) ruleset dané domény. Ruleset může přibýt z administrace
 * (viz {@see PayrollRulesetRegistry}), takže se rok zpřístupní bez nasazení nové
 * verze aplikace, a naopak: rok bez rulesetu **selže**, nikdy se nedopočítává.
 */
final class PayrollRulesetYearCoverage
{
    /**
     * Domény, bez kterých se mzdový rok nedá spočítat.
     *
     * Bydlí tady, a ne ve {@see \MyInvoice\Service\Payroll\SupportMatrix}, kde
     * dřív stály: ptá se na ně i výhled pokrytí příštích let
     * ({@see PayrollRulesetYearOutlook}) a dvě kopie téhož seznamu by se
     * rozešly přesně v okamžiku, kdy by na pokrytí začala záviset nová doména —
     * matice by rok přestala hlásit jako podporovaný, ale výhled by na jeho
     * chybějící sadu neupozornil.
     *
     * Cestovní náhrady, exekuční srážky, lhůty, číselníky ani podání v seznamu
     * NEJSOU: jejich chybějící sada zablokuje jen svou vlastní agendu, ne výpočet
     * mzdy jako takový.
     *
     * @var non-empty-list<PayrollRulesetDomain>
     */
    public const CALCULATION_CRITICAL_DOMAINS = [
        PayrollRulesetDomain::IncomeTax,
        PayrollRulesetDomain::SocialInsurance,
        PayrollRulesetDomain::HealthInsurance,
        PayrollRulesetDomain::EmploymentThresholds,
        PayrollRulesetDomain::CompensationAverages,
    ];

    public static function coversDate(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
        string $date,
    ): bool {
        self::assertDateFormat($date);
        foreach (self::intervals($rulesets, $domain) as $interval) {
            if ($date >= $interval['from'] && $date <= $interval['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rok je pokrytý jen tehdy, když na něj účinné rulesety navazují bez díry
     * od 1. 1. do 31. 12. Částečné pokrytí by znamenalo, že část roku spadne do
     * prázdna až při konkrétním výpočtu — což je přesně ta tichá chyba, kterou
     * má tahle brána vyloučit.
     */
    public static function coversYear(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
        int $year,
    ): bool {
        if ($year < 1000 || $year > 9999) {
            return false;
        }
        $cursor = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-12-31', $year);
        foreach (self::intervals($rulesets, $domain) as $interval) {
            if ($interval['from'] > $cursor) {
                return false;
            }
            if ($interval['to'] < $cursor) {
                continue;
            }
            if ($interval['to'] >= $end) {
                return true;
            }
            $cursor = (new \DateTimeImmutable($interval['to']))
                ->modify('+1 day')
                ->format('Y-m-d');
        }

        return false;
    }

    /** @return list<int> */
    public static function years(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
    ): array {
        $candidates = [];
        foreach (self::intervals($rulesets, $domain) as $interval) {
            $from = (int) substr($interval['from'], 0, 4);
            $to = (int) substr($interval['to'], 0, 4);
            for ($year = $from; $year <= $to; $year++) {
                $candidates[$year] = true;
            }
        }
        $years = [];
        foreach (array_keys($candidates) as $year) {
            if (self::coversYear($rulesets, $domain, $year)) {
                $years[] = $year;
            }
        }
        sort($years);

        return $years;
    }

    /**
     * Roky pokryté všemi zadanými doménami současně — mzdový rok bez jediné
     * z nich není spočitatelný.
     *
     * @param non-empty-list<PayrollRulesetDomain> $domains
     * @return list<int>
     */
    public static function commonYears(PayrollRulesetProvider $rulesets, array $domains): array
    {
        $common = null;
        foreach ($domains as $domain) {
            $years = self::years($rulesets, $domain);
            $common = $common === null ? $years : array_values(array_intersect($common, $years));
        }
        $result = $common ?? [];
        sort($result);

        return $result;
    }

    public static function assertYear(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
        int $year,
    ): void {
        if (self::coversYear($rulesets, $domain, $year)) {
            return;
        }

        throw new InvalidArgumentException(self::missing((string) $year, $domain));
    }

    public static function assertDate(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
        string $date,
    ): void {
        if (self::coversDate($rulesets, $domain, $date)) {
            return;
        }

        throw new InvalidArgumentException(self::missing($date, $domain));
    }

    private static function missing(string $period, PayrollRulesetDomain $domain): string
    {
        return "Pro {$period} není účinný mzdový ruleset domény {$domain->value};"
            . ' doplň ho v administraci mzdových rulesetů — bez něj mzdový modul nepočítá.';
    }

    /**
     * ÚČINNÉ intervaly domény, seřazené a bez překryvů (překryv zakazuje už
     * {@see PayrollRulesetProvider}).
     *
     * Účinná je jen verze ve stavu `active`. Do 8/2026 se sem počítalo cokoli
     * kromě `superseded`, což je fail-open: rozpracovaná sada na příští rok
     * (`draft`/`reviewed`, tedy přesně to, co v administraci vznikne jako první)
     * by rok rozsvítila v {@see \MyInvoice\Service\Payroll\SupportMatrix} jako
     * podporovaný, ačkoli by `forCalculation()` na témže roce fail-closed
     * selhalo. Pokrytí a spočitatelnost si musí odpovídat, jinak matice lže.
     *
     * @return list<array{from:string,to:string}>
     */
    private static function intervals(
        PayrollRulesetProvider $rulesets,
        PayrollRulesetDomain $domain,
    ): array {
        $intervals = [];
        foreach ($rulesets->versions() as $version) {
            if (
                $version->domain !== $domain
                || $version->lifecycle !== PayrollRulesetLifecycle::Active
            ) {
                continue;
            }
            $intervals[] = ['from' => $version->effectiveFrom, 'to' => $version->effectiveTo];
        }
        usort(
            $intervals,
            static fn (array $left, array $right): int => $left['from'] <=> $right['from'],
        );

        return $intervals;
    }

    private static function assertDateFormat(string $value): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Datum musí být ve tvaru YYYY-MM-DD.');
        }
    }
}
