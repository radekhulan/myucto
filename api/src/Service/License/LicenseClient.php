<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP klient licenčního serveru (myucto.cz). Volání jsou JSON POST s timeoutem
 * 5 s; server nemusí při vývoji běžet, proto síťové chyby vyhazujeme jako
 * `LicenseNetworkException` a volající je toleruje (stav se řídí platností tokenu).
 *
 * Endpointy:
 *   POST {server}/api/license/activate
 *   POST {server}/api/license/renew
 *   POST {server}/api/license/deactivate
 *   POST {server}/api/license/upgrade
 *   POST {server}/api/license/quota
 *   POST {server}/api/license/tier
 *   POST {server}/api/license/change-status
 *   POST {server}/api/license/purchase-session
 *   POST {server}/api/license/purchase-claim
 *   POST {server}/api/license/cancel-renewal
 *   POST {server}/api/license/resume-renewal
 *   POST {server}/api/license/support-session
 */
final class LicenseClient
{
    private readonly LoggerInterface $logger;

    /**
     * Kolik čekat na volání, které STRHÁVÁ z karty.
     *
     * Víc než u ostatních: server při něm jde na platební bránu a hned
     * vystavuje doklad. Marné čekání tady nestojí jen čas — uživatel dostane
     * „nevíme, jak platba dopadla", i když peníze odešly.
     */
    private const CHARGE_TIMEOUT = 25;

