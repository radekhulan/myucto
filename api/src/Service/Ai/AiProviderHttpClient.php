<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmProviderCapabilities;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\OllamaClient;
use MyInvoice\Service\Import\ResidencyPolicy;
use MyInvoice\Service\Import\ResidencyViolationException;
use Psr\Log\LoggerInterface;

final class AiProviderHttpClient
{
    public const BANK_ESCALATION_MODEL = 'claude-sonnet-5';

    /** Strop výstupu pro dlouhé odpovědi (AI asistent, vytěžení dokumentu). */
    public const MAX_OUTPUT_TOKENS = 16000;

    /** Timeout dotazu s přílohami nebo dlouhým výstupem (s). Běžné dotazy drží výchozích 60 s klienta. */
    public const LONG_REQUEST_TIMEOUT = 180;

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly LlmProviderRegistry $registry,
        private readonly ResidencyPolicy $residency,
        private readonly AiDpaGate $dpa,
        private readonly LoggerInterface $logger,
        ?Client $http = null,
    ) {
        // Azure endpoint zadává admin; přesměrování jen v rámci hostu (AiRedirectPolicy).
        $this->http = $http ?? new Client(['timeout' => 60, 'http_errors' => false, 'allow_redirects' => AiRedirectPolicy::options()]);
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<array{name:string, mime:string, bytes:string}> $attachments binární přílohy (PDF, obrázky)
     *        vložené před text dotazu; textové přílohy patří do $user
     * @return array<string,mixed>
     */
    public function completeJson(
        int $supplierId,
        string $system,
        string $user,
        array $schema,
        ?string $modelOverride = null,
        int $maxOutputTokens = 500,
        array $attachments = [],
        bool $structuredOutput = false,
    ): array
    {
        try {
            $ctx = $this->context($supplierId);
            if ($modelOverride !== null && $modelOverride !== '') {
                $ctx['model'] = $modelOverride;
            }
            $this->dpa->assertConfirmed($supplierId, $ctx['provider']);
            return $this->completeForProvider(
                $ctx,
                $system,
                $user,
                $schema,
                max(100, min(self::MAX_OUTPUT_TOKENS, $maxOutputTokens)),
                array_values($attachments),
                $structuredOutput,
            );
        } catch (AiDpaException $e) {
            return ['ok' => false, 'error' => $e->errorCode];
        } catch (ResidencyViolationException) {
            return ['ok' => false, 'error' => 'residency_conflict'];
        } catch (GuzzleException $e) {
            $this->logger->warning('AI classifier transport error', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'provider_transport_error'];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $this->safeRuntimeError($e)];
        } catch (\Throwable $e) {
            $this->logger->error('AI classifier request failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'provider_error'];
        }
    }

    /** @param list<string> $texts @return array<string,mixed> */
    public function embeddings(int $supplierId, array $texts): array
    {
        try {
            $ctx = $this->context($supplierId);
            if (!in_array($ctx['provider'], ['openai', 'azure_openai'], true)) {
                return ['ok' => false, 'error' => 'embedding_unavailable'];
            }
            $this->dpa->assertConfirmed($supplierId, $ctx['provider']);
            $payload = ['input' => array_values($texts), 'dimensions' => 1536];
            if ($ctx['provider'] === 'openai') {
                $payload['model'] = 'text-embedding-3-small';
                $url = rtrim((string) $ctx['credentials']['base_url'], '/') . '/v1/embeddings';
                $headers = ['Authorization' => 'Bearer ' . $ctx['credentials']['api_key']];
                $model = 'text-embedding-3-small';
            } else {
                self::assertAzureEndpoint((string) $ctx['credentials']['endpoint']);
                $deployment = (string) $ctx['credentials']['deployment'];
                $url = rtrim((string) $ctx['credentials']['endpoint'], '/') . '/openai/deployments/'
                    . rawurlencode($deployment) . '/embeddings?api-version=' . rawurlencode((string) $ctx['credentials']['api_version']);
                $headers = ['api-key' => $ctx['credentials']['api_key']];
                $model = $deployment;
            }
            $response = $this->http->post($url, [
                'headers' => $headers + ['content-type' => 'application/json'], 'json' => $payload,
                'allow_redirects' => AiRedirectPolicy::options(),
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200 || !is_array($body['data'] ?? null)) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $embeddings = [];
            foreach ($body['data'] as $row) {
                if (!is_array($row['embedding'] ?? null) || count($row['embedding']) !== 1536) {
                    return ['ok' => false, 'error' => 'invalid_embedding'];
                }
                $embeddings[] = array_map('floatval', $row['embedding']);
            }
            return [
                'ok' => true, 'embeddings' => $embeddings, 'model' => $model,
                'provider' => $ctx['provider'], 'region' => $ctx['region'],
                'usage' => ['tokens' => (int) ($body['usage']['total_tokens'] ?? 0)],
            ];
        } catch (AiDpaException $e) {
            return ['ok' => false, 'error' => $e->errorCode];
        } catch (ResidencyViolationException) {
            return ['ok' => false, 'error' => 'residency_conflict'];
        } catch (GuzzleException $e) {
            $this->logger->warning('AI embedding transport error', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'provider_transport_error'];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $this->safeRuntimeError($e)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'provider_error'];
        }
    }

    public function isEmbeddingAvailable(int $supplierId): bool
    {
        try {
            $ctx = $this->context($supplierId);
            return in_array($ctx['provider'], ['openai', 'azure_openai'], true)
                && $this->dpa->isConfirmed($supplierId, $ctx['provider']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cílový silnější model pro jednorázovou auto-escalaci bank_tx kontace (§12b).
     * Escaluje výhradně anthropic haiku → claude-sonnet-5; pro jiné providery nebo
     * když tenant běží na jiném než haiku modelu vrací null (escalace není plošná).
     * Fail-closed: neznámý kontext / residency konflikt → null (žádná escalace).
     */
    public function bankEscalationModel(int $supplierId): ?string
    {
        return $this->strongModelOverride($supplierId);
    }

    /**
     * Silnější model pro náročné jednorázové dotazy (AI asistent, vytěžení dokumentu):
     * tenant na anthropic haiku dostane {@see self::BANK_ESCALATION_MODEL}, jinak null
     * = výchozí model tenanta.
     */
    public function strongModelOverride(int $supplierId): ?string
    {
        try {
            $ctx = $this->context($supplierId);
        } catch (\Throwable) {
            return null;
        }
        if ($ctx['provider'] !== 'anthropic' || !str_contains($ctx['model'], 'haiku')) {
            return null;
        }
        return self::BANK_ESCALATION_MODEL;
    }

    /**
     * Dostupnost AI pro interaktivní dotazy: poskytovatel, model a důvod nedostupnosti
     * (provider_not_configured, residency_conflict, dpa_not_confirmed).
     *
     * @return array{available:bool, reason:?string, provider:?string, model:?string, supports_files:bool}
     */
    public function status(int $supplierId): array
    {
        try {
            $ctx = $this->context($supplierId);
        } catch (ResidencyViolationException) {
            return ['available' => false, 'reason' => 'residency_conflict', 'provider' => null, 'model' => null, 'supports_files' => false];
        } catch (\Throwable) {
            return ['available' => false, 'reason' => 'provider_not_configured', 'provider' => null, 'model' => null, 'supports_files' => false];
        }
        $provider = $ctx['provider'];
        try {
            $confirmed = $this->dpa->isConfirmed($supplierId, $provider);
        } catch (\Throwable) {
            $confirmed = false;
        }
        return [
            'available' => $confirmed,
            'reason' => $confirmed ? null : 'dpa_not_confirmed',
            'provider' => $provider,
            'model' => $this->strongModelOverride($supplierId) ?? $ctx['model'],
            'supports_files' => $provider !== 'ollama',
        ];
    }

    public function availableModels(int $supplierId): array
    {
        try {
            $ctx = $this->context($supplierId);
            $models = $this->registry->resolve($ctx['provider'])->capabilities($supplierId)->models;
            return array_values(array_unique([$ctx['model'], ...$models]));
        } catch (\Throwable) {
            return [];
        }
    }

    public function isClassificationAvailable(int $supplierId): bool
    {
        try {
            $ctx = $this->context($supplierId);
            return $this->dpa->isConfirmed($supplierId, $ctx['provider']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{provider:string,region:string,model:string,credentials:array<string,mixed>,supplier_id:int} */
    private function context(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ai_provider,ai_eu_residency_required FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('provider_not_configured');
        }
        $provider = (string) ($row['ai_provider'] ?: 'anthropic');
        $client = $this->registry->resolve($provider);
        $credentials = $client->getCredentials($supplierId);
        if (!is_array($credentials)) {
            throw new \RuntimeException('provider_not_configured');
        }
        $caps = $client->capabilities($supplierId);
        $this->residency->assertAllowed($provider, $caps->dataRegion, (bool) $row['ai_eu_residency_required']);
        return [
            'provider' => $provider,
            'region' => $caps->dataRegion,
            'model' => (string) ($credentials['default_model'] ?? $caps->defaultModel),
            'credentials' => $credentials,
            'supplier_id' => $supplierId,
        ];
    }

    /**
     * @param array{provider:string,region:string,model:string,credentials:array<string,mixed>,supplier_id:int} $ctx
     * @param array<string,mixed> $schema
     * @param list<array{name:string, mime:string, bytes:string}> $attachments
     * @return array<string,mixed>
     */
    private function completeForProvider(array $ctx, string $system, string $user, array $schema, int $maxOutputTokens, array $attachments = [], bool $structuredOutput = false): array
    {
        $provider = $ctx['provider'];
        $creds = $ctx['credentials'];
        // Dlouhý dotaz (přílohy, velký výstup) dostane delší timeout; běžné volání posílá
        // požadavek beze změny s výchozím timeoutem klienta.
        $extra = ($attachments !== [] || $maxOutputTokens > 4000) ? ['timeout' => self::LONG_REQUEST_TIMEOUT] : [];
        if ($provider === 'anthropic') {
            $userContent = $user;
            if ($attachments !== []) {
                $userContent = [];
                foreach ($attachments as $a) {
                    $source = ['type' => 'base64', 'media_type' => $a['mime'], 'data' => base64_encode($a['bytes'])];
                    $userContent[] = $a['mime'] === 'application/pdf'
                        ? ['type' => 'document', 'source' => $source, 'title' => $a['name']]
                        : ['type' => 'image', 'source' => $source];
                }
                $userContent[] = ['type' => 'text', 'text' => $user];
            }
            $model = $ctx['model'];
            // Dlouhé odpovědi (AI asistent) jdou přes structured outputs: vynucený tool_use
            // s dlouhým markdownem model občas „vylije" do jediného pole. Modely, které vynucený
            // tool_choice odmítají, jdou přes structured outputs vždy; jinak klasifikace beze změny.
            $structuredOutput = $structuredOutput || LlmProviderCapabilities::anthropicRejectsForcedToolChoice($model);
            // Modely od generace 5 parametr temperature odmítají (HTTP 400), posílá se jen starším.
            $payload = [
                'model' => $model, 'max_tokens' => $maxOutputTokens,
                ...(LlmProviderCapabilities::anthropicAcceptsTemperature($model) ? ['temperature' => 0] : []),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $userContent]],
            ];
            if ($structuredOutput) {
                $payload['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
                // Přemýšlení se počítá do max_tokens: krátká odpověď potřebuje rezervu
                // a nízké úsilí, jinak model skončí na limitu dřív, než JSON napíše.
                if (LlmProviderCapabilities::anthropicThinksByDefault($model) && $maxOutputTokens < 4000) {
                    $payload['max_tokens'] = 4000;
                    $payload['output_config']['effort'] = 'low';
                }
            } else {
                $payload['tools'] = [[
                    'name' => 'posting_suggestion', 'description' => 'Vrátí návrh kontace.',
                    'input_schema' => $schema,
                ]];
                $payload['tool_choice'] = ['type' => 'tool', 'name' => 'posting_suggestion'];
            }
            $response = $this->http->post('https://api.anthropic.com/v1/messages', [
                'headers' => AnthropicClient::requestHeaders((string) $creds['api_key'], $creds['workspace_id'] ?? null),
                'json' => $payload,
            ] + $extra);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $data = null;
            foreach (($body['content'] ?? []) as $part) {
                if (!$structuredOutput && ($part['type'] ?? null) === 'tool_use' && is_array($part['input'] ?? null)) {
                    $data = $part['input'];
                    break;
                }
                if ($structuredOutput && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $data = json_decode($part['text'], true);
                    break;
                }
            }
            if (($body['stop_reason'] ?? '') === 'refusal') {
                return ['ok' => false, 'error' => 'refusal'];
            }
            if ($structuredOutput && ($body['stop_reason'] ?? '') === 'max_tokens') {
                return ['ok' => false, 'error' => 'max_tokens'];
            }
            $usage = ['input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0)];
            $model = (string) ($body['model'] ?? $ctx['model']);
        } elseif (in_array($provider, ['openai', 'azure_openai'], true)) {
            $userContent = $user;
            if ($attachments !== []) {
                $userContent = [];
                foreach ($attachments as $a) {
                    $dataUrl = 'data:' . $a['mime'] . ';base64,' . base64_encode($a['bytes']);
                    $userContent[] = $a['mime'] === 'application/pdf'
                        ? ['type' => 'file', 'file' => ['filename' => $a['name'], 'file_data' => $dataUrl]]
                        : ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
                }
                $userContent[] = ['type' => 'text', 'text' => $user];
            }
            $payload = [
                'model' => $ctx['model'], 'max_completion_tokens' => $maxOutputTokens,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userContent]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'posting_suggestion', 'strict' => true, 'schema' => $schema,
                ]],
            ];
            if ($provider === 'openai') {
                $url = rtrim((string) $creds['base_url'], '/') . '/v1/chat/completions';
                $headers = ['Authorization' => 'Bearer ' . $creds['api_key']];
            } else {
                self::assertAzureEndpoint((string) $creds['endpoint']);
                unset($payload['model']);
                $url = rtrim((string) $creds['endpoint'], '/') . '/openai/deployments/' . rawurlencode((string) $creds['deployment'])
                    . '/chat/completions?api-version=' . rawurlencode((string) $creds['api_version']);
                $headers = ['api-key' => $creds['api_key']];
            }
            $response = $this->http->post($url, [
                'headers' => $headers + ['content-type' => 'application/json'], 'json' => $payload,
                'allow_redirects' => AiRedirectPolicy::options(),
            ] + $extra);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $text = AzureOpenAiClient::extractText(is_array($body) ? $body : null);
            $data = is_string($text) ? json_decode($text, true) : null;
            $usage = ['input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0)];
            $model = (string) ($body['model'] ?? $ctx['model']);
        } elseif ($provider === 'gemini') {
            $parts = [];
            foreach ($attachments as $a) {
                $parts[] = ['inline_data' => ['mime_type' => $a['mime'], 'data' => base64_encode($a['bytes'])]];
            }
            $parts[] = ['text' => $user];
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['parts' => $parts]],
                'generationConfig' => [
                    'temperature' => 0, 'maxOutputTokens' => $maxOutputTokens,
                    'responseMimeType' => 'application/json', 'responseSchema' => $this->geminiSchema($schema),
                ],
            ];
            // Gemini 3.x přemýšlí a myšlenky se počítají do maxOutputTokens: krátká
            // klasifikace by skončila uprostřed JSON. Stejně jako v GeminiClient nízké
            // přemýšlení a rezerva výstupu.
            if (preg_match('/^gemini-3(?:[.-]|$)/', $ctx['model']) === 1) {
                $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'low'];
                $payload['generationConfig']['maxOutputTokens'] = max($maxOutputTokens, 4000);
            }
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($ctx['model']) . ':generateContent';
            $response = $this->http->post($url, [
                'headers' => ['x-goog-api-key' => $creds['api_key'], 'content-type' => 'application/json'], 'json' => $payload,
            ] + $extra);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $text = GeminiClient::extractText(is_array($body) ? $body : null);
            $data = is_string($text) ? json_decode($text, true) : null;
            $usage = ['input_tokens' => (int) ($body['usageMetadata']['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0)];
            $model = $ctx['model'];
        } elseif ($provider === 'ollama') {
            if ($attachments !== []) {
                return ['ok' => false, 'error' => 'attachments_unsupported'];
            }
            $client = $this->registry->resolve('ollama');
            if (!$client instanceof OllamaClient) {
                return ['ok' => false, 'error' => 'provider_not_configured'];
            }
            $r = $client->completeJson($ctx['supplier_id'], $ctx['model'], $system, $user, $schema, $maxOutputTokens);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => $this->ollamaError($ctx['supplier_id'], $r)];
            }
            $data  = $r['data'];
            $usage = $r['usage'];
            $model = $r['model'];
        } else {
            return ['ok' => false, 'error' => 'provider_not_configured'];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }
        return ['ok' => true, 'data' => $data, 'provider' => $provider, 'region' => $ctx['region'], 'model' => $model, 'usage' => $usage];
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private function geminiSchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') {
                continue;
            }
            if ($key === 'type' && is_string($value)) {
                $out[$key] = strtoupper($value);
            } elseif (is_array($value)) {
                $out[$key] = $this->geminiSchema($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function httpError(int $code, mixed $body): string
    {
        $this->logger->warning('AI provider returned an HTTP error', [
            'status' => $code,
            'provider_error' => is_array($body) ? mb_substr((string) ($body['error']['message'] ?? ''), 0, 500) : '',
        ]);
        return 'provider_http_' . $code;
    }

    /**
     * Síťové a HTTP chyby Ollamy převede na obecný slovník, podle kterého AiWorker::transient()
     * rozhoduje o opakování (uspaná GPU stanice nesmí job trvale shodit). Původní kód jde do logu.
     *
     * @param array{error:string, status?:int} $r
     */
    private function ollamaError(int $supplierId, array $r): string
    {
        $code = $r['error'];
        $mapped = match ($code) {
            'ollama_unreachable', 'ollama_timeout' => 'provider_transport_error',
            'ollama_http_error' => 'provider_http_' . (isset($r['status']) && $r['status'] >= 400 ? $r['status'] : '5xx'),
            default => $code,
        };
        if ($mapped !== $code) {
            $this->logger->warning('Ollama classifier request failed', ['supplier_id' => $supplierId, 'code' => $code, 'mapped' => $mapped]);
        }
        return $mapped;
    }

    /** Uložený Azure endpoint se ověřuje i před voláním, nejen při uložení nastavení. */
    private static function assertAzureEndpoint(string $endpoint): void
    {
        if (AzureOpenAiClient::endpointError($endpoint) !== null) {
            throw new \RuntimeException('provider_not_configured');
        }
    }

    private function safeRuntimeError(\RuntimeException $e): string
    {
        $code = $e->getMessage();
        if (in_array($code, ['provider_not_configured', 'embedding_unavailable'], true)) {
            return $code;
        }
        $this->logger->error('AI provider runtime error', ['error' => $code]);
        return 'provider_error';
    }
}
