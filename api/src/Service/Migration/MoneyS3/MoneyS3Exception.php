<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Chyba převodu z Money S3 se strojovým kódem — rozhraní z něj staví hlášku,
 * protokol ho ukládá u kroku, na kterém převod skončil.
 */
final class MoneyS3Exception extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        int $httpStatus = 422,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
