<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EP-6: povinná inventarizace rozvahových účtů před uzavřením knih.
 *
 * Ověřuje, že:
 *   - před uložením inventarizace kontrola `inventory_unresolved` selhává (blokuje close),
 *   - nevyřešený inventurní rozdíl brání dokončení inventarizace i uzavření knih,
 *   - potvrzení (resolution='resolved') / shoda skutečného stavu rozdíl vyřeší a odblokuje.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingSmallAssetAccrualTest).
 */
#[Group('integration')]
final class ClosingBalanceInventoryTest extends TestCase
{
    private const YEAR = 2093;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->closing = $container->get(ClosingService::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId  = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId    = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId     = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId          = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Inventarizace test s.r.o.', $czId, 'inv-test@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        // Rozvahový pohyb: MD 221 (banka) / D 411 (základní kapitál) 1000 — dva rozvahové účty.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 1000.0],
        ], [
            'entry_date' => self::ENDS_ON,
            'document_no' => 'INV-TEST-1',
            'description' => 'Vklad kapitálu (test inventarizace)',
            'posted' => true,
            'user_id' => $this->userId,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function rowVersion(): int
    {
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        return (int) $period['row_version'];
    }

    private function inventoryCheckOk(): bool
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);
        foreach ($result['checks'] as $c) {
            if ($c['key'] === 'inventory_unresolved') {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola inventory_unresolved chybí v seznamu kontrol.');
    }

    public function testUnsavedInventoryBlocksClose(): void
    {
        // Bez uložené inventarizace je kontrola inventory_unresolved v chybovém stavu.
        self::assertFalse($this->inventoryCheckOk());

        $preview = $this->closing->inventoryPreview($this->supplierId, $this->periodId);
        $codes = array_map(static fn (array $r): string => (string) $r['account_code'], $preview['rows']);
        self::assertContains('221', $codes);
        self::assertContains('411', $codes);
    }

    /**
     * Uzavřené/schválené období bez uložené inventarizace (např. rok uzavřený ještě
     * před EP-6): buildWithSaved dopočte skutečný stav z účetního (counted = book,
     * resolved) — inventarizace se prezentuje jako dokončená, ne jako samá nevyřešená.
     */
    public static function closedStatuses(): iterable
    {
        yield 'closed' => ['closed'];
        yield 'reviewed' => ['reviewed'];
        yield 'approved' => ['approved'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedStatuses')]
    public function testClosedPeriodBackFillsInventoryFromLedger(string $status): void
    {
        // Simulace uzavřeného období — bez uložené inventarizace.
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$status, $this->periodId, $this->supplierId]);

        $preview = $this->closing->inventoryPreview($this->supplierId, $this->periodId);

        self::assertNotSame([], $preview['rows']);
        foreach ($preview['rows'] as $r) {
            self::assertTrue((bool) $r['back_filled'], 'Účet ' . $r['account_code'] . ' má být dopočten.');
            self::assertTrue((bool) $r['resolved'], 'Dopočtený účet je vyřešený.');
            self::assertSame(
                round((float) $r['book_balance'], 2),
                round((float) $r['counted_balance'], 2),
                'Skutečný stav = účetní zůstatek.',
            );
        }
        self::assertSame('completed', $preview['inventory']['status']);
        self::assertSame(0, $preview['inventory']['unresolved_count']);
        self::assertTrue($preview['inventory']['completed']);
        self::assertTrue($preview['inventory']['back_filled']);
    }

    public function testCompletedInventoryWithMatchingCountsUnblocksClose(): void
    {
        $items = [];
        foreach ($this->closing->inventoryPreview($this->supplierId, $this->periodId)['rows'] as $r) {
            // Skutečný stav = účetní (book) → rozdíl 0 → auto-resolved.
            $items[] = ['account_id' => (int) $r['account_id'], 'counted_balance' => (float) $r['book_balance']];
        }
        $body = $this->saveBody($items, true);
        $res = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), $body['header'], $body['items'], ['user_id' => $this->userId]);

        self::assertSame('completed', $res['status']);
        self::assertSame(0, $res['unresolved_count']);
        self::assertTrue($res['ok']);
        self::assertTrue($this->inventoryCheckOk());
    }

    public function testUnresolvedDifferenceBlocksCompletionAndClose(): void
    {
        $rows = $this->closing->inventoryPreview($this->supplierId, $this->periodId)['rows'];
        $items = [];
        foreach ($rows as $r) {
            $counted = (float) $r['book_balance'];
            if ((string) $r['account_code'] === '221') {
                $counted -= 100.0; // úmyslný nevyřešený rozdíl −100
            }
            $items[] = ['account_id' => (int) $r['account_id'], 'counted_balance' => $counted, 'resolution' => 'open'];
        }
        $body = $this->saveBody($items, true);
        $res = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), $body['header'], $body['items'], ['user_id' => $this->userId]);

        // Dokončení je zablokované — zůstává rozpracované, uzavření knih blokované.
        self::assertSame('in_progress', $res['status']);
        self::assertFalse($res['completed']);
        self::assertSame(1, $res['unresolved_count']);
        self::assertFalse($this->inventoryCheckOk());

        // Potvrzení rozdílu (resolution='resolved') ho vyřeší a odblokuje uzavření.
        $items2 = [];
        foreach ($rows as $r) {
            $counted = (float) $r['book_balance'];
            $resolution = 'open';
            if ((string) $r['account_code'] === '221') {
                $counted -= 100.0;
                $resolution = 'resolved';
            }
            $items2[] = ['account_id' => (int) $r['account_id'], 'counted_balance' => $counted, 'resolution' => $resolution];
        }
        $body2 = $this->saveBody($items2, true);
        $res2 = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), $body2['header'], $body2['items'], ['user_id' => $this->userId]);

        self::assertSame('completed', $res2['status']);
        self::assertSame(0, $res2['unresolved_count']);
        self::assertTrue($res2['ok']);
        self::assertTrue($this->inventoryCheckOk());
    }

    /**
     * @param list<array{account_id:int, counted_balance:float, resolution?:string}> $items
     * @return array{header:array<string,mixed>, items:array<int,array<string,mixed>>}
     */
    private function saveBody(array $items, bool $complete): array
    {
        $byAccount = [];
        foreach ($items as $it) {
            $byAccount[(int) $it['account_id']] = [
                'counted_balance' => $it['counted_balance'],
                'resolution' => $it['resolution'] ?? 'open',
                'note' => null,
            ];
        }
        return [
            'header' => [
                'responsible_person' => 'Jan Účetní',
                'inventory_date' => self::ENDS_ON,
                'protocol_ref' => 'PROT-' . self::YEAR,
                'note' => null,
                'complete' => $complete,
            ],
            'items' => $byAccount,
        ];
    }
}
