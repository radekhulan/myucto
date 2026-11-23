<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpFileLimits;
use MyInvoice\Service\Mcp\McpInternalRequest;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

final class McpInternalRequestTest extends TestCase
{
    private string $tmpDir;
    private McpInternalRequest $internal;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-internal-' . bin2hex(random_bytes(6));
        $this->internal = new McpInternalRequest('https://ucto.example.test', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->internal->cleanup();
        @rmdir($this->tmpDir);
    }

    public function testJsonRequestStaysAsBefore(): void
    {
        $request = $this->internal->build([
            'url' => 'https://ucto.example.test/api/v1/invoices?page=2', 'method' => 'post',
            'headers' => ['Content-Type' => 'application/json', 'Host' => 'evil.test'],
            'body' => '{"a":1}', 'serverParams' => ['REMOTE_ADDR' => '127.0.0.1'],
        ]);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('{"a":1}', (string) $request->getBody());
        self::assertSame('ucto.example.test', $request->getUri()->getHost());
        self::assertTrue($request->getAttribute('mcp.internal'));
        self::assertSame([], $request->getUploadedFiles());
    }

    public function testMultipartBodyArrivesAsUploadedFile(): void
    {
        $boundary = 'b0undary';
        $pdf = "%PDF-\x00\xFF";
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"zip_mode\"\r\n\r\nkeep\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n$pdf\r\n--$boundary--\r\n";
        $request = $this->internal->build([
            'url' => 'https://ucto.example.test/api/v1/documents', 'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'bodyBase64' => base64_encode($body),
        ]);
        self::assertSame(['zip_mode' => 'keep'], $request->getParsedBody());
        $file = $request->getUploadedFiles()['file'];
        self::assertSame($pdf, (string) $file->getStream());

        $path = $file->getStream()->getMetadata('uri');
        self::assertFileExists($path);
        $this->internal->cleanup();
        self::assertFileDoesNotExist($path);
    }

    public function testSessionOfAssistantOnlyForReadRequestWithoutToken(): void
    {
        $internal = new McpInternalRequest('https://ucto.example.test', $this->tmpDir, '__Host-session');
        $read = $internal->build(['url' => 'https://ucto.example.test/api/v1/clients', 'method' => 'GET', 'sessionToken' => 'tok']);
        self::assertSame(['__Host-session' => 'tok'], $read->getCookieParams());

        foreach ([
            ['method' => 'POST', 'body' => '{}'],
            ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer mi_pat_x']],
        ] as $input) {
            try {
                $internal->build($input + ['url' => 'https://ucto.example.test/api/v1/clients', 'sessionToken' => 'tok']);
                self::fail('Relace smí nést jen čtecí požadavek bez tokenu.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Relace', $e->getMessage());
            }
        }
        $withoutName = $this->internal->build(['url' => 'https://ucto.example.test/api/v1/clients', 'method' => 'GET', 'sessionToken' => 'tok']);
        self::assertSame([], $withoutName->getCookieParams(), 'bez jména cookie se relace nepřenese');
    }

    public function testRejectsPathsOutsidePublicApi(): void
    {
        foreach ([
            'https://jiny.example.test/api/v1/invoices',
            'https://ucto.example.test/api/admin/users',
            'https://ucto.example.test/api/v1/../admin/users',
            'https://ucto.example.test/mcp',
        ] as $url) {
            try {
                $this->internal->build(['url' => $url, 'method' => 'GET']);
                self::fail($url . ' neměla projít.');
            } catch (\RuntimeException $e) {
                self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->internal->build(['url' => 'https://ucto.example.test/api/v1/invoices', 'method' => 'TRACE']);
    }

    public function testRejectsInvalidOrOversizedBase64(): void
    {
        $base = ['url' => 'https://ucto.example.test/api/v1/documents', 'method' => 'POST',
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=x']];
        foreach (['abc', 'ab*=', 'YWJj\nZA==', ''] as $invalid) {
            try {
                $this->internal->build($base + ['bodyBase64' => $invalid]);
                self::fail('Base64 "' . $invalid . '" nemělo projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
        try {
            $this->internal->build($base + ['bodyBase64' => base64_encode(str_repeat('x', McpFileLimits::MAX_BODY_BYTES + 1))]);
            self::fail('Velké tělo nemělo projít.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(413, $e->getCode());
            self::assertSame(413, McpInternalRequest::uploadError($e)['status']);
        }
    }

    /** Platí i pro NodeBridge, který jde rovnou do build() bez ManagedNodeRelay. */
    public function testBinaryBodyOnlyForMultipartWrite(): void
    {
        $url = 'https://ucto.example.test/api/v1/documents';
        $multipart = ['Content-Type' => 'multipart/form-data; boundary=x'];
        $cases = [
            'JSON v base64' => ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'bodyBase64' => base64_encode('{"a":1}')],
            'bez typu v base64' => ['method' => 'PUT', 'bodyBase64' => base64_encode('{"a":1}')],
            'multipart přes GET' => ['method' => 'GET', 'headers' => $multipart, 'bodyBase64' => base64_encode('x')],
            'multipart přes DELETE' => ['method' => 'DELETE', 'headers' => $multipart, 'bodyBase64' => base64_encode('x')],
            'multipart jako text' => ['method' => 'POST', 'headers' => $multipart, 'body' => "--x--\r\n"],
        ];
        foreach ($cases as $label => $input) {
            try {
                $this->internal->build(['url' => $url] + $input);
                self::fail($label . ': nemělo projít.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode(), $label);
                self::assertSame(400, McpInternalRequest::uploadError($e)['status'], $label);
            }
        }
    }

    public function testBinaryResponseIsBase64AndJsonStaysText(): void
    {
        $pdf = "%PDF-\x00\xFF\x80";
        $response = (new ResponseFactory())->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="F.pdf"')
            ->withBody((new StreamFactory())->createStream($pdf));
        $encoded = $this->internal->encodeResponse($response);
        self::assertSame(base64_encode($pdf), $encoded['bodyBase64']);
        self::assertSame('', $encoded['body']);
        self::assertSame('attachment; filename="F.pdf"', $encoded['headers']['Content-Disposition']);

        $json = (new ResponseFactory())->createResponse(404)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody((new StreamFactory())->createStream('{"error":{"code":"not_found"}}'));
        $encoded = $this->internal->encodeResponse($json);
        self::assertSame('{"error":{"code":"not_found"}}', $encoded['body']);
        self::assertArrayNotHasKey('bodyBase64', $encoded);
    }

    public function testOversizedFileResponseBecomes413(): void
    {
        $response = (new ResponseFactory())->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withBody((new StreamFactory())->createStream(str_repeat('x', McpFileLimits::MAX_FILE_BYTES + 1)));
        $encoded = $this->internal->encodeResponse($response);
        self::assertSame(413, $encoded['status']);
        self::assertSame('file_too_large', json_decode($encoded['body'], true)['error']['code']);
        self::assertArrayNotHasKey('bodyBase64', $encoded);
    }
}
