<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * OpenAI (přímé API) klient pro AI extrakci z PDF faktur (F7 §3.3 / §13.1).
 *
 * Endpoint: POST {base_url|https://api.openai.com}/v1/chat/completions
 * Auth: `Authorization: Bearer`. Structured output přes `response_format=json_schema`.
 * Vrací IDENTICKÉ dekódované JSON schéma jako AnthropicClient.
 *
 * EU rezidence: když `openai_base_url` = EU data-residency endpoint
 * (eu.api.openai.com), jinak us — vyhodnocuje {@see LlmProviderCapabilities} a
 * vynucuje {@see ResidencyPolicy}.
 *
 * Inkrement `openai_extractions_count` vlastní klient sám (per-client counter model).
 */
final class OpenAiClient implements LlmGatewayInterface
{
    private const TIMEOUT = 120;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;
    private const DEFAULT_BASE_URL = 'https://api.openai.com';

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
     * @return array{api_key:string, default_model:string, base_url:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT openai_api_key_enc, openai_default_model, openai_base_url FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['openai_api_key_enc'])) {
            return null;
        }
        try {
            $key = $this->crypto->decrypt((string) $row['openai_api_key_enc']);
        } catch (\Throwable) {
            $this->logger->error('OpenAI API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => (string) ($row['openai_default_model'] ?? LlmProviderCapabilities::OPENAI_DEFAULT_MODEL)
                ?: LlmProviderCapabilities::OPENAI_DEFAULT_MODEL,
            'base_url'      => (string) ($row['openai_base_url'] ?? '') ?: self::DEFAULT_BASE_URL,
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null, ?string $baseUrl = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET openai_api_key_enc = ?, openai_default_model = ?, openai_base_url = ? WHERE id = ?'
        )->execute([
            $enc,
            $defaultModel !== null && $defaultModel !== '' ? $defaultModel : LlmProviderCapabilities::OPENAI_DEFAULT_MODEL,
            $baseUrl !== null && $baseUrl !== '' ? $baseUrl : null,
            $supplierId,
        ]);
    }

    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET openai_api_key_enc = NULL WHERE id = ?')->execute([$supplierId]);
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        $stmt = $this->db->pdo()->prepare('SELECT openai_base_url, openai_default_model FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return LlmProviderCapabilities::openai($row['openai_base_url'] ?? null, $row['openai_default_model'] ?? null);
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return $this->capabilities($supplierId)->strongerModel($currentModel);
    }

    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'OpenAI API key nenastaven'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->post($creds, [
                'model'                 => $creds['default_model'],
                'messages'              => [['role' => 'user', 'content' => 'Reply OK']],
                'max_completion_tokens' => 10,
            ]);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $body['model'] ?? $creds['default_model'], 'usage' => self::mapUsage($body['usage'] ?? null)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride,
            InvoiceExtractionPrompt::tenantContext($this->db, $supplierId) . InvoiceExtractionPrompt::invoiceSystem(),
            'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON.',
            16384, InvoiceExtractionPrompt::invoiceJsonSchema());
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'data' => $data, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::fuelSystem(),
            'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.', 8192, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['ok' => false, 'error' => 'OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'transactions' => array_values($data['transactions']), 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::totalSystem(), 'Vrať K úhradě podle JSON schema.', 100, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !array_key_exists('total_with_vat', $data)) {
            return ['ok' => false, 'error' => 'OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $total = is_numeric($data['total_with_vat']) ? (float) $data['total_with_vat'] : null;
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'total' => $total, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::paymentAccountSystem(),
            'Vrať platební údaje dodavatele podle JSON schema.', 200, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
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
     * @param array<string,mixed>|null $jsonSchema
     * @return array{ok:bool, text?:string, model?:string, usage?:?array, error?:string}
     */
    private function chat(int $supplierId, string $pdfBytes, ?string $modelOverride, string $systemPrompt, string $userText, int $maxTokens, ?array $jsonSchema): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'OpenAI API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $responseFormat = $jsonSchema !== null
            ? ['type' => 'json_schema', 'json_schema' => ['name' => 'invoice_extraction', 'strict' => true, 'schema' => $jsonSchema]]
            : ['type' => 'json_object'];

        $payload = [
            'model'    => $modelOverride ?: $creds['default_model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'file', 'file' => ['filename' => 'invoice.pdf', 'file_data' => 'data:application/pdf;base64,' . base64_encode($pdfBytes)]],
                ]],
            ],
            'max_completion_tokens' => $maxTokens,
            'response_format'       => $responseFormat,
        ];
        $payload += LlmProviderCapabilities::openai($creds['base_url'] ?? null, $creds['default_model'] ?? null)
            ->effortPayload((string) $payload['model'], $this->tenantEffort($supplierId));

        try {
            ['code' => $code, 'body' => $body] = $this->post($creds, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI extraction failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($code !== 200) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
            return ['ok' => false, 'error' => $msg];
        }
        $text = AzureOpenAiClient::extractText($body);
        if ($text === null || $text === '') {
            return ['ok' => false, 'error' => 'Prázdná odpověď od OpenAI'];
        }
        return ['ok' => true, 'text' => $text, 'model' => $body['model'] ?? $payload['model'], 'usage' => self::mapUsage($body['usage'] ?? null)];
    }

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

    /**
     * @param array{api_key:string, base_url:string} $creds
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */
    private function post(array $creds, array $payload): array
    {
        $url = rtrim($creds['base_url'], '/') . '/v1/chat/completions';
        $resp = $this->http->post($url, [
            'headers' => ['Authorization' => 'Bearer ' . $creds['api_key'], 'content-type' => 'application/json'],
            'json'    => $payload,
        ]);
        $body = json_decode((string) $resp->getBody(), true);
        return ['code' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : null];
    }

    /** @param array<string,mixed>|null $usage @return array{input_tokens:int, output_tokens:int}|null */
    private static function mapUsage(?array $usage): ?array
    {
        if (!is_array($usage)) return null;
        return [
            'input_tokens'  => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }

    private function incrementCounter(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET openai_extractions_count = openai_extractions_count + 1 WHERE id = ?'
        )->execute([$supplierId]);
    }
}
