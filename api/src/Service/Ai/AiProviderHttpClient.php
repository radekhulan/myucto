<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Service\Import\GeminiClient;
use MyInvoice\Service\Import\LlmProviderRegistry;
use MyInvoice\Service\Import\ResidencyPolicy;
use MyInvoice\Service\Import\ResidencyViolationException;
use Psr\Log\LoggerInterface;

final class AiProviderHttpClient
{
    public const BANK_ESCALATION_MODEL = 'claude-sonnet-5';

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly LlmProviderRegistry $registry,
        private readonly ResidencyPolicy $residency,
        private readonly AiDpaGate $dpa,
        private readonly LoggerInterface $logger,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 60, 'http_errors' => false]);
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    public function completeJson(
        int $supplierId,
        string $system,
        string $user,
        array $schema,
        ?string $modelOverride = null,
        int $maxOutputTokens = 500,
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
                max(100, min(4000, $maxOutputTokens)),
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
                $deployment = (string) $ctx['credentials']['deployment'];
                $url = rtrim((string) $ctx['credentials']['endpoint'], '/') . '/openai/deployments/'
                    . rawurlencode($deployment) . '/embeddings?api-version=' . rawurlencode((string) $ctx['credentials']['api_version']);
                $headers = ['api-key' => $ctx['credentials']['api_key']];
                $model = $deployment;
            }
            $response = $this->http->post($url, ['headers' => $headers + ['content-type' => 'application/json'], 'json' => $payload]);
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

    public function isClassificationAvailable(int $supplierId): bool
    {
        try {
            $ctx = $this->context($supplierId);
            return $this->dpa->isConfirmed($supplierId, $ctx['provider']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{provider:string,region:string,model:string,credentials:array<string,mixed>} */
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
        ];
    }

    /** @param array{provider:string,region:string,model:string,credentials:array<string,mixed>} $ctx @param array<string,mixed> $schema @return array<string,mixed> */
    private function completeForProvider(array $ctx, string $system, string $user, array $schema, int $maxOutputTokens): array
    {
        $provider = $ctx['provider'];
        $creds = $ctx['credentials'];
        if ($provider === 'anthropic') {
            $payload = [
                'model' => $ctx['model'], 'max_tokens' => $maxOutputTokens, 'temperature' => 0,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'tools' => [[
                    'name' => 'posting_suggestion', 'description' => 'Vrátí návrh kontace.',
                    'input_schema' => $schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => 'posting_suggestion'],
            ];
            $response = $this->http->post('https://api.anthropic.com/v1/messages', [
                'headers' => ['x-api-key' => $creds['api_key'], 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
                'json' => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $data = null;
            foreach (($body['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'tool_use' && is_array($part['input'] ?? null)) {
                    $data = $part['input'];
                    break;
                }
            }
            $usage = ['input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0)];
            $model = (string) ($body['model'] ?? $ctx['model']);
        } elseif (in_array($provider, ['openai', 'azure_openai'], true)) {
            $payload = [
                'model' => $ctx['model'], 'max_completion_tokens' => $maxOutputTokens,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'posting_suggestion', 'strict' => true, 'schema' => $schema,
                ]],
            ];
            if ($provider === 'openai') {
                $url = rtrim((string) $creds['base_url'], '/') . '/v1/chat/completions';
                $headers = ['Authorization' => 'Bearer ' . $creds['api_key']];
            } else {
                unset($payload['model']);
                $url = rtrim((string) $creds['endpoint'], '/') . '/openai/deployments/' . rawurlencode((string) $creds['deployment'])
                    . '/chat/completions?api-version=' . rawurlencode((string) $creds['api_version']);
                $headers = ['api-key' => $creds['api_key']];
            }
            $response = $this->http->post($url, ['headers' => $headers + ['content-type' => 'application/json'], 'json' => $payload]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $text = AzureOpenAiClient::extractText(is_array($body) ? $body : null);
            $data = is_string($text) ? json_decode($text, true) : null;
            $usage = ['input_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($body['usage']['completion_tokens'] ?? 0)];
            $model = (string) ($body['model'] ?? $ctx['model']);
        } elseif ($provider === 'gemini') {
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['parts' => [['text' => $user]]]],
                'generationConfig' => [
                    'temperature' => 0, 'maxOutputTokens' => $maxOutputTokens,
                    'responseMimeType' => 'application/json', 'responseSchema' => $this->geminiSchema($schema),
                ],
            ];
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($ctx['model']) . ':generateContent';
            $response = $this->http->post($url, [
                'headers' => ['x-goog-api-key' => $creds['api_key'], 'content-type' => 'application/json'], 'json' => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() !== 200) {
                return ['ok' => false, 'error' => $this->httpError($response->getStatusCode(), $body)];
            }
            $text = GeminiClient::extractText(is_array($body) ? $body : null);
            $data = is_string($text) ? json_decode($text, true) : null;
            $usage = ['input_tokens' => (int) ($body['usageMetadata']['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0)];
            $model = $ctx['model'];
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
