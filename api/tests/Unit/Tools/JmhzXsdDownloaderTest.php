<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Tooling\JmhzXsdDownloader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

require_once dirname(__DIR__, 4) . '/tools/JmhzXsdDownloader.php';

final class JmhzXsdDownloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/myucto-jmhz-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testOfficialPackageManifestIsCompleteAndPinned(): void
    {
        $packages = $this->officialPackages();

        self::assertSame(
            [
                'jmhz' => ['1.4.3.6', '79a08fc6', 14, ['jmhzPodani.xsd']],
                'regzec' => ['1.4.0.4', '0d0396fd', 2, ['REGZEC25.xsd']],
                'prezec' => ['1.2', 'dda370c1', 2, ['PREZEC26 1.2.xsd']],
                'regzeldopl' => ['1.2', '6f0eb190', 2, ['REGZELDOPL25.xsd']],
                'dzmh' => ['1.1', '1e89ec55', 2, ['DZMH25.xsd']],
                'orezam-zrezam' => ['1.0', '9a153012', 3, ['OREZAM26.xsd', 'ZREZAM26.xsd']],
            ],
            array_map(
                static fn (array $package): array => [
                    $package['version'],
                    substr($package['sha256'], 0, 8),
                    $package['xsd_count'],
                    $package['entry_points'],
                ],
                $packages,
            ),
        );

        // Podstrom se připíná jen tam, kde ho oficiální archiv skutečně má.
        // U ostatních balíčků by `xsd_root` znamenal, že se tiše instaluje
        // něco jiného, než co je v archivu.
        $raw = require dirname(__DIR__, 4) . '/tools/jmhz-xsd-packages.php';
        self::assertIsArray($raw);
        self::assertSame('xsd_1_4_3_6/externi_xsd', $raw['jmhz']['xsd_root'] ?? null);
        foreach (['regzec', 'prezec', 'regzeldopl', 'dzmh', 'orezam-zrezam'] as $flatPackage) {
            self::assertIsArray($raw[$flatPackage]);
            self::assertArrayNotHasKey('xsd_root', $raw[$flatPackage]);
        }

        foreach ($packages as $id => $package) {
            self::assertSame($id . '-' . $package['version'], $package['target']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $package['sha256']);
            self::assertStringStartsWith('https://developers.mpsv.cz/assets/documents/', $package['url']);
            self::assertGreaterThanOrEqual(count($package['entry_points']), $package['xsd_count']);
            self::assertNotSame([], $package['entry_points']);
        }
    }

    public function testManifestRejectsAnyUrlOutsidePinnedMpsvArchivePath(): void
    {
        foreach ([
            'http://developers.mpsv.cz/assets/documents/00000000-0000-0000-0000-000000000000/sample.zip',
            'https://evil.test/assets/documents/00000000-0000-0000-0000-000000000000/sample.zip',
            'https://developers.mpsv.cz.evil.test/assets/documents/00000000-0000-0000-0000-000000000000/sample.zip',
            'https://developers.mpsv.cz/assets/documents/00000000-0000-0000-0000-000000000000/sample.zip?replace=1',
            'https://developers.mpsv.cz/other/sample.zip',
        ] as $url) {
            try {
                new JmhzXsdDownloader([
                    'sample' => $this->package($url, str_repeat('0', 64), 1, ['schema.xsd']),
                ]);
                self::fail("Unapproved JMHZ URL must be rejected: {$url}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('approved MPSV archive URL', $e->getMessage());
            }
        }
    }

    public function testCheckedInPackageTreeContainsOnlyWellFormedXsdFiles(): void
    {
        $root = dirname(__DIR__, 4) . '/api/xsd/jmhz';
        $expectedCounts = [
            'jmhz-1.4.3.6' => 14,
            'regzec-1.4.0.4' => 2,
            'prezec-1.2' => 2,
            'regzeldopl-1.2' => 2,
            'dzmh-1.1' => 2,
            'orezam-zrezam-1.0' => 3,
        ];

        foreach ($expectedCounts as $relative => $expectedCount) {
            $files = glob($root . '/' . $relative . '/*.xsd') ?: [];
            self::assertCount($expectedCount, $files, "Unexpected XSD count in {$relative}.");
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if (
                strtolower($item->getExtension()) === 'md'
                || $item->getFilename() === 'SHA256SUMS'
            ) {
                continue;
            }
            self::assertSame('xsd', strtolower($item->getExtension()), $item->getPathname());
            $document = new \DOMDocument();
            self::assertTrue($document->load($item->getPathname(), LIBXML_NONET), $item->getPathname());
        }
    }

    public function testInstallExtractsOnlyXsdAndPreservesOlderVersions(): void
    {
        $archive = $this->createArchive([
            'official-package/schema/main.xsd' => $this->schema('main'),
            'official-package/schema/base.xsd' => $this->schema('base'),
            'official-package/schema/utf16.xsd' => "\xFF\xFE"
                . mb_convert_encoding($this->schema('utf16'), 'UTF-16LE', 'UTF-8'),
            'official-package/sample.xml' => '<real-data-must-not-be-installed/>',
            'official-package/readme.txt' => 'documentation',
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target . '/legacy/1.0', 0777, true));
        self::assertSame(6, file_put_contents($target . '/legacy/1.0/old.xsd', 'legacy'));
        self::assertSame(8, file_put_contents($target . '/README.md', 'metadata'));

        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-9.9',
                'version' => '9.9',
                'url' => $this->officialUrl(),
                'sha256' => $this->hash($archive),
                'xsd_count' => 3,
                'entry_points' => ['schema/main.xsd'],
            ],
        ]);
        $downloader->installFromArchives(['sample' => $archive], $target);

        self::assertFileExists($target . '/sample-9.9/schema/main.xsd');
        self::assertFileExists($target . '/sample-9.9/schema/base.xsd');
        self::assertFileExists($target . '/sample-9.9/schema/utf16.xsd');
        self::assertFileDoesNotExist($target . '/sample-9.9/sample.xml');
        self::assertFileDoesNotExist($target . '/sample-9.9/readme.txt');
        self::assertSame('legacy', file_get_contents($target . '/legacy/1.0/old.xsd'));
        self::assertSame('metadata', file_get_contents($target . '/README.md'));
        self::assertSame(
            [
                hash('sha256', 'legacy') . '  legacy/1.0/old.xsd',
                hash_file('sha256', $target . '/sample-9.9/schema/base.xsd')
                    . '  sample-9.9/schema/base.xsd',
                hash_file('sha256', $target . '/sample-9.9/schema/main.xsd')
                    . '  sample-9.9/schema/main.xsd',
                hash_file('sha256', $target . '/sample-9.9/schema/utf16.xsd')
                    . '  sample-9.9/schema/utf16.xsd',
                '',
            ],
            explode("\n", (string) file_get_contents($target . '/SHA256SUMS')),
        );
    }

    /**
     * Oficiální balíček JMHZ veze od verze 1.4.3.5 vedle vnějších schémat i
     * vnitřní (`interni_xsd`) a k tomu HTML dokumentaci. `xsd_root` vybírá
     * podstrom, ze kterého se schémata připínají: bez něj by se nainstalovala
     * i vnitřní schémata, jejichž závislosti míří přes `../` mimo vlastní
     * adresář, a kontrola cest by celý balíček odmítla.
     */
    public function testXsdRootPinsOnlyTheSelectedSubtreeAndFlattensIt(): void
    {
        $archive = $this->createArchive([
            'official-package/xsd/externi/main.xsd' => $this->schema('main'),
            'official-package/xsd/externi/base.xsd' => $this->schema('base'),
            'official-package/xsd/interni/message.xsd' => '<?xml version="1.0"?>'
                . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
                . '<xs:include schemaLocation="../externi/base.xsd"/>'
                . '</xs:schema>',
            'official-package/html/index.html' => '<html lang="cs"></html>',
        ]);
        $target = $this->tempDir . '/jmhz';

        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-9.9',
                'version' => '9.9',
                'url' => $this->officialUrl(),
                'sha256' => $this->hash($archive),
                'xsd_count' => 2,
                'entry_points' => ['main.xsd'],
                'xsd_root' => 'xsd/externi',
            ],
        ]);
        $downloader->installFromArchives(['sample' => $archive], $target);

        self::assertFileExists($target . '/sample-9.9/main.xsd');
        self::assertFileExists($target . '/sample-9.9/base.xsd');
        self::assertFileDoesNotExist($target . '/sample-9.9/xsd/interni/message.xsd');
        self::assertFileDoesNotExist($target . '/sample-9.9/message.xsd');
    }

    public function testManifestRejectsAnUnsafeXsdRoot(): void
    {
        foreach (['../externi', '/xsd/externi', 'xsd\\externi', 'xsd/./externi', 'xsd/externi/', ''] as $root) {
            try {
                new JmhzXsdDownloader([
                    'sample' => $this->package(
                        $this->officialUrl(),
                        str_repeat('0', 64),
                        1,
                        ['schema.xsd'],
                    ) + ['xsd_root' => $root],
                ]);
                self::fail("Nebezpečný xsd_root musí být odmítnut: {$root}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('manifest entry', $e->getMessage());
            }
        }
    }

    public function testHashMismatchLeavesExistingTreeUntouched(): void
    {
        $archive = $this->createArchive([
            'schema.xsd' => $this->schema('schema'),
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target, 0777, true));
        self::assertSame(8, file_put_contents($target . '/keep.xsd', 'original'));
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => $this->officialUrl(),
                'sha256' => str_repeat('0', 64),
                'xsd_count' => 1,
                'entry_points' => ['schema.xsd'],
            ],
        ]);

        try {
            $downloader->installFromArchives(['sample' => $archive], $target);
            self::fail('A package with an unexpected hash must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }

        self::assertSame('original', file_get_contents($target . '/keep.xsd'));
        self::assertDirectoryDoesNotExist($target . '/sample');
    }

    public function testUnsafeXsdPathIsRejectedBeforeTargetReplacement(): void
    {
        $archive = $this->createArchive([
            '../escape.xsd' => $this->schema('escape'),
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target, 0777, true));
        self::assertSame(8, file_put_contents($target . '/keep.xsd', 'original'));
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => $this->officialUrl(),
                'sha256' => $this->hash($archive),
                'xsd_count' => 1,
                'entry_points' => ['escape.xsd'],
            ],
        ]);

        try {
            $downloader->installFromArchives(['sample' => $archive], $target);
            self::fail('A path-traversal archive entry must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('unsafe path', $e->getMessage());
        }

        self::assertSame('original', file_get_contents($target . '/keep.xsd'));
        self::assertFileDoesNotExist($this->tempDir . '/escape.xsd');
    }

    public function testArchivePathGuardRejectsWindowsAliasesAndAmbiguousSegments(): void
    {
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => $this->officialUrl(),
                'sha256' => str_repeat('0', 64),
                'xsd_count' => 1,
                'entry_points' => ['schema.xsd'],
            ],
        ]);
        $guard = new \ReflectionMethod($downloader, 'assertSafeArchivePath');

        foreach ([
            'schema/./main.xsd',
            'schema/file:stream.xsd',
            'schema/CON.xsd',
            'schema/trailing./main.xsd',
        ] as $path) {
            $rejected = false;
            try {
                $guard->invoke($downloader, $path);
            } catch (RuntimeException $exception) {
                $rejected = str_contains(
                    $exception->getMessage(),
                    'unsafe path',
                );
            }
            self::assertTrue(
                $rejected,
                "Nejednoznačná ZIP cesta musí být odmítnuta: {$path}",
            );
        }
    }

    public function testHttpMetadataUsesFinalStatusAndSingleRedirectLocation(): void
    {
        $downloader = new JmhzXsdDownloader([
            'sample' => $this->package(
                $this->officialUrl(),
                str_repeat('0', 64),
                1,
                ['schema.xsd'],
            ),
        ]);
        $status = new \ReflectionMethod($downloader, 'responseStatus');
        $location = new \ReflectionMethod($downloader, 'redirectLocation');

        self::assertSame(200, $status->invoke($downloader, [
            'HTTP/1.1 302 Found',
            'Location: /assets/documents/00000000-0000-0000-0000-000000000000/next.zip',
            'HTTP/2 200',
        ]));
        self::assertSame(
            '/assets/documents/00000000-0000-0000-0000-000000000000/next.zip',
            $location->invoke($downloader, [
                'HTTP/1.1 302 Found',
                'Location: /assets/documents/00000000-0000-0000-0000-000000000000/next.zip',
            ]),
        );
        self::assertNull($location->invoke($downloader, [
            'Location: /first.zip',
            'Location: /second.zip',
        ]));
    }

    public function testRedirectResolutionKeepsOfficialPinnedArchiveBoundary(): void
    {
        $downloader = new JmhzXsdDownloader([
            'sample' => $this->package(
                $this->officialUrl(),
                str_repeat('0', 64),
                1,
                ['schema.xsd'],
            ),
        ]);
        $resolve = new \ReflectionMethod($downloader, 'resolveRedirectUrl');
        $nextUrl = 'https://developers.mpsv.cz/assets/documents/'
            . '00000000-0000-0000-0000-000000000000/next.zip';

        self::assertSame(
            $nextUrl,
            $resolve->invoke($downloader, $this->officialUrl(), 'next.zip'),
        );
        self::assertSame(
            $nextUrl,
            $resolve->invoke(
                $downloader,
                $this->officialUrl(),
                '/assets/documents/00000000-0000-0000-0000-000000000000/next.zip',
            ),
        );

        foreach ([
            '//evil.test/next.zip',
            'https://evil.test/next.zip',
            '../next.zip',
        ] as $unsafeLocation) {
            try {
                $resolve->invoke(
                    $downloader,
                    $this->officialUrl(),
                    $unsafeLocation,
                );
                self::fail("Unsafe redirect must be rejected: {$unsafeLocation}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString(
                    $unsafeLocation === '//evil.test/next.zip'
                        ? 'Protocol-relative'
                        : 'approved MPSV archive URL',
                    $e->getMessage(),
                );
            }
        }
    }

    public function testDownloadedArchiveMustHaveZipSignatureAndIsRemovedOnFailure(): void
    {
        $downloader = new JmhzXsdDownloader([
            'sample' => $this->package(
                $this->officialUrl(),
                str_repeat('0', 64),
                1,
                ['schema.xsd'],
            ),
        ]);
        $write = new \ReflectionMethod($downloader, 'writeLimitedArchive');
        $input = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        self::assertSame(12, fwrite($input, 'not a zip!!!'));
        rewind($input);
        $target = $this->tempDir . '/download.zip';

        try {
            $write->invoke($downloader, $input, $target, $this->officialUrl());
            self::fail('Downloaded content without a ZIP signature must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                'not a non-empty ZIP archive',
                $e->getMessage(),
            );
        } finally {
            fclose($input);
        }

        self::assertFileDoesNotExist($target);
    }

    public function testMissingEntryPointOrDependencyLeavesExistingTreeUntouched(): void
    {
        $archive = $this->createArchive([
            'schema/main.xsd' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
                . '<xs:include schemaLocation="missing.xsd"/>'
                . '</xs:schema>',
        ]);
        $target = $this->tempDir . '/jmhz';
        self::assertTrue(mkdir($target, 0777, true));
        self::assertSame(8, file_put_contents($target . '/keep.xsd', 'original'));
        $downloader = new JmhzXsdDownloader([
            'sample' => [
                'target' => 'sample-1.0',
                'version' => '1.0',
                'url' => $this->officialUrl(),
                'sha256' => $this->hash($archive),
                'xsd_count' => 1,
                'entry_points' => ['main.xsd'],
            ],
        ]);

        try {
            $downloader->installFromArchives(['sample' => $archive], $target);
            self::fail('A package with a missing XSD dependency must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('missing or external XSD dependency', $e->getMessage());
        }

        self::assertSame('original', file_get_contents($target . '/keep.xsd'));
        self::assertDirectoryDoesNotExist($target . '/sample-1.0');
    }

    /** @param array<string,string> $entries */
    private function createArchive(array $entries): string
    {
        $path = $this->tempDir . '/' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    private function schema(string $element): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
            . '<xs:element name="' . $element . '" type="xs:string"/>'
            . '</xs:schema>';
    }

    /**
     * @return array<string,array{
     *     target:string,
     *     version:string,
     *     url:string,
     *     sha256:string,
     *     xsd_count:int,
     *     entry_points:list<string>
     * }>
     */
    private function officialPackages(): array
    {
        $packages = require dirname(__DIR__, 4) . '/tools/jmhz-xsd-packages.php';
        self::assertIsArray($packages);

        $validated = [];
        foreach ($packages as $id => $package) {
            self::assertIsString($id);
            self::assertIsArray($package);
            $validated[$id] = [
                'target' => $this->stringField($package, 'target'),
                'version' => $this->stringField($package, 'version'),
                'url' => $this->stringField($package, 'url'),
                'sha256' => $this->stringField($package, 'sha256'),
                'xsd_count' => $this->intField($package, 'xsd_count'),
                'entry_points' => $this->stringListField($package, 'entry_points'),
            ];
        }

        return $validated;
    }

    /** @param array<mixed> $values */
    private function stringField(array $values, string $field): string
    {
        self::assertArrayHasKey($field, $values);
        self::assertIsString($values[$field]);

        return $values[$field];
    }

    /** @param array<mixed> $values */
    private function intField(array $values, string $field): int
    {
        self::assertArrayHasKey($field, $values);
        self::assertIsInt($values[$field]);

        return $values[$field];
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringListField(array $values, string $field): array
    {
        self::assertArrayHasKey($field, $values);
        self::assertIsArray($values[$field]);
        $result = [];
        foreach ($values[$field] as $value) {
            if (!is_string($value)) {
                self::fail("Manifest field {$field} must contain only strings.");
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param list<string> $entryPoints
     * @return array{
     *     target:string,
     *     version:string,
     *     url:string,
     *     sha256:string,
     *     xsd_count:int,
     *     entry_points:list<string>
     * }
     */
    private function package(string $url, string $sha256, int $xsdCount, array $entryPoints): array
    {
        return [
            'target' => 'sample-1.0',
            'version' => '1.0',
            'url' => $url,
            'sha256' => $sha256,
            'xsd_count' => $xsdCount,
            'entry_points' => $entryPoints,
        ];
    }

    private function officialUrl(): string
    {
        return 'https://developers.mpsv.cz/assets/documents/'
            . '00000000-0000-0000-0000-000000000000/sample.zip';
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        self::assertIsString($hash);

        return $hash;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
