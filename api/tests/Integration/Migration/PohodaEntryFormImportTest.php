<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Support\EntryFormMigrationTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Převod z POHODY v instalaci s výchozím jednoduchým zápisem: nová firma dostane formu
 * single před prvním zápisem deníku a deník POHODY (řádek = účet MD, účet Dal, částka)
 * přijde po dvojicích přímo ze zdroje; zápisy, které převod účtuje sám (úhrady pohybů,
 * které POHODA nezaúčtovala, uzávěrka), jdou po dvojicích zaúčtováním MyÚčta. Obraty
 * po účtech, řádky převzatého deníku a rekonciliace jsou stejné jako ve složené formě.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PohodaEntryFormImportTest extends TestCase
{
    use EntryFormMigrationTrait;

    private const MAP_KEYS = "SELECT pohoda_key AS entry_key, target_id AS entry_id FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'journal_entry'";

    private ?Connection $db = null;
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_form_' . bin2hex(random_bytes(5));
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

    /** @return iterable<string,array{bool}> */
    public static function exports(): iterable
    {
        yield 'základní export' => [false];
        yield 'majetek a nezaúčtované úhrady' => [true];
    }

    #[DataProvider('exports')]
    public function testSingleInstallationImportsPohodaJournalAsSourcePairsWithSameTurnover(bool $extended): void
    {
        $dir = $extended
            ? SyntheticPohodaExport::write($this->tmp . '/x', withAssets: true, unbooked: true)
            : SyntheticPohodaExport::write($this->tmp . '/x');
        $this->assertSingleMatchesComposite(function (ContainerInterface $c, PDO $pdo) use ($dir): array {
            $supplierId = $this->migrationFirm($pdo, SyntheticPohodaExport::NAME, SyntheticPohodaExport::ICO, true);
            $protocol = $c->get(PohodaImporter::class)->run($supplierId, $this->firstUserId($pdo), PohodaExport::open($dir), false);
            self::assertFalse($protocol->hasErrors(), (string) json_encode($protocol->toArray()['steps'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $criteria = ReconciliationCriteria::summarize((array) $protocol->get('reconciliation'));
            self::assertTrue($criteria['total'][ReconciliationCriteria::K1] ?? false);
            return [
                'fingerprint' => $this->journalFingerprint($pdo, $supplierId),
                'lines' => $this->journalLinesByKey($pdo, $supplierId, self::MAP_KEYS),
                'compare' => $criteria,
            ];
        });
    }
}
