<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Jestli a kudy umí aplikace odeslat jednu agendu.
 *
 * `reason` je vyplněný PRÁVĚ TEHDY, když odeslat nejde — je to věta pro
 * účetní, ne kód chyby. Fronta odchozích podání ji ukazuje u řádku, protože
 * mlčky vynechaná položka je horší než položka s důvodem.
 */
final readonly class PayrollDispatchCapability
{
    public function __construct(
        public string $agendaCode,
        public string $mode,
        public ?string $reason,
        /**
         * Druhý rovnocenný kanál, pokud existuje (JMHZ jde VREP i datovkou).
         * Fronta ho jen HLÁSÍ; odesílá se primárním, aby se dvě cesty
         * nerozešly v tom, kde se hledá odpověď úřadu.
         */
        public ?string $alternateMode = null,
        /** Kanál je doložený jen pro ostré prostředí (schránky pojišťoven). */
        public bool $productionOnly = false,
    ) {
        if (($mode === PayrollDispatchCapabilityCatalog::MODE_NONE)
            !== ($reason !== null)
        ) {
            throw new \LogicException(
                'Neodesílatelná agenda musí mít důvod a odesílatelná ho mít'
                    . ' nesmí: ' . $agendaCode,
            );
        }
    }

    public function isDispatchable(): bool
    {
        return $this->mode !== PayrollDispatchCapabilityCatalog::MODE_NONE;
    }
}
