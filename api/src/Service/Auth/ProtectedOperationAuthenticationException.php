<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final class ProtectedOperationAuthenticationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Chráněnou operaci se nepodařilo znovu ověřit.', 0, $previous);
    }
}
