<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Výsledek pokusu včetně auditních dat (odeslané a přijaté XML, doba odezvy,
 * výsledek ověření podpisu odpovědi). `requestXml` se zachovává i při síťové
 * chybě, aby šlo doložit, co přesně odešlo.
 */
final readonly class EetSendResult
{
    /**
     * @param list<array{code:int,message:string}> $warnings
     */
    public function __construct(
        public EetOutcome $outcome,
        public ?string $pok = null,
        public ?bool $testFlag = null,
        public ?int $errorCode = null,
        public ?string $errorMessage = null,
        public array $warnings = [],
        public string $requestXml = '',
        public ?string $responseXml = null,
        public ?int $httpStatus = null,
        public int $durationMs = 0,
        public ?string $receivedAt = null,
        public ?string $responseUuid = null,
        public bool $signatureVerified = false,
        public ?string $signatureFailure = null,
        /** Hodnota HTTP hlavičky X-Global-Transaction-Id pro dohledání u správce daně. */
        public ?string $transactionId = null,
    ) {}

    public function isConfirmed(): bool
    {
        return $this->outcome === EetOutcome::Confirmed || $this->outcome === EetOutcome::ConfirmedWithWarning;
    }

    /** Má smysl poslat tutéž tržbu znovu (s novým UUID a prvni_zaslani=false). */
    public function isRetryable(): bool
    {
        return match ($this->outcome) {
            EetOutcome::Timeout,
            EetOutcome::TransportError,
            EetOutcome::TemporaryError,
            EetOutcome::InvalidResponse => true,
            default => false,
        };
    }
}
