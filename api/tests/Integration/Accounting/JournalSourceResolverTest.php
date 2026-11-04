<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalSourceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\JournalSourceSummaryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Resolver zdrojového dokladu pro drawer v deníku.
 *
 * TĚŽIŠTĚ TESTU je bezpečnostní: uzávěrkové typy nesou SYNTETICKÁ source_id
 * (period_id*10+SLOT, 1e12+, 2e12+, 3e12+ — viz ClosingSourceId). Kdyby je
 * resolver použil jako id dokladu, vytáhl by NÁHODNÝ CIZÍ ŘÁDEK. Test hlídá, že
 * takové zápisy skončí `available:false` a bez bloků.
 *
 * Druhá past: 'provision' má source_id = ID FAKTURY, ne id dohadné položky.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalSourceResolverTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private JournalEntryRepository $journal;
    private JournalSourceSummaryService $summaries;
    private AccountingPeriodRepository $periods;
    private JournalSourceAction $action;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db        = $container->get(Connection::class);
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->summaries = $container->get(JournalSourceSummaryService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $this->action    = $container->get(JournalSourceAction::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId  = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) $pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '211' LIMIT 1"
        )->fetchColumn();
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova nemá účet 211.');
        }
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

    /**
     * Syntetická source_id uzávěrkových typů se NIKDY nesmí interpretovat jako
     * id dokladu. Hodnoty jsou přesně ty, které generuje ClosingSourceId.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function syntheticSourceIds(): iterable
    {
        $periodId = 123;
        yield 'closing (plain period_id)'      => ['closing', $periodId];
        yield 'opening (next period_id)'       => ['opening', $periodId + 1];
        yield 'fx saldo slot'                  => ['fx_revaluation', ClosingSourceId::fxSaldo($periodId)];
        yield 'fx bank slot'                   => ['fx_revaluation', ClosingSourceId::fxBank($periodId)];
        yield 'fx reversal slot'               => ['fx_revaluation', ClosingSourceId::fxReversal($periodId)];
        yield 'stock closing slot (1e12+)'     => ['closing', ClosingSourceId::stockClosing($periodId)];
        yield 'stock shortage slot'            => ['closing', ClosingSourceId::stockShortage($periodId)];
        yield 'stock surplus slot'             => ['closing', ClosingSourceId::stockSurplus($periodId)];
        yield 'stock opening slot'             => ['opening', ClosingSourceId::stockOpening($periodId)];
        yield 'small asset accrual'            => ['small_asset_accrual', ClosingSourceId::smallAssetAccrual($periodId)];
        yield 'small asset release (2e12+)'    => ['small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($periodId)];
        yield 'prepaid accrual'                => ['prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($periodId)];
        yield 'prepaid release (3e12+)'        => ['prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($periodId)];
        yield 'income tax'                     => ['income_tax', $periodId];
        yield 'profit distribution'            => ['profit_distribution', $periodId];
        yield 'stock type'                     => ['stock', $periodId];
    }

    #[DataProvider('syntheticSourceIds')]
    public function testSyntheticSourceIdsAreRejected(string $sourceType, int $sourceId): void
    {
        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
        ]);

        self::assertFalse($summary['available'], "{$sourceType}/{$sourceId} nesmí být resolvováno.");
        self::assertSame('synthetic_source_id', $summary['unavailable_reason']);
        self::assertSame([], $summary['blocks'], 'Uzávěrkový zápis nemá bloky.');
        self::assertSame([], $summary['fields']);
        self::assertNull($summary['title']);
        self::assertNull($summary['route']);
    }

    public function testSyntheticIdCollidingWithRealInvoiceIdIsStillRejected(): void
    {
        // Regrese na konkrétní past: existuje-li faktura s id == fxSaldo(periodId),
        // naivní resolver by ji vrátil jako „zdroj" uzávěrkového zápisu.
        $realInvoiceId = (int) $this->db->pdo()->query(
            "SELECT id FROM invoices WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($realInvoiceId <= 0) {
            self::markTestSkipped('Nemám reálnou fakturu pro kolizní scénář.');
        }

        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'fx_revaluation',
            'source_id'   => $realInvoiceId,
        ]);

        self::assertFalse($summary['available']);
        self::assertSame([], $summary['blocks']);
        self::assertNull($summary['title'], 'Nesmí prosáknout číslo cizí faktury.');
    }

    public function testAnySourceIdAboveSyntheticFloorIsRejected(): void
    {
        // Druhá pojistka nad whitelistem — i „resolvovatelný" typ s id ≥ 1e12
        // je zjevně syntetický, žádný reálný doklad takové id nemá.
        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'invoice',
            'source_id'   => ClosingSourceId::STOCK_SLOT_BASE + 42,
        ]);
        self::assertFalse($summary['available']);
        self::assertSame('synthetic_source_id', $summary['unavailable_reason']);
    }

    public function testProvisionSourceIdIsInvoiceIdAndYieldsNoBlocks(): void
    {
        // 'provision' nese ID FAKTURY. Náhled nedáváme (zápis JE dohadná položka,
        // ne ta faktura), ale proklik musí mířit na fakturu.
        $invoiceId = 4242;
        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'provision',
            'source_id'   => $invoiceId,
        ]);

        self::assertFalse($summary['available']);
        self::assertSame('no_preview', $summary['unavailable_reason']);
        self::assertSame([], $summary['blocks'], 'Dohadná položka nerenderuje fakturu jako svůj obsah.');
        self::assertIsArray($summary['route']);
        self::assertSame('invoice-detail', $summary['route']['name']);
        self::assertSame($invoiceId, $summary['route']['params']['id'], 'source_id je ID faktury.');
    }

    public function testManualEntryHasNoSource(): void
    {
        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'manual',
            'source_id'   => null,
        ]);
        self::assertFalse($summary['available']);
        self::assertSame('no_source', $summary['unavailable_reason']);
        self::assertSame([], $summary['blocks']);
    }

    public function testOtherItemPreviewUsesJournalEntryAndStaysWithinSupplier(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO other_items
            (supplier_id, side, kind, title, partner_name, issued_on, due_on, amount,
             status, currency, note, account_code, counter_account_code)
            VALUES (?, 'receivable', 'rent', 'Nárok ze smlouvy', 'Syntetická protistrana',
                    '2097-06-01', '2097-06-30', 1250, 'posted', 'CZK', 'Podklad k nároku', '315', '602')")
            ->execute([$this->supplierId]);
        $itemId = (int) $pdo->lastInsertId();
        $entryId = $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id' => $this->periodId,
                'entry_date' => self::YEAR . '-06-01',
                'source_type' => 'other_item',
                'source_id' => $itemId,
                'posted_at' => date('Y-m-d H:i:s'),
                'posted_by' => $this->userId,
            ],
            [
                ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 1250.0],
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 1250.0],
            ],
        );

        $res = $this->invoke('GET', 'readonly', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        self::assertTrue($res['body']['available']);
        self::assertSame('other-item-detail', $res['body']['route']['name']);
        self::assertSame($itemId, $res['body']['route']['params']['id']);
        self::assertSame('other_items', $res['body']['actions'][0]['permission']);
        $fields = array_column($res['body']['fields'], 'value', 'key');
        self::assertSame('receivable', $fields['side']);
        self::assertSame('rent', $fields['kind']);
        self::assertSame('Podklad k nároku', $fields['note']);
        self::assertSame(1250, $fields['amount_to_pay']);

        // Úhrada převzatá mimo evidenci se počítá stejně jako v detailu položky.
        $pdo->prepare('UPDATE other_items SET settled_outside_amount = 500 WHERE id = ? AND supplier_id = ?')
            ->execute([$itemId, $this->supplierId]);
        $outside = array_column($this->summaries->summarize($this->supplierId, [
            'source_type' => 'other_item', 'source_id' => $itemId,
        ])['fields'], 'value', 'key');
        self::assertEqualsWithDelta(500.0, $outside['paid_total'], 0.001);
        self::assertEqualsWithDelta(750.0, $outside['amount_to_pay'], 0.001);

        $restrictedRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal/' . $entryId . '/source')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1, 'Accounting only', 'staff', true, ['accounting' => 1, 'other_items' => 0],
            ));
        $restricted = $this->action->__invoke($restrictedRequest, new Psr7Response(), ['id' => (string) $entryId]);
        self::assertSame(403, $restricted->getStatusCode());

        $foreign = $this->summaries->summarize($this->supplierId + 99999, [
            'source_type' => 'other_item', 'source_id' => $itemId,
        ]);
        self::assertFalse($foreign['available']);
        self::assertSame('not_found', $foreign['unavailable_reason']);
    }

    public function testMissingDocumentReportsNotFoundInsteadOfForeignRow(): void
    {
        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'invoice',
            'source_id'   => 999999999,
        ]);
        self::assertFalse($summary['available']);
        self::assertSame('not_found', $summary['unavailable_reason']);
    }

    public function testForeignTenantDocumentIsNotResolved(): void
    {
        $invoice = $this->db->pdo()->query(
            "SELECT id, supplier_id FROM invoices ORDER BY id LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($invoice)) {
            self::markTestSkipped('Nemám žádnou fakturu.');
        }

        // Tentýž doklad pod CIZÍM supplier_id → nesmí se dohledat.
        $summary = $this->summaries->summarize((int) $invoice['supplier_id'] + 99999, [
            'source_type' => 'invoice',
            'source_id'   => (int) $invoice['id'],
        ]);
        self::assertFalse($summary['available']);
        self::assertSame('not_found', $summary['unavailable_reason']);
    }

    public function testEndpointIsKeyedByEntryIdAndScopedByTenant(): void
    {
        $entryId = $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id'   => $this->periodId,
                'entry_date'  => self::YEAR . '-06-15',
                'source_type' => 'closing',
                'source_id'   => ClosingSourceId::stockClosing($this->periodId),
                'posted_at'   => date('Y-m-d H:i:s'),
                'posted_by'   => $this->userId,
            ],
            [
                ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100.0],
            ],
        );

        $res = $this->invoke('GET', 'readonly', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status'], 'Náhled je readonly+.');
        self::assertSame($entryId, $res['body']['entry_id']);
        self::assertFalse($res['body']['available']);
        self::assertSame('synthetic_source_id', $res['body']['unavailable_reason']);

        // Neexistující zápis → 404, ne prázdné shrnutí.
        $missing = $this->invoke('GET', 'readonly', ['id' => '999999999']);
        self::assertSame(404, $missing['status']);
        self::assertSame('not_found', $missing['body']['error']['code']);
    }

    public function testTableBlocksAreCappedAndFlagTruncation(): void
    {
        // Náhled nesmí vracet neomezený počet řádků; oříznutí musí být přiznané.
        $invoiceId = (int) $this->db->pdo()->query(
            "SELECT i.id FROM invoices i
               JOIN invoice_items it ON it.invoice_id = i.id
              WHERE i.supplier_id = {$this->supplierId}
              GROUP BY i.id HAVING COUNT(*) > 0 ORDER BY COUNT(*) DESC LIMIT 1"
        )->fetchColumn();
        if ($invoiceId <= 0) {
            self::markTestSkipped('Nemám fakturu s položkami.');
        }

        $summary = $this->summaries->summarize($this->supplierId, [
            'source_type' => 'invoice',
            'source_id'   => $invoiceId,
        ]);
        self::assertTrue($summary['available']);

        foreach ($summary['blocks'] as $block) {
            if (($block['type'] ?? '') !== 'table') {
                continue;
            }
            self::assertLessThanOrEqual(
                JournalSourceSummaryService::MAX_BLOCK_ROWS,
                count($block['rows']),
                "Blok {$block['key']} přesáhl strop řádků."
            );
            self::assertArrayHasKey('truncated', $block);
            self::assertArrayHasKey('total_rows', $block);
            if (count($block['rows']) < $block['total_rows']) {
                self::assertTrue($block['truncated'], 'Oříznutí musí být přiznané, ne tiché.');
            }
            // Částky chodí jako čísla, formátuje až FE (jinak by se rozbila lokalizace).
            foreach ($block['columns'] as $col) {
                if ($col['format'] === 'currency') {
                    foreach ($block['rows'] as $row) {
                        if ($row[$col['key']] !== null) {
                            self::assertIsNumeric($row[$col['key']], 'Částka musí být číslo, ne string.');
                        }
                    }
                }
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(string $httpMethod, string $role, array $args): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);

        $resp = $this->action->__invoke($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
