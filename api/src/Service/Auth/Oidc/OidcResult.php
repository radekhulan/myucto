<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

final readonly class OidcResult
{
    /**
     * @param array<string,mixed>|null $user  jen u přihlášení
     */
    public function __construct(
        public string $purpose,
        public ?string $returnTo,
        public ?array $user = null,
        public ?int $externalIdentityId = null,
        #[\SensitiveParameter] public ?string $stepUpToken = null,
        public ?string $stepUpOperation = null,
    ) {}
}
