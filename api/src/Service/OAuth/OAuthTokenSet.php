<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/** Tokeny z odpovědi token endpointu. `refreshToken` null = server nový nevydal. */
final class OAuthTokenSet
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $accessToken,
        #[\SensitiveParameter] public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly ?string $scope = null,
    ) {}
}
