<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Poskytovatelé identity zapnutí v této instalaci (`auth.oidc`).
 *
 * Registrace aplikace u poskytovatele je vždy vlastní pro každou instalaci,
 * protože poskytovatel vrací uživatele jen na předem zapsanou adresu. Návratová
 * adresa je proto vždy na `app.url`, nikdy na hostname požadavku: za proxy by
 * šla podvrhnout hlavičkou a vlastní domény firem by každá potřebovala vlastní
 * zápis u poskytovatele. Na vlastní doménu se uživatel vrací přes domain-login.
 *
 * Neúplně nastavený poskytovatel se nezapne a {@see configurationErrors()} ho
 * vypíše do Diagnostiky; aplikace kvůli němu nespadne.
 */
final class OidcProviderRegistry
{
    public const PROVIDERS = ['entra', 'google', 'apple', 'seznam'];

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** @var array<string,OidcProvider>|null */
    private ?array $providers = null;
    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('auth.oidc.enabled', false) && $this->enabled() !== [];
    }

    public function get(string $id): ?OidcProvider
    {
        return $this->enabled()[$id] ?? null;
    }

    /** @return array<string,OidcProvider> */
    public function enabled(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $this->providers = [];
        $this->errors = [];
        if (!(bool) $this->config->get('auth.oidc.enabled', false)) {
            return $this->providers;
        }
        if ($this->appUrl() === null) {
            $this->errors['app'] = 'app.url musí být absolutní https adresa bez cesty.';
            return $this->providers;
        }
        foreach (self::PROVIDERS as $id) {
            $settings = $this->config->get('auth.oidc.providers.' . $id, []);
            if (!is_array($settings) || !(bool) ($settings['enabled'] ?? false)) {
                continue;
            }
            try {
                $this->providers[$id] = match ($id) {
                    'entra' => $this->entra($settings),
                    'google' => $this->google($settings),
                    'apple' => $this->apple($settings),
                    'seznam' => $this->seznam($settings),
                };
            } catch (\InvalidArgumentException $e) {
                $this->errors[$id] = $e->getMessage();
            }
        }
        return $this->providers;
    }

    /** @return array<string,string> */
    public function configurationErrors(): array
    {
        $this->enabled();
        return $this->errors;
    }

    /**
     * Co smí vědět nepřihlášený prohlížeč: jen ID a popisek tlačítka.
     *
     * @return list<array{id:string,label:string,strong:bool}>
     */
    public function publicList(): array
    {
        $list = [];
        foreach ($this->enabled() as $provider) {
            // `strong` = poskytovatel nese celé přihlášení včetně MFA (Entra ID):
            // jen ten umí potvrdit citlivou operaci a jen ten jde vynutit pro firmu.
            $list[] = ['id' => $provider->id, 'label' => $provider->label, 'strong' => $provider->isStrong()];
        }
        return $list;
    }

    public function redirectUri(OidcProvider $provider): string
    {
        $base = $this->appUrl();
        if ($base === null) {
            throw new \LogicException('app.url není nastavené.');
        }
        return $base . '/api/auth/oidc/' . $provider->id . '/callback';
    }

    /** @param array<string,mixed> $s */
    private function entra(array $s): OidcProvider
    {
        $tenant = strtolower(trim((string) ($s['tenant_id'] ?? '')));
        // Jen konkrétní tenant. `common`/`organizations` by pustily kohokoli
        // s jakýmkoli pracovním účtem Microsoftu a doménové jméno tenantu se
        // nedá porovnat s claimem `tid`.
        if (preg_match(self::GUID, $tenant) !== 1) {
            throw new \InvalidArgumentException('tenant_id musí být GUID tenantu (ne common, organizations ani doména).');
        }
        $clientId = strtolower(trim((string) ($s['client_id'] ?? '')));
        if (preg_match(self::GUID, $clientId) !== 1) {
            throw new \InvalidArgumentException('client_id musí být Application (client) ID registrace.');
        }
        $secret = (string) ($s['client_secret'] ?? '');
        if (trim($secret) === '') {
            throw new \InvalidArgumentException('Chybí client_secret.');
        }
        $label = trim((string) ($s['label'] ?? ''));

        $authority = 'https://login.microsoftonline.com/' . $tenant;
        return new OidcProvider(
            id: 'entra',
            label: $label !== '' ? mb_substr($label, 0, 60) : 'Microsoft',
            issuer: $authority . '/v2.0',
            authorizeUrl: $authority . '/oauth2/v2.0/authorize',
            tokenUrl: $authority . '/oauth2/v2.0/token',
            jwksUrl: $authority . '/discovery/v2.0/keys',
            clientId: $clientId,
            clientSecret: $secret,
            scope: 'openid profile email',
            tenantId: $tenant,
            allowGuests: (bool) ($s['allow_guests'] ?? false),
        );
    }

    /** @param array<string,mixed> $s */
    private function google(array $s): OidcProvider
    {
        $clientId = trim((string) ($s['client_id'] ?? ''));
        if (preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/D', $clientId) !== 1) {
            throw new \InvalidArgumentException('client_id musí být OAuth Client ID ve tvaru …apps.googleusercontent.com.');
        }
        $secret = (string) ($s['client_secret'] ?? '');
        if (trim($secret) === '') {
            throw new \InvalidArgumentException('Chybí client_secret.');
        }
        $domains = array_values(array_filter(array_map(
            static fn ($d) => strtolower(trim((string) $d)),
            (array) ($s['allowed_hosted_domains'] ?? []),
        )));
        return new OidcProvider(
            id: 'google',
            label: self::label($s, 'Google'),
            issuer: 'https://accounts.google.com',
            authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenUrl: 'https://oauth2.googleapis.com/token',
            jwksUrl: 'https://www.googleapis.com/oauth2/v3/certs',
            clientId: $clientId,
            clientSecret: $secret,
            scope: 'openid email profile',
            tenantId: null,
            allowGuests: true,
            assurance: OidcProvider::FIRST_FACTOR,
            responseMode: '',
            issuerAliases: ['accounts.google.com'],
            allowedHostedDomains: $domains,
        );
    }

    /** @param array<string,mixed> $s */
    private function apple(array $s): OidcProvider
    {
        $teamId = trim((string) ($s['team_id'] ?? ''));
        $keyId = trim((string) ($s['key_id'] ?? ''));
        $servicesId = trim((string) ($s['services_id'] ?? ''));
        if (preg_match('/^[A-Z0-9]{10}$/D', $teamId) !== 1 || preg_match('/^[A-Z0-9]{10}$/D', $keyId) !== 1) {
            throw new \InvalidArgumentException('team_id a key_id jsou desetimístné identifikátory z Apple Developer.');
        }
        if (preg_match('/^[A-Za-z0-9.\-]{3,200}$/D', $servicesId) !== 1) {
            throw new \InvalidArgumentException('services_id musí být identifikátor Services ID (např. cz.firma.ucto.web).');
        }
        $pem = (string) ($s['private_key'] ?? '');
        $file = trim((string) ($s['private_key_file'] ?? ''));
        if (trim($pem) === '' && $file !== '') {
            $pem = is_file($file) && is_readable($file) ? (string) file_get_contents($file) : '';
        }
        // ENV nese klíč často na jednom řádku s literálními \n.
        $pem = str_replace('\n', "\n", trim($pem));
        if (!str_contains($pem, 'PRIVATE KEY') || openssl_pkey_get_private($pem) === false) {
            throw new \InvalidArgumentException('Chybí nebo nejde načíst soukromý klíč .p8 (private_key / private_key_file).');
        }
        $secret = new AppleClientSecret($teamId, $keyId, $servicesId, $pem);
        return new OidcProvider(
            id: 'apple',
            label: self::label($s, 'Apple'),
            issuer: 'https://appleid.apple.com',
            authorizeUrl: 'https://appleid.apple.com/auth/authorize',
            tokenUrl: 'https://appleid.apple.com/auth/token',
            jwksUrl: 'https://appleid.apple.com/auth/keys',
            clientId: $servicesId,
            clientSecret: '',
            scope: 'openid email',
            tenantId: null,
            allowGuests: true,
            assurance: OidcProvider::FIRST_FACTOR,
            // S rozsahem email Apple vrací výsledek jen POSTem (form_post).
            responseMode: 'form_post',
            clientSecretFactory: static fn (): string => $secret->generate(),
        );
    }

    /** @param array<string,mixed> $s */
    private function seznam(array $s): OidcProvider
    {
        $clientId = trim((string) ($s['client_id'] ?? ''));
        if ($clientId === '' || strlen($clientId) > 200 || preg_match('/[\x00-\x20\x7f]/', $clientId) === 1) {
            throw new \InvalidArgumentException('Chybí client_id.');
        }
        $secret = (string) ($s['client_secret'] ?? '');
        if (trim($secret) === '') {
            throw new \InvalidArgumentException('Chybí client_secret.');
        }
        return new OidcProvider(
            id: 'seznam',
            label: self::label($s, 'Seznam'),
            issuer: 'https://login.szn.cz',
            authorizeUrl: 'https://login.szn.cz/api/v1/oauth/auth',
            tokenUrl: 'https://login.szn.cz/api/v1/oauth/token',
            jwksUrl: '',
            clientId: $clientId,
            clientSecret: $secret,
            scope: 'identity',
            tenantId: null,
            allowGuests: true,
            assurance: OidcProvider::FIRST_FACTOR,
            protocol: 'oauth2',
            userinfoUrl: 'https://login.szn.cz/api/v1/user',
            responseMode: '',
            tokenRequestJson: true,
            supportsPkce: false,
        );
    }

    /** @param array<string,mixed> $s */
    private static function label(array $s, string $default): string
    {
        $label = trim((string) ($s['label'] ?? ''));
        return $label !== '' ? mb_substr($label, 0, 60) : $default;
    }

    private function appUrl(): ?string
    {
        $url = rtrim(trim((string) $this->config->get('app.url', '')), '/');
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }
        return $url;
    }
}
