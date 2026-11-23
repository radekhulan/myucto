<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\Reports\JournalLineContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Protiúčet žije třikrát a nesmí se rozejít (kritérium d): díly z JournalPairer
 * (zobrazení v opisu, {@see JournalLineContext::derivedPieces()}, u dvojic partner),
 * součty {@see LedgerReportRepository::accountCounterTotals()} a filtr
 * {@see LedgerReportRepository::accountLines()} s protiúčtem.
 *
 * Sada zápisů: 1 : N (faktura, přijatá faktura, hromadná platba, faktura se dvěma
 * sazbami), dvojice se shodnou částkou vedle 1 : N zbytku, červené storno 1 : N. Ověřuje
 * se nad zápisy rozloženými za běhu, nad fyzickými dvojicemi i nad jejich směsí.
 */
#[Group('integration')]
final class CounterAccountConsistencyTest extends TestCase
{
    use PairReportFixture;

    private LedgerReportRepository $ledger;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->ledger = $this->container->get(LedgerReportRepository::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    /** @return iterable<string,array{string}> */
    public static function variants(): iterable
    {
        yield 'rozložené za běhu' => ['derived'];
        yield 'fyzické dvojice' => ['paired'];
        yield 'směs' => ['mixed'];
    }

    #[DataProvider('variants')]
    public function testPairerTotalsAndFilterAgree(string $variant): void
    {
        $entries = $this->postOneToManySet();
        $this->switchToSingle(self::YEAR . '-01-01');
        $i = 0;
        foreach ($entries as $entryId) {
            if ($variant === 'paired' || ($variant === 'mixed' && $i++ % 2 === 0)) {
                self::assertTrue($this->pairEntryInPlace($entryId));
            }
        }
        // Zápisy pořízené rovnou po dvojicích, kde se dvojice od párování podle částky liší:
        // dvě dvojice se shodnou částkou a vlastní účet na obou stranách.
        $y = self::YEAR;
        $this->postPairs("{$y}-06-01", 'ID-2', 'Dvě dvojice se shodnou částkou', [
            ['311', '602', '100.00'], ['311', '604', '100.00'],
        ]);
        $this->postPairs("{$y}-06-02", 'ID-3', 'Účet na obou stranách', [
            ['221', '311', '50.00'], ['311', '602', '50.00'],
        ]);

        $pieces = $this->piecesByLine();
        $accounts = ['311', '321', '221', '211', '602', '604', '343', '343.200', '343.100', '512', '513', '568'];
        foreach ($accounts as $code) {
            $accountId = $this->accountId($code);
            $own = $this->ownLines($accountId);
            self::assertNotSame([], $own, 'Účet ' . $code . ' má v sadě pohyby.');

            // 1) součty podle protiúčtu = součty dílů
            $expected = [];
            foreach ($own as $lineId => $line) {
                foreach ($pieces[$lineId] as $piece) {
                    $signed = $line['is_red_storno'] ? -$piece['amount_cents'] : $piece['amount_cents'];
                    $expected[$piece['counter_account_id']][$line['side'] === 'debit' ? 'md' : 'd'] =
                        ($expected[$piece['counter_account_id']][$line['side'] === 'debit' ? 'md' : 'd'] ?? 0) + $signed;
                }
            }
            $actual = [];
            foreach ($this->ledger->accountCounterTotals($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', true, true) as $row) {
                foreach (['md', 'd'] as $side) {
                    $cents = self::cents($row[$side]);
                    if ($cents !== 0) {
                        $actual[$row['account_id']][$side] = $cents;
                    }
                }
            }
            foreach ($expected as $counter => $sides) {
                $expected[$counter] = array_filter($sides, static fn (int $c): bool => $c !== 0);
            }
            $expected = array_filter($expected);
            ksort($expected);
            ksort($actual);
            foreach ($expected as &$sides) {
                ksort($sides);
            }
            unset($sides);
            foreach ($actual as &$sides) {
                ksort($sides);
            }
            unset($sides);
            self::assertSame($expected, $actual, $variant . ': součty podle protiúčtu účtu ' . $code);

            // 2) filtr protiúčtu vybere právě řádky, které mají díl s tím protiúčtem
            $counters = [];
            foreach ($own as $lineId => $_) {
                foreach ($pieces[$lineId] as $piece) {
                    $counters[$piece['counter_account_id']] = true;
                    if ($piece['counter_parent_id'] !== null) {
                        $counters[$piece['counter_parent_id']] = true;
                    }
                }
            }
            foreach (array_keys($counters) as $counterId) {
                $want = [];
                foreach ($own as $lineId => $_) {
                    foreach ($pieces[$lineId] as $piece) {
                        if ($piece['counter_account_id'] === $counterId || $piece['counter_parent_id'] === $counterId) {
                            $want[] = $lineId;
                            break;
                        }
                    }
                }
                $got = array_map(
                    static fn (array $r): int => $r['line_id'],
                    $this->ledger->accountLines($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1000, 0, true, $counterId, true),
                );
                sort($want);
                sort($got);
                self::assertSame($want, $got, $variant . ': filtr protiúčtu #' . $counterId . ' na účtu ' . $code);
                self::assertSame(count($want), $this->ledger->accountLinesTotal($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', true, $counterId, true));
            }
        }
    }

    /** @return array<string,int> */
    private function postOneToManySet(): array
    {
        $y = self::YEAR;
        return [
            'invoice'  => $this->manual("{$y}-01-10", 'FV-1', 'Faktura', [
                ['311', 'debit', '1210.00'], ['602', 'credit', '1000.00'], ['343.200', 'credit', '210.00'],
            ]),
            'purchase' => $this->manual("{$y}-01-12", 'FP-1', 'Přijatá faktura', [
                ['513', 'debit', '1000.00'], ['343.100', 'debit', '210.00'], ['321', 'credit', '1210.00'],
            ]),
            'bulk'     => $this->manual("{$y}-01-25", 'BV-2', 'Hromadná platba', [
                ['321', 'debit', '1210.00'], ['321', 'debit', '605.00'], ['568', 'debit', '15.00'], ['221', 'credit', '1830.00'],
            ]),
            'sale2'    => $this->manual("{$y}-03-20", 'FV-3', 'Dvě sazby', [
                ['311', 'debit', '13220.00'], ['602', 'credit', '10000.00'], ['343.200', 'credit', '2100.00'],
                ['604', 'credit', '1000.00'], ['343.200', 'credit', '120.00'],
            ]),
            'equal'    => $this->manual("{$y}-04-02", 'PD-2', 'Dvojice a zbytek 1 : N', [
                ['512', 'debit', '100.00'], ['211', 'credit', '100.00'],
                ['513', 'debit', '80.00'], ['568', 'debit', '40.00'], ['221', 'credit', '120.00'],
            ]),
            'storno'   => $this->manual("{$y}-04-15", 'FV-4', 'Opravný doklad', [
                ['311', 'debit', '121.00', true], ['602', 'credit', '100.00', true], ['343.200', 'credit', '21.00', true],
            ]),
            'transfer' => $this->manual("{$y}-05-01", 'BV-3', 'Výběr', [
                ['211', 'debit', '500.00'], ['221', 'credit', '500.00'],
            ]),
        ];
    }

    /**
     * Díly každého řádku firmy: partner dvojice, nebo rozložení za běhu (JournalPairer).
     *
     * @return array<int, list<array{amount_cents:int, counter_account_id:int, counter_parent_id:?int}>>
     */
    private function piecesByLine(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id, l.entry_id, l.account_id, a.account_code, a.parent_id, l.amount, l.amount_foreign, l.side AS raw_side,
                    l.is_red_storno, l.pair_no,
                    CASE WHEN l.is_red_storno = 1 THEN (CASE WHEN l.side = 'debit' THEN 'credit' ELSE 'debit' END) ELSE l.side END AS side
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? ORDER BY l.entry_id, l.line_no, l.id"
        );
        $stmt->execute([$this->supplierId]);
        $byEntry = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byEntry[(int) $r['entry_id']][] = [
                'line_id' => (int) $r['id'], 'account_id' => (int) $r['account_id'], 'account_code' => (string) $r['account_code'],
                'parent_id' => $r['parent_id'] === null ? null : (int) $r['parent_id'], 'side' => (string) $r['side'],
                'raw_side' => (string) $r['raw_side'], 'is_red_storno' => (bool) $r['is_red_storno'], 'amount' => (string) $r['amount'],
                'amount_foreign' => $r['amount_foreign'], 'amount_cents' => self::cents($r['amount']),
                'pair_no' => $r['pair_no'] === null ? null : (int) $r['pair_no'],
            ];
        }
        $out = [];
        foreach ($byEntry as $lines) {
            if ($lines[0]['pair_no'] !== null) {
                $byPair = [];
                foreach ($lines as $l) {
                    $byPair[$l['pair_no']][] = $l;
                }
                foreach ($byPair as [$a, $b]) {
                    foreach ([[$a, $b], [$b, $a]] as [$own, $counter]) {
                        $out[$own['line_id']][] = [
                            'amount_cents' => $own['amount_cents'],
                            'counter_account_id' => $counter['account_id'],
                            'counter_parent_id' => $counter['parent_id'],
                        ];
                    }
                }
                continue;
            }
            $pieces = JournalLineContext::derivedPieces($lines);
            self::assertNotNull($pieces, 'Zápis sady jde jednoznačně rozložit.');
            foreach ($pieces as $lineId => $list) {
                $out[$lineId] = $list;
            }
        }
        return $out;
    }

    /** @return array<int, array{side:string, is_red_storno:bool}> řádky účtu a jeho analytik */
    private function ownLines(int $accountId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.side, l.is_red_storno FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND (a.id = ? OR a.parent_id = ?)'
        );
        $stmt->execute([$this->supplierId, $accountId, $accountId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['side' => (string) $r['side'], 'is_red_storno' => (bool) $r['is_red_storno']];
        }
        return $out;
    }
}
