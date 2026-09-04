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
        /**
         * Dorazí od úřadu strojově čitelný výsledek zpracování?
         *
         * U ČSSZ ano: na e-Podání odpoví protokolem, který aplikace načte
         * a podle něj podání uzavře. U zdravotních pojišťoven NE — doložený
         * strojový protokol neexistuje, takže po odeslání nepřijde nic než
         * doručenka ({@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsInboxProcessor}).
         *
         * Bez tohohle rozlišení uvízne přehled pojišťovně navždy ve stavu
         * „čeká na výsledek podání": ve frontě k odeslání, s nabídkou zahodit
         * a podat znovu, a se lhůtou, která se nikdy neuzavře. Čeká se přitom
         * na něco, co nepřijde. Tam, kde je tenhle příznak `false`, smí
         * povinnost uzavřít ČLOVĚK
         * ({@see PayrollSubmissionSettlementService}).
         */
        public bool $authorityReportsResult = true,
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