    public function __construct(
        private readonly Config $config,
        ?LoggerInterface $logger = null,
        private readonly ?Client $http = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param bool $takeover Vynucený přenos vazby z jiné instalace (počítá se do limitu
     *                       přenosů 2/30 dní). Bez něj server u obsazeného klíče vrátí
     *                       `already_bound`.
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function activate(
        string $licenseKey,
        string $instanceId,
        string $fingerprint,
        string $appVersion,
        bool $takeover = false,
        int $usersActive = 0,
        int $companiesActive = 0,
        string $domain = '',
    ): array
    {
        $body = [
            'license_key'      => $licenseKey,
            'instance_id'      => $instanceId,
            'fingerprint'      => $fingerprint,
            'app_version'      => $appVersion,
            'takeover'         => $takeover,
            'users_active'     => max(0, $usersActive),
            'companies_active' => max(0, $companiesActive),
        ];
        if ($domain !== '') {
            $body['domain'] = $domain;
        }

        return $this->post('/api/license/activate', $body);
    }

    /**
     * @param array<string,scalar|null>|null $telemetry Volitelná provozní telemetrie
     *        instance (H-21) — verze, stav migrací, stáří zálohy a dispatcheru, režim
     *        údržby. Sestavuje ji {@see \MyInvoice\Service\System\TelemetryPayloadBuilder}
     *        a neobsahuje NIC identifikujícího. `null` = telemetrie vypnutá nebo se ji
     *        nepodařilo sestavit; do požadavku se pak vůbec nepřikládá a obnova licence
     *        proběhne přesně jako dřív. Starší licenční server pole prostě ignoruje.
     * @param string $domain Doména, na které instalace běží (hostname z `app.url`).
     *        ⚠️ ZÁMĚRNĚ mimo `telemetry` — ta má uzavřený whitelist bez čehokoli
     *        identifikujícího a doména do něj nepatří. Je to samostatné, výslovné
     *        pole: provozovatel potřebuje u licence vidět, kde běží (podpora,
     *        podezření na klon), a tahle vazba má být v kódu vidět, ne schovaná
     *        v „provozní telemetrii". Prázdné = neposílá se.
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function renew(
        string $licenseKey,
        string $instanceId,
        int $counter,
        ?string $prevNonce,
        int $usersActive,
        int $companiesActive,
        string $appVersion,
        ?array $telemetry = null,
        string $domain = '',
    ): array {
        $body = [
            'license_key'      => $licenseKey,
            'instance_id'      => $instanceId,
            'counter'          => $counter,
            'prev_nonce'       => $prevNonce,
            'users_active'     => $usersActive,
            'companies_active' => $companiesActive,
            'app_version'      => $appVersion,
        ];
        if ($telemetry !== null) {
            $body['telemetry'] = $telemetry;
        }
        if ($domain !== '') {
            $body['domain'] = $domain;
        }

        return $this->post('/api/license/renew', $body);
    }

    /**
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    public function deactivate(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/license/deactivate', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Založí checkout session svázanou s instalací a PKCE challenge.
     *
     * @return array<string,mixed> {ok,token,buy_url,expires_in} / {error}
     * @throws LicenseNetworkException
     */
    public function purchaseSession(
        string $instanceId,
        string $state,
        string $codeChallenge,
        string $returnUrl,
    ): array {
        return $this->post('/api/license/purchase-session', [
            'instance_id'   => $instanceId,
            'state'         => $state,
            'code_challenge' => $codeChallenge,
            'return_url'    => $returnUrl,
        ]);
    }

    /**
     * Vymění zaplacený jednorázový order token za klíč a podepsaný token.
     * PKCE verifier zůstává server-to-server a nikdy nejde přes prohlížeč.
     *
     * @return array<string,mixed> {ok,license_key,token,subscription?,instance?} / {error}
     * @throws LicenseNetworkException
     */
    public function purchaseClaim(
        string $orderToken,
        string $codeVerifier,
        string $instanceId,
        string $fingerprint,
        string $appVersion,
        int $usersActive,
        int $companiesActive,
    ): array {
        return $this->post('/api/license/purchase-claim', [
            'order_token'      => $orderToken,
            'code_verifier'    => $codeVerifier,
            'instance_id'      => $instanceId,
            'fingerprint'      => $fingerprint,
            'app_version'      => $appVersion,
            'users_active'     => max(0, $usersActive),
            'companies_active' => max(0, $companiesActive),
        ]);
    }

    /**
     * Vypnutí automatického prodlužování předplatného. NENÍ to deaktivace —
     * licence doběhne do konce zaplaceného období, jen se nestrhne další platba.
     *
     * @return array<string,mixed> {ok,already_cancelled,valid_until,subscription} / {error}
     * @throws LicenseNetworkException
     */
    public function cancelRenewal(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/license/cancel-renewal', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Obnova dobrovolně zrušeného předplatného.
     *
     * ⚠️ NEOBNOVUJE ho rovnou — vrací `pay_url`. Zrušení zneplatnilo mandát
     * u brány, takže se předplatné rozeběhne až po zaplacení novou kartou.
     *
     * @return array<string,mixed> {ok,pay_url,valid_until} / {error}
     * @throws LicenseNetworkException
     */
    public function resumeRenewal(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/license/resume-renewal', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Poměrný doplatek za navýšení počtu uživatelů (quote — jen kalkulace, nestrhává).
     *
     * @return array<string,mixed> {ok,current_users,new_users,amount,currency,period_end} / {error}
     * @throws LicenseNetworkException
     */
    public function upgradeQuote(string $licenseKey, string $instanceId, int $users): array
    {
        return $this->post('/api/license/upgrade', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'users'       => $users,
            'quote'       => true,
        ]);
    }

    /**
     * Navýšení počtu uživatelů (in-place) — strhne poměrný doplatek z uložené karty.
     *
     * ⚠️ Delší timeout: server v jednom požadavku strhne z karty A vystaví
     * doklad. Když se vystavení zdrží, kratší čekání by skončilo hláškou
     * „nevíme, jak platba dopadla" u platby, která proběhla.
     *
     * @return array<string,mixed> {ok,new_users,amount_charged} / {error}
     * @throws LicenseNetworkException
     */
    public function upgrade(string $licenseKey, string $instanceId, int $users, string $quoteToken): array
    {
        return $this->post('/api/license/upgrade', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'users'       => $users,
            'quote_token' => $quoteToken,
        ], self::CHARGE_TIMEOUT);
    }

    /**
     * Kolik by stálo rozšíření úložiště na `$quotaGb` GiB (bez stržení).
     *
     * ⚠️ `$quotaGb` je CÍLOVÁ hodnota z výčtu 2/7/22/102, ne přírůstek —
     * „+5 GB" se posílá jako 7. Server jinou hodnotu odmítne; tichá oprava na
     * nejbližší povolenou by zákazníkovi strhla peníze za jiný objem, než
     * potvrdil.
     *
     * @return array<string,mixed> {ok,current_quota_gb,new_quota_gb,amount,recurring_delta,period_end} / {error}
     * @throws LicenseNetworkException
     */
    public function storageQuote(string $licenseKey, string $instanceId, int $quotaGb): array
    {
        return $this->post('/api/license/quota', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'quota_gb'    => $quotaGb,
            'quote'       => true,
        ]);
    }

