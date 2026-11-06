<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzRejectedSubmissionRefreezeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

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
 * CO SE ZAHODIT NESMÍ
 * ------------------------------------------------------------------------------
 * Zpráva, kterou úřad PROKAZATELNĚ dostal. Stav podání to neprozradí —
 * `submitted` znamená jen tolik, že aplikace odeslání zapsala. Důkazem je řádek
 * odchozí fronty datové schránky: dodání potvrzené ISDS, připnutá doručenka nebo
 * vyjádření úřadu ({@see PayrollSubmissionDeliveryProof}). Dokud se to
 * nekontrolovalo, nabízela fronta „Zahodit a podat znovu" i u přehledu, který
 * pojišťovna měla ve schránce — a druhé podání téhož by u ní založilo duplicitu,
 * kterou nejde vzít zpět. Špatný obsah se v takovém případě řeší opravným nebo
 * stornovacím podáním, ne novým odesláním.
 *
 * CO ZŮSTÁVÁ
 * ------------------------------------------------------------------------------
 * Zahozený pokus se z ledgeru NEMAŽE. Dostane terminální stav `expired` s kódem
 * `abandoned_by_user` a důvodem, takže v historii podání zůstane i s tím, co úřad
 * odpověděl; jen přestane blokovat další odeslání
 * ({@see PayrollDispatchGate::attemptAllowsRetry()}). Zmrazený artefakt se nemění,
 * s jedinou výjimkou: řádné podání JMHZ, které ČSSZ zpracovala a zamítla, se
 * znovu zmrazí s novým GUID ({@see JmhzRejectedSubmissionRefreezeService}).
 */
final readonly class PayrollSubmissionAbandonService
{
    /**
     * Stavy pokusu, které JEŠTĚ drží odeslání otevřené a jde je zahodit.
     *
     * `awaiting_protocol` tu chybělo, přestože je to PŘESNĚ ten stav, kvůli
     * kterému služba vznikla: ČSSZ zprávu převezme (HTTP 200, CorrelationID)
     * a odmítne ji až protokolem. Pokus, který na protokol čeká, tak zahození
     * tiše minulo — dál se na výsledek doptával, dál blokoval odeslání a
     * povinnost zůstala z aplikace nepodatelná. Viz
     * {@see \MyInvoice\Tests\Unit\Payroll\Submission\PayrollSubmissionAbandonRulesTest}.
     */
    private const OPEN_ATTEMPT_STATUSES = [
        'prepared', 'sent', 'awaiting_protocol', 'completed', 'possibly_delivered',
    ];

    /** Agendy, jejichž podání nese GUID podání podle pravidel JMHZ. */
    private const GUID_AGENDAS = ['JMHZ', ...JmhzSpecVersion::MONTHLY_AGENDA_CODES];

    public function __construct(
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollSubmissionRepository $repository,
        private JmhzRejectedSubmissionRefreezeService $refreeze,
    ) {}

    /**
     * @param  string $reason co úřad odpověděl / proč se odeslání zahazuje
     * @return array{
     *   submission:array{id:int,status:string,row_version:int},
     *   abandoned_attempts:list<int>,
     *   refreeze:?array<string,mixed>
     * }
     */
    public function abandon(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        string $reason,
        ?int $actorUserId = null,
    ): array {
        // Zahození pokusů, návrat na `ready` a případné znovuzmrazení s novým
        // GUID jsou JEDEN krok. Mezi návratem a znovuzmrazením by jinak bylo
        // podání chvíli odesílatelné se starým GUID.
        return $this->repository->transaction(fn (): array => $this->abandonInTransaction(
            $supplierId,
            $environment,
            $submissionId,
            $expectedRowVersion,
            $reason,
            $actorUserId,
        ));
    }

    /**
     * @return array{
     *   submission:array{id:int,status:string,row_version:int},
     *   abandoned_attempts:list<int>,
     *   refreeze:?array<string,mixed>
     * }
     */
    private function abandonInTransaction(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        string $reason,
        ?int $actorUserId,
    ): array {
        $before = $this->submissions->get($supplierId, $submissionId);
        // Doložené doručení je STOPKA, a kontroluje se jako první — dřív, než
        // se sáhne na jediný pokus. Kdyby se pokusy uzavřely a teprve pak se
        // zjistilo, že zprávu úřad má, zůstalo by podání bez otevřeného pokusu
        // a tedy volné k dalšímu odeslání: přesně ta duplicita, které se
        // kontrola snaží zabránit.
        $blocked = PayrollSubmissionDeliveryProof::abandonBlockedReason(
            $this->repository->findDispatchOutboxForSubmission(
                $supplierId,
                $environment,
                $submissionId,
            ),
        );
        if ($blocked !== null) {
            throw new \DomainException($blocked);
        }

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

        // ČSSZ podání ZPRACOVALA a zamítla. Řádné podání se pak posílá znovu
        // s NOVÝM GUID (pravidla podání, kap. 9; stejné R se stejným GUID
        // odmítne kontrola 22). Opravné a stornovací podání nesou GUID řádného
        // podání, ten se nemění. O tom, který GUID se obnoví, rozhoduje
        // JmhzSubmissionGuidPolicy. U podání bez výsledku zpracování
        // (odesláno, zpracovává se) zůstává GUID stejný: kdyby originál u ČSSZ
        // přece jen byl, ohlásí ho kontrola 22, místo aby vznikla duplicita.
        $refreeze = null;
        $obligation = $this->repository->findObligationOfSubmission($supplierId, $environment, $submissionId);
        if ((string) $before['status'] === 'rejected'
            && $obligation !== null
            && in_array((string) $obligation['agenda_code'], self::GUID_AGENDAS, true)
        ) {
            $refreeze = $this->refreeze->refreeze(
                $supplierId,
                $environment,
                $submissionId,
                (int) $submission['row_version'],
                $actorUserId,
            );
            $submission['row_version'] = (int) $refreeze['submission_row_version'];
        }

        return [
            'submission' => $submission,
            'abandoned_attempts' => $abandoned,
            'refreeze' => $refreeze,
        ];
    }
}
