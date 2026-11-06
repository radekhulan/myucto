<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

final readonly class SessionAuthContext
{
    private const ASSURANCE_LEVELS = ['legacy', 'basic', 'strong', 'setup'];

    public function __construct(
        public string $authMethod,
        public string $assuranceLevel,
        public ?\DateTimeImmutable $mfaVerifiedAt = null,
        public ?int $authCredentialId = null,
        // Sezení z přihlášení u poskytovatele identity nese odkaz na identitu,
        // jejíž odpojení sezení zruší. Kratší platnost řeší SessionManager.
        public ?int $externalIdentityId = null,
    ) {
        if ($authMethod === '' || strlen($authMethod) > 32) {
            throw new \InvalidArgumentException('Neplatná metoda autentizace session.');
        }
        if (!in_array($assuranceLevel, self::ASSURANCE_LEVELS, true)) {
            throw new \InvalidArgumentException('Neplatná úroveň assurance session.');
        }
        if ($assuranceLevel === 'strong' && $mfaVerifiedAt === null) {
            throw new \InvalidArgumentException('Strong session vyžaduje čas MFA ověření.');
        }
        if ($authCredentialId !== null && $authCredentialId <= 0) {
            throw new \InvalidArgumentException('Neplatné interní ID passkey.');
        }
        if ($externalIdentityId !== null && $externalIdentityId <= 0) {
            throw new \InvalidArgumentException('Neplatné ID externí identity.');
        }
    }

    public const EXTERNAL_PREFIX = 'oidc:';

    public static function externalIdentity(
        string $provider,
        \DateTimeImmutable $verifiedAt,
        int $externalIdentityId,
    ): self {
        return new self(self::EXTERNAL_PREFIX . $provider, 'strong', $verifiedAt, null, $externalIdentityId);
    }

    public static function isExternalMethod(?string $authMethod): bool
    {
        return $authMethod !== null && str_starts_with($authMethod, self::EXTERNAL_PREFIX);
    }

    public function withExternalIdentity(string $provider, int $externalIdentityId): self
    {
        return new self(
            self::EXTERNAL_PREFIX . $provider,
            $this->assuranceLevel,
            $this->mfaVerifiedAt,
            $this->authCredentialId,
            $externalIdentityId,
        );
    }

    public static function legacy(): self
    {
        return new self('legacy', 'legacy');
    }

    public static function basic(string $authMethod): self
    {
        return new self($authMethod, 'basic');
    }

    public static function strong(
        string $authMethod,
        \DateTimeImmutable $verifiedAt,
        ?int $authCredentialId = null,
    ): self {
        return new self($authMethod, 'strong', $verifiedAt, $authCredentialId);
    }

    public static function setup(string $authMethod = 'setup'): self
    {
        return new self($authMethod, 'setup');
    }
}
