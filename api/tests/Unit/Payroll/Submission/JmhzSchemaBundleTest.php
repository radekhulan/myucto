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
        yield 'JMHZ 2027' => ['jmhz-1.5.0.3/jmhzPodani.xsd'];
        yield 'JMHZ 2027 simpleTypes' => ['jmhz-1.5.0.3/simpleTypes.xsd'];
        yield 'REGZEC 2027' => ['regzec-1.5/REGZEC27_1_5.xsd'];
        yield 'PREZEC 2027' => ['prezec-1.5/PREZEC26_1_5.xsd'];
        yield 'DZMH 2027' => ['dzmh-1.5/DZMH25_1_5.xsd'];
        yield 'ZREZAM 2027' => ['zrezam-1.5/ZREZAM27_1_5.xsd'];
    }

    /**
     * Jmenné prostory a `version` schémat 2027, na které se napojí přepínač verzí
     * (podání se volí podle vykazovaného období). Změna kterékoli hodnoty musí být
     * vědomá, proto je test připíná.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function namespaces2027(): iterable
    {
        yield 'JMHZ podani' => ['jmhz-1.5.0.3/jmhzPodani.xsd', 'http://schemas.cssz.cz/JMHZ/podani/2027', '1.5.0.3'];
        yield 'JMHZ form' => ['jmhz-1.5.0.3/form.xsd', 'http://schemas.cssz.cz/JMHZ/form/2027', '1.5.0.3'];
        yield 'JMHZ simpleTypes' => ['jmhz-1.5.0.3/simpleTypes.xsd', 'http://schemas.cssz.cz/JMHZ/simpleTypes/2027', '1.5.0.3'];
        yield 'JMHZ souhrn' => ['jmhz-1.5.0.3/souhrn.xsd', 'http://schemas.cssz.cz/JMHZ/souhrn/2027', '1.5.0.3'];
        yield 'JMHZ PVPOJ' => ['jmhz-1.5.0.3/PVPOJ.xsd', 'http://schemas.cssz.cz/JMHZ/PVPOJ/2027', '1.5.0.3'];
        yield 'REGZEC27' => ['regzec-1.5/REGZEC27_1_5.xsd', 'http://schemas.cssz.cz/REGZEC/2027', '1.5.0.0'];
        yield 'PREZEC' => ['prezec-1.5/PREZEC26_1_5.xsd', 'http://schemas.cssz.cz/PREZEC/2026', '1.5.0.0'];
        yield 'DZMH' => ['dzmh-1.5/DZMH25_1_5.xsd', 'http://schemas.cssz.cz/JMHZ/dotazNaStav/2025', '1.5.0.0'];
        yield 'ZREZAM27' => ['zrezam-1.5/ZREZAM27_1_5.xsd', 'http://schemas.cssz.cz/ZREZAM/2027', '1.5.0.0'];
    }

    #[DataProvider('namespaces2027')]
    public function testSchemas2027PinTargetNamespaceAndVersion(string $relativePath, string $namespace, string $version): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(self::ROOT . '/' . $relativePath, LIBXML_NONET));
        $schema = $document->documentElement;
        self::assertNotNull($schema);
        self::assertSame($namespace, $schema->getAttribute('targetNamespace'));
        self::assertSame($version, $schema->getAttribute('version'));
    }

    /** Schéma 2027 se musí dát zkompilovat (include/import + typy), ne jen načíst jako XML. */
    #[DataProvider('entryPoints')]
    public function testEntryPointCompilesAsAnXmlSchema(string $relativePath): void
    {
        $instance = new DOMDocument();
        $instance->loadXML('<x xmlns="urn:myucto:no-such-namespace"/>');
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $instance->schemaValidate(self::ROOT . '/' . $relativePath);
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Instance schválně nevaliduje; chyby kompilace schématu ale mají jiný text
        // než "No matching global declaration".
        foreach ($messages as $message) {
            self::assertStringNotContainsString('Schemas parser error', $message, $relativePath);
            self::assertStringNotContainsString('failed to load', $message, $relativePath);
            self::assertStringNotContainsString('WXS schema', $message, $relativePath);
        }
        self::assertNotSame([], $messages, 'Instance cizího jmenného prostoru musí být zamítnuta.');
    }

    #[DataProvider('entryPoints')]
    public function testOfficialEntryPointIsVendored(string $relativePath): void
    {
        self::assertFileExists(self::ROOT . '/' . $relativePath);
    }

    public function testEverySchemaIsWellFormedAndHasCompleteLocalDependencies(): void
    {
        $files = $this->schemaFiles();
        self::assertCount(48, $files);

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
