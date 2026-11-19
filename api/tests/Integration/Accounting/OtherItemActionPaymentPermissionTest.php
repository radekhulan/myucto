<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use Psr\Container\ContainerInterface;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OtherItemActionPaymentPermissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemAction $action;
    private OtherItemService $service;
    private int $supplierId;
    private int $itemId;
    private int $cashId;
    private int $bankId;
    private int $statementId;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->action = $container->get(OtherItemAction::class);
        $this->service = $container->get(OtherItemService::class);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $item = $this->service->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetický nájem',
            'issued_on' => '2099-01-01', 'due_on' => '2099-01-20', 'amount' => 1200,
            'currency' => 'CZK',
        ], null);
        $this->itemId = (int) $item['id'];
        $this->service->post($this->supplierId, $this->itemId, null);
        $this->pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, 'Syntetická pokladna', 'CZK', '211')"
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number,
                                         issue_date, description, total_amount, currency_code, status)
             VALUES (?, ?, 'out', 'other', 'SYNTH-CASH-OTHER-1', '2099-01-21',
                     'Syntetická hotovostní platba', 500, 'CZK', 'posted')"
        )->execute([$this->supplierId, $registerId]);
        $this->cashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005/0100', '2099-01-20', 'CZK']);
        $this->statementId = $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, '2099-01-20', -400, 'CZK', 'Syntetická bankovní platba')"
        )->execute([$statementId]);
        $this->bankId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testCandidatesRespectEachSourceReadPermission(): void
    {
        $bankOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'bank' => 1,
        ]));
        self::assertSame(['bank'], array_values(array_unique(array_column($bankOnly['items'], 'source'))));
        self::assertSame([$this->bankId], array_column($bankOnly['items'], 'id'));
        self::assertSame([$this->bankId], array_column($this->data($this->call('paymentCandidates', 'GET',
            ['other_items' => 1, 'bank' => 1], [], null, ['limit' => 1]))['items'], 'id'));

        $cashOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'cash' => 1,
        ]));
        self::assertSame(['cash'], array_values(array_unique(array_column($cashOnly['items'], 'source'))));
        self::assertSame([$this->cashId], array_column($cashOnly['items'], 'id'));
        self::assertSame(403, $this->call('paymentCandidates', 'GET', ['other_items' => 1])->getStatusCode());
    }

    public function testCashAllocationAndRemovalRequireCashDocumentWrite(): void
    {
        $bankMatcher = ['other_items' => 2, 'bank' => 1, 'bank.match' => 2];
        $cashWriter = ['other_items' => 2, 'cash' => 1, 'cash.document.write' => 2];
        $payload = ['cash_document_id' => $this->cashId, 'amount' => 200];

        self::assertSame(403, $this->call('allocate', 'POST', $bankMatcher, $payload)->getStatusCode());
        self::assertSame(403, $this->call('allocate', 'POST', $cashWriter,
            ['bank_transaction_id' => $this->bankId, 'amount' => 100])->getStatusCode());
        self::assertSame([], $this->service->allocations($this->supplierId, $this->itemId));

        $allocated = $this->call('allocate', 'POST', $cashWriter, $payload);
        self::assertSame(200, $allocated->getStatusCode());
        $allocationId = (int) $this->service->allocations($this->supplierId, $this->itemId)[0]['id'];
        self::assertSame([], $this->data($this->call('allocations', 'GET', $bankMatcher))['items']);

        self::assertSame(403, $this->call('unallocate', 'DELETE', $bankMatcher, [], $allocationId)->getStatusCode());
        self::assertCount(1, $this->service->allocations($this->supplierId, $this->itemId));
        self::assertSame(200, $this->call('unallocate', 'DELETE', $cashWriter, [], $allocationId)->getStatusCode());

        self::assertSame(200, $this->call('allocate', 'POST', $bankMatcher,
            ['bank_transaction_id' => $this->bankId, 'amount' => 100])->getStatusCode());
        $bankAllocationId = (int) $this->service->allocations($this->supplierId, $this->itemId)[0]['id'];
        self::assertSame(403, $this->call('unallocate', 'DELETE', $cashWriter, [], $bankAllocationId)->getStatusCode());
        self::assertSame(200, $this->call('unallocate', 'DELETE', $bankMatcher, [], $bankAllocationId)->getStatusCode());
    }

    public function testAllocationsLinkStatementCashDocumentAndLiveJournalEntry(): void
    {
        $periodId = $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $entry = $this->pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, reversed_by, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $posted = '2099-01-20 10:00:00';
        $entry->execute([$this->supplierId, $periodId, '2099-01-20', 'manual', null, null, $posted]);
        $reversalId = (int) $this->pdo->lastInsertId();
        $entry->execute([$this->supplierId, $periodId, '2099-01-20', 'bank', $this->bankId, $reversalId, $posted]);
        $entry->execute([$this->supplierId, $periodId, '2099-01-20', 'bank', $this->bankId, null, $posted]);
        $liveId = (int) $this->pdo->lastInsertId();
        // Pokladní doklad má zatím jen koncept zápisu (nezaúčtovaný návrh).
        $entry->execute([$this->supplierId, $periodId, '2099-01-21', 'cash', $this->cashId, null, null]);
        $cashEntryId = (int) $this->pdo->lastInsertId();
        $this->service->allocate($this->supplierId, $this->itemId, ['bank_transaction_id' => $this->bankId, 'amount' => 100], null);
        $this->service->allocate($this->supplierId, $this->itemId, ['cash_document_id' => $this->cashId, 'amount' => 200], null);
        $reader = ['other_items' => 1, 'bank' => 1, 'cash' => 1];

        $rows = $this->data($this->call('allocations', 'GET', $reader + ['accounting' => 1]))['items'];

        $bank = array_values(array_filter($rows, static fn (array $row): bool => $row['bank_transaction_id'] !== null))[0];
        $cash = array_values(array_filter($rows, static fn (array $row): bool => $row['cash_document_id'] !== null))[0];
        self::assertSame($this->statementId, $bank['bank_statement_id']);
        self::assertSame($liveId, $bank['journal_entry_id'], 'Odkaz vede na živý zápis, ne na stornovaný.');
        self::assertNull($bank['cash_doc_number']);
        self::assertSame('SYNTH-CASH-OTHER-1', $cash['cash_doc_number']);
        self::assertNull($cash['bank_statement_id']);
        self::assertNull($cash['journal_entry_id'], 'Koncept zápisu není zaúčtování, odkaz nevede nikam.');

        $withoutJournal = $this->data($this->call('allocations', 'GET', $reader))['items'];
        self::assertSame([null, null], array_column($withoutJournal, 'journal_entry_id'), 'Bez práva k deníku bez odkazu do něj.');
        self::assertContains($this->statementId, array_column($withoutJournal, 'bank_statement_id'));

        // Po zaúčtování pokladního dokladu vede odkaz na jeho zápis.
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = ? WHERE id = ?')->execute([$posted, $cashEntryId]);
        // Stornovaná bankovní úhrada odkaz ztratí: živý zápis pohybu už může patřit jinému dokladu.
        $this->pdo->prepare("UPDATE other_item_allocations SET reversed_on = '2099-01-25' WHERE supplier_id = ? AND bank_transaction_id = ?")
            ->execute([$this->supplierId, $this->bankId]);
        $rows = $this->data($this->call('allocations', 'GET', $reader + ['accounting' => 1]))['items'];
        $byKind = [];
        foreach ($rows as $row) $byKind[$row['bank_transaction_id'] !== null ? 'bank' : 'cash'] = $row['journal_entry_id'];
        self::assertSame(['bank' => null, 'cash' => $cashEntryId], $byKind);
    }

    public function testCashOnlyCandidateSurvivesMoreThanFiftyNewerBankPayments(): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM bank_statements WHERE supplier_id = ? LIMIT 1');
        $statement->execute([$this->supplierId]);
        $statementId = (int) $statement->fetchColumn();
        $insert = $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, '2099-01-22', -10, 'CZK', 'Syntetická novější bankovní platba')"
        );
        for ($i = 0; $i < 55; $i++) $insert->execute([$statementId]);

        $cashOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'cash' => 1,
        ]));
        self::assertSame([$this->cashId], array_column($cashOnly['items'], 'id'));
        self::assertSame(['cash'], array_column($cashOnly['items'], 'source'));
    }

    public function testListAllowsOpenEndedFutureDueDateFilter(): void
    {
        $response = $this->call('list', 'GET', ['other_items' => 1], [], null,
            ['from' => '2099-01-01']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->data($response)['total']);

        $response = $this->call('list', 'GET', ['other_items' => 1], [], null,
            ['to' => '2020-01-01']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->data($response)['total']);
    }

    public function testOpenEndedDueDateFiltersIncludeDerivedTaxAdvances(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date)
             VALUES (?, "po", "tax", ?, 1, 1200.00, ?)'
        );
        $insert->execute([$this->supplierId, 2024, '2024-06-15']);
        $pastId = (int) $this->pdo->lastInsertId();
        $insert->execute([$this->supplierId, 2099, '2099-06-15']);
        $futureId = (int) $this->pdo->lastInsertId();

        $permissions = ['other_items' => 1, 'reports' => 1];
        $past = $this->data($this->call('list', 'GET', $permissions, [], null,
            ['to' => '2024-12-31']));
        self::assertContains('tax_advance:' . $pastId, array_column($past['sources'], 'id'));

        $future = $this->data($this->call('list', 'GET', $permissions, [], null,
            ['from' => '2099-01-01']));
        self::assertContains('tax_advance:' . $futureId, array_column($future['sources'], 'id'));
    }

    private function call(string $method, string $http, array $permissions, array $body = [],
        ?int $allocationId = null, array $query = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($http, '/api/accounting/other-items/' . $this->itemId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'))
            ->withQueryParams($query)
            ->withParsedBody($body);
        $args = ['id' => (string) $this->itemId];
        if ($allocationId !== null) $args['allocation_id'] = (string) $allocationId;
        return $this->action->{$method}($request, new Response(), $args);
    }

    private function data(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }
}
