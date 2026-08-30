<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

/** Jediný zdroj účetních prefixů a ochrany nákladového účtu hrubé mzdy. */
final class PayrollPostingAccountPolicy
{
    /** @var list<string> */
    public const GROSS_WAGE_PREFIXES = ['521', '522', '523'];

    /** @var list<string> */
    public const EMPLOYER_CONTRIBUTION_PREFIXES = ['524'];

    /** @var list<string> */
    public const SOCIAL_HEALTH_INSURANCE_PREFIXES = ['336'];

    /**
     * Daň ze závislé činnosti. Prefix je SYNTETIKA, takže pokrývá i rozpad
     * Ú-13 na zálohovou (342.100) a srážkovou (342.200) daň — reconciliace
     * i ochrana proti kolizní předkontaci fungují na obou analytikách stejně.
     *
     * @var list<string>
     */
    public const INCOME_TAX_PREFIXES = ['342'];

    /** @var list<string> */
    public const OTHER_DEDUCTION_PREFIXES = ['379'];

    /** @var list<string> */
    public const NET_WAGE_PREFIXES = ['331', '366'];

    /**
     * Protiúčet zápočtu čisté mzdy na účet společníka (365).
     *
     * Zápočet je čistě VNITŘNÍ překlasifikace závazku: 331/366 MD proti 365 D.
     * Mzdová strana o něm nic neví — kontrolní součty MZ-13 znají jen čistou
     * mzdu, ne způsob jejího vypořádání. Kdyby se 365 nechalo mimo kategorii
     * čisté mzdy, deník by byl o zápočet nižší než mzda a firma se zápočtem by
     * měla na kontrolní obrazovce TRVALÝ falešný rozdíl. Proto se 365 porovnává
     * SPOLU s 331/366 — uvnitř skupiny se zápočet vyruší — a samotná částka se
     * nezahazuje: vykazuje ji informativní kategorie `partner_settlement`.
     *
     * @var list<string>
     */
    public const PARTNER_SETTLEMENT_PREFIXES = ['365'];

    /**
     * Rezervované prefixy analytické dimenze srážek a exekucí.
     *
     * Sloupec `cost_center` deníku nese u srážek pseudonym odvozený z klíče
     * alokace (`MZ-SR-…`, `MZ-EX-…`). Reálný kód mzdové dimenze proto tyhle
     * prefixy mít NESMÍ — jinak by se středisko firmy v reconciliaci vydávalo
     * za srážku. Hlídá {@see \MyInvoice\Service\Payroll\Settings\PayrollDimensionService}.
     *
     * ⚠ NENÍ to saldokonto per oprávněný a nikdy nebylo: pseudonym se počítá ze
     * CELÉHO klíče alokace (`employee:{id}:deduction:…:settlement:…`), takže se
     * liší jak podle zaměstnance, tak podle vypořádacího koše. Dvě srážky
     * TÉHOŽ oprávněného u dvou zaměstnanců mají dvě různé hodnoty a sečíst je
     * nejde. Reálně tedy slouží jen k tomu, aby reconciliace odlišila srážku
     * od exekuce; oddělit salda umí až rozpad účtů z Ú-14
     * (379.100 / 379.200 / 379.300). Saldokonto per oprávněný pořád chybí —
     * identita oprávněného se do mzdového VÝSLEDKU vůbec nedostane, žije až
     * v platební vrstvě (`payroll_payment_liabilities.recipient_reference`).
     *
     * @var list<string>
     */
    public const RESERVED_DIMENSION_PREFIXES = ['MZ-SR-', 'MZ-EX-'];

    /**
     * Povinný příspěvek na spoření u rizikové práce.
     *
     * ZÁMĚRNĚ není mezi rezervovanými prefixy hrubé mzdy: 527 je běžný účet
     * zákonných sociálních nákladů (příspěvek na stravování a podobně) a
     * zakázat ho složkám by rozbil existující kontace. Slouží jen k tomu, aby
     * reconciliace uměla kategorii pojmenovat.
     *
     * @var list<string>
     */
    public const RISKY_SAVINGS_PREFIXES = ['527'];

    public static function assertGrossCostAccountIsUnambiguous(string $account): void
    {
        self::assertUnambiguous(
            $account,
            [
                self::EMPLOYER_CONTRIBUTION_PREFIXES,
                self::SOCIAL_HEALTH_INSURANCE_PREFIXES,
                self::INCOME_TAX_PREFIXES,
                self::OTHER_DEDUCTION_PREFIXES,
                self::NET_WAGE_PREFIXES,
                self::PARTNER_SETTLEMENT_PREFIXES,
            ],
            'Nákladový účet hrubé mzdy',
        );
    }

    /**
     * Nákladový účet pojistného zaměstnavatele (výchozí 524) nesmí spadnout do
     * ŽÁDNÉ jiné mzdové kategorie — ani do hrubé mzdy. Nastavením 521 nebo 331
     * by zápis sice prošel, ale reconciliace by kategorii nedokázala rozlišit
     * a firma by měla trvalý rozdíl, který nejde odstranit jinak než změnou
     * nastavení. Chyba proto patří k zadání, ne až k zaúčtování.
     */
    public static function assertEmployerInsuranceCostAccountIsUnambiguous(
        string $account,
    ): void {
        self::assertUnambiguous(
            $account,
            [
                self::GROSS_WAGE_PREFIXES,
                self::SOCIAL_HEALTH_INSURANCE_PREFIXES,
                self::INCOME_TAX_PREFIXES,
                self::OTHER_DEDUCTION_PREFIXES,
                self::NET_WAGE_PREFIXES,
                self::PARTNER_SETTLEMENT_PREFIXES,
            ],
            'Nákladový účet pojistného zaměstnavatele',
        );
    }

    /** Nese kód mzdové dimenze rezervovaný pseudonym srážky nebo exekuce? */
    public static function isReservedDimensionCode(string $code): bool
    {
        foreach (self::RESERVED_DIMENSION_PREFIXES as $prefix) {
            if (str_starts_with(strtoupper($code), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<list<string>> $reservedGroups */
    private static function assertUnambiguous(
        string $account,
        array $reservedGroups,
        string $label,
    ): void {
        $prefix = substr($account, 0, 3);
        foreach ($reservedGroups as $reservedPrefixes) {
            if (in_array($prefix, $reservedPrefixes, true)) {
                throw new \DomainException(
                    "{$label} {$account} je kolizní s jinou mzdovou kategorií.",
                );
            }
        }
    }
}
