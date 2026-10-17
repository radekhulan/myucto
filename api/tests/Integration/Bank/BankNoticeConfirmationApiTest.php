<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use MyInvoice\Action\Bank\BankNoticeConfirmationAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Reálné úhrady, izolovaný syntetický tenant, žádné tiché skipy prázdné DB. */
final class BankNoticeConfirmationApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private BankNoticeConfirmationService $service;
    private \Psr\Container\ContainerInterface $container;
    private int $supplier;
    private int $client;
    private int $currency;
    private int $user;
    private const DATE = '2099-06-15';
    private const ACCOUNT = '1000000005';

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Testovací DB musí mít syntetický seed.');
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET company_name='Synthetic notice confirmation', accounting_enabled=0 WHERE id=?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic notice','Kč','Koruna','Koruna',?,'0100')")->execute([$this->supplier, self::ACCOUNT]);
        $this->currency = (int) $pdo->lastInsertId();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,'Synthetic counterparty','Test 1','Test','11000',?,'',?)")->execute([$this->supplier, $country, $this->currency]);
        $this->client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (email,password_hash,name,role) VALUES (?,'synthetic-not-a-password','Synthetic notice tester','admin')")->execute(['notice-' . bin2hex(random_bytes(6)) . '@example.invalid']);
        $this->user = (int) $pdo->lastInsertId();
        $this->service = new BankNoticeConfirmationService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    private function movement(string $source, float $amount = 100.0, string $date = self::DATE, array $overrides = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_statements (supplier_id,source,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$overrides['supplier'] ?? $this->supplier, $source, 'Synthetic notice.' . $source, hash('sha256', random_bytes(20)), $overrides['account'] ?? self::ACCOUNT, $overrides['bank'] ?? '0100', $overrides['currency'] ?? 'CZK', $date]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO bank_transactions (statement_id,source,source_ref,posted_at,amount,currency,variable_symbol,counterparty_account,bank_ref,balance) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$statement, $source === 'email_notice' ? 'email_notice' : 'statement', $overrides['source_ref'] ?? 'synthetic:' . bin2hex(random_bytes(10)), $date, $amount, $overrides['currency'] ?? 'CZK', $overrides['vs'] ?? '2099157', $overrides['counterparty'] ?? null, $overrides['bank_ref'] ?? null, $overrides['balance'] ?? null]);
        return (int) $pdo->lastInsertId();
    }

    private function statement(int $tx): int
    {
        return (int) $this->db->pdo()->query('SELECT statement_id FROM bank_transactions WHERE id=' . $tx)->fetchColumn();
    }

    private function call(string $method, int $id, array $query = [], array $body = [], string $role = 'admin'): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => $role])
            ->withQueryParams($query)->withParsedBody($body);
        $class = in_array($method, ['check', 'confirm'], true) ? BankNoticeConfirmationAction::class : BankStatementAction::class;
        $response = $this->container->get($class)->$method($request, new Response(), ['id' => $id]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testPreviewIsReadOnlyAndApplyKeepsNumericHistoryAndGlobalCounts(): void
    {
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $id = $this->statement($notice);
        [$status, $preview] = $this->call('check', $id);
        self::assertSame(200, $status);
        self::assertSame(1, $preview['confirmation_summary']['pending']);
        self::assertSame(1, $preview['proposed_summary']['confirmed']);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
        [$status, $result] = $this->call('check', $id, body: ['dry_run' => false]);
        self::assertSame(200, $status);
        self::assertSame(1, $result['confirmation_summary']['confirmed']);
        [$status, $hidden] = $this->call('detail', $id);
        self::assertSame(200, $status);
        self::assertSame([], $hidden['transactions']);
        self::assertSame(1, $hidden['confirmation_summary']['confirmed']);
        [$status, $history] = $this->call('detail', $id, ['show_confirmed' => '1']);
        self::assertSame(200, $status);
        self::assertCount(1, $history['transactions']);
        $row = $history['transactions'][0];
        self::assertTrue(is_int($row['amount']) || is_float($row['amount']));
        self::assertSame($target, $row['authoritative_transaction_id']);
        self::assertArrayNotHasKey('processing_pending_at', $row);
        self::assertArrayNotHasKey('processing_error', $row);
        self::assertTrue($history['can_delete_notices']);
    }

    public function testUnlinkedNoticeCanBeDeletedAndConfirmationRequiresWritePermission(): void
    {
        $notice = $this->movement('email_notice');
        $statement = $this->statement($notice);
        self::assertSame(200, $this->call('delete', $statement)[0]);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
        $notice = $this->movement('email_notice');
        $statement = $this->statement($notice);
        self::assertSame(403, $this->call('check', $statement, role: 'readonly')[0]);
        self::assertSame(404, $this->call('check', PHP_INT_MAX)[0]);
        self::assertSame(422, $this->call('confirm', $notice, body: ['reason' => str_repeat('a', 501)])[0]);
    }

    public function testDeletingConfirmedNoticeRetainsTombstoneAndWarnsBeforeAuthorityDeletion(): void
    {
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $statement = $this->statement($notice);
        $official = $this->statement($target);
        self::assertSame(200, $this->call('check', $statement, body: ['dry_run' => false])[0]);
        self::assertSame(409, $this->call('ignore', $notice)[0]);
        self::assertSame(200, $this->call('delete', $statement)[0]);
        self::assertSame($official, $this->statement($target));
        $record = $this->db->pdo()->query('SELECT * FROM bank_notice_confirmations WHERE authoritative_transaction_id=' . $target)->fetch(PDO::FETCH_ASSOC);
        self::assertNull($record['notice_transaction_id']);
        self::assertNotNull($record['deleted_at']);
        [$status, $detail] = $this->call('detail', $official);
        self::assertSame(200, $status);
        self::assertSame(1, $detail['deleted_notice_confirmations']);
        [$status, $deleted] = $this->call('delete', $official);
        self::assertSame(200, $status);
        self::assertSame(1, $deleted['deleted_notice_confirmations']);
        $record = $this->db->pdo()->query('SELECT * FROM bank_notice_confirmations WHERE id=' . (int) $record['id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame('review', $record['state']);
        self::assertSame('authoritative_removed', $record['reason']);
        self::assertNull($record['authoritative_transaction_id']);
    }

    public function testPersistedPendingAndReviewFiltersAgreeWithCheckResult(): void
    {
        $notice = $this->movement('email_notice');
        $this->movement('gpc');
        $this->movement('gpc');
        $statement = $this->statement($notice);
        [$status, $detail] = $this->call('detail', $statement, ['confirmation_state' => 'pending']);
        self::assertSame(200, $status);
        self::assertCount(1, $detail['transactions']);
        self::assertSame('pending', $detail['transactions'][0]['confirmation_state']);
        self::assertSame(200, $this->call('check', $statement, body: ['dry_run' => false])[0]);
        [$status, $detail] = $this->call('detail', $statement, ['confirmation_state' => 'review']);
        self::assertSame(200, $status);
        self::assertCount(1, $detail['transactions']);
        self::assertSame(1, $detail['confirmation_summary']['review']);
        foreach ($detail['transactions'][0]['candidates'] as $candidate) {
            self::assertTrue(is_int($candidate['amount']) || is_float($candidate['amount']));
            self::assertArrayNotHasKey('processing_error', $candidate);
        }
    }
    public function testActiveMovementCanStillBeIgnoredAndNoticeDeletionRequiresBankWrite(): void
    {
        $notice = $this->movement('email_notice');
        [$status, $result] = $this->call('ignore', $notice, body: ['note' => 'Synthetic checked notice']);
        self::assertSame(200, $status);
        self::assertTrue($result['ignored']);
        self::assertSame('ignored', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        $statement = $this->statement($notice);
        self::assertSame(403, $this->call('delete', $statement, role: 'readonly')[0]);
        self::assertSame(200, $this->call('delete', $statement, role: 'accountant')[0]);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
    }

}
