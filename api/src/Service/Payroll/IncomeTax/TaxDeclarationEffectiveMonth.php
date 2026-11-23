<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\PayrollApprovedPeriodFreeze;

/**
 * První měsíc, za který plátce přihlédne k prohlášení poplatníka podepsanému
 * k danému dni (§ 38k ZDP). Jediné místo, které z data podpisu odvozuje
 * začátek věty prohlášení; zákonná evidence pak vede věty po celých měsících
 * a výpočet je čte k prvnímu dni měsíce.
 *
 * § 38k odst. 4 ZDP: plátce přihlédne ke slevě, „učiní-li poplatník
 * prokazatelně do 30 dnů po vstupu do zaměstnání a každoročně nejpozději do
 * 15. února na příslušné zdaňovací období prohlášení“.
 * § 38k odst. 1 ZDP: „Při nástupu do zaměstnání je lhůta dodržena, prokáže-li
 * poplatník tyto skutečnosti do 30 dnů ode dne nástupu do zaměstnání.“
 * § 38k odst. 7 ZDP: „… neučiní-li prohlášení podle odstavce 4 ve stanovené
 * lhůtě, přihlédne k nim plátce počínaje měsícem následujícím po měsíci,
 * v němž … prokazatelně učiní prohlášení podle odstavce 4.“
 *
 * Z toho plyne pořadí pravidel:
 *  1. podpis nejpozději 30. den po nástupu (i před nástupem) = od měsíce
 *     nástupu, i když se podepsalo až v následujícím měsíci;
 *  2. podpis před koncem roční lhůty (do 15. 2. zdaňovacího roku, nebo už
 *     v předchozím roce) = od ledna;
 *  3. jinak od měsíce následujícího po měsíci podpisu.
 *
 * Měsíce uzavřené schválenou mzdou se nikdy nepřepočítávají: začátek se
 * posune za hranici zmrazení a slevu za ty měsíce dorovná roční zúčtování
 * (§ 38k odst. 7 věta druhá), proto `annual_settlement`.
 */
final class TaxDeclarationEffectiveMonth
{
    /** Lhůta po nástupu do zaměstnání (§ 38k odst. 1 a 4). */
    public const ENTRY_WINDOW_DAYS = 30;

    /** Roční lhůta (§ 38k odst. 4): do 15. února zdaňovacího roku. */
    public const ANNUAL_DEADLINE = '02-15';

    public const BASIS_EMPLOYMENT_START = 'employment_start';
    public const BASIS_YEAR_START = 'year_start';
    public const BASIS_FOLLOWING_MONTH = 'following_month';
    public const BASIS_AFTER_FROZEN_PERIOD = 'after_frozen_period';

    public const BASES = [
        self::BASIS_EMPLOYMENT_START,
        self::BASIS_YEAR_START,
        self::BASIS_FOLLOWING_MONTH,
        self::BASIS_AFTER_FROZEN_PERIOD,
    ];

    /**
     * @param list<string> $employmentStarts skutečné dny nástupu vztahů osoby
     *     u tohoto plátce (bez nenastoupených)
     * @param ?string $frozenThrough poslední den uzavřený schválenou mzdou
     * @return array{
     *     from:?string,
     *     legal_from:?string,
     *     basis:string,
     *     entry:?string,
     *     annual_settlement:bool
     * } `from` je null, když do zdaňovacího roku nepatří žádný měsíc (celý
     *     zbytek roku je uzavřený, nebo prohlášení platí až od dalšího roku)
     */
    public static function resolve(
        int $taxYear,
        string $signedOn,
        array $employmentStarts,
        ?string $frozenThrough = null,
    ): array {
        $yearStart = sprintf('%04d-01-01', $taxYear);
        $yearEnd = sprintf('%04d-12-31', $taxYear);
        $entry = self::entry($employmentStarts, $signedOn);

        if ($entry !== null) {
            $legal = max($yearStart, self::monthStart($entry));
            $basis = self::BASIS_EMPLOYMENT_START;
        } elseif ($signedOn <= sprintf('%04d-%s', $taxYear, self::ANNUAL_DEADLINE)) {
            $legal = $yearStart;
            $basis = self::BASIS_YEAR_START;
        } else {
            $legal = (new DateTimeImmutable($signedOn))
                ->modify('first day of next month')
                ->format('Y-m-d');
            $basis = self::BASIS_FOLLOWING_MONTH;
        }
        if ($legal > $yearEnd) {
            return [
                'from' => null,
                'legal_from' => null,
                'basis' => $basis,
                'entry' => $entry,
                'annual_settlement' => false,
            ];
        }

        $from = PayrollApprovedPeriodFreeze::firstOpenMonth($legal, $frozenThrough);
        $moved = $from !== $legal;

        return [
            'from' => $from > $yearEnd ? null : $from,
            'legal_from' => $legal,
            'basis' => $moved ? self::BASIS_AFTER_FROZEN_PERIOD : $basis,
            'entry' => $entry,
            'annual_settlement' => $moved,
        ];
    }

