<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tools;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Balík specifikace JMHZ pro rok 2027 (XSD 1.5.0.3, slovník 1.5.0.3, katalog
 * kontrol 1.5.0.4, scénáře 1.5.0.3) a dokumentační balíček EDV REGZEC 1.5.0.3.
 *
 * Zdrojové sešity jsou finální návrh MPSV, který se v připomínkovém řízení
 * vydává znovu. Test proto připíná bajty, hashe manifestů a počty: nová verze
 * podkladu musí být vědomá změna, ne tichá výměna souboru.
 */
final class JmhzSpecPackage2027Test extends TestCase
{
    private const PACKAGE_KEY = 'jmhz-xsd-1.5.0.3_dictionary-1.5.0.3_controls-source-1.5.0.4_manifest-v1';
    private const MANIFEST_SHA256 = 'fc244e34b74117cfc332b62d787738754562b91c61f33ee29370197967f8e5b0';

    private string $root;
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
        $this->temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-jmhz-spec-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporary);
        parent::tearDown();
    }

    public function testSourceWorkbooksAreBytePinned(): void
    {
        $directory = $this->packageDirectory();
        $expected = [
            'datovy_slovnik_1.5.0.3.xlsx' => '861bc297adbadd475efa68214bdf194ce1b60a14337d5cf10307cf00b12e92ca',
            'Katalog kontrol MH(public)_1.5.0.4.xlsx' => '748dd83a376540b85d5e0942af95ba6230671007c6e4269dda50db74faa88d35',
            'datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx' => 'c4cb0704208f463417b564fd91a1884c71789b84d2966b074af33e5c41b5f45e',
        ];
        foreach ($expected as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $directory . '/' . $file), $file);
        }
        self::assertSame(
            '1b1a4c1ec0d1e49663d0b793539a67c655555dfc208e2a4ba792f07226bc79c0',
            hash_file('sha256', $this->edvDirectory() . '/Definice externí datové věty_1.5.0.3.xlsx'),
        );
    }

    public function testDictionaryManifestIsSelfConsistentAndPinned(): void
    {
        $manifest = $this->manifest('manifest.json');
        $payload = $manifest['payload'];

        self::assertSame(self::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame(hash('sha256', CanonicalJson::encode($payload)), $manifest['manifest_sha256']);
        self::assertSame(self::PACKAGE_KEY, $payload['package_key']);
        self::assertSame(
            [
                'xsd' => '1.5.0.3',
                'dictionary' => '1.5.0.3',
                'control_catalog' => '1.5.0.4',
                'process' => '1.5.0.3',
                'instructions' => '1.4.14',
            ],
            $payload['versions'],
        );
        self::assertSame(
            [
                'attributes' => 454,
                'reporting_marker_attributes' => 453,
                'reporting_marker_xsd_mapped_attributes' => 354,
                'monthly_attributes' => 245,
                'monthly_xsd_mapped_attributes' => 239,
                'codebooks' => 45,
                'embedded_codebooks' => 38,
                'external_reference_codebooks' => 7,
                'codebook_entries' => 597,
            ],
            $payload['counts'],
        );
        self::assertSame(
            ['dictionary', 'control_catalog_source'],
            array_column($payload['sources'], 'role'),
        );
    }

    /**
     * Rozdíl proti slovníku 2026 je přesně to, co NV2027 mění: 27 nových a
     * 15 zrušených atributů (zrušené jsou jmenovitě v kartách ZP 066, 108, 115,
     * 128 a v aktualitě ze 7. 10. 2026).
     */
    public function testDictionaryDifferenceAgainst2026MatchesTheAnnouncedChange(): void
    {
        $new = array_column($this->manifest('manifest.json')['payload']['dictionary_attributes'], null, 'attribute_id');
        $old = array_column($this->manifest('manifest.json', '1.4.1.6')['payload']['dictionary_attributes'], null, 'attribute_id');

        $added = array_map('strval', array_diff(array_keys($new), array_keys($old)));
        $removed = array_map('strval', array_diff(array_keys($old), array_keys($new)));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);

        self::assertSame(
            [
                '10550', '10551', '10552', '10553', '10554', '10555', '10556', '10565', '10566', '10568',
                '10569', '10570', '10571', '10575', '10577', '10578', '10579', '10580', '10581', '10582',
                '10583', '10584', '10585', '10586', '10587', '10588', '10589',
            ],
            $added,
        );
        self::assertSame(
            [
                '10267', '10292', '10293', '10294', '10295', '10296', '10310', '10347', '10411', '10412',
                '10416', '10418', '10531', '10532', '10533',
            ],
            $removed,
        );
        // Atributy zrušené uvnitř dodávky se nikdy nezakládají.
        foreach (['10557', '10558', '10559', '10560', '10561', '10562', '10563', '10564', '10567', '10572', '10573', '10574'] as $neverCreated) {
            self::assertArrayNotHasKey($neverCreated, $new);
        }
        // Sloupec MĚSÍČNÍ se ve slovníku 1.5 posunul o nový sloupec A3 DOHL - PRIO;
        // sběr se proto hledá podle hlavičky. Atribut 10001 (GUID podání) je měsíční.
        self::assertSame('x', $new['10001']['monthly_marker']);
        self::assertSame($old['10001']['monthly_marker'], $new['10001']['monthly_marker']);
    }

    public function testControlCatalogIsPinnedAndMatchesTheDocumentedControlChanges(): void
    {
        $manifest = $this->manifest('control-manifest.json');
        $payload = $manifest['payload'];

        self::assertSame(hash('sha256', CanonicalJson::encode($payload)), $manifest['manifest_sha256']);
        self::assertSame('jmhz-controls-1.5.0.4-source-v4', $payload['catalog_key']);
        self::assertSame('1.5.0.4', $payload['version']);
        self::assertSame(self::PACKAGE_KEY, $payload['spec_package_key']);
        self::assertSame(self::MANIFEST_SHA256, $payload['spec_manifest_sha256']);
        self::assertSame(221, $payload['counts']['controls']);
        self::assertSame(199, $payload['counts']['blocking_remote_controls']);
        self::assertSame(18, $payload['counts']['passable_remote_controls']);
        self::assertSame(4, $payload['counts']['unavailable_remote_controls']);
        self::assertSame(0, $payload['counts']['source_anomalies']);

        $new = array_column($payload['controls'], null, 'control_id');
        $old = array_column($this->manifest('control-manifest.json', '1.4.1.6')['payload']['controls'], null, 'control_id');
        $added = array_values(array_diff(array_keys($new), array_keys($old)));
        $removed = array_values(array_diff(array_keys($old), array_keys($new)));
        sort($added);

        // Katalog 1.5.0.4 přidává proti 1.4.2.10 22 kontrol; tři z nich (368,
        // 369 a 371) převzal už katalog 1.4.2.11 pro rok 2026.
        self::assertSame(
            [347, 350, 351, 362, 363, 365, 366, 370, 378, 379, 380, 381, 382, 383, 384, 385, 386, 387, 388],
            $added,
        );
        foreach ([368, 369, 371] as $sharedWith2026) {
            self::assertArrayHasKey($sharedWith2026, $old);
            self::assertSame(
                $old[$sharedWith2026]['remote_passability'],
                $new[$sharedWith2026]['remote_passability'],
                "Kontrola {$sharedWith2026}.",
            );
            $added[] = $sharedWith2026;
        }
        self::assertSame([109], $removed, 'Kontrola 109 se v katalogu 1.5 ruší.');
        // Všechny nové kontroly jsou nepropustné kromě 371 (propustná v cJMHZ).
        foreach ($added as $controlId) {
            self::assertSame(
                $controlId === 371 ? 'passable' : 'blocking',
                $new[$controlId]['remote_passability'],
                "Kontrola {$controlId}.",
            );
        }
        // Kontrola 290 je v 2027 nepropustná, v 2026 zůstává propustná.
        self::assertSame('blocking', $new[290]['remote_passability']);
        self::assertSame('passable', $old[290]['remote_passability']);
        // Katalog 1.5 vypustil sloupec se zdrojem kontroly a přidal Konzultace / Tbd.
        self::assertNull($new[1]['source_label']);
        self::assertArrayHasKey('consultation', $new[1]);
        self::assertArrayNotHasKey('consultation', $old[1]);
    }

    public function testScenarioCatalogCoversTheNewInteractions(): void
    {
        $manifest = $this->manifest('scenario-requirement-manifest.json');
        $payload = $manifest['payload'];

        self::assertSame(hash('sha256', CanonicalJson::encode($payload)), $manifest['manifest_sha256']);
        self::assertSame('jmhz-scenario-requirements-1.5.0.3-source-v1', $payload['catalog_key']);
        self::assertSame('1.5.0.3', $payload['version']);
        self::assertSame(self::PACKAGE_KEY, $payload['spec_package_key']);
        self::assertSame(self::MANIFEST_SHA256, $payload['spec_manifest_sha256']);
        self::assertSame(8, $payload['counts']['scenarios']);
        self::assertSame(43, $payload['counts']['interactions']);
        self::assertSame(54, $payload['counts']['matrices']);
        self::assertSame(1437, $payload['counts']['requirements']);
        self::assertSame(454, $payload['counts']['master_attributes']);
        self::assertSame(9, $payload['counts']['unspecified_requirements']);

        $interactions = array_column($payload['interactions'], 'interaction_key');
        foreach (['IN38', 'IN39', 'IN40', 'IN41', 'IN42', 'IN43', 'M12'] as $interaction) {
            self::assertContains($interaction, $interactions);
        }
        $matrices = array_column($payload['matrices'], null, 'matrix_key');
        foreach (['in38', 'in39', 'in40', 'in41', 'in42', 'in43'] as $matrix) {
            self::assertArrayHasKey($matrix, $matrices);
            self::assertGreaterThan(0, $matrices[$matrix]['row_count'], $matrix);
        }
        // Oficiální sešit nechal tři atributy (10255, 10407, 10258) bez povinnosti
        // ve dvou scénářích a v IN06; builder je zapíše jako anomálii, ne jako "N".
        $anomalies = array_column($payload['anomalies'], null, 'kind');
        self::assertSame(
            [
                '1 až 9 s příznakem!I135', '1 až 9 s příznakem!I136', '1 až 9 s příznakem!I137',
                'K,N,O,P,Q,R,S!I123', 'K,N,O,P,Q,R,S!I124', 'K,N,O,P,Q,R,S!I125',
                'IN06!H7', 'IN06!H8', 'IN06!H9',
            ],
            $anomalies['blank_requirement_cells']['source_cells'],
        );
    }

    public function testEdvDocumentationPackageSummarisesTheSeries15Changelog(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->edvDirectory() . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $payload = $manifest['payload'];

        self::assertSame(hash('sha256', CanonicalJson::encode($payload)), $manifest['manifest_sha256']);
        self::assertSame('jmhz-edv-1.5.0.3-source-v1', $payload['package_key']);
        self::assertSame(33, $payload['counts']['sheets']);
        self::assertSame(286, $payload['counts']['changelog_records']);
        // 37 + 15 + 8 + 11 záznamů verzí 1.5, 1.5.0.1, 1.5.0.2 a 1.5.0.3.
        self::assertSame(71, $payload['counts']['changelog_records_series_1_5']);
        self::assertSame(
            ['1.5' => 37, '1.5.0.1' => 15, '1.5.0.2' => 8, '1.5.0.3' => 11],
            array_intersect_key(
                $payload['changelog_records_by_version'],
                array_flip(['1.5', '1.5.0.1', '1.5.0.2', '1.5.0.3']),
            ),
        );
    }

    public function testPinnedProfileMatchesTheCheckedInManifests(): void
    {
        $profiles = require $this->root . '/tools/jmhz-spec-package-profiles.php';

        self::assertSame(self::MANIFEST_SHA256, $profiles['2027']['manifest_sha256']);
        self::assertSame(self::PACKAGE_KEY, $profiles['2027']['package_key']);
        self::assertSame('39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8', $profiles['2026']['manifest_sha256']);
        foreach (['dictionary_sha256', 'control_catalog_sha256', 'scenario_sha256'] as $key) {
            self::assertNotSame($profiles['2026'][$key], $profiles['2027'][$key]);
        }
    }

    public function testBuildersRegenerateThePinned2027ManifestsByteForByte(): void
    {
        $sources = $this->stageSources();
        $output = $this->temporary . '/out';
        $this->runBuilder('JmhzDictionaryPackageBuilder.php', [
            $sources . '/datovy_slovnik_1.5.0.3.xlsx',
            $sources . '/Katalog kontrol MH(public)_1.5.0.4.xlsx',
            $output,
            '--profile=2027',
        ]);
        $this->runBuilder('JmhzControlCatalogPackageBuilder.php', [
            $sources . '/Katalog kontrol MH(public)_1.5.0.4.xlsx',
            $output . '/control-manifest.json',
            '--profile=2027',
        ]);
        $this->runBuilder('JmhzScenarioRequirementPackageBuilder.php', [
            $sources . '/datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx',
            $output,
            '--profile=2027',
        ]);
        $this->runBuilder('JmhzEdvSourcePackageBuilder.php', [
            $this->edvDirectory() . '/Definice externí datové věty_1.5.0.3.xlsx',
            $output . '/edv',
        ]);

        foreach (['manifest.json', 'control-manifest.json', 'scenario-requirement-manifest.json'] as $file) {
            self::assertFileEquals($this->packageDirectory() . '/' . $file, $output . '/' . $file, $file);
        }
        self::assertFileEquals($this->edvDirectory() . '/manifest.json', $output . '/edv/manifest.json');
    }

    /** Builder 2026 po zavedení profilů vyrábí bajtově stejný balík jako dřív. */
    public function testBuilders2026StillRegenerateTheNowDeployedPackageByteForByte(): void
    {
        $resource = $this->root . '/api/resources/payroll/jmhz/dictionary-1.4.1.6';
        $output = $this->temporary . '/out26';
        $this->runBuilder('JmhzDictionaryPackageBuilder.php', [
            $resource . '/datovy_slovnik_1.4.1.6.xlsx',
            $resource . '/Katalog kontrol MH(public)_1.4.2.11.xlsx',
            $output,
        ]);
        $this->runBuilder('JmhzControlCatalogPackageBuilder.php', [
            $resource . '/Katalog kontrol MH(public)_1.4.2.11.xlsx',
            $output . '/control-manifest.json',
        ]);

        foreach (['manifest.json', 'control-manifest.json'] as $file) {
            self::assertFileEquals($resource . '/' . $file, $output . '/' . $file, $file);
        }
    }

    /** Změněný bajt zdroje builder odmítne dřív, než cokoli zapíše. */
    public function testBuildersRejectAChangedSourceByte(): void
    {
        $sources = $this->stageSources();
        foreach ([
            ['JmhzDictionaryPackageBuilder.php', 'datovy_slovnik_1.5.0.3.xlsx', 'SHA-256'],
            ['JmhzControlCatalogPackageBuilder.php', 'Katalog kontrol MH(public)_1.5.0.4.xlsx', 'SHA-256'],
            ['JmhzScenarioRequirementPackageBuilder.php', 'datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx', 'SHA-256'],
            ['JmhzEdvSourcePackageBuilder.php', '../edv/Definice externí datové věty_1.5.0.3.xlsx', 'SHA-256'],
        ] as [$builder, $file, $message]) {
            $source = $sources . '/' . $file;
            if (str_starts_with($file, '../edv/')) {
                $source = $this->temporary . '/tampered-edv.xlsx';
                self::assertTrue(copy($this->edvDirectory() . '/Definice externí datové věty_1.5.0.3.xlsx', $source));
            }
            $contents = (string) file_get_contents($source);
            $contents[100] = $contents[100] === 'A' ? 'B' : 'A';
            self::assertNotFalse(file_put_contents($source, $contents));
            $output = $this->temporary . '/tampered-' . bin2hex(random_bytes(4));

            $arguments = match ($builder) {
                'JmhzDictionaryPackageBuilder.php' => [$source, $sources . '/Katalog kontrol MH(public)_1.5.0.4.xlsx', $output, '--profile=2027'],
                'JmhzControlCatalogPackageBuilder.php' => [$source, $output . '.json', '--profile=2027'],
                'JmhzScenarioRequirementPackageBuilder.php' => [$source, $output, '--profile=2027'],
                default => [$source, $output],
            };
            [$exit, $stderr] = $this->runRaw($builder, $arguments);

            self::assertNotSame(0, $exit, "{$builder} musí odmítnout změněný zdroj.");
            self::assertStringContainsString($message, $stderr, $builder);
            self::assertDirectoryDoesNotExist($output);
            self::assertFileDoesNotExist($output . '.json');
        }
    }

    public function testBuilderRejectsAnUnknownProfile(): void
    {
        [$exit, $stderr] = $this->runRaw('JmhzDictionaryPackageBuilder.php', ['a.xlsx', 'b.xlsx', $this->temporary . '/x', '--profile=2099']);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Použití', $stderr);
    }

    private function packageDirectory(): string
    {
        return $this->root . '/api/resources/payroll/jmhz/dictionary-1.5.0.3';
    }

    private function edvDirectory(): string
    {
        return $this->root . '/api/resources/payroll/jmhz/edv-1.5.0.3';
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function manifest(string $file, string $dictionaryVersion = '1.5.0.3'): array
    {
        $path = $this->root . '/api/resources/payroll/jmhz/dictionary-' . $dictionaryVersion . '/' . $file;
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** Builder pojmenovává kopii podle zdroje, takže zdroje dostanou plné oficiální názvy. */
    private function stageSources(): string
    {
        $directory = $this->temporary . '/sources';
        self::assertTrue(mkdir($directory));
        foreach ([
            'datovy_slovnik_1.5.0.3.xlsx',
            'Katalog kontrol MH(public)_1.5.0.4.xlsx',
            'datove_scenare_interakce_povinnosti_MH_1.5.0.3.xlsx',
        ] as $file) {
            self::assertTrue(copy($this->packageDirectory() . '/' . $file, $directory . '/' . $file));
        }

        return $directory;
    }

    /** @param list<string> $arguments */
    private function runBuilder(string $builder, array $arguments): void
    {
        [$exit, $stderr, $stdout] = $this->runRaw($builder, $arguments);
        self::assertSame(0, $exit, "{$builder} selhal.\n{$stdout}\n{$stderr}");
    }

    /**
     * @param list<string> $arguments
     * @return array{int,string,string}
     */
    private function runRaw(string $builder, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $this->root . '/tools/' . $builder, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // CLI PHP píše neodchycené výjimky (display_errors) na stdout, ne na stderr.
        return [proc_close($process), $stderr . "\n" . $stdout, $stdout];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
