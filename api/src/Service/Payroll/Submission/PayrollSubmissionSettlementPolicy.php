<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Smí účetní prohlásit povinnost za vyřízenou, když úřad neodpoví?
 *
 * Pravidlo je ZÁMĚRNĚ oddělené od {@see PayrollSubmissionSettlementService}:
 * ptá se na ně jak samotné uzavření, tak přehled podání, který podle něj
 * rozhoduje, jestli u řádku svítí tlačítko. Kdyby si každý postavil vlastní
 * podmínku, nabízela by obrazovka akci, kterou server odmítne — nebo ji
 * naopak zatajila.
 *
 * Brána je úzká a fail-closed. Projde jen agenda, u které je DOLOŽENÉ, že
 * úřad výsledek zpracování neposílá; jinde by se tudy odklikl měsíc, o kterém
 * úřad teprve rozhoduje.
 */
final readonly class PayrollSubmissionSettlementPolicy
{
    /** Stavy podání, které smí uzavřít ruční potvrzení. */
    public const SETTLEABLE_SUBMISSION_STATUSES = ['submitted', 'processing'];

    public function __construct(
        private PayrollDispatchCapabilityCatalog $capabilities,
    ) {}

    /**
     * `null` znamená „jde to"; jinak věta pro účetní.
     *
     * @param array<string,mixed>|null $outbox
     *        řádek z {@see \MyInvoice\Repository\Payroll\PayrollSubmissionRepository::findDispatchOutboxForSubmission()}
     */
    public function blockedReason(
        string $agendaCode,
        string $obligationStatus,
        string $submissionStatus,
        ?array $outbox,
    ): ?string {
        // Uzavřená povinnost se znovu neuzavírá. Bez tohohle kroku by tlačítko
        // svítilo dál i po uzavření: stav PODÁNÍ zůstává „odesláno" schválně,
        // takže sám o sobě o hotovosti měsíce nic neříká.
        if (in_array($obligationStatus, ['fulfilled', 'cancelled'], true)) {
            return 'Povinnost už je uzavřená.';
        }
        $capability = $this->capabilities->forAgenda($agendaCode);
        if (!$capability->isDispatchable()) {
            // ELDP a spol. mají vlastní, přísnější evidenci výsledku
            // (dokládá se dokumentem, ne kliknutím). Kdyby prošly i sem,
            // stály by vedle sebe dvě brány s různou laťkou a nikdo by
            // nepoužíval tu vyšší.
            return 'Tuhle agendu aplikace neodesílá, takže tady není co'
                . ' uzavírat. Výsledek se dokládá na záložce příslušné agendy.';
        }
        if ($capability->authorityReportsResult) {
            return 'U téhle agendy dorazí výsledek od úřadu sám a aplikace'
                . ' podle něj podání uzavře. Ručně ho uzavírat nelze — jinak by'
                . ' se za hotové prohlásilo něco, o čem úřad teprve rozhoduje.';
        }
        if (!in_array(
            $submissionStatus,
            self::SETTLEABLE_SUBMISSION_STATUSES,
            true,
        )) {
            return sprintf(
                'Uzavřít jde jen odeslané podání; tohle je ve stavu „%s".',
                $submissionStatus,
            );
        }
        // Řádek odchozí fronty existuje jen u datovky. Když existuje, musí
        // zpráva aplikaci prokazatelně opustit — uzavřít podání, které leží
        // ve frontě jako nepotvrzený koncept, by znamenalo prohlásit za
        // odevzdané něco, co nikdo neodeslal.
        if ($outbox !== null
            && !PayrollSubmissionDeliveryProof::hasLeftApplication($outbox)
        ) {
            return 'Zpráva zatím leží v odchozí frontě datové schránky'
                . ' neodeslaná. Nejdřív ji odešlete, teprve pak jde podání'
                . ' uzavřít.';
        }

        return null;
    }
}
