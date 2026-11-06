<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\BankTransactionContext;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PaymentCorrectionFixture;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PaymentSourceMutationGuardTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PaymentCorrectionFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testIgnoreCannotChangeAnAllocatedBankSource(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $before = $this->sourceState($base['bank']);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/bank/transactions/' . $base['bank'] . '/ignore')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->f['supplier'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->f['actor'], 'role' => 'admin'])
            ->withParsedBody(['note' => 'Synthetic generic bank action']);
        $response = $this->paymentFixtureContainer()->get(BankStatementAction::class)->ignore($request, new Response(), ['id' => $base['bank']]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_payment_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->sourceState($base['bank']));
    }

    public function testDirectIgnoreServiceCannotReleaseAnAllocatedSource(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $before = $this->sourceState($base['bank']);
        try {
            $this->paymentFixtureContainer()->get(BankPostingService::class)->onIgnore($this->f['supplier'], $base['bank']);
            self::fail('Allocated bank source accepted by generic ignore.');
        } catch (PostingException $e) {
            self::assertSame('property_payment_source_immutable', $e->errorCode);
        }
        self::assertSame($before, $this->sourceState($base['bank']));
    }

    public function testAutomaticMatchingLeavesOwnedSourceMetadataUntouched(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $before = $this->sourceState($base['bank']);
        $matcher = $this->paymentFixtureContainer()->get(StatementMatcher::class);
        $result = $matcher->match($base['bank']);

        self::assertSame($before, $this->sourceState($base['bank']));
        self::assertSame('property_payment_source_immutable', $result['reason'] ?? null);
        self::assertTrue($result['already_recorded'] ?? false);
        $batch = $matcher->matchBatch([$base['bank']]);
        self::assertSame('property_payment_source_immutable', $batch[$base['bank']]['reason'] ?? null);
        self::assertSame($before, $this->sourceState($base['bank']));
    }

    public function testUnignoreCannotChangeAnAllocatedBankSource(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status='ignored',ignore_origin='manual',ignore_note='Synthetic historical note' WHERE id=?")->execute([$base['bank']]);
        $before = $this->sourceState($base['bank']);
        $request = $this->bankRequest();
        $response = $this->paymentFixtureContainer()->get(BankStatementAction::class)->unignore($request, new Response(), ['id' => $base['bank']]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_payment_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->sourceState($base['bank']));
    }

    public function testStatementDeletionRejectsAnyAllocatedSourceBeforeReleasingTransactions(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $q = $this->db->pdo()->prepare('SELECT statement_id FROM bank_transactions WHERE id=?');
        $q->execute([$base['bank']]);
        $statement = (int) $q->fetchColumn();
        $before = $this->sourceState($base['bank']);
        $response = $this->paymentFixtureContainer()->get(BankStatementAction::class)->delete($this->bankRequest(), new Response(), ['id' => $statement]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_payment_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->sourceState($base['bank']));
        $q = $this->db->pdo()->prepare('SELECT status FROM unit_payment_operations WHERE supplier_id=? AND id=?');
        $q->execute([$this->f['supplier'], $base['operation']['id']]);
        self::assertSame('posted', $q->fetchColumn());
    }

    public function testSourceRemainsReadableAndForeignTenantCannotDiscoverPaymentUse(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $context = new BankTransactionContext($this->db);
        self::assertSame($base['bank'], (int) $context->lock($this->f['supplier'], $base['bank'])['id']);
        $q = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id<>? ORDER BY id LIMIT 1');
        $q->execute([$this->f['supplier']]);
        $foreign = (int) $q->fetchColumn();
        self::assertGreaterThan(0, $foreign);
        self::assertNull($context->mutableTransaction($foreign, $base['bank']));
    }

    public function testOwnedPaymentCannotBeUsedAsReversalOfAnUnrelatedEntry(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $bank = $this->correctionBank('1.00');
        $q = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");
        $q->execute([$this->f['supplier'], $bank]);
        $entry = (int) $q->fetchColumn();
        self::assertGreaterThan(0, $entry);
        try {
            $this->paymentFixtureContainer()->get(JournalEntryRepository::class)->setReversedBy($entry, $this->f['supplier'], $base['operation']['journal_entry_id']);
            self::fail('Unrelated entry was paired with an owned payment reversal.');
        } catch (PostingException $e) {
            self::assertSame('property_payment_source_immutable', $e->errorCode);
        }
        $q = $this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=?');
        $q->execute([$this->f['supplier'], $entry]);
        self::assertNull($q->fetchColumn());
    }

    public function testJournalDeletionProtectsTheBankSourceBeforeAncillaryCleanup(): void
    {
        $base = $this->settledPaymentFixture('90000', '0');
        $q = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");
        $q->execute([$this->f['supplier'], $base['bank']]);
        $entry = (int) $q->fetchColumn();
        self::assertGreaterThan(0, $entry);
        $before = $this->sourceState($base['bank']);
        $response = $this->paymentFixtureContainer()->get(JournalAction::class)->delete($this->bankRequest(), new Response(), ['id' => $entry]);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_payment_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->sourceState($base['bank']));
    }

    public function testRefundSourceCannotCreateAndPayAnUnrelatedPurchaseDraft(): void
    {
        $this->db->pdo()->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic',2,1,1,'1000000005','0100')")->execute([$this->f['supplier']]);
        $base = $this->settledPaymentFixture('90000', '1000');
        $payments = $this->correctionPayments();
        $reservation = $payments->reserve($this->f['supplier'], $base['operation']['credit_id'], 1, [
            'amount_cents' => '1000', 'party_ids' => [$this->f['clients'][1]], 'disposition' => 'Synthetic refund instruction', 'recipient_account' => '1000000005/0100',
        ], 'refund-reservation', $this->f['actor']);
        $bank = $this->correctionBank('-10.00');
        $refund = ['kind' => 'refund', 'source' => ['kind' => 'bank', 'id' => $bank, 'expected_version' => 0], 'amount_cents' => '1000',
            'accounting_date' => '2026-02-01', 'reservation_id' => $reservation['id'], 'expected_reservation_version' => 1, 'reason' => 'Synthetic refund'];
        $payments->apply($this->f['supplier'], $refund, $payments->preview($this->f['supplier'], $refund)['preview_hash'], 'refund', $this->f['actor']);
        $before = $this->sourceState($bank);
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id=?');
        $q->execute([$this->f['supplier']]);
        $count = (int) $q->fetchColumn();
        $request = $this->bankRequest()->withParsedBody(['vendor_id' => $this->f['clients'][1]]);
        try {
            $response = $this->paymentFixtureContainer()->get(BankStatementAction::class)->createPurchaseInvoice($request, new Response(), ['id' => $bank]);
        } finally {
            $q->execute([$this->f['supplier']]);
            self::assertSame($count, (int) $q->fetchColumn(), 'Owned refund must be rejected before creating a purchase draft.');
        }

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('property_payment_source_immutable', (string) $response->getBody());
        self::assertSame($before, $this->sourceState($bank));
    }

    public function testOrdinaryFiveHundredBankPaymentStillCreatesAndMatchesDraft(): void
    {
        $pdo = $this->db->pdo();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $q = $pdo->prepare('SELECT COUNT(*) FROM supplier_accounting_frameworks WHERE supplier_id=?');
        $q->execute([$supplier]);
        self::assertSame(0, (int) $q->fetchColumn());
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic',2,1,1,'1000000005','0100')")->execute([$supplier]);
        $pdo->prepare('INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT ?,company_name,street,city,zip,country_id,main_email,currency_default_id FROM clients WHERE id=?')->execute([$supplier, $this->f['clients'][1]]);
        $vendor = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK','2026-02-01')")->execute([$supplier, hash('sha256', random_bytes(20))]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01','-10.00','CZK','1000000005','0100')")->execute([$statement]);
        $bank = (int) $pdo->lastInsertId();
        $request = $this->bankRequest()->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)->withParsedBody(['vendor_id' => $vendor]);
        $action = $this->paymentFixtureContainer()->get(BankStatementAction::class);
        $response = $action->createPurchaseInvoice($request, new Response(), ['id' => $bank]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $q = $pdo->prepare('SELECT p.status,p.total_with_vat,m.amount FROM purchase_invoices p JOIN payment_matches m ON m.purchase_invoice_id=p.id AND m.supplier_id=p.supplier_id WHERE p.supplier_id=? AND m.bank_transaction_id=?');
        $q->execute([$supplier, $bank]);
        self::assertSame(['status' => 'draft', 'total_with_vat' => '10.00', 'amount' => '10.00'], $q->fetch(\PDO::FETCH_ASSOC));
        self::assertSame('manual', $this->sourceState($bank)['match_status']);
        self::assertSame(409, $action->createPurchaseInvoice($request, new Response(), ['id' => $bank])->getStatusCode());
    }

    private function bankRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/bank')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->f['supplier'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->f['actor'], 'role' => 'admin']);
    }

    private function sourceState(int $id): array
    {
        $q = $this->db->pdo()->prepare('SELECT match_status, matched_invoice_id, match_reason, ignore_origin, ignore_note FROM bank_transactions WHERE id=?');
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC);
    }
}
