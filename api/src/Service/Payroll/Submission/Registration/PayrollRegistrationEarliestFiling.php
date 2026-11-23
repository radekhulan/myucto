<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Nejbližší den, kdy jde přihlášku zaměstnance k budoucímu nástupu připravit
 * a podat, a věta, kterou se to účetní dozví.
 *
 * Přihlásit jde nejdřív osm dnů před nástupem (§ 19 odst. 1 písm. a) zákona
 * č. 323/2025 Sb.; EDV REGZEC, ID 10223), a to částečně (PREZEC P1) i plně
 * (REGZEC A1): obě cesty mají totéž okno. Nástup od 1. 1. 2027 se navíc hlásí
 * formulářem REGZEC27, který ostrá ČSSZ přijímá od 23. 12. 2026. Platí
 * pozdější z obou dnů. Dřív každá kontrola hlásila svůj den a radila cestu,
 * kterou zavírala ta druhá.
 */
final class PayrollRegistrationEarliestFiling
{
    /** Stejná hodnota jako okno v {@see PayrollEmployeeRegistrationDeadlinePolicy}. */
    public const DAYS_BEFORE_START = 8;

    public static function earliestOn(string $startOn): string
    {
        $window = self::date($startOn)
            ->modify('-' . self::DAYS_BEFORE_START . ' days')
            ->format('Y-m-d');
        if ($startOn < JmhzSpecVersion::FIRST_2027_EVENT_DATE) {
            return $window;
        }

        return max($window, JmhzSpecVersion::FIRST_REGZEC27_ACCEPTED_ON);
    }

    public static function tooEarly(string $startOn, string $today): bool
    {
        return $today < self::earliestOn($startOn);
    }

    /**
     * Jedno datum, co jde udělat už teď a kde se vztah připomene.
     */
    public static function tooEarlyMessage(string $startOn): string
    {
        $start = self::date($startOn);

        return 'Přihlášku zaměstnance s nástupem ' . $start->format('j. n. Y')
            . ' půjde připravit a podat nejdřív '
            . self::date(self::earliestOn($startOn))->format('j. n. Y')
            . ', částečnou (PREZEC) i plnou (REGZEC): ČSSZ ji přijímá nejdřív osm'
            . ' dnů před nástupem a podat ji je potřeba před nástupem.'
            . ($startOn < JmhzSpecVersion::FIRST_2027_EVENT_DATE
                ? ''
                : ' Formulář REGZEC27 pro nástupy od 1. 1. 2027 přijímá ostrá ČSSZ'
                    . ' už od 23. 12. 2026, rozhoduje pozdější z obou dnů.')
            . ' Už teď můžete vyplnit a uložit profil registrace A1 na kartě'
            . ' pracovního vztahu, podání se pak jen připraví a odešle. Vztah'
            . ' najdete také v Mzdy → Podání → Přihlášky k podání (A1), kde'
            . ' u něj je den, od kterého půjde podat (období přehledu nastavte'
            . ' tak, aby zahrnulo den nástupu).';
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Den nástupu musí být platné datum RRRR-MM-DD.');
        }

        return $date;
    }
}
