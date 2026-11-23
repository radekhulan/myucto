<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\LedgerReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plán dotazů opisu účtu a filtru protiúčtu po dvojicích nad syntetickým deníkem
 * s milionem řádků (AGENTS.md: SQL nad miliony řádků): žádný průchod celou tabulkou,
 * partner dvojice po indexu idx_jel_supplier_entry_pair, odhad řádků daleko pod
 * velikostí deníku.
 *
 * Plán má smysl jen nad aktuální statistikou (ANALYZE), kterou v transakci testu
 * získat nejde. Deník, zápisy a osnova se proto v session zastíní dočasnými tabulkami
 * se stejnými indexy (bez cizích klíčů a verzování); skutečné tabulky zůstanou netknuté
 * a dočasné zmizí v tearDown.
 */
#[Group('integration')]
final class CounterAccountExplainTest extends TestCase
{
    /** Syntetických zápisů po třech dvojicích: 170 000 × 6 ≈ 1 M řádků. */
    private const ENTRIES = 170000;

    private const SHADOWED = ['journal_entries', 'journal_entry_lines', 'chart_of_accounts'];

    /** Hlavní účty a protiúčty syntetických dvojic (opis jednoho účtu je zlomek deníku). */
    private const MAIN = ['221', '211', '311', '321', '331', '336', '342', '343.100', '343.200', '378'];
    private const COUNTER = ['501', '511', '512', '513', '518', '521', '538', '548', '549', '602'];

    /** Poplatek třetí dvojice každého zápisu (mimo protiúčty, aby filtr vybral jen jeho dvojice). */
    private const FEE = '568';

