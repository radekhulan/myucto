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

    /** @var list<string> */
    public const INCOME_TAX_PREFIXES = ['342'];

    /** @var list<string> */
    public const OTHER_DEDUCTION_PREFIXES = ['379'];

    /** @var list<string> */
    public const NET_WAGE_PREFIXES = ['331', '366'];

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
        $prefix = substr($account, 0, 3);
        foreach ([
            self::EMPLOYER_CONTRIBUTION_PREFIXES,
            self::SOCIAL_HEALTH_INSURANCE_PREFIXES,
            self::INCOME_TAX_PREFIXES,
            self::OTHER_DEDUCTION_PREFIXES,
            self::NET_WAGE_PREFIXES,
        ] as $reservedPrefixes) {
            if (in_array($prefix, $reservedPrefixes, true)) {
                throw new \DomainException(
                    "Nákladový účet hrubé mzdy {$account} je kolizní s jinou mzdovou kategorií.",
                );
            }
        }
    }
}
