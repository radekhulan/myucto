<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Action\Accounting\OtherItemOffsetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Service\Accounting\JournalRelatedAccess;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemMonthlyCheck;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemOffsetService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyrovnání ostatní pohledávky a závazku zápočtem (migrace 2351): interní doklad nebo
 * přiřazení existujícího zápisu se promítne do uhrazené části položky, jejího stavu,
 * předpovědi i saldokonta, storno ji zase otevře.
 */
#[Group('integration')]
final class OtherItemOffsetTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemOffsetService $offsets;
    private PostingService $posting;
    private SaldoService $saldo;
    private JournalSettlementService $journalSettlements;
    private int $supplierId;
    private int $periodId;

    protected function setUp(): void
    {
        $container = $this->container = Bootstrap::buildContainer();
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
        $this->items = $container->get(OtherItemService::class);
        $this->offsets = $container->get(OtherItemOffsetService::class);
        $this->posting = $container->get(PostingService::class);
        $this->saldo = $container->get(SaldoService::class);
        $this->journalSettlements = $container->get(JournalSettlementService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testReceivablePartialOffsetPostsInternalDocumentAndSettlesItem(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');

        $first = $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 400, 'note' => 'Zápočet s přijatou fakturou',
        ], ['user_id' => null]);

        self::assertSame(['395:debit:400.00', '315:credit:400.00'], $this->lines($first['entry_id']));
        $document = $this->row('SELECT kind, status, journal_entry_id, partner_name FROM internal_documents WHERE id = ?', [$first['internal_document_id']]);
        self::assertSame('offset', $document['kind']);
        self::assertSame('posted', $document['status']);
        self::assertSame($first['entry_id'], (int) $document['journal_entry_id']);
        self::assertSame('Syntetická pojišťovna', $document['partner_name']);

        $item = $this->items->get($this->supplierId, $itemId);
        self::assertSame(40000, $this->cents($item['paid_amount']));
        self::assertSame(60000, $this->cents($item['remaining_amount']));
        $allocation = $this->items->allocations($this->supplierId, $itemId)[0];
        self::assertSame($first['allocation_id'], (int) $allocation['id']);
        self::assertSame($first['entry_id'], $allocation['journal_entry_id']);
        self::assertSame($first['internal_document_id'], $allocation['internal_document_id']);
        self::assertSame('315', $allocation['offset_account_code']);
        self::assertSame('2099-02-10', $allocation['payment_on']);

        // Deník u zápisu zápočtu ukáže položku, kterou hradí.
        $links = $this->container->get(JournalLinkService::class);
        $entry = $this->container->get(JournalEntryRepository::class)->find($first['entry_id'], $this->supplierId);
        $related = array_values(array_filter($links->related($this->supplierId, $entry, JournalRelatedAccess::all())['items'],
            static fn (array $row): bool => $row['source_type'] === 'other_item'));
        self::assertCount(1, $related);
        self::assertSame($itemId, $related[0]['source_id']);
        self::assertSame(400.0, $related[0]['allocated_amount']);
        self::assertSame([$first['entry_id'] => true],
            $links->hasRelatedMap($this->supplierId, [$entry], JournalRelatedAccess::all()));

        $january = $this->account('315', '2099-01-31');
        self::assertSame(100000, $this->cents($january['open_items_total']), 'Zápočet platí od data zápisu.');
        self::assertTrue($january['matches']);
        $february = $this->account('315', '2099-02-28');
        self::assertSame(60000, $this->cents($february['gl_balance']));
        self::assertSame(60000, $this->cents($february['open_items_total']));
        self::assertTrue($february['matches']);

        $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('321'), 'date' => '2099-02-15', 'amount' => 600,
        ], ['user_id' => null]);
        $settled = $this->items->get($this->supplierId, $itemId);
        self::assertSame(0, $this->cents($settled['remaining_amount']));
        self::assertSame([], array_filter($this->items->list($this->supplierId, ['status' => 'open'], 1, 50)['items'],
            static fn (array $row): bool => (int) $row['id'] === $itemId));
        self::assertSame([], (new OtherItemForecastService($this->db))->dueBetween($this->supplierId, '2099-01-01', '2099-12-31'));
        $closed = $this->account('315', '2099-03-31');
        self::assertSame(0, $this->cents($closed['open_items_total']));
        self::assertTrue($closed['matches']);
    }

    public function testPayableOffsetDebitsItemAccount(): void
    {
        $itemId = $this->postedItem('payable', '325', '518');

        $result = $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('311'), 'date' => '2099-03-01', 'amount' => 1000,
        ], ['user_id' => null]);

        self::assertSame(['325:debit:1000.00', '311:credit:1000.00'], $this->lines($result['entry_id']));
        self::assertSame(0, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        $saldo = $this->account('325', '2099-03-31');
        self::assertSame(0, $this->cents($saldo['open_items_total']));
        self::assertTrue($saldo['matches']);
    }

    public function testCancelReversesInternalDocumentAndReopensItem(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $result = $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 1000,
        ], ['user_id' => null]);
        self::assertSame(0, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));

        $cancelled = $this->offsets->cancel($this->supplierId, $itemId, $result['allocation_id'],
            'Chybný zápočet', '2099-03-05', ['user_id' => null]);

        self::assertSame('2099-03-05', $cancelled['reversed_on']);
        self::assertNotNull($cancelled['reversal_entry_id']);
        self::assertSame('reversed', $this->row('SELECT status FROM internal_documents WHERE id = ?', [$result['internal_document_id']])['status']);
        self::assertSame(100000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        $february = $this->account('315', '2099-02-28');
        self::assertSame(0, $this->cents($february['open_items_total']), 'Před stornem zůstává položka v saldokontu vyrovnaná.');
        self::assertTrue($february['matches']);
        $march = $this->account('315', '2099-03-31');
        self::assertSame(100000, $this->cents($march['open_items_total']));
        self::assertTrue($march['matches']);

        $this->expectOtherItemError('offset_already_reversed', fn () => $this->offsets->cancel($this->supplierId, $itemId,
            $result['allocation_id'], 'Znovu', null, ['user_id' => null]));
        $this->expectOtherItemError('offset_cancel_required', fn () => $this->offsets->detach($this->supplierId, $itemId, $result['allocation_id']));
    }

    public function testAttachExistingJournalLineAndDetach(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 700.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 700.0],
        ], ['entry_date' => '2099-02-12', 'document_no' => 'ID-SYNT-1', 'description' => 'Zápočet pojistného plnění', 'posted' => true]);

        $candidates = $this->offsets->lineCandidates($this->supplierId, $itemId);
        self::assertCount(1, $candidates);
        self::assertSame($entryId, $candidates[0]['entry_id']);
        self::assertSame(70000, $this->cents($candidates[0]['free_amount']));

        $attached = $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $candidates[0]['line_id'], 'amount' => 300], null);
        self::assertSame($entryId, $attached['entry_id']);
        self::assertSame(70000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        self::assertSame(40000, $this->cents($this->offsets->lineCandidates($this->supplierId, $itemId)[0]['free_amount']),
            'Volná částka řádku se po přiřazení sníží.');
        $allocation = $this->items->allocations($this->supplierId, $itemId)[0];
        self::assertNull($allocation['internal_document_id']);
        self::assertSame('2099-02-12', $allocation['payment_on']);
        self::assertSame($entryId, $allocation['journal_entry_id']);

        $this->expectOtherItemError('offset_line_used', fn () => $this->offsets->attach($this->supplierId, $itemId,
            ['line_id' => $candidates[0]['line_id'], 'amount' => 100], null));
        $this->expectOtherItemError('offset_use_detach', fn () => $this->items->unallocate($this->supplierId, $itemId, $attached['allocation_id']));
        $this->expectOtherItemError('offset_not_own', fn () => $this->offsets->cancel($this->supplierId, $itemId,
            $attached['allocation_id'], 'Storno', null, ['user_id' => null]));
        $jsaId = (int) $this->row('SELECT journal_allocation_id FROM other_item_allocations WHERE id = ?', [$attached['allocation_id']])['journal_allocation_id'];
        try {
            $this->journalSettlements->delete($this->supplierId, $jsaId);
            self::fail('Rozpad zápočtu ostatní položky se nesmí rušit ze saldokonta.');
        } catch (JournalSettlementException $e) {
            self::assertSame('other_item_offset', $e->errorCode);
        }

        // Přiřazený zápis se nesmí tiše smazat ani přeúčtovat pod vyrovnáním.
        try {
            (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertEntriesFree($this->supplierId, [$entryId]);
            self::fail('Zápis s přiřazeným zápočtem musí být chráněný.');
        } catch (PostingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
        }

        $this->offsets->detach($this->supplierId, $itemId, $attached['allocation_id']);
        self::assertSame(100000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        self::assertSame([], $this->items->allocations($this->supplierId, $itemId));
        self::assertSame(0, (int) $this->row('SELECT COUNT(*) c FROM journal_settlement_allocations WHERE supplier_id = ? AND entry_id = ?',
            [$this->supplierId, $entryId])['c']);
    }

    public function testReversingAttachedEntryReopensItem(): void
    {
        $itemId = $this->postedItem('payable', '325', '518');
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '325', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '379', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $line['line_id'], 'amount' => 1000], null);
        self::assertSame(0, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));

        $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => '2099-04-01']);

        self::assertSame(100000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        self::assertSame('2099-04-01', $this->items->allocations($this->supplierId, $itemId)[0]['reversed_on']);
        self::assertTrue($this->account('325', '2099-04-30')['matches']);
    }

    public function testClosedPeriodAndInvalidInputAreRejected(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $this->expectOtherItemError('overpayment', fn () => $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 1000.01,
        ], ['user_id' => null]));
        $this->expectOtherItemError('offset_account_same', fn () => $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('315'), 'date' => '2099-02-10', 'amount' => 100,
        ], ['user_id' => null]));
        $this->expectOtherItemError('invalid_amount', fn () => $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 0,
        ], ['user_id' => null]));

        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        self::assertSame($entryId, $line['entry_id']);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);

        $this->expectOtherItemError('offset_period_closed', fn () => $this->offsets->attach($this->supplierId, $itemId,
            ['line_id' => $line['line_id'], 'amount' => 500], null));
        try {
            $this->offsets->offset($this->supplierId, $itemId, [
                'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 100,
            ], ['user_id' => null]);
            self::fail('Zápočet do uzavřeného období se nesmí zaúčtovat.');
        } catch (PostingException|OtherItemException) {
        }
        self::assertSame([], $this->items->allocations($this->supplierId, $itemId));
        self::assertSame(0, (int) $this->row("SELECT COUNT(*) c FROM internal_documents WHERE supplier_id = ? AND kind = 'offset'",
            [$this->supplierId])['c'], 'Odmítnutý zápočet nesmí nechat interní doklad.');
    }

    public function testTaxEvidenceRejectsOffset(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $this->expectOtherItemError('offset_double_entry_only', fn () => $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 100,
        ], ['user_id' => null]));
    }

    public function testOffsetCoversInstallmentAndKeepsMonthlyCheckBalanced(): void
    {
        $draft = $this->items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Syntetická půjčka',
            'issued_on' => '2099-01-10', 'accounting_on' => '2099-01-10', 'due_on' => '2099-03-05',
            'currency' => 'CZK', 'amount' => 1000, 'counter_account_code' => '221',
        ], null);
        $itemId = (int) $draft['id'];
        $this->container->get(OtherItemScheduleService::class)->setInstallments($this->supplierId, $itemId, [
            ['due_on' => '2099-02-05', 'amount' => 400],
            ['due_on' => '2099-03-05', 'amount' => 600],
        ]);
        $this->items->post($this->supplierId, $itemId, null);

        $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('379'), 'date' => '2099-02-01', 'amount' => 400,
        ], ['user_id' => null]);

        $coverage = $this->items->installmentCoverage($this->supplierId, $itemId);
        self::assertSame(['paid', 'unpaid'], array_column($coverage, 'status'));
        self::assertNotNull($this->items->allocations($this->supplierId, $itemId)[0]['installment_id']);
        $forecast = (new OtherItemForecastService($this->db))->dueBetween($this->supplierId, '2099-01-01', '2099-12-31');
        self::assertSame(['2099-03-05'], array_column($forecast, 'due_on'));
        self::assertSame([600.0], array_column($forecast, 'remaining'));
        $check = $this->container->get(OtherItemMonthlyCheck::class)->accountBalanceDifference($this->supplierId, '2099-02-28');
        self::assertNotNull($check);
        self::assertTrue($check['ok'], json_encode($check['value']));
    }

    public function testActionRequiresJournalPermissionsAndLogsActivity(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $action = $this->container->get(OtherItemOffsetAction::class);
        $call = function (string $method, array $permissions, array $body, array $args = []) use ($action, $itemId) {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/other-items/' . $itemId . '/offset')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'))
                ->withParsedBody($body);
            return $action->{$method}($request, new Response(), ['id' => (string) $itemId] + $args);
        };
        $body = ['account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 250];

        self::assertSame(403, $call('create', ['other_items' => 2], $body)->getStatusCode());
        self::assertSame([], $this->items->allocations($this->supplierId, $itemId));
        $created = $call('create', ['other_items' => 2, 'accounting.journal.post' => 2], $body);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(75000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
        $log = $this->row("SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'other_item.offset_created' AND entity_id = ?",
            [$this->supplierId, $itemId]);
        self::assertNotNull($log, 'Zápočet se zapíše do historie činností.');

        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 100.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 100.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        self::assertSame(403, $call('attach', ['other_items' => 2, 'accounting.journal.post' => 2],
            ['line_id' => $line['line_id'], 'amount' => 100])->getStatusCode());
        self::assertSame(201, $call('attach', ['other_items' => 2, 'accounting.journal.write' => 2],
            ['line_id' => $line['line_id'], 'amount' => 100])->getStatusCode());
        $error = $call('create', ['other_items' => 2, 'accounting.journal.post' => 2], array_replace($body, ['amount' => 9999]));
        self::assertSame(409, $error->getStatusCode());
        $error->getBody()->rewind();
        self::assertStringContainsString('other_items.error.overpayment', (string) $error->getBody());
    }

    public function testOffsetRowsAreVisibleOnlyWithJournalRead(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-02-10', 'amount' => 100,
        ], ['user_id' => null]);
        $action = $this->container->get(OtherItemAction::class);
        $list = function (array $permissions) use ($action, $itemId): array {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/accounting/other-items/' . $itemId . '/allocations')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
            $response = $action->allocations($request, new Response(), ['id' => (string) $itemId]);
            $response->getBody()->rewind();
            $body = json_decode((string) $response->getBody(), true);
            return ($body['data'] ?? $body)['items'];
        };

        self::assertSame([], $list(['other_items' => 1, 'cash' => 1, 'bank' => 1]), 'Zápočet bez práva číst deník nevidí.');
        $rows = $list(['other_items' => 1, 'accounting' => 1]);
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]['journal_allocation_id']);
        self::assertNotNull($rows[0]['journal_entry_id']);
    }

    public function testEntryDateChangeMovesOffsetPaymentDate(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 400.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 400.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0];
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $line['line_id'], 'amount' => 400], null);

        // Přepis zápisu (např. opakovaný převod) s novým datem: rozpad na faktury platí k datu
        // zápisu, úhrada položky zápočtem se proto posune s ním.
        $journal = $this->container->get(JournalEntryRepository::class);
        $entry = $journal->findForUpdate($entryId, $this->supplierId);
        $lines = array_map(static fn (array $l): array => [
            'account_id' => (int) $l['account_id'], 'side' => (string) $l['side'], 'amount' => (string) $l['amount'], 'line_no' => (int) $l['line_no'],
        ], $journal->linesForEntry($entryId, $this->supplierId, true));
        $journal->replace($entryId, [
            'supplier_id' => $this->supplierId, 'period_id' => (int) $entry['period_id'], 'entry_date' => '2099-03-20',
            'document_no' => $entry['document_no'], 'description' => $entry['description'], 'source_type' => 'manual',
            'source_id' => null, 'posted_at' => $entry['posted_at'], 'posted_by' => null,
        ], $lines);

        self::assertSame('2099-03-20', $this->items->allocations($this->supplierId, $itemId)[0]['payment_on']);
        $february = $this->account('315', '2099-02-28');
        self::assertSame(100000, $this->cents($february['open_items_total']));
        self::assertTrue($february['matches']);
        $check = $this->container->get(OtherItemMonthlyCheck::class)->accountBalanceDifference($this->supplierId, '2099-02-28');
        self::assertTrue($check['ok'], json_encode($check['value']));
        self::assertTrue($this->account('315', '2099-03-31')['matches']);
    }

    public function testCounterAccountMustBeBalanceSheetAccount(): void
    {
        $itemId = $this->postedItem('receivable', '315', '648');
        foreach (['518' => 'offset_account_not_balance', '648' => 'offset_account_not_balance', '751' => 'offset_account_not_balance',
                  '343' => 'offset_account_vat', '315' => 'offset_account_same'] as $code => $error) {
            $code = (string) $code;
            if ($this->row('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?', [$this->supplierId, $code]) === null) {
                continue;
            }
            $this->expectOtherItemError($error, fn () => $this->offsets->offset($this->supplierId, $itemId, [
                'account_id' => $this->accountId($code), 'date' => '2099-02-10', 'amount' => 100,
            ], ['user_id' => null]));
        }
        $this->expectOtherItemError('offset_date_before_item', fn () => $this->offsets->offset($this->supplierId, $itemId, [
            'account_id' => $this->accountId('395'), 'date' => '2099-01-09', 'amount' => 100,
        ], ['user_id' => null]));
        self::assertSame([], $this->items->allocations($this->supplierId, $itemId));
    }

    public function testEntryLinkedToPurchaseInvoiceStillOffersItsOtherItemLine(): void
    {
        // Zápočet MD 321 / D 315 navázaný na přijatou fakturu (zaúčtovanou na 321) vyrovná
        // fakturu řádkem 321, položku řádkem 315: vazba na fakturu řádek položky neblokuje.
        $itemId = $this->postedItem('receivable', '315', '648');
        $purchase = $this->purchaseInvoice();
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => '2099-01-20', 'posted' => true]);
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $this->link($entryId, 'purchase_invoice', $purchase);

        $line = $this->offsets->lineCandidates($this->supplierId, $itemId)[0] ?? null;
        self::assertNotNull($line);
        self::assertSame($entryId, $line['entry_id']);
        $this->offsets->attach($this->supplierId, $itemId, ['line_id' => $line['line_id'], 'amount' => 500], null);
        self::assertSame(50000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
    }

    public function testEntryLinkedToInvoiceOnItemAccountIsNotCountedTwice(): void
    {
        // Firma vede vydanou fakturu na 315: zápis MD 395 / D 315 navázaný jen na ni ji
        // saldokonto vyrovná celým pohybem na 315. Položce na 315 ho nabídnout nesmí.
        $itemId = $this->postedItem('receivable', '315', '648');
        $invoice = $this->issuedInvoice();
        $this->posting->postDocument($this->supplierId, 'invoice', $invoice, [
            ['account_code' => '315', 'side' => 'debit', 'amount' => 700.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 700.0],
        ], ['entry_date' => '2099-01-20', 'posted' => true]);
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '395', 'side' => 'debit', 'amount' => 700.0],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 700.0],
        ], ['entry_date' => '2099-02-12', 'posted' => true]);
        $lineId = (int) $this->row("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE l.entry_id = ? AND a.account_code = '315'", [$entryId])['id'];
        self::assertSame([$entryId], array_column($this->offsets->lineCandidates($this->supplierId, $itemId), 'entry_id'));

        $this->link($entryId, 'invoice', $invoice);

        self::assertSame([], $this->offsets->lineCandidates($this->supplierId, $itemId));
        $this->expectOtherItemError('offset_entry_linked', fn () => $this->offsets->attach($this->supplierId, $itemId,
            ['line_id' => $lineId, 'amount' => 700], null));
        self::assertSame(100000, $this->cents($this->items->get($this->supplierId, $itemId)['remaining_amount']));
    }

    private function link(int $entryId, string $docType, int $docId): void
    {
        $this->pdo->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $entryId, $docType, $docId]);
    }

    /** @return array{0:int,1:int,2:int} currency, client, user */
    private function partyContext(): array
    {
        $this->pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, "CZK", "Syntetická koruna", "Kč", "Koruna", "Crown")')
            ->execute([$this->supplierId]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_vendor, is_customer)
            VALUES (?, "Syntetický partner", "Test 1", "Praha", "11000", (SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1), "partner@example.invalid", "cs", ?, 1, 1)')
            ->execute([$this->supplierId, $currency]);
        $client = (int) $this->pdo->lastInsertId();
        return [$currency, $client, (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn()];
    }

    private function purchaseInvoice(): int
    {
        [$currency, $vendor, $user] = $this->partyContext();
        $this->pdo->prepare('INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                total_without_vat, total_vat, total_with_vat, status, vat_classification_code, tax_deductible, created_by)
            VALUES (?, ?, "PF-SYNT-1", "{}", "invoice", "none", "2099-01-20", "2099-01-20", "2099-02-20", "2099-01-20", ?, 1, 0, 0,
                500, 0, 500, "received", "40", 0, ?)')->execute([$this->supplierId, $vendor, $currency, $user]);
        return (int) $this->pdo->lastInsertId();
    }

    private function issuedInvoice(): int
    {
        [$currency, $client, $user] = $this->partyContext();
        $this->pdo->prepare('INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, status, created_by)
            VALUES (?, "2099000777", "invoice", ?, "2099-01-20", "2099-01-20", "2099-02-20", ?, 0, 700, 0, 700, "issued", ?)')
            ->execute([$this->supplierId, $client, $currency, $user]);
        return (int) $this->pdo->lastInsertId();
    }

    private function postedItem(string $side, string $account, string $counter): int
    {
        $draft = $this->items->create($this->supplierId, [
            'side' => $side, 'kind' => $side === 'receivable' ? 'insurance' : 'other',
            'title' => $side === 'receivable' ? 'Syntetické pojistné plnění' : 'Syntetický závazek',
            'partner_name' => 'Syntetická pojišťovna',
            'issued_on' => '2099-01-10', 'accounting_on' => '2099-01-10', 'due_on' => '2099-02-20',
            'currency' => 'CZK', 'amount' => 1000, 'account_code' => $account, 'counter_account_code' => $counter,
        ], null);
        $posted = $this->items->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame('posted', $posted['status']);
        return (int) $draft['id'];
    }

    private function accountId(string $code): int
    {
        $row = $this->row('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?', [$this->supplierId, $code]);
        self::assertNotNull($row, 'Účet ' . $code . ' chybí v osnově.');
        return (int) $row['id'];
    }

    /** @return list<string> */
    private function lines(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(a.account_code, ":", l.side, ":", CAST(l.amount AS CHAR))
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function account(string $code, string $date): array
    {
        foreach ($this->saldo->build($this->supplierId, $this->periodId, $date, $code)['accounts'] as $block) {
            if ($block['account']['code'] === $code) return $block;
        }
        self::fail('Účet ' . $code . ' nebyl v sestavě.');
    }

    private function expectOtherItemError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Očekávaná chyba ' . $code . '.');
        } catch (OtherItemException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function cents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
