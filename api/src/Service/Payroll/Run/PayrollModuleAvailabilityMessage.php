<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\PayrollPeriodLabel;

/**
 * Hlášky, proč za období nejde založit mzdový běh, i s tím, kde se to napraví.
 *
 * Sdílí je založení běžného i převzatého běhu, aby účetní dostala na stejnou
 * příčinu stejný pokyn. Formulace drží kontrola před během
 * ({@see PayrollRunReadinessService::moduleStartFinding()}).
 */
final class PayrollModuleAvailabilityMessage
{
    public const PAYROLL_DISABLED = 'Firma nemá vedení mezd zapnuté. Zapněte „Vést mzdy“ v Nastavení firmy '
        . 'a potom v Mzdy → Přehled mezd aktivujte mzdovou agendu.';

    public static function moduleInactive(?string $status): string
    {
        return $status === 'suspended'
            ? 'Mzdová agenda firmy je pozastavená, nový mzdový běh proto nejde založit. Stav agendy '
                . 'a další krok najdete v Mzdy → Přehled mezd.'
            : 'Plný mzdový modul firmy není aktivní. Otevřete Mzdy → Přehled mezd a v části '
                . '„Aktivovat mzdovou agendu“ zvolte první mzdové období a zahajte nastavení.';
    }

    public static function periodBeforeStart(string $periodStart, string $startPeriod): string
    {
        return sprintf(
            'Období %s předchází prvnímu mzdovému období, které vedete v MyÚčtu (%s), mzdový běh '
                . 'za něj proto nejde založit. Měsíce zpracované v předchozím programu patří do '
                . 'počátečních stavů kumulací u zaměstnanců. Pokud má tenhle měsíc spočítat MyÚčto, '
                . 'změňte první mzdové období v Mzdy → Přehled mezd.',
            PayrollPeriodLabel::monthYear($periodStart),
            PayrollPeriodLabel::monthYear($startPeriod),
        );
    }
}
