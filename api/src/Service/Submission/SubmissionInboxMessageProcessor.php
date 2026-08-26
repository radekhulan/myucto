<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Service\Submission\Channel\InboxMessageHeader;

interface SubmissionInboxMessageProcessor
{
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
    ): array;
}
