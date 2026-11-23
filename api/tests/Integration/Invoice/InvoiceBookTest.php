<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\InvoiceBookAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\Book\InvoiceBook;
use MyInvoice\Service\Invoice\Book\InvoiceBookService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Kniha vystavených a přijatých faktur: výběr přes filtr seznamu, koncepty bez čísla,
 * hlavní položka, součty po měnách a izolace firmy.
 */
#[Group('integration')]
final class InvoiceBookTest extends TestCase
{
    private Connection $db;
    private InvoiceBookService $service;
    private InvoiceRepository $invoices;
    private InvoiceBookAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $vatRateId;
    private string $token;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(InvoiceBookService::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->action = $container->get(InvoiceBookAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->otherSupplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query("SELECT default_vat_rate_id FROM supplier WHERE id = {$this->supplierId}")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->otherSupplierId, $this->userId, $this->vatRateId], true)
            || $this->currency($this->supplierId, 'CZK') === 0
            || $this->currency($this->supplierId, 'EUR') === 0
            || $this->currency($this->otherSupplierId, 'CZK') === 0) {
            $this->markTestSkipped('Chybí základní data (dvě firmy, měny CZK/EUR, uživatel, sazba DPH).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->token = 'KNIHA' . strtoupper(bin2hex(random_bytes(4)));
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

    public function testIssuedBookUsesListFilterSkipsNumberlessDraftsAndSumsPerCurrency(): void
    {
        $client = $this->client($this->supplierId);
        $czk = $this->currency($this->supplierId, 'CZK');
        $eur = $this->currency($this->supplierId, 'EUR');

        $main = $this->invoice($this->supplierId, $client, $czk, 'A01', 'invoice', 'issued', 1210.0);
        $this->item($main, 'Drobná položka', 121.0, 0);
        $this->item($main, 'Hlavní položka', 1089.0, 1);
        $credit = $this->invoice($this->supplierId, $client, $czk, 'A02', 'credit_note', 'issued', -242.0);
        $this->item($credit, 'Vratka', -242.0, 0);
        $this->invoice($this->supplierId, $client, $czk, null, 'invoice', 'draft', 999.0);
        $numberedDraft = $this->invoice($this->supplierId, $client, $czk, 'A03', 'invoice', 'draft', 50.0);
        $cancelled = $this->invoice($this->supplierId, $client, $czk, 'A04', 'invoice', 'cancelled', 70.0);
        $taxDocument = $this->invoice($this->supplierId, $client, $czk, 'A05', 'tax_document', 'paid', 500.0);
        $this->invoice($this->supplierId, $client, $eur, 'A06', 'invoice', 'issued', 100.0);

        // Doklad jiné firmy se stejným hledaným textem do knihy nesmí.
        $foreignClient = $this->client($this->otherSupplierId);
        $this->invoice($this->otherSupplierId, $foreignClient, $this->currency($this->otherSupplierId, 'CZK'), 'A00', 'invoice', 'issued', 5000.0);

        $book = $this->service->issued($this->filters(), ['name' => 'Účetní Test']);
        $rows = iterator_to_array($book->rows(), false);

        self::assertSame(
            [$this->token . 'A01', $this->token . 'A02', $this->token . 'A03', $this->token . 'A04', $this->token . 'A05', $this->token . 'A06'],
            array_column($rows, 'number'),
            'koncept bez čísla a doklad cizí firmy v knize nejsou, řazení podle čísla',
        );
        $byId = array_column($rows, null, 'id');
        self::assertSame('Hlavní položka', $byId[$main]['description']);
        self::assertTrue($byId[$numberedDraft]['draft']);
        self::assertFalse($byId[$numberedDraft]['counted']);
        self::assertTrue($byId[$cancelled]['cancelled']);
        self::assertSame('tax_document', $byId[$taxDocument]['category']);

        $totals = array_column($book->totals(), null, 'currency');
        self::assertSame(['CZK', 'EUR'], array_keys($totals), 'domácí měna první, cizí zvlášť bez přepočtu');
        self::assertSame(3, $totals['CZK']['count']);
        self::assertSame(968.0, $totals['CZK']['to_pay']);
        self::assertSame(1210.0, $totals['CZK']['remaining_invoices']);
        self::assertSame(-242.0, $totals['CZK']['remaining_credit_notes']);
        self::assertSame(500.0, $totals['CZK']['tax_documents']);
        self::assertSame(100.0, $totals['EUR']['to_pay']);

        $selection = array_column($book->meta['selection'], 'value', 'label');
        self::assertSame('rok 2004 (podle DUZP)', $selection['Období']);
        self::assertSame($this->token, $selection['Hledání']);
        self::assertSame('Účetní Test', $book->meta['printed_by']);

        $issuedOnly = iterator_to_array($this->service->issued($this->filters(['status' => 'issued']), [])->rows(), false);
        self::assertSame([$this->token . 'A01', $this->token . 'A02', $this->token . 'A06'], array_column($issuedOnly, 'number'));
    }

    public function testIssuedBatchesFollowNumberKeyWithoutGapsOrDuplicates(): void
    {
        $client = $this->client($this->supplierId);
        $czk = $this->currency($this->supplierId, 'CZK');
        foreach (['B05', 'B01', 'B03', 'B02', 'B04'] as $number) {
            $this->invoice($this->supplierId, $client, $czk, $number, 'invoice', 'issued', 121.0);
        }

        $filters = $this->filters();
        $seen = [];
        $after = null;
        do {
            $batch = $this->invoices->listBookBatch($filters, $after, 2);
            foreach ($batch as $row) {
                $seen[] = $row['number'];
            }
            $last = $batch === [] ? null : $batch[count($batch) - 1];
            $after = $last === null ? null : [(string) $last['number'], (int) $last['id']];
        } while (count($batch) === 2);

        self::assertSame(array_map(fn (string $n): string => $this->token . $n, ['B01', 'B02', 'B03', 'B04', 'B05']), $seen);
        self::assertSame([], $this->invoices->listBookBatch(['supplier_id' => 0, 'q' => $this->token], null, 10), 'bez firmy nic');
    }

    public function testReceivedBookIsTenantScopedAndUsesVendorNumberAsVariableSymbol(): void
    {
        $vendor = $this->client($this->supplierId);
        $czk = $this->currency($this->supplierId, 'CZK');
        $invoice = $this->purchase($this->supplierId, $vendor, $czk, 'P01', 'FV-2004/0042', 'invoice', 'received', 1210.0);
        $this->purchaseItem($invoice, 'Servis serveru', 1210.0);
        $this->purchase($this->supplierId, $vendor, $czk, 'P02', 'DB-7', 'credit_note', 'received', -121.0);
        $this->purchase($this->supplierId, $vendor, $czk, null, 'X-1', 'invoice', 'draft', 10.0);
        $foreignVendor = $this->client($this->otherSupplierId);
        $this->purchase($this->otherSupplierId, $foreignVendor, $this->currency($this->otherSupplierId, 'CZK'), 'P00', 'CIZI-1', 'invoice', 'received', 9999.0);

        $book = $this->service->received($this->filters(), []);
        $rows = iterator_to_array($book->rows(), false);

        self::assertSame([$this->token . 'P01', $this->token . 'P02'], array_column($rows, 'number'));
        self::assertSame('20040042', $rows[0]['variable_symbol']);
        self::assertSame('Servis serveru', $rows[0]['description']);
        self::assertSame(-121.0, $rows[1]['amount']);

        $totals = $book->totals();
        self::assertCount(1, $totals);
        self::assertSame(1089.0, $totals[0]['to_pay']);
        self::assertSame(1210.0, $totals[0]['remaining_invoices']);
        self::assertSame(-121.0, $totals[0]['remaining_credit_notes']);
        self::assertSame(InvoiceBook::RECEIVED, $book->kind);
    }

    public function testActionReturnsPdfAndXlsxAndRejectsUnknownFormat(): void
    {
        $client = $this->client($this->supplierId);
        $this->invoice($this->supplierId, $client, $this->currency($this->supplierId, 'CZK'), 'C01', 'invoice', 'issued', 1210.0);

        $pdf = $this->call('issued', ['format' => 'pdf']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $xlsx = $this->call('received', ['format' => 'xlsx']);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        self::assertStringContainsString('kniha-prijatych-faktur-', $xlsx->getHeaderLine('Content-Disposition'));

        self::assertSame(422, $this->call('issued', ['format' => 'csv'])->getStatusCode());
        self::assertSame(422, $this->call('issued', ['format' => 'pdf', 'filter' => ['unpaid_as_of' => '31.2.2004']])->getStatusCode());
        self::assertSame(403, $this->call('issued', ['format' => 'pdf'], 'nonexistent_role')->getStatusCode());
    }

    /** @param array<string,mixed> $query */
    private function call(string $method, array $query, string $role = 'admin'): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices/book')
            ->withQueryParams($query + ['q' => $this->token, 'filter' => ['year' => '2004']])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'name' => 'Test', 'email' => 'kniha@example.test', 'role' => $role]);
        $response = $this->action->$method($request, new Psr7Response());
        $response->getBody()->rewind();
        return $response;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function filters(array $extra = []): array
    {
        return $extra + ['supplier_id' => $this->supplierId, 'q' => $this->token, 'year' => 2004];
    }

    private function currency(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function client(int $supplierId): int
    {
        $country = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Praha", "11000", ?, "kniha@example.test", "cs", ?, 1, 1)'
        )->execute([$supplierId, 'Partner ' . $this->token, $country, $this->currency($supplierId, 'CZK')]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $supplierId, int $clientId, int $currencyId, ?string $number, string $type, string $status, float $gross): int
    {
        $net = round($gross / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, "2004-03-10", "2004-03-10", "2004-03-24", ?, 1.0, 0, ?, ?, ?, ?, "1", ?)'
        )->execute([
            $supplierId, $number === null ? null : $this->token . $number, $type, $clientId, $currencyId,
            $net, round($gross - $net, 2), $gross, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function item(int $invoiceId, string $description, float $gross, int $order): void
    {
        $net = round($gross / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, ?, ?, 21, ?, ?, ?, ?)'
        )->execute([$invoiceId, $description, $net, $this->vatRateId, $net, round($gross - $net, 2), $gross, $order]);
    }

    private function purchase(int $supplierId, int $vendorId, int $currencyId, ?string $number, string $vendorNumber, string $kind, string $status, float $gross): int
    {
        $net = round($gross / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, "2004-03-10", "2004-03-10", "2004-03-24", "2004-03-11", ?, 1.0, 0, "{}", ?, ?, ?, ?, "40", "full", ?)'
        )->execute([
            $supplierId, $vendorId, $number === null ? null : $this->token . $number, $vendorNumber, $kind, $currencyId,
            $net, round($gross - $net, 2), $gross, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseItem(int $purchaseId, string $description, float $gross): void
    {
        $net = round($gross / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat)
             VALUES (?, ?, 1, ?, ?, 21, ?, ?, ?)'
        )->execute([$purchaseId, $description, $net, $this->vatRateId, $net, round($gross - $net, 2), $gross]);
    }
}
