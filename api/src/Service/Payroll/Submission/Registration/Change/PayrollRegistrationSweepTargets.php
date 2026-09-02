<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Firmy, u kterých má detekce registračních změn vůbec co dělat.
 *
 * Mzdy jsou opt-in per firma (migrace 1290 zavedla `supplier.payroll_enabled`,
 * migrace 1186 pak stav plného modulu). Instalace, kde mzdy nikdo nezapnul,
 * nesmí noční úlohu zdržovat ani jí vyrábět chyby — proto se seznam cílů
 * počítá JEDNÍM dotazem předem, ne až selháním uvnitř detekce.
 *
 * Implementaci drží {@see \MyInvoice\Repository\Payroll\PayrollModuleStateRepository},
 * rozhraní tu je proto, aby šel průchod otestovat bez databáze.
 */
interface PayrollRegistrationSweepTargets
{
    /**
     * Firmy se zapnutými mzdami, vzestupně podle id.
     *
     * @return list<int>
     */
    public function payrollEnabledSupplierIds(): array;
}
