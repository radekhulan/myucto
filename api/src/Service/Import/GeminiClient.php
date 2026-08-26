<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Google Gemini (AI Studio přímé API) klient pro AI extrakci z PDF faktur
 * (F7 §3.3 / §13.1).
 *
 * Endpoint: POST generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Auth: hlavička `x-goog-api-key`. Structured output přes
 * `generationConfig.responseMimeType=application/json` + `responseSchema` →
 * vrací IDENTICKÉ dekódované JSON schéma jako AnthropicClient.
 *
 * dataRegion: přímé API = us (EU jen přes Vertex regional endpoint, mimo rozsah v1).
 * Inkrement `gemini_extractions_count` vlastní klient sám (per-client counter model).
 */
final class GeminiClient implements LlmGatewayInterface
{
    private const TIMEOUT = 120;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => self::TIMEOUT, 'http_errors' => false]);
    }

    /**
     * @return array{api_key:string, default_model:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gemini_api_key_enc, gemini_default_model FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['gemini_api_key_enc'])) {
            return null;
        }
        try {
            $key = $this->crypto->decrypt((string) $row['gemini_api_key_enc']);
        } catch (\Throwable) {
            $this->logger->error('Gemini API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => LlmProviderCapabilities::gemini(
                isset($row['gemini_default_model']) ? (string) $row['gemini_default_model'] : null,
            )->defaultModel,
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET gemini_api_key_enc = ?, gemini_default_model = ? WHERE id = ?'
        )->execute([
            $enc,
            $defaultModel !== null && $defaultModel !== '' ? $defaultModel : LlmProviderCapabilities::GEMINI_DEFAULT_MODEL,
            $supplierId,
        ]);
    }

    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = NULL WHERE id = ?')->execute([$supplierId]);
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        $stmt = $this->db->pdo()->prepare('SELECT gemini_default_model FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return LlmProviderCapabilities::gemini($row['gemini_default_model'] ?? null);
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return $this->capabilities($supplierId)->strongerModel($currentModel);
    }

    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Gemini API key nenastaven'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->post($creds['default_model'], $creds['api_key'], [
                'contents' => [['parts' => [['text' => 'Reply OK']]]],
                'generationConfig' => ['maxOutputTokens' => 10],
            ]);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                $this->logger->warning('Gemini connection test failed', [
                    'supplier_id' => $supplierId,
                    'model'       => $creds['default_model'],
                    'http_status' => $code,
                    'api_error'   => substr((string) $msg, 0, 500),
                ]);
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $creds['default_model'], 'usage' => self::mapUsage($body['usageMetadata'] ?? null)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride,
            InvoiceExtractionPrompt::tenantContext($this->db, $supplierId) . InvoiceExtractionPrompt::invoiceSystem(),
            'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON.',
            16384, self::geminiInvoiceSchema());
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            $this->logger->warning('Gemini invoice extraction returned invalid JSON', [
                'supplier_id'    => $supplierId,
                'model'          => $r['model'] ?? $modelOverride,
                'response_bytes' => strlen($r['text']),
                'finish_reason'   => $r['finish_reason'] ?? null,
                'output_tokens'   => $r['usage']['output_tokens'] ?? null,
                'thought_tokens'  => $r['thought_tokens'] ?? null,
                'json_error'      => json_last_error_msg(),
            ]);
            return [
                'ok'    => false,
                'error' => 'Gemini vrátil neplatný nebo neúplný JSON'
                    . (isset($r['finish_reason']) ? ' (finishReason: ' . $r['finish_reason'] . ')' : ''),
            ];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'data' => $data, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::fuelSystem(),
            'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.', 8192, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'transactions' => array_values($data['transactions']), 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::totalSystem(), 'Vrať K úhradě podle JSON schema.', 100, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !array_key_exists('total_with_vat', $data)) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $total = is_numeric($data['total_with_vat']) ? (float) $data['total_with_vat'] : null;
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'total' => $total, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::paymentAccountSystem(),
            'Vrať platební údaje dodavatele podle JSON schema.', 200, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $this->incrementCounter($supplierId);
        $str = static fn ($v) => (is_string($v) && trim($v) !== '') ? trim($v) : null;
        return [
            'ok'              => true,
            'bank_account'    => $str($data['bank_account'] ?? null),
            'iban'            => $str($data['iban'] ?? null),
            'variable_symbol' => $str($data['variable_symbol'] ?? null),
            'model'           => $r['model'],
            'usage'           => $r['usage'],
        ];
    }

    /**
     * @param array<string,mixed>|null $responseSchema Gemini-format schema (nebo null = jen JSON mime)
     * @return array{ok:bool, text?:string, model?:string, usage?:?array, error?:string}
     */
    private function generate(int $supplierId, string $pdfBytes, ?string $modelOverride, string $systemPrompt, string $userText, int $maxTokens, ?array $responseSchema): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Gemini API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $generationConfig = ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens];
        if (preg_match('/^gemini-3(?:[.-]|$)/', $model) === 1) {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'low'];
        } elseif (str_starts_with($model, 'gemini-2.5-')) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 1024];
        }
        // Volba tenanta rychle/přesně přebije výchozí thinkingConfig výše.
        $generationConfig = array_replace(
            $generationConfig,
            LlmProviderCapabilities::gemini($creds['default_model'] ?? null)
                ->effortPayload($model, $this->tenantEffort($supplierId)),
        );
        if ($responseSchema !== null) {
            $generationConfig['responseSchema'] = $responseSchema;
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => [[
                'parts' => [
                    ['text' => $userText],
                    ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                ],
            ]],
            'generationConfig'  => $generationConfig,
        ];

        try {
            ['code' => $code, 'body' => $body] = $this->post($model, $creds['api_key'], $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Gemini extraction failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($code !== 200) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
            $this->logger->warning('Gemini extraction request failed', [
                'supplier_id' => $supplierId,
                'model'       => $model,
                'http_status' => $code,
                'api_error'   => substr((string) $msg, 0, 500),
            ]);
            return ['ok' => false, 'error' => $msg];
        }
        $text = self::extractText($body);
        if ($text === null || $text === '') {
            $this->logger->warning('Gemini extraction returned no text', [
                'supplier_id'  => $supplierId,
                'model'        => $model,
                'finish_reason' => $body['candidates'][0]['finishReason'] ?? null,
                'block_reason'  => $body['promptFeedback']['blockReason'] ?? null,
            ]);
            return ['ok' => false, 'error' => 'Prázdná odpověď od Gemini'];
        }
        return [
            'ok'            => true,
            'text'          => $text,
            'model'         => $model,
            'usage'         => self::mapUsage($body['usageMetadata'] ?? null),
            'thought_tokens' => (int) ($body['usageMetadata']['thoughtsTokenCount'] ?? 0),
            'finish_reason' => $body['candidates'][0]['finishReason'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */

    /**
     * Zvolená míra uvažování tenanta ({@see LlmProviderCapabilities::EFFORTS}).
     * Fail-safe: cokoliv nečekaného (chybějící sloupec, DB error) znamená `default`,
     * tedy neposílat providerovi nic navíc.
     */
    private function tenantEffort(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT ai_effort FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $v = (string) $stmt->fetchColumn();
        } catch (\Throwable) {
            return LlmProviderCapabilities::EFFORT_DEFAULT;
        }
        return in_array($v, LlmProviderCapabilities::EFFORTS, true)
            ? $v
            : LlmProviderCapabilities::EFFORT_DEFAULT;
    }

    private function post(string $model, string $apiKey, array $payload): array
    {
        $url = self::BASE_URL . '/models/' . rawurlencode($model) . ':generateContent';
        $resp = $this->http->post($url, [
            'headers' => ['x-goog-api-key' => $apiKey, 'content-type' => 'application/json'],
            'json'    => $payload,
        ]);
        $body = json_decode((string) $resp->getBody(), true);
        return ['code' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : null];
    }

    /** Vytáhne text z Gemini generateContent obálky. @param array<string,mixed>|null $body */
    public static function extractText(?array $body): ?string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            return null;
        }
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['text']) && is_string($p['text'])) {
                $text .= $p['text'];
            }
        }
        return $text === '' ? null : $text;
    }

    /** @param array<string,mixed>|null $usage @return array{input_tokens:int, output_tokens:int}|null */
    private static function mapUsage(?array $usage): ?array
    {
        if (!is_array($usage)) return null;
        return [
            'input_tokens'  => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private static function geminiInvoiceSchema(): array
    {
        return self::toGeminiSchema(InvoiceExtractionPrompt::invoiceJsonSchema());
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private static function toGeminiSchema(array $schema): array
    {
        $converted = [];
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $converted['nullable'] = in_array('null', $type, true);
            $nonNullTypes = array_values(array_filter($type, static fn ($value) => $value !== 'null'));
            $type = $nonNullTypes[0] ?? null;
        }
        if (is_string($type)) {
            $converted['type'] = strtoupper($type);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $converted['properties'] = [];
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $converted['properties'][$name] = self::toGeminiSchema($property);
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $converted['items'] = self::toGeminiSchema($schema['items']);
        }
        foreach (['required', 'minimum', 'maximum', 'minItems', 'maxItems', 'format', 'title', 'description'] as $key) {
            if (array_key_exists($key, $schema)) {
                $converted[$key] = $schema[$key];
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $enum = array_values(array_filter($schema['enum'], static fn ($value) => $value !== null));
            if ($enum !== []) {
                $converted['enum'] = $enum;
            }
        }
        return $converted;
    }

    private function incrementCounter(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET gemini_extractions_count = gemini_extractions_count + 1 WHERE id = ?'
        )->execute([$supplierId]);
    }
}
