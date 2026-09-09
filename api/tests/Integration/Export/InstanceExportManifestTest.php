<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * H-14 — manifest a kontrolní součty.
 *
 * Bez manifestu se po roce nikdo nedopočítá, jestli je archiv úplný, a bez součtů
 * nejde ověřit, že se stáhl celý. Proto se tu kontroluje obojí PROTI SKUTEČNOSTI:
 * počty řádků v manifestu proti `COUNT(*)` v databázi a součty proti obsahu archivu,
 * ne jen samo se sebou.
 */
#[Group('integration')]
final class InstanceExportManifestTest extends TestCase
{
    private static ?Connection $db = null;
    private static ?InstanceExportService $export = null;

    private static int $supplierId = 0;
    private static bool $inTx = false;

    /** @var list<string> */
    private static array $tempPaths = [];
    private static array $archives = [];
    private static ?string $exportDirectory = null;
    private static ?string $lockPath = null;

    protected function setUp(): void
    {
        if (self::$db !== null) {
            return;
        }
        try {
            self::initializeFixture();
        } catch (\Throwable $e) {
            self::cleanupFixture();
            throw $e;
        }
    }

    private static function initializeFixture(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            [self::$db, self::$export] = Connection::withoutSharedTestConnection(static function (): array {
                $container = Bootstrap::buildApp()->getContainer();
                return [$container->get(Connection::class), $container->get(InstanceExportService::class)];
            });
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = self::$db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            self::markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        self::$inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovaci 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['H14 manifest s.r.o.', $czId, 'h14-manifest@example.com', $currencyId, $vatRateId]);
        self::$supplierId = (int) $pdo->lastInsertId();
        self::$exportDirectory = RuntimePaths::storage('instance-exports') . DIRECTORY_SEPARATOR . 'sup-' . self::$supplierId;
        self::$lockPath = RuntimePaths::storage('locks') . '/instance-export-sup' . self::$supplierId . '.lock';

