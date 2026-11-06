<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\InvoiceOtherItemLinkException;
use MyInvoice\Service\Invoice\InvoiceOtherItemLinkService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Faktura krytá ostatní pohledávkou (migrace 2246): prodej pozemku zaúčtovaný smlouvou
 * (315 / 641), úhrada spárovaná na pohledávku, faktura bez DPH vystavená později.
 * Syntetická firma v podvojném účetnictví, rok 2099, VS 2099001, částka 185 000 Kč.
 */
#[Group('integration')]
final class InvoiceOtherItemLinkTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const VS = '2099001';
    private const AMOUNT = 185000.0;

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private InvoiceOtherItemLinkService $links;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private int $statementId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(OtherItemService::class);
        $this->links = $this->container->get(InvoiceOtherItemLinkService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', auto_match_other_items = 1 WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $ownAccount = '2099' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
             VALUES (?, "CZK", "Kč", "Kč", "koruna česká", "Czech koruna", ?, "0800")'
        )->execute([$this->supplierId, $ownAccount]);
        $this->currencyId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, "0800", ?, "CZK")'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), $ownAccount, '2099-03-31']);
        $this->statementId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testLinkReversesOwnPostingAndInvoiceIsCoveredByOtherItem(): void
    {
        $item = $this->postedItem();
        $invoice = $this->invoice();
        $ownEntry = $this->container->get(DocumentAutoPoster::class)->post($this->supplierId, 'invoice', $invoice, [], $this->userId);

        $result = $this->links->link($this->supplierId, $invoice, $item, $this->userId);

        self::assertSame([$ownEntry], array_keys($result['reversed_entries']), 'Vlastní 311/602 faktury se stornuje.');
        self::assertSame([], (new DocumentPostingGroup($this->db))->active($this->supplierId, 'invoice', $invoice));
        self::assertTrue($this->hasActivePosting($invoice), 'Fakturu účtuje zápis pohledávky.');
        self::assertFalse($this->hasActivePosting($invoice, false), 'Vlastní zápis faktura nemá.');
        self::assertNotNull($this->column($invoice, 'booked_at'));
        self::assertSame(0, $this->container->get(PendingBackfillCounter::class)->count($this->supplierId)['invoices'],
            'Doúčtování fakturu nenabízí.');
        try {
            $this->container->get(DocumentAutoPoster::class)->post($this->supplierId, 'invoice', $invoice);
            self::fail('Navázanou fakturu nelze zaúčtovat.');
        } catch (PostingException $e) {
            self::assertSame('invoice_covered_by_other_item', $e->errorCode);
        }
        try {
            $this->items->reverse($this->supplierId, $item, 'Syntetické storno', null);
            self::fail('Pohledávku, která účtuje fakturu, nelze stornovat.');
        } catch (OtherItemException $e) {
            self::assertSame('linked_invoice', $e->errorCode);
        }
        self::assertSame($invoice, $this->items->get($this->supplierId, $item)['linked_invoice']['id']);
    }

    public function testPaymentsMirrorOtherItemAllocationsWithoutJournalEntry(): void
    {
        $item = $this->postedItem();
        $invoice = $this->invoice();
        $this->links->link($this->supplierId, $invoice, $item, $this->userId);
        $txId = $this->transaction(self::AMOUNT);

        $result = $this->container->get(StatementMatcher::class)->matchBatch([$txId])[$txId];

        self::assertSame($item, $result['other_item_id'] ?? null, 'Platbu se shodným VS dostane pohledávka, ne faktura.');
        self::assertSame([['other_item', 185000.0, null]], $this->payments($invoice));
        self::assertSame('paid', $this->column($invoice, 'status'));
        self::assertSame(1, $this->bankEntryCount($txId), 'Úhrada se zaúčtuje jen jednou, na účet pohledávky.');
        self::assertNotContains('other_item', InvoicePaymentService::BANK_RECONCILABLE_SOURCES);

        $allocation = (int) $this->items->allocations($this->supplierId, $item)[0]['id'];
        $this->items->unallocate($this->supplierId, $item, $allocation);

        self::assertSame([], $this->payments($invoice));
        self::assertSame('issued', $this->column($invoice, 'status'));
        self::assertEqualsWithDelta(0.0, (float) $this->column($invoice, 'paid_total'), 0.001);
    }

    public function testBankPaymentCannotBeRecordedOnLinkedInvoice(): void
    {
        $invoice = $this->invoice();
        $this->links->link($this->supplierId, $invoice, $this->postedItem(), $this->userId);

        $this->expectException(\RuntimeException::class);
        $this->container->get(InvoicePaymentService::class)->recordPayment($invoice, self::AMOUNT, '2099-03-12', ['source' => 'bank']);
    }

    public function testUnlinkLeavesInvoiceUnpostedAndUnpaid(): void
    {
        $item = $this->postedItem();
        $invoice = $this->invoice();
        $this->links->link($this->supplierId, $invoice, $item, $this->userId);
        $this->container->get(StatementMatcher::class)->matchBatch([$txId = $this->transaction(self::AMOUNT)]);
        self::assertSame('paid', $this->column($invoice, 'status'));

        self::assertSame($item, $this->links->unlink($this->supplierId, $invoice));

        self::assertNull($this->column($invoice, 'other_item_id'));
        self::assertNull($this->column($invoice, 'booked_at'));
        self::assertSame([], $this->payments($invoice));
        self::assertSame('issued', $this->column($invoice, 'status'));
        self::assertFalse($this->hasActivePosting($invoice), 'Faktura je znovu nezaúčtovaná, automaticky se nepřeúčtuje.');
        self::assertSame(1, $this->allocationCount($txId), 'Úhrada zůstává pohledávce.');
    }

    public function testCancelUnlinksAndRemovesMirroredPayments(): void
    {
        $item = $this->postedItem();
        $invoice = $this->invoice();
        $this->links->link($this->supplierId, $invoice, $item, $this->userId);
        $this->container->get(StatementMatcher::class)->matchBatch([$this->transaction(self::AMOUNT)]);

        self::assertSame($item, InvoiceOtherItemLinkService::detachForCancel($this->pdo, $this->supplierId, $invoice));

        self::assertNull($this->column($invoice, 'other_item_id'));
        self::assertSame([], $this->payments($invoice));
        self::assertNull($this->items->get($this->supplierId, $item)['linked_invoice']);
    }

    public function testDraftLinkSyncsPaymentsOnIssue(): void
    {
        $item = $this->postedItem();
        $this->container->get(StatementMatcher::class)->matchBatch([$this->transaction(self::AMOUNT)]);
        $invoice = $this->invoice(['status' => 'draft']);

        $this->links->link($this->supplierId, $invoice, $item, $this->userId);
        self::assertSame([], $this->payments($invoice), 'Koncept platby neeviduje.');
        self::assertNull($this->column($invoice, 'booked_at'));

        $this->pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoice]);
        self::assertSame(['status' => 'linked'], $this->links->afterIssue($this->supplierId, $invoice, $this->userId));

        self::assertSame([['other_item', 185000.0, null]], $this->payments($invoice));
        self::assertSame('paid', $this->column($invoice, 'status'));
        self::assertNotNull($this->column($invoice, 'booked_at'));
    }

    public function testIssueUnlinksDraftThatNoLongerMatches(): void
    {
        $item = $this->postedItem();
        $invoice = $this->invoice(['status' => 'draft']);
        $this->links->link($this->supplierId, $invoice, $item, $this->userId);
        $this->pdo->prepare("UPDATE invoices SET status = 'issued', total_with_vat = 180000, total_without_vat = 180000 WHERE id = ?")
            ->execute([$invoice]);

        $result = $this->links->afterIssue($this->supplierId, $invoice, $this->userId);

        self::assertSame('unlinked', $result['status']);
        self::assertNull($this->column($invoice, 'other_item_id'));
    }

    public function testLinkConditionsAreEnforced(): void
    {
        $item = $this->postedItem();
        $this->assertRefused('invoice_has_vat', $this->invoice(['total_vat' => 38850.0, 'total_with_vat' => 223850.0]), $item);
        $this->assertRefused('amount_mismatch', $this->invoice(['total_without_vat' => 180000.0, 'total_with_vat' => 180000.0]), $item);
        $this->assertRefused('invalid_invoice_type', $this->invoice(['invoice_type' => 'proforma']), $item);
        $this->assertRefused('other_item_not_posted', $this->invoice(),
            (int) $this->items->create($this->supplierId, $this->itemInput(), null)['id']);
        $this->assertRefused('other_item_not_receivable', $this->invoice(),
            $this->postedItem(['side' => 'payable', 'counter_account_code' => '518']));

        $paid = $this->invoice();
        $this->pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
            VALUES (?, ?, '2099-03-01', 100, 'CZK', 'manual')")->execute([$this->supplierId, $paid]);
        $this->assertRefused('invoice_has_payments', $paid, $item);

        $first = $this->invoice();
        $this->links->link($this->supplierId, $first, $item, $this->userId);
        $this->assertRefused('other_item_taken', $this->invoice(), $item);
    }

    public function testCandidatesRankExactVariableSymbolFirstAndSuggestUniqueMatch(): void
    {
        $other = $this->postedItem(['variable_symbol' => '2099999', 'title' => 'Jiný pozemek']);
        $match = $this->postedItem();
        $invoice = $this->invoice();

        $candidates = $this->links->candidates($this->supplierId, $invoice);

        self::assertSame([$match, $other], array_column($candidates, 'id'), 'Shoda VS první.');
        self::assertTrue($candidates[0]['vs_match'] && $candidates[0]['amount_match']);
        $row = $this->container->get(\MyInvoice\Repository\InvoiceRepository::class)->find($invoice);
        self::assertSame($match, $this->links->withLink($this->supplierId, $row)['other_item_suggestion']['id'] ?? null);
    }

    private function assertRefused(string $code, int $invoice, int $item): void
    {
        try {
            $this->links->link($this->supplierId, $invoice, $item, $this->userId);
            self::fail('Navázání mělo skončit chybou ' . $code);
        } catch (InvoiceOtherItemLinkException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
        self::assertNull($this->column($invoice, 'other_item_id'));
    }

    private function hasActivePosting(int $invoice, bool $withCover = true): bool
    {
        $stmt = $this->pdo->prepare('SELECT ' . DocumentPostingGroup::activePostingExistsSql("'invoice'", 'i.id', 'i.supplier_id', true, $withCover)
            . ' FROM invoices i WHERE i.id = ?');
        $stmt->execute([$invoice]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $changes */
    private function postedItem(array $changes = []): int
    {
        $id = (int) $this->items->create($this->supplierId, $this->itemInput($changes), null)['id'];
        $this->items->post($this->supplierId, $id, null);
        return $id;
    }

    /** @param array<string,mixed> $changes */
    private function itemInput(array $changes = []): array
    {
        return array_replace([
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický prodej pozemku',
            'issued_on' => '2099-02-01', 'accounting_on' => '2099-02-01', 'due_on' => '2099-03-15',
            'currency' => 'CZK', 'amount' => self::AMOUNT, 'counter_account_code' => '641',
            'variable_symbol' => self::VS,
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function invoice(array $changes = []): int
    {
        $row = array_replace([
            'invoice_type' => 'invoice', 'status' => 'issued',
            'total_without_vat' => self::AMOUNT, 'total_vat' => 0.0, 'total_with_vat' => self::AMOUNT,
        ], $changes);
        $client = $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Syntetický kupující", "Testovací 1", "Praha", "11000", (SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1), "kupujici@example.com", "cs", ?, 1)'
        );
        $client->execute([$this->supplierId, $this->currencyId]);
        $clientId = (int) $this->pdo->lastInsertId();
        $varsymbol = $row['status'] === 'draft' ? null : self::VS . str_pad((string) ++$this->sequence, 3, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, payment_variable_symbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, "2099-03-05", "2099-03-05", "2099-03-20", ?, 0, ?, ?, ?, 0, ?, "1", ?)'
        )->execute([$this->supplierId, $varsymbol, self::VS, $row['invoice_type'], $clientId, $this->currencyId,
            $row['total_without_vat'], $row['total_vat'], $row['total_with_vat'],
            $row['status'], $this->userId]);
        $id = (int) $this->pdo->lastInsertId();
        $vatRate = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY rate_percent, id LIMIT 1')->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Pozemek', 1, 'ks', ?, ?, 0, ?, ?, ?, 0)"
        )->execute([$id, $row['total_without_vat'], $vatRate, $row['total_without_vat'], $row['total_vat'], $row['total_with_vat']]);
        return $id;
    }

    private function transaction(float $amount): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, variable_symbol, counterparty_account,
                 counterparty_bank, counterparty_name, description)
             VALUES (?, "2099-03-12", ?, "CZK", "unmatched", ?, "1000000005", "0100", "Syntetický kupující", "Kupní cena")'
        )->execute([$this->statementId, $amount, self::VS]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array{0:string,1:float,2:?int}> */
    private function payments(int $invoice): array
    {
        $stmt = $this->pdo->prepare('SELECT source, amount, bank_transaction_id FROM invoice_payments WHERE invoice_id = ? ORDER BY id');
        $stmt->execute([$invoice]);
        return array_map(static fn (array $row): array => [(string) $row[0], (float) $row[1], $row[2] !== null ? (int) $row[2] : null],
            $stmt->fetchAll(PDO::FETCH_NUM));
    }

    private function column(int $invoice, string $column): mixed
    {
        $stmt = $this->pdo->prepare("SELECT {$column} FROM invoices WHERE id = ?");
        $stmt->execute([$invoice]);
        return $stmt->fetchColumn();
    }

    private function bankEntryCount(int $txId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ?");
        $stmt->execute([$this->supplierId, $txId]);
        return (int) $stmt->fetchColumn();
    }

    private function allocationCount(int $txId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM other_item_allocations WHERE bank_transaction_id = ? AND reversed_on IS NULL');
        $stmt->execute([$txId]);
        return (int) $stmt->fetchColumn();
    }
}
