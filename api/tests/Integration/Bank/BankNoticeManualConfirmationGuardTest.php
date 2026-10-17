<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use MyInvoice\Action\Bank\BankNoticeConfirmationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reálné úhrady, izolovaný syntetický tenant, žádné tiché skipy prázdné DB. */
final class BankNoticeManualConfirmationGuardTest extends TestCase
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

    private function call(int $notice, int $target, ?ActivityLogger $logger = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/', ['REMOTE_ADDR' => '192.0.2.157'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withHeader('User-Agent', 'Synthetic confirmation test')
            ->withParsedBody(['authoritative_transaction_id' => $target, 'reason' => 'Ověřen rozdíl data a částky.']);
        $action = $logger === null ? $this->container->get(BankNoticeConfirmationAction::class)
            : new BankNoticeConfirmationAction($this->service, $logger, new IpMatcher());
        $response = $action->confirm($request, new Response(), ['id' => $notice]);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public static function closedDates(): array
    {
        return [
            'source date lock' => ['source', 'date_locked'],
            'target date lock' => ['target', 'date_locked'],
            'paid_on date lock' => ['payment', 'date_locked'],
            'source final DE year' => ['source', 'closing_final'],
            'target final DE year' => ['target', 'closing_final'],
            'paid_on final DE year' => ['payment', 'closing_final'],
            'source closed fiscal period' => ['source', 'period_not_open'],
            'target closed fiscal period' => ['target', 'period_not_open'],
            'paid_on closed fiscal period' => ['payment', 'period_not_open'],
        ];
    }

    #[DataProvider('closedDates')]
    public function testClosedSourceTargetAndOriginalPaymentAreRejectedWithoutMutation(string $part, string $code): void
    {
        $pdo = $this->db->pdo();
        $notice = $this->movement('email_notice', 100, $part === 'source' ? '2098-12-31' : self::DATE);
        $target = $this->movement('gpc', 110, $part === 'target' ? '2098-12-31' : self::DATE);
        $payment = $this->payment($notice, $this->invoice(), 100);
        if ($part === 'payment') $pdo->prepare("UPDATE invoice_payments SET paid_on='2098-12-31' WHERE id=?")->execute([$payment]);
        if ($code === 'date_locked') {
            $pdo->prepare("INSERT INTO accounting_supplier_settings (supplier_id,locked_until) VALUES (?,'2098-12-31')")->execute([$this->supplier]);
        } elseif ($code === 'closing_final') {
            $pdo->prepare("INSERT INTO tax_evidence_closings (supplier_id,year,status) VALUES (?,2098,'final')")->execute([$this->supplier]);
        } else {
            $pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);
            $this->period(2098, 'closed');
            $this->period(2099, 'open');
        }
        [$status, $result] = $this->call($notice, $target);
        self::assertSame(409, $status);
        self::assertSame($code, $result['error']['code']);
        $this->assertUntouched($notice, $payment);
    }

    private function period(int $year, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO accounting_periods (supplier_id,fiscal_year,starts_on,ends_on,status) VALUES (?,?,?,?,?)')
            ->execute([$this->supplier, $year, $year . '-01-01', $year . '-12-31', $status]);
        return (int) $pdo->lastInsertId();
    }

    private function assertUntouched(int $notice, int $payment): void
    {
        $pdo = $this->db->pdo();
        self::assertSame($notice, (int) $pdo->query('SELECT bank_transaction_id FROM invoice_payments WHERE id=' . $payment)->fetchColumn());
        self::assertSame('manual', $pdo->query('SELECT match_status FROM bank_transactions WHERE id=' . $notice)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM bank_notice_confirmations WHERE notice_transaction_id=' . $notice)->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action='bank_notice.confirmed' AND supplier_id=" . $this->supplier)->fetchColumn());
    }

    public function testPostedCanonicalTargetAlsoBlocksWhenReferencedByAnotherImport(): void
    {
        $pdo = $this->db->pdo();
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $payment = $this->payment($notice, $this->invoice(), 100);
        $alias = $this->movement('gpc');
        $pdo->prepare('INSERT INTO bank_transaction_imports (statement_id,bank_transaction_id,import_fingerprint,supplier_id,original_statement_id) VALUES (?,?,?,?,?)')
            ->execute([$this->statement($alias), $target, hash('sha256', 'synthetic import'), $this->supplier, $this->statement($target)]);
        $period = $this->period(2099, 'open');
        $pdo->prepare("INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id,posted_at) VALUES (?,? ,?,'bank',?,NOW())")
            ->execute([$this->supplier, $period, self::DATE, $target]);
        [$status, $result] = $this->call($notice, $target);
        self::assertSame(409, $status);
        self::assertSame('target_posted', $result['error']['code']);
        $this->assertUntouched($notice, $payment);
    }

    public function testSuccessfulManualDifferenceIsAuditedOnceAndKeepsOriginalPayment(): void
    {
        $pdo = $this->db->pdo();
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc', 110, '2099-06-17');
        $payment = $this->payment($notice, $this->invoice(), 100);
        self::assertSame(200, $this->call($notice, $target)[0]);
        self::assertSame(200, $this->call($notice, $target)[0]);
        $row = $pdo->query('SELECT * FROM invoice_payments WHERE id=' . $payment)->fetch(PDO::FETCH_ASSOC);
        self::assertSame($target, (int) $row['bank_transaction_id']);
        self::assertSame(self::DATE, $row['paid_on']);
        self::assertSame('100.00', $row['amount']);
        $rows = $pdo->query("SELECT *,INET6_NTOA(ip) AS client_ip FROM activity_log WHERE action='bank_notice.confirmed' AND supplier_id=" . $this->supplier)->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame($this->user, (int) $rows[0]['user_id']);
        self::assertSame('bank_transaction', $rows[0]['entity_type']);
        self::assertSame($notice, (int) $rows[0]['entity_id']);
        self::assertSame('192.0.2.157', $rows[0]['client_ip']);
        self::assertSame('Synthetic confirmation test', $rows[0]['user_agent']);
        self::assertSame(['notice_transaction_id' => $notice, 'authoritative_transaction_id' => $target, 'reason' => 'Ověřen rozdíl data a částky.'], json_decode($rows[0]['payload'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAuditFailureRollsBackPaymentAndConfirmation(): void
    {
        $notice = $this->movement('email_notice');
        $target = $this->movement('gpc');
        $payment = $this->payment($notice, $this->invoice(), 100);
        $logger = new class($this->db) extends ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
                throw new \RuntimeException('Synthetic audit failure');
            }
        };
        try {
            $this->call($notice, $target, $logger);
            self::fail('Chyba auditu musí zrušit i přesun úhrady.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertUntouched($notice, $payment);
    }

    public function testForeignTargetDoesNotDiscloseItsPostingOrWriteAudit(): void
    {
        $notice = $this->movement('email_notice');
        $foreign = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $target = $this->movement('gpc', overrides: ['supplier' => $foreign]);
        $payment = $this->payment($notice, $this->invoice(), 100);
        [$status, $result] = $this->call($notice, $target);
        self::assertSame(409, $status);
        self::assertSame('unsafe_confirmation', $result['error']['code']);
        $this->assertUntouched($notice, $payment);
    }

    public function testExistingYearLockCallersKeepDefaultReadBehaviour(): void
    {
        self::assertFalse(TaxEvidenceYearLock::isFinal($this->db, $this->supplier, 2099));
        $this->db->pdo()->prepare("INSERT INTO tax_evidence_closings (supplier_id,year,status) VALUES (?,2099,'draft')")->execute([$this->supplier]);
        self::assertFalse(TaxEvidenceYearLock::isFinal($this->db, $this->supplier, 2099));
        $this->db->pdo()->prepare("UPDATE tax_evidence_closings SET status='final' WHERE supplier_id=? AND year=2099")->execute([$this->supplier]);
        self::assertTrue(TaxEvidenceYearLock::isFinal($this->db, $this->supplier, 2099));
        self::assertTrue(TaxEvidenceYearLock::isFinal($this->db, $this->supplier, 2099, true));
    }
}
