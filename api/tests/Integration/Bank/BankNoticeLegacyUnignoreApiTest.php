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
use MyInvoice\Security\EffectiveRole;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reálné úhrady, izolovaný syntetický tenant, žádné tiché skipy prázdné DB. */
final class BankNoticeLegacyUnignoreApiTest extends TestCase
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

    private function legacyNotice(string $source = 'email_notice'): int
    {
        $tx = $this->movement($source);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET source=?,match_status='ignored',ignore_origin='superseded',ignore_note='Synthetic superseded notice' WHERE id=?")
            ->execute([$source, $tx]);
        return $tx;
    }

    private function call(int $tx, string $role = 'admin', ?int $supplier = null, string $method = 'unignore'): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier ?? $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => $role])
            ->withQueryParams(['show_confirmed' => '1']);
        if ($role === 'client') $request = $request->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic client', 'client', true, ['bank' => 1, 'bank.ignore' => 2]));
        $response = $this->container->get(BankStatementAction::class)->$method($request, new Response(), ['id' => $method === 'detail' ? $this->statement($tx) : $tx]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public static function sources(): array
    {
        return ['email notice' => ['email_notice'], 'legacy idoklad notice' => ['idoklad']];
    }

    #[DataProvider('sources')]
    public function testStaffCanUndoLegacySupersessionWithoutConfirmationHistory(string $source): void
    {
        $notice = $this->legacyNotice($source);
        [$status, $detail] = $this->call($notice, method: 'detail');
        self::assertSame(200, $status);
        self::assertFalse($detail['transactions'][0]['confirmation_record_exists']);
        [$status, $result] = $this->call($notice);
        self::assertSame(200, $status);
        self::assertTrue($result['unmatched']);
        $row = $this->db->pdo()->query('SELECT match_status,ignore_origin,ignore_note FROM bank_transactions WHERE id=' . $notice)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['match_status' => 'unmatched', 'ignore_origin' => null, 'ignore_note' => null], $row);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action='bank.tx_unignore' AND entity_id=" . $notice . ' AND supplier_id=' . $this->supplier)->fetchColumn());
    }

    public static function history(): array
    {
        $rows = [];
        foreach (['email_notice', 'idoklad'] as $source) foreach (['pending', 'review', 'confirmed'] as $state) $rows[$source . ' ' . $state] = [$source, $state];
        return $rows;
    }

    #[DataProvider('history')]
    public function testAnyConfirmationRecordPreventsUndoOfSupersededNotice(string $source, string $state): void
    {
        $notice = $this->legacyNotice($source);
        $ref = 'synthetic-legacy:' . $notice;
        $this->db->pdo()->prepare('INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state) VALUES (?,?,?,?,?)')
            ->execute([$this->supplier, $notice, $ref, hash('sha256', $ref), $state]);
        [$detailStatus, $detail] = $this->call($notice, method: 'detail');
        self::assertSame(200, $detailStatus);
        self::assertTrue($detail['transactions'][0]['confirmation_record_exists']);
        [$status, $result] = $this->call($notice);
        self::assertSame(409, $status);
        self::assertContains($result['error']['code'], ['notice_read_only', 'payment_unavailable']);
        self::assertSame('superseded', $this->db->pdo()->query('SELECT ignore_origin FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action='bank.tx_unignore' AND entity_id=" . $notice)->fetchColumn());
    }

    #[DataProvider('sources')]
    public function testClientCannotUndoLegacySupersession(string $source): void
    {
        $notice = $this->legacyNotice($source);
        self::assertSame(409, $this->call($notice, 'client')[0]);
        self::assertSame('superseded', $this->db->pdo()->query('SELECT ignore_origin FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
    }

    public function testClientCanStillUndoOwnManualIgnore(): void
    {
        $notice = $this->movement('gpc');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status='ignored',ignore_origin='manual' WHERE id=?")->execute([$notice]);
        self::assertSame(200, $this->call($notice, 'client')[0]);
        self::assertSame('unmatched', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
    }

    public function testConfirmedNoticeCannotBypassReadOnlyGuardThroughManualIgnoreMarker(): void
    {
        $notice = $this->legacyNotice();
        $this->db->pdo()->prepare("UPDATE bank_transactions SET ignore_origin='manual' WHERE id=?")->execute([$notice]);
        $ref = 'synthetic-confirmed-manual:' . $notice;
        $this->db->pdo()->prepare("INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state) VALUES (?,?,?,?,'confirmed')")
            ->execute([$this->supplier, $notice, $ref, hash('sha256', $ref)]);
        [$status, $result] = $this->call($notice);
        self::assertSame(409, $status);
        self::assertSame('notice_read_only', $result['error']['code']);
        self::assertSame('ignored', $this->db->pdo()->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
    }

    public function testLegacyUndoRespectsOwnershipAndExistingPayment(): void
    {
        $notice = $this->legacyNotice();
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        self::assertSame(404, $this->call($notice, supplier: $other)[0]);
        $payment = $this->payment($notice, $this->invoice(), 100);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status='ignored' WHERE id=?")->execute([$notice]);
        self::assertSame(409, $this->call($notice)[0]);
        self::assertSame($notice, (int) $this->db->pdo()->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
    }
}
