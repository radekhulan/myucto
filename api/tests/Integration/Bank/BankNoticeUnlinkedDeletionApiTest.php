<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reálné úhrady, izolovaný syntetický tenant, žádné tiché skipy prázdné DB. */
final class BankNoticeUnlinkedDeletionApiTest extends TestCase
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
        $pdo->prepare("UPDATE supplier SET company_name='Synthetic notice confirmation', accounting_enabled=0, accounting_mode='tax_evidence' WHERE id=?")->execute([$this->supplier]);
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

    private function invoice(float $amount = 100.0, string $type = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoices (supplier_id,client_id,currency_id,invoice_type,varsymbol,issue_date,tax_date,due_date,status,total_without_vat,total_with_vat,paid_total,created_by) VALUES (?,?,?,?,?,?,?,?,'issued',?,?,?,?)")
            ->execute([$this->supplier, $this->client, $this->currency, $type, '2099' . random_int(100000, 999999), self::DATE, self::DATE, self::DATE, $amount, $amount, $amount, $this->user]);
        return (int) $pdo->lastInsertId();
    }

    private function payment(int $tx, int $invoice, float $amount, ?int $taxDocument = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id,invoice_id,paid_on,amount,currency,source,bank_transaction_id,tax_document_invoice_id) VALUES (?,?,?,?,'CZK','bank',?,?)")
            ->execute([$this->supplier, $invoice, self::DATE, $amount, $tx, $taxDocument]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE bank_transactions SET match_status='manual',matched_invoice_id=?,matched_by=?,matched_at=NOW() WHERE id=?")->execute([$invoice, $this->user, $tx]);
        return $id;
    }

    private function delete(int $statement): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/bank-statements/' . $statement)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin']);
        $response = $this->container->get(BankStatementAction::class)->delete($request, new Response(), ['id' => $statement]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public static function unlinkedNotices(): array
    {
        return [
            'empty notice overview' => ['empty'],
            'unconfirmed notice with existing statement' => ['unchecked'],
            'account without any bank statements' => ['no_statement'],
            'incorrect imported notice' => ['incorrect'],
        ];
    }

    #[DataProvider('unlinkedNotices')]
    public function testUnlinkedNoticeOverviewCanActuallyBeDeletedWithoutConfirmation(string $scenario): void
    {
        $pdo = $this->db->pdo();
        $notice = $this->movement('email_notice', $scenario === 'incorrect' ? 1234.56 : 100, overrides: ['vs' => $scenario === 'incorrect' ? '9999999999' : '157']);
        $statement = $this->statement($notice);
        if ($scenario === 'empty') $pdo->prepare('DELETE FROM bank_transactions WHERE id=?')->execute([$notice]);
        if ($scenario === 'unchecked') $this->movement('gpc', 500, overrides: ['vs' => 'different']);
        if ($scenario === 'incorrect') {
            $pdo->prepare("UPDATE bank_transactions SET match_status='ignored',ignore_note='Synthetic incorrect notice' WHERE id=?")->execute([$notice]);
        }
        $inspection = $this->service->inspectStatement($this->supplier, $statement);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
        [$status] = $this->delete($statement);
        self::assertSame(200, $status);
        self::assertTrue($inspection['can_delete_notices']);
        self::assertSame([], $inspection['deletion_blockers']);
        self::assertSame($scenario === 'empty' ? 0 : 1, $inspection['confirmation_summary']['pending']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM bank_statements WHERE id=' . $statement)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action='bank.statement_deleted' AND supplier_id=" . $this->supplier . ' AND entity_id=' . $statement)->fetchColumn());
        if ($scenario === 'no_statement') {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM bank_statements WHERE supplier_id=' . $this->supplier)->fetchColumn());
        }
    }

    public static function linkedNotices(): array
    {
        return ['invoice payment' => ['payment'], 'document request' => ['document_request'], 'cash journal note' => ['journal_note']];
    }

    #[DataProvider('linkedNotices')]
    public function testUnconfirmedLinkedNoticeCannotBeDeleted(string $dependency): void
    {
        $pdo = $this->db->pdo();
        $notice = $this->movement('email_notice');
        $statement = $this->statement($notice);
        $payment = null;
        if ($dependency === 'payment') $payment = $this->payment($notice, $this->invoice(), 100);
        elseif ($dependency === 'document_request') $pdo->prepare("INSERT INTO document_requests (supplier_id,description,bank_transaction_id) VALUES (?,'Synthetic request',?)")->execute([$this->supplier, $notice]);
        else $pdo->prepare("INSERT INTO cash_journal_notes (supplier_id,source_type,source_id,body) VALUES (?,'bank',?,'Synthetic note')")->execute([$this->supplier, $notice]);
        $inspection = $this->service->inspectStatement($this->supplier, $statement);
        self::assertFalse($inspection['can_delete_notices']);
        self::assertNotEmpty($inspection['deletion_blockers']);
        [$status, $result] = $this->delete($statement);
        self::assertSame(409, $status);
        self::assertNotEmpty($result['error']['code']);
        self::assertSame($statement, $this->statement($notice));
        if ($payment !== null) self::assertSame($notice, (int) $pdo->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action='bank.statement_deleted' AND supplier_id=" . $this->supplier)->fetchColumn());
    }
}
