<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunTransitionContext
{
    public function __construct(
        public int $actorUserId,
        public ?int $calculatedBy = null,
        public ?int $reviewedBy = null,
        public int $blockerCount = 0,
        public int $unresolvedOverrideCount = 0,
        public bool $hasImmutableSnapshot = false,
        public bool $hasCalculatedResult = false,
        public bool $hasPostingBatch = false,
        public bool $hasPaymentBatch = false,
        /*
         * Pravidlo čtyř očí tady bývalo jako `fourEyesRequired`. Uzavřené
         * produktové rozhodnutí zní: nikdy se nezavede. Řada firem má jedinou
         * účetní, takže samostatný krok „Zkontrolovat" byl prázdný obřad před
         * „Schválit" — workflow ho stejně nikdy neporovnávalo s JINOU osobou,
         * jen kontrolovalo, že je vyplněný. Příznak byl proto natvrdo `false`
         * ve všech vrstvách a nešel zapnout ani z databáze; zůstávala po něm
         * jen podmínka, kterou nikdy nikdo nesplnil. Stopa po kontrole se ale
         * zapisuje dál — schválení doplní `reviewed_by` samo, viz
         * {@see PayrollRunCommandService}.
         */
        public ?string $reason = null,
        /*
         * Obnova podkladů vrací běh na úroveň „k přepočtu". Opravná revize
         * zůstává opravnou (`reopened`), řádná se vrací do `inputs_locked`.
         */
        public bool $correctionRevision = false,
        /*
         * Kolik podkladů se od vzniku snímku změnilo (nové nebo změněné
         * schválené vstupy, nepřítomnosti, vztahy, zákonná evidence). Schválení
         * s nenulovým počtem by revizi uzavřelo bez nich.
         */
        public int $staleSourceCount = 0,
        /*
         * Má běh schválenou revizi? Zrušení takového běhu (opravné revize nad
         * schváleným, zaúčtovaným či uzavřeným během) ruší schválený základ
         * a vyžaduje důvod stejně jako vyžádání opravy.
         */
        public bool $hasApprovedRevision = false,
    ) {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel přechodu musí být platný.');
        }
        if ($blockerCount < 0 || $unresolvedOverrideCount < 0 || $staleSourceCount < 0) {
            throw new \InvalidArgumentException('Počet validačních problémů nesmí být záporný.');
        }
    }
}
