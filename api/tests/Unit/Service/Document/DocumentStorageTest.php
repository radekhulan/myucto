<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentStorageTest extends TestCase
{
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . '/myucto-document-storage-' . bin2hex(random_bytes(8));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dataDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataDir);
        }
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        parent::tearDown();
    }

    private function storage(int $maxBytes = 50 * 1024 * 1024): DocumentStorage
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key, mixed $default = null) => $key === 'documents.max_file_bytes' ? $maxBytes : $default
        );
        return new DocumentStorage($config);
    }

    // ───────── sanitizeFilename ─────────

    /** @return array<string,array{string,string}> */
    public static function filenameProvider(): array
    {
        return [
            'path traversal'   => ['../../etc/passwd', 'passwd'],
            'absolute path'    => ['/var/www/secret.pdf', 'secret.pdf'],
            'leading dots'     => ['...hidden.txt', 'hidden.txt'],
            'forward slashes'  => ['a/b/c.txt', 'c.txt'],
            'reserved chars'   => ['in<voi>ce:*?.pdf', 'in_voi_ce___.pdf'],
            'normal'           => ['Smlouva 2026.docx', 'Smlouva 2026.docx'],
        ];
    }

    #[DataProvider('filenameProvider')]
    public function testSanitizeFilename(string $input, string $expected): void
    {
        self::assertSame($expected, $this->storage()->sanitizeFilename($input));
    }

    public function testSanitizeStripsControlChars(): void
    {
        $out = $this->storage()->sanitizeFilename("evil\x00\x1f.txt");
        self::assertStringNotContainsString("\x00", $out);
        self::assertStringContainsString('.txt', $out);
    }

    public function testSanitizeEmptyFallsBackToDocument(): void
    {
        self::assertSame('document', $this->storage()->sanitizeFilename('...'));
        self::assertSame('document', $this->storage()->sanitizeFilename(''));
    }

    public function testSanitizeLongNameTruncated(): void
    {
        $name = str_repeat('a', 300) . '.pdf';
        $out = $this->storage()->sanitizeFilename($name);
        self::assertLessThanOrEqual(205, strlen($out));
        self::assertStringEndsWith('.pdf', $out);
    }

    // ───────── classify ─────────

    public function testClassifyAllowedTypes(): void
    {
        $s = $this->storage();
        self::assertSame('pdf',  $s->classify('pdf', 'application/pdf'));
        self::assertSame('zfo',  $s->classify('zfo', 'application/octet-stream'));
        self::assertSame('p7s',  $s->classify('p7s', 'application/pkcs7-signature'));
        self::assertSame('docx', $s->classify('docx', 'application/octet-stream'));
        self::assertSame('xlsx', $s->classify('xlsx', 'application/zip'));
        self::assertSame('xml',  $s->classify('isdoc', 'text/xml'));
        self::assertSame('image', $s->classify('png', 'image/png'));
    }

    public function testClassifyRejectsDangerousMimeEvenWithSafeExt(): void
    {
        $this->expectException(DocumentException::class);
        // .pdf přípona, ale obsah je HTML → stored-XSS risk → odmítnout
        $this->storage()->classify('pdf', 'text/html');
    }

    public function testClassifyRejectsExecutable(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('exe', 'application/x-dosexec');
    }

    public function testClassifyRejectsSvg(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('svg', 'image/svg+xml');
    }

    public function testClassifyUnknownExtensionBecomesOther(): void
    {
        // Blacklist přístup: neznámá, ale neškodná přípona projde jako 'other'
        // (např. bankovní výpisy .gpc/.abo, .json, .log…).
        $s = $this->storage();
        self::assertSame('other', $s->classify('xyz', 'application/octet-stream'));
        self::assertSame('other', $s->classify('gpc', 'text/plain'));
        self::assertSame('other', $s->classify('abo', 'text/plain'));
    }

    public function testClassifyRejectsExecutableByExtensionAlone(): void
    {
        // I s neškodným detekovaným MIME musí spustitelná přípona spadnout (blocklist přípon).
        $s = $this->storage();
        try {
            $s->classify('exe', 'application/octet-stream');
            self::fail('exe měl být odmítnut');
        } catch (DocumentException $e) {
            self::assertSame('executable_blocked', $e->errorCode);
        }
        $this->expectException(DocumentException::class);
        $s->classify('bat', 'text/plain');
    }

    public function testClassifyRejectsPhp(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('php', 'text/x-php');
    }

    public function testStoresHtmlExtractedFromZfoAsDownloadOnlyAttachment(): void
    {
        $html = '<!doctype html><html><body><h1>Důležité oznámení</h1></body></html>';

        $stored = $this->storage()->storeZfoAttachmentFromBytes(
            $html,
            42,
            'oznámení.html',
            'text/html',
        );

        self::assertSame('application/octet-stream', $stored['mime_type']);
        self::assertSame('other', $stored['doc_type']);
        self::assertSame('html', $stored['ext']);
        self::assertSame($html, file_get_contents($stored['abs_path']));
    }

    // ───────── maxFileBytes ─────────

    public function testMaxFileBytesFromConfig(): void
    {
        self::assertSame(12345, $this->storage(12345)->maxFileBytes());
    }

    public function testMaxFileBytesCappedAtAbsolute(): void
    {
        // 2 GB požadavek se zaklopí na absolutní strop (500 MiB).
        self::assertLessThanOrEqual(500 * 1024 * 1024, $this->storage(2 * 1024 * 1024 * 1024)->maxFileBytes());
    }
}
