<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzEffectiveFormState
{
    public function __construct(
        public string $employmentExternalIdentifier,
        public ?string $personExternalIdentifier,
        public string $state,
        public ?string $formGuid,
        public ?int $sourceSubmissionId,
    ) {}
}
