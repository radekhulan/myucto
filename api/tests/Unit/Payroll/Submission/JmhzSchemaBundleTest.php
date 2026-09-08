<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class JmhzSchemaBundleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../xsd/jmhz';

    /** @return iterable<string, array{string}> */
    public static function entryPoints(): iterable
    {
        yield 'JMHZ' => ['jmhz-1.4.3.6/jmhzPodani.xsd'];
        yield 'REGZEC' => ['regzec-1.4.0.4/REGZEC25.xsd'];
        yield 'PREZEC' => ['prezec-1.2/PREZEC26 1.2.xsd'];
        yield 'REGZELDOPL' => ['regzeldopl-1.2/REGZELDOPL25.xsd'];
        yield 'DZMH' => ['dzmh-1.1/DZMH25.xsd'];
        yield 'OREZAM' => ['orezam-zrezam-1.0/OREZAM26.xsd'];
        yield 'ZREZAM' => ['orezam-zrezam-1.0/ZREZAM26.xsd'];
    }

    #[DataProvider('entryPoints')]
    public function testOfficialEntryPointIsVendored(string $relativePath): void
    {
        self::assertFileExists(self::ROOT . '/' . $relativePath);
    }

    public function testEverySchemaIsWellFormedAndHasCompleteLocalDependencies(): void
    {
        $files = $this->schemaFiles();
        self::assertCount(25, $files);

        foreach ($files as $file) {
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            libxml_clear_errors();

            $loaded = $document->load($file, LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            self::assertTrue($loaded, $this->formatErrors($file, $errors));
            self::assertSame(
                'http://www.w3.org/2001/XMLSchema',
                $document->documentElement?->namespaceURI,
                $file . ' není XSD schema.',
            );

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            $locations = $xpath->query(
                '//xs:include/@schemaLocation | //xs:import/@schemaLocation | //xs:redefine/@schemaLocation',
            );
            self::assertNotFalse($locations);

            $packageDirectory = realpath(dirname($file));
            self::assertNotFalse($packageDirectory);

            foreach ($locations as $location) {
                $value = $location->nodeValue;
                self::assertNotNull($value);
                $relative = trim($value);
                self::assertNull(
                    parse_url($relative, PHP_URL_SCHEME),
                    $file . ' odkazuje na síťovou závislost ' . $relative,
                );

                $dependency = realpath(dirname($file) . DIRECTORY_SEPARATOR . $relative);
                self::assertNotFalse(
                    $dependency,
                    $file . ' odkazuje na chybějící schema ' . $relative,
                );
                self::assertStringStartsWith(
                    strtolower($packageDirectory . DIRECTORY_SEPARATOR),
                    strtolower($dependency),
                    $file . ' opouští svůj verzovaný balíček přes ' . $relative,
                );
            }
        }
    }

    public function testContentManifestPinsEveryVendoredSchemaByteForByte(): void
    {
        $manifestPath = self::ROOT . '/SHA256SUMS';
        self::assertFileExists($manifestPath);
        $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        $expected = [];
        foreach ($this->schemaFiles() as $file) {
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file, strlen(self::ROOT) + 1),
            );
            $hash = hash_file('sha256', $file);
            self::assertIsString($hash);
            $expected[$relative] = $hash;
        }

        $actual = [];
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}  [^\r\n]+\z/D', $line);
            [$hash, $relative] = explode('  ', $line, 2);
            self::assertArrayNotHasKey($relative, $actual, 'Duplicitní cesta v SHA256SUMS.');
            $actual[$relative] = $hash;
        }

        self::assertSame($expected, $actual);
    }

    public function testOrezamPackagePinsTheOfficialArchiveBytes(): void
    {
        $expected = [
            'OREZAM26.xsd' => '599762bcfaad8e742c30b50b29f4bc8161d2f524734e006fb91c210286f5248d',
            'ZREZAM26.xsd' => 'fb19cb5b3235f316d39511f661a01e7806fd3dea194d4a641ce45c2c0f5ef89d',
            'baseTypes2.xsd' => '579888e1dd29a60eb66b26dcf32031658ead51618c74f9f96fabf8d7a1305747',
        ];

        foreach ($expected as $file => $hash) {
            self::assertSame(
                $hash,
                hash_file('sha256', self::ROOT . '/orezam-zrezam-1.0/' . $file),
                $file . ' neodpovídá bajtům v oficiálním OREZAM/ZREZAM 1.0 archivu.',
            );
        }
    }

    /** @return list<string> */
    private function schemaFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::ROOT, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                self::fail('Nelze načíst položku adresáře JMHZ schémat.');
            }
            if ($file->isFile() && strtolower($file->getExtension()) === 'xsd') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @param list<\LibXMLError> $errors */
    private function formatErrors(string $file, array $errors): string
    {
        $messages = array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            $errors,
        );

        return $file . " není validní XML:\n" . implode("\n", $messages);
    }
}
