<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\Invoice\GetInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\GetPurchaseInvoiceItemsAction;
use MyInvoice\Action\PurchaseInvoice\GetPurchaseInvoicePreviewAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DocumentTagRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\InvoiceListDetailsRepository;
use MyInvoice\Repository\InvoiceListExtrasRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryNoteRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Report\InvoiceKhSections;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class InvoiceListOptionalColumnsTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplierId;
    private int $userId;
    private int $currencyId;
    private int $clientId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php chybí, test vyžaduje lokální DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, is_vat_payer,
                 email, default_currency_id, default_vat_rate_id, default_payment_due_days, default_hourly_rate, accounting_mode)
             SELECT '__TEST LIST COLUMNS', '__TEST LIST COLUMNS', 'Test 1', 'Test', '10000', country_id, 1,
                 'list-columns@example.test', default_currency_id, default_vat_rate_id, 14, 0, 'double_entry'
               FROM supplier WHERE id = ?"
        )->execute([$source]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, '__TEST LIST COLUMNS', 'Test 1', 'Test', '10000', ?, 'client@example.test', 'cs', ?, 1, 1)"
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testNewSortFieldsApplyBeforePaginationAndKeepDeterministicTies(): void
    {
        foreach (['invoice', 'purchase_invoice'] as $source) {
            $older = $this->document($source, 'LC-OLDER', 'Alpha', '200');
            $newer = $this->document($source, 'LC-NEWER', 'Zulu', '100');
            $repo = $this->container->get($source === 'invoice' ? InvoiceRepository::class : PurchaseInvoiceRepository::class);
            $filter = ['supplier_id' => $this->supplierId, 'group_by_month' => false, 'sort_key' => 'note', 'sort_dir' => 'asc'];
            $first = $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0];
            $second = $repo->listGroupedByMonth($filter, 2, 1)['data'][0]['invoices'][0];
            self::assertSame($older, $first['id']);
            self::assertSame($newer, $second['id']);
            self::assertSame('CZ', $first['country']);
            self::assertSame('Alpha', $first['note_above_items']);
            $filter['sort_key'] = 'country';
            self::assertSame($newer, $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0]['id']);
            if ($source === 'purchase_invoice') {
                $filter['sort_key'] = 'payment_vs';
                $row = $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0];
                self::assertSame($newer, $row['id']);
                self::assertSame('100', $row['payment_variable_symbol']);
                self::assertSame('bank_transfer', $row['payment_method']);
            }
        }
    }

    /** Vydané faktury se řadí i podle data vystavení, ne jen podle DUZP (issue #432). */
    public function testIssuedInvoicesSortByIssueDate(): void
    {
        $later = $this->document('invoice', 'LC-ISSUE-LATER', 'Alpha', '300');
        $earlier = $this->document('invoice', 'LC-ISSUE-EARLIER', 'Zulu', '400');
        // DUZP v opačném pořadí než vystavení: řazení musí jít podle issue_date.
        $this->db->pdo()->prepare('UPDATE invoices SET issue_date = ?, tax_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2097-01-20', '2097-01-02', $this->supplierId, $later]);
        $this->db->pdo()->prepare('UPDATE invoices SET issue_date = ?, tax_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2097-01-10', '2097-01-25', $this->supplierId, $earlier]);
        $repo = $this->container->get(InvoiceRepository::class);
        $filter = ['supplier_id' => $this->supplierId, 'group_by_month' => false, 'sort_key' => 'issue_date', 'sort_dir' => 'asc'];
        $first = $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0];
        self::assertSame($earlier, $first['id']);
        self::assertSame('2097-01-10', $first['issue_date']);
        $filter['sort_dir'] = 'desc';
        self::assertSame($later, $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0]['id']);
    }

    public function testTaxIdColumnsComeFromDocumentSnapshotWithClientCardFallback(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE clients SET ic = '12345679', dic = 'CZ12345679' WHERE id = ?")->execute([$this->clientId]);

        $draft = $this->document('invoice', 'LC-DIC-DRAFT', '', '100');
        $issued = $this->document('invoice', 'LC-DIC-ISSUED', '', '200');
        $pdo->prepare('UPDATE invoices SET client_snapshot = ? WHERE id = ?')
            ->execute([json_encode(['id' => $this->clientId, 'ic' => '87654321', 'dic' => 'CZ87654321']), $issued]);
        $repo = $this->container->get(InvoiceRepository::class);
        $filter = ['supplier_id' => $this->supplierId, 'group_by_month' => false, 'sort_key' => 'client_dic', 'sort_dir' => 'asc'];
        $first = $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0];
        $second = $repo->listGroupedByMonth($filter, 2, 1)['data'][0]['invoices'][0];
        self::assertSame([$draft, '12345679', 'CZ12345679'], [$first['id'], $first['client_ic'], $first['client_dic']]);
        self::assertSame([$issued, '87654321', 'CZ87654321'], [$second['id'], $second['client_ic'], $second['client_dic']]);
        $filter['sort_key'] = 'client_ic';
        $filter['sort_dir'] = 'desc';
        self::assertSame($issued, $repo->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0]['id']);

        $cardOnly = $this->document('purchase_invoice', 'LC-DIC-CARD', '', '300');
        $onDocument = $this->document('purchase_invoice', 'LC-DIC-DOC', '', '400');
        $pdo->prepare('UPDATE purchase_invoices SET vendor_snapshot = ? WHERE id = ?')
            ->execute([json_encode(['dic' => 'CZ00000001']), $onDocument]);
        $purchases = $this->container->get(PurchaseInvoiceRepository::class);
        $filter = ['supplier_id' => $this->supplierId, 'group_by_month' => false, 'sort_key' => 'vendor_dic', 'sort_dir' => 'asc'];
        $first = $purchases->listGroupedByMonth($filter, 1, 1)['data'][0]['invoices'][0];
        $second = $purchases->listGroupedByMonth($filter, 2, 1)['data'][0]['invoices'][0];
        self::assertSame([$onDocument, 'CZ00000001'], [$first['id'], $first['vendor_dic']]);
        self::assertSame([$cardOnly, 'CZ12345679'], [$second['id'], $second['vendor_dic']]);
    }

    public function testPostingAccountsPreserveBalanceSheetAndPlaceVatLast(): void
    {
        $id = $this->document('purchase_invoice', 'LC-POSTED', 'Posted', '100');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periodId = $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2097, '2097-01-01', '2097-12-31');
        $accountIds = [];
        foreach (['518', '321', '343'] as $code) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
            $stmt->execute([$this->supplierId, $code]);
            $accountIds[$code] = (int) $stmt->fetchColumn();
            self::assertGreaterThan(0, $accountIds[$code]);
        }
        $entryId = $this->container->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $periodId, 'entry_date' => '2097-01-01',
            'source_type' => 'purchase_invoice', 'source_id' => $id, 'posted_at' => '2097-01-01 12:00:00', 'posted_by' => $this->userId,
        ], [
            ['account_id' => $accountIds['343'], 'side' => 'debit', 'amount' => 21],
            ['account_id' => $accountIds['321'], 'side' => 'debit', 'amount' => 10],
            ['account_id' => $accountIds['518'], 'side' => 'debit', 'amount' => 90],
            ['account_id' => $accountIds['321'], 'side' => 'credit', 'amount' => 121],
        ]);
        $details = $this->container->get(InvoiceListDetailsRepository::class)->forDocuments($this->supplierId, 'purchase_invoice', [$id], false, true);
        self::assertSame(['518', '321', '343'], $details[$id]['debit_accounts']);
        self::assertSame(['321'], $details[$id]['credit_accounts']);
        $notes = $this->container->get(JournalEntryNoteRepository::class);
        $notes->add($entryId, $this->supplierId, 'Pinned synthetic note', true, $this->userId);
        $deleted = $notes->add($entryId, $this->supplierId, 'Deleted synthetic note', false, $this->userId);
        $this->db->pdo()->prepare('UPDATE journal_entry_notes SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$deleted]);
        $extras = $this->container->get(InvoiceListExtrasRepository::class);
        self::assertSame(['Pinned synthetic note'], $extras->forDocuments($this->supplierId, 'purchase_invoice', [$id], true, null)[$id]['journal_notes']);
        self::assertSame([], $extras->forDocuments($this->supplierId, 'purchase_invoice', [$id], false, null));
        self::assertSame([], $extras->forDocuments($this->supplierId + 1, 'purchase_invoice', [$id], true, null)[$id]['journal_notes']);
    }

    public function testAttachmentTagsRespectDocumentOwnerAndTrash(): void
    {
        $id = $this->document('invoice', 'LC-TAGS', '', '100');
        $this->taggedAttachment($id, 'company', null, 'Company');
        $this->taggedAttachment($id, 'user', $this->userId, 'Private');
        $this->taggedAttachment($id, 'company', null, 'Trashed', true);
        $extras = $this->container->get(InvoiceListExtrasRepository::class);
        $company = $extras->forDocuments($this->supplierId, 'invoice', [$id], false, DocumentViewerContext::companyOnly());
        self::assertSame(['Company'], $company[$id]['document_tags']);
        $owner = $extras->forDocuments($this->supplierId, 'invoice', [$id], false, DocumentViewerContext::forUser($this->userId));
        self::assertSame(['Company', 'Private'], $owner[$id]['document_tags']);
        $foreign = $extras->forDocuments($this->supplierId + 1, 'invoice', [$id], false, DocumentViewerContext::admin());
        self::assertSame([], $foreign[$id]['document_tags']);
    }

    public function testAccountingNotesAndAccountsRequireAccountingRead(): void
    {
        foreach ([ListInvoicesAction::class, ListPurchaseInvoicesAction::class] as $action) {
            foreach ([['filter' => ['include_journal_notes' => '1']], ['filter' => ['include_posting_accounts' => '1']], ['sort_key' => 'journal_notes'], ['sort_key' => 'debit_accounts']] as $query) {
                $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
                    ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
                    ->withQueryParams($query);
                self::assertSame(403, ($this->container->get($action))($request, new Response())->getStatusCode());
            }
        }
    }

    public function testVatClassificationAndReturnLinesComeFromTheVatBook(): void
    {
        $vatRateId = (int) $this->db->pdo()->query("SELECT id FROM vat_rates WHERE rate_percent = 21 AND country = 'CZ' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $vatRateId);
        foreach ([['invoice', '1', '1', 'issued'], ['purchase_invoice', '40', '40', 'received']] as [$source, $code, $line, $status]) {
            $id = $this->document($source, 'LC-VAT', '', '100');
            $table = $source === 'invoice' ? 'invoices' : 'purchase_invoices';
            $items = $source === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
            $foreignKey = $source === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
            $this->db->pdo()->prepare("UPDATE {$table} SET status = ?, tax_date = '2097-01-01', total_without_vat = 100, total_vat = 21, total_with_vat = 121 WHERE id = ?")
                ->execute([$status, $id]);
            $this->db->pdo()->prepare(
                "INSERT INTO {$items} ({$foreignKey}, description, quantity, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, vat_classification_code)
                 VALUES (?, 'Synthetic VAT line', 1, 100, ?, 21, 100, 21, 121, ?)"
            )->execute([$id, $vatRateId, $code]);
            $repo = $this->container->get($source === 'invoice' ? InvoiceRepository::class : PurchaseInvoiceRepository::class);
            $groups = $repo->listGroupedByMonth(['supplier_id' => $this->supplierId], 1, 100)['data'];
            $this->container->get(InvoiceKhSections::class)->addToGroups($this->supplierId, $groups, $source === 'invoice' ? 'issued' : 'received', true);
            $row = array_values(array_filter($groups[0]['invoices'], static fn (array $row): bool => $row['id'] === $id))[0];
            self::assertSame([$code], $row['vat_classification_codes']);
            self::assertSame([$line], $row['vat_return_lines']);
        }
    }

    public function testPurchasePreviewWorksWithoutPostingAndRejectsForeignOrUnauthorizedRequests(): void
    {
        $id = $this->document('purchase_invoice', 'PREVIEW-SYNTHETIC', 'Synthetic', '100');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/purchase-invoices/' . $id . '/preview')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $action = $this->container->get(GetPurchaseInvoicePreviewAction::class);
        $response = $action($request, new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode());
        $summary = json_decode((string) $response->getBody(), true);
        self::assertTrue($summary['available']);
        self::assertSame('purchase_invoice', $summary['source_type']);
        self::assertSame($id, $summary['source_id']);
        self::assertSame(0, $summary['entry_id']);
        self::assertSame('PREVIEW-SYNTHETIC', $summary['title']);
        $foreign = $request->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId === 1 ? 2 : 1);
        self::assertSame(404, $action($foreign, new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(404, $action($request, new Response(), ['id' => PHP_INT_MAX])->getStatusCode());
        self::assertSame(403, $action($request->withoutAttribute(AuthMiddleware::ATTR_USER), new Response(), ['id' => $id])->getStatusCode());
    }

    public function testItemsEndpointsReturnOnlyOrderedItemsAndEnforceSupplierOwnership(): void
    {
        $vatId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        foreach (['invoice', 'purchase_invoice'] as $source) {
            $id = $this->document($source, 'ITEMS-SYNTHETIC', 'Synthetic', '100');
            $action = $this->container->get($source === 'invoice' ? GetInvoiceItemsAction::class : GetPurchaseInvoiceItemsAction::class);
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/items')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
            $empty = $action($request, new Response(), ['id' => $id]);
            self::assertSame(200, $empty->getStatusCode());
            self::assertSame(['items' => []], json_decode((string) $empty->getBody(), true));
            $table = $source === 'invoice' ? 'invoice_items' : 'purchase_invoice_items';
            $foreignKey = $source === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
            foreach ([2, 1] as $order) {
                $this->db->pdo()->prepare(
                    "INSERT INTO {$table} ({$foreignKey}, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                     VALUES (?, ?, 1, 'ks', 100, ?, 0, 100, 0, 100, ?)"
                )->execute([$id, 'Synthetic item ' . $order, $vatId, $order]);
            }
            $response = $action($request, new Response(), ['id' => $id]);
            self::assertSame(200, $response->getStatusCode());
            $data = json_decode((string) $response->getBody(), true);
            self::assertSame(['items'], array_keys($data));
            self::assertSame(['Synthetic item 1', 'Synthetic item 2'], array_column($data['items'], 'description'));
            self::assertSame(1.0, $data['items'][0]['quantity'] * 1.0);
            $foreign = $request->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId === 1 ? 2 : 1);
            self::assertSame(404, $action($foreign, new Response(), ['id' => $id])->getStatusCode());
            self::assertSame(404, $action($request, new Response(), ['id' => PHP_INT_MAX])->getStatusCode());
        }
    }

    private function document(string $source, string $number, string $note, string $vs): int
    {
        $table = $source === 'invoice' ? 'invoices' : 'purchase_invoices';
        $counterparty = $source === 'invoice' ? 'client_id' : 'vendor_id';
        $extraColumns = $source === 'invoice' ? '' : ', vendor_invoice_number, received_at, vendor_snapshot';
        $extraValues = $source === 'invoice' ? '' : ", '{$number}', '2097-01-01', '{}'";
        $this->db->pdo()->prepare(
            "INSERT INTO {$table} (supplier_id, {$counterparty}, varsymbol, issue_date, due_date, currency_id,
                 created_by, note_above_items, payment_variable_symbol {$extraColumns})
             VALUES (?, ?, ?, '2097-01-01', '2097-01-31', ?, ?, ?, ? {$extraValues})"
        )->execute([$this->supplierId, $this->clientId, $number, $this->currencyId, $this->userId, $note, $vs]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function taggedAttachment(int $invoiceId, string $scope, ?int $ownerId, string $tag, bool $trash = false): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes,
                 doc_type, scope, owner_user_id, deleted_at)
             VALUES (?, 'Synthetic attachment', 'test.pdf', 'synthetic.pdf', ?, 'application/pdf', 10, 'pdf', ?, ?, ?)"
        )->execute([$this->supplierId, hash('sha256', $tag), $scope, $ownerId, $trash ? '2097-01-01 00:00:00' : null]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO document_links (supplier_id, document_id, entity_type, entity_id) VALUES (?, ?, 'invoice', ?)")
            ->execute([$this->supplierId, $documentId, $invoiceId]);
        $this->container->get(DocumentTagRepository::class)->setTags($this->supplierId, $documentId, [$tag]);
    }
}
