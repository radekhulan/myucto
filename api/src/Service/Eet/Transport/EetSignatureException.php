<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Podpis nebo tvar zprávy neprošel ověřením. `reason` je strojový kód, který se
 * propisuje do {@see EetSendResult::$signatureFailure}.
 */
final class EetSignatureException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
