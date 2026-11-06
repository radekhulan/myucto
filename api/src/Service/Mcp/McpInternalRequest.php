<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Http\RequestPath;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Převod zprávy mostu na požadavek do aplikace a odpovědi zpět
 * (`api/bin/mcp-internal-api.php`).
 *
 * Požadavek jde celým middleware stackem aplikace, takže autentizace tokenem,
 * {@see \MyInvoice\Middleware\ApiScopeMiddleware} i kontrola firmy platí stejně
 * jako u veřejného API. Tady se hlídá jen to, co stack předpokládá od webového
 * serveru: cesta smí mířit jen do `/api/v1/`, nahrávaný soubor dorazí jako
 * {@see \Psr\Http\Message\UploadedFileInterface} a binární odpověď přežije JSON.
 */
final class McpInternalRequest
{
    /** @var list<string> */
    private array $tmpFiles = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tmpDir,
    ) {}

    /**
     * @throws \RuntimeException          nepovolená cesta nebo metoda
     * @throws \InvalidArgumentException  neplatné tělo nebo soubor; kód = HTTP status
     */
    public function build(array $input): ServerRequestInterface
    {
        $base = rtrim($this->baseUrl, '/');
        $url = (string) ($input['url'] ?? '');
        $method = strtoupper((string) ($input['method'] ?? ''));
        $path = parse_url($url, PHP_URL_PATH);
        if ($base === '' || !str_starts_with($url, $base . '/api/v1/')
            || !is_string($path) || !str_starts_with(RequestPath::normalize($path), '/api/v1/')
            || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \RuntimeException('Nepovolená cesta interního API.');
        }

        $params = (array) ($input['serverParams'] ?? []);
        $request = (new ServerRequestFactory())->createServerRequest($method, $url, $params);
        foreach ((array) ($input['headers'] ?? []) as $name => $value) {
            if (!is_string($name) || !is_string($value) || strtolower($name) === 'host') continue;
            $request = $request->withHeader($name, $value);
        }

        // Platí pro oba mosty (NodeBridge i ManagedNodeRelay): binární tělo smí
        // nést jen nahrání souboru zápisovou metodou a nahrání jen v base64.
        $contentType = $request->getHeaderLine('Content-Type');
        $multipart = preg_match('#^multipart/form-data\b#i', $contentType) === 1;
        if (array_key_exists('bodyBase64', $input)) {
            if (!$multipart || !in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                throw new \InvalidArgumentException('Binární tělo smí nést jen nahrání souboru.', 400);
            }
            $body = McpFileLimits::decodeBase64($input['bodyBase64']);
        } elseif ($multipart) {
            throw new \InvalidArgumentException('Nahrávaný soubor musí přijít v base64.', 400);
        } else {
            $body = (string) ($input['body'] ?? '');
        }
        $request = $request->withBody((new StreamFactory())->createStream($body));

        if ($multipart) {
            [$fields, $files, $this->tmpFiles] = (new McpMultipartParser())->parse($body, $contentType, $this->tmpDir);
            $request = $request->withParsedBody($fields)->withUploadedFiles($files);
        }

        return $request->withAttribute('mcp.internal', true)
            ->withAttribute('mcp.resource', is_string($input['mcpResource'] ?? null) ? $input['mcpResource'] : null);
    }

    /**
     * Odpověď aplikace jako zpráva pro most. JSON jde beze změny v `body`,
     * cokoli jiného (PDF, ISDOC, příloha) v `bodyBase64`, nad
     * {@see McpFileLimits::MAX_FILE_BYTES} se místo souboru vrátí 413.
     *
     * @return array{status:int, headers:array<string,string>, body:string, bodyBase64?:string}
     */
    public function encodeResponse(ResponseInterface $response): array
    {
        $type = $response->getHeaderLine('Content-Type') ?: 'application/json';
        $headers = [
            'Content-Type' => $type,
            'Retry-After' => $response->getHeaderLine('Retry-After'),
        ];
        $disposition = str_replace(["\r", "\n"], '', $response->getHeaderLine('Content-Disposition'));
        if ($disposition !== '') {
            $headers['Content-Disposition'] = $disposition;
        }

        $stream = $response->getBody();
        if (preg_match('#^application/([a-z0-9.+-]+\+)?json\b#i', $type) === 1) {
            return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => (string) $stream];
        }

        $size = $stream->getSize();
        if ($size !== null && $size > McpFileLimits::MAX_FILE_BYTES) {
            return self::error(413, 'file_too_large', self::tooLargeMessage());
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        if (strlen($bytes) > McpFileLimits::MAX_FILE_BYTES) {
            return self::error(413, 'file_too_large', self::tooLargeMessage());
        }
        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => '',
            'bodyBase64' => base64_encode($bytes),
        ];
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    public static function error(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /** Chyba nahrání (z {@see build()}) jako odpověď API, ať ji nástroj umí vysvětlit. */
    public static function uploadError(\InvalidArgumentException $e): array
    {
        return match ($e->getCode()) {
            413 => self::error(413, 'file_too_large', self::tooLargeMessage()),
            415 => self::error(415, 'unsupported_type', $e->getMessage()),
            default => self::error(400, 'invalid_upload', $e->getMessage()),
        };
    }

    /** Smaže dočasné soubory, které si akce nepřesunula. */
    public function cleanup(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) @unlink($path);
        }
        $this->tmpFiles = [];
    }

    private static function tooLargeMessage(): string
    {
        return 'Soubor je větší než ' . intdiv(McpFileLimits::MAX_FILE_BYTES, 1024 * 1024)
            . ' MB, což je strop serverového MCP. Stáhněte nebo nahrajte ho přímo v aplikaci.';
    }
}
