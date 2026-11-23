<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use MyInvoice\Tests\Support\EntryFormMigrationTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Převod z PREMIER v instalaci s výchozím jednoduchým zápisem: nová firma dostane formu
 * single před prvním zápisem deníku, deník PREMIER (řádek = účet MD, účet Dal, částka)
 * i počáteční stavy (účet proti 701) přijdou po dvojicích přímo ze zdroje. Dva roky po
 * sobě (s uzávěrkou prvního): obraty po účtech, řádky převzatého deníku a rekonciliace
 * jsou stejné jako ve složené formě.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PremierEntryFormImportTest extends TestCase
{
    use EntryFormMigrationTrait;

    private const MAP_KEYS = "SELECT premier_key AS entry_key, target_id AS entry_id FROM premier_import_map WHERE supplier_id = ? AND kind = 'journal_entry'";

    private ?Connection $db = null;
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_form_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testSingleInstallationImportsPremierJournalAsSourcePairsWithSameTurnover(): void
    {
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup';
        PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . DIRECTORY_SEPARATOR . 'zaloha.icab', $this->tmp, false, []), $dir);
        $this->assertSingleMatchesComposite(function (ContainerInterface $c, PDO $pdo) use ($dir): array {
            $supplierId = $this->migrationFirm($pdo, SyntheticPremierBackup::NAME, SyntheticPremierBackup::ICO, true);
            $pdo->prepare("UPDATE supplier SET vat_period = 'monthly' WHERE id = ?")->execute([$supplierId]);
            $criteria = [];
            foreach ([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR2] as $year) {
                $protocol = $c->get(PremierImporter::class)->run($supplierId, $this->firstUserId($pdo), PremierBackup::open($dir), $year, false);
                self::assertFalse($protocol->hasErrors(), (string) json_encode($protocol->toArray()['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $criteria[$year] = ReconciliationCriteria::summarize((array) $protocol->get('reconciliation'));
                self::assertTrue($criteria[$year]['total'][ReconciliationCriteria::K1] ?? false);
            }
            return [
                'fingerprint' => $this->journalFingerprint($pdo, $supplierId),
                'lines' => $this->journalLinesByKey($pdo, $supplierId, self::MAP_KEYS),
                'compare' => $criteria,
            ];
        });
    }
}
