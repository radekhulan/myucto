<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Repository\ClosingCarryReadRepository;
use MyInvoice\Repository\JournalLineProofColumns;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Otisky zdroje (uzávěrkové kroky 504, převod rámce, přecenění, sdružené rozúčtování)
 * hashují celý řádek deníku a ukládají se k pozdějšímu porovnání. Migrace jednoduchého
 * zápisu přidala k řádku `pair_no` a `line_text`; se `SELECT l.*` by uložené otisky po
 * aktualizaci hlásily „zdroj se změnil", i když se v účetních datech nic nestalo.
 *
 * Test ověřuje, že otisk čtenáře je stejný jako otisk řádku ve tvaru před migrací
 * a že ho nové sloupce neovlivní ani svou hodnotou.
 */
#[Group('integration')]
final class JournalLineProofHashTest extends TestCase
{
    use PairConversionFixture;

    /** Čtenáři, kteří otiskují celé řádky deníku; žádný z nich nesmí číst `l.*`. */
    private const PROOF_READERS = [
        'Repository/ClosingCarryReadRepository.php',
        'Repository/Fx504ReadRepository.php',
        'Repository/SecuritiesValuation504ReadRepository.php',
        'Repository/FrameworkConversionRepository.php',
        'Service/Accounting/Allocation/JointAllocationPreviewService.php',
    ];

    protected function setUp(): void
    {
        $this->setUpFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testProofColumnsAreSchemaColumnsBeforeSingleEntry(): void
    {
        $columns = $this->db->pdo()->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entry_lines'
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('pair_no', $columns);
        self::assertContains('line_text', $columns);
        self::assertSame(
            JournalLineProofColumns::COLUMNS,
            array_values(array_diff($columns, ['pair_no', 'line_text'])),
            'Seznam musí přesně odpovídat dřívějšímu l.* (pořadí klíčů je součástí otisku).',
        );
    }

    public function testClosingCarrySourceHashIgnoresPairColumns(): void
    {
        $entryId = $this->invoiceOneToMany('2098-03-01');
        $reader = new ClosingCarryReadRepository($this->db, false);

        $rows = $reader->entryLines($this->supplierId, $entryId);
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('pair_no', $row);
            self::assertArrayNotHasKey('line_text', $row);
        }

        // Otisk ve tvaru před migrací: tytéž řádky bez nových klíčů.
        $legacy = array_map(static function (array $row): array {
            unset($row['pair_no'], $row['line_text']);
            return $row;
        }, $rows);
        $before = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR));
        self::assertSame($before, hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)));

        // Ani hodnota nových sloupců otisk nezmění (text dvojice je zobrazení, ne účetní fakt).
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET line_text = 'Text dvojice' WHERE supplier_id = ? AND entry_id = ?")
            ->execute([$this->supplierId, $entryId]);
        $after = $reader->entryLines($this->supplierId, $entryId);
        self::assertSame($before, hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)));
    }

    public function testProofReadersSelectExplicitLineColumns(): void
    {
        $root = dirname(__DIR__, 4) . '/src/';
        foreach (self::PROOF_READERS as $file) {
            $source = (string) file_get_contents($root . $file);
            self::assertSame(0, preg_match('/\bl\.\*/', $source), $file . ' čte řádky deníku přes l.*');
            self::assertTrue(str_contains($source, 'JournalLineProofColumns::select('), $file);
        }
        $closure = (string) file_get_contents($root . 'Repository/Property/Receivables/PropertySaldoReadClosure.php');
        self::assertTrue(str_contains($closure, 'JournalLineProofColumns::select('), 'PropertySaldoReadClosure');
    }
}
