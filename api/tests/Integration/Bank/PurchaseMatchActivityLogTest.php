<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Regrese: ODCHOZÍ platba spárovaná s přijatou fakturou musí
 *   1) vrátit purchase_invoice_id se status auto_exact a fakturu označit jako paid,
 *   2) zapsat aktivitu `purchase_invoice.payment_matched` proti dokladu, ať je
 *      auto-úhrada vidět v těle faktury (dřív matcher neměl logger → žádný log).
 *
 * Souvisí s opravou e-mailových avíz (BankEmailNoticeScanner), kde se úhrada přijaté
 * faktury chybně hlásila jako match_failed (čteno jen invoice_id, ne purchase_invoice_id).
 *
 * Izolace: rok 2099, vlastní statement/transakce/faktura + úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseMatchActivityLogTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private int $purchaseId = 0;
    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__purchmatch2099__';
    private const TEST_VS = '2099000260';
    private const VENDOR_MARKER = '__purchmatch_vendor_2099__';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            // ActivityLogger injektován → ověřujeme zápis do activity_log; mailer/payments null.
            $this->matcher = new StatementMatcher(
                $this->db,
                $c->get(FinalFromProformaCreator::class),
                null,
                null,
                null,
                $c->get(ActivityLogger::class),
            );
            $this->action = $c->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;

        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $countryId === 0) {
            $this->markTestSkipped('Chybí user/country pro supplier.');
        }

        $this->cleanup();
        $pdo->prepare(
            "INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, 'Testovací 1', 'Testov', '10000', ?, '', ?, 0, 1)"
        )->execute([$this->supplierId, self::VENDOR_MARKER, $countryId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        // Aktivitu navázanou na testovací přijaté faktury (dle markeru) smaž nejdřív.
        $pdo->prepare(
            "DELETE al FROM activity_log al
               JOIN purchase_invoices pi ON pi.id = al.entity_id
              WHERE al.entity_type = 'purchase_invoice' AND pi.vendor_invoice_number LIKE ?"
        )->execute([self::TEST_VS . '%']);
        // Statementy (cascade → transakce + payment_matches), pak faktury.
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE vendor_invoice_number LIKE ?)")
            ->execute([$this->supplierId, self::TEST_VS . '%']);
        $pdo->prepare("DELETE FROM invoice_settlements WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id IN (SELECT id FROM purchase_invoices WHERE vendor_invoice_number LIKE ?)")
            ->execute([$this->supplierId, self::TEST_VS . '%']);
        $pdo->prepare("DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE ?")
            ->execute([$this->supplierId, self::TEST_VS . '%']);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::VENDOR_MARKER]);
        $this->purchaseId = $this->statementId = $this->transactionId = 0;
    }

    /**
     * @param 'received'|'booked'|'paid' $status Stav přijaté faktury
     * @param ?string $txVs VS na bankovní transakci (null = karetní platba bez VS)
     */
    private function seed(
        float $amount,
        string $status = 'received',
        ?string $txVs = self::TEST_VS,
        string $counterpartyName = '',
    ): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';

        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, paid_at, created_by)
             VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::TEST_VS, self::TEST_VS,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $status,
            $status === 'paid' ? $d : null, $this->userId,
        ]);
        $this->purchaseId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::TEST_VS . $status . ($txVs ?? 'novs')),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // ODCHOZÍ platba = záporná částka → matcher routuje na přijatou fakturu.
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_name)
             VALUES (?, ?, ?, 'CZK', ?, ?)"
        )->execute([$this->statementId, $d, -$amount, $txVs, $counterpartyName]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }

    public function testOutgoingPaymentMatchesPurchaseAndLogsActivity(): void
    {
        $this->seed(2500.00);

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('auto_exact', $res['status'] ?? null, 'Odchozí platba se musí spárovat s přijatou fakturou.');
        self::assertSame($this->purchaseId, $res['purchase_invoice_id'] ?? null);
        self::assertArrayNotHasKey('invoice_id', $res, 'Přijatá faktura nesmí vracet invoice_id (jen purchase_invoice_id).');

        $status = $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}")->fetchColumn();
        self::assertSame('paid', $status, 'Spárovaná přijatá faktura má být zaplacená.');

        $logCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE entity_type = 'purchase_invoice' AND entity_id = {$this->purchaseId}
                AND action = 'purchase_invoice.payment_matched'"
        )->fetchColumn();
        self::assertSame(1, $logCount, 'Auto-spárování platby musí zapsat aktivitu purchase_invoice.payment_matched.');
    }

    public function testUnmatchRemovesPurchaseAllocationAndRestoresInvoice(): void
    {
        $this->seed(2500.00);
        self::assertSame('auto_exact', $this->matcher->match($this->transactionId)['status'] ?? null);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/unmatch')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId]);
        $response = $this->action->unmatch($request, new Response(), ['id' => $this->transactionId]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
        self::assertSame('received', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}"
        )->fetchColumn());
        self::assertSame('unmatched', $this->db->pdo()->query(
            "SELECT match_status FROM bank_transactions WHERE id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testSecondPassMatchesUniquePaidCardPaymentByExactAmountAndDate(): void
    {
        $this->seed(2500.00, 'paid', null, self::VENDOR_MARKER . ' Brno CZE');

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($this->purchaseId, $res['purchase_invoice_id'] ?? null);
        self::assertTrue($res['amount_date'] ?? false);
        self::assertTrue($res['second_pass'] ?? false);

        $pmCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId} AND purchase_invoice_id = {$this->purchaseId}"
        )->fetchColumn();
        self::assertSame(1, $pmCount);
        self::assertSame('paid', $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}")->fetchColumn());
    }

    public function testSecondPassMatchesUniqueOpenInvoiceAndIsIdempotent(): void
    {
        $this->seed(2500.00, 'received', null);

        $first = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];
        $second = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('auto_exact', $first['status'] ?? null);
        self::assertSame('auto_exact', $second['status'] ?? null);
        self::assertTrue($second['already_recorded'] ?? false);
        self::assertSame('paid', $this->db->pdo()->query(
            "SELECT status FROM purchase_invoices WHERE id = {$this->purchaseId}"
        )->fetchColumn());
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testPrimaryPassDefersAmountDateFallback(): void
    {
        $this->seed(2500.00, 'received', null);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testSecondPassDoesNotGuessBetweenTwoPaymentsOfSameAmount(): void
    {
        $this->seed(2500.00, 'received', null);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_name)
             VALUES (?, '2099-06-15', -2500, 'CZK', NULL, 'Jiný testovací obchodník')"
        )->execute([$this->statementId]);
        $secondTransactionId = (int) $pdo->lastInsertId();

        $results = $this->matcher->matchBatch([$this->transactionId, $secondTransactionId]);

        self::assertSame('unmatched', $results[$this->transactionId]['status'] ?? null);
        self::assertSame('unmatched', $results[$secondTransactionId]['status'] ?? null);
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id IN ({$this->transactionId}, {$secondTransactionId})"
        )->fetchColumn());
    }

    public function testFirstPassVsMatchWinsBeforeWeakPaymentFallback(): void
    {
        $this->seed(2500.00, 'received', null);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_name)
             VALUES (?, '2099-06-15', -2500, 'CZK', ?, 'Testovací dodavatel')"
        )->execute([$this->statementId, self::TEST_VS]);
        $strongTransactionId = (int) $pdo->lastInsertId();

        $results = $this->matcher->matchBatch([$this->transactionId, $strongTransactionId]);

        self::assertSame('auto_exact', $results[$strongTransactionId]['status'] ?? null);
        self::assertSame('unmatched', $results[$this->transactionId]['status'] ?? null);
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = {$this->purchaseId}"
        )->fetchColumn());
    }

    public function testPaidInvoiceNeedsMatchingPaidDateAndMerchantName(): void
    {
        $this->seed(2500.00, 'paid', null, 'Nesouvisející obchodník');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET paid_at = '2099-06-14' WHERE id = ?")
            ->execute([$this->purchaseId]);

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('amount_date_requires_review', $res['reason'] ?? null);
        self::assertTrue($res['requires_review'] ?? false);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testSecondPassRejectsDifferentCurrency(): void
    {
        $this->seed(2500.00, 'received', null);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET currency = 'EUR' WHERE id = ?")
            ->execute([$this->transactionId]);

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testSecondPassRejectsInvoiceOutsideDateWindow(): void
    {
        $this->seed(2500.00, 'received', null);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices
                SET issue_date = '2099-05-01', due_date = '2099-05-15'
              WHERE id = ?"
        )->execute([$this->purchaseId]);

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('no_amount_date_match', $res['reason'] ?? null);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testSecondPassRejectsInvoiceWithExistingSettlement(): void
    {
        $this->seed(2500.00, 'received', null);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, match_status, matched_at)
             VALUES (?, '2099-06-14', -2500, 'CZK', '2099000999', 'auto_exact', NOW())"
        )->execute([$this->statementId]);
        $settledTransactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, 2500, 'auto', 95)"
        )->execute([$this->supplierId, $settledTransactionId, $this->purchaseId]);

        $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('no_amount_date_match', $res['reason'] ?? null);
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = {$this->purchaseId}"
        )->fetchColumn());
    }

    public function testOutgoingPaymentToAlreadyPaidPurchaseRequiresReview(): void
    {
        $this->seed(2500.00, 'paid', self::TEST_VS);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('already_paid_verify', $res['reason'] ?? null);
        self::assertTrue($res['requires_review'] ?? false);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn(), 'Druhá platba nesmí vytvořit duplicitní alokaci ani 321/221.');
    }

    public function testExactVsDoesNotMatchPurchaseSettledThroughAccount(): void
    {
        $this->seed(2500.00, 'received', self::TEST_VS);
        $pdo = $this->db->pdo();
        $accountId = (int) ($pdo->query(
            "SELECT id FROM chart_of_accounts
              WHERE supplier_id = {$this->supplierId} AND account_code LIKE '365%'
              ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($accountId === 0) {
            /*
             * Účet 365 seedovaná osnova mít nemusí. Vyžadovat ho znamená test,
             * který projde lokálně nad ostrou osnovou a spadne v CI nad čistou —
             * a to není nález v aplikaci, jen v testu. Založíme si ho proto sami;
             * je to jediná věc, kterou z osnovy potřebujeme.
             */
            $this->db->pdo()->prepare(
                'INSERT INTO chart_of_accounts
                    (supplier_id, account_code, name, account_type, normal_side, is_synthetic)
                 VALUES (?, "365", "Ostatní závazky", "liability", "credit", 1)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            )->execute([$this->supplierId]);
            $accountId = (int) $this->db->pdo()->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO invoice_settlements
                (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status, created_by)
             VALUES (?, 'purchase_invoice', ?, '2099-06-15', 2500, ?, 'confirmed', ?)"
        )->execute([$this->supplierId, $this->purchaseId, $accountId, $this->userId]);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('already_paid_verify', $res['reason'] ?? null);
        self::assertTrue($res['requires_review'] ?? false);
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    public function testAmbiguousAmountDateStaysUnmatched(): void
    {
        // Dvě přijaté faktury stejné částky+data bez VS na platbě → nejednoznačné,
        // amount+date záchrana radši nechá unmatched (ať nespáruje špatnou).
        $this->seed(2500.00, 'paid', null);
        $pdo = $this->db->pdo();
        // Druhá faktura stejné částky ve stejném okně (jiné vendor_invoice_number kvůli unikátu).
        $secondVno = self::TEST_VS . '-B';
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', '2099-06-15','2099-06-15','2099-06-15','2099-06-15', ?, '{}', ?, ?, 'paid', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $secondVno, $secondVno,
            $this->currencyId, 2500.00, 2500.00, $this->userId,
        ]);
        $secondId = (int) $pdo->lastInsertId();

        try {
            $res = $this->matcher->matchBatch([$this->transactionId])[$this->transactionId];
            self::assertSame('unmatched', $res['status'] ?? null, 'Dvojznačná shoda se nesmí automaticky spárovat.');
            self::assertSame('ambiguous_amount_date_match', $res['reason'] ?? null);
        } finally {
            $pdo->prepare("DELETE FROM payment_matches WHERE purchase_invoice_id = ?")->execute([$secondId]);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE id = ?")->execute([$secondId]);
        }
    }
}
