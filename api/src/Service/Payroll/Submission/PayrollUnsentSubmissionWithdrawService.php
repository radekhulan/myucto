<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

/**
 * Zrušení zmrazeného, ale NEODESLANÉHO řádného měsíčního hlášení, aby šlo
 * připravit znovu z opravené mzdy.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Účetní hlášení zmrazí (připraví k odeslání) a před odesláním opraví mzdový
 * běh. Nová příprava z opravené revize pak zmrazit nejde: za období smí
 * existovat jediné řádné hlášení ({@see PayrollObligationService}) a opravné
 * hlášení lze navázat až na přijaté řádné. Zbývalo odeslat hlášení se starými
 * částkami a hned po přijetí poslat opravné, přestože úřad ještě nic nedostal.
 *
 * CO SE ZRUŠIT SMÍ
 * ------------------------------------------------------------------------------
 * Jen řádné měsíční hlášení v předodeslaném stavu, které nemá jediný pokus
 * o odeslání a nečeká v odchozí frontě datové schránky. Jakmile se odesílat
 * začalo, rozhoduje o dalším postupu odpověď úřadu a vede tudy zahození pokusu
 * ({@see PayrollSubmissionAbandonService}) nebo opravné hlášení, ne tahle
 * služba. Aplikace nevidí hlášení nahrané na ePortál ručně ze staženého XML,
 * proto zrušení vyžaduje výslovné potvrzení a důvod: druhé řádné hlášení za
 * totéž období úřad odmítne jako duplicitní.
 *
 * CO ZŮSTÁVÁ
 * ------------------------------------------------------------------------------
 * Podání ani jeho zmrazený artefakt se nemažou. Podání přejde do stavu
 * `cancelled_in_time` (včas zrušeno), jeho povinnost do `cancelled`; tím se
 * uvolní období pro nové řádné hlášení. Kdo, kdy a proč zrušil, zapisuje akce
 * do auditu.
 */
final readonly class PayrollUnsentSubmissionWithdrawService
{
    /** Agendy měsíčního hlášení, jejichž řádné podání smí být za období jen jedno. */
    private const AGENDAS = [
        'JMHZ',
        ...JmhzSpecVersion::MONTHLY_AGENDA_CODES,
        HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
    ];

    public function __construct(
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollSubmissionRepository $repository,
    ) {}

    /**
     * Důvod, proč podání zrušit nejde, nebo `null`, když to jde.
     *
     * Čistá funkce nad daty: stejné pravidlo čte fronta podání (nabídka
     * tlačítka) i samotné zrušení.
     */
    public static function blockedReason(
        string $agendaCode,
        string $submissionKind,
        string $submissionStatus,
        int $attemptCount,
        bool $queuedInDataBox,
    ): ?string {
        if (!in_array($agendaCode, self::AGENDAS, true) || $submissionKind !== 'regular') {
            return 'Zrušit přípravu jde jen u řádného měsíčního hlášení a přehledu o platbě pojistného.';
        }
        if (!in_array($submissionStatus, PayrollSubmissionStateMachine::PRE_SUBMISSION_STATUSES, true)) {
            return 'Hlášení už bylo odesláno nebo o něm úřad rozhodl. Změny pošlete opravným '
                . 'hlášením; nepřijal-li ho úřad, zahoďte rozdělané odeslání ve frontě podání.';
        }
        if ($attemptCount > 0 || $queuedInDataBox) {
            return 'Hlášení se už odesílalo (má pokus o odeslání nebo čeká v datové schránce). '
                . 'Nepřijal-li ho úřad, zahoďte rozdělané odeslání ve frontě podání; jinak změny '
                . 'pošlete opravným hlášením.';
        }

        return null;
    }

    /**
     * @return array{
     *   submission:array{id:int,status:string,row_version:int},
     *   obligation:array{id:int,agenda_code:string,period_start:string,subject_reference:string}
     * }
     */
    public function withdraw(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        string $reason,
    ): array {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Uveďte důvod zrušení připraveného hlášení.');
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $submissionId,
            $expectedRowVersion,
        ): array {
            $submission = $this->submissions->get($supplierId, $submissionId);
            $obligation = $this->repository->findObligationOfSubmission($supplierId, $environment, $submissionId);
            if ($obligation === null || (string) $submission['environment'] !== $environment) {
                throw new \DomainException('Podání nebylo nalezeno ve stejné firmě a prostředí.');
            }
            $blocked = self::blockedReason(
                (string) $obligation['agenda_code'],
                (string) $submission['submission_kind'],
                (string) $submission['status'],
                count($this->attempts->listForSubmission($supplierId, $environment, $submissionId)),
                $this->repository->hasActiveIsdsOutbox($supplierId, $environment, $submissionId),
            );
            if ($blocked !== null) {
                throw new \DomainException($blocked);
            }
            $cancelled = $this->submissions->transition(
                $supplierId,
                $submissionId,
                $expectedRowVersion,
                'cancelled_in_time',
            );
            if ((string) $obligation['agenda_code'] === HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW) {
                $this->repository->updateObligationStatus(
                    $supplierId,
                    $environment,
                    (int) $obligation['id'],
                    (int) $obligation['row_version'],
                    'cancelled',
                );
            }

            return [
                'submission' => [
                    'id' => $submissionId,
                    'status' => (string) $cancelled['status'],
                    'row_version' => (int) $cancelled['row_version'],
                ],
                'obligation' => [
                    'id' => (int) $obligation['id'],
                    'agenda_code' => (string) $obligation['agenda_code'],
                    'period_start' => (string) $obligation['period_start'],
                    'subject_reference' => (string) $obligation['subject_reference'],
                ],
            ];
        });
    }
}
