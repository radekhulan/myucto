<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Vrep;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolPartKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolReport;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;

/**
 * Zápis výsledku ověřeného protokolu ČSSZ k podání jednoho formuláře do jeho
 * případu dávky (NEMPRI, HZUPN) nebo záměru slevy (OZUSPOJ).
 *
 * Logiku přijetí a odmítnutí nedrží: volá tytéž služby jako ruční zápis
 * výsledku ({@see SicknessCaseService::recordReceipt()},
 * {@see OzuspojIntentService::recordReceipt()}), takže platí i všechna jejich
 * pravidla. Co služba odmítne, se nezapíše a podání dostane nález s důvodem;
 * výsledek dotazu na ČSSZ to nepřebije.
 *
 * Den doručení je den odeslání podání (převzetí bránou VREP), v pražském čase.
 *
 * Odmítnutí bez zpracovaného formuláře (chyba komunikace, typicky 103) se
 * u případu dávky zapíše jako odmítnuté podání s důvodem — tiskopis zůstává
 * k podání a lhůta se hlídá dál. Záměr slevy se tím NEODMÍTÁ: odmítnutý záměr
 * je rozhodnutí ČSSZ o nároku (§ 7a odst. 5 zák. č. 589/1992 Sb.), ze kterého
 * už přijetí nevede, a ČSSZ ho v takovém případě vůbec neposoudila.
 */
