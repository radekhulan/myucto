<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Accounting\JournalForDocumentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * GET /api/accounting/journal/for-document/{source}/{id} — podklad sbalené sekce
 * „Zaúčtování" na detailu faktury.
 *
 * Hlídá to, na čem sekce stojí: nezaúčtovaný doklad vrací prázdno (sekce se vůbec
 * nezobrazí), zaúčtovaný vrací zápis i s kódy a názvy účtů, a po stornu vrací
 * OBĚ strany případu (původní zápis i protizápis).
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class JournalForDocumentActionTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
    private JournalForDocumentAction $forDocumentAction;
    private AccountingPeriodRepository $periods;
    private PostingService $posting;
    private OtherItemService $otherItems;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db                = $container->get(Connection::class);
            $this->journalAction     = $container->get(JournalAction::class);
            $this->forDocumentAction = $container->get(JournalForDocumentAction::class);
            $this->periods           = $container->get(AccountingPeriodRepository::class);
            $this->posting           = $container->get(PostingService::class);
            $this->otherItems        = $container->get(OtherItemService::class);
            $seeder                  = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $mode = (string) $pdo->query("SELECT accounting_mode FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
        if ($mode !== 'double_entry') {
            $this->markTestSkipped('Firma není v podvojném účetnictví — deník neexistuje.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testUnpostedInvoiceReturnsNoEntries(): void
    {
        $invoiceId = $this->sale('FV-2099-DOC-1', $this->client('Odběratel s.r.o.'), '1', 1000.00, 210.00, 21.00);

        $res = $this->forDocument('invoices', $invoiceId);

        self::assertSame(200, $res['status']);
        self::assertSame([], $res['body']['items'], 'Nezaúčtovaný doklad → prázdno, sekce se nezobrazí.');
    }

    public function testPostedInvoiceReturnsEntryWithNamedAccounts(): void
    {
        $invoiceId = $this->sale('FV-2099-DOC-2', $this->client('Odběratel s.r.o.'), '1', 1000.00, 210.00, 21.00);
        $posted = $this->call('postInvoice', 'POST', ['id' => (string) $invoiceId]);
        self::assertSame(200, $posted['status']);
        $entryId = (int) $posted['body']['id'];

        $res = $this->forDocument('invoices', $invoiceId);

        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['items']);
        $entry = $res['body']['items'][0];
        self::assertSame($entryId, (int) $entry['id']);
        self::assertSame('invoice', $entry['source_type']);
        self::assertNotEmpty($entry['lines'], 'Zápis chodí i s řádky — sekce je kreslí rovnou.');

        foreach ($entry['lines'] as $line) {
            self::assertNotNull($line['account_code'], 'Řádek nese kód účtu, ne jen account_id.');
            self::assertNotNull($line['account_name'], 'Řádek nese název účtu — uživatel nečte holá čísla.');
        }
    }

    public function testReversedInvoiceReturnsBothEntries(): void
    {
        $invoiceId = $this->sale('FV-2099-DOC-3', $this->client('Odběratel s.r.o.'), '1', 1000.00, 210.00, 21.00);
        $posted = $this->call('postInvoice', 'POST', ['id' => (string) $invoiceId]);
        $entryId = (int) $posted['body']['id'];
        $rev = $this->call('reverse', 'POST', ['id' => (string) $entryId], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame(201, $rev['status']);

        $res = $this->forDocument('invoices', $invoiceId);

        self::assertCount(2, $res['body']['items'], 'Po stornu vidí účetní původní zápis i protizápis.');
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $res['body']['items']);
        self::assertSame($entryId, $ids[0], 'Řazení od nejstaršího zápisu.');
        self::assertGreaterThan($ids[0], $ids[1]);
    }

    public function testPostedOtherItemReturnsOwnEntry(): void
    {
        $itemId = $this->otherItem($this->freeId(), 'posted');
        $entryId = $this->posting->postDocument($this->supplierId, 'other_item', $itemId, [
            ['account_code' => '378', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '648', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted' => true]);

        $res = $this->forDocument('other-items', $itemId);

        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['items']);
        $entry = $res['body']['items'][0];
        self::assertSame($entryId, (int) $entry['id']);
        self::assertSame('other_item', $entry['source_type']);
        self::assertSame('own', $entry['relation']);
        self::assertSame(['378', '648'], array_map(static fn (array $l): string => (string) $l['account_code'], $entry['lines']));
    }

    public function testReversedOtherItemReturnsEntryAndReversal(): void
    {
        $itemId = $this->otherItem($this->freeId(), 'posted');
        $entryId = $this->posting->postDocument($this->supplierId, 'other_item', $itemId, [
            ['account_code' => '378', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '648', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted' => true]);
        // Storno ostatní položky jde přes její detail (deník ho odmítne), stejně jako v aplikaci.
        $this->db->pdo()->prepare('UPDATE other_items SET journal_entry_id = ? WHERE id = ?')->execute([$entryId, $itemId]);
        $this->otherItems->reverse($this->supplierId, $itemId, 'Syntetické storno', $this->userId, self::YEAR . '-06-30');

        $ids = array_map(static fn (array $e): int => (int) $e['id'], $this->forDocument('other-items', $itemId)['body']['items']);

        self::assertCount(2, $ids, 'Po stornu vidí účetní u položky zápis i protizápis.');
        self::assertSame($entryId, $ids[0]);
    }

    /**
     * Zálohy bez vlastního zápisu mají jen faktury. Koncept ostatní položky se stejným id
     * jako zaplacená proforma nesmí převzít zápis konečné faktury té proformy.
     */
    public function testDraftOtherItemDoesNotBorrowEntriesOfProformaWithSameId(): void
    {
        $id = $this->freeId();
        $this->otherItem($id, 'draft');
        $proformaId = $this->sale('ZF-2099-DOC-1', $this->client('Odběratel s.r.o.'), '1', 1000.00, 210.00, 21.00, $id);
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'proforma' WHERE id = ?")->execute([$proformaId]);
        $finalId = $this->sale('FV-2099-DOC-4', $this->client('Odběratel s.r.o.'), '1', 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$proformaId, $finalId]);
        self::assertSame(200, $this->call('postInvoice', 'POST', ['id' => (string) $finalId])['status']);
        self::assertNotSame([], $this->forDocument('invoices', $proformaId)['body']['items'], 'Proforma vidí zápis konečné faktury.');

        $res = $this->forDocument('other-items', $id);

        self::assertSame(200, $res['status']);
        self::assertSame([], $res['body']['items']);
    }

    public function testUnknownSourceIsRejected(): void
    {
        $res = $this->forDocument('cash', 1);

        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function forDocument(string $source, int $docId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal/for-document/' . $source . '/' . $docId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        $resp = ($this->forDocumentAction)($req, new Psr7Response(), ['source' => $source, 'id' => (string) $docId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $args = [], array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $this->journalAction->{$method}($req, new Psr7Response())
            : $this->journalAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Id volné v tabulce faktur i ostatních položek — pro záměrnou shodu id dvou dokladů. */
    private function freeId(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT GREATEST(COALESCE((SELECT MAX(id) FROM invoices), 0), COALESCE((SELECT MAX(id) FROM other_items), 0)) + 1000'
        )->fetchColumn();
    }

    private function otherItem(int $id, string $status): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO other_items
               (id, supplier_id, side, title, issued_on, accounting_on, due_on, currency, amount, amount_czk,
                account_code, counter_account_code, status, document_no, posted_at)
             VALUES (?, ?, "receivable", "Náhrada škody", ?, ?, ?, "CZK", 500, 500, "378", "648", ?, ?, ?)'
        )->execute([$id, $this->supplierId, self::YEAR . '-06-15', self::YEAR . '-06-15', self::YEAR . '-07-15', $status,
            $status === 'draft' ? null : 'OST-' . $id, $status === 'draft' ? null : self::YEAR . '-06-15 10:00:00']);
        return $id;
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate, ?int $id = null): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (id, supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$id, $this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }
}
