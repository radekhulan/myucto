<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Rozebraná odpověď správce daně. Údaje pocházejí výhradně z elementu `Odpoved`
 * uvnitř jediného `soapenv:Body` (`body`), aby ověření podpisu a čtení obsahu
 * mluvily o tomtéž uzlu.
 */
final readonly class EetParsedResponse
{
    /**
     * @param list<array{code:int,message:string}> $warnings
     */
    public function __construct(
        public \DOMDocument $document,
        public ?\DOMElement $body,
        public ?string $faultCode = null,
        public ?string $faultString = null,
        public ?string $uuid = null,
        public ?string $receivedAt = null,
        public ?string $rejectedAt = null,
        public ?string $pok = null,
        public ?bool $testFlag = null,
        public ?int $errorCode = null,
        public ?string $errorText = null,
        public array $warnings = [],
    ) {}

    public function isFault(): bool
    {
        return $this->faultCode !== null || $this->faultString !== null;
    }

    public function isConfirmation(): bool
    {
        return $this->pok !== null;
    }
}
