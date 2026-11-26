<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\OllamaEndpoint;
use MyInvoice\Service\Ai\OllamaEndpointException;
use MyInvoice\Service\Ai\OllamaEndpointGuard;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Lokální Ollama jako AI provider — vytěžování PDF i textové návrhy kontací.
 *
 * Endpoint: POST {base_url}/api/chat. Nativní API, protože jen to umí `num_ctx`,
 * `format` se schématem a `think`. Adresu zadává admin firmy, proto každý request
 * jde přes {@see OllamaEndpointGuard}, připojuje se na ověřenou IP a nenásleduje
 * přesměrování. Tělo chybové odpovědi se volajícímu nikdy nevrací.
 *
 * PDF jde hybridně: PNG stránky (jen když model hlásí capability `vision`) +
 * textová vrstva PDF kvůli přesným číslicím. `think` jde jen modelu s capability
 * `thinking` — jinak Ollama vrací 400.
 */
final class OllamaClient implements LlmGatewayInterface
{
    public const DEFAULT_TIMEOUT = 110;
    public const ENV_TIMEOUT = 'MYINVOICE_OLLAMA_TIMEOUT';
    /**
     * Velikost kontextu (`num_ctx`). Menší kontext = menší KV cache, takže se model na
     * GPU s malou VRAM vejde celý a generuje řádově rychleji. Prompt se schématem má
     * ~9k tokenů a stránka ~2k, při 16384 se tedy vejdou zhruba 3–4 strany.
     */
    public const ENV_NUM_CTX = 'MYINVOICE_OLLAMA_NUM_CTX';
    public const MIN_NUM_CTX = 8192;
    public const MAX_NUM_CTX = 131072;
    public const MAX_PAGES = 6;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;
    private const CONNECT_TIMEOUT = 5;
    private const META_TIMEOUT = 15;
    /** Spodní mez pro /api/chat, když přípravu (DNS, /api/show, rasterizace) sežrala většinu limitu. */
    private const MIN_CHAT_TIMEOUT = 5;

    /** Hlášky pro uživatele; kód v závorce kvůli diagnostice. */
    private const MESSAGES = [
        'provider_not_configured' => 'Ollama není nastavená — chybí adresa nebo model (provider_not_configured).',
        'ollama_endpoint_invalid' => 'Neplatná adresa Ollamy. Zadejte jen http(s)://host:port, bez cesty (ollama_endpoint_invalid).',
        'ollama_endpoint_blocked' => 'Adresa Ollamy není povolená (ollama_endpoint_blocked).',
        'ollama_unreachable'      => 'Ollama na zadané adrese neodpovídá (ollama_unreachable).',
        'ollama_timeout'          => 'Ollama nestihla odpovědět v časovém limitu. Pomůže GPU nebo vyšší MYINVOICE_OLLAMA_TIMEOUT (ollama_timeout).',
        'ollama_auth_failed'      => 'Ollama nebo její proxy odmítla API klíč (ollama_auth_failed).',
        'ollama_not_ollama'       => 'Na zadané adrese neběží Ollama (ollama_not_ollama).',
        'ollama_model_missing'    => 'Vybraný model v Ollamě není nainstalovaný (ollama_model_missing).',
        'ollama_no_input'         => 'Z PDF se nepodařilo získat obrázky stránek ani text (ollama_no_input).',
        'ollama_empty'            => 'Ollama vrátila prázdnou odpověď (ollama_empty).',
        'ollama_http_error'       => 'Ollama vrátila chybu (ollama_http_error).',
        'ollama_response_too_large' => 'Ollama vrátila příliš velkou odpověď (ollama_response_too_large).',
        'ollama_request_invalid'  => 'Požadavek na Ollamu se nepodařilo sestavit (ollama_request_invalid).',
        'ollama_curl_missing'     => 'PHP rozšíření curl není dostupné — Ollama ho vyžaduje (ollama_curl_missing).',
    ];

    /** Jak dlouho se drží ověřené rozlišení adresy (region i request musí vidět tutéž IP). */
    private const ENDPOINT_TTL = 60;
    private const MAX_BODY_BYTES = 8 * 1024 * 1024;
    private const MAX_MODELS = 100;
    /** Celkový strop na smyčku `/api/show` v seznamu modelů; zbylé modely zůstanou bez příznaků. */
    private const LIST_SHOW_BUDGET = 30;

