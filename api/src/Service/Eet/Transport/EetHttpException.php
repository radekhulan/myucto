<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Odpověď nedorazila. `timeout` odlišuje vypršení limitu (výsledek neznámý —
 * zpráva mohla být přijata) od chyby spojení.
 */
final class EetHttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $timeout = false,
        public readonly int $durationMs = 0,
    ) {
        parent::__construct($message);
    }
}
