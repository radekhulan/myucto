<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Capabilities;

final class TenantTransferCapabilitiesUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
