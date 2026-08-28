<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsInboxProcessor;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsInboxProcessor;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;

/** Dispatches a stored ISDS response to its explicitly scoped processor. */
final readonly class CompositeSubmissionInboxMessageProcessor implements SubmissionInboxMessageProcessor
{
    public function __construct(
        private JmhzIsdsInboxProcessor $jmhz,
        private HealthInsuranceIsdsInboxProcessor $healthInsurance,
    ) {}

    /**
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    public function process(
        int $supplierId,
        string $environment,
        int $inboxMessageId,
        InboxMessageHeader $header,
        array $verdict,
        string $zfoBytes,
        ?int $actorUserId,
    ): array {
        foreach ([$this->jmhz, $this->healthInsurance] as $processor) {
            $result = $processor->process(
                $supplierId,
                $environment,
                $inboxMessageId,
                $header,
                $verdict,
                $zfoBytes,
                $actorUserId,
            );
            if ($result['status'] !== 'not_applicable') {
                return $result;
            }
        }

        return $result;
    }
}
