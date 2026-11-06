<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Zda poplatník podá nebo je povinen podat daňové přiznání (§ 38g).
 *
 * § 38ch odst. 1 věta druhá: „Roční zúčtování záloh a daňového zvýhodnění
 * NEPROVEDE plátce u poplatníka, který podá nebo je povinen podat přiznání
 * k dani." Není to doporučení — plátce zúčtování provést nesmí.
 *
 * Povinnost plyne mimo jiné z toho, že poplatník má jiné příjmy podle § 7 až 10
 * nad 20 000 Kč (za zdaňovací období 2027 nad 40 000 Kč a obecná hranice
 * § 38g odst. 1 je 100 000 Kč místo 50 000 Kč, zák. č. 180/2026 Sb.), není
 * daňovým rezidentem ČR a uplatňuje slevy podle § 35ba odst. 1 písm. b) až e)
 * (od roku 2027 i g), dostal doplatky mezd za minulá léta, nebo mu plátce
 * oznámil dlužnou částku podle § 38i odst. 5 písm. b). Aplikace to NEODVOZUJE —
 * o většině těch skutečností nic neví. Odpovídá na to člověk.
 */
enum AnnualSettlementFilingObligation: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case Required = 'required';
}
