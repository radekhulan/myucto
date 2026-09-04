<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Co se dá vyčíst z VĚCI datové zprávy, kterou ČSSZ pošle jako odpověď.
 *
 * Protokol ČSSZ v1.47 (str. 24) slibuje tvar
 * `"ČSSZ - Odpověď na e-Podání. [{classname}-{correlationId}-{dmId}]"`, ale
 * pro JMHZ chodí ve skutečnosti jiné věty a hranatá závorka v nich není:
 *
 * - `Odpověď na e-Podání z DZ 1761891234 - podání JMH VS4442070407 "…" bylo přijato`
 * - `Protokol o kompletnosti podání JMH VS4442070407 08/2026 - Hlášení je částečně přijato`
 * - `Odpověď na ePodání JMH Protokol o kompletnosti VS4442070407-05/2026-Hlášení je zpracováno a je úplné`
 *
 * Věc je nepodepsaný text a nic tu neprohlašuje za ověřené — je to jen vodítko,
 * ke KTERÉMU podání se má protokol zkusit přiřadit. Rozhodne až obsah přílohy
 * ({@see JmhzDeliveredProtocolVerifier}), který nese `idPodani`, variabilní
 * symbol i období a porovnává se se zmrazenou datovou větou.
 */
final readonly class JmhzIsdsProtocolSubject
{
    public function __construct(
        /** `dmId` NAŠÍ odeslané zprávy, když ho věc uvádí. */
        public ?string $originalMessageId,
        public ?string $variableSymbol,
        public ?int $periodMonth,
        public ?int $periodYear,
    ) {}

    public function isEmpty(): bool
    {
        return $this->originalMessageId === null
            && $this->variableSymbol === null
            && $this->periodMonth === null;
    }
}
