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
use MyInvoice\Service\Ai\OllamaEndpointException;
use MyInvoice\Service\Import\AiCredentialsBulkApplier;
use MyInvoice\Service\Import\AiModelCatalog;
use MyInvoice\Service\Import\ClientAiExtractionPolicy;
use MyInvoice\Service\Import\ClientAiExtractionQuota;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\OpenAiClient;
use MyInvoice\Service\Import\ResidencyPolicy;
use MyInvoice\Service\Import\ResidencyViolationException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * F7 §3.8 / §13.3 — per-provider správa AI credentials (admin-only, BYOK).
 *
 *   GET    /api/admin/imports/ai/credentials       — status všech providerů ({@see LlmProviderRegistry::PROVIDERS})
 *   PUT    /api/admin/imports/ai/credentials        — set klíče + testConnection (body.provider)
 *   DELETE /api/admin/imports/ai/credentials?provider=…  — remove klíč providera
 *   POST   /api/admin/imports/ai/{anthropic,openai,gemini}/models — modely pro zadaný nebo uložený klíč
 *   POST   /api/admin/imports/ai/ollama/models {base_url}  — modely v Ollamě
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
    private const PROVIDERS = LlmProviderRegistry::PROVIDERS;
    private const CLOUD_CATALOG_PROVIDERS = ['anthropic', 'openai', 'gemini'];
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
        'ollama'       => true,
    ];
    private const COUNTER_COL = [
        'anthropic'    => 'anthropic_extractions_count',
        'azure_openai' => 'azure_extractions_count',
        'openai'       => 'openai_extractions_count',
        'gemini'       => 'gemini_extractions_count',
        'ollama'       => 'ollama_extractions_count',
    ];

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly AzureOpenAiClient $azure,
        private readonly OpenAiClient $openai,
        private readonly GeminiClient $gemini,
        private readonly OllamaClient $ollama,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly AiCredentialsBulkApplier $bulkApplier,
        private readonly ClientAiExtractionQuota $clientQuota,
    ) {}

    /**
     * GET /api/admin/imports/ai-extract-pdf{,-issued}/status — stav AI pro stránky
     * vytěžení. Jen aktivní poskytovatel a jeho modely, bez klíčů a konfigurace;
     * AI nastavuje firma, používat ho smí každý s právem vytěžovat doklady.
     */
    public function extractionStatus(Request $request, Response $response): Response
    {
        if (!ClientAiExtractionPolicy::hasDocumentPermissions($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        return $this->safeExtractionStatus($request, $response);
    }

    public function issuedExtractionStatus(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        return $this->safeExtractionStatus($request, $response);
    }

    private function safeExtractionStatus(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $provider = $this->selection($supplierId)['ai_provider'];
        $providers = [];
        if (in_array($provider, self::PROVIDERS, true)) {
            $client = $this->clientFor($provider);
            $caps   = $client->capabilities($supplierId);
            $creds  = $client->getCredentials($supplierId);
            $providers[$provider] = [
                'configured'     => $creds !== null,
                'default_model'  => $creds['default_model'] ?? $caps->defaultModel,
                'models'         => $caps->models,
                'allowed_models' => $caps->models,
            ];
        }

        return Json::ok($response, [
            'client_ai_extraction_enabled' => $this->clientExtractionEnabled($supplierId),
            'client_ai_extraction_usage' => $this->clientQuota->usage($supplierId),
            'ai_provider' => $provider,
            'providers'   => $providers,
        ]);
    }

    private function clientExtractionEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT client_ai_extraction_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

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
                'replaced_model'    => $creds['replaced_model'] ?? null,
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
            if ($p === 'anthropic' && $creds !== null) {
                $providers[$p]['workspace_id'] = $creds['workspace_id'] ?? null;
            }
            if ($p === 'ollama' && $creds !== null) {
                $providers[$p]['base_url']    = $creds['base_url'];
                $providers[$p]['has_api_key'] = $creds['api_key'] !== '';
            }
        }

        $tuning = $this->tuning($supplierId);

        return Json::ok($response, [
            'client_ai_extraction_enabled' => $this->clientExtractionEnabled($supplierId),
            'client_ai_extraction_usage' => $this->clientQuota->usage($supplierId),
            'ai_provider'              => $sel['ai_provider'],
            'ai_data_region'           => $sel['ai_data_region'],
            'ai_eu_residency_required' => $sel['ai_eu_residency_required'],
            'ai_extraction_notes'      => $tuning['ai_extraction_notes'],
            'ai_effort'                => $tuning['ai_effort'],
            'ai_efforts'               => LlmProviderCapabilities::EFFORTS,
            'ai_extraction_notes_max'  => InvoiceExtractionPrompt::TENANT_NOTES_MAX,
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
        foreach (['api_key', 'default_model', 'base_url'] as $field) {
            if (isset($body[$field]) && !is_string($body[$field])) {
                return Json::error($response, 'validation_failed', 'Neplatná hodnota pro ' . $field . '.', 400);
            }
        }
        $apiKey = trim($body['api_key'] ?? '');

        // Descriptor pro validaci (bez DB — postaven z body).
        $caps = $this->capabilitiesFromBody($provider, $body);

        // Rozkopírování do dalších firem jen z přihlášené relace: hromadný zápis
        // klíčů napříč firmami nemá jít přes API token.
        $bulk = ($body['apply_to_all_companies'] ?? false) === true;
        $onlyUnconfigured = ($body['only_unconfigured'] ?? true) !== false;
        if ($bulk && !RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'forbidden', 'Hromadné uložení je dostupné jen z přihlášené relace.', 403);
        }

        if ($provider === 'ollama') {
            return $this->updateOllama($request, $response, $supplierId, $body, $userId, $ip, $ua, $bulk, $onlyUnconfigured);
        }

        if ($provider === 'anthropic' && array_key_exists('workspace_id', $body)) {
            if (!is_string($body['workspace_id']) && $body['workspace_id'] !== null) {
                return Json::error($response, 'workspace_id_invalid', 'Neplatné ID workspace.', 400);
            }
            $body['workspace_id'] = trim((string) $body['workspace_id']);
            $wsErr = AnthropicClient::workspaceIdError($body['workspace_id']);
            if ($wsErr !== null) {
                return Json::error($response, 'workspace_id_invalid', $wsErr, 400);
            }
        }

        $existing = $this->clientFor($provider)->getCredentials($supplierId);
        if ($apiKey !== '') {
            $keyErr = $caps->validateKey($apiKey);
            if ($keyErr !== null) {
                return Json::error($response, 'validation_failed', $keyErr, 400);
            }
        }
        if (in_array($provider, self::CLOUD_CATALOG_PROVIDERS, true) && ($existing !== null || $apiKey !== '')) {
            $model = trim((string) ($body['default_model'] ?? ''));
            $baseUrl = array_key_exists('base_url', $body)
                ? trim((string) $body['base_url'])
                : ($apiKey === '' ? ($existing['base_url'] ?? '') : '');
            $workspaceId = $body['workspace_id'] ?? ($existing['workspace_id'] ?? '');
            $error = $provider === 'openai' ? OpenAiClient::baseUrlError($baseUrl) : null;
            if ($error !== null) {
                return Json::error($response, 'openai_base_url_invalid', $error, 400);
            }
            if ($provider === 'openai'
                && OpenAiClient::normalizeBaseUrl($baseUrl) !== OpenAiClient::normalizeBaseUrl($existing['base_url'] ?? '')
                && $this->residencyConflict($provider, $supplierId, LlmProviderCapabilities::openai($baseUrl, null)->dataRegion)) {
                return Json::error($response, 'residency_conflict', 'Poskytovatel nesplňuje požadavek na EU rezidenci.', 422);
            }
            if ($model !== '' && !in_array($model, $caps->models, true)) {
                $error = $this->validateCloudProjectModel($provider, $supplierId, $model, $baseUrl, $workspaceId, $apiKey);
                if ($error !== null) {
                    return Json::error($response, $error['code'], $error['message'], 400);
                }
            }
        }

        // Config-only update (klíč zůstává) — nemění secret, jen non-secret sloupce.
        if ($apiKey === '' && $existing !== null) {
            $err = $this->updateNonSecretConfig($provider, $supplierId, $body);
            if ($err !== null) {
                return Json::error($response, 'validation_failed', $err, 400);
            }
            $this->logger->log('import.ai_config_changed', $userId, 'supplier', $supplierId, ['provider' => $provider], $ip, $ua);
            return $this->configSaved($response, $bulk);
        }

        if ($apiKey === '') {
            return Json::error($response, 'validation_failed', 'api_key je povinné.', 400);
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
        // Persist secret přes dedikovaný setCredentials (do *_enc; NIKDY přes SettingsAction).
        $this->persistCredentials($provider, $supplierId, $apiKey, $body);
        $this->logger->log('import.ai_credentials_set', $userId, 'supplier', $supplierId, ['provider' => $provider], $ip, $ua);

        $result = ['saved' => true] + $this->runTest($provider, $supplierId);
        if ($bulk) {
            $result += $this->bulkOutcome($request, $supplierId, $provider, $onlyUnconfigured, $result, $ip, $ua);
        }
        return Json::ok($response, $result);
    }

    private function configSaved(Response $response, bool $bulk): Response
    {
        $result = ['saved' => true, 'tested' => false, 'test_ok' => null, 'test_error' => null];
        if ($bulk) {
            $result['bulk'] = ['applied' => false, 'reason' => 'key_required'];
        }
        return Json::ok($response, $result);
    }

    /**
     * Jediné volání poskytovatele za celé uložení — i při rozkopírování do dalších
     * firem se klíč testuje jen tady, na aktuální firmě (test stojí uživatele peníze).
     *
     * @param array<string,mixed>|null $known výsledek testConnection, který volající už má (Ollama)
     * @return array{test_ok:bool, test_error:?string, model:?string}
     */
    private function runTest(string $provider, int $supplierId, ?array $known = null): array
    {
        // L1: EU-required tenant nesmí testovat us endpoint — fail-closed před testConnection.
        if ($this->residencyConflict($provider, $supplierId)) {
            return ['test_ok' => false, 'test_error' => 'residency_conflict', 'model' => null];
        }
        $test = $known ?? $this->clientFor($provider)->testConnection($supplierId);
        return [
            'test_ok'    => (bool) ($test['ok'] ?? false),
            'test_error' => ($test['ok'] ?? false) ? null : ($test['error'] ?? null),
            'model'      => $test['model'] ?? null,
        ];
    }

    /**
     * @param array{test_ok:bool} $test
     * @return array{bulk: array<string,mixed>}
     */
    private function bulkOutcome(
        Request $request,
        int $supplierId,
        string $provider,
        bool $onlyUnconfigured,
        array $test,
        ?string $ip,
        string $ua,
    ): array {
        if (!$test['test_ok']) {
            return ['bulk' => ['applied' => false, 'reason' => 'test_failed']];
        }
        return ['bulk' => ['applied' => true]
            + $this->bulkApplier->apply($request, $supplierId, $provider, $onlyUnconfigured, $ip, $ua)];
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
            'anthropic'    => $this->anthropic->setCredentials($supplierId, '', LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL, ''),
            'azure_openai' => $this->azure->clearCredentials($supplierId),
            'openai'       => $this->openai->clearCredentials($supplierId),
            'gemini'       => $this->gemini->clearCredentials($supplierId),
            'ollama'       => $this->ollama->clearCredentials($supplierId),
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

    public function cloudModels(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $provider = $args['provider'] ?? '';
        if (!in_array($provider, self::CLOUD_CATALOG_PROVIDERS, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný provider.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        foreach (['api_key', 'base_url', 'workspace_id'] as $field) {
            if (isset($body[$field]) && !is_string($body[$field])) {
                return Json::error($response, 'validation_failed', 'Neplatná hodnota pro ' . $field . '.', 400);
            }
        }
        $caps = $this->capabilitiesFromBody($provider, []);
        $existing = $this->clientFor($provider)->getCredentials($supplierId);
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $baseUrl = trim((string) ($body['base_url'] ?? ($existing['base_url'] ?? '')));
        $workspaceId = trim((string) ($body['workspace_id'] ?? ($existing['workspace_id'] ?? '')));

        if ($provider === 'openai') {
            $error = OpenAiClient::baseUrlError($baseUrl);
            if ($error !== null) {
                return Json::error($response, 'openai_base_url_invalid', $error, 400);
            }
            $caps = LlmProviderCapabilities::openai($baseUrl, null);
            if ($apiKey === '' && OpenAiClient::normalizeBaseUrl($baseUrl) !== OpenAiClient::normalizeBaseUrl($existing['base_url'] ?? '')) {
                return Json::error($response, 'model_catalog_endpoint_key_required', 'Pro jinou adresu zadejte API klíč.', 400);
            }
        }
        if ($provider === 'anthropic' && ($error = AnthropicClient::workspaceIdError($workspaceId)) !== null) {
            return Json::error($response, 'workspace_id_invalid', $error, 400);
        }
        if ($this->residencyConflict($provider, $supplierId, $caps->dataRegion)) {
            return Json::error($response, 'residency_conflict', 'Poskytovatel nesplňuje požadavek na EU rezidenci.', 409);
        }
        $apiKey = $apiKey !== '' ? $apiKey : ($existing['api_key'] ?? '');
        if ($apiKey === '') {
            return Json::error($response, 'provider_not_configured', 'Zadejte API klíč.', 400);
        }
        if ($caps->validateKey($apiKey) !== null) {
            return Json::error($response, AiModelCatalog::INVALID_KEY, 'API klíč má neplatný formát.', 400);
        }

        $list = $this->loadCloudModels($provider, $apiKey, $baseUrl, $workspaceId);
        return match ($list['error']) {
            null => Json::ok($response, ['models' => $list['models']]),
            AiModelCatalog::INVALID_KEY => Json::error($response, AiModelCatalog::INVALID_KEY, 'Poskytovatel odmítl API klíč.', 422),
            AiModelCatalog::INCOMPLETE => Json::ok($response, ['models' => $list['models'] ?: $caps->models, 'catalog_error' => $list['error']]),
            default => Json::ok($response, ['models' => $caps->models, 'catalog_error' => $list['error']]),
        };
    }

    /** @return array{models:list<string>, error:?string} */
    private function loadCloudModels(string $provider, #[\SensitiveParameter] string $apiKey, string $baseUrl, string $workspaceId): array
    {
        return match ($provider) {
            'anthropic' => $this->anthropic->listModels($apiKey, $workspaceId),
            'openai' => $this->openai->listModels($apiKey, $baseUrl),
            'gemini' => $this->gemini->listModels($apiKey),
        };
    }

    /** @return array{code:string, message:string}|null */
    private function validateCloudProjectModel(
        string $provider,
        int $supplierId,
        string $model,
        string $baseUrl,
        string $workspaceId,
        #[\SensitiveParameter] string $apiKey,
    ): ?array
    {
        $supported = match ($provider) {
            'anthropic' => LlmProviderCapabilities::isAnthropicPdfModel($model),
            'openai' => LlmProviderCapabilities::isOpenaiPdfModel($model),
            'gemini' => LlmProviderCapabilities::isGeminiPdfModel($model),
        };
        if (!$supported) {
            return ['code' => 'model_invalid', 'message' => 'Neplatný model.'];
        }
        if ($apiKey === '') {
            return null;
        }
        $region = $provider === 'openai' ? LlmProviderCapabilities::openai($baseUrl, null)->dataRegion : 'us';
        if ($this->residencyConflict($provider, $supplierId, $region)) {
            return ['code' => 'residency_conflict', 'message' => 'Poskytovatel nesplňuje požadavek na EU rezidenci.'];
        }
        $list = $this->loadCloudModels($provider, $apiKey, $baseUrl, $workspaceId);
        if (in_array($model, $list['models'], true)) {
            return null;
        }
        return match ($list['error']) {
            null, AiModelCatalog::EMPTY => ['code' => 'model_unavailable', 'message' => 'Model není pro tento API klíč dostupný.'],
            AiModelCatalog::INVALID_KEY => ['code' => AiModelCatalog::INVALID_KEY, 'message' => 'Poskytovatel odmítl API klíč.'],
            AiModelCatalog::INCOMPLETE => ['code' => AiModelCatalog::INCOMPLETE, 'message' => 'Seznam modelů od poskytovatele se nepodařilo načíst celý.'],
            default => ['code' => AiModelCatalog::UNAVAILABLE, 'message' => 'Seznam modelů od poskytovatele se nepodařilo načíst.'],
        };
    }

    /**
     * POST /api/admin/imports/ai/ollama/models {base_url} — modely nainstalované v Ollamě
     * (admin-only). POST kvůli CSRF: server se podle adresy připojuje ven a GET by spustil
     * i podvržený odkaz. Uložený klíč se pošle jen na tutéž adresu, na kterou byl uložen.
     */
    public function ollamaModels(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if ((isset($body['api_key']) && !is_string($body['api_key'])) || (isset($body['base_url']) && !is_string($body['base_url']))) {
            return Json::error($response, 'validation_failed', 'Neplatný API klíč nebo adresa Ollamy.', 400);
        }
        $baseUrl = trim((string) ($body['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = (string) ($this->ollama->getCredentials($supplierId)['base_url'] ?? '');
        }
        if ($baseUrl === '') {
            return Json::error($response, 'validation_failed', 'Zadejte adresu Ollamy.', 400);
        }
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $clearKey = ($body['clear_api_key'] ?? false) === true;
        $keyError = LlmProviderCapabilities::ollama(null, 'us')->validateKey($apiKey);
        if (!$clearKey && $keyError !== null) {
            return Json::error($response, 'validation_failed', $keyError, 400);
        }
        $list = $this->ollama->listModels($baseUrl, $clearKey ? '' : (
            $apiKey !== '' ? $apiKey : $this->storedOllamaKeyFor($supplierId, $baseUrl)
        ));
        if (!$list['ok']) {
            return Json::error($response, $list['code'], $list['error'], 422);
        }
        return Json::ok($response, ['models' => $list['models']]);
    }

    /**
     * Ollama umožňuje uložení adresy a modelu i bez uloženého API klíče.
     *
     * @param array<string,mixed> $body
     */
    private function updateOllama(Request $request, Response $response, int $supplierId, #[\SensitiveParameter] array $body, int $userId, ?string $ip, string $ua, bool $bulk, bool $onlyUnconfigured): Response
    {
        $existing = $this->ollama->getCredentials($supplierId);
        $baseUrl = trim((string) ($body['base_url'] ?? ($existing['base_url'] ?? '')));
        $model   = trim((string) ($body['default_model'] ?? ($existing['default_model'] ?? '')));
        if ($baseUrl === '' || $model === '') {
            return Json::error($response, 'validation_failed', 'Adresa Ollamy i model jsou povinné.', 400);
        }
        if (strlen($model) > 128 || preg_match('~\A(?:[\w.-]+(?::[0-9]+)?/)*[\w.-]+(?::[\w.-]+)?\z~', $model) !== 1) {
            return Json::error($response, 'validation_failed', 'Neplatný model.', 400);
        }
        $clearKey = ($body['clear_api_key'] ?? false) === true;
        $newKey = $clearKey ? '' : trim((string) ($body['api_key'] ?? ''));
        if ($newKey !== '') {
            $keyErr = LlmProviderCapabilities::ollama($model, 'us')->validateKey($newKey);
            if ($keyErr !== null) {
                return Json::error($response, 'validation_failed', $keyErr, 400);
            }
            $list = $this->ollama->listModels($baseUrl, $newKey);
            if (!$list['ok']) {
                return Json::error($response, 'validation_failed', $list['error'], 400);
            }
            if (!in_array($model, array_column($list['models'], 'name'), true)) {
                return Json::error($response, 'validation_failed', OllamaClient::message('ollama_model_missing'), 400);
            }
        }
        $apiKey = $this->resolveOllamaKey($supplierId, $baseUrl, $newKey !== '' ? $newKey : null, $clearKey);
        try {
            $this->ollama->setCredentials($supplierId, $baseUrl, $model, $apiKey);
        } catch (OllamaEndpointException $e) {
            return Json::error($response, 'validation_failed', OllamaClient::message($e->errorCode), 400);
        }
        $this->logger->log('import.ai_credentials_set', $userId, 'supplier', $supplierId, ['provider' => 'ollama'], $ip, $ua);
        if ($newKey === '') {
            return $this->configSaved($response, $bulk);
        }

        // Spojení i model ověřil seznam výše; testConnection by /api/tags a všechna /api/show zopakoval.
        $result = ['saved' => true] + $this->runTest('ollama', $supplierId, ['ok' => true, 'model' => $model]);
        if ($bulk) {
            $result += $this->bulkOutcome($request, $supplierId, 'ollama', $onlyUnconfigured, $result, $ip, $ua);
        }
        return Json::ok($response, $result);
    }

    /**
     * Co uložit do `ollama_api_key_enc`: null = ponechat, '' = smazat, jinak nový klíč.
     * Změna adresy bez nového klíče (i nečitelná uložená konfigurace) klíč maže — jinak by uložený Bearer token odešel
     * na host, pro který nebyl zadán.
     */
    private function resolveOllamaKey(int $supplierId, string $baseUrl, #[\SensitiveParameter] ?string $newKey, bool $clear): ?string
    {
        if ($clear) {
            return '';
        }
        if ($newKey !== null) {
            return $newKey;
        }
        $existing = $this->ollama->getCredentials($supplierId);
        if ($existing === null) {
            // Nečitelná/neúplná konfigurace: případný osiřelý šifrovaný klíč nesmí přežít pod novou adresou.
            return '';
        }
        if ($existing['api_key'] === '') {
            return null;
        }
        return $this->ollama->sameBaseUrl($existing['base_url'], $baseUrl) ? null : '';
    }

    /** Uložený klíč, ale jen pro tutéž adresu, na kterou byl uložen. */
    private function storedOllamaKeyFor(int $supplierId, string $baseUrl): string
    {
        $existing = $this->ollama->getCredentials($supplierId);
        return ($existing !== null && $this->ollama->sameBaseUrl($existing['base_url'], $baseUrl)) ? $existing['api_key'] : '';
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
            'ollama'       => $this->ollama,
        };
    }

    /** @param array<string,mixed> $body */
    private function capabilitiesFromBody(string $provider, array $body): LlmProviderCapabilities
    {
        return match ($provider) {
            'anthropic'    => LlmProviderCapabilities::anthropic(),
            'openai'       => LlmProviderCapabilities::openai(null, null),
            'gemini'       => LlmProviderCapabilities::gemini(null),
            'ollama'       => LlmProviderCapabilities::ollama((string) ($body['default_model'] ?? ''), 'us'),
            'azure_openai' => LlmProviderCapabilities::azureOpenai((string) ($body['endpoint'] ?? ''), (string) ($body['deployment'] ?? ''), 'eu'),
        };
    }

    /** @param array<string,mixed> $body */
    private function persistCredentials(string $provider, int $supplierId, #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] array $body): void
    {
        switch ($provider) {
            case 'anthropic':
                $model = trim((string) ($body['default_model'] ?? '')) ?: LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;
                // Bez pole v požadavku zůstává uložené ID workspace (null), validace proběhla v update().
                $workspaceId = array_key_exists('workspace_id', $body) ? (string) $body['workspace_id'] : null;
                $this->anthropic->setCredentials($supplierId, $apiKey, $model, $workspaceId);
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
                    trim((string) ($body['default_model'] ?? '')),
                    trim((string) ($body['base_url'] ?? '')),
                );
                break;
            case 'gemini':
                $this->gemini->setCredentials($supplierId, $apiKey, trim((string) ($body['default_model'] ?? '')));
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
                if (array_key_exists('default_model', $body)) {
                    $model = trim((string) $body['default_model']) ?: LlmProviderCapabilities::ANTHROPIC_DEFAULT_MODEL;
                    $this->anthropic->updateDefaultModel($supplierId, $model);
                }
                if (array_key_exists('workspace_id', $body)) {
                    $this->anthropic->updateWorkspaceId($supplierId, (string) $body['workspace_id']);
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
     * Azure endpoint host allowlist (SSRF guard + residency, MEDIUM ze security auditu):
     * bez allowlistu by admin-set URL dostala dešifrovaný klíč + PDF faktury na
     * libovolný host, a `azureRegion` by rezidenci odvozovala z crafted hostname
     * (`x-swedencentral.attacker.tld`). Povolené jen skutečné Azure hosty; region se
     * pak (pro ověřený Azure host) bere z deklarovaného ai_data_region.
     *
     * Stejné pravidlo se kontroluje i při každém volání a klienti nenásledují
     * přesměrování ({@see AzureOpenAiClient::endpointError()}).
     */
    private static function validateAzureEndpoint(string $endpoint): ?string
    {
        return AzureOpenAiClient::endpointError($endpoint);
    }

    /**
     * L1: vrací true, když tenant vyžaduje EU rezidenci a testovaný provider se
     * (dle reálného regionu z capabilities) rozpadá na non-EU → test se nesmí spustit.
     */
    private function residencyConflict(string $provider, int $supplierId, ?string $region = null): bool
    {
        if (!$this->selection($supplierId)['ai_eu_residency_required']) {
            return false;
        }
        try {
            $region ??= $this->clientFor($provider)->capabilities($supplierId)->dataRegion;
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
            'SELECT anthropic_extractions_count, azure_extractions_count, openai_extractions_count, gemini_extractions_count, ollama_extractions_count
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'anthropic'    => (int) ($row['anthropic_extractions_count'] ?? 0),
            'azure_openai' => (int) ($row['azure_extractions_count'] ?? 0),
            'openai'       => (int) ($row['openai_extractions_count'] ?? 0),
            'gemini'       => (int) ($row['gemini_extractions_count'] ?? 0),
            'ollama'       => (int) ($row['ollama_extractions_count'] ?? 0),
        ];
    }

    /**
     * PUT /api/admin/imports/ai/tuning — dvě per-tenant páčky na ladění extrakce:
     * volné poznámky do promptu a volba rychle/přesně. Žádné secrety, takže žádný
     * testConnection ani dotyk `*_enc` sloupců.
     *
     * Updatuje POUZE pole skutečně přítomná v requestu, aby dílčí PATCH nesmazal to
     * druhé.
     */
    public function updateTuning(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        $supplierId = SupplierGuard::currentId($request);
        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $body   = (array) ($request->getParsedBody() ?? []);

        $sets   = [];
        $params = [];

        if (array_key_exists('ai_extraction_notes', $body)) {
            $notes = trim((string) $body['ai_extraction_notes']);
            if (mb_strlen($notes) > InvoiceExtractionPrompt::TENANT_NOTES_MAX) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Poznámky k extrakci mohou mít nejvýš ' . InvoiceExtractionPrompt::TENANT_NOTES_MAX . ' znaků.',
                    422,
                );
            }
            $sets[]   = 'ai_extraction_notes = ?';
            $params[] = $notes !== '' ? $notes : null;
        }

        if (array_key_exists('ai_effort', $body)) {
            $effort = trim((string) $body['ai_effort']);
            if (!in_array($effort, LlmProviderCapabilities::EFFORTS, true)) {
                return Json::error($response, 'validation_failed', 'Neplatná míra uvažování AI.', 422);
            }
            $sets[]   = 'ai_effort = ?';
            $params[] = $effort;
        }

        if ($sets === []) {
            return Json::error($response, 'validation_failed', 'Nebylo co uložit.', 400);
        }

        $params[] = $supplierId;
        $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $this->logger->log(
            'import.ai_tuning_changed',
            $userId,
            'supplier',
            $supplierId,
            // Text poznámek se do auditu NEукládá (může nést obchodní detaily), jen že se změnil.
            ['fields' => array_keys(array_intersect_key($body, ['ai_extraction_notes' => 1, 'ai_effort' => 1]))],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, $this->tuning($supplierId) + ['saved' => true]);
    }

    /** @return array{ai_extraction_notes:string, ai_effort:string} */
    private function tuning(int $supplierId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT ai_extraction_notes, ai_effort FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            // Kód napřed proti nezmigrované DB by jinak shodil celý status(), a ten FE
            // polyká do prázdné obrazovky — radši vrať výchozí hodnoty.
            $row = [];
        }
        $effort = (string) ($row['ai_effort'] ?? LlmProviderCapabilities::EFFORT_DEFAULT);
        return [
            'ai_extraction_notes' => (string) ($row['ai_extraction_notes'] ?? ''),
            'ai_effort'           => in_array($effort, LlmProviderCapabilities::EFFORTS, true)
                ? $effort
                : LlmProviderCapabilities::EFFORT_DEFAULT,
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
