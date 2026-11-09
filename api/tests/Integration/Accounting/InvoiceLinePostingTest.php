<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DraftPostingPreviewAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Zaúčtování zadané už na řádku faktury: protiúčet a předkontace řádku (migrace 2265)
 * a náhled zápisu v editoru z neuloženého stavu.
 *
 * Hlídá tři věci: řádek s účty se zaúčtuje podle nich, doklad bez nových polí se
 * zaúčtuje přesně jako dřív a náhled v editoru dá tytéž řádky, jaké pak vzniknou
 * zaúčtováním, aniž by cokoli uložil. Vše v transakci s rollbackem, data syntetická.
 */
#[Group('integration')]
final class InvoiceLinePostingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const DATE = '2026-03-02';

    private Connection $db;
    private PostingService $posting;
    private JournalAction $journal;
    private DraftPostingPreviewAction $preview;
    private CreatePurchaseInvoiceAction $createPurchase;
    private CreateInvoiceAction $createInvoice;
    private PurchaseInvoiceRepository $purchases;

    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $rate21 = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->posting = $c->get(PostingService::class);
            $this->journal = $c->get(JournalAction::class);
            $this->preview = $c->get(DraftPostingPreviewAction::class);
            $this->createPurchase = $c->get(CreatePurchaseInvoiceAction::class);
            $this->createInvoice = $c->get(CreateInvoiceAction::class);
            $this->purchases = $c->get(PurchaseInvoiceRepository::class);
            $seeder = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate21 = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $sourceCurrency = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->rate21, $czId, $sourceCurrency], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '2020-01-01', true);
        $seeder->seedForSupplier($this->supplierId);

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             SELECT ?, code, label, symbol, name_cs, name_en, decimals, 1, 1 FROM currencies WHERE id = ?'
        )->execute([$this->supplierId, $sourceCurrency]);
        $this->currencyId = (int) $pdo->lastInsertId();

        // Bez DIČ: založení přijaté faktury jinak sahá na CRPDPH (síť).
        $client = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Praha", "11000", ?, ?, "cs", ?, ?, ?)'
        );
        $client->execute([$this->supplierId, 'TEST dodavatel řádků', $czId, 'vendor@example.test', $this->currencyId, 0, 1]);
        $this->vendorId = (int) $pdo->lastInsertId();
        $client->execute([$this->supplierId, 'TEST odběratel řádků', $czId, 'customer@example.test', $this->currencyId, 1, 0]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Firma, která nová pole nepoužije: zápis přijaté faktury přesně jako dřív. */
    public function testPurchaseWithoutLineFieldsPostsAsBefore(): void
    {
        $id = $this->receivedPurchase([$this->purchaseItem()]);

        self::assertSame(
            ['518|debit|10000.00', '343.100|debit|2100.00', '321|credit|12100.00'],
            self::signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $id)),
        );
    }

    /** Firma, která nová pole nepoužije: zápis vydané faktury přesně jako dřív. */
    public function testIssuedWithoutLineFieldsPostsAsBefore(): void
    {
        $id = $this->issuedInvoice([$this->saleItem()]);

        self::assertSame(
            ['311|debit|12100.00', '602|credit|10000.00', '343.200|credit|2100.00'],
            self::signature($this->posting->buildFromInvoice($this->supplierId, $id)),
        );
    }

    /** BEZ OPRAVY PADÁ: protiúčet řádku se ignoroval a závazek šel vždy na 321. */
    public function testPurchaseLineCounterAccountReplacesPayable(): void
    {
        $id = $this->receivedPurchase([$this->purchaseItem(['expense_account_code' => '501', 'counter_account_code' => '325'])]);

        self::assertSame(
            ['501|debit|10000.00', '343.100|debit|2100.00', '325|credit|12100.00'],
            self::signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $id)),
        );
    }

    /** BEZ OPRAVY PADÁ: předkontace řádku doplní účet MD i protiúčet, ruční účet má přednost. */
    public function testPurchaseLineRuleFillsMissingAccounts(): void
    {
        $this->rule('test.line.material', '501', '325');
        $id = $this->receivedPurchase([
            $this->purchaseItem(['posting_rule_key' => 'test.line.material']),
            $this->purchaseItem(['posting_rule_key' => 'test.line.material', 'expense_account_code' => '548', 'unit_price_without_vat' => 1000]),
        ]);

        self::assertSame(
            ['501|debit|10000.00', '548|debit|1000.00', '343.100|debit|2310.00', '325|credit|13310.00'],
            self::signature($this->posting->buildFromPurchaseInvoice($this->supplierId, $id)),
        );
    }

    /** Dva různé závazky by úhrada neuzavřela: zaúčtování to odmítne. */
    public function testPurchaseMixedCounterAccountsAreRejected(): void
    {
        $id = $this->receivedPurchase([
            $this->purchaseItem(['counter_account_code' => '325']),
            $this->purchaseItem(['counter_account_code' => '321']),
        ]);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
            self::fail('Různé protiúčty řádků musí zaúčtování odmítnout.');
        } catch (PostingException $e) {
            self::assertSame('mixed_counter_accounts', $e->errorCode);
        }
    }

    /** Protiúčet přijaté faktury musí být závazek, ne náklad ani DPH. */
    public function testPurchaseCounterMustBeLiability(): void
    {
        $id = $this->receivedPurchase([$this->purchaseItem(['counter_account_code' => '518'])]);

        $this->expectException(PostingException::class);
        $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
    }

    /** BEZ OPRAVY PADÁ: předkontace řádku vydané faktury určí výnosový účet řádku. */
    public function testIssuedLineRuleFillsRevenueAccount(): void
    {
        $id = $this->issuedInvoice([
            $this->saleItem(['posting_rule_key' => 'invoice.goods.issued']),
            $this->saleItem(),
        ]);

        self::assertSame(
            ['311|debit|24200.00', '604|credit|10000.00', '602|credit|10000.00', '343.200|credit|4200.00'],
            self::signature($this->posting->buildFromInvoice($this->supplierId, $id)),
        );
    }

    /** Úhrada vydané faktury odúčtuje 311: protiúčet mimo ni by saldo neuzavřel. */
    public function testIssuedCounterOutsideReceivableIsRejected(): void
    {
        $id = $this->issuedInvoice([$this->saleItem(['counter_account_code' => '315'])]);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $id);
            self::fail('Protiúčet mimo pohledávku 311 musí zaúčtování odmítnout.');
        } catch (PostingException $e) {
            self::assertSame('invalid_counter_account', $e->errorCode);
        }
    }

    /** Neplatný tvar protiúčtu je validační chyba uložení, ne chyba databáze. */
    public function testInvalidCounterFormatIsSaveValidationError(): void
    {
        $res = self::decode(($this->createPurchase)(
            $this->request('POST', $this->purchasePayload([$this->purchaseItem(['counter_account_code' => 'abc; DROP'])])),
            new Psr7Response(),
        ));

        self::assertSame(400, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * JÁDRO: náhled v editoru z NEULOŽENÉHO stavu nic nezapíše a dá přesně tytéž řádky,
     * jaké vzniknou uložením, přijetím a zaúčtováním.
     */
    public function testDraftPreviewMatchesPostingAndWritesNothing(): void
    {
        $payload = $this->purchasePayload([
            $this->purchaseItem(['expense_account_code' => '501', 'counter_account_code' => '325']),
            $this->purchaseItem(['unit_price_without_vat' => 333.33]),
        ]);
        $before = $this->purchaseCount();

        $preview = $this->draftPreview('purchase-invoices', null, $payload);

        self::assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
        self::assertTrue($preview['body']['balanced']);
        self::assertSame($before, $this->purchaseCount(), 'Náhled nesmí doklad uložit.');

        $created = self::decode(($this->createPurchase)($this->request('POST', $payload), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];
        $this->purchases->setStatus($id, 'received', $this->supplierId);
        $posted = self::decode($this->journal->postPurchase(
            $this->request('POST', []),
            new Psr7Response(),
            ['id' => (string) $id],
        ));
        self::assertSame(200, $posted['status'], json_encode($posted['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame(self::signature($this->journalLines($id)), self::signature($preview['body']['lines']));
        self::assertContains('325|credit|12503.33', self::signature($preview['body']['lines']));
    }

    /** Náhled nového konceptu vydané faktury: koncept se pro náhled bere jako vystavený. */
    public function testDraftPreviewOfNewIssuedInvoice(): void
    {
        $preview = $this->draftPreview('invoices', null, $this->invoicePayload([$this->saleItem(['posting_rule_key' => 'invoice.goods.issued'])]));

        self::assertSame(200, $preview['status'], json_encode($preview['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            ['311|debit|12100.00', '604|credit|10000.00', '343.200|credit|2100.00'],
            self::signature($preview['body']['lines']),
        );
    }

    /** Chyba uložení (tady chybějící dodavatel) je odpovědí náhledu, nic se neuloží. */
    public function testDraftPreviewReturnsSaveValidationError(): void
    {
        $payload = $this->purchasePayload([$this->purchaseItem()]);
        unset($payload['vendor_id']);

        $preview = $this->draftPreview('purchase-invoices', null, $payload);

        self::assertSame(400, $preview['status']);
    }

    /** Zaúčtovaný doklad se nesimuluje, náhled jen odkáže na živý zápis. */
    public function testDraftPreviewOfPostedDocumentReturnsEntry(): void
    {
        $id = $this->receivedPurchase([$this->purchaseItem()]);
        $posted = self::decode($this->journal->postPurchase($this->request('POST', []), new Psr7Response(), ['id' => (string) $id]));
        self::assertSame(200, $posted['status'], json_encode($posted['body'], JSON_UNESCAPED_UNICODE));

        $preview = $this->draftPreview('purchase-invoices', $id, $this->purchasePayload([$this->purchaseItem()]));

        self::assertSame(200, $preview['status']);
        self::assertSame((int) $posted['body']['id'], $preview['body']['already_posted']);
        self::assertSame([], $preview['body']['lines']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $lines @return list<string> */
    private static function signature(array $lines): array
    {
        return array_map(
            static fn (array $l): string => $l['account_code'] . '|' . $l['side'] . '|' . number_format((float) $l['amount'], 2, '.', ''),
            $lines,
        );
    }

    private function rule(string $key, string $debit, string $credit): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, "Test předkontace řádku", ?, ?, 0, 1)'
        )->execute([$this->supplierId, $key, $debit, $credit]);
    }

    /** @param list<array<string,mixed>> $items */
    private function receivedPurchase(array $items): int
    {
        $created = self::decode(($this->createPurchase)($this->request('POST', $this->purchasePayload($items)), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];
        $this->purchases->setStatus($id, 'received', $this->supplierId);

        return $id;
    }

    /** @param list<array<string,mixed>> $items */
    private function issuedInvoice(array $items): int
    {
        $created = self::decode(($this->createInvoice)($this->request('POST', $this->invoicePayload($items)), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$id]);

        return $id;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function purchasePayload(array $items): array
    {
        static $seq = 0;
        $seq++;

        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'LINE-' . $seq,
            'document_kind'         => 'invoice',
            'issue_date'            => self::DATE,
            'tax_date'              => self::DATE,
            'due_date'              => self::DATE,
            'received_at'           => self::DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => $items,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function invoicePayload(array $items): array
    {
        return [
            'client_id'          => $this->clientId,
            'invoice_type'       => 'invoice',
            'issue_date'         => self::DATE,
            'tax_date'           => self::DATE,
            'due_date'           => self::DATE,
            'currency_id'        => $this->currencyId,
            'prices_include_vat' => false,
            'items'              => $items,
        ];
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function purchaseItem(array $override = []): array
    {
        return $override + [
            'description'            => 'Služba',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 10000,
            'vat_rate_id'            => $this->rate21,
        ];
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function saleItem(array $override = []): array
    {
        return $override + [
            'description'            => 'Zboží',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 10000,
            'vat_rate_id'            => $this->rate21,
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @return array{status:int, body:array<string,mixed>}
     */
    private function draftPreview(string $source, ?int $docId, array $document): array
    {
        return self::decode(($this->preview)(
            $this->request('POST', ['doc_id' => $docId, 'document' => $document]),
            new Psr7Response(),
            ['source' => $source],
        ));
    }

    /** @return list<array<string,mixed>> */
    private function journalLines(int $purchaseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, l.side, l.amount
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.source_id = ? AND e.reversed_by IS NULL
              ORDER BY l.line_no, l.id"
        );
        $stmt->execute([$this->supplierId, $purchaseId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function purchaseCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
