<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

/** Validační chyba číselníku řad (4xx s hláškou). */
final class NumberSeriesException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
