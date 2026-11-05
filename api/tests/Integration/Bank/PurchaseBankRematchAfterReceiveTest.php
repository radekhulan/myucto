<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Platba odešla dřív, než byl přijatý doklad přijatý. Přijetí dokladu spustí nad
 * nespárovanými odchozími pohyby firmy běžné párování, takže se platba dohledá bez
 * ručního „Přepárovat výpis". Nesouvisející pohyb zůstane nespárovaný.
 *
 * Izolace: rok 2099, vlastní doklad + výpis, úklid v tearDown.
 */
#[Group('integration')]
final class PurchaseBankRematchAfterReceiveTest extends TestCase
{
    private const FILE_MARKER = '__purchase_rematch_after_receive2099__';
    private const VS = '20998177';
    private const PAYMENT = 1234.00;

    private Connection $db;
    private PurchaseInvoiceReceiver $receiver;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;
    private int $statementId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->receiver = $c->get(PurchaseInvoiceReceiver::class);
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

    public function testReceivingDocumentMatchesEarlierPaymentAndLeavesOthersAlone(): void
    {
        $paymentId = $this->seedTransaction(-self::PAYMENT, self::VS);
        $unrelatedId = $this->seedTransaction(-777.00, '99990001');
        $piId = $this->seedDraftPurchase(self::PAYMENT);

        self::assertTrue($this->receiver->receiveDraft($this->supplierId, $piId, $this->userId));

        self::assertSame('auto_exact', $this->scalar("SELECT match_status FROM bank_transactions WHERE id = {$paymentId}"));
        self::assertSame('1', (string) $this->scalar(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$paymentId} AND purchase_invoice_id = {$piId}"
        ));
        self::assertSame('paid', $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$piId}"));
        self::assertSame('unmatched', $this->scalar("SELECT match_status FROM bank_transactions WHERE id = {$unrelatedId}"));
        self::assertSame('1', (string) $this->scalar("SELECT matched_count FROM bank_statements WHERE id = {$this->statementId}"));
    }

    public function testDocumentWithoutMatchingPaymentStaysUnpaid(): void
    {
        $unrelatedId = $this->seedTransaction(-777.00, '99990001');
        $piId = $this->seedDraftPurchase(self::PAYMENT);

        self::assertTrue($this->receiver->receiveDraft($this->supplierId, $piId, $this->userId));

        self::assertSame('received', $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$piId}"));
        self::assertSame('unmatched', $this->scalar("SELECT match_status FROM bank_transactions WHERE id = {$unrelatedId}"));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function scalar(string $sql): mixed
    {
        return $this->db->pdo()->query($sql)->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.payment_variable_symbol = ?"
        )->execute([$this->supplierId, self::VS]);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND payment_variable_symbol = ?')
            ->execute([$this->supplierId, self::VS]);
        $this->statementId = 0;
    }

    private function seedDraftPurchase(float $amount): int
    {
        $d = '2099-06-15';
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, payment_variable_symbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, 'FV-2099-8177', 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, 'draft', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, self::VS,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedStatement(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::VS),
            $this->account, $this->bankCode, '2099-06-10',
        ]);
        $this->statementId = (int) $this->db->pdo()->lastInsertId();
    }

    private function seedTransaction(float $amount, string $vs): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, '2099-06-10', ?, 'CZK', ?)"
        )->execute([$this->statementId, $amount, $vs]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
