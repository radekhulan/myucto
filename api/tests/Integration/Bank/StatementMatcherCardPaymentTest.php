<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Card\CardMerchantMap;
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
    /** @var array<string,int> */
    private array $foreignCurrencies = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
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

    public function testLateCardDocumentDoesNotUseOtherItemPayment(): void
    {
        $doc = $this->seedPurchase(812.50, '2093-06-13', '4321');
        $tx = $this->seedTransaction($this->seedStatement(), -812.50, self::DAY, '4321');
        $this->allocateOtherItemPayment($tx, 812.50);

        self::assertNull($this->matcher->matchCardDocument($this->supplierId, $doc));
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    public function testLockedCardBranchRejectsOtherItemPayment(): void
    {
        $doc = $this->seedPurchase(410.00, '2093-06-14', '4321');
        $tx = $this->seedTransaction($this->seedStatement(), -410.00, self::DAY, '4321');
        $this->allocateOtherItemPayment($tx, 410.00);

        $result = $this->callBranch('matchPurchaseByCard', [$this->db->pdo(), $this->supplierId, '4321', 410.00, self::DAY, $tx, 'CZK']);

        self::assertSame('unmatched', $result['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    public function testLockedAmountDateBranchRejectsOtherItemPayment(): void
    {
        $doc = $this->seedPurchase(410.00, '2093-06-14', null);
        $statement = $this->seedStatement();
        $tx = $this->seedTransaction($statement, -410.00, self::DAY, '4321');
        $this->allocateOtherItemPayment($tx, 410.00);

        $result = $this->callBranch('matchPurchaseByAmountDate', [
            $this->db->pdo(), $this->supplierId, 410.00, self::DAY, $tx, 'CZK', $statement, '', '4321',
        ]);

        self::assertSame('other_item_allocated', $result['reason'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    /**
     * Doklad placený kartou bez koncovky: částka sedí na haléř a o tutéž částku nesoupeří
     * žádný jiný pohyb kartou, takže není co hádat. Spáruje se a doklad si koncovku zapamatuje.
     */
    public function testCardDocumentWithoutLast4IsPairedWhenExactAndUncontested(): void
    {
        $doc = $this->seedPurchase(300.00, '2093-06-14', null);
        $tx = $this->seedTransaction($this->seedStatement(), -300.00, self::DAY, '1111');

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null, (string) json_encode($res));
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('paid', $this->purchaseStatus($doc));
        self::assertSame([$doc], $this->matchedPurchases($tx));
        self::assertSame('1111', $this->db->pdo()->query("SELECT card_last4 FROM purchase_invoices WHERE id = {$doc}")->fetchColumn());
    }

    /** Částka sedí jen v haléřové toleranci, ne přesně: doklad bez koncovky zůstává návrhem. */
    public function testCardDocumentWithoutLast4AndInexactAmountIsOnlySuggested(): void
    {
        $doc = $this->seedPurchase(300.00, '2093-06-14', null);
        $tx = $this->seedTransaction($this->seedStatement(), -300.04, self::DAY, '1111');

        $res = $this->matcher->match($tx);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('card_match_requires_review', $res['reason'] ?? null);
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
    }

    /**
     * Cizoměnová služba zaplacená kartou z korunového účtu: obchodník píše značku dohromady
     * („ELEVENLABS.IO" proti „Eleven Labs") a karetní schéma přidá k ČNB kurzu přes 4 %.
     */
    public function testForeignDocumentPaidByCardWithMarkupAndJoinedMerchantName(): void
    {
        $vendor = $this->seedVendor('Elevenzz Labsyy Synthetic Inc.');
        $doc = $this->seedPurchase(100.00, '2093-06-14', null, 'bank_transfer', 0.0, $vendor, $this->foreignCurrencyId('EUR'), 25.00);
        $tx = $this->seedTransaction($this->seedStatement(), -2610.00, self::DAY, '6801',
            "ELEVENZZLABSYY.IO\nNEW YORK USA\nPK: 535499******6801", 'Banka Creditas');

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null, (string) json_encode($res));
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('paid', $this->purchaseStatus($doc));
    }

    public function testForeignDocumentPaidByCardBeyondCardToleranceIsNotPaired(): void
    {
        $vendor = $this->seedVendor('Elevenzz Labsyy Synthetic Inc.');
        $doc = $this->seedPurchase(100.00, '2093-06-14', null, 'bank_transfer', 0.0, $vendor, $this->foreignCurrencyId('EUR'), 25.00);
        $tx = $this->seedTransaction($this->seedStatement(), -2680.00, self::DAY, '6801',
            "ELEVENZZLABSYY.IO\nNEW YORK USA\nPK: 535499******6801", 'Banka Creditas');

        $res = $this->matcher->match($tx);

        self::assertNotSame('auto_exact', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
    }

    /**
     * Pohyb z účtu v jiné cizí měně (USD) za doklad v EUR: banka uvádí původní částku nákupu
     * v EUR a ta sedí na cent, takže se páruje bez jakéhokoli kurzu.
     */
    public function testDocumentPaidFromOtherForeignCurrencyAccountByOriginalAmount(): void
    {
        $vendor = $this->seedVendor('Gogolplexx Synthetic LLC');
        $doc = $this->seedPurchase(25.00, '2093-06-13', null, 'bank_transfer', 0.0, $vendor, $this->foreignCurrencyId('EUR'), 25.00);
        $tx = $this->seedTransaction($this->seedStatement(), -27.41, self::DAY, '8783',
            "Gogolplexx Play\nMountain View USA\nPK: 535499******8783", 'Banka Creditas', 'USD', -25.00, 'EUR');

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null, (string) json_encode($res));
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);

        $other = $this->seedPurchase(30.00, '2093-06-13', null, 'bank_transfer', 0.0, $vendor, $this->foreignCurrencyId('EUR'), 25.00);
        $tx2 = $this->seedTransaction($this->seedStatement(), -27.41, self::DAY, '8783',
            "Gogolplexx Play\nMountain View USA\nPK: 535499******8783", 'Banka Creditas', 'USD', -25.00, 'EUR');

        self::assertNotSame('auto_exact', $this->matcher->match($tx2)['status'] ?? null, 'Jiná původní částka než doklad se nepáruje.');
        self::assertSame('received', $this->purchaseStatus($other));
    }

    /**
     * Obchodník, jehož text se s názvem dodavatele neshoduje: po ručním spárování se vazba
     * naučí a další pohyb téhož obchodníka už najde doklad sám.
     */
    public function testLearnedMerchantPairsDocumentWithUnrelatedName(): void
    {
        $vendor = $this->seedVendor('Qwvx Drahy Synthetic a.s.');
        $merchant = "www.zzqq.test/eshop/\nPraha 1 CZE\nPK: 535499******5194";
        $doc = $this->seedPurchase(455.00, '2093-06-14', null, 'bank_transfer', 0.0, $vendor);
        $tx = $this->seedTransaction($this->seedStatement(), -455.00, self::DAY, '5194', $merchant, 'Banka Creditas');

        self::assertNotSame('auto_exact', $this->matcher->match($tx)['status'] ?? null);

        $earlier = $this->seedTransaction($this->seedStatement(), -120.00, '2093-06-01', '5194', $merchant, 'Banka Creditas');
        $row = $this->db->pdo()->query("SELECT * FROM bank_transactions WHERE id = {$earlier}")->fetch(PDO::FETCH_ASSOC);
        (new CardMerchantMap($this->db))->recordManual($this->supplierId, $row, $vendor);

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null, (string) json_encode($res));
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
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

    /**
     * Souběh: pohyb mezitím spároval jiný běh (tady ručně). Větev kartou dřív
     * označila doklad jako zaplacený a vložila párování, i když podmíněný UPDATE
     * pohybu nezměnil nic — tatáž platba pak visela na dvou dokladech.
     */
    public function testCardBranchDoesNotPayDocumentOfTransactionMatchedMeanwhile(): void
    {
        $doc = $this->seedPurchase(410.00, '2093-06-14', '4321');
        $tx = $this->seedTransaction($this->seedStatement(), -410.00, self::DAY, '4321');
        $this->markManual($tx);

        $res = $this->callBranch('matchPurchaseByCard', [$this->db->pdo(), $this->supplierId, '4321', 410.00, self::DAY, $tx, 'CZK']);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    /** Totéž pro párování podle VS — konzistentně se všemi větvemi, které vkládají párování. */
    public function testVsBranchDoesNotPayDocumentOfTransactionMatchedMeanwhile(): void
    {
        $doc = $this->seedPurchase(420.00, '2093-06-14', null);
        $tx = $this->seedTransaction($this->seedStatement(), -420.00, self::DAY, '4321');
        $this->markManual($tx);

        $res = $this->callBranch('matchPurchase', [$this->db->pdo(), $this->supplierId, 'C2093' . $this->docSeq, 420.00, self::DAY, $tx, 'CZK']);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    /** #46 — důvod nespárování se uloží k pohybu a spárováním zmizí. */
    public function testUnmatchedReasonIsStoredAndClearedOnMatch(): void
    {
        $tx = $this->seedTransaction($this->seedStatement(), -275.00, self::DAY, '9876');

        $res = $this->matcher->match($tx);

        self::assertSame('unmatched', $res['status'] ?? null);
        self::assertIsString($res['reason'] ?? null);
        self::assertSame($res['reason'], $this->matchReason($tx));

        $doc = $this->seedPurchase(275.00, '2093-06-14', '9876');
        $again = $this->matcher->matchBatch([$tx])[$tx];

        self::assertSame('auto_exact', $again['status'] ?? null);
        self::assertSame($doc, $again['purchase_invoice_id'] ?? null);
        self::assertNull($this->matchReason($tx));
    }

    /**
     * Doklad bez karty (AI import přečetl „Google Pay" jako jiný způsob úhrady) se
     * zaokrouhlením: obchodník je jen v prvním řádku popisu, jménem protistrany je banka.
     * Shoda dodavatele s obchodníkem, částky na haléř a data stačí k automatickému párování.
     */
    public function testDocumentWithoutCardIsPairedByMerchantNameAndRoundedAmount(): void
    {
        $doc = $this->seedPurchase(12467.80, '2093-06-13', null, 'other', 0.20);
        $tx = $this->seedTransaction($this->seedStatement(), -12468.00, self::DAY, '5194',
            $this->vendorName() . "\nPrague CZE\nPK: 535499******5194", 'Banka Creditas');

        $res = $this->matcher->match($tx);

        self::assertSame('auto_exact', $res['status'] ?? null);
        self::assertSame($doc, $res['purchase_invoice_id'] ?? null);
        self::assertSame('paid', $this->purchaseStatus($doc));
        self::assertSame([$doc], $this->matchedPurchases($tx));
    }

    public function testDocumentWithoutCardIsNotPairedWhenMerchantDiffers(): void
    {
        $doc = $this->seedPurchase(12467.80, '2093-06-13', null, 'other', 0.20);
        $tx = $this->seedTransaction($this->seedStatement(), -12468.00, self::DAY, '5194',
            "Zzyzx Obchod\nPrague CZE\nPK: 535499******5194", 'Banka Creditas');

        $res = $this->matcher->match($tx);

        self::assertNotSame('auto_exact', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    public function testDocumentWithoutCardIsNotPairedWhenOtherCardChargedSameAmount(): void
    {
        $doc = $this->seedPurchase(12467.80, '2093-06-13', null, 'other', 0.20);
        $statement = $this->seedStatement();
        $merchant = $this->vendorName() . "\nPrague CZE\nPK: 535499******5194";
        $tx = $this->seedTransaction($statement, -12468.00, self::DAY, '5194', $merchant, 'Banka Creditas');
        $this->seedTransaction($statement, -12468.00, self::DAY, '6801', $merchant, 'Banka Creditas');

        $res = $this->matcher->matchBatch([$tx])[$tx];

        self::assertNotSame('auto_exact', $res['status'] ?? null);
        self::assertSame('received', $this->purchaseStatus($doc));
        self::assertSame(0, $this->matchCount($tx));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function vendorName(): string
    {
        return (string) $this->db->pdo()->query("SELECT company_name FROM clients WHERE id = {$this->vendorId}")->fetchColumn();
    }

    private function matchReason(int $txId): ?string
    {
        $v = $this->db->pdo()->query("SELECT match_reason FROM bank_transactions WHERE id = {$txId}")->fetchColumn();
        return $v === null || $v === false ? null : (string) $v;
    }

    private function markManual(int $txId): void
    {
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$txId]);
    }

    /**
     * Větev párování volaná napřímo — přes match() by ji guard na uložený stav
     * vůbec nepustil, a právě mezeru mezi čtením stavu a zápisem test simuluje.
     *
     * @param list<mixed> $args
     * @return array<string,mixed>
     */
    private function callBranch(string $method, array $args): array
    {
        $ref = new \ReflectionMethod(StatementMatcher::class, $method);
        return (array) $ref->invokeArgs($this->matcher, $args);
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.vendor_invoice_number LIKE ?'
        )->execute([$this->supplierId, self::DOC_PREFIX . '%']);
        $pdo->prepare('DELETE FROM bank_statements WHERE file_name LIKE ?')->execute(['%' . self::MARKER . '%']);
        $pdo->prepare('DELETE FROM other_items WHERE supplier_id = ? AND title = ?')
            ->execute([$this->supplierId, self::MARKER]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE ?')
            ->execute([$this->supplierId, self::DOC_PREFIX . '%']);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND note = ?')->execute([$this->supplierId, self::MARKER]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND label = ?')->execute([$this->supplierId, self::MARKER]);
    }

    private function seedVendor(string $name): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer, is_vendor, note)
             SELECT ?, ?, 'Test 1', 'Test', '10000', country_id, ?, 0, 1, ? FROM clients WHERE id = ?"
        )->execute([$this->supplierId, $name, $this->currencyId, self::MARKER, $this->vendorId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function foreignCurrencyId(string $code): int
    {
        if (isset($this->foreignCurrencies[$code])) {
            return $this->foreignCurrencies[$code];
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $this->db->pdo()->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$this->supplierId, $code, self::MARKER, $code, $code, $code]);
            $id = (int) $this->db->pdo()->lastInsertId();
        }
        return $this->foreignCurrencies[$code] = $id;
    }

    private function seedPurchase(
        float $amount,
        string $date,
        ?string $last4,
        string $paymentMethod = 'card',
        float $rounding = 0.0,
        ?int $vendorId = null,
        ?int $currencyId = null,
        ?float $rate = null,
    ): int {
        $no = self::DOC_PREFIX . (++$this->docSeq);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, vendor_snapshot,
                 total_without_vat, total_with_vat, rounding, status, created_by, payment_method, card_last4)
             VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, ?, '{}', ?, ?, ?, 'received', ?, ?, ?)"
        )->execute([
            $this->supplierId, $vendorId ?? $this->vendorId, 'C2093' . $this->docSeq, $no,
            $date, $date, $date, $date, $currencyId ?? $this->currencyId, $rate, $amount, $amount, $rounding, $this->userId, $paymentMethod, $last4,
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

    private function seedTransaction(
        int $statementId,
        float $amount,
        string $date,
        string $last4,
        ?string $description = null,
        ?string $counterpartyName = null,
        string $currency = 'CZK',
        ?float $originalAmount = null,
        ?string $originalCurrency = null,
    ): int {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, original_amount, original_currency, counterparty_name, card_last4, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$statementId, $date, $amount, $currency, $originalAmount, $originalCurrency, $counterpartyName, $last4, $description ?? 'PK: 000000******' . $last4]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function allocateOtherItemPayment(int $transactionId, float $amount): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO other_items (supplier_id, side, title, issued_on, due_on, amount, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, 'payable', self::MARKER, self::DAY, self::DAY, $amount, 'confirmed']);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->supplierId, $itemId, $transactionId, $amount, self::DAY]);
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
