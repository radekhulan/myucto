<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

/** Odmítnutý vstup hromadné akce (výběr, parametry operace). Akce ho vrací jako 4xx. */
final class BulkActionException extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