    /**
     * Rozšíření úložiště — strhne poměrný doplatek z uložené karty.
     * Delší timeout jako u navýšení míst — viz {@see upgrade()}.
     *
     * @return array<string,mixed> {ok,new_quota_gb,amount_charged,provisioning_pending} / {error}
     * @throws LicenseNetworkException
     */
    public function storageUpgrade(string $licenseKey, string $instanceId, int $quotaGb, string $quoteToken): array
    {
        return $this->post('/api/license/quota', [
            'instance_id' => $instanceId,
            'license_key' => $licenseKey,
            'quota_gb'    => $quotaGb,
            'quote_token' => $quoteToken,
        ], self::CHARGE_TIMEOUT);
    }

    /** @return array<string,mixed> */
    public function tierQuote(string $licenseKey, string $instanceId, string $tier): array
    {
        return $this->post('/api/license/tier', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'tier'        => $tier,
            'quote'       => true,
        ]);
    }

    /** @return array<string,mixed> */
    public function tierChange(string $licenseKey, string $instanceId, string $tier, string $quoteToken): array
    {
        return $this->post('/api/license/tier', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'tier'        => $tier,
            'quote_token' => $quoteToken,
        ], self::CHARGE_TIMEOUT);
    }

    /** @return array<string,mixed> */
    public function changeStatus(string $licenseKey, string $instanceId, string $orderId): array
    {
        return $this->post('/api/license/change-status', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'order_id'    => $orderId,
        ]);
    }

    /**
     * Jednorázový přihlašovací odkaz na portál podpory (myucto.cz/support). Zákazník
     * je na portálu rovnou identifikovaný jako firma, která licenci platí — token
     * v odkazu je jednorázový a krátkodobý (~10 minut).
     *
     * @param array<string,string> $company Záložní předvyplnění fakturačních údajů na
     *        portálu (name/ic/dic/street/city/zip/country/email). Server ho použije jen
     *        tam, kde údaje sám nezná — evidovaný zákazník licence má vždy přednost.
     *        Prázdné pole se do požadavku vůbec nepřikládá.
     * @return array<string,mixed> {ok,url,token,expires_in} / {error}
     * @throws LicenseNetworkException
     */
    public function supportSession(string $licenseKey, string $instanceId, string $appVersion, array $company = []): array
    {
        $body = [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
            'app_version' => $appVersion,
        ];
        if ($company !== []) {
            $body['company'] = $company;
        }

        return $this->post('/api/license/support-session', $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws LicenseNetworkException
     */
    private function post(string $path, array $body, int $timeout = 5): array
    {
        $client = $this->http ?? new Client([
            'base_uri'        => rtrim((string) $this->config->get('license.server_url', 'https://myucto.cz'), '/') . '/',
            'timeout'         => $timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
            // U .web dev domény lze ověření certifikátu vypnout (license.verify_tls=false).
            'verify'          => (bool) $this->config->get('license.verify_tls', true),
        ]);

        try {
            $response = $client->post(ltrim($path, '/'), ['json' => $body]);
        } catch (GuzzleException $e) {
            $this->logger->info('license.client.network_error', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LicenseNetworkException($e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger->info('license.client.bad_response', ['path' => $path, 'status' => $status]);
            throw new LicenseNetworkException("Neplatná odpověď licenčního serveru (HTTP {$status}).");
        }

        return $data;
    }
}
