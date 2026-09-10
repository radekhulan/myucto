<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Azure OpenAI klient pro AI extrakci z PDF faktur (F7 §3.3 / §13.1).
 *
 * Endpoint: POST {endpoint}/openai/deployments/{deployment}/chat/completions?api-version={ver}
 * Auth: hlavička `api-key`. Structured output přes `response_format=json_schema`
 * zamčené na extrakční schéma → vrací IDENTICKÉ dekódované JSON schéma
 * (vendor/customer/payment/items[]/vat_recap[]/document_kind/…) jako AnthropicClient,
 * které {@see AiPdfExtractor::createDraft} konzumuje.
 *
 * PDF-nosič: base64 `file` content part (fallback render stránek na obrázky mimo v1).
 * dataRegion musí být EU (endpoint fyzicky v EU Azure regionu) — vynucuje
 * {@see ResidencyPolicy} v routeru.
 *
 * Inkrement `azure_extractions_count` si klient vlastní sám (per-client counter model;
 * router NEinkrementuje — žádné dvojí počítání).
 */
final class AzureOpenAiClient implements LlmGatewayInterface
{
    private const TIMEOUT = 120;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;

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
     * @return array{api_key:string, default_model:string, endpoint:string, deployment:string, api_version:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT azure_openai_api_key_enc, azure_openai_endpoint, azure_openai_deployment, azure_openai_api_version
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['azure_openai_api_key_enc']) || empty($row['azure_openai_endpoint']) || empty($row['azure_openai_deployment'])) {
            return null;
        }
        try {
            $key = $this->crypto->decrypt((string) $row['azure_openai_api_key_enc']);
        } catch (\Throwable) {
            $this->logger->error('Azure OpenAI API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        $deployment = (string) $row['azure_openai_deployment'];
        return [
            'api_key'       => $key,
            'default_model' => $deployment,
            'endpoint'      => (string) $row['azure_openai_endpoint'],
            'deployment'    => $deployment,
            'api_version'   => (string) ($row['azure_openai_api_version'] ?? '2024-10-21'),
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $endpoint = null, ?string $deployment = null, ?string $apiVersion = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET azure_openai_api_key_enc = ?, azure_openai_endpoint = ?,
                    azure_openai_deployment = ?, azure_openai_api_version = ? WHERE id = ?'
        )->execute([
            $enc,
            $endpoint !== null && $endpoint !== '' ? $endpoint : null,
            $deployment !== null && $deployment !== '' ? $deployment : null,
            $apiVersion !== null && $apiVersion !== '' ? $apiVersion : '2024-10-21',
            $supplierId,
        ]);
    }

    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET azure_openai_api_key_enc = NULL WHERE id = ?'
        )->execute([$supplierId]);
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT azure_openai_endpoint, azure_openai_deployment, ai_data_region FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return LlmProviderCapabilities::azureOpenai(
            $row['azure_openai_endpoint'] ?? null,
            $row['azure_openai_deployment'] ?? null,
            (string) ($row['ai_data_region'] ?? 'eu'),
        );
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return $this->capabilities($supplierId)->strongerModel($currentModel);
    }

    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Azure OpenAI credentials nenastaveny'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->post($creds, [
                'messages'              => [['role' => 'user', 'content' => 'Reply OK']],
                'max_completion_tokens' => 10,
            ]);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $body['model'] ?? $creds['deployment'], 'usage' => self::mapUsage($body['usage'] ?? null)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null, string $tenantRole = self::TENANT_ROLE_BUYER): array
    {
        $r = $this->chat($supplierId, $pdfBytes,
            InvoiceExtractionPrompt::tenantContext($this->db, $supplierId, $tenantRole) . InvoiceExtractionPrompt::invoiceSystem(),
            'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON.',
            16384, InvoiceExtractionPrompt::invoiceJsonSchema());
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Azure OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'data' => $data, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, InvoiceExtractionPrompt::fuelSystem(),
            'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.', 8192, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['ok' => false, 'error' => 'Azure OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'transactions' => array_values($data['transactions']), 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, InvoiceExtractionPrompt::totalSystem(), 'Vrať K úhradě podle JSON schema.', 100, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !array_key_exists('total_with_vat', $data)) {
            return ['ok' => false, 'error' => 'Azure OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $total = is_numeric($data['total_with_vat']) ? (float) $data['total_with_vat'] : null;
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'total' => $total, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, InvoiceExtractionPrompt::paymentAccountSystem(),
            'Vrať platební údaje dodavatele podle JSON schema.', 200, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Azure OpenAI vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
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
     * Společná chat-completions cesta: validace PDF → sestav messages → POST →
     * vytáhni text z choices[0].message.content.
     *
     * @param array<string,mixed>|null $jsonSchema
     * @return array{ok:bool, text?:string, model?:string, usage?:?array, error?:string}
     */
    private function chat(int $supplierId, string $pdfBytes, string $systemPrompt, string $userText, int $maxTokens, ?array $jsonSchema): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Azure OpenAI credentials nenastaveny pro tohoto suppliera.'];
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

        try {
            ['code' => $code, 'body' => $body] = $this->post($creds, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Azure OpenAI extraction failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($code !== 200) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
            return ['ok' => false, 'error' => $msg];
        }
        $text = self::extractText($body);
        if ($text === null || $text === '') {
            return ['ok' => false, 'error' => 'Prázdná odpověď od Azure OpenAI'];
        }
        return ['ok' => true, 'text' => $text, 'model' => $body['model'] ?? $creds['deployment'], 'usage' => self::mapUsage($body['usage'] ?? null)];
    }

    /**
     * @param array{api_key:string, endpoint:string, deployment:string, api_version:string} $creds
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */
    private function post(array $creds, array $payload): array
    {
        $url = rtrim($creds['endpoint'], '/') . '/openai/deployments/' . rawurlencode($creds['deployment'])
            . '/chat/completions?api-version=' . rawurlencode($creds['api_version']);
        $resp = $this->http->post($url, [
            'headers' => ['api-key' => $creds['api_key'], 'content-type' => 'application/json'],
            'json'    => $payload,
        ]);
        $body = json_decode((string) $resp->getBody(), true);
        return ['code' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : null];
    }

    /** Vytáhne text z chat-completions obálky. @param array<string,mixed>|null $body */
    public static function extractText(?array $body): ?string
    {
        $content = $body['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? $content : null;
    }

    /** @param array<string,mixed>|null $usage @return array{input_tokens:int, output_tokens:int, model?:string}|null */
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
            'UPDATE supplier SET azure_extractions_count = azure_extractions_count + 1 WHERE id = ?'
        )->execute([$supplierId]);
    }
}
