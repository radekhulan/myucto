<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

interface BankPaymentCapabilityProvider
{
    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool;
}
