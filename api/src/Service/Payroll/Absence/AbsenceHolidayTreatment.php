<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Jak se se svátkem uvnitř absence naloží.
 *
 * Svátek není pro absence neutrální den a nakládá se s ním pro každý druh
 * absence jinak, takže volající musí říct, co po výpočtu chce — implicitní
 * chování by u jednoho z druhů absence bylo vždycky špatně.
 */
enum AbsenceHolidayTreatment
{
    /** Svátek se neřeší (druhy absencí, kde na něj zákon nereaguje). */
    case Ignore;

    /**
     * § 219 odst. 1 ZP — připadne-li svátek na den, kdy by zaměstnanec jinak
     * pracoval, dovolená se za něj nečerpá.
     */
    case ExcludeFromLeave;

    /**
     * § 192 odst. 1 ZP ve spojení s § 348 odst. 1 písm. d) ZP — za svátek uvnitř
     * okna náhrady mzdy při DPN náhrada náleží, i když na něj směna naplánovaná
     * není.
     */
    case CompensateSickness;
}
