<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

/**
 * Jak je u konkrétního pracovního vztahu sjednáno odměňování přesčasu a svátku.
 *
 * Zákon má u obou ustanovení jiné VÝCHOZÍ pravidlo a je to snadné splést:
 *
 *  - § 114 odst. 1 — výchozí je PŘÍPLATEK; náhradní volno musí být DOHODNUTO.
 *  - § 115 odst. 1 — výchozí je NÁHRADNÍ VOLNO; příplatek podle odst. 2 musí být
 *    DOHODNUT místo něj.
 *
 * Proto tady nejsou modely dva, ale jeden výčet použitý na obě ustanovení s tím,
 * že výchozí hodnota se liší podle druhu příplatku
 * ({@see PayrollSurchargePolicy::statutoryDefault()}).
 */
enum PayrollSurchargeCompensationMode: string
{
    /** Vyplácí se příplatek. */
    case Surcharge = 'surcharge';

    /** Místo příplatku se poskytuje náhradní volno. */
    case CompensatoryTimeOff = 'compensatory_time_off';

    /**
     * § 114 odst. 3 — mzda byla sjednána už s přihlédnutím k případné práci
     * přesčas, takže nepřísluší ani příplatek, ani náhradní volno. Zákon to
     * dovoluje jen do rozsahu 150 hodin ročně, u vedoucích zaměstnanců do
     * celého ročního rozsahu přesčasu; rozsah hlídá § 93 a jeho vyhodnocení
     * v {@see \MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitEvaluator},
     * ne tenhle výpočet.
     */
    case IncludedInWage = 'included_in_wage';
}
