<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountCodeRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Meze AccountCodeRange::sqlBounds() proti skutečné kolaci sloupce account_code
 * (utf8mb4_unicode_ci): prefixový výběr včetně krajů i pro kódy z importů.
 */
#[Group('integration')]
final class AccountCodeRangeCollationTest extends TestCase
{
    private const CODES = ['013', '221.KB', '321', '501', '501.100', '518', '518-1', '518.100', '518/1', '518200', '518_1', '599.999', '602'];

    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        $collation = (string) $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chart_of_accounts' AND COLUMN_NAME = 'account_code'")->fetchColumn();
        $pdo->exec("CREATE TEMPORARY TABLE account_code_range_probe (code VARCHAR(10) COLLATE {$collation} NOT NULL)");
        $insert = $pdo->prepare('INSERT INTO account_code_range_probe VALUES (?)');
        foreach (self::CODES as $code) $insert->execute([$code]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) $this->db->pdo()->exec('DROP TEMPORARY TABLE IF EXISTS account_code_range_probe');
    }

    /** @return iterable<string,array{string,string,list<string>}> */
    public static function ranges(): iterable
    {
        yield 'třída' => ['5', '5', ['501', '501.100', '518', '518-1', '518.100', '518/1', '518200', '518_1', '599.999']];
        yield 'rozsah včetně analytik horní meze' => ['501', '518', ['501', '501.100', '518', '518-1', '518.100', '518/1', '518200', '518_1']];
        yield 'jedna analytika' => ['501.100', '501.100', ['501.100']];
        yield 'kód s pomlčkou' => ['518-1', '518-1', ['518-1']];
        yield 'kód s podtržítkem' => ['518_1', '518_1', ['518_1']];
        yield 'třída 0' => ['0', '0', ['013']];
        yield 'jen dolní mez' => ['6', '', ['602']];
        yield 'jen horní mez' => ['', '3', ['013', '221.KB', '321']];
        yield 'malá písmena v mezi' => ['221.kb', '221.kb', ['221.KB']];
    }

    /** @param list<string> $expected */
    #[DataProvider('ranges')]
    public function testSqlBoundsSelectPrefixInclusiveRange(string $from, string $to, array $expected): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM account_code_range_probe WHERE code BETWEEN ? AND ?');
        $stmt->execute(AccountCodeRange::parse($from, $to)->sqlBounds());
        $selected = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        sort($selected);
        $expectedSorted = $expected;
        sort($expectedSorted);
        self::assertSame($expectedSorted, $selected);
    }
}
