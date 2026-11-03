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
    public const PROVIDERS = ['entra'];

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
     * @return list<array{id:string,label:string}>
     */
    public function publicList(): array
    {
        $list = [];
        foreach ($this->enabled() as $provider) {
            $list[] = ['id' => $provider->id, 'label' => $provider->label];
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
            allowGuests: (bool) ($s['allow_guests'] ?? true),
        );
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
