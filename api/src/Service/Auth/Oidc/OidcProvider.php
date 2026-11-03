<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

/**
 * Nastavení jednoho poskytovatele identity. Adresy se neberou z discovery
 * dokumentu, ale skládají se z konfigurace: vydavatel, proti kterému se
 * id_token ověřuje, tak nemůže podstrčit nikdo, kdo by ovlivnil síťovou odpověď.
 */
final readonly class OidcProvider
{
    /**
     * @param list<string> $allowedAlgorithms
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $issuer,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $jwksUrl,
        public string $clientId,
        #[\SensitiveParameter] public string $clientSecret,
        public string $scope,
        public ?string $tenantId,
        public bool $allowGuests,
        public array $allowedAlgorithms = ['RS256'],
    ) {}
}