    private Client $http;
    private readonly bool $curlAvailable;
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var array<string, array{ep: OllamaEndpoint, at: int}> normalizovaná base_url => ověřený cíl */
    private array $endpoints = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
        private readonly OllamaEndpointGuard $guard,
        private readonly PdfPageRasterizerInterface $rasterizer,
        private readonly PdfTotalExtractor $pdfText,
        ?Client $http = null,
        ?bool $curlAvailable = null, // test seam: simulace chybějícího ext-curl
        ?\Closure $clock = null,     // test seam: hodiny časového rozpočtu
    ) {
        $this->curlAvailable = $curlAvailable ?? \function_exists('curl_exec');
        $this->clock = $clock ?? static fn (): float => microtime(true);
        // Pinning IP (CURLOPT_RESOLVE) umí jen cURL — StreamHandler by volbu `curl` tiše ignoroval,
        // proto explicitně CurlHandler (bez ext-curl request() selže, viz výše).
        $this->http = $http ?? new Client([
            'handler'         => HandlerStack::create($this->curlAvailable ? new CurlHandler() : null),
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['ollama_http_error'];
    }

    // ── credentials ─────────────────────────────────────────────────────────

    /** @return array{api_key:string, default_model:string, base_url:string}|null */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ollama_base_url, ollama_default_model, ollama_api_key_enc FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $base  = trim((string) ($row['ollama_base_url'] ?? ''));
        $model = trim((string) ($row['ollama_default_model'] ?? ''));
        if (!$row || $base === '' || $model === '') {
            return null;
        }
        $key = '';
        if (!empty($row['ollama_api_key_enc'])) {
            try {
                $key = $this->crypto->decrypt((string) $row['ollama_api_key_enc']);
            } catch (\Throwable) {
                $this->logger->error('Ollama API key decryption failed', ['supplier_id' => $supplierId]);
                return null;
            }
        }
        return ['api_key' => $key, 'default_model' => $model, 'base_url' => $base];
    }

    /**
     * @param string|null $apiKey null = ponechat uložený klíč, '' = smazat
     * @throws OllamaEndpointException
     */
    public function setCredentials(int $supplierId, string $baseUrl, string $model, #[\SensitiveParameter] ?string $apiKey = null): void
    {
        $base = $this->guard->normalizeForStorage($baseUrl);
        if ($apiKey === null) {
            $this->db->pdo()->prepare('UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ? WHERE id = ?')
                ->execute([$base, $model, $supplierId]);
            return;
        }
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ollama_base_url = ?, ollama_default_model = ?, ollama_api_key_enc = ? WHERE id = ?'
        )->execute([$base, $model, $apiKey === '' ? null : $this->crypto->encrypt($apiKey), $supplierId]);
    }

    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ollama_base_url = NULL, ollama_default_model = NULL, ollama_api_key_enc = NULL WHERE id = ?'
        )->execute([$supplierId]);
    }

    public function sameBaseUrl(string $a, string $b): bool
    {
        try {
            return $this->guard->normalize($a) === $this->guard->normalize($b);
        } catch (OllamaEndpointException) {
            return false;
        }
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        $stmt = $this->db->pdo()->prepare('SELECT ollama_base_url, ollama_default_model FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return LlmProviderCapabilities::ollama(
            isset($row['ollama_default_model']) ? (string) $row['ollama_default_model'] : null,
            $this->regionFor((string) ($row['ollama_base_url'] ?? '')),
        );
    }

    /** Fail-closed: prázdná, nevalidní, zablokovaná nebo nerezolvovatelná adresa = 'us'. */
    public function regionFor(string $baseUrl): string
    {
        if (trim($baseUrl) === '') {
            return 'us';
        }
        try {
            return $this->endpoint($baseUrl)->region;
        } catch (OllamaEndpointException) {
            return 'us';
        }
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return null;
    }

    // ── modely a test spojení ───────────────────────────────────────────────

    /**
     * Modely nainstalované v Ollamě (`/api/tags`) s příznaky z `/api/show`.
     * `vision`/`thinking` = null, když Ollama capabilities nehlásí (starší verze)
     * nebo když na model nezbyl čas z LIST_SHOW_BUDGET.
     *
     * @return array{ok:true, models:list<array{name:string, size:int, vision:?bool, thinking:?bool}>}|array{ok:false, error:string, code:string}
     */
    public function listModels(string $baseUrl, #[\SensitiveParameter] string $apiKey = ''): array
    {
        try {
            $ep = $this->endpoint($baseUrl);
            $tags = $this->request($ep, 'GET', '/api/tags', null, $apiKey, self::META_TIMEOUT);
            if (in_array($tags['code'], [401, 403], true)) {
                throw new OllamaEndpointException('ollama_auth_failed');
            }
            if ($tags['code'] !== 200 || !is_array($tags['body']['models'] ?? null)) {
                throw new OllamaEndpointException('ollama_not_ollama');
            }
            $models = [];
            $deadline = ($this->clock)() + self::LIST_SHOW_BUDGET;
            foreach (array_slice($tags['body']['models'], 0, self::MAX_MODELS) as $m) {
                $name = is_array($m) ? (string) ($m['name'] ?? $m['model'] ?? '') : '';
                if ($name === '') {
                    continue;
                }
                $left = (int) floor($deadline - ($this->clock)());
                try {
                    $caps = $left > 0 ? $this->modelCapabilities($ep, $name, $apiKey, min(self::META_TIMEOUT, $left)) : null;
                } catch (OllamaEndpointException $e) {
                    // Jeden vadný /api/show nesmí shodit celý seznam; síťová chyba ano.
                    if (in_array($e->errorCode, ['ollama_timeout', 'ollama_unreachable'], true)) {
                        throw $e;
                    }
                    $caps = null;
                }
                $models[] = [
                    'name'     => $name,
                    'size'     => (int) ($m['size'] ?? 0),
                    'vision'   => $caps === null ? null : in_array('vision', $caps, true),
                    'thinking' => $caps === null ? null : in_array('thinking', $caps, true),
                ];
            }
            usort($models, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            return ['ok' => true, 'models' => $models];
        } catch (OllamaEndpointException $e) {
            return $this->fail($e->errorCode);
        }
    }

    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => self::message('provider_not_configured')];
        }
        $list = $this->listModels($creds['base_url'], $creds['api_key']);
        if (!$list['ok']) {
            return ['ok' => false, 'error' => $list['error']];
        }
        foreach ($list['models'] as $m) {
            if ($m['name'] === $creds['default_model']) {
                return ['ok' => true, 'model' => $m['name'], 'vision' => $m['vision']];
            }
        }
        return ['ok' => false, 'error' => self::message('ollama_model_missing')];
    }

    // ── extrakce ────────────────────────────────────────────────────────────

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null, string $tenantRole = self::TENANT_ROLE_BUYER): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride,
            InvoiceExtractionPrompt::tenantContext($this->db, $supplierId, $tenantRole) . InvoiceExtractionPrompt::invoiceSystem(),
            'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON.',
            InvoiceExtractionPrompt::invoiceJsonSchema());
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Ollama vrátila invalid JSON: ' . mb_strcut($r['text'], 0, 200, 'UTF-8')];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'data' => $data, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::fuelSystem(),
            'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.', 'json');
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['ok' => false, 'error' => 'Ollama vrátila invalid JSON: ' . mb_strcut($r['text'], 0, 200, 'UTF-8')];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'transactions' => array_values($data['transactions']), 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::totalSystem(), 'Vrať K úhradě podle JSON schema.', 'json');
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !array_key_exists('total_with_vat', $data)) {
            return ['ok' => false, 'error' => 'Ollama vrátila invalid JSON: ' . mb_strcut($r['text'], 0, 100, 'UTF-8')];
        }
        $total = is_numeric($data['total_with_vat']) ? (float) $data['total_with_vat'] : null;
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'total' => $total, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->chat($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::paymentAccountSystem(),
            'Vrať platební údaje dodavatele podle JSON schema.', 'json');
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Ollama vrátila invalid JSON: ' . mb_strcut($r['text'], 0, 100, 'UTF-8')];
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
     * Textový JSON dotaz pro {@see \MyInvoice\Service\Ai\AiProviderHttpClient} (AI návrhy
     * kontací). Sdílí guard, transport i parsování; vrací strojové kódy jako ostatní
     * větve toho klienta. Přemýšlení se vypíná — jde o krátkou klasifikaci a
     * `num_predict` by jinak spotřebovaly thinking tokeny.
     *
     * @param array<string,mixed> $schema
     * @return array{ok:true, data:array<string,mixed>, model:string, usage:array{input_tokens:int, output_tokens:int}}|array{ok:false, error:string, status?:int}
     */
    public function completeJson(int $supplierId, string $model, string $system, string $user, array $schema, int $maxOutputTokens): array
    {
        $deadline = $this->startBudget();
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'provider_not_configured'];
        }
        try {
            $ep = $this->endpoint($creds['base_url']);
            $caps = $this->modelCapabilities($ep, $model, $creds['api_key']);
            $extra = ($caps !== null && in_array('thinking', $caps, true)) ? ['think' => false] : [];
            $r = $this->request($ep, 'POST', '/api/chat',
                OllamaChatRequest::build($model, $system, $user, null, [], $schema, $extra, $maxOutputTokens, self::numCtx()),
                $creds['api_key'], $this->remaining($deadline));
        } catch (OllamaEndpointException $e) {
            return ['ok' => false, 'error' => $e->errorCode];
        }
        $parsed = $this->parseChat($r, $model);
        if (!$parsed['ok']) {
            // HTTP status kvůli rozlišení přechodné (5xx) a trvalé (4xx) chyby v AI workeru.
            return ['ok' => false, 'error' => $parsed['code'], 'status' => $r['code']];
        }
        $data = InvoiceExtractionPrompt::decodeJsonText($parsed['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }
        return ['ok' => true, 'data' => $data, 'model' => $parsed['model'], 'usage' => $parsed['usage']];
    }

    // ── interní ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|string $format
     * @return array{ok:true, text:string, model:string, usage:array{input_tokens:int, output_tokens:int}}|array{ok:false, error:string, code?:string}
     */
    private function chat(int $supplierId, string $pdfBytes, ?string $modelOverride, string $system, string $user, array|string $format): array
    {
        $deadline = $this->startBudget();
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return $this->fail('provider_not_configured');
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }
        $model = $modelOverride ?: $creds['default_model'];
        try {
            $ep = $this->endpoint($creds['base_url']);
            $caps = $this->modelCapabilities($ep, $model, $creds['api_key']);
            $vision = $caps === null || in_array('vision', $caps, true);
            $thinking = $caps !== null && in_array('thinking', $caps, true);

            $pages = $vision ? $this->rasterizer->rasterize($pdfBytes, self::MAX_PAGES) : [];
            $text = $this->pdfText->extractText($pdfBytes);
            if ($pages === [] && trim((string) $text) === '') {
                return $this->fail('ollama_no_input');
            }
            $extra = $thinking
                ? LlmProviderCapabilities::ollama($model, $ep->region)->effortPayload($model, $this->tenantEffort($supplierId))
                : [];
            $r = $this->request($ep, 'POST', '/api/chat',
                OllamaChatRequest::build($model, $system, $user, $text, $pages, $format, $extra, null, self::numCtx()),
                $creds['api_key'], $this->remaining($deadline));
        } catch (OllamaEndpointException $e) {
            $this->logger->warning('Ollama extraction failed', ['supplier_id' => $supplierId, 'code' => $e->errorCode]);
            return $this->fail($e->errorCode);
        }
        return $this->parseChat($r, $model);
    }

    /**
     * @param array{code:int, body:array<string,mixed>|null} $r
     * @return array{ok:true, text:string, model:string, usage:array{input_tokens:int, output_tokens:int}}|array{ok:false, error:string, code:string}
     */
    private function parseChat(array $r, string $model): array
    {
        if ($r['code'] === 404) {
            return $this->fail('ollama_model_missing');
        }
        if ($r['code'] !== 200) {
            $this->logger->warning('Ollama returned HTTP error', ['status' => $r['code']]);
            return $this->fail($r['code'] >= 300 && $r['code'] < 400 ? 'ollama_not_ollama' : 'ollama_http_error');
        }
        $text = $r['body']['message']['content'] ?? null;
        if (!is_string($text)) {
            return $this->fail('ollama_not_ollama');
        }
        if (trim($text) === '') {
            return $this->fail('ollama_empty');
        }
        return [
            'ok'    => true,
            'text'  => $text,
            'model' => self::reflectedModel($r['body']['model'] ?? null, $model),
            'usage' => [
                'input_tokens'  => (int) ($r['body']['prompt_eval_count'] ?? 0),
                'output_tokens' => (int) ($r['body']['eval_count'] ?? 0),
            ],
        ];
    }

    /**
     * @return list<string>|null null = Ollama capabilities nehlásí
     * @throws OllamaEndpointException
     */
    private function modelCapabilities(OllamaEndpoint $ep, string $model, #[\SensitiveParameter] string $apiKey, int $timeout = self::META_TIMEOUT): ?array
    {
        $r = $this->request($ep, 'POST', '/api/show', ['model' => $model], $apiKey, $timeout);
        if ($r['code'] === 404) {
            throw new OllamaEndpointException('ollama_model_missing');
        }
        if ($r['code'] !== 200 || !is_array($r['body'])) {
            throw new OllamaEndpointException('ollama_not_ollama');
        }
        $caps = $r['body']['capabilities'] ?? null;
        return is_array($caps) ? array_values(array_map('strval', $caps)) : null;
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array{code:int, body:array<string,mixed>|null}
     * @throws OllamaEndpointException ollama_timeout | ollama_unreachable
     */
    private function request(OllamaEndpoint $ep, string $method, string $path, ?array $json, #[\SensitiveParameter] string $apiKey, int $timeout): array
    {
        $options = [
            'timeout'         => $timeout,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => false,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json'],
        ];
        // Strop velikosti odpovědi vynucený za běhu přenosu (hostile server nesmí tlačit gigabajty).
        $tooLarge = false;
        $abort = static function () use (&$tooLarge): never {
            $tooLarge = true;
            throw new \LengthException('response too large');
        };
        // Ollama nekomprimuje; rozbalování (CURLOPT_ENCODING) by obešlo strop (gzip bomba) —
        // zakázané, a navíc sink, který počítá až rozbalené bajty.
        $options['decode_content'] = false;
        $options['sink'] = new CappedSinkStream(self::MAX_BODY_BYTES, static function () use (&$tooLarge): void {
            $tooLarge = true;
        });
        $options['on_headers'] = static function (\Psr\Http\Message\ResponseInterface $r) use ($abort): void {
            if ((int) $r->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
                $abort();
            }
        };
        $options['progress'] = static function (int|float $total, int|float $downloaded) use ($abort): void {
            if ($total > self::MAX_BODY_BYTES || $downloaded > self::MAX_BODY_BYTES) {
                $abort();
            }
        };
        if ($apiKey !== '') {
            $options['headers']['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        if (!$this->curlAvailable) {
            $this->logger->error('Ollama vyžaduje PHP rozšíření curl');
            throw new OllamaEndpointException('ollama_curl_missing');
        }
        // Proxy z prostředí (http_proxy, HTTPS_PROXY, ALL_PROXY) by spojení vedla jinam než
        // na ověřenou IP — pinning i region by přestaly platit. Prázdná `proxy` = proxy vypnutá:
        // Guzzle nečte env a libcurlu pevně nastaví CURLOPT_PROXY i CURLOPT_NOPROXY na ''.
        // (CURLOPT_NOPROXY přímo v `curl` Guzzle odmítá jako konflikt s volbou `proxy`.)
        $options['proxy'] = '';
        $resolve = $ep->curlResolve();
        if ($resolve !== null) {
            $options['curl'] = [\CURLOPT_RESOLVE => [$resolve]];
        }
        try {
            $resp = $this->http->request($method, $ep->baseUrl . $path, $options);
            $raw = $this->readBody($resp->getBody()); // druhá linie obrany; uvnitř try, ať nic neunikne
        } catch (OllamaEndpointException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($tooLarge) {
                throw new OllamaEndpointException('ollama_response_too_large');
            }
            throw $this->mapTransportError($e);
        }
        if ($raw === null || $tooLarge) {
            throw new OllamaEndpointException('ollama_response_too_large');
        }
        $body = json_decode($raw, true);
        return ['code' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : null];
    }

    private function mapTransportError(\Throwable $e): OllamaEndpointException
    {
        try {
            throw $e;
        } catch (\InvalidArgumentException) {
            // json_encode těla selhal (GuzzleHttp\Exception\InvalidArgumentException je i GuzzleException,
            // proto první) — nesmí skončit jako 500.
            return new OllamaEndpointException('ollama_request_invalid');
        } catch (GuzzleException $e) {
            $ctx = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
            $msg = $e->getMessage();
            // cURL hlásí kódem 28 i vypršení PŘIPOJENÍ/DNS („Connection/Resolving timed out")
            // — to je nedostupná adresa (firewall, vypnutý stroj), ne pomalý model.
            $notConnected = stripos($msg, 'Connection timed out') !== false || stripos($msg, 'Resolving timed out') !== false;
            $timedOut = !$notConnected && ((int) ($ctx['errno'] ?? 0) === 28 || stripos($msg, 'timed out') !== false);
            return new OllamaEndpointException($timedOut ? 'ollama_timeout' : 'ollama_unreachable');
        } catch (\Throwable $e) {
            $this->logger->error('Ollama request failed', ['exception' => $e::class]);
            return new OllamaEndpointException('ollama_unreachable');
        }
    }

    /** Tělo odpovědi nejvýš MAX_BODY_BYTES; větší = null (volající vyhodí ollama_response_too_large). */
    private function readBody(\Psr\Http\Message\StreamInterface $stream): ?string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $out = '';
        while (!$stream->eof() && strlen($out) <= self::MAX_BODY_BYTES) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        return strlen($out) > self::MAX_BODY_BYTES ? null : $out;
    }

    private static function reflectedModel(mixed $value, string $requested): string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 128 ? $value : $requested;
    }

    /**
     * Ověřený cíl pro adresu, jedno rozlišení DNS na ENDPOINT_TTL sekund — region (rezidence dat)
     * a vlastní request tak vidí tutéž IP (jinak DNS s TTL 0 vrátí napoprvé privátní, napodruhé
     * veřejnou adresu). Neúspěchy se nekešují: chyba = fail-closed a další pokus se rozhoduje znovu.
     *
     * @throws OllamaEndpointException
     */
    private function endpoint(string $baseUrl): OllamaEndpoint
    {
        $key = $this->guard->normalize($baseUrl);
        $hit = $this->endpoints[$key] ?? null;
        if ($hit !== null && time() - $hit['at'] < self::ENDPOINT_TTL) {
            return $hit['ep'];
        }
        $ep = $this->guard->resolve($key);
        $this->endpoints[$key] = ['ep' => $ep, 'at' => time()];
        return $ep;
    }

    /** @return array{ok:false, error:string, code:string} */
    private function fail(string $code): array
    {
        return ['ok' => false, 'error' => self::message($code), 'code' => $code];
    }

    /**
     * Jeden časový rozpočet na celé volání: DNS, /api/show, rasterizace i textová vrstva
     * se odečítají z MYINVOICE_OLLAMA_TIMEOUT, ne až /api/chat. Synchronní import tak
     * skončí čistou chybou ollama_timeout dřív, než webserver vrátí 504.
     *
     * @return float okamžik, kdy rozpočet vyprší
     */
    private function startBudget(): float
    {
        $timeout = self::timeout();
        @set_time_limit($timeout + 30);
        return ($this->clock)() + $timeout;
    }

    private function remaining(float $deadline): int
    {
        return max(self::MIN_CHAT_TIMEOUT, (int) floor($deadline - ($this->clock)()));
    }

    /** Neplatná hodnota = výchozí; mimo rozsah se ořízne na [MIN_NUM_CTX, MAX_NUM_CTX]. */
    public static function numCtx(): int
    {
        $v = (int) (getenv(self::ENV_NUM_CTX) ?: 0);
        return $v > 0 ? max(self::MIN_NUM_CTX, min($v, self::MAX_NUM_CTX)) : OllamaChatRequest::NUM_CTX;
    }

    private static function timeout(): int
    {
        $v = (int) (getenv(self::ENV_TIMEOUT) ?: 0);
        return $v > 0 ? min($v, 3600) : self::DEFAULT_TIMEOUT;
    }

    /** Stejná logika jako OpenAiClient::tenantEffort() — fail-safe `default`. */
    private function tenantEffort(int $supplierId): string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT ai_effort FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $v = (string) $stmt->fetchColumn();
        } catch (\Throwable) {
            return LlmProviderCapabilities::EFFORT_DEFAULT;
        }
        return in_array($v, LlmProviderCapabilities::EFFORTS, true) ? $v : LlmProviderCapabilities::EFFORT_DEFAULT;
    }

    private function incrementCounter(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ollama_extractions_count = ollama_extractions_count + 1 WHERE id = ?'
        )->execute([$supplierId]);
    }
}
