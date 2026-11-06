<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Portal\PortalSummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Karta DPH přebírá periodicitu firmy a sumuje řádky celého příslušného období. */
#[Group('integration')]
final class PortalVatPeriodTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PortalSummaryAction $action;
    private int $supplierId;
    private int $contactId;
    private int $currencyId;
    private int $vatRateId;
    private int $userId;
    private int $sequence = 0;
    private \DateTimeImmutable $today;
    private \DateTimeImmutable $quarterStart;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $db = $container->get(Connection::class);
            $pdo = $db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB nedostupná: ' . $e->getMessage());
        }
        $this->db = $db;
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->action = $container->get(PortalSummaryAction::class);

        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $this->vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        foreach ([$source, $this->userId, $this->currencyId, $this->vatRateId, $countryId] as $id) {
            self::assertGreaterThan(0, $id, 'Test vyžaduje základní syntetická data.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare("UPDATE supplier SET company_name = 'Test období portálu', display_name = '', vat_period = 'quarterly', oss_enabled = 0 WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, 'Testovací protistrana portálu', 'Testovací 1', 'Praha', '11000', ?,
                     'portal-vat@example.com', 'cs', ?, 1, 1)"
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $this->contactId = (int) $pdo->lastInsertId();

        $this->today = new \DateTimeImmutable('today');
        $firstMonth = intdiv((int) $this->today->format('n') - 1, 3) * 3 + 1;
        $this->quarterStart = $this->today->setDate((int) $this->today->format('Y'), $firstMonth, 1);
        for ($index = 1; $index <= 3; $index++) {
            $date = $this->quarterStart->modify('+' . ($index - 1) . ' months')->format('Y-m-d');
            $this->documents($date, $index * 1000, $index * 100);
        }
        // Doklady těsně mimo kvartál nesmějí do součtů proniknout na žádné straně.
        $this->documents($this->quarterStart->modify('-1 day')->format('Y-m-d'), 10000, 10000);
        $this->documents($this->quarterStart->modify('+3 months')->format('Y-m-d'), 10000, 10000);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testQuarterlyVatStatusIncludesWholeQuarter(): void
    {
        $vat = $this->summary()['vat'];
        self::assertTrue($vat['is_vat_payer']);
        self::assertIsArray($vat['status'], 'Výpočet DPH nesmí tiše skončit prázdnou kartou.');
        $status = $vat['status'];
        self::assertSame('quarterly', $status['period_type']);
        self::assertSame(intdiv((int) $this->today->format('n') - 1, 3) + 1, $status['quarter']);
        $this->assertAmounts($status, 1260, 126, 1134);
        $deadlineMonth = $this->quarterStart->modify('+3 months');
        self::assertSame(CzechWorkingDays::deadline((int) $deadlineMonth->format('Y'), (int) $deadlineMonth->format('n')), $status['submission_deadline']);
    }

    public function testMonthlyVatStatusIncludesOnlyCurrentMonth(): void
    {
        $this->setPeriod('monthly');
        $this->assertMonthlyStatus();
    }

    public function testMissingVatPeriodDefaultsToMonthly(): void
    {
        $this->setPeriod(null);
        $this->assertMonthlyStatus();
    }

    public function testNonPayerHasNoVatStatus(): void
    {
        $this->setVatPayerAt($this->db->pdo(), $this->supplierId, '1900-01-01', false);
        $vat = $this->summary()['vat'];
        self::assertFalse($vat['is_vat_payer']);
        self::assertNull($vat['status']);
    }

    private function assertMonthlyStatus(): void
    {
        $status = $this->summary()['vat']['status'];
        self::assertIsArray($status);
        self::assertSame('monthly', $status['period_type']);
        self::assertSame($this->today->format('Y-m'), $status['period']);
        self::assertNull($status['quarter']);
        $index = (int) $this->today->format('n') - (int) $this->quarterStart->format('n') + 1;
        $this->assertAmounts($status, $index * 210, $index * 21, $index * 189);
        $deadlineMonth = $this->today->modify('first day of next month');
        self::assertSame(CzechWorkingDays::deadline((int) $deadlineMonth->format('Y'), (int) $deadlineMonth->format('n')), $status['submission_deadline']);
    }

    /** @param array<string,mixed> $status */
    private function assertAmounts(array $status, int $output, int $input, int $due): void
    {
        self::assertEquals($output, $status['vat_output']);
        self::assertEquals($input, $status['vat_input']);
        self::assertEquals($due, $status['tax_due']);
        self::assertFalse($status['is_excess_deduction']);
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/portal/summary')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = ($this->action)($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function setPeriod(?string $period): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET vat_period = ? WHERE id = ?')->execute([$period, $this->supplierId]);
    }

    private function documents(string $date, int $saleBase, int $purchaseBase): void
    {
        $pdo = $this->db->pdo();
        $number = 'PORTAL-VAT-' . ++$this->sequence;
        $saleVat = $saleBase * 0.21;
        $purchaseVat = $purchaseBase * 0.21;
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                                   due_date, currency_id, exchange_rate, client_snapshot, supplier_snapshot,
                                   total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, 1, '{}', '{}', ?, ?, ?, 'issued', ?)"
        )->execute([$this->supplierId, $this->contactId, $number, $date, $date, $date,
            $this->currencyId, $saleBase, $saleVat, $saleBase + $saleVat, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
                                        vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat,
                                        total_with_vat, order_index, vat_classification_code)
             VALUES (?, 'Test období', 1, 'ks', ?, ?, 21, ?, ?, ?, 0, '1')"
        )->execute([$invoiceId, $saleBase, $this->vatRateId, $saleBase, $saleVat, $saleBase + $saleVat]);
        $pdo->prepare(
            "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind,
                                            issue_date, tax_date, due_date, received_at, received_at_source,
                                            currency_id, exchange_rate, vendor_snapshot, total_without_vat, total_vat,
                                            total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, 'manual', ?, 1, '{}', ?, ?, ?, 'received', '40', 'full', ?)"
        )->execute([$this->supplierId, $this->contactId, $number, $date, $date, $date, $date,
            $this->currencyId, $purchaseBase, $purchaseVat, $purchaseBase + $purchaseVat, $this->userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit,
                                                 unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                                 total_without_vat, total_vat, total_with_vat,
                                                 order_index, vat_classification_code)
             VALUES (?, 'Test období', 1, 'ks', ?, ?, 21, ?, ?, ?, 0, '40')"
        )->execute([$purchaseId, $purchaseBase, $this->vatRateId, $purchaseBase, $purchaseVat, $purchaseBase + $purchaseVat]);
    }
}
