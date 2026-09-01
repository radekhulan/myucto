<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\PayrollBackupArchiveLayout;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Mzdové úložiště do zálohy patří — a musí být poznat, co v ní je.
 *
 * Před zavedením `cron-backup-payroll.php` nespadaly výplatní pásky, archivy
 * období ani soubory platebních příkazů do žádné zálohy: dump databáze bere
 * jen metadata, `cron-backup-documents` `storage/documents` a `cron-backup-pdf`
 * faktury. Po obnově by zbyly otisky bez obsahu, a to u dokumentů se zákonnou
 * archivační lhůtou.
 *
 * Běží nad vlastním datovým adresářem, aby se nesahalo do skutečného úložiště.
 */
#[Group('integration')]
final class PayrollBackupArchiveLayoutTest extends TestCase
{
    private Connection $db;
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payroll-backup-'
            . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dataDir, 0750, true));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $container = Bootstrap::buildContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
    }

    protected function tearDown(): void
    {
        if ($this->previousDataDir === false) {
            putenv('MYINVOICE_DATA_DIR');
        } else {
            putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        }
        $this->smazRekurzivne($this->dataDir);
        parent::tearDown();
    }

    public function testSbiraSouboryZeVsechMzdovychKorenuAVynechavaRozepsane(): void
    {
        $paska = str_repeat('a', 64);
        $archiv = str_repeat('b', 64);
        $prikaz = str_repeat('c', 64);
        $this->polozSoubor('payroll-documents/sup-1/aa/' . $paska, 'paska');
        $this->polozSoubor('payroll-period-exports/sup-1/bb/' . $archiv, 'archiv');
        $this->polozSoubor('payroll-payment-exports/sup-1/cc/' . $prikaz, 'prikaz');
        // Rozepsaný soubor nesedí na otisk; v záloze by vypadal jako porušený.
        $this->polozSoubor('payroll-documents/sup-1/aa/.tmp-' . $paska, 'rozepsane');

        $cesty = array_column(
            (new PayrollBackupArchiveLayout($this->db->pdo()))->all(),
            'entry',
        );

        self::assertContains('payroll-documents/sup-1/aa/' . $paska, $cesty);
        self::assertContains('payroll-period-exports/sup-1/bb/' . $archiv, $cesty);
        self::assertContains('payroll-payment-exports/sup-1/cc/' . $prikaz, $cesty);
        foreach ($cesty as $cesta) {
            self::assertStringNotContainsString('.tmp-', $cesta);
        }
    }

    public function testManifestNezamenujeZmrazenouKopiiZaOriginal(): void
    {
        // Týž otisk leží ve dvou kořenech: archiv období si zmrazí kopii pásky.
        // Párování jen podle otisku by o kopii tvrdilo, že je to originál.
        $otisk = str_repeat('d', 64);
        $this->polozSoubor('payroll-documents/sup-1/dd/' . $otisk, 'paska');
        $this->polozSoubor('payroll-period-exports/sup-1/dd/' . $otisk, 'kopie');
        $sirotek = str_repeat('e', 64);
        $this->polozSoubor('payroll-documents/sup-1/ee/' . $sirotek, 'sirotek');

        $manifest = (new PayrollBackupArchiveLayout($this->db->pdo()))->manifestCsv();
        $radky = explode("\n", $manifest);
        self::assertSame('cesta;druh;popis;velikost_b;vytvoreno', $radky[0]);

        $druh = [];
        foreach (array_slice($radky, 1) as $radek) {
            if ($radek === '') {
                continue;
            }
            $sloupce = explode(';', $radek);
            $druh[$sloupce[0]] = $sloupce[1];
        }

        // Soubor bez záznamu v databázi se nevynechává — je to nález, ne šum.
        self::assertSame('neznamy', $druh['payroll-documents/sup-1/ee/' . $sirotek] ?? null);
        self::assertArrayHasKey('payroll-documents/sup-1/dd/' . $otisk, $druh);
        self::assertArrayHasKey('payroll-period-exports/sup-1/dd/' . $otisk, $druh);
        self::assertNotSame(
            'dokument',
            $druh['payroll-period-exports/sup-1/dd/' . $otisk],
            'Zmrazená kopie v archivu se nesmí vydávat za originální dokument.',
        );
    }

    private function polozSoubor(string $relativni, string $obsah): void
    {
        $cesta = RuntimePaths::storage($relativni);
        $adresar = dirname($cesta);
        if (!is_dir($adresar)) {
            self::assertTrue(mkdir($adresar, 0750, true));
        }
        self::assertNotFalse(file_put_contents($cesta, $obsah));
    }

    private function smazRekurzivne(string $cesta): void
    {
        if (!is_dir($cesta)) {
            @unlink($cesta);
            return;
        }
        foreach (scandir($cesta) ?: [] as $polozka) {
            if ($polozka === '.' || $polozka === '..') {
                continue;
            }
            $this->smazRekurzivne($cesta . '/' . $polozka);
        }
        @rmdir($cesta);
    }
}