    /**
     * První měsíc prohlášení jen ze dne podpisu, pro formulář zákonné evidence.
     *
     * Formulář dřív předvyplnil „Platí od" prvním neuzavřeným měsícem a datum
     * podpisu ho nezměnilo, takže prohlášení podepsané v lednu do 30 dnů po
     * prosincovém nástupu začínalo až v únoru. Tady se zdaňovací rok určí sám:
     * podpis ve lhůtě po nástupu patří roku nástupu (odst. 1 a 4), jinak roku
     * podpisu; podpis po roční lhůtě, který už do svého roku nezasáhne (odst. 7,
     * prosinec), platí od ledna roku následujícího. Pravidlo samo zůstává
     * v {@see self::resolve()}, nic se tu neopisuje.
     *
     * `legal_from` je měsíc podle zákona bez ohledu na schválené mzdy,
     * `open_from` první měsíc od něj, který ještě není uzavřený schválenou
     * mzdou (shodný, není-li co uzavřeného). Liší-li se, je `frozen` true:
     * za uzavřené měsíce slevu dorovná oprava běhu nebo roční zúčtování
     * (§ 38k odst. 7 věta druhá).
     *
     * @param list<string> $employmentStarts skutečné dny nástupu vztahů osoby
     * @return array{
     *     tax_year:int,
     *     legal_from:string,
     *     basis:string,
     *     entry:?string,
     *     open_from:string,
     *     frozen:bool
     * }
     */
    public static function forSignature(
        string $signedOn,
        array $employmentStarts,
        ?string $frozenThrough = null,
    ): array {
        $taxYear = (int) substr(self::entry($employmentStarts, $signedOn) ?? $signedOn, 0, 4);
        $resolved = self::resolve($taxYear, $signedOn, $employmentStarts);
        if ($resolved['legal_from'] === null) {
            $resolved = self::resolve(++$taxYear, $signedOn, $employmentStarts);
        }
        $legal = (string) $resolved['legal_from'];
        $open = PayrollApprovedPeriodFreeze::firstOpenMonth($legal, $frozenThrough);

        return [
            'tax_year' => $taxYear,
            'legal_from' => $legal,
            'basis' => $resolved['basis'],
            'entry' => $resolved['entry'],
            'open_from' => $open,
            'frozen' => $open !== $legal,
        ];
    }

    /**
     * Nástup, ke kterému se podpis vztahuje, je-li podpis v lhůtě 30 dnů.
     *
     * Rozhoduje poslední nástup nejpozději v den podpisu: osoba, která u plátce
     * pracuje déle než 30 dní, podepisuje jako stávající zaměstnanec, i když má
     * domluvený další vztah později. Podpis před vůbec prvním nástupem (nikdo
     * nenastoupil do dne podpisu) se váže k nejbližšímu budoucímu nástupu.
     *
     * @param list<string> $employmentStarts
     */
    public static function entry(array $employmentStarts, string $signedOn): ?string
    {
        $past = null;
        $future = null;
        foreach ($employmentStarts as $start) {
            if ($start <= $signedOn) {
                $past = $past === null ? $start : max($past, $start);
            } else {
                $future = $future === null ? $start : min($future, $start);
            }
        }
        if ($past === null) {
            return $future;
        }
        $deadline = (new DateTimeImmutable($past))
            ->modify(sprintf('+%d days', self::ENTRY_WINDOW_DAYS))
            ->format('Y-m-d');

        return $signedOn <= $deadline ? $past : null;
    }

    /**
     * Rozpor věty prohlášení se zákonem pro editor (nic neblokuje).
     *
     * @param list<string> $employmentStarts
     * @return array{legal_from:?string,basis:string,warning:?string}
     *     `earlier_than_allowed` = věta začíná dřív, než zákon dovoluje;
     *     `later_than_entitled` = poplatník má nárok už od dřívějšího měsíce
     */
    public static function check(string $effectiveFrom, string $signedOn, array $employmentStarts): array
    {
        $resolved = self::resolve((int) substr($effectiveFrom, 0, 4), $signedOn, $employmentStarts);
        $legal = $resolved['legal_from'];
        $warning = match (true) {
            $legal === null || $effectiveFrom < $legal => 'earlier_than_allowed',
            $effectiveFrom > $legal => 'later_than_entitled',
            default => null,
        };

        return ['legal_from' => $legal, 'basis' => $resolved['basis'], 'warning' => $warning];
    }

    private static function monthStart(string $date): string
    {
        return substr($date, 0, 8) . '01';
    }
}
