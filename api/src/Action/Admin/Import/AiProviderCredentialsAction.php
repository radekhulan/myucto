<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\ResidencyPolicy;
use MyInvoice\Service\Import\ResidencyViolationException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * F7 §3.8 / §13.3 — per-provider správa AI credentials (admin-only, BYOK).
 *
 *   GET    /api/admin/imports/ai/credentials       — status všech 4 providerů
 *   PUT    /api/admin/imports/ai/credentials        — set klíče + testConnection (body.provider)
 *   DELETE /api/admin/imports/ai/credentials?provider=…  — remove klíč providera
 *
 * Secrety jdou VÝHRADNĚ přes {@see \MyInvoice\Service\Auth\SecretEncryption} do
 * `*_enc` sloupců přes dedikované setCredentials klientů — NIKDY přes SettingsAction
 * allowlist (mass-assign `*_enc` zakázán, mustFix #11).
 *
 * Legacy `/api/admin/imports/anthropic/credentials` zůstává jako back-compat alias
 * (obsluhuje ho původní {@see AnthropicCredentialsAction}, beze změny).
 */
final class AiProviderCredentialsAction
{
    private const PROVIDERS = ['anthropic', 'azure_openai', 'openai', 'gemini'];
    /**
     * Zda provider umí pro tenanta dosáhnout EU rezidence dat (FE badge / EU-required
     * filtr). anthropic/gemini v1 = jen US (EU jen přes dedikovaný/Vertex endpoint,
     * mimo rozsah); openai (eu.api.openai.com) a azure (EU region) EU umí.
     */
    private const EU_CAPABLE = [
        'anthropic'    => false,
        'azure_openai' => true,
        'openai'       => true,
        'gemini'       => false,
    ];
    private const COUNTER_COL = [
        'anthropic'    => 'anthropic_extractions_count',
        'azure_openai' => 'azure_extractions_count',
        'openai'       => 'openai_extractions_count',
        'gemini'       => 'gemini_extractions_count',
    ];

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly AzureOpenAiClient $azure,
        private readonly OpenAiClient $openai,
        private readonly GeminiClient $gemini,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);

        $counts = $this->extractionCounts($supplierId);
        $sel    = $this->selection($supplierId);

        $providers = [];
        foreach (self::PROVIDERS as $p) {
            $client = $this->clientFor($p);
            $caps   = $client->capabilities($supplierId);
            $creds  = $client->getCredentials($supplierId);
            $providers[$p] = [
                'configured'        => $creds !== null,
                'default_model'     => $creds['default_model'] ?? $caps->defaultModel,
                // FE kontrakt (AiProviderInfo): `models` je kanonické pole; `allowed_models`
                // zachováno jako back-compat alias.
                'models'            => $caps->models,
                'allowed_models'    => $caps->models,
                'data_region'       => $caps->dataRegion,
                'eu_capable'        => self::EU_CAPABLE[$p] ?? false,
                'residency_label'   => $caps->residencyLabel,
                'extractions_count' => $counts[$p] ?? 0,
            ];
            // Non-secret konfigurace (NIKDY klíč) pro předvyplnění FE formu.
            if ($p === 'azure_openai' && $creds !== null) {
                $providers[$p]['endpoint']    = $creds['endpoint'] ?? null;
                $providers[$p]['deployment']  = $creds['deployment'] ?? null;
                $providers[$p]['api_version'] = $creds['api_version'] ?? null;
            }
            if ($p === 'openai' && $creds !== null) {
                $providers[$p]['base_url'] = $creds['base_url'] ?? null;
            }
        }

        return Json::ok($response, [
            'ai_provider'              => $sel['ai_provider'],
            'ai_data_region'           => $sel['ai_data_region'],
            'ai_eu_residency_required' => $sel['ai_eu_residency_required'],
            'providers'                => $providers,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip     = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua     = $request->getHeaderLine('User-Agent');

        $body     = (array) ($request->getParsedBody() ?? []);
        $provider = (string) ($body['provider'] ?? '');
        if (!in_array($provider, self::PROVIDERS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný provider.', 400);
        }
        $apiKey = (string) ($body['api_key'] ?? '');

        // Descriptor pro validaci (bez DB — postaven z body).
        $caps = $this->capabilitiesFromBody($provider, $body);

        // Model whitelist validace (Azure model = deployment, whitelist nemá).
        $model = trim((string) ($body['default_model'] ?? ''));
        if ($provider !== 'azure_openai' && $model !== '' && !in_array($model, $caps->models, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný model.', 400);
        }

        $existing = $this->clientFor($provider)->getCredentials($supplierId);

        // Config-only update (klíč zůstává) — nemění secret, jen non-secret sloupce.
        if ($apiKey === '' && $existing !== null) {
            $err = $this->updateNonSecretConfig($provider, $supplierId, $body);
            if ($err !== null) {
                return Json::error($response, 'validation_failed', $err, 400);
            }
            $this->logger->log('import.ai_config_changed', $userId, 'supplier', $supplierId, ['provider' => $provider], $ip, $ua);
            // Nešahali jsme na secret ani nevolali testConnection → NEpředstírej úspěšný test
            // (`test_ok=null`, `tested=false`), ať FE neukáže falešný „spojení OK".
            return Json::ok($response, ['saved' => true, 'tested' => false, 'test_ok' => null, 'test_error' => null]);
        }

        if ($apiKey === '') {
            return Json::error($response, 'validation_failed', 'api_key je povinné.', 400);
        }
        // Per-provider validace klíče (§3.8).
        $keyErr = $caps->validateKey($apiKey);
        if ($keyErr !== null) {
            return Json::error($response, 'validation_failed', $keyErr, 400);
        }
        // Azure vyžaduje endpoint + deployment + api-version.
        if ($provider === 'azure_openai') {
            $endpoint = trim((string) ($body['endpoint'] ?? ''));
            $deployment = trim((string) ($body['deployment'] ?? ''));
            $epErr = self::validateAzureEndpoint($endpoint);
            if ($epErr !== null) {
                return Json::error($response, 'validation_failed', $epErr, 400);
            }
            if ($deployment === '') {
                return Json::error($response, 'validation_failed', 'deployment je povinné.', 400);
            }
        }
        // OpenAI base_url: exact-host allowlist (SSRF guard) — jinak by admin-set URL
        // dostala dešifrovaný Bearer klíč + PDF faktury server-side (M2). Prázdné = default.
        if ($provider === 'openai') {
            $baseErr = self::validateOpenaiBaseUrl((string) ($body['base_url'] ?? ''));
            if ($baseErr !== null) {
                return Json::error($response, 'validation_failed', $baseErr, 400);
            }
        }

        // Persist secret přes dedikovaný setCredentials (do *_enc; NIKDY přes SettingsAction).
        $this->persistCredentials($provider, $supplierId, $apiKey, $body);
        $this->logger->log('import.ai_credentials_set', $userId, 'supplier', $supplierId, ['provider' => $provider], $ip, $ua);

        // L1: EU-required tenant nesmí testovat us endpoint — fail-closed před testConnection.
        if ($this->residencyConflict($provider, $supplierId)) {
            return Json::ok($response, [
                'saved'      => true,
                'test_ok'    => false,
                'test_error' => 'residency_conflict',
                'model'      => null,
            ]);
        }

        $test = $this->clientFor($provider)->testConnection($supplierId);
        return Json::ok($response, [
            'saved'      => true,
            'test_ok'    => $test['ok'] ?? false,
            'test_error' => ($test['ok'] ?? false) ? null : ($test['error'] ?? null),
            'model'      => $test['model'] ?? null,
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $provider = (string) ($request->getQueryParams()['provider'] ?? '');
        if (!in_array($provider, self::PROVIDERS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný provider.', 400);
        }
        match ($provider) {
            'anthropic'    => $this->anthropic->setCredentials($supplierId, '', LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL),
            'azure_openai' => $this->azure->clearCredentials($supplierId),
            'openai'       => $this->openai->clearCredentials($supplierId),
            'gemini'       => $this->gemini->clearCredentials($supplierId),
        };
        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip     = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.ai_credentials_removed', $userId, 'supplier', $supplierId, ['provider' => $provider], $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }

    /**
     * POST /api/admin/imports/ai/credentials/test {provider} — spustí testConnection
     * pro daného providera BEZ změny uložených credentials (admin-only). Vrací
     * {test_ok, test_error, model}.
     */
    public function test(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $body     = (array) ($request->getParsedBody() ?? []);
        $provider = (string) ($body['provider'] ?? '');
        if (!in_array($provider, self::PROVIDERS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný provider.', 400);
        }
        // L1: residency gate před síťovým testem — EU-required tenant nesmí testovat us endpoint.
        if ($this->residencyConflict($provider, $supplierId)) {
            return Json::ok($response, ['test_ok' => false, 'test_error' => 'residency_conflict', 'model' => null]);
        }
        $result = $this->clientFor($provider)->testConnection($supplierId);
        return Json::ok($response, [
            'test_ok'    => $result['ok'] ?? false,
            'test_error' => ($result['ok'] ?? false) ? null : ($result['error'] ?? null),
            'model'      => $result['model'] ?? null,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function isAdmin(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'settings.ai_provider', AccessLevel::WRITE);
    }

    private function clientFor(string $provider): LlmGatewayInterface
    {
        return match ($provider) {
            'anthropic'    => $this->anthropic,
            'azure_openai' => $this->azure,
            'openai'       => $this->openai,
            'gemini'       => $this->gemini,
        };
    }

    /** @param array<string,mixed> $body */
    private function capabilitiesFromBody(string $provider, array $body): LlmProviderCapabilities
    {
        return match ($provider) {
            'anthropic'    => LlmProviderCapabilities::anthropic(),
            'openai'       => LlmProviderCapabilities::openai((string) ($body['base_url'] ?? ''), (string) ($body['default_model'] ?? '')),
            'gemini'       => LlmProviderCapabilities::gemini((string) ($body['default_model'] ?? '')),
            'azure_openai' => LlmProviderCapabilities::azureOpenai((string) ($body['endpoint'] ?? ''), (string) ($body['deployment'] ?? ''), 'eu'),
        };
    }

    /** @param array<string,mixed> $body */
    private function persistCredentials(string $provider, int $supplierId, string $apiKey, array $body): void
    {
        switch ($provider) {
            case 'anthropic':
                $model = trim((string) ($body['default_model'] ?? '')) ?: LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;
                $this->anthropic->setCredentials($supplierId, $apiKey, $model);
                break;
            case 'azure_openai':
                $this->azure->setCredentials(
                    $supplierId, $apiKey,
                    (string) ($body['endpoint'] ?? ''),
                    (string) ($body['deployment'] ?? ''),
                    (string) ($body['api_version'] ?? ''),
                );
                break;
            case 'openai':
                $this->openai->setCredentials(
                    $supplierId, $apiKey,
                    (string) ($body['default_model'] ?? ''),
                    (string) ($body['base_url'] ?? ''),
                );
                break;
            case 'gemini':
                $this->gemini->setCredentials($supplierId, $apiKey, (string) ($body['default_model'] ?? ''));
                break;
        }
    }

    /**
     * Config-only update (klíč zůstává) — jen non-secret sloupce, NIKDY `*_enc`.
     * Updatuje POUZE pole skutečně přítomná v requestu (L4): vynechané klíče se
     * NEnullují (jinak by dílčí PATCH smazal existující endpoint/deployment/base_url).
     * @param array<string,mixed> $body
     * @return string|null chybová hláška, nebo null při úspěchu
     */
    private function updateNonSecretConfig(string $provider, int $supplierId, array $body): ?string
    {
        $pdo = $this->db->pdo();
        switch ($provider) {
            case 'anthropic':
                $model = trim((string) ($body['default_model'] ?? ''));
                if ($model !== '') {
                    $this->anthropic->updateDefaultModel($supplierId, $model);
                }
                return null;
            case 'openai':
                $sets = [];
                $params = [];
                if (array_key_exists('default_model', $body)) {
                    $sets[] = 'openai_default_model = ?';
                    $params[] = trim((string) $body['default_model']) ?: LlmProviderCapabilities::OPENAI_DEFAULT_MODEL;
                }
                if (array_key_exists('base_url', $body)) {
                    $baseUrl = trim((string) $body['base_url']);
                    $baseErr = self::validateOpenaiBaseUrl($baseUrl);
                    if ($baseErr !== null) {
                        return $baseErr;
                    }
                    $sets[] = 'openai_base_url = ?';
                    $params[] = $baseUrl !== '' ? $baseUrl : null;
                }
                if ($sets !== []) {
                    $params[] = $supplierId;
                    $pdo->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
                }
                return null;
            case 'gemini':
                if (array_key_exists('default_model', $body)) {
                    $pdo->prepare('UPDATE supplier SET gemini_default_model = ? WHERE id = ?')->execute([
                        trim((string) $body['default_model']) ?: LlmProviderCapabilities::GEMINI_DEFAULT_MODEL,
                        $supplierId,
                    ]);
                }
                return null;
            case 'azure_openai':
                $sets = [];
                $params = [];
                if (array_key_exists('endpoint', $body)) {
                    $endpoint = trim((string) $body['endpoint']);
                    if ($endpoint !== '') {
                        $epErr = self::validateAzureEndpoint($endpoint);
                        if ($epErr !== null) {
                            return $epErr;
                        }
                    }
                    $sets[] = 'azure_openai_endpoint = ?';
                    $params[] = $endpoint !== '' ? $endpoint : null;
                }
                if (array_key_exists('deployment', $body)) {
                    $deployment = trim((string) $body['deployment']);
                    $sets[] = 'azure_openai_deployment = ?';
                    $params[] = $deployment !== '' ? $deployment : null;
                }
                if (array_key_exists('api_version', $body)) {
                    $sets[] = 'azure_openai_api_version = ?';
                    $params[] = trim((string) $body['api_version']) ?: '2024-10-21';
                }
                if ($sets !== []) {
                    $params[] = $supplierId;
                    $pdo->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
                }
                return null;
        }
        return null;
    }

    /**
     * SSRF/residency guard pro OpenAI base_url (M2): povolen jen https na EXACT host
     * `api.openai.com` / `eu.api.openai.com`. Prázdné = default (api.openai.com). Model
     * whitelist je fixní, legitimní custom-host neexistuje.
     */
    /**
     * Azure endpoint host allowlist (SSRF guard + residency, MEDIUM ze security auditu):
     * bez allowlistu by admin-set URL dostala dešifrovaný klíč + PDF faktury na
     * libovolný host, a `azureRegion` by rezidenci odvozovala z crafted hostname
     * (`x-swedencentral.attacker.tld`). Povolené jen skutečné Azure hosty; region se
     * pak (pro ověřený Azure host) bere z deklarovaného ai_data_region.
     */
    private static function validateAzureEndpoint(string $endpoint): ?string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return 'endpoint je povinný.';
        }
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if ($scheme !== 'https') {
            return 'azure endpoint musí používat https.';
        }
        $allowed = ['.openai.azure.com', '.cognitiveservices.azure.com', '.azure-api.net'];
        foreach ($allowed as $suffix) {
            if ($host !== '' && str_ends_with($host, $suffix)) {
                return null;
            }
        }
        return 'azure endpoint musí směřovat na *.openai.azure.com / *.cognitiveservices.azure.com / *.azure-api.net.';
    }

    private static function validateOpenaiBaseUrl(string $baseUrl): ?string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return null; // default endpoint
        }
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($scheme !== 'https') {
            return 'openai_base_url musí používat https.';
        }
        if (!in_array($host, ['api.openai.com', 'eu.api.openai.com'], true)) {
            return 'openai_base_url musí směřovat na api.openai.com nebo eu.api.openai.com.';
        }
        return null;
    }

    /**
     * L1: vrací true, když tenant vyžaduje EU rezidenci a testovaný provider se
     * (dle reálného regionu z capabilities) rozpadá na non-EU → test se nesmí spustit.
     */
    private function residencyConflict(string $provider, int $supplierId): bool
    {
        if (!$this->selection($supplierId)['ai_eu_residency_required']) {
            return false;
        }
        try {
            $region = $this->clientFor($provider)->capabilities($supplierId)->dataRegion;
            (new ResidencyPolicy())->assertAllowed($provider, $region, true);
            return false;
        } catch (ResidencyViolationException) {
            return true;
        }
    }

    /** @return array<string,int> */
    private function extractionCounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT anthropic_extractions_count, azure_extractions_count, openai_extractions_count, gemini_extractions_count
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'anthropic'    => (int) ($row['anthropic_extractions_count'] ?? 0),
            'azure_openai' => (int) ($row['azure_extractions_count'] ?? 0),
            'openai'       => (int) ($row['openai_extractions_count'] ?? 0),
            'gemini'       => (int) ($row['gemini_extractions_count'] ?? 0),
        ];
    }

    /** @return array{ai_provider:string, ai_data_region:string, ai_eu_residency_required:bool} */
    private function selection(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ai_provider, ai_data_region, ai_eu_residency_required FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'ai_provider'              => (string) ($row['ai_provider'] ?? 'anthropic'),
            'ai_data_region'           => (string) ($row['ai_data_region'] ?? 'us'),
            'ai_eu_residency_required' => (bool) ($row['ai_eu_residency_required'] ?? false),
        ];
    }
}
