<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;

/**
 * Ruční uzavření POVINNOSTI, na kterou úřad nikdy neodpoví.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to existuje
 * ═══════════════════════════════════════════════════════════════════════════
 * Přehled o platbě pojistného zdravotní pojišťovně odejde datovou schránkou,
 * pojišťovna ho převezme — a tím to končí. Doložený strojový protokol, kterým
 * by odpověděla, neexistuje ({@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsInboxProcessor}).
 * Povinnost proto zůstala napořád ve stavu `submitted`:
 *
 *   * ve frontě „K odeslání" s nabídkou zahodit a podat znovu (což by
 *     u pojišťovny založilo duplicitu),
 *   * v přehledu se štítkem „Čeká na výsledek podání", ačkoliv se nečeká
 *     na nic,
 *   * a lhůta se nikdy neuzavřela.
 *
 * Účetní tedy měsíc poctivě odeslala a aplikace jí dál tvrdila, že není
 * hotovo. To je horší než nic neříct: naučí ji ta upozornění přehlížet.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se mění POVINNOST a ne stav podání
 * ═══════════════════════════════════════════════════════════════════════════
 * Stav podání je záznam o tom, co řekl ÚŘAD. `accepted` znamená „úřad přijal"
 * a smí ho zapsat jen ověřený protokol
 * ({@see PayrollSubmissionService::transitionWithEvidence()} to vynucuje;
 * automat proto ani nezná hranu `submitted → accepted`). Tady žádný protokol
 * není a vyrobit ho kliknutím by znamenalo tvrdit něco, co nikdo neřekl —
 * a navíc obejít roční uzávěrku, která se u `accepted` vědomě nekontroluje.
 *
 * Podání tedy zůstane „odesláno", protože to je pravda. Uzavírá se POVINNOST,
 * což je přesně to rozhodnutí, které účetní dělá: „za tenhle měsíc už nic
 * nedlužím". Z povinnosti čte i štítek u termínu
 * ({@see PayrollDeadlineAssessmentService}), takže řádek hned ukáže „Splněno".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co po sobě nechá
 * ═══════════════════════════════════════════════════════════════════════════
 * Do odchozí fronty se zapíše `manual_confirmation` i s poznámkou účetní, aby
 * bylo v historii vidět, že měsíc uzavřel ČLOVĚK a o co se opřel — ne že by
 * dorazil protokol.
 *
 * Brána je v {@see PayrollSubmissionSettlementPolicy}; ptá se na ni i přehled
 * podání, který podle ní rozhoduje, jestli u řádku svítí tlačítko.
 */
final readonly class PayrollSubmissionSettlementService
{
    public function __construct(
        private PayrollSubmissionRepository $repository,
        private SubmissionOutboxRepository $outbox,
        private PayrollSubmissionSettlementPolicy $policy,
    ) {}

    /**
     * @param  int    $expectedObligationVersion optimistický zámek povinnosti
     * @param  string $note čím účetní vyřízení doložila (č. zprávy, datum…)
     * @return array{
     *   obligation:array{id:int,status:string,row_version:int},
     *   submission:array{id:int,status:string},
     *   outbox_id:?int,delivery_proof:?string
     * }
     */
    public function settle(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedObligationVersion,
        string $note,
        ?int $userId,
    ): array {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException(
                'Uveďte, čím je vyřízení doložené — bez poznámky by v historii'
                    . ' zůstalo uzavření, u kterého nikdo nezjistí, o co se'
                    . ' opíralo.',
            );
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $submissionId,
            $expectedObligationVersion,
            $note,
            $userId,
        ): array {
            $submission = $this->repository->findSubmission($supplierId, $submissionId);
            if ($submission === null
                || (string) $submission['environment'] !== $environment
            ) {
                throw new \DomainException('Podání nenalezeno.');
            }
            $obligation = $this->repository->findObligationOfSubmission(
                $supplierId,
                $environment,
                $submissionId,
            );
            if ($obligation === null) {
                throw new \DomainException(
                    'K podání nepatří žádná povinnost, takže není co uzavřít.',
                );
            }
            $outboxRow = $this->repository->findDispatchOutboxForSubmission(
                $supplierId,
                $environment,
                $submissionId,
            );
            $blocked = $this->policy->blockedReason(
                $obligation['agenda_code'],
                $obligation['status'],
                (string) $submission['status'],
                $outboxRow,
            );
            if ($blocked !== null) {
                throw new \DomainException($blocked);
            }
            // Zámek je na POVINNOSTI, protože ta se mění. Kdyby se zamykalo
            // podání, prošlo by uzavření i povinnosti, kterou mezitím zavřel
            // někdo jiný — nebo naopak znovu otevřel.
            if ($obligation['row_version'] !== $expectedObligationVersion) {
                throw new PayrollSubmissionConflictException(
                    'Povinnost se mezitím změnila. Načtěte přehled znovu.',
                );
            }

            $this->repository->updateObligationStatus(
                $supplierId,
                $environment,
                $obligation['id'],
                $obligation['row_version'],
                'fulfilled',
            );

            // `manual_confirmation` je pravda o původu výroku: rozhodl člověk.
            // Kdyby se zapsal protokol úřadu, historie by lhala o tom, odkud
            // vyjádření pochází.
            if ($outboxRow !== null
                && $outboxRow['acceptance_state'] === 'unknown'
            ) {
                $this->outbox->recordAcceptance(
                    $supplierId,
                    $outboxRow['id'],
                    'accepted',
                    'manual_confirmation',
                    self::acceptanceNote($note, $userId),
                    $outboxRow['row_version'],
                );
            }

            return [
                'obligation' => [
                    'id' => $obligation['id'],
                    'status' => 'fulfilled',
                    'row_version' => $obligation['row_version'] + 1,
                ],
                // Stav podání se NEMĚNÍ a jde ven schválně: účetní musí vidět,
                // že „odesláno" zůstává — uzavřel se měsíc, ne výrok úřadu.
                'submission' => [
                    'id' => $submissionId,
                    'status' => (string) $submission['status'],
                ],
                'outbox_id' => $outboxRow['id'] ?? null,
                'delivery_proof' => PayrollSubmissionDeliveryProof::reason($outboxRow),
            ];
        });
    }

    private static function acceptanceNote(string $note, ?int $userId): string
    {
        $prefix = $userId === null
            ? 'Ručně potvrzeno: '
            : sprintf('Ručně potvrdil uživatel #%d: ', $userId);

        return mb_substr($prefix . $note, 0, 500);
    }
}
