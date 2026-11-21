<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Vystavená ostatní pohledávka či závazek: doplnění poznámky s auditem a smazání
 * zaúčtovaného dokladu i se zápisem v deníku za stejných podmínek jako mazání zápisu
 * v deníku (otevřené a nezamčené období, bez úhrad a navazujících vazeb).
 */
#[Group('integration')]
final class OtherItemPostedChangesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERMS = ['other_items' => 2, 'accounting' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2];

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $service;
    private OtherItemAction $action;
    private int $supplierId;
    private int $periodId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje místní databázi.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->service = $this->container->get(OtherItemService::class);
        $this->action = $this->container->get(OtherItemAction::class);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testNoteOfPostedItemIsEditableWithAuditAndAccountingStaysLocked(): void
    {
        $posted = $this->posted();
        $id = (int) $posted['id'];
        $entryId = (int) $posted['journal_entry_id'];
        $linesBefore = $this->lines($entryId);

        $response = $this->action->updateText($this->request('PUT', ['other_items' => 2])
            ->withParsedBody(['note' => 'Doplněná poznámka', 'amount' => 1, 'title' => 'Jiný název']), new Response(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode());
        $item = $this->data($response);
        self::assertSame('Doplněná poznámka', $item['note']);
        self::assertSame('Syntetické nájemné', $item['title']);
        self::assertEquals(1200, $item['amount']);
        self::assertSame('posted', $item['status']);
        self::assertSame($linesBefore, $this->lines($entryId));

        $log = $this->pdo->prepare("SELECT payload FROM activity_log WHERE entity_type = 'other_item' AND entity_id = ?
            AND action = 'other_item.text_updated'");
        $log->execute([$id]);
        self::assertSame(['before' => null, 'after' => 'Doplněná poznámka'], json_decode((string) $log->fetchColumn(), true)['note'] ?? null);

        // Poznámka nemění účetnictví, proto jde doplnit i po uzávěrce a u stornovaného dokladu.
        $this->container->get(AccountingPeriodRepository::class)->setStatus($this->periodId, $this->supplierId, 'closed');
        self::assertSame('Po uzávěrce', $this->service->updateNote($this->supplierId, $id, 'Po uzávěrce', null)['item']['note']);

        $draft = $this->service->create($this->supplierId, $this->input(), null);
        try {
            $this->service->updateNote($this->supplierId, (int) $draft['id'], 'x', null);
            self::fail('Koncept se upravuje v editaci.');
        } catch (OtherItemException $e) {
            self::assertSame('is_draft', $e->errorCode);
        }
        self::assertSame(403, $this->action->updateText($this->request('PUT', ['other_items' => 1])->withParsedBody(['note' => 'x']),
            new Response(), ['id' => (string) $id])->getStatusCode());
    }

    public function testAccrualOfPostedReceivableIsEditableWithAudit(): void
    {
        $draft = $this->service->create($this->supplierId, ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602'] + $this->input(), null);
        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);
        $id = (int) $posted['id'];
        $linesBefore = $this->lines((int) $posted['journal_entry_id']);

        $empty = $this->action->updateAccrual($this->request('PUT', ['other_items' => 2])->withParsedBody(['note' => 'x']),
            new Response(), ['id' => (string) $id]);
        self::assertSame(422, $empty->getStatusCode());
        self::assertSame('other_items.error.validation_failed', $this->error($empty));

        $response = $this->action->updateAccrual($this->request('PUT', ['other_items' => 2])
            ->withParsedBody(['accrual_from' => '2099-07-01', 'accrual_to' => '2100-06-30', 'amount' => 1]), new Response(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode());
        $item = $this->data($response);
        self::assertSame('2099-07-01', substr((string) $item['accrual_from'], 0, 10));
        self::assertEquals(1200, $item['amount']);
        self::assertSame($linesBefore, $this->lines((int) $posted['journal_entry_id']));

        $log = $this->pdo->prepare("SELECT payload FROM activity_log WHERE entity_type = 'other_item' AND entity_id = ?
            AND action = 'other_item.accrual_changed'");
        $log->execute([$id]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame(['before' => null, 'after' => '2099-07-01'], $payload['accrual_from'] ?? null);
        self::assertSame(['before' => null, 'after' => '2100-06-30'], $payload['accrual_to'] ?? null);

        // Stejné období znovu: odpověď 200, ale v aktivitě žádný další záznam.
        self::assertSame(200, $this->action->updateAccrual($this->request('PUT', ['other_items' => 2])
            ->withParsedBody(['accrual_from' => '2099-07-01', 'accrual_to' => '2100-06-30']), new Response(), ['id' => (string) $id])->getStatusCode());
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE entity_type = 'other_item' AND entity_id = ?
            AND action = 'other_item.accrual_changed'");
        $count->execute([$id]);
        self::assertSame(1, (int) $count->fetchColumn());

        self::assertSame(403, $this->action->updateAccrual($this->request('PUT', ['other_items' => 1])
            ->withParsedBody(['accrual_from' => null, 'accrual_to' => null]), new Response(), ['id' => (string) $id])->getStatusCode());
    }

    /** Změna období v editaci konceptu se zapíše do aktivity stejně jako přes route `/accrual`. */
    public function testDraftPeriodChangeIsAudited(): void
    {
        $draft = $this->service->create($this->supplierId, ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602'] + $this->input(), null);
        $this->service->update($this->supplierId, (int) $draft['id'], ['accrual_from' => '2099-07-01', 'accrual_to' => '2100-06-30'], $this->userId);

        $log = $this->pdo->prepare("SELECT payload, supplier_id FROM activity_log WHERE entity_type = 'other_item' AND entity_id = ?
            AND action = 'other_item.accrual_changed'");
        $log->execute([(int) $draft['id']]);
        $row = $log->fetch(\PDO::FETCH_ASSOC);
        $payload = json_decode((string) $row['payload'], true);
        self::assertSame(['before' => null, 'after' => '2100-06-30'], $payload['accrual_to'] ?? null);
        self::assertSame($this->supplierId, (int) $row['supplier_id'], 'Záznam patří firmě (filtr aktivity podle firmy).');
    }

    public function testDeletingPostedItemRemovesItemAndEntry(): void
    {
        $posted = $this->posted();
        $id = (int) $posted['id'];
        $entryId = (int) $posted['journal_entry_id'];

        $preview = $this->data($this->action->get($this->request('GET', ['other_items' => 1]), new Response(), ['id' => (string) $id]))['posted_deletion'];
        self::assertTrue($preview['allowed']);
        $denied = $this->action->deletePosted($this->request('DELETE', ['other_items' => 2, 'accounting' => 2, 'accounting.journal.write' => 2]),
            new Response(), ['id' => (string) $id]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('posted', $this->service->get($this->supplierId, $id)['status']);

        $response = $this->action->deletePosted($this->request('DELETE', self::PERMS), new Response(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('deleted', $this->data($response)['status']);
        try {
            $this->service->get($this->supplierId, $id);
            self::fail('Položka musí být smazaná.');
        } catch (OtherItemException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        $entries = $this->pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $entries->execute([$this->supplierId]);
        self::assertSame(0, (int) $entries->fetchColumn());
        $lines = $this->pdo->prepare('SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ?');
        $lines->execute([$this->supplierId]);
        self::assertSame(0, (int) $lines->fetchColumn());

        $log = $this->pdo->prepare("SELECT action, payload FROM activity_log WHERE supplier_id = ?
            AND ((entity_type = 'other_item' AND entity_id = ?) OR (entity_type = 'journal_entry' AND entity_id = ?))");
        $log->execute([$this->supplierId, $id, $entryId]);
        $byAction = array_column($log->fetchAll(PDO::FETCH_ASSOC), 'payload', 'action');
        self::assertSame('other_item_delete', json_decode((string) ($byAction['accounting.entry_deleted'] ?? 'null'), true)['reason'] ?? null);
        self::assertSame($posted['document_no'], json_decode((string) ($byAction['other_item.deleted_posted'] ?? 'null'), true)['document_no'] ?? null);
    }

    public function testDeletingPostedItemRespectsClosedAndLockedPeriod(): void
    {
        $posted = $this->posted();
        $args = ['id' => (string) $posted['id']];
        $this->pdo->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, '2099-06-30']);
        $locked = $this->action->deletePosted($this->request('DELETE', self::PERMS), new Response(), $args);
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame('date_locked', $this->error($locked));
        self::assertSame('posted', $this->service->get($this->supplierId, (int) $posted['id'])['status']);

        $this->pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->container->get(AccountingPeriodRepository::class)->setStatus($this->periodId, $this->supplierId, 'closed');
        $closed = $this->action->deletePosted($this->request('DELETE', self::PERMS)->withQueryParams(['ack_locked' => '1']),
            new Response(), $args);
        self::assertSame(409, $closed->getStatusCode());
        self::assertSame('period_not_open', $this->error($closed));
        $item = $this->service->get($this->supplierId, (int) $posted['id']);
        self::assertSame('posted', $item['status']);
        self::assertSame((int) $posted['journal_entry_id'], (int) $item['journal_entry_id']);
    }

    public function testDeletingPostedItemRefusesPaymentsOrdersAndRepostHistory(): void
    {
        $paid = $this->posted();
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), '1000000005/0100', '2099-01-20', 'CZK']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-01-20', -1200, 'CZK')")
            ->execute([$statementId]);
        $this->pdo->prepare(
            "INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
             VALUES (?, ?, ?, 1200, '2099-01-20')"
        )->execute([$this->supplierId, (int) $paid['id'], (int) $this->pdo->lastInsertId()]);
        $this->assertRefused((int) $paid['id'], 'has_payments');

        $ordered = $this->posted();
        $this->pdo->prepare("INSERT INTO payment_orders (supplier_id, payment_date) VALUES (?, '2099-01-20')")->execute([$this->supplierId]);
        $this->pdo->prepare('INSERT INTO payment_order_items (payment_order_id, other_item_id, amount) VALUES (?, ?, 1200)')
            ->execute([(int) $this->pdo->lastInsertId(), (int) $ordered['id']]);
        $this->assertRefused((int) $ordered['id'], 'in_payment_order');

        $reposted = $this->posted();
        $this->service->repost($this->supplierId, (int) $reposted['id'],
            ['entry_date' => '2099-01-05', 'reason' => 'Oprava protiúčtu', 'counter_account_code' => '511'], null);
        $this->assertRefused((int) $reposted['id'], 'has_posting_history');

        $this->service->reverse($this->supplierId, (int) $ordered['id'], 'Oprava podkladu', null);
        $this->assertRefused((int) $ordered['id'], 'not_posted');
    }

    private function assertRefused(int $id, string $code): void
    {
        $entries = fn (): int => (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}")->fetchColumn();
        $before = $entries();
        $preview = $this->data($this->action->get($this->request('GET', ['other_items' => 1]), new Response(), ['id' => (string) $id]))['posted_deletion'] ?? null;
        if ($code !== 'not_posted') {
            self::assertFalse($preview['allowed'] ?? true, $code);
            self::assertSame($code, $preview['code'] ?? null);
        } else {
            self::assertNull($preview);
        }
        $response = $this->action->deletePosted($this->request('DELETE', self::PERMS), new Response(), ['id' => (string) $id]);
        self::assertSame(409, $response->getStatusCode(), $code);
        self::assertSame('other_items.error.' . $code, $this->error($response));
        self::assertSame($before, $entries());
        $this->service->get($this->supplierId, $id);
    }

    private function posted(): array
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        return $this->service->post($this->supplierId, (int) $draft['id'], null);
    }

    private function input(): array
    {
        return [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ];
    }

    /** @return list<string> */
    private function lines(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(a.account_code, ":", l.side, ":", CAST(l.amount AS CHAR))
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.id'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function request(string $method, array $permissions)
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/accounting/other-items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    private function data(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }

    private function error(ResponseInterface $response): ?string
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }
}
