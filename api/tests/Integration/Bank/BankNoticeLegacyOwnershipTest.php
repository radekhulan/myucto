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
final class BankNoticeLegacyOwnershipTest extends TestCase
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
    private const ACCOUNT = '1000000021';

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

    private function makeLegacy(int $transactionId): int
    {
        $statement = $this->statement($transactionId);
        $this->db->pdo()->prepare('UPDATE bank_statements SET supplier_id=NULL WHERE id=?')->execute([$statement]);
        return $statement;
    }

    public function testUniqueLegacyNoticeDetailAndUnlinkedDeletionRemainAvailable(): void
    {
        $notice = $this->movement('email_notice');
        $statement = $this->makeLegacy($notice);
        [$status, $detail] = $this->call('detail', $statement);
        self::assertSame(200, $status);
        self::assertSame(1, $detail['confirmation_summary']['pending']);
        self::assertSame('pending', $detail['transactions'][0]['confirmation_state']);
        self::assertFalse($detail['transactions'][0]['confirmation_record_exists']);
        self::assertTrue($detail['can_delete_notices']);
        self::assertSame(200, $this->call('delete', $statement)[0]);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
    }

    public function testUniqueLegacyNoticeWithoutLinksCanBeDeletedDirectly(): void
    {
        $notice = $this->movement('email_notice');
        $statement = $this->makeLegacy($notice);
        self::assertSame(200, $this->call('delete', $statement)[0]);
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
    }

    public function testAmbiguousLegacyNoticeIsHiddenFromBothTenants(): void
    {
        $notice = $this->movement('email_notice');
        $statement = $this->makeLegacy($notice);
        $pdo = $this->db->pdo();
        $other = $this->createIsolatedSupplier($pdo, $this->supplier);
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic ambiguous','Kč','Koruna','Koruna',?,'0100')")
            ->execute([$other, self::ACCOUNT]);
        $original = $this->supplier;
        foreach ([$original, $other] as $supplier) {
            $this->supplier = $supplier;
            self::assertSame(404, $this->call('detail', $statement)[0]);
            self::assertSame(404, $this->call('delete', $statement)[0]);
            self::assertSame([], (new \MyInvoice\Service\Bank\BankNoticeConfirmationReader($this->db))->notices($supplier, [$notice]));
        }
        $this->supplier = $original;
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
    }

    public function testManualConfirmationUsesUniqueLegacyOwnershipWithoutBackfillingHeaders(): void
    {
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $noticeStatement = $this->makeLegacy($notice);
        $targetStatement = $this->makeLegacy($target);
        [$status, $result] = $this->call('confirm', $notice, body: ['authoritative_transaction_id' => $target, 'reason' => 'Synthetic legacy statement checked']);
        self::assertSame(200, $status);
        self::assertSame('confirmed', $result['confirmation_state']);
        self::assertSame($target, $result['authoritative_transaction_id']);
        $pdo = $this->db->pdo();
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM bank_statements WHERE supplier_id IS NULL AND id IN (' . $noticeStatement . ',' . $targetStatement . ')')->fetchColumn());
        [$status, $detail] = $this->call('detail', $noticeStatement, ['show_confirmed' => true]);
        self::assertSame(200, $status);
        self::assertSame('confirmed', $detail['transactions'][0]['confirmation_state']);
        self::assertTrue($detail['transactions'][0]['confirmation_record_exists']);
        self::assertSame([$targetStatement], $detail['transactions'][0]['authoritative_statement_ids']);
    }
}