    private Connection $db;
    private LedgerReportRepository $ledger;
    private bool $shadowed = false;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->ledger = $container->get(LedgerReportRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $this->markTestSkipped('Spojení má rozdělanou transakci, ANALYZE by ji potvrdil.');
        }
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí firma v DB.');
        }
        foreach (self::SHADOWED as $table) {
            $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1];
            $lines = array_filter(explode("\n", $create), static fn (string $l): bool => !str_contains($l, 'FOREIGN KEY'));
            $create = (string) preg_replace('/,\s*\n\)/', "\n)", implode("\n", $lines));
            $create = str_replace(['WITH SYSTEM VERSIONING', 'CREATE TABLE'], ['', 'CREATE TEMPORARY TABLE'], $create);
            $pdo->exec($create);
            $this->shadowed = true;
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->shadowed) {
            foreach (self::SHADOWED as $table) {
                $this->db->pdo()->exec('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
            }
            $this->db->close();
        }
    }

    public function testStatementAndCounterFilterUseIndexes(): void
    {
        $accounts = $this->seedChart();
        $total = $this->seedJournal($accounts);
        self::assertGreaterThanOrEqual(1000000, $total);
        foreach (self::SHADOWED as $table) {
            $this->db->pdo()->query('ANALYZE TABLE `' . $table . '`')->fetchAll();
        }

        $account = $accounts[$this->supplierId]['221'];
        $counter = $accounts[$this->supplierId]['568'];
        $from = '2099-01-01';
        $to = '2099-12-31';
        $queries = [
            'opis s filtrem'         => $this->ledger->accountLinesQuery($this->supplierId, $account, $from, $to, 50, 0, true, $counter, true),
            'opis bez filtru'        => $this->ledger->accountLinesQuery($this->supplierId, $account, $from, $to, 50, 0, true, null, true),
            'počet s filtrem'        => $this->ledger->accountLinesTotalQuery($this->supplierId, $account, $from, $to, true, $counter, true),
            'součty podle protiúčtu' => $this->ledger->accountCounterTotalsQuery($this->supplierId, $account, $from, $to, true, true),
        ];
        $report = [];
        foreach ($queries as $name => [$sql, $params]) {
            $report[$name] = $this->explain($sql, $params);
        }
        if (getenv('PAIR_EXPLAIN_DUMP') === '1') {
            foreach ($report as $name => $plan) {
                fwrite(STDERR, "== {$name}\n");
                foreach ($plan as $r) {
                    fwrite(STDERR, implode(' | ', [$r['id'], $r['select_type'], $r['table'], $r['type'], $r['key'], $r['rows'], $r['Extra']]) . "\n");
                }
            }
        }

        foreach ($report as $name => $plan) {
            foreach ($plan as $row) {
                $table = (string) $row['table'];
                if ($table === '' || str_starts_with($table, '<')) {
                    continue; // odvozená tabulka nad už vybranými řádky
                }
                self::assertNotSame('ALL', $row['type'], $name . ': průchod celou tabulkou ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                self::assertNotSame('index', $row['type'], $name . ': průchod celým indexem ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                // Opis čte řádky svého účtu za období (běžící zůstatek), dnes stejně; ostatní
                // přístupy jsou dohledání v rámci jednoho zápisu.
                if (in_array($table, ['l', 'e', 'cl', 'pg', 'o'], true)) {
                    self::assertLessThan($total / 5, (int) $row['rows'], $name . ': odhad řádků ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                }
                if (in_array($table, ['cl', 'pg', 'o'], true)) {
                    self::assertLessThan(100, (int) $row['rows'], $name . ': dohledání v zápisu ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        foreach (['opis s filtrem', 'počet s filtrem'] as $name) {
            $keys = array_column(array_filter($report[$name], static fn (array $r): bool => $r['table'] === 'cl'), 'key');
            self::assertContains('idx_jel_supplier_entry_pair', $keys, $name . ': partner dvojice po idx_jel_supplier_entry_pair.');
        }

        $rows = $this->ledger->accountLines($this->supplierId, $account, $from, $to, 50, 0, true, $counter, true);
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertSame(1.0, $row['amount'], 'Filtr 568 vrátí jen dvojice 568 / 221.');
        }
    }

    /** @return array<int, array<string,int>> firma => kód => id (cílová firma a firma „šumu") */
    private function seedChart(): array
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts (id, supplier_id, account_code, name, account_type, is_synthetic, parent_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $out = [];
        foreach ([$this->supplierId => 1000, $this->supplierId + 100000 => 2000] as $supplier => $base) {
            $insert->execute([$base, $supplier, '343', 'DPH', 'liability', 1, null]);
            $out[$supplier]['343'] = $base;
            foreach ([...self::MAIN, ...self::COUNTER, self::FEE] as $i => $code) {
                $id = $base + 1 + $i;
                $parent = str_starts_with($code, '343.') ? $base : null;
                $insert->execute([$id, $supplier, $code, 'Účet ' . $code, $code[0] === '5' ? 'expense' : ($code[0] === '6' ? 'revenue' : 'asset'), $parent === null ? 1 : 0, $parent]);
                $out[$supplier][$code] = $id;
            }
        }
        return $out;
    }

    /** @param array<int, array<string,int>> $accounts */
    private function seedJournal(array $accounts): int
    {
        $pdo = $this->db->pdo();
        $noise = $this->supplierId + 100000;
        // 60 % zápisů cílové firmy, zbytek jiné firmy (deník je sdílený všemi firmami).
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, description, source_type, posted_at)
             SELECT IF(seq MOD 5 < 3, ?, ?), 1, DATE_ADD('2099-01-01', INTERVAL (seq MOD 365) DAY), CONCAT('SYN-', seq),
                    'Syntetický zápis', 'manual', NOW()
               FROM seq_1_to_" . self::ENTRIES
        )->execute([$this->supplierId, $noise]);

        $mainBase = 1 + 1; // index 0 účtu MAIN je id base + 1
        $counterBase = 1 + count(self::MAIN) + 1;
        $base = "IF(e.supplier_id = {$this->supplierId}, 1000, 2000)";
        // Výběr účtu nezávislý na rozdělení zápisů mezi firmy (seq MOD 5).
        $main = "{$base} + {$mainBase} - 1 + ((e.id DIV 7) MOD " . count(self::MAIN) . ')';
        $counter = "{$base} + {$counterBase} - 1 + ((e.id DIV 70) MOD " . count(self::COUNTER) . ')';
        $fee = "{$base} + " . (1 + count(self::MAIN) + count(self::COUNTER));
        $pdo->exec(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, is_red_storno, line_no, pair_no)
             SELECT e.id, e.supplier_id,
                    CASE WHEN p.side = 'debit' THEN (CASE p.pair_no WHEN 3 THEN {$fee} ELSE {$counter} END) ELSE {$main} END,
                    p.side,
                    CASE p.pair_no WHEN 1 THEN 100 + (e.id MOD 997) WHEN 2 THEN 50 ELSE 1 END,
                    0, p.line_no, p.pair_no
               FROM journal_entries e
               JOIN (SELECT 1 AS pair_no, 'debit' AS side, 1 AS line_no UNION ALL SELECT 1, 'credit', 2
                     UNION ALL SELECT 2, 'debit', 3 UNION ALL SELECT 2, 'credit', 4
                     UNION ALL SELECT 3, 'debit', 5 UNION ALL SELECT 3, 'credit', 6) p"
        );
        self::assertSame($accounts[$this->supplierId][self::FEE], 1000 + 1 + count(self::MAIN) + count(self::COUNTER));
        return (int) $pdo->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function explain(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare('EXPLAIN ' . $sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
