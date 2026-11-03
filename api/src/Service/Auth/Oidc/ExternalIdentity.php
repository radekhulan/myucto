<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

/**
 * Ověřená identita z poskytovatele. Párovací klíč je výhradně
 * (provider, issuer, subject); e-mail a jméno slouží jen pro přehled.
 */
final readonly class ExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $issuer,
        public ?string $tenantId,
        public string $subject,
        public ?string $email,
        public ?string $name,
        public bool $isGuest = false,
        public ?int $authTime = null,
    ) {}
}
