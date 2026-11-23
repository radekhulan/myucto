<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

/** Odmítnuté označení dokladu jako převzatého z počátečních stavů ({@see OpeningBalanceDocumentMarker}). */
final class OpeningBalanceDocumentException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
