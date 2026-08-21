<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Grant;

final readonly class TenantTransferGrantValidation
{
    /** @param array<string,mixed>|null $grant */
    private function __construct(
        public ?array $grant,
        public ?string $errorCode,
        public int $httpStatus,
        public ?int $retryAfterSeconds,
    ) {}

    /** @param array<string,mixed> $grant */
    public static function allowed(array $grant): self
    {
        return new self($grant, null, 200, null);
    }

    public static function rejected(
        string $errorCode,
        int $httpStatus,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(null, $errorCode, $httpStatus, $retryAfterSeconds);
    }

    public function isAllowed(): bool
    {
        return $this->grant !== null;
    }
}
