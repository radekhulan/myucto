<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\StorageUsageMeter;
use PHPUnit\Framework\TestCase;

/**
 * H-10 — měření spotřeby místa.
 *
 * Hlídá se hlavně jedna věc: **do kvóty se nesmí započítat adresář záloh.**
 * Hosting ho z kvóty taky vyjímá, a instalace, která se zamkne vlastními
 * zálohami, je nejtrapnější možná varianta selhání — čím déle běží, tím dřív
 * se zastaví, a odemknout to zvenčí nejde.
 */
final class StorageUsageMeterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myucto-storage-meter-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/invoices', 0o777, true);
        mkdir($this->root . '/storage/backup', 0o777, true);
        mkdir($this->root . '/storage/backup/monthly', 0o777, true);
        mkdir($this->root . '/log', 0o777, true);

        file_put_contents($this->root . '/storage/invoices/2026-0001.pdf', str_repeat('a', 1000));
        file_put_contents($this->root . '/log/app.log', str_repeat('b', 500));
        // Zálohy: řádově víc než živá data — přesně ten případ, kdy by je
        // započítání do kvóty samo o sobě přehouplo přes práh.
        file_put_contents($this->root . '/storage/backup/dump-2026-08-20.zip', str_repeat('c', 20000));
        file_put_contents($this->root . '/storage/backup/monthly/dump-2026-08-01.zip', str_repeat('d', 30000));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /** ⚠️ Živá data = všechno KROMĚ záloh. */
    public function testBackupDirectoryIsNotCountedIntoUsage(): void
    {
        $result = StorageUsageMeter::walk($this->root, [$this->root . '/storage/backup']);

        self::assertSame(1500, $result['bytes'], 'Do kvóty patří jen faktura (1000 B) a log (500 B).');
        self::assertSame(2, $result['count']);
        self::assertFalse($result['truncated']);
    }

    /** Bez vyloučení by zálohy kvótu nafoukly 34×. Kontrola předpokladu testu výš. */
    public function testWithoutExclusionBackupsWouldDominateTheMeasurement(): void
    {
        $withBackups = StorageUsageMeter::walk($this->root, []);

        self::assertSame(51500, $withBackups['bytes']);
        self::assertGreaterThan(
            10 * 1500,
            $withBackups['bytes'],
            'Kdyby se zálohy počítaly, instalace by se zamkla sama sebou.',
        );
    }

    /** Vynechává se celý PODSTROM, ne jen první patro adresáře záloh. */
    public function testExclusionPrunesTheWholeSubtree(): void
    {
        $result = StorageUsageMeter::walk($this->root, [$this->root . '/storage/backup']);

        self::assertSame(1500, $result['bytes'], 'Ani vnořená měsíční záloha se počítat nesmí.');
    }

    /**
     * Casing se musí porovnávat necitlivě — `realpath()` na Windows vrací
     * nekonzistentní velikost písmen a jinak by se zálohy do kvóty započetly.
     */
    public function testExclusionIsCaseInsensitive(): void
    {
        $result = StorageUsageMeter::walk($this->root, [strtoupper($this->root . '/STORAGE/BACKUP')]);

        self::assertSame(1500, $result['bytes']);
    }

    /** Nečitelný/neexistující kořen = NEZMĚŘENO (null), nikdy nula. */
    public function testMissingRootIsUnmeasuredNotZero(): void
    {
        $result = StorageUsageMeter::walk($this->root . '/neexistuje', []);

        self::assertNull($result['bytes'], 'Neexistující kořen není prázdná instance.');
        self::assertNull($result['count']);
    }

    /**
     * Adresáře záloh se berou z OBOU konfiguračních klíčů. Instalace, která má
     * vyplněný jen jeden z nich, by při výčtu jediného klíče měla zálohy
     * započítané do kvóty.
     */
    public function testBothBackupConfigKeysAreExcluded(): void
    {
        $config = new Config([
            'cron'    => ['backup' => ['output_dir' => $this->root . '/storage/backup']],
            'storage' => ['backup_dir' => $this->root . '/jine-zalohy'],
        ]);
        $meter = new StorageUsageMeter(new Connection($config), $config);

        $excluded = $meter->excludedDirectories();

        self::assertContains(self::normalized($this->root . '/storage/backup'), $excluded);
        self::assertContains(self::normalized($this->root . '/jine-zalohy'), $excluded);
    }

    /** Vlastní výjimky z konfigurace se přidávají k povinným (zálohy zůstanou). */
    public function testExtraExcludesDoNotReplaceBackupDirectories(): void
    {
        $config = new Config([
            'cron'          => ['backup' => ['output_dir' => $this->root . '/storage/backup']],
            'storage_quota' => ['exclude_dirs' => [$this->root . '/log']],
        ]);
        $meter = new StorageUsageMeter(new Connection($config), $config);

        $excluded = $meter->excludedDirectories();

        self::assertContains(self::normalized($this->root . '/storage/backup'), $excluded);
        self::assertContains(self::normalized($this->root . '/log'), $excluded);
    }

    /** Velikost záloh se sice měří, ale drží se ZVLÁŠŤ — do kvóty nevstupuje. */
    public function testBackupSizeIsReportedSeparately(): void
    {
        $config = new Config([
            'cron' => ['backup' => ['output_dir' => $this->root . '/storage/backup']],
        ]);
        $meter = new StorageUsageMeter(new Connection($config), $config);

        self::assertSame(50000, $meter->measureBackups());
    }

    /**
     * Výchozí konfigurace míří oběma klíči do téhož adresáře. Zálohy se pak
     * nesmí sečíst dvakrát, ani když je cesta napsaná jinak.
     */
    public function testSameBackupDirectoryUnderBothKeysIsCountedOnce(): void
    {
        $config = new Config([
            'cron'    => ['backup' => ['output_dir' => $this->root . '/storage/backup']],
            'storage' => ['backup_dir' => $this->root . '/storage/backup/'],
        ]);
        $meter = new StorageUsageMeter(new Connection($config), $config);

        self::assertCount(1, $meter->backupDirectories());
        self::assertSame(50000, $meter->measureBackups());
    }

    /** Dva různé adresáře záloh se sčítají dál. */
    public function testDifferentBackupDirectoriesAreSummed(): void
    {
        mkdir($this->root . '/jine-zalohy', 0777, true);
        file_put_contents($this->root . '/jine-zalohy/dump.sql', str_repeat('x', 700));
        $config = new Config([
            'cron'    => ['backup' => ['output_dir' => $this->root . '/storage/backup']],
            'storage' => ['backup_dir' => $this->root . '/jine-zalohy'],
        ]);
        $meter = new StorageUsageMeter(new Connection($config), $config);

        self::assertSame(50700, $meter->measureBackups());
    }

    private static function normalized(string $path): string
    {
        $real = realpath($path);

        return strtolower(rtrim(str_replace('\\', '/', is_string($real) && $real !== '' ? $real : $path), '/'));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
