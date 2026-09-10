<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Import;

final class CatalogMediaFetchException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($errorCode);
    }
}
