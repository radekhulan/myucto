<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Service\Document\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DocumentTextExtractorTest extends TestCase
{
    private string $tmp;

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_file($this->tmp)) @unlink($this->tmp);
    }

    private function file(string $content, string $ext): string
    {
        $this->tmp = (string) tempnam(sys_get_temp_dir(), 'txt') . '.' . $ext;
        file_put_contents($this->tmp, $content);
        return $this->tmp;
    }

    public function testPlainText(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('Smlouva o dílo č. 42', 'txt'), 'other', 'txt');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('Smlouva o dílo', (string) $res['text']);
    }

    public function testXmlStripsTags(): void
    {
        $xml = '<root><name>ACME</name><note>Faktura 2026</note></root>';
        $res = (new DocumentTextExtractor())->extract($this->file($xml, 'xml'), 'xml', 'xml');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('ACME', (string) $res['text']);
        self::assertStringContainsString('Faktura 2026', (string) $res['text']);
        self::assertStringNotContainsString('<root>', (string) $res['text']);
    }

    public function testCsv(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file("a;b;c\n1;2;Praha", 'csv'), 'other', 'csv');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('Praha', (string) $res['text']);
    }

    public function testDocxTextIsExtracted(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:t>Kupní smlouva</w:t></w:p></w:body></w:document>');
        $zip->close();
        try {
            $res = (new DocumentTextExtractor())->extract($path, 'other', 'docx');
            self::assertSame('extracted', $res['status']);
            self::assertStringContainsString('Kupní smlouva', (string) $res['text']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Zip-bomb DOCX (část rozbalená na stovky MB) nesmí shodit proces fatalem
     * z vyčerpané paměti — ten není \Throwable a nechytí ho žádná vrstva nad
     * extrakcí (ingest datové schránky by na téže zprávě padal pořád dokola).
     * Běží v podprocesu s nízkým memory_limit, aby fatal byl vidět.
     */
    public function testZipBombDocxDoesNotExhaustMemory(): void
    {
        $dir = sys_get_temp_dir() . '/myucto-docx-bomb-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $raw = $dir . '/document.xml';
        $docx = $dir . '/bomb.docx';
        $runner = $dir . '/runner.php';
        try {
            $fh = fopen($raw, 'wb');
            self::assertIsResource($fh);
            fwrite($fh, '<document>');
            $chunk = str_repeat('A', 1024 * 1024);
            for ($i = 0; $i < 200; $i++) {
                fwrite($fh, $chunk);
            }
            fwrite($fh, '</document>');
            fclose($fh);

            $zip = new \ZipArchive();
            $zip->open($docx, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
            $zip->addFile($raw, 'word/document.xml');
            $zip->close();
            unlink($raw);

            $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
            file_put_contents($runner, '<?php require ' . var_export($autoload, true) . ";\n"
                . '$r = (new MyInvoice\Service\Document\DocumentTextExtractor())->extract($argv[1], "other", "docx");' . "\n"
                . 'echo "STATUS=", $r["status"], " LEN=", strlen((string) $r["text"]);' . "\n");

            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $runner, $docx]);
            $process->setTimeout(60);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertStringContainsString('STATUS=extracted', $process->getOutput());
        } finally {
            foreach ([$raw, $docx, $runner] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    public function testEmptyFileUnsupported(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('   ', 'txt'), 'other', 'txt');
        self::assertSame('unsupported', $res['status']);
        self::assertNull($res['text']);
    }

    public function testUnknownTypeUnsupported(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('binary', 'bin'), 'other', 'bin');
        self::assertSame('unsupported', $res['status']);
    }

    public function testNormalizesWhitespace(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file("a\t\t  b\n\n\nc", 'txt'), 'other', 'txt');
        self::assertSame('a b c', $res['text']);
    }
}
