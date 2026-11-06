<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

/**
 * Nastavení jednoho poskytovatele identity. Adresy se neberou z discovery
 * dokumentu, ale skládají se z konfigurace: vydavatel, proti kterému se
 * id_token ověřuje, tak nemůže podstrčit nikdo, kdo by ovlivnil síťovou odpověď.
 *
 * `assurance`:
 *  - `strong`        poskytovatel je celé přihlášení včetně MFA (Entra ID),
 *  - `first_factor`  poskytovatel nahrazuje jen heslo, druhý faktor se ověřuje
 *                    v MyÚčto stejně jako po hesle (Google, Apple, Seznam).
 *
 * `protocol`: `oidc` ověřuje id_token, `oauth2` (Seznam) čte identitu
 * z userinfo endpointu přes TLS.
 */
final readonly class OidcProvider
{
    public const STRONG = 'strong';
    public const FIRST_FACTOR = 'first_factor';

    /**
     * @param list<string> $allowedAlgorithms
     * @param list<string> $issuerAliases  další přípustné hodnoty `iss` (Google bez https)
     * @param list<string> $allowedHostedDomains  Google Workspace domény (claim `hd`), prázdné = kdokoli
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
        public string $assurance = self::STRONG,
        public string $protocol = 'oidc',
        public ?string $userinfoUrl = null,
        public string $responseMode = 'query',
        public array $issuerAliases = [],
        public array $allowedHostedDomains = [],
        public bool $tokenRequestJson = false,
        public bool $supportsPkce = true,
        // Apple chce místo statického tajemství krátce platný JWT podepsaný klíčem
        // vývojářského účtu; vyrobí se až při výměně kódu.
        public ?\Closure $clientSecretFactory = null,
    ) {}

    public function secret(): string
    {
        return $this->clientSecretFactory !== null ? (string) ($this->clientSecretFactory)() : $this->clientSecret;
    }

    public function isStrong(): bool
    {
        return $this->assurance === self::STRONG;
    }
}
