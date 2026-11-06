<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/** Odpověď device authorization endpointu (RFC 8628, odd. 3.2). */
final class OAuthDeviceAuthorization
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly string $verificationUri,
        public readonly int $interval,
        public readonly int $expiresIn,
    ) {}
}
