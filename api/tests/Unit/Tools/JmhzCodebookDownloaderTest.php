<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tooling\JmhzCodebookDownloader;
use MyInvoice\Tooling\JmhzCodebookManifestDiff;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/tools/JmhzCodebookDownloader.php';
require_once dirname(__DIR__, 4) . '/tools/JmhzCodebookManifestDiff.php';

final class JmhzCodebookDownloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/myucto-jmhz-codebook-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempDir, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testPinnedResourceTreeMatchesTheOfficialManifest(): void
    {
        $downloader = new JmhzCodebookDownloader($this->officialManifest());
        $downloader->verifyInstalled(dirname(__DIR__, 3) . '/resources/payroll/jmhz');

        $sources = $downloader->sources();
        self::assertSame(
            [
                'dictionary',
                'control-catalog',
                'scenario-matrix',
                'cisob',
                'czemalfa',
                'cisob-511-legal-coverage',
                'czemalfa-august-coverage',
                'cisob-145-2026',
                'czemalfa-2026-08-26',
            ],
            array_keys($sources),
        );
        foreach ($sources as $id => $source) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $source['sha256'], $id);
            if ($source['url'] === null) {
                continue;
            }
            self::assertStringStartsWith('https://developers.mpsv.cz/assets/documents/', $source['url'], $id);
            self::assertTrue(str_ends_with($source['url'], $source['filename']), $id);
        }
        self::assertNull($sources['cisob']['url']);
        self::assertNull($sources['czemalfa']['url']);
    }

    public function testCheckedInContentManifestCoversEveryPinnedResource(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/payroll/jmhz';
        $lines = file($root . '/SHA256SUMS', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $listed = [];
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64} {2}\S.*\z/', $line);
            [$hash, $relative] = explode('  ', $line, 2);
            $listed[$relative] = $hash;
        }
        self::assertSame(array_keys($listed), $this->sortedCopy(array_keys($listed)));

        $found = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if (!$item->isFile() || $item->getFilename() === 'SHA256SUMS'
                || strtolower($item->getExtension()) === 'md'
            ) {
                continue;
            }
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($item->getPathname(), strlen($root) + 1),
            );
            self::assertArrayHasKey($relative, $listed, 'Chybí v SHA256SUMS.');
            self::assertSame($listed[$relative], hash_file('sha256', $item->getPathname()), $relative);
            $found[$relative] = true;
        }
        self::assertSame([], array_diff_key($listed, $found), 'SHA256SUMS uvádí soubor, který neexistuje.');
    }

    public function testRealPinnedManifestKeepsExternalCodebooksEmptyAndDiffsCleanAgainstItself(): void
    {
        $manifest = JmhzCodebookManifestDiff::load(
            dirname(__DIR__, 3) . '/resources/payroll/jmhz/dictionary-1.4.1.6/manifest.json',
        );

        $report = JmhzCodebookManifestDiff::between($manifest, $manifest);
        self::assertFalse($report['changed']);
        self::assertSame([], $report['codebooks']);

        $external = [];
        foreach ($manifest['payload']['codebooks'] as $codebook) {
            if ($codebook['source_kind'] === 'external_reference') {
                $external[] = $codebook['codebook_key'];
                self::assertSame(0, $codebook['entry_count'], $codebook['codebook_key']);
                self::assertSame([], $codebook['entries'], $codebook['codebook_key']);
            }
        }
        self::assertSame(
            [
                'klasifikace_ekonomickych_ci',
                'klasifikace_v_zamestnani',
                'kody_bank',
                'obce',
                'stat',
                'zdravotni_pojistovny',
            ],
            $this->sortedCopy($external),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedCopy(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    public function testInstallReplacesPinnedSourcesAndRewritesContentManifest(): void
    {
        [$root, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $downloaded = $this->tempDir . '/downloaded.xlsx';
        self::assertSame(10, file_put_contents($downloaded, "PK\x03\x04sample"));
        self::assertSame(7, file_put_contents($root . '/catalog-1.0/source.xlsx', 'damaged'));

        $downloader->installFromFiles(['remote' => $downloaded], $root);

        self::assertSame("PK\x03\x04sample", file_get_contents($root . '/catalog-1.0/source.xlsx'));
        self::assertSame('ruční zdroj', file_get_contents($root . '/catalog-1.0/manual.csv'));
        $sums = (string) file_get_contents($root . '/SHA256SUMS');
        self::assertSame(
            [
                hash_file('sha256', $root . '/catalog-1.0/manifest.json') . '  catalog-1.0/manifest.json',
                hash('sha256', 'ruční zdroj') . '  catalog-1.0/manual.csv',
                hash('sha256', "PK\x03\x04sample") . '  catalog-1.0/source.xlsx',
                '',
            ],
            explode("\n", $sums),
        );
    }

    public function testWrongHashLeavesThePinnedTreeUntouched(): void
    {
        [$root, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $downloaded = $this->tempDir . '/downloaded.xlsx';
        self::assertSame(10, file_put_contents($downloaded, "PK\x03\x04rogue!"));

        try {
            $downloader->installFromFiles(['remote' => $downloaded], $root);
            self::fail('Zdroj s jiným hashem musí být odmítnut.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('nemá připnutý SHA-256', $e->getMessage());
        }

        self::assertSame("PK\x03\x04sample", file_get_contents($root . '/catalog-1.0/source.xlsx'));
        self::assertFileDoesNotExist($root . '/SHA256SUMS');
    }

    public function testUnexpectedByteLengthIsRejectedBeforeTheHashCheck(): void
    {
        [$root, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $downloaded = $this->tempDir . '/downloaded.xlsx';
        self::assertSame(11, file_put_contents($downloaded, "PK\x03\x04sample2"));

        try {
            $downloader->installFromFiles(['remote' => $downloaded], $root);
            self::fail('Zdroj s jiným počtem bajtů musí být odmítnut.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('11 bajtů; očekáváno 10', $e->getMessage());
        }

        self::assertSame("PK\x03\x04sample", file_get_contents($root . '/catalog-1.0/source.xlsx'));
    }

    public function testUnexpectedCodebookCountIsRejected(): void
    {
        [$root, $manifest] = $this->syntheticTree();
        $manifest['catalogs']['catalog-1.0/manifest.json']['counts']['codebook_entries'] = 99;
        $downloader = new JmhzCodebookDownloader($manifest);
        $downloaded = $this->tempDir . '/downloaded.xlsx';
        self::assertSame(10, file_put_contents($downloaded, "PK\x03\x04sample"));

        try {
            $downloader->installFromFiles(['remote' => $downloaded], $root);
            self::fail('Katalog s jiným počtem položek musí být odmítnut.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('u codebook_entries hodnotu 2; očekáváno 99', $e->getMessage());
        }

        self::assertFileDoesNotExist($root . '/SHA256SUMS');
    }

    public function testExternalReferenceCodebookMustStayEmpty(): void
    {
        [$root, $manifest] = $this->syntheticTree(fillExternalReference: true);
        $downloader = new JmhzCodebookDownloader($manifest);
        $downloaded = $this->tempDir . '/downloaded.xlsx';
        self::assertSame(10, file_put_contents($downloaded, "PK\x03\x04sample"));

        try {
            $downloader->installFromFiles(['remote' => $downloaded], $root);
            self::fail('Naplněná externí reference musí být odmítnuta.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('musí zůstat prázdnou externí referencí', $e->getMessage());
        }

        self::assertFileDoesNotExist($root . '/SHA256SUMS');
    }

    public function testManifestRejectsAnyUrlOutsideThePinnedMpsvArchivePath(): void
    {
        foreach ([
            'http://developers.mpsv.cz/assets/documents/00000000-0000-0000-0000-000000000000/source.xlsx',
            'https://evil.test/assets/documents/00000000-0000-0000-0000-000000000000/source.xlsx',
            'https://developers.mpsv.cz.evil.test/assets/documents/00000000-0000-0000-0000-000000000000/source.xlsx',
            'https://developers.mpsv.cz/assets/documents/00000000-0000-0000-0000-000000000000/source.xlsx?raw=1',
            'https://developers.mpsv.cz:8443/assets/documents/00000000-0000-0000-0000-000000000000/source.xlsx',
            'https://developers.mpsv.cz/other/source.xlsx',
            'https://developers.mpsv.cz/assets/documents/00000000-0000-0000-0000-000000000000/source.exe',
        ] as $url) {
            [, $manifest] = $this->syntheticTree();
            $manifest['sources']['remote']['url'] = $url;
            try {
                new JmhzCodebookDownloader($manifest);
                self::fail("Neschválená URL musí být odmítnuta: {$url}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('schválenou URL archivu MPSV', $e->getMessage());
            }
        }
    }

    public function testRedirectResolutionKeepsThePinnedArchiveBoundary(): void
    {
        [, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $resolve = new \ReflectionMethod($downloader, 'resolveRedirectUrl');
        $current = $this->officialUrl();
        $next = 'https://developers.mpsv.cz/assets/documents/'
            . '00000000-0000-0000-0000-000000000000/next.xlsx';

        self::assertSame($next, $resolve->invoke($downloader, $current, 'next.xlsx'));
        self::assertSame($next, $resolve->invoke(
            $downloader,
            $current,
            '/assets/documents/00000000-0000-0000-0000-000000000000/next.xlsx',
        ));

        foreach ([
            '//evil.test/next.xlsx',
            'https://evil.test/next.xlsx',
            '../next.xlsx',
        ] as $location) {
            try {
                $resolve->invoke($downloader, $current, $location);
                self::fail("Nebezpečné přesměrování musí být odmítnuto: {$location}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString(
                    $location === '//evil.test/next.xlsx'
                        ? 'Přesměrování bez schématu'
                        : 'schválenou URL archivu MPSV',
                    $e->getMessage(),
                );
            }
        }
    }

    public function testOversizedOrMalformedPayloadIsRejectedAndRemoved(): void
    {
        [, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $write = new \ReflectionMethod($downloader, 'writeLimitedSource');

        $input = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        self::assertSame(64, fwrite($input, str_repeat('x', 64)));
        rewind($input);
        $target = $this->tempDir . '/oversized.bin';
        try {
            $write->invoke($downloader, $input, $target, $this->officialUrl(), 'zip', 16);
            self::fail('Příliš velký obsah musí být odmítnut.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('překračuje povolenou velikost 16 B', $e->getMessage());
        } finally {
            fclose($input);
        }
        self::assertFileDoesNotExist($target);

        $input = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        self::assertSame(12, fwrite($input, '<html>404!!!'));
        rewind($input);
        $target = $this->tempDir . '/not-a-zip.bin';
        try {
            $write->invoke($downloader, $input, $target, $this->officialUrl(), 'zip', 1024);
            self::fail('Obsah bez ZIP podpisu musí být odmítnut.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('nemá očekávaný obsah (zip)', $e->getMessage());
        } finally {
            fclose($input);
        }
        self::assertFileDoesNotExist($target);
    }

    public function testMpsvBlobFallsBackToTheOtherUnicodeNormalization(): void
    {
        [, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $select = new \ReflectionMethod($downloader, 'selectAvailableUrl');
        $request = new \ReflectionMethod($downloader, 'requestUrl');
        $fixture = $this->normalizationFixture();

        foreach ($fixture as $name => $case) {
            $statuses = $case['statuses'];
            $probe = function (string $candidate) use ($request, $downloader, $statuses, $name): int {
                $encoded = $request->invoke($downloader, $candidate);
                self::assertIsString($encoded);
                self::assertArrayHasKey($encoded, $statuses, "Neočekávaný požadavek v případu {$name}: {$encoded}");

                return $statuses[$encoded];
            };

            if ($case['expected_request_url'] === null) {
                try {
                    $select->invoke($downloader, $case['pinned_url'], $probe);
                    self::fail("Chybějící soubor musí selhat: {$name}");
                } catch (RuntimeException $e) {
                    self::assertStringContainsString('není dostupný v žádné normalizaci', $e->getMessage());
                    self::assertSame(JmhzCodebookDownloader::NOT_FOUND, $e->getCode());
                }
                continue;
            }

            $selected = $select->invoke($downloader, $case['pinned_url'], $probe);
            self::assertIsString($selected);
            self::assertSame(
                $case['expected_request_url'],
                $request->invoke($downloader, $selected),
                "Nesprávná normalizace v případu {$name}.",
            );
        }
    }

    public function testAsciiOnlyNameProducesASingleRequestCandidate(): void
    {
        [, $manifest] = $this->syntheticTree();
        $downloader = new JmhzCodebookDownloader($manifest);
        $candidates = new \ReflectionMethod($downloader, 'downloadUrlCandidates');

        self::assertSame(
            [$this->officialUrl()],
            $candidates->invoke($downloader, $this->officialUrl()),
        );
        self::assertCount(
            2,
            (array) $candidates->invoke(
                $downloader,
                'https://developers.mpsv.cz/assets/documents/'
                    . '00000000-0000-0000-0000-000000000000/Seznam zaměstnanců.csv',
            ),
        );
    }

    public function testChangeReportListsAddedRemovedAndChangedItemCodes(): void
    {
        $pinned = JmhzCodebookManifestDiff::load($this->fixturePath('jmhz-codebook-manifest-pinned.json'));
        $candidate = JmhzCodebookManifestDiff::load($this->fixturePath('jmhz-codebook-manifest-candidate.json'));

        $report = JmhzCodebookManifestDiff::between($pinned, $candidate);

        self::assertSame(JmhzCodebookManifestDiff::SCHEMA_VERSION, $report['schema_version']);
        self::assertTrue($report['changed']);
        self::assertSame(
            [
                'added_codebooks' => 1,
                'removed_codebooks' => 1,
                'changed_codebooks' => 1,
                'added_items' => 2,
                'removed_items' => 2,
                'changed_items' => 1,
            ],
            $report['counts'],
        );
        self::assertSame('1.4.1.6', $report['pinned']['versions']['dictionary']);
        self::assertSame('1.4.1.7', $report['candidate']['versions']['dictionary']);
        self::assertSame('2026-08-13', $report['pinned']['snapshot_date']);
        self::assertSame('2026-09-01', $report['candidate']['snapshot_date']);

        $byKey = [];
        foreach ($report['codebooks'] as $codebook) {
            $byKey[$codebook['codebook_key']] = $codebook;
        }
        self::assertSame(['druh_cinnosti', 'novy', 'zanikly'], array_keys($byKey));
        self::assertArrayNotHasKey('obce', $byKey, 'Nezměněný číselník se do reportu nesmí dostat.');

        self::assertSame('changed', $byKey['druh_cinnosti']['status']);
        self::assertSame(['D'], $byKey['druh_cinnosti']['added_item_codes']);
        self::assertSame(['C'], $byKey['druh_cinnosti']['removed_item_codes']);
        self::assertSame(
            [[
                'item_code' => 'B',
                'pinned_row_hash' => 'hash-b',
                'candidate_row_hash' => 'hash-b2',
                'pinned_label' => 'Dohoda o provedení práce',
                'candidate_label' => 'Dohoda o provedení práce (nově)',
            ]],
            $byKey['druh_cinnosti']['changed_items'],
        );
        self::assertSame('added', $byKey['novy']['status']);
        self::assertSame(['N'], $byKey['novy']['added_item_codes']);
        self::assertSame('removed', $byKey['zanikly']['status']);
        self::assertSame(['Z'], $byKey['zanikly']['removed_item_codes']);

        $path = $this->tempDir . '/changes.json';
        JmhzCodebookManifestDiff::write($report, $path);
        self::assertSame($report, json_decode((string) file_get_contents($path), true));
    }

    public function testUnchangedManifestProducesAnEmptyChangeReport(): void
    {
        $pinned = JmhzCodebookManifestDiff::load($this->fixturePath('jmhz-codebook-manifest-pinned.json'));

        $report = JmhzCodebookManifestDiff::between($pinned, $pinned);

        self::assertFalse($report['changed']);
        self::assertSame([], $report['codebooks']);
        self::assertSame(0, array_sum($report['counts']));
    }

    /**
     * @return array<string,array{pinned_url:string,expected_request_url:string|null,statuses:array<string,int>}>
     */
    private function normalizationFixture(): array
    {
        $decoded = json_decode(
            (string) file_get_contents($this->fixturePath('jmhz-mpsv-blob-normalization.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['cases'] ?? null);

        $cases = [];
        foreach ($decoded['cases'] as $name => $case) {
            self::assertIsString($name);
            self::assertIsArray($case);
            self::assertIsString($case['pinned_url']);
            self::assertIsArray($case['statuses']);
            $statuses = [];
            foreach ($case['statuses'] as $url => $status) {
                self::assertIsString($url);
                self::assertIsInt($status);
                $statuses[$url] = $status;
            }
            $expected = $case['expected_request_url'];
            self::assertTrue($expected === null || is_string($expected));
            $cases[$name] = [
                'pinned_url' => $case['pinned_url'],
                'expected_request_url' => $expected,
                'statuses' => $statuses,
            ];
        }

        return $cases;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function syntheticTree(bool $fillExternalReference = false): array
    {
        $root = $this->tempDir . '/jmhz-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root . '/catalog-1.0', 0777, true));
        self::assertSame(10, file_put_contents($root . '/catalog-1.0/source.xlsx', "PK\x03\x04sample"));
        self::assertSame(13, file_put_contents($root . '/catalog-1.0/manual.csv', 'ruční zdroj'));

        $payload = [
            'schema_version' => 'jmhz-spec-package.v1',
            'package_key' => 'jmhz-test-manifest-v1',
            'counts' => [
                'codebooks' => 2,
                'codebook_entries' => 2,
            ],
            'sources' => [
                [
                    'filename' => 'source.xlsx',
                    'sha256' => hash('sha256', "PK\x03\x04sample"),
                    'byte_length' => 10,
                ],
            ],
            'codebooks' => [
                [
                    'codebook_key' => 'vlozeny',
                    'source_kind' => 'embedded',
                    'entry_count' => 2,
                    'entries' => [
                        ['item_code' => 'A', 'label' => 'A', 'row_hash' => 'hash-a'],
                        ['item_code' => 'B', 'label' => 'B', 'row_hash' => 'hash-b'],
                    ],
                ],
                [
                    'codebook_key' => 'obce',
                    'source_kind' => $fillExternalReference ? 'embedded' : 'external_reference',
                    'entry_count' => $fillExternalReference ? 1 : 0,
                    'entries' => $fillExternalReference
                        ? [['item_code' => '500054', 'label' => 'Vymyšlená obec', 'row_hash' => 'hash-x']]
                        : [],
                ],
            ],
        ];
        $manifestSha256 = hash('sha256', CanonicalJson::encode($payload));
        $json = json_encode(
            ['manifest_sha256' => $manifestSha256, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        self::assertIsInt(file_put_contents($root . '/catalog-1.0/manifest.json', $json));

        return [$root, [
            'sources' => [
                'remote' => [
                    'target' => 'catalog-1.0',
                    'filename' => 'source.xlsx',
                    'version' => '1.0',
                    'url' => $this->officialUrl(),
                    'sha256' => hash('sha256', "PK\x03\x04sample"),
                    'byte_length' => 10,
                    'content_types' => ['application/octet-stream'],
                    'signature' => 'zip',
                ],
                'manual' => [
                    'target' => 'catalog-1.0',
                    'filename' => 'manual.csv',
                    'version' => '2026-01-01',
                    'url' => null,
                    'sha256' => hash('sha256', 'ruční zdroj'),
                    'byte_length' => 13,
                    'content_types' => [],
                    'signature' => 'utf8-text',
                ],
            ],
            'catalogs' => [
                'catalog-1.0/manifest.json' => [
                    'schema_version' => 'jmhz-spec-package.v1',
                    'identity_key' => 'package_key',
                    'identity' => 'jmhz-test-manifest-v1',
                    'manifest_sha256' => $manifestSha256,
                    'counts' => ['codebooks' => 2, 'codebook_entries' => 2],
                    'external_reference_codebooks' => ['obce'],
                    'base_manifest_sha256' => null,
                ],
            ],
        ]];
    }

    /** @return array<string,mixed> */
    private function officialManifest(): array
    {
        $manifest = require dirname(__DIR__, 4) . '/tools/jmhz-codebook-sources.php';
        self::assertIsArray($manifest);

        return $manifest;
    }

    private function officialUrl(): string
    {
        return 'https://developers.mpsv.cz/assets/documents/'
            . '00000000-0000-0000-0000-000000000000/source.xlsx';
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Payroll/' . $name;
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