final readonly class CsszFormReceiptRecorder
{
    /** Třídy podání, jejichž výsledek se zapisuje. */
    public const CLASSES = ['CSSZ_NEM_PRI', 'CSSZ_OZUSPOJ'];

    /** Nález u podání: ČSSZ ho nezpracovala kvůli chybějícímu pověření u OSSZ. */
    public const SERVICE_AUTHORIZATION_ISSUE = 'jmhz_protocol_service_authorization_missing';

    /** Nález u podání: výsledek z protokolu se k případu ani záměru nezapsal. */
    public const NOT_RECORDED_ISSUE = 'jmhz_protocol_result_not_recorded';

    /** Délka sloupců `*_rejection_reason` případu i záměru. */
    private const REASON_MAX_LENGTH = 190;

    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private SicknessCaseService $cases,
        private OzuspojIntentService $intents,
    ) {}

    /**
     * @return 'not_applicable'|'recorded'|'skipped'|'not_recorded'
     */
    public function record(
        int $supplierId,
        string $environment,
        int $submissionId,
        JmhzProtocolReport $report,
    ): string {
        if (!in_array($report->submissionClass, self::CLASSES, true)) {
            return 'not_applicable';
        }
        $remote = $report->payrollRemoteStatus();
        if ($remote !== 'accepted' && $remote !== 'rejected') {
            return 'not_applicable';
        }
        if ($report->missingServiceAuthorization()) {
            $this->issue(
                $supplierId,
                $submissionId,
                'error',
                self::SERVICE_AUTHORIZATION_ISSUE,
                [
                    'submission_class' => $report->submissionClass,
                    'message' => self::serviceAuthorizationMessage($report->submissionClass),
                ],
            );
        }
        $target = $this->repository->singlePartReceiptTarget($supplierId, $environment, $submissionId);
        if ($target === null) {
            return $this->notRecorded(
                $supplierId,
                $submissionId,
                'cssz_form_receipt_target_missing',
                'Podání nemá jedinou součást, podle které by šlo dohledat případ dávky nebo záměr slevy.',
            );
        }
        $acceptedOn = self::deliveryDate($target['submitted_at']);
        $reason = self::rejectionReason($report);

        try {
            if (preg_match('/^sickness:([1-9][0-9]*):(nempri_transfer|nempri|hzupn)$/D', $target['part_reference'], $match) === 1) {
                return $this->recordSickness(
                    $supplierId,
                    $environment,
                    $submissionId,
                    (int) $match[1],
                    SicknessDocumentKind::from($match[2]),
                    $remote,
                    $acceptedOn,
                    $reason,
                );
            }
            if (preg_match('/^ozuspoj:([1-9][0-9]*):(start|end|cancellation)$/D', $target['part_reference'], $match) === 1) {
                return $this->recordIntent(
                    $supplierId,
                    $environment,
                    $submissionId,
                    (int) $match[1],
                    $match[2],
                    $remote,
                    $acceptedOn,
                    $reason,
                    self::formProcessed($report),
                    $report->missingServiceAuthorization(),
                );
            }
        } catch (SicknessException | OzuspojException $exception) {
            return $this->notRecorded(
                $supplierId,
                $submissionId,
                $exception->validationCode,
                $exception->getMessage(),
            );
        } catch (\OutOfBoundsException $exception) {
            return $this->notRecorded(
                $supplierId,
                $submissionId,
                'cssz_form_receipt_target_missing',
                $exception->getMessage(),
            );
        }

        return $this->notRecorded(
            $supplierId,
            $submissionId,
            'cssz_form_receipt_target_missing',
            'Součást podání neodkazuje na případ dávky ani na záměr slevy.',
        );
    }

    /**
     * Text pro účetní k chybě 103. Říká, KDE je vada: v registraci u OSSZ,
     * ne v podání.
     */
    public static function serviceAuthorizationMessage(string $submissionClass): string
    {
        return 'ČSSZ podání nezpracovala (chyba 103): u OSSZ chybí pověření'
            . ' k e-službě ' . $submissionClass . ' nebo registrace certifikátu,'
            . ' kterým je podepsané. Nejde o vadu podání.';
    }

    private function recordSickness(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $caseId,
        SicknessDocumentKind $document,
        string $remote,
        ?string $acceptedOn,
        string $reason,
    ): string {
        $case = $this->cases->requireCase($supplierId, $environment, $caseId);
        // Případ po odmítnutí nese už nové podání. Pozdní protokol starého
        // podání nesmí přepsat výsledek toho, které platí.
        if ((int) ($case[$document->submissionColumn()] ?? 0) !== $submissionId) {
            throw new SicknessException(
                'sickness_receipt_submission_mismatch',
                'Případ dávky je navázaný na jiné podání ' . $document->agendaCode()
                    . ', výsledek tohoto podání se k němu nezapisuje.',
            );
        }
        $this->cases->recordReceipt(
            $supplierId,
            $environment,
            $caseId,
            $document,
            $remote,
            $remote === 'accepted' ? $acceptedOn : null,
            $remote === 'rejected' ? $reason : null,
        );

        return 'recorded';
    }

    private function recordIntent(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $intentId,
        string $kind,
        string $remote,
        ?string $acceptedOn,
        string $reason,
        bool $formProcessed,
        bool $serviceAuthorizationMissing,
    ): string {
        $intent =$this->intents->requireIntent($supplierId, $environment, $intentId);
        $bound = match ($kind) {
            'start' => $intent['start_submission_id'] ?? null,
            'end' => $intent['end_submission_id'] ?? null,
            default => $submissionId,
        };
        if ((int) $bound !== $submissionId) {
            throw new OzuspojException(
                'ozuspoj_receipt_submission_mismatch',
                'Záměr slevy je navázaný na jiné oznámení, výsledek tohoto podání se k němu nezapisuje.',
            );
        }
        if ($remote === 'accepted') {
            $this->intents->recordReceipt(
                $supplierId,
                $environment,
                $intentId,
                match ($kind) {
                    'start' => 'accepted',
                    'end' => 'ended',
                    default => 'cancelled',
                },
                $kind === 'cancellation' ? null : $acceptedOn,
                null,
            );

            return 'recorded';
        }
        // Odmítnuté oznámení o skončení nebo storno záměr nemění: platí dál
        // tak, jak ho ČSSZ přijala. Odmítnutí stojí u podání.
        if ($kind !== 'start') {
            return 'skipped';
        }
        if (!$formProcessed) {
            if ($serviceAuthorizationMissing) {
                // Důvod už nese nález SERVICE_AUTHORIZATION_ISSUE.
                return 'skipped';
            }
            throw new OzuspojException(
                'ozuspoj_receipt_not_processed',
                'ČSSZ oznámení záměru nezpracovala, takže ho ani neodmítla. Záměr zůstává'
                    . ' podaný; po odstranění příčiny oznámení pošlete znovu. ' . $reason,
            );
        }
        $this->intents->recordReceipt(
            $supplierId,
            $environment,
            $intentId,
            'rejected',
            null,
            $reason,
        );

        return 'recorded';
    }

    /** @param array<string,mixed> $details */
    private function issue(
        int $supplierId,
        int $submissionId,
        string $severity,
        string $code,
        array $details,
    ): void {
        $this->submissions->recordIssue(
            $supplierId,
            $submissionId,
            (int) $this->submissions->get($supplierId, $submissionId)['row_version'],
            null,
            $severity,
            'remote',
            $code,
            'payroll_submission',
            (string) $submissionId,
            $details,
        );
    }

    /** @return 'not_recorded' */
    private function notRecorded(
        int $supplierId,
        int $submissionId,
        string $reasonCode,
        string $message,
    ): string {
        $this->issue(
            $supplierId,
            $submissionId,
            'warning',
            self::NOT_RECORDED_ISSUE,
            ['reason_code' => $reasonCode, 'message' => $message],
        );

        return 'not_recorded';
    }

    /** Zpracovala ČSSZ formulář, nebo podání odmítla dřív (chyba komunikace)? */
    private static function formProcessed(JmhzProtocolReport $report): bool
    {
        foreach ($report->parts as $part) {
            if ($part->kind === JmhzProtocolPartKind::Form) {
                return true;
            }
        }

        return false;
    }

    /** Den odeslání v pražském čase; `submitted_at` je v UTC. */
    private static function deliveryDate(?string $submittedAt): ?string
    {
        return PayrollSubmissionCalendar::dayOfUtc($submittedAt);
    }

    private static function rejectionReason(JmhzProtocolReport $report): string
    {
        if ($report->missingServiceAuthorization()) {
            $text = self::serviceAuthorizationMessage($report->submissionClass);
        } else {
            $messages = [];
            foreach ($report->allErrors() as $error) {
                $messages[$error->code . ' - ' . $error->message] = true;
            }
            $text = $messages === []
                ? 'ČSSZ podání odmítla bez uvedení důvodu; podrobnosti jsou v protokolu u podání.'
                : 'ČSSZ podání odmítla: ' . implode('; ', array_keys($messages));
        }
        if (mb_strlen($text) > self::REASON_MAX_LENGTH) {
            $text = mb_substr($text, 0, self::REASON_MAX_LENGTH - 1) . '…';
        }

        return $text;
    }
}
