<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Action\Project\GetProjectAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OverdueDateBoundaryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $supplierId;
    private int $clientId;
    private int $projectId;
    private int $overdueInvoiceId;
    private int $overduePurchaseId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje nakonfigurovanou testovací DB.');
        }
        $this->container = Bootstrap::buildApp()->getContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $sourceId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceId, 'Spusť ci-seed.php pro syntetické testovací číselníky.');
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceId);
        $currencyId = (int) $this->pdo->query("SELECT MIN(id) FROM currencies WHERE code = 'CZK'")->fetchColumn();
        $userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $countryId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, main_email,
                country_id, currency_default_id, is_customer, is_vendor)
             VALUES (?, 'Test hranice splatnosti', 'Testovací 1', 'Praha', '11000', 'due@example.test', ?, ?, 1, 1)"
        )->execute([$this->supplierId, $countryId, $currencyId]);
        $this->clientId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO projects (client_id, name, currency_id) VALUES (?, 'Test splatnosti', ?)"
        )->execute([$this->clientId, $currencyId]);
        $this->projectId = (int) $this->pdo->lastInsertId();

        $today = new \DateTimeImmutable((string) $this->pdo->query('SELECT CURDATE()')->fetchColumn());
        foreach ([-1, 0, 1] as $offset) {
            $due = $today->modify("{$offset} days")->format('Y-m-d');
            $this->pdo->prepare(
                "INSERT INTO invoices (supplier_id, varsymbol, client_id, project_id, issue_date, due_date,
                    currency_id, total_without_vat, total_with_vat, status, created_by)
                 VALUES (?, ?, ?, ?, CURDATE(), ?, ?, 100, 100, 'issued', ?)"
            )->execute([$this->supplierId, 'DUE-' . $offset, $this->clientId, $this->projectId, $due, $currencyId, $userId]);
            if ($offset === -1) $this->overdueInvoiceId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, issue_date,
                    due_date, received_at, currency_id, total_without_vat, total_with_vat, status, created_by, vendor_snapshot)
                 VALUES (?, ?, ?, CURDATE(), ?, CURDATE(), ?, 100, 100, 'received', ?, '{}')"
            )->execute([$this->supplierId, $this->clientId, 'DUE-' . $offset, $due, $currencyId, $userId]);
            if ($offset === -1) $this->overduePurchaseId = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testIssuedInvoiceFilterExcludesTodayIncludingPagination(): void
    {
        $result = $this->container->get(InvoiceRepository::class)->listGroupedByMonth(
            ['supplier_id' => $this->supplierId, 'overdue' => true], 1, 10,
        );
        self::assertSame(1, $result['meta']['total']);
        self::assertSame([$this->overdueInvoiceId], array_column($result['data'][0]['invoices'], 'id'));
    }

    public function testPurchaseInvoiceFilterExcludesTodayIncludingPagination(): void
    {
        $result = $this->container->get(PurchaseInvoiceRepository::class)->listGroupedByMonth(
            ['supplier_id' => $this->supplierId, 'overdue' => true], 1, 10,
        );
        self::assertSame(1, $result['meta']['total']);
        self::assertSame([$this->overduePurchaseId], array_column($result['data'][0]['invoices'], 'id'));
    }

    public function testDashboardCountsOnlyYesterdayAsOverdue(): void
    {
        $action = $this->container->get(SummaryAction::class);
        $year = (int) date('Y');
        $kpi = new \ReflectionMethod($action, 'kpi')->invoke($action, $this->pdo, $year, $year - 1, $this->supplierId, true);
        self::assertSame(1, $kpi['overdue_count']);
        self::assertEquals(100, $kpi['overdue_per_currency'][0]['total']);
        $rows = new \ReflectionMethod($action, 'overdue')->invoke($action, $this->pdo, $this->supplierId);
        self::assertSame([$this->overdueInvoiceId], array_column($rows, 'id'));
        self::assertSame(1, $rows[0]['days_overdue']);
        $upcoming = new \ReflectionMethod($action, 'unpaidUpcoming')->invoke($action, $this->pdo, $this->supplierId);
        self::assertCount(2, $upcoming);
        self::assertNotContains($this->overdueInvoiceId, array_column($upcoming, 'id'));
    }

    public function testClientSummaryExcludesToday(): void
    {
        $this->assertSummary(GetClientAction::class, $this->clientId);
    }

    public function testProjectSummaryExcludesToday(): void
    {
        $this->assertSummary(GetProjectAction::class, $this->projectId);
    }

    private function assertSummary(string $actionClass, int $id): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', '/')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = ($this->container->get($actionClass))($request, new Response(), ['id' => $id]);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['unpaid_summary'][0]['overdue_count']);
        self::assertEquals(100, $data['unpaid_summary'][0]['overdue_total']);
        self::assertEquals(100, $data['unpaid_summary'][0]['overdue_total_czk']);
        self::assertSame(3, $data['unpaid_summary'][0]['unpaid_count']);
    }
}
