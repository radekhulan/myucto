<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Párování pohybu kartou s přijatým dokladem podle koncovky, částky a data.
 *
 * Jádro: stejná částka dvěma kartami téhož dne se NESMÍ spárovat křížem.
 * Doklad nesoucí koncovku jiné karty není kandidát žádnou cestou — ani přes
 * shodu podle částky a data, která dřív doklad jiné karty klidně uhradila.
 *
 * Izolace: rok 2093, vlastní výpisy a doklady, úklid v setUp i tearDown.
 */
#[Group('integration')]
final class StatementMatcherCardPaymentTest extends TestCase
{
    private const MARKER = '__cardmatch2093__';
    private const DOC_PREFIX = 'CARD-2093-';
    private const DAY = '2093-06-15';

    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;
    private int $docSeq = 0;
    private int $statementSeq = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->matcher = new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null);
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
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    /**
     * RED bez opravy: jediný volný pohyb na výpisu a jediný otevřený doklad téže
     * částky stačily druhému průchodu (částka + datum) k automatické úhradě — i když
     * doklad zaplatila JINÁ karta. Po opravě pohyb karty 1111 doklad karty 2222
     * nechá být a ten se spáruje až s pohybem své karty.
     */
    public function testDocumentOfOtherCardIsNeverPaidByAmountDateFallback(): void
    {
        $docOfCard2222 = $this->seedPurchase(500.00, '2093-06-14', '2222');
        $txCard1111 = $this->seedTransaction($this->seedStatement(), -500.00, self::DAY, '1111');

        $first = $this->matcher->matchBatch([$txCard1111])[$txCard1111];

        self::assertSame('unmatched', $first['status'] ?? null, 'Pohyb karty 1111 nesmí uhradit doklad karty 2222.');
        self::assertSame('received', $this->purchaseStatus($docOfCard2222));
        self::assertSame(0, $this->matchCount($txCard1111));

        $txCard2222 = $this->seedTransaction($this->seedStatement(), -500.00, self::DAY, '2222');
        $second = $this->matcher->matchBatch([$txCard2222])[$txCard2222];

        self::assertSame('auto_exact', $second['status'] ?? null);
        self::assertSame($docOfCard2222, $second['purchase_invoice_id'] ?? null);
    }

    /** Dvě karty, stejná částka, týž den, dva doklady — každý pohyb si vezme doklad své karty. */
    public function testSameAmountTwoCardsSameDayDoNotCrossMatch(): void
    {
        $docOfCard2222 = $this->seedPurchase(750.00, '2093-06-14', '2222');
        $docOfCard1111 = $this->seedPurchase(750.00, '2093-06-14', '1111');
        $statement = $this->seedStatement();
        $txCard1111 = $this->seedTransaction($statement, -750.00, self::DAY, '1111');
        $txCard2222 = $this->seedTransaction($statement, -750.00, self::DAY, '2222');

        $results = $this->matcher->matchBatch([$txCard1111, $txCard2222]);

        self::assertSame('auto_exact', $results[$txCard1111]['status'] ?? null);
        self::assertSame($docOfCard1111, $results[$txCard1111]['purchase_invoice_id'] ?? null);
        self::assertSame('auto_exact', $results[$txCard2222]['status'] ?? null);
        self::assertSame($docOfCard2222, $results[$txCard2222]['purchase_invoice_id'] ?? null);
        self::assertSame([$docOfCard1111], $this->matchedPurchases($txCard1111));
        self::assertSame([$docOfCard2222], $this->matchedPurchases($txCard2222));
    }

    public function testUniqueCardDocumentIsPairedAutomatically(): void
    {
        $doc = $this->seedPurchase(812.50, '2093-06-13', '4321');
        $tx = $this->seedTransaction($this->seedStatement(), -812.50, self::DAY, '4321');

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('paid', $this->purchaseStatus($doc));
        self::assertSame([$doc], $this->matchedPurchases($tx));
    }

    /** Doklad placený kartou bez koncovky je jen návrh — a jen když ho nechce i jiná karta. */
    public function testCardDocumentWithoutLast4IsOnlySuggested(): void
    {
        $doc = $this->seedPurchase(300.00, '2093-06-14', null);
        $tx = $this->seedTransaction($this->seedStatement(), -300.00, self::DAY, '1111');

        $res = $this->matcher->match($tx);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('card_match_requires_review', $res['reason'] ?? null);
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
    }

    public function testCardDocumentWithoutLast4IsNotSuggestedWhenOtherCardCompetes(): void
    {
        $doc = $this->seedPurchase(300.00, '2093-06-14', null);
        $statement = $this->seedStatement();
        $txCard1111 = $this->seedTransaction($statement, -300.00, self::DAY, '1111');
        $this->seedTransaction($statement, -300.00, self::DAY, '2222');

        $res = $this->matcher->matchBatch([$txCard1111])[$txCard1111];

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertEmpty($res['requires_review'] ?? null, 'Doklad bez koncovky si nárokují dvě karty — nenavrhovat.');
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($txCard1111));
    }

    /** Dvě stejné platby toutéž kartou a jeden doklad — nehádat, ke kterému pohybu patří. */
    public function testDuplicateChargeOnSameCardGoesToReview(): void
    {
        $doc = $this->seedPurchase(120.00, '2093-06-14', '1111');
        $statement = $this->seedStatement();
        $tx = $this->seedTransaction($statement, -120.00, self::DAY, '1111');
        $this->seedTransaction($statement, -120.00, self::DAY, '1111');

        $res = $this->matcher->match($tx);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertTrue((bool) ($res['requires_review'] ?? false));
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
    }

    public function testDocumentOutsideDateWindowIsNotPaired(): void
    {
        $doc = $this->seedPurchase(640.00, '2093-05-20', '1111');
        $tx = $this->seedTransaction($this->seedStatement(), -640.00, self::DAY, '1111');

        $res = $this->matcher->match($tx);

        self::assertNotSame('auto_exact', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.vendor_invoice_number LIKE ?'
        )->execute([$this->supplierId, self::DOC_PREFIX . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::MARKER . '%']);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE ?')
            ->execute([$this->supplierId, self::DOC_PREFIX . '%']);
    }

    private function seedPurchase(float $amount, string $date, ?string $last4): int
    {
        $no = self::DOC_PREFIX . (++$this->docSeq);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by, payment_method, card_last4)
             VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, 'received', ?, 'card', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, 'C2093' . $this->docSeq, $no,
            $date, $date, $date, $date, $this->currencyId, $amount, $amount, $this->userId, $last4,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedStatement(): int
    {
        $name = self::MARKER . (++$this->statementSeq) . '.gpc';
        $this->db->pdo()->prepare(
            "INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, 'CZK', ?)"
        )->execute([$this->supplierId, $name, hash('sha256', $name), $this->account, $this->bankCode, self::DAY]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedTransaction(int $statementId, float $amount, string $date, string $last4): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, counterparty_name, card_last4, description)
             VALUES (?, ?, ?, 'CZK', NULL, ?, ?)"
        )->execute([$statementId, $date, $amount, $last4, 'PK: 000000******' . $last4]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseStatus(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
    }

    private function matchCount(int $txId): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$txId}")->fetchColumn();
    }

    /** @return list<int> */
    private function matchedPurchases(int $txId): array
    {
        return array_map('intval', $this->db->pdo()->query(
            "SELECT purchase_invoice_id FROM payment_matches WHERE bank_transaction_id = {$txId} ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }
}
