<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dvojice (pohyb, přijatý doklad) smí mít v `payment_matches` jediný řádek.
 *
 * Pohyb v `auto_partial` už svou alokaci na doklad má. Opakované párování ji dřív
 * počítalo do „už uhrazeno" dokladu, takže:
 *   - po opravě částky dokladu na přesnou shodu skončil pohyb v `already_paid_verify`
 *     a doklad nikdy nepřešel na `paid`,
 *   - když se naopak zbytek dokladu shodou náhod rovnal platbě (doklad narostl na
 *     dvojnásobek), přesná větev vložila DRUHÝ řádek a doklad „uhradila" dvakrát.
 * Ruční párování téhož pohybu na týž doklad přidávalo druhý řádek taky.
 *
 * Izolace: rok 2099, vlastní doklad + výpis, úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseRematchSingleAllocationTest extends TestCase
{
    private const FILE_MARKER = '__purchase_rematch_single2099__';
    private const VS = '20998131';
    private const PAYMENT = 1000.00;

    private Connection $db;
    private StatementMatcher $matcher;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;
    private int $transactionId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->matcher = new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null);
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

        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
        $this->seedStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testExactRematchAfterDocumentCorrectionSettlesOnce(): void
    {
        $piId = $this->seedPurchase(self::PAYMENT + 0.36);
        self::assertSame('auto_partial', $this->matcher->match($this->transactionId)['status'] ?? null);

        $this->setDocumentTotal($piId, self::PAYMENT);
        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null,
            'Vlastní alokace pohybu se nesmí počítat jako cizí úhrada dokladu.');
        self::assertSame('paid', $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$piId}"));
        self::assertSame([[
            'amount' => '1000.00', 'match_type' => 'auto', 'match_confidence' => 95,
        ]], $this->pairRows($piId));
    }

    public function testExactRematchNeverInsertsSecondRowForSamePair(): void
    {
        $piId = $this->seedPurchase(self::PAYMENT + 0.36);
        self::assertSame('auto_partial', $this->matcher->match($this->transactionId)['status'] ?? null);

        // Zbytek dokladu po odečtení VLASTNÍ alokace se rovná platbě — dřív přesná shoda.
        $this->setDocumentTotal($piId, 2 * self::PAYMENT);
        $this->matcher->match($this->transactionId);

        self::assertCount(1, $this->pairRows($piId), 'Tatáž dvojice pohyb × doklad nesmí mít dva řádky.');
        self::assertSame('received', $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$piId}"),
            'Jediná platba 1 000 nesmí uhradit doklad na 2 000.');
    }

    public function testManualMatchTakesOverAutoPartialRow(): void
    {
        $piId = $this->seedPurchase(self::PAYMENT + 0.36);
        self::assertSame('auto_partial', $this->matcher->match($this->transactionId)['status'] ?? null);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $this->transactionId . '/match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['purchase_invoice_id' => $piId]);
        $response = $this->action->manualMatch($request, new Psr7Response(), ['id' => (string) $this->transactionId]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'amount' => '1000.00', 'match_type' => 'manual', 'match_confidence' => null,
        ]], $this->pairRows($piId), 'Ruční párování převezme auto řádek, nepřidá druhý.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function pairRows(int $piId): array
    {
        return $this->db->pdo()->query(
            "SELECT amount, match_type, match_confidence FROM payment_matches
              WHERE bank_transaction_id = {$this->transactionId} AND purchase_invoice_id = {$piId}
              ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->pdo()->query($sql)->fetchColumn();
    }

    private function setDocumentTotal(int $piId, float $total): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET total_without_vat = ?, total_with_vat = ? WHERE id = ?'
        )->execute([$total, $total, $piId]);
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.varsymbol = ?"
        )->execute([$this->supplierId, self::VS]);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?')
            ->execute([$this->supplierId, self::VS]);
        $this->transactionId = 0;
    }

    private function seedPurchase(float $amount): int
    {
        $d = '2099-06-15';
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, 'FV-2099-8131', 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, 'received', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::VS,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedStatement(): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::VS),
            $this->account, $this->bankCode, $d,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$statementId, $d, -self::PAYMENT, self::VS]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }
}
