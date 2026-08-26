<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final readonly class PayrollRegistrationXmlPayload
{
    public function __construct(
        public PayrollRegistrationIdentitySnapshot $identity,
        public PayrollRegistrationInteraction $interaction,
        public int $sequenceNumber,
        public string $formGuid,
        public string $preparedOn,
        public ?string $expectedStartOn,
        public ?string $actualStartOn,
        public string $employerVariableSymbol,
        public ?string $employerName = null,
        public ?string $csszWorkplaceCode = null,
        /** @var array<string,mixed>|null */
        public ?array $eventSnapshot = null,
    ) {}
}
