<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;

/**
 * Druhý znak kódu ELDP „D": pojištěnec výdělečně činný po splnění podmínky
 * nároku na starobní důchod podle § 29 odst. 1 nebo 3 zákona č. 155/1995 Sb.,
 * tj. po dovršení důchodového věku (ELDP12 Zadání a kontroly údajů v1.04,
 * údaj 20; Číselníky ELDP12 v1.03; číselník JMHZ CIS Kód ELDP, ID 10240).
 * Poživatel předčasného starobního důchodu (§ 31) kód D před dovršením věku
 * nemá: rozšíření D na předčasný důchod znal jen číselník ELDP09 (roky 2009
 * až 2011) a od roku 2012 se u něj odečítané doby neuplatňují (Všeobecné
 * zásady ELDP od 1. 1. 2012); doba jeho činnosti se přičítá celá (§ 36
 * odst. 3 zákona č. 155/1995 Sb.).
 *
 * Jediné místo pravidla pro oba výstupy, které kód ELDP nesou: roční
 * evidenční list ({@see EldpAnnualStatementBuilder}) i měsíční hlášení JMHZ
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder}).
 * Číselník je pro ně společný, takže stejná osoba nemůže mít v listu a v hlášení
 * různý kód.
 *
 * Den, od kterého kód D platí, je den dovršení důchodového věku
 * (`pension_age_reached_on`) ze zákonné evidence osoby
 * ({@see \MyInvoice\Service\Payroll\Pension\PayrollPensionStatus}). Bez něj
 * se kód nemění a zůstává „++".
 */
final class EldpPensionAgeCode
{
    /** Interval pojištění leží celý před dnem, od kterého platí kód D. */
    public const PLAIN = 'plain';

    /** Interval pojištění leží celý v době, na kterou se kód D vztahuje. */
    public const PENSION_AGE = 'pension_age';

    /** Kód D začíná uprostřed intervalu; vyměřovací základ by bylo nutné rozdělit. */
    public const MID_INTERVAL = 'mid_interval';

    /**
     * Den, od kterého nese činnost kód D: dovršení důchodového věku. Datum
     * přiznání předčasného starobního důchodu (`early_pension_from`) kód D
     * nezakládá.
     *
     * @param array{pension_age_reached_on?:?string,early_pension_from?:?string} $pension
     */
    public static function codeFrom(array $pension): ?string
    {
        return $pension['pension_age_reached_on'] ?? null;
    }

    /**
     * Kam v intervalu pojištění (včetně krajních dnů) padá den, od kterého
     * platí kód D.
     *
     * @return self::PLAIN|self::PENSION_AGE|self::MID_INTERVAL
     */
    public static function placement(?string $codeFrom, string $intervalFrom, string $intervalTo): string
    {
        if ($codeFrom === null || $intervalTo < $codeFrom) {
            return self::PLAIN;
        }

        return $intervalFrom >= $codeFrom ? self::PENSION_AGE : self::MID_INTERVAL;
    }

    /**
     * Vedou se v intervalu odečtené doby (údaj 40 ELDP, JMHZ 10375 a rozpad
     * 10462 až 10469)?
     *
     * U pojištěnce, který důchodový věk dovršil nejpozději prvním dnem
     * intervalu (§ 38 odst. 4 písm. h) zákona č. 582/1991 Sb.: doby „ode dne
     * dosažení věku" pro zvýšení procentní výměry), s výjimkou poživatele
     * starobního důchodu v plné výši po roce 2024. Všeobecné zásady ELDP
     * omezují odečtené doby na pojištěnce, který „po 31. 12. 2024 nepobírá
     * plný starobní důchod"; za roky do 2024 je vedl i pracující poživatel
     * důchodu (Metodická pomůcka ELDP, příklad 8). Měsíc, od kterého výplata
     * plného důchodu náleží, je ještě v listu a odečtené doby nese
     * (Metodická pomůcka, příklad 8a). Předčasný starobní důchod sám odečtené
     * doby neruší ani nezakládá: před dovršením věku kód D není, po něm
     * rozhoduje výplata v plné výši (§ 37 odst. 2 zákona č. 155/1995 Sb.).
     *
     * Logické testy ELDP12 39, 47 a 48 chtějí u kódu D odečtené doby aspoň ve
     * výši vyloučených dob a dnů měsíců „X"; odečtené doby jsou podle zásad
     * jejich nadmnožinou, takže řádek s kódem D je vede vždy.
     *
     * @param array{
     *   pension_age_reached_on?:?string,early_pension_from?:?string,
     *   full_pension_paid_from?:?string
     * } $pension data RRRR-MM-DD, `full_pension_paid_from` měsíc RRRR-MM
     */
    public static function deductsPeriods(array $pension, string $intervalFrom, string $intervalTo): bool
    {
        $reachedOn = $pension['pension_age_reached_on'] ?? null;
        if ($reachedOn === null || $reachedOn > $intervalFrom) {
            return false;
        }
        $fullPaidFrom = $pension['full_pension_paid_from'] ?? null;

        return (int) substr($intervalTo, 0, 4) < EldpAnnualStatementBuilder::FULL_PENSION_EXCLUSION_FROM_YEAR
            || PayrollPensionStatus::eldpKeptInMonth($fullPaidFrom, $intervalFrom);
    }

    /**
     * Kód ELDP s druhým znakem „D" ("1++" => "1D+"). Druh činnosti na začátku
     * a třetí znak na konci zůstávají; druhý znak je vždy předposlední.
     */
    public static function withPensionAge(string $code): string
    {
        return substr($code, 0, -2) . 'D' . substr($code, -1);
    }
}
