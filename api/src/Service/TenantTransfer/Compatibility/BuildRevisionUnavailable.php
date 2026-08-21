<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

final class BuildRevisionUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
