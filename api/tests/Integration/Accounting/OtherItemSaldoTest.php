<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class OtherItemSaldoTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private OtherItemService $items;
    private SaldoService $saldo;
    private int $supplierId;
    private int $periodId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->posting = $container->get(PostingService::class);
        $this->items = $container->get(OtherItemService::class);
        $this->saldo = $container->get(SaldoService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testReceivableUsesPostingDatesAndReopensWhenPaymentIsReversed(): void
    {
        $itemId = $this->item('receivable', '315', '602', 'Syntetická pohledávka');
        $this->post($itemId, '315', 'debit', '602', '2099-01-10');

        $before = $this->account('315', '2099-01-31');
        self::assertSame(100000, $this->cents($before['open_items_total']));
        self::assertTrue($before['matches']);
        self::assertLessThan(0, $before['partners'][0]['partner_id']);
        self::assertSame('other_item', $before['partners'][0]['items'][0]['doc_type']);

        $bankId = $this->bankPayment(400.0);
        $paymentEntry = $this->posting->postDocument($this->supplierId, 'bank', $bankId, [
            $this->line('221', 'debit', 400.0), $this->line('315', 'credit', 400.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);
        $this->pdo->prepare(
            'INSERT INTO other_item_allocations
                (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
             VALUES (?, ?, ?, 400, ?)'
        )->execute([$this->supplierId, $itemId, $bankId, '2099-01-20']);

        $january = $this->account('315', '2099-01-31');
        self::assertSame(100000, $this->cents($january['open_items_total']),
            'Datum na alokaci nesmí přesunout únorový účetní zápis do ledna.');
        self::assertTrue($january['matches']);

        $february = $this->account('315', '2099-02-28');
        self::assertSame(60000, $this->cents($february['gl_balance']));
        self::assertSame(60000, $this->cents($february['open_items_total']));
        self::assertTrue($february['matches']);

        $this->posting->reverse($this->supplierId, $paymentEntry, ['entry_date' => '2099-03-01']);
        self::assertTrue($this->account('315', '2099-02-28')['matches']);
        $march = $this->account('315', '2099-03-31');
        self::assertSame(100000, $this->cents($march['open_items_total']));
        self::assertTrue($march['matches']);
        self::assertSame(100000, $this->cents((new OtherItemRepository($this->db))
            ->find($this->supplierId, $itemId)['remaining_amount']));
        $forecast = (new OtherItemForecastService($this->db))
            ->dueBetween($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertSame(100000, $this->cents($forecast[0]['remaining']));
    }

    /** @return array<string,array{string}> */
    public static function paymentSources(): array
    {
        return ['bank' => ['bank'], 'cash' => ['cash']];
    }

    #[DataProvider('paymentSources')]
    public function testRepostingPaymentDoesNotReactivateReversedAllocation(string $sourceType): void
    {
        $itemId = $this->item('receivable', '315', '602', 'Syntetická pohledávka');
        $itemEntry = $this->post($itemId, '315', 'debit', '602', '2099-01-10');
        $this->pdo->prepare('UPDATE other_items SET journal_entry_id = ? WHERE id = ?')
            ->execute([$itemEntry, $itemId]);
        $paymentId = $sourceType === 'bank' ? $this->bankPayment(400.0) : $this->cashPayment(400.0);
        $cashAccount = $sourceType === 'bank' ? '221' : '211';
        $entry = $this->posting->postDocument($this->supplierId, $sourceType, $paymentId, [
            $this->line($cashAccount, 'debit', 400.0), $this->line('315', 'credit', 400.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);
        $paymentColumn = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $this->pdo->prepare(
            "INSERT INTO other_item_allocations
                (supplier_id, other_item_id, {$paymentColumn}, amount, payment_on)
             VALUES (?, ?, ?, 400, '2099-02-05')"
        )->execute([$this->supplierId, $itemId, $paymentId]);

        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => '2099-03-01']);
        $reversedOn = $this->pdo->prepare(
            'SELECT reversed_on FROM other_item_allocations
              WHERE supplier_id = ? AND other_item_id = ?'
        );
        $reversedOn->execute([$this->supplierId, $itemId]);
        self::assertSame('2099-03-01', $reversedOn->fetchColumn());
        $this->posting->postDocument($this->supplierId, $sourceType, $paymentId, [
            $this->line($cashAccount, 'debit', 400.0), $this->line('315', 'credit', 400.0),
        ], ['entry_date' => '2099-04-05', 'posted' => true]);

        self::assertSame(60000, $this->cents($this->account('315', '2099-02-28')['open_items_total']));
        self::assertSame(100000, $this->cents($this->account('315', '2099-03-31')['open_items_total']));
        $april = $this->account('315', '2099-04-30');
        self::assertSame(60000, $this->cents($april['gl_balance']));
        self::assertSame(100000, $this->cents($april['open_items_total']));
        self::assertFalse($april['matches']);

        $otherId = $this->item('receivable', '315', '602', 'Jiná syntetická pohledávka');
        $otherEntry = $this->post($otherId, '315', 'debit', '602', '2099-05-05');
        $this->pdo->prepare('UPDATE other_items SET journal_entry_id = ? WHERE id = ?')
            ->execute([$otherEntry, $otherId]);
        self::assertNotContains($paymentId, array_column(
            $this->items->paymentCandidates($this->supplierId, $otherId, '', 20,
                $sourceType === 'bank', $sourceType === 'cash'), 'id'));
        try {
            $this->items->allocate($this->supplierId, $otherId,
                [$sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id' => $paymentId, 'amount' => 400], null);
            self::fail('Zpětně by se platba započetla k oběma pohledávkám.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_reallocation_conflict', $e->errorCode);
        }

        self::assertContains($paymentId, array_column(
            $this->items->paymentCandidates($this->supplierId, $itemId, '', 20,
                $sourceType === 'bank', $sourceType === 'cash'), 'id'));
        try {
            $this->items->allocate($this->supplierId, $itemId,
                [$sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id' => $paymentId, 'amount' => 300], null);
            self::fail('Jiná částka by zpětně změnila historické saldo.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_reallocation_amount', $e->errorCode);
        }
        $allocated = $this->items->allocate($this->supplierId, $itemId,
            [$sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id' => $paymentId, 'amount' => 400], null);
        self::assertSame(60000, $this->cents($allocated['remaining_amount']));
        self::assertSame(60000, $this->cents($this->account('315', '2099-02-28')['open_items_total']));
        self::assertSame(100000, $this->cents($this->account('315', '2099-03-31')['open_items_total']));
        self::assertTrue($this->account('315', '2099-04-30')['matches']);
    }

    public function testRepostingMovesPayableBetweenAccountsAtItsEntryDate(): void
    {
        $id = $this->item('payable', '325', '518', 'Syntetický závazek');
        $original = $this->post($id, '325', 'credit', '518', '2099-01-10');
        $this->posting->reverse($this->supplierId, $original, ['entry_date' => '2099-03-01']);
        $this->post($id, '379', 'credit', '518', '2099-03-01');

        $february = $this->account('325', '2099-02-28');
        self::assertSame(100000, $this->cents($february['open_items_total']));
        self::assertTrue($february['matches']);

        $marchOld = $this->account('325', '2099-03-31');
        self::assertSame(0, $this->cents($marchOld['open_items_total']));
        self::assertTrue($marchOld['matches']);
        $marchNew = $this->account('379', '2099-03-31');
        self::assertSame(100000, $this->cents($marchNew['open_items_total']));
        self::assertTrue($marchNew['matches']);
    }

    /**
     * Převzatá položka vyrovnaná interním dokladem (bez vazby na položku) nese úhradu
     * v `settled_outside_amount`. Saldokonto ji musí vidět stejně jako detail položky,
     * jinak svítí otevřená, přestože účet je v hlavní knize vyrovnaný.
     */
    public function testSettledOutsideAmountReducesOpenItemLikeItemDetail(): void
    {
        $partial = $this->item('receivable', '315', '602', 'Syntetický příspěvek částečně');
        $this->post($partial, '315', 'debit', '602', '2099-01-10');
        $full = $this->item('receivable', '315', '602', 'Syntetický příspěvek celý');
        $this->post($full, '315', 'debit', '602', '2099-01-10');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            $this->line('395', 'debit', 1400.0), $this->line('315', 'credit', 1400.0),
        ], ['entry_date' => '2099-01-31', 'posted' => true]);
        $outside = $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = ? WHERE id = ? AND supplier_id = ?');
        $outside->execute([400, $partial, $this->supplierId]);
        $outside->execute([1000, $full, $this->supplierId]);

        $account = $this->account('315', '2099-02-28');
        self::assertSame(60000, $this->cents($account['gl_balance']));
        self::assertSame(60000, $this->cents($account['open_items_total']));
        self::assertSame(1, $account['open_items_count']);
        self::assertTrue($account['matches']);
        self::assertSame($partial, $account['partners'][0]['items'][0]['doc_id']);
        self::assertSame(40000, $this->cents($account['partners'][0]['items'][0]['paid_czk']));
        self::assertSame(60000, $this->cents((new OtherItemRepository($this->db))
            ->find($this->supplierId, $partial)['remaining_amount']));
    }

    public function testSettledOutsideAmountAddsToAllocatedBankPayment(): void
    {
        $itemId = $this->item('receivable', '315', '602', 'Syntetický příspěvek s platbou');
        $this->post($itemId, '315', 'debit', '602', '2099-01-10');
        $bankId = $this->bankPayment(300.0);
        $this->posting->postDocument($this->supplierId, 'bank', $bankId, [
            $this->line('221', 'debit', 300.0), $this->line('315', 'credit', 300.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);
        $this->items->allocate($this->supplierId, $itemId, ['bank_transaction_id' => $bankId, 'amount' => 300], null);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            $this->line('395', 'debit', 400.0), $this->line('315', 'credit', 400.0),
        ], ['entry_date' => '2099-02-10', 'posted' => true]);
        $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = 400 WHERE id = ? AND supplier_id = ?')
            ->execute([$itemId, $this->supplierId]);

        $account = $this->account('315', '2099-02-28');
        self::assertSame(30000, $this->cents($account['gl_balance']));
        self::assertSame(30000, $this->cents($account['open_items_total']));
        self::assertTrue($account['matches']);
        self::assertSame(70000, $this->cents($account['partners'][0]['items'][0]['paid_czk']));
    }

    public function testSettledOutsideAmountClosesPayable(): void
    {
        $itemId = $this->item('payable', '325', '518', 'Syntetický převzatý závazek');
        $this->post($itemId, '325', 'credit', '518', '2099-01-10');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            $this->line('325', 'debit', 1000.0), $this->line('395', 'credit', 1000.0),
        ], ['entry_date' => '2099-01-31', 'posted' => true]);
        $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = 1000 WHERE id = ? AND supplier_id = ?')
            ->execute([$itemId, $this->supplierId]);

        $account = $this->account('325', '2099-02-28');
        self::assertSame(0, $this->cents($account['open_items_total']));
        self::assertSame(0, $account['open_items_count']);
        self::assertTrue($account['matches']);
    }

    public function testOtherItemOnAdvanceAccountIsIncludedOnce(): void
    {
        $id = $this->item('receivable', '314', '378', 'Syntetická záloha');
        $this->post($id, '314', 'debit', '378', '2099-01-10');

        $account = $this->account('314', '2099-01-31');
        self::assertSame(100000, $this->cents($account['gl_balance']));
        self::assertSame(100000, $this->cents($account['open_items_total']));
        self::assertSame(1, $account['open_items_count']);
        self::assertTrue($account['matches']);
    }

    public function testOneBankPaymentCanSettleDifferentBalanceAccounts(): void
    {
        $first = $this->item('receivable', '315', '602', 'První pohledávka');
        $second = $this->item('receivable', '378', '602', 'Druhá pohledávka');
        foreach ([[$first, '315'], [$second, '378']] as [$itemId, $account]) {
            $entryId = $this->post($itemId, $account, 'debit', '602', '2099-01-10');
            $this->pdo->prepare('UPDATE other_items SET journal_entry_id = ? WHERE id = ?')
                ->execute([$entryId, $itemId]);
        }
        $bankId = $this->bankPayment(1000.0);
        $this->posting->postDocument($this->supplierId, 'bank', $bankId, [
            $this->line('221', 'debit', 1000.0),
            $this->line('315', 'credit', 500.0),
            $this->line('378', 'credit', 500.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);

        $this->items->allocate($this->supplierId, $first, ['bank_transaction_id' => $bankId, 'amount' => 500], null);
        self::assertContains($bankId, array_column(
            $this->items->paymentCandidates($this->supplierId, $second, '', 20), 'id'));
        $secondResult = $this->items->allocate($this->supplierId, $second,
            ['bank_transaction_id' => $bankId, 'amount' => 500], null);
        self::assertSame(50000, $this->cents($secondResult['remaining_amount']));
    }

    public function testPaymentUsesActualRedirectedAnalyticAccount(): void
    {
        $accounts = new ChartOfAccountsRepository($this->db);
        $parent = $accounts->findByCode($this->supplierId, '315');
        self::assertNotNull($parent);
        $accounts->insert($this->supplierId, [
            'account_code' => '315.001', 'name' => 'Syntetická analytika pohledávek',
            'account_type' => $parent['account_type'], 'normal_side' => $parent['normal_side'],
            'is_synthetic' => false, 'parent_id' => (int) $parent['id'], 'is_active' => true,
        ]);
        $item = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetická pohledávka',
            'issued_on' => '2099-01-10', 'accounting_on' => '2099-01-10',
            'due_on' => '2099-02-20', 'currency' => 'CZK', 'amount' => 1000,
            'account_code' => '315', 'counter_account_code' => '602',
        ], null);
        $item = $this->items->post($this->supplierId, (int) $item['id'], null);
        $stmt = $this->pdo->prepare(
            'SELECT ca.account_code FROM journal_entry_lines l
              JOIN chart_of_accounts ca ON ca.id = l.account_id
             WHERE l.entry_id = ? AND l.side = ?'
        );
        $stmt->execute([(int) $item['journal_entry_id'], 'debit']);
        self::assertSame('315.001', $stmt->fetchColumn());
        self::assertSame('315', $item['account_code']);

        $bankId = $this->bankPayment(400.0);
        $this->posting->postDocument($this->supplierId, 'bank', $bankId, [
            $this->line('221', 'debit', 400.0), $this->line('315.001', 'credit', 400.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);
        self::assertContains($bankId, array_column(
            $this->items->paymentCandidates($this->supplierId, (int) $item['id'], '', 20, true, false), 'id'));
        $paid = $this->items->allocate($this->supplierId, (int) $item['id'],
            ['bank_transaction_id' => $bankId, 'amount' => 400], null);
        self::assertSame(60000, $this->cents($paid['remaining_amount']));

        $saldo = $this->account('315', '2099-02-28');
        self::assertSame(60000, $this->cents($saldo['gl_balance']));
        self::assertSame(60000, $this->cents($saldo['open_items_total']));
        self::assertTrue($saldo['matches']);
    }

    public function testPaymentPostingLimitCountsOtherItemsOnTheSameRedirectedAccount(): void
    {
        $accounts = new ChartOfAccountsRepository($this->db);
        $parent = $accounts->findByCode($this->supplierId, '315');
        self::assertNotNull($parent);
        $accounts->insert($this->supplierId, [
            'account_code' => '315.001', 'name' => 'Syntetická analytika pohledávek',
            'account_type' => $parent['account_type'], 'normal_side' => $parent['normal_side'],
            'is_synthetic' => false, 'parent_id' => (int) $parent['id'], 'is_active' => true,
        ]);
        $ids = [];
        foreach (['315', '315.001'] as $account) {
            $item = $this->items->create($this->supplierId, [
                'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetická pohledávka',
                'issued_on' => '2099-01-10', 'accounting_on' => '2099-01-10',
                'due_on' => '2099-02-20', 'currency' => 'CZK', 'amount' => 1000,
                'account_code' => $account, 'counter_account_code' => '602',
            ], null);
            $this->items->post($this->supplierId, (int) $item['id'], null);
            $ids[] = (int) $item['id'];
        }
        $bankId = $this->bankPayment(1000.0);
        $this->posting->postDocument($this->supplierId, 'bank', $bankId, [
            $this->line('221', 'debit', 1000.0), $this->line('315.001', 'credit', 500.0),
            $this->line('378', 'credit', 500.0),
        ], ['entry_date' => '2099-02-05', 'posted' => true]);

        $this->items->allocate($this->supplierId, $ids[0],
            ['bank_transaction_id' => $bankId, 'amount' => 400], null);
        try {
            $this->items->allocate($this->supplierId, $ids[1],
                ['bank_transaction_id' => $bankId, 'amount' => 200], null);
            self::fail('Platba překročila zaúčtovanou částku na analytickém účtu.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_not_posted', $e->errorCode);
        }
    }

    public function testDueBeforeFiltersOtherItemsInDocumentCompletenessQuery(): void
    {
        $early = $this->item('receivable', '315', '602', 'Dřívější splatnost');
        $late = $this->item('receivable', '315', '602', 'Pozdější splatnost');
        $this->pdo->prepare('UPDATE other_items SET due_on = ? WHERE id = ?')
            ->execute(['2099-04-20', $late]);
        $this->post($early, '315', 'debit', '602', '2099-01-10');
        $this->post($late, '315', 'debit', '602', '2099-01-10');

        $repository = new SaldoRepository($this->db);
        $account = $repository->resolveAccount($this->supplierId, '315');
        self::assertNotNull($account);
        $rows = $repository->openItems(
            $this->supplierId, $account['id'], '2099-05-01', '315', null, null,
            '2099-03-01', true,
        );
        self::assertCount(1, $rows);
        self::assertSame($early, $rows[0]['doc_id']);
    }

    private function item(string $side, string $account, string $counter, string $partner): int
    {
        $this->pdo->prepare(
            'INSERT INTO other_items
               (supplier_id, side, title, partner_name, issued_on, accounting_on, due_on,
                currency, amount, amount_czk, account_code, counter_account_code,
                status, document_no, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId, $side, $partner, $partner, '2099-01-10', '2099-01-10',
            '2099-02-20', 'CZK', 1000, 1000, $account, $counter, 'posted',
            'OST-' . bin2hex(random_bytes(4)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function post(int $id, string $account, string $side, string $counter, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'other_item', $id, [
            $this->line($account, $side, 1000.0),
            $this->line($counter, $side === 'debit' ? 'credit' : 'debit', 1000.0),
        ], ['entry_date' => $date, 'posted' => true]);
    }

    private function bankPayment(float $amount): int
    {
        $hash = hash('sha256', random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO bank_statements
               (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", ?, ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, 'synteticky.gpc', $hash, '2099-02-05']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
               (statement_id, source, posted_at, amount, currency, counterparty_name, match_status)
             VALUES (?, "statement", ?, ?, "CZK", "Syntetický plátce", "unmatched")'
        )->execute([$statementId, '2099-01-20', $amount]);
        return (int) $this->pdo->lastInsertId();
    }

    private function cashPayment(float $amount): int
    {
        $this->pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, "Syntetická pokladna", "CZK", "211")'
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date,
                 description, total_amount, currency_code, status)
             VALUES (?, ?, "in", "other", "SYNTH-SALDO-CASH", "2099-02-05",
                     "Syntetická úhrada", ?, "CZK", "posted")'
        )->execute([$this->supplierId, $registerId, $amount]);
        return (int) $this->pdo->lastInsertId();
    }

    private function account(string $code, string $date): array
    {
        foreach ($this->saldo->build($this->supplierId, $this->periodId, $date, $code)['accounts'] as $block) {
            if ($block['account']['code'] === $code) return $block;
        }
        self::fail('Účet ' . $code . ' nebyl v sestavě.');
    }

    private function line(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private function cents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