        for ($i = 1; $i <= 3; $i++) {
            $c = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, main_email)
                 VALUES (?, ?, "Testovaci 2", "Brno", "60200", ?, ?, ?)'
            );
            $c->execute([
                self::$supplierId,
                'H14 odberatel ' . $i,
                $czId,
                $currencyId,
                "h14-odberatel-{$i}@example.com",
            ]);
        }

        // Víc agend, ať manifest popisuje víc než dvě tabulky — jinak by
        // „checksumy sedí" znamenalo jen to, že archiv skoro nic neobsahuje.
        foreach (['document_folders', 'document_tags', 'cash_registers', 'journal_entry_templates'] as $table) {
            $pdo->prepare('INSERT INTO ' . $table . ' (supplier_id, name) VALUES (?, ?)')
                ->execute([self::$supplierId, 'H14 manifest ' . $table]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupFixture();
    }

    private static function cleanupFixture(): void
    {
        foreach (self::$tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (self::$exportDirectory !== null) {
            $dir = self::$exportDirectory;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    is_dir($file) ? @rmdir($file) : @unlink($file);
                }
                @rmdir($dir);
            }
            if (self::$lockPath !== null) {
                @unlink(self::$lockPath);
            }
        }
        try {
            if (isset(self::$db) && self::$inTx) {
                $pdo = self::$db->pdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } finally {
            self::$db?->close();
            self::$db = null;
            self::$export = null;
            self::$supplierId = 0;
            self::$inTx = false;
            self::$tempPaths = [];
            self::$archives = [];
            self::$exportDirectory = null;
            self::$lockPath = null;
        }
    }

    public function testManifestDescribesArchiveAndCountsMatchDatabase(): void
    {
        $result = $this->exportData();
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertIsArray($manifest, 'manifest.json je parsovatelný JSON.');
        self::assertSame('myucto-instance-export', $manifest['format']);
        self::assertSame(self::$supplierId, (int) $manifest['supplier']['id'], 'Manifest jmenuje exportovanou firmu.');
        self::assertNotSame('unknown', (string) $manifest['schema_version'], 'Manifest nese verzi schématu.');
        self::assertArrayHasKey('read_started_at', $manifest, 'Manifest nese okno, ve kterém se data četla.');
        self::assertArrayHasKey('read_finished_at', $manifest);
        self::assertSame('non-atomic', $manifest['data_snapshot'], 'Manifest přiznává, že snapshot není atomický.');

        // Počty v manifestu vs. COUNT(*) v DB — ne manifest proti sobě samému.
        $clientsInDb = (int) self::$db->pdo()->query(
            'SELECT COUNT(*) FROM clients WHERE supplier_id = ' . self::$supplierId
        )->fetchColumn();
        self::assertSame(
            $clientsInDb,
            (int) $manifest['sections']['data']['tables']['clients']['rows'],
            'Počet klientů v manifestu sedí s databází.',
        );
        self::assertSame(1, (int) $manifest['sections']['data']['tables']['supplier']['rows'], 'Master řádek firmy je právě jeden.');

        // Vynechané tabulky jsou v manifestu vidět i s důvodem.
        self::assertArrayHasKey('skipped_tables', $manifest['sections']['data']);
        self::assertArrayHasKey('users', $manifest['sections']['data']['skipped_tables']);

        $zip->close();
    }

    public function testEveryEntryChecksumMatchesItsContent(): void
    {
        $result = $this->exportData();
        $manifestChecksums = (array) ($result['manifest']['checksums'] ?? []);
        self::assertNotSame([], $manifestChecksums, 'Manifest nese kontrolní součty položek.');

        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        $verified = 0;
        foreach ($manifestChecksums as $entryName => $meta) {
            $content = $zip->getFromName((string) $entryName);
            self::assertNotFalse($content, "Položka {$entryName} v archivu je.");
            self::assertSame(
                $meta['sha256'],
                hash('sha256', $content),
                "SHA-256 položky {$entryName} nesedí s manifestem.",
            );
            self::assertSame((int) $meta['size'], strlen($content), "Velikost {$entryName} nesedí s manifestem.");
            $verified++;
        }
        self::assertGreaterThan(3, $verified, 'Ověřilo se víc než pár položek.');

        // Řádky JSONL musí odpovídat počtu z manifestu — součet sám o sobě neřekne,
        // že se neztratil celý blok dat.
        foreach ((array) $result['manifest']['sections']['data']['tables'] as $table => $info) {
            if (($info['entry'] ?? null) === null) {
                continue;
            }
            $content = (string) $zip->getFromName((string) $info['entry']);
            $lines = array_values(array_filter(explode("\n", $content), static fn (string $l): bool => trim($l) !== ''));
            self::assertCount((int) $info['rows'], $lines, "Počet řádků {$table} sedí s manifestem.");
        }
        $zip->close();
    }

    /** Součet celého archivu je vedle něj i v návratové hodnotě — podle něj se pozná useknuté stažení. */
    public function testWholeArchiveChecksumIsRecordedNextToIt(): void
    {
        $result = $this->exportData();
        $absPath = (string) $result['abs_path'];

        self::assertSame(hash_file('sha256', $absPath), $result['sha256'], 'Vrácený součet sedí se souborem.');
        self::assertFileExists($absPath . '.sha256', 'Vedle archivu leží sidecar se součtem.');
        self::assertStringContainsString(
            (string) $result['sha256'],
            (string) file_get_contents($absPath . '.sha256'),
            'Sidecar obsahuje součet archivu.',
        );
    }

    /** Archiv musí být čitelný bez naší aplikace — návod a strojový popis jsou uvnitř. */
    public function testArchiveIsSelfDescribing(): void
    {
        $result = $this->exportData();
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        foreach (['manifest.json', 'CHECKSUMS.txt', 'CTI-MNE.txt'] as $entry) {
            self::assertNotFalse($zip->getFromName($entry), "Archiv obsahuje {$entry}.");
        }
        $readme = (string) $zip->getFromName('CTI-MNE.txt');
        self::assertStringContainsString('JSON Lines', $readme, 'Návod říká, v jakém formátu data jsou.');
        self::assertStringContainsString('sha256sum', $readme, 'Návod říká, jak ověřit úplnost.');

        $checksums = (string) $zip->getFromName('CHECKSUMS.txt');
        self::assertStringContainsString('data/clients.jsonl', $checksums, 'CHECKSUMS.txt vyjmenovává položky.');
        $zip->close();
    }

    /** Obnovitelný export je přímo tento ZIP, bez druhého vnořeného archivu. */
    public function testRestorePartMakesTheCompleteExportDirectlyRestorable(): void
    {
        $result = $this->exportPart(InstanceExportService::PART_RESTORE);

        $archive = new ZipArchive();
        self::assertTrue($archive->open((string) $result['abs_path']) === true);
        $manifest = json_decode((string) $archive->getFromName('manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame('myucto-instance-export', $manifest['format']);
        self::assertSame(6, $manifest['version']);
        self::assertArrayHasKey('supplier', $manifest);
        self::assertFalse($archive->locateName('obnova/myucto-archiv-pro-obnovu.zip') !== false, 'Nevzniká druhý vložený ZIP.');
        $archive->close();

        self::assertTrue((bool) ($result['manifest']['restore']['available'] ?? false));
        self::assertSame('myucto-instance-export', $result['manifest']['restore']['format'] ?? null);
        self::assertArrayHasKey('documents', $result['manifest']['restore'], 'Manifest nese mapu PDF dokladů pro volitelnou obnovu.');
        self::assertArrayHasKey('identity', $result['manifest']['sections']['data']);
    }

    // ── CLI obnovy: exit kódy 0 / 1 / 2 ──────────────────────────────────────

    /**
     * `api/bin/archive-restore.php` je jediná cesta, jak archiv obnovit, takže
     * jeho návratové kódy jsou kontrakt — podle nich se pozná rozdíl mezi
     * „archiv je v pořádku", „archiv je poškozený" a „spustil jsi to špatně".
     *
     * ⚠️ `--database` je povinné i pro dry-run: validace se neptá jen na
     * kontrolní součty uvnitř archivu, ale i na to, jestli CÍLOVÉ schéma archiv
     * unese ({@see \MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService})
     * — a na to potřebuje `information_schema` nad konkrétní databází.
     */
    public function testRestoreCliReportsValidCorruptAndMisuseApart(): void
    {
        // ⚠️ PART_RESTORE, ne PART_DATA: samotný datový export obnovitelný
        // NENÍ a skript ho odmítne (`restore_incomplete`). Kontrolovat exit
        // kódy nad archivem, který se stejně obnovit nedá, by neověřilo nic.
        $result = $this->exportPart(InstanceExportService::PART_RESTORE);
        $archive = (string) $result['abs_path'];

        $script = dirname(__DIR__, 3) . '/bin/archive-restore.php';
        self::assertFileExists($script);

        $database = (string) self::$db->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertNotSame('', $database, 'Test potřebuje znát jméno testovací databáze.');

        // Platný archiv → 0
        [$code, $output] = $this->runCli([$script, '--file=' . $archive, '--database=' . $database, '--dry-run']);
        self::assertSame(0, $code, "Dry-run platného archivu → exit 0. Výstup:\n" . $output);
        self::assertStringContainsString('Archiv je validní', $output);

        // Poškozený obsah (sha256 nesedí) → 1
        $corrupt = $archive . '.corrupt.zip';
        copy($archive, $corrupt);
        self::$tempPaths[] = $corrupt;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($corrupt) === true);
        $zip->addFromString('manifest.json', '{"format":"myucto-instance-export","poskozeno":true}');
        $zip->close();
        [$code, $output] = $this->runCli([$script, '--file=' . $corrupt, '--database=' . $database, '--dry-run']);
        self::assertSame(1, $code, "Poškozený archiv → exit 1. Výstup:\n" . $output);

        // Bez režimu → 2. Obnova se nesmí rozjet jinak než s explicitním --restore.
        [$code, $output] = $this->runCli([$script, '--file=' . $archive, '--database=' . $database]);
        self::assertSame(2, $code, "Bez režimu → exit 2 (usage). Výstup:\n" . $output);
        self::assertStringContainsString('--dry-run|--restore', $output);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function exportData(): array
    {
        return $this->exportPart(InstanceExportService::PART_DATA);
    }

    private function exportPart(string $part): array
    {
        if (isset(self::$archives[$part])) {
            return self::$archives[$part];
        }
        $result = self::$export->runForSupplier(self::$supplierId, [$part]);
        $source = (string) $result['abs_path'];
        $immutable = $source . '.' . $part . '.fixture.zip';
        self::$tempPaths[] = $source;
        self::$tempPaths[] = $source . '.sha256';
        self::$tempPaths[] = $immutable;
        self::$tempPaths[] = $immutable . '.sha256';
        if (!copy($source, $immutable) || !copy($source . '.sha256', $immutable . '.sha256')) {
            throw new \RuntimeException('Nepodařilo se připravit neměnný testovací archiv.');
        }
        $result['abs_path'] = $immutable;
        return self::$archives[$part] = $result;
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string} [exit code, výstup]
     */
    private function runCli(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $outputLines = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $outputLines, $exitCode);

        return [$exitCode, implode("\n", $outputLines)];
    }
}
