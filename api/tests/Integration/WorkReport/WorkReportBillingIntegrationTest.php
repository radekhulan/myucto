<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Repository\WorkReportBillingTemplateRepository;
use MyInvoice\Service\WorkReport\WorkReportBillingService;
use MyInvoice\Action\WorkReport\SaveWorkReportAction;
use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Unit\WorkReport\WorkReportBillingTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class WorkReportBillingIntegrationTest extends TestCase
{
    private Connection $db;
    private $container;
    private int $supplier;
    private int $invoice;
    private int $user;
    private int $vat;
    private array $invoiceIds = [];
    private ?int $foreignVat = null;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $this->vat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21'")->fetchColumn();
        self::assertGreaterThan(0, $this->vat);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                VALUES ('Syntetické účtování', 'Testovací 1', 'Testov', '10000', ?, 'billing@example.test', 0, ?)")->execute([$country, $this->vat]);
            $this->supplier = (int) $pdo->lastInsertId();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'CZK', 'Kč', 'Koruna', 'Crown', 1)")->execute([$this->supplier]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $this->supplier]);
        $pdo->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, '', 'Syntetický účtující', 'admin')")->execute(['billing-' . bin2hex(random_bytes(8)) . '@example.test']);
        $this->user = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            VALUES (?, 'Syntetický klient', 'Testovací 2', 'Testov', '10000', ?, 'client@example.test', ?)")->execute([$this->supplier, $country, $currency]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices (supplier_id, client_id, issue_date, tax_date, due_date, currency_id, created_by, status, invoice_type)
            VALUES (?, ?, '2026-11-01', '2026-11-01', '2026-11-15', ?, ?, 'draft', 'invoice')")->execute([$this->supplier, $client, $currency, $this->user]);
        $this->invoice = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $this->invoice;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->invoiceIds as $id) $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        if (isset($this->supplier)) {
            $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplier]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplier]);
            } finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
        }
        if (isset($this->user)) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->user]);
        if ($this->foreignVat !== null) $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$this->foreignVat]);
    }

    private function save(array $body): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/invoices/' . $this->invoice . '/work-report')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody($body);
        $response = ($this->container->get(SaveWorkReportAction::class))($request, new Response(), ['id' => $this->invoice]);
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode(), json_encode($data));
        return $data;
    }

    private function setupReport(): array
    {
        return $this->save(['title' => 'Syntetická podpora', 'billing_snapshot' => WorkReportBillingTest::settings(),
            'billing_period' => '2026-11', 'vat_rate_id' => $this->vat, 'items' => [['description' => 'Syntetická práce', 'duration_minutes' => 420, 'rate' => 0]]]);
    }

    private function transfer(array $report): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $this->container->get(WorkReportBillingService::class)->transfer($this->invoice, $this->supplier, $report['billing_preview']['fingerprint'], $this->container->get(\MyInvoice\Service\Oss\OssItemPlanner::class));
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Skutečný editor ukládá odstranění starého souhrnu před přenosem nových pásem. */
    public function testExistingDraftCanBeEmptiedBeforeTransferringBillingRows(): void
    {
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($this->invoice, [['description' => 'Původní souhrn práce', 'quantity' => 1,
            'unit' => 'ks', 'unit_price_without_vat' => 700, 'vat_rate_id' => $this->vat]]);
        $this->container->get(\MyInvoice\Service\Invoice\InvoiceCalculator::class)->recompute($this->invoice);
        $this->save(['title' => 'Práce', 'items' => [['description' => 'Syntetická práce', 'duration_minutes' => 180, 'rate' => 300]]]);
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/invoices/' . $this->invoice)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody(['items' => []]);
        $response = ($this->container->get(\MyInvoice\Action\Invoice\UpdateInvoiceAction::class))($request, new Response(), ['id' => $this->invoice]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $empty = $repo->find($this->invoice);
        self::assertSame('draft', $empty['status']);
        self::assertSame([], $empty['items']);
        self::assertEquals(0, $empty['total_with_vat']);
        $issue = ($this->container->get(\MyInvoice\Action\Invoice\IssueInvoiceAction::class))(
            $request->withMethod('POST')->withParsedBody([]), new Response(), ['id' => $this->invoice]);
        self::assertSame(422, $issue->getStatusCode(), (string) $issue->getBody());
        self::assertSame('no_items', json_decode((string) $issue->getBody(), true)['error']['code']);
        self::assertSame('draft', $repo->find($this->invoice)['status']);
        $report = $this->save(['billing_snapshot' => WorkReportBillingTest::settings(), 'billing_period' => '2026-11']);
        $transferred = $this->transfer($report);
        self::assertCount(2, $transferred['items']);
        self::assertSame(['fixed', 'band:0'], array_column($transferred['items'], 'work_report_billing_key'));
    }

    public function testIssuedInvoiceCannotLoseAllRowsEvenWithForce(): void
    {
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($this->invoice, [['description' => 'Vystavené plnění', 'quantity' => 1,
            'unit' => 'ks', 'unit_price_without_vat' => 700, 'vat_rate_id' => $this->vat]]);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$this->invoice]);
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/invoices/' . $this->invoice)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withQueryParams(['force' => '1'])
            ->withParsedBody(['items' => []]);
        $response = ($this->container->get(\MyInvoice\Action\Invoice\UpdateInvoiceAction::class))($request, new Response(), ['id' => $this->invoice]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no_items', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertCount(1, $repo->itemsFor($this->invoice));
    }

    /** Táž stavová politika platí i pro obecný PUT přijatého konceptu. */
    public function testPurchaseDraftCanBeEmptiedButReceivedDocumentCannot(): void
    {
        $invoice = $this->container->get(InvoiceRepository::class)->find($this->invoice);
        $this->db->pdo()->prepare('UPDATE clients SET is_vendor = 1 WHERE id = ?')->execute([$invoice['client_id']]);
        $body = ['vendor_id' => $invoice['client_id'], 'vendor_invoice_number' => 'SYN-EMPTY-DRAFT',
            'document_kind' => 'invoice', 'issue_date' => '2026-11-01', 'tax_date' => '2026-11-01',
            'due_date' => '2026-11-15', 'received_at' => '2026-11-01', 'currency_id' => $invoice['currency_id'],
            'items' => [['description' => 'Syntetické přijaté plnění', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 700, 'vat_rate_id' => $this->vat]]];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody($body);
        $created = ($this->container->get(\MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction::class))($request, new Response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) json_decode((string) $created->getBody(), true)['id'];
        $repo = $this->container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
        $update = $this->container->get(\MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction::class);
        try {
            $emptyRequest = $request->withMethod('PUT')->withParsedBody(['items' => []]);
            $response = $update($emptyRequest, new Response(), ['id' => $id]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $empty = $repo->find($id, $this->supplier);
            self::assertSame([], $empty['items']);
            self::assertSame('draft', $empty['status']);
            self::assertEquals(0, $empty['total_with_vat']);
            $restored = $update($request->withMethod('PUT')->withParsedBody(['items' => $body['items']]), new Response(), ['id' => $id]);
            self::assertSame(200, $restored->getStatusCode(), (string) $restored->getBody());
            $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);
            $rejected = $update($emptyRequest->withQueryParams(['force' => '1']), new Response(), ['id' => $id]);
            self::assertSame(422, $rejected->getStatusCode(), (string) $rejected->getBody());
            self::assertSame('no_items', json_decode((string) $rejected->getBody(), true)['error']['code']);
            self::assertCount(1, $repo->find($id, $this->supplier)['items']);
        } finally {
            $this->db->pdo()->prepare('DELETE FROM purchase_invoices WHERE id = ? AND supplier_id = ?')->execute([$id, $this->supplier]);
        }
    }

    public function testTransferIsRepeatableKeepsUnrelatedRowsAndStableLinks(): void
    {
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($this->invoice, [['description' => 'Samostatná služba', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 500, 'vat_rate_id' => $this->vat]]);
        $report = $this->setupReport();
        self::assertEquals(2300.0, $report['total_amount']);
        self::assertTrue($report['billing_stale']);
        $first = $this->transfer($report);
        self::assertCount(4, $first['items']);
        self::assertFalse($first['work_report']['billing_stale']);
        $second = $this->transfer($report);
        self::assertCount(4, $second['items']);
        self::assertSame('Samostatná služba', $second['items'][0]['description']);
        $repo->replaceItems($this->invoice, $second['items']);
        $this->container->get(WorkReportBillingService::class)->assertCurrent($this->invoice, $this->supplier);
        $changed = $this->save(['items' => [['description' => 'Méně práce', 'duration_minutes' => 180, 'rate' => 0]]]);
        self::assertTrue($changed['billing_stale']);
        self::assertSame('2026-11', $changed['billing_period']);
        self::assertNotNull($changed['billing_snapshot']);
        $last = $this->transfer($changed);
        self::assertCount(3, $last['items']);
        self::assertSame(['fixed', 'band:0'], array_values(array_filter(array_column($last['items'], 'work_report_billing_key'))));
    }

    public function testSnapshotIsIndependentFromTemplateAndCloneRemapsLinks(): void
    {
        $templates = $this->container->get(WorkReportBillingTemplateRepository::class);
        $id = $templates->save($this->supplier, null, WorkReportBillingTest::settings());
        $report = $this->save(['title' => 'Paušál', 'billing_period' => '2026-11', 'billing_template_id' => $id, 'items' => []]);
        self::assertEquals(1000.0, $report['total_amount']);
        $this->transfer($report);
        $settings = WorkReportBillingTest::settings(); $settings['fixed_fee'] = 9000;
        $templates->save($this->supplier, $id, $settings);
        $templates->delete($this->supplier, $id);
        $report = $this->save(['title' => 'Paušál', 'billing_template_id' => $id, 'billing_snapshot' => $report['billing_snapshot']]);
        self::assertEquals(1000.0, $report['total_amount']);
        $newId = $this->container->get(BulkReissueAction::class)->cloneOne($this->invoice, '2026-11-02', false, $this->user);
        $this->invoiceIds[] = $newId;
        $copy = $this->container->get(WorkReportRepository::class)->findByInvoice($newId, $this->supplier);
        self::assertSame($report['billing_snapshot'], $copy['billing_snapshot']);
        $items = $this->container->get(InvoiceRepository::class)->itemsFor($newId);
        self::assertSame((int) $copy['id'], $items[0]['linked_work_report_id']);
        self::assertSame('fixed', $items[0]['work_report_billing_key']);
    }

    public function testExactMinuteGrossTransferWithDiscountAndTextRows(): void
    {
        $settings = WorkReportBillingTest::settings();
        $settings['fixed_fee'] = null;
        $settings['included_hours'] = 0;
        $settings['bands'] = [['up_to_hours' => null, 'rate' => 121, 'description' => '{nazev}']];
        $this->db->pdo()->prepare('UPDATE invoices SET prices_include_vat = 1, discount_percent = 10 WHERE id = ?')->execute([$this->invoice]);
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($this->invoice, [['description' => 'Poznámka', 'item_kind' => 'text', 'vat_rate_id' => $this->vat]]);
        $report = $this->save(['title' => 'Minuty', 'billing_snapshot' => $settings, 'vat_rate_id' => $this->vat,
            'items' => [['description' => 'Přesný čas', 'duration_minutes' => 20, 'rate' => 0]]]);
        $result = $this->transfer($report);
        self::assertCount(3, $result['items']);
        self::assertEquals(40.33, $result['preview']['totals']['with_vat']);
        self::assertEquals(36.30, $repo->find($this->invoice)['total_with_vat']);
        $this->container->get(WorkReportBillingService::class)->assertCurrent($this->invoice, $this->supplier);
    }

    public function testDisablingTransferredPricingIsConflictAndKeepsSnapshot(): void
    {
        $report = $this->setupReport();
        $this->transfer($report);
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/invoices/' . $this->invoice . '/work-report')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody(['billing_snapshot' => null, 'billing_template_id' => null]);
        $response = ($this->container->get(SaveWorkReportAction::class))($request, new Response(), ['id' => $this->invoice]);
        self::assertSame(409, $response->getStatusCode());
        $stored = $this->container->get(WorkReportRepository::class)->findByInvoice($this->invoice, $this->supplier);
        self::assertSame($report['billing_snapshot'], $stored['billing_snapshot']);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testCurrencyChangeIsValidationErrorAndLeavesInvoiceReadable(): void
    {
        $this->setupReport();
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro')")->execute([$this->supplier]);
        $currency = (int) $this->db->pdo()->lastInsertId();
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/invoices/' . $this->invoice)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody(['currency_id' => $currency]);
        $response = ($this->container->get(\MyInvoice\Action\Invoice\UpdateInvoiceAction::class))($request, new Response(), ['id' => $this->invoice]);
        self::assertSame(400, $response->getStatusCode());
        self::assertNotNull($this->container->get(WorkReportRepository::class)->findByInvoice($this->invoice, $this->supplier)['billing_preview']);
    }

    public function testStaleFingerprintLeavesInvoiceRowsUntouched(): void
    {
        $report = $this->setupReport();
        $this->transfer($report);
        $before = $this->container->get(InvoiceRepository::class)->itemsFor($this->invoice);
        $this->save(['billing_period' => '2026-12']);
        try {
            $this->transfer($report);
            self::fail('Zastaralý náhled nesmí změnit položky.');
        } catch (\DomainException) {
            self::assertSame($before, $this->container->get(InvoiceRepository::class)->itemsFor($this->invoice));
        }
    }

    public function testFixedWorkFeeAndBandsUseServiceClassificationForOss(): void
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'NL'")->fetchColumn();
        $pdo->prepare("INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, valid_from)
            VALUES (?, 21, 'NL', 'Syntetická NL sazba', 'Synthetic NL rate', '2020-01-01')")->execute(['TNL-' . bin2hex(random_bytes(6))]);
        $this->foreignVat = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE supplier SET oss_enabled = 1, oss_valid_from = '2020-01-01', oss_valid_to = NULL WHERE id = ?")->execute([$this->supplier]);
        $pdo->prepare("UPDATE clients SET country_id = ?, dic = NULL, oss_mode = 'auto', oss_default_supply_type = 'goods' WHERE supplier_id = ?")->execute([$country, $this->supplier]);
        $report = $this->setupReport();
        $result = $this->transfer($report);
        self::assertCount(3, $result['items']);
        foreach ($result['items'] as $item) {
            self::assertTrue((bool) $item['oss_applicable']);
            self::assertSame('services', $item['oss_supply_type']);
        }
        self::assertSame('ks', $result['items'][0]['unit']);
        $this->container->get(WorkReportBillingService::class)->assertCurrent($this->invoice, $this->supplier);
    }

    public function testReportChangedBetweenIssueChecksReturnsConflictWithoutNumberAllocation(): void
    {
        $report = $this->setupReport();
        $this->transfer($report);
        $actual = $this->db->pdo();
        $otherDb = Connection::withoutSharedTestConnection(fn () => new Connection(
            $this->container->get(\MyInvoice\Infrastructure\Config\Config::class)));
        $other = $otherDb->pdo();
        self::assertSame($actual->query('SELECT DATABASE()')->fetchColumn(), $other->query('SELECT DATABASE()')->fetchColumn());
        $interleaved = false;
        $proxy = new BillingIssueObservedPdo($actual, function (string $sql) use ($other, &$interleaved): void {
            if (!$interleaved && str_contains($sql, 'SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')) {
                $interleaved = true;
                // Druhý request commituje po první kontrole, ještě před získáním zámku vystavení.
                $other->prepare('UPDATE work_reports SET billing_period = ? WHERE invoice_id = ?')
                    ->execute(['2026-12', $this->invoice]);
            }
        });
        $property = new \ReflectionProperty(Connection::class, 'pdo');
        $property->setValue($this->db, $proxy);
        try {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices/' . $this->invoice . '/issue')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody([]);
            $response = ($this->container->get(\MyInvoice\Action\Invoice\IssueInvoiceAction::class))($request, new Response(), ['id' => $this->invoice]);
            self::assertTrue($interleaved, 'Test musí projít oběma kontrolami vystavení.');
            self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('billing_stale', json_decode((string) $response->getBody(), true)['error']['code']);
            self::assertFalse($actual->inTransaction());
            $invoice = $this->container->get(InvoiceRepository::class)->find($this->invoice);
            self::assertSame('draft', $invoice['status']);
            self::assertEmpty($invoice['varsymbol']);
            self::assertCount(3, $invoice['items']);
        } finally {
            $property->setValue($this->db, $actual);
            if ($actual->inTransaction()) $actual->rollBack();
            $otherDb->close();
        }
    }

    public function testAutoIssueRejectsReportChangedAfterInitialCheck(): void
    {
        $report = $this->setupReport();
        $this->transfer($report);
        $actual = $this->db->pdo();
        // Schvalovací tok již číslo přidělil; automatické vystavení je musí zachovat na konceptu.
        $actual->prepare('UPDATE invoices SET varsymbol = ? WHERE id = ?')->execute(['TEST-BILLING-AUTO', $this->invoice]);
        $otherDb = Connection::withoutSharedTestConnection(fn () => new Connection(
            $this->container->get(\MyInvoice\Infrastructure\Config\Config::class)));
        $other = $otherDb->pdo();
        $interleaved = false;
        $proxy = new BillingIssueObservedPdo($actual, function (string $sql) use ($other, &$interleaved): void {
            if (str_contains($sql, 'UPDATE invoices SET status = "issued"')) self::fail('Zastaralý výkaz se dostal až k vydání dokladu.');
            if (!$interleaved && str_contains($sql, 'SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')) {
                $interleaved = true;
                $other->prepare('UPDATE work_reports SET billing_period = ? WHERE invoice_id = ?')->execute(['2026-12', $this->invoice]);
            }
        });
        $property = new \ReflectionProperty(Connection::class, 'pdo');
        $property->setValue($this->db, $proxy);
        try {
            try {
                $this->container->get(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class)
                    ->run($this->invoice, $this->user, '127.0.0.1', 'synthetic-test');
                self::fail('Automatické vystavení nesmí potvrdit zastaralé účtování.');
            } catch (\MyInvoice\Service\WorkReport\WorkReportBillingConflict) {
                self::assertTrue($interleaved);
                self::assertFalse($actual->inTransaction());
                $invoice = $this->container->get(InvoiceRepository::class)->find($this->invoice);
                self::assertSame('draft', $invoice['status']);
                self::assertSame('TEST-BILLING-AUTO', $invoice['varsymbol']);
            }
        } finally {
            $property->setValue($this->db, $actual);
            if ($actual->inTransaction()) $actual->rollBack();
            $otherDb->close();
        }
    }

    public function testDeleteSeesTransferCommittedBeforeItsInvoiceLock(): void
    {
        $report = $this->setupReport();
        $actual = $this->db->pdo();
        $otherDb = Connection::withoutSharedTestConnection(fn () => new Connection(
            $this->container->get(\MyInvoice\Infrastructure\Config\Config::class)));
        $other = $otherDb->pdo();
        $interleaved = false;
        $proxy = new BillingIssueObservedPdo($actual, function (string $sql) use ($other, $report, &$interleaved): void {
            if (!$interleaved && (str_contains($sql, 'SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')
                || str_starts_with($sql, 'DELETE wr FROM work_reports'))) {
                $interleaved = true;
                $other->beginTransaction();
                $other->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')->execute([$this->supplier, $this->invoice]);
                $other->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                    linked_work_report_id, work_report_billing_key) VALUES (?, ?, 1, ?, 1000, ?, 21, 1000, 210, 1210, 0, ?, ?)')
                    ->execute([$this->invoice, 'Souběžně přenesený paušál', 'ks', $this->vat, $report['id'], 'fixed']);
                $other->commit();
            }
        });
        $property = new \ReflectionProperty(Connection::class, 'pdo');
        $property->setValue($this->db, $proxy);
        try {
            $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/invoices/' . $this->invoice . '/work-report')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
            $response = ($this->container->get(\MyInvoice\Action\WorkReport\DeleteWorkReportAction::class))($request, new Response(), ['id' => $this->invoice]);
            self::assertTrue($interleaved);
            self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('billing_conflict', json_decode((string) $response->getBody(), true)['error']['code']);
            self::assertNotNull($this->container->get(WorkReportRepository::class)->findByInvoice($this->invoice, $this->supplier));
            self::assertSame((int) $report['id'], $this->container->get(InvoiceRepository::class)->itemsFor($this->invoice)[0]['linked_work_report_id']);
            self::assertFalse($actual->inTransaction());
        } finally {
            $property->setValue($this->db, $actual);
            if ($actual->inTransaction()) $actual->rollBack();
            if ($other->inTransaction()) $other->rollBack();
            $otherDb->close();
        }
    }

    public function testCommittedReportSaveSucceedsEvenWhenAuditLoggerFails(): void
    {
        $logger = $this->createMock(\MyInvoice\Service\ActivityLogger::class);
        $logger->expects(self::once())->method('log')->willThrowException(new \RuntimeException('Syntetická chyba logu po commitu'));
        $this->container->set(\MyInvoice\Service\ActivityLogger::class, $logger);
        $report = $this->setupReport();
        self::assertNotNull($this->container->get(WorkReportRepository::class)->findByInvoice($this->invoice, $this->supplier));
        self::assertSame('2026-11', $report['billing_period']);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testInvoiceIncludesProjectBillingDefaultWithoutProjectRead(): void
    {
        $repo = $this->container->get(InvoiceRepository::class);
        $invoice = $repo->find($this->invoice);
        $templateId = $this->container->get(WorkReportBillingTemplateRepository::class)
            ->save($this->supplier, null, WorkReportBillingTest::settings());
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id, billing_template_id) VALUES (?, ?, ?, ?)')
            ->execute([$invoice['client_id'], 'Syntetická podpora', $invoice['currency_id'], $templateId]);
        $projectId = (int) $pdo->lastInsertId();
        try {
            $pdo->prepare('UPDATE invoices SET project_id = ? WHERE id = ?')->execute([$projectId, $this->invoice]);
            $withProject = $repo->find($this->invoice);
            self::assertArrayHasKey('project_billing_template_id', $withProject);
            self::assertSame($templateId, $withProject['project_billing_template_id']);
            $pdo->prepare('UPDATE invoices SET project_id = NULL WHERE id = ?')->execute([$this->invoice]);
            $withoutProject = $repo->find($this->invoice);
            self::assertArrayHasKey('project_billing_template_id', $withoutProject);
            self::assertNull($withoutProject['project_billing_template_id']);
        } finally {
            $pdo->prepare('UPDATE invoices SET project_id = NULL WHERE id = ?')->execute([$this->invoice]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
        }
    }

    public static function malformedPreviewInputs(): array
    {
        return [
            [['vat_rate_id' => ['invalid']]],
            [['title' => ['invalid']]],
            [['project_name' => ['invalid']]],
            [['items' => [['hours' => 1, 'description' => ['invalid']]]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPreviewInputs')]
    public function testMalformedPreviewReturnsValidationError(array $override): void
    {
        $body = array_replace(['title' => 'Práce', 'billing_snapshot' => WorkReportBillingTest::settings(),
            'billing_period' => '2026-11', 'vat_rate_id' => $this->vat, 'items' => []], $override);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/work-report-billing-preview')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)->withParsedBody($body);
        $response = $this->container->get(\MyInvoice\Action\WorkReport\BillingAction::class)->previewUnsaved($request, new Response());
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testStructuredTransferFingerprintReturnsValidationError(): void
    {
        $this->setupReport();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices/' . $this->invoice . '/work-report/billing-transfer')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withParsedBody(['fingerprint' => ['invalid']]);
        $response = $this->container->get(\MyInvoice\Action\WorkReport\BillingAction::class)->transfer($request, new Response(), ['id' => $this->invoice]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public static function assetLinks(): array
    {
        return [['asset_id'], ['small_asset_id']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assetLinks')]
    public function testLegacyReplacementCannotRemoveAssetSale(string $column): void
    {
        $report = $this->setupReport();
        $pdo = $this->db->pdo();
        $sql = $column === 'asset_id'
            ? "INSERT INTO assets (supplier_id, name, inventory_number, input_price, acquisition_date, status) VALUES (?, 'Syntetický majetek', 'TEST-ASSET', 1000, '2026-01-01', 'in_use')"
            : "INSERT INTO small_assets (supplier_id, name, price, acquisition_date) VALUES (?, 'Syntetický drobný majetek', 1000, '2026-01-01')";
        $pdo->prepare($sql)->execute([$this->supplier]);
        $assetId = (int) $pdo->lastInsertId();
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($this->invoice, [['description' => 'Prodej majetku', 'quantity' => 1,
            'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->vat, $column => $assetId]]);
        $before = $repo->itemsFor($this->invoice);
        self::assertSame($assetId, $before[0][$column]);
        try {
            $this->container->get(WorkReportBillingService::class)->transfer($this->invoice, $this->supplier,
                $report['billing_preview']['fingerprint'], $this->container->get(\MyInvoice\Service\Oss\OssItemPlanner::class), (int) $before[0]['id']);
            self::fail('Přenos výkazu nesmí nahradit řádek prodeje majetku.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $repo->itemsFor($this->invoice));
        }
    }

    public function testIssueGuardRejectsChangedLinkedLine(): void
    {
        $report = $this->setupReport();
        $result = $this->transfer($report);
        $result['items'][0]['unit_price_without_vat'] = 1;
        $this->container->get(InvoiceRepository::class)->replaceItems($this->invoice, $result['items']);
        $this->expectException(\DomainException::class);
        $this->container->get(WorkReportBillingService::class)->assertCurrent($this->invoice, $this->supplier);
    }
}

/** Průhledný pozorovatel SQL; výpočty, zápisy a transakce běží na skutečné MariaDB. */
final class BillingIssueObservedPdo extends \PDO
{
    public function __construct(private \PDO $actual, private \Closure $observe) {}
    public function prepare(string $query, array $options = []): \PDOStatement|false { ($this->observe)($query); return $this->actual->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false { return $fetchMode === null ? $this->actual->query($query) : $this->actual->query($query, $fetchMode, ...$fetchModeArgs); }
    public function exec(string $statement): int|false { return $this->actual->exec($statement); }
    public function beginTransaction(): bool { return $this->actual->beginTransaction(); }
    public function commit(): bool { return $this->actual->commit(); }
    public function rollBack(): bool { return $this->actual->rollBack(); }
    public function inTransaction(): bool { return $this->actual->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->actual->lastInsertId($name); }
    public function getAttribute(int $attribute): mixed { return $this->actual->getAttribute($attribute); }
    public function setAttribute(int $attribute, mixed $value): bool { return $this->actual->setAttribute($attribute, $value); }
    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false { return $this->actual->quote($string, $type); }
}
