<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;

/**
 * Zahození rozdělaného odeslání, aby šlo podat znovu.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * ČSSZ zprávu převezme, ale zpracovat ji odmítne — třeba proto, že certifikát,
 * kterým je e-podání podepsané, není u OSSZ zapsaný v registru podávajících.
 * Odeslané tedy nic není, jenže podání uvízlo ve stavu, ze kterého nevedla cesta
 * nikam: na `ready` se nedalo vrátit, klíč `uq_payroll_submissions_regular` pouští
 * na jednu povinnost jediné řádné podání, takže nešlo založit ani nové, a otevřený
 * pokus blokoval odeslání. Povinnost byla z aplikace trvale nepodatelná i poté, co
 * účetní příčinu u OSSZ vyřídila.
 *
 * ROZHODUJE ČLOVĚK
 * ------------------------------------------------------------------------------
 * Důvodů, proč úřad podání nepřijme, je víc, než kolik jich umíme z protokolu
 * spolehlivě rozpoznat — a špatně uhodnutý důvod je horší než žádný. Aplikace proto
 * odpověď úřadu ukáže a rozhodne účetní, která ji vidí. Tahle služba je ta ruční
 * páka, ne automatika.
 *
 * CO ZŮSTÁVÁ
 * ------------------------------------------------------------------------------
 * Zahozený pokus se z ledgeru NEMAŽE. Dostane terminální stav `expired` s kódem
 * `abandoned_by_user` a důvodem, takže v historii podání zůstane i s tím, co úřad
 * odpověděl; jen přestane blokovat další odeslání
 * ({@see PayrollDispatchGate::attemptAllowsRetry()}). Zmrazený artefakt se nemění —
 * odesílá se pak přesně totéž XML, ne nově sestavené.
 */
final readonly class PayrollSubmissionAbandonService
{
    /** Stavy pokusu, které JEŠTĚ drží odeslání otevřené a jde je zahodit. */
    private const OPEN_ATTEMPT_STATUSES = ['prepared', 'sent', 'completed'];

    public function __construct(
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
    ) {}

    /**
     * @param  string $reason co úřad odpověděl / proč se odeslání zahazuje
     * @return array{
     *   submission:array{id:int,status:string,row_version:int},
     *   abandoned_attempts:list<int>
     * }
     */
    public function abandon(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        string $reason,
    ): array {
        // Pokusy se uzavírají PŘED návratem podání na `ready`. Kdyby se pořadí
        // otočilo a uzavření selhalo, zůstalo by podání odesílatelné s otevřeným
        // pokusem — tedy přesně stav, ve kterém hrozí druhé odeslání téhož.
        $abandoned = [];
        foreach ($this->attempts->listForSubmission($supplierId, $environment, $submissionId) as $attempt) {
            if (!in_array((string) ($attempt['status'] ?? ''), self::OPEN_ATTEMPT_STATUSES, true)) {
                continue;
            }
            $this->attempts->markExpired(
                (int) $attempt['id'],
                PayrollDispatchGate::ABANDONED_ERROR_CODE,
                $reason,
                (int) $attempt['row_version'],
            );
            $abandoned[] = (int) $attempt['id'];
        }

        $submission = $this->submissions->abandonAndReopen(
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $reason,
        );

        return ['submission' => $submission, 'abandoned_attempts' => $abandoned];
    }
}
