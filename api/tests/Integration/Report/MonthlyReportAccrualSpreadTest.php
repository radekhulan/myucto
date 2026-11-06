<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Pdf\MonthlyReportPdfRenderer;
use MyInvoice\Service\Report\MonthlyReportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Měsíční přehled s průběžným časovým rozlišením mimo deník. Přijatá faktura 9 200 Kč
 * s obdobím 1. 10. – 31. 12. (92 dnů, 100 Kč na den) zaúčtovaná 1. 10.: bez volby je
 * v říjnu celý náklad, s volbou jen 31 dnů, v listopadu dalších 30 a na 381 je zbytek.
 * Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class MonthlyReportAccrualSpreadTest extends TestCase
{
    // Minulý rok: přehled ořezává rozhodné datum na dnešek.
    private const YEAR = 2025;

    private Connection $db;
    private MonthlyReportService $service;
    private MonthlyReportPdfRenderer $pdf;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(MonthlyReportService::class);
        $this->pdf = $container->get(MonthlyReportPdfRenderer::class);
        $posting = $container->get(PostingService::class);

        $pdo = $this->db->pdo();
        $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if ($userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Přehled rozlišení test s.r.o.', $czId, 'monthly-spread@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel", "Test 1", "Praha", "11000", ?, "CZ12345678", "partner@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $currencyId]);
        $vendorId = (int) $pdo->lastInsertId();

        $date = self::YEAR . '-10-01';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, "PF-NAJEM", "invoice", ?, ?, ?, ?, ?, NULL, 0, "{}", 9200, 0, 9200, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $currencyId, $userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Nájem", 1, "ks", 9200, ?, 21.00, 9200, 0, 9200, 0, "518", ?, ?)'
        )->execute([$purchaseId, $vatRateId, $date, self::YEAR . '-12-31']);
        $posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 9200.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 9200.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $userId, 'user_id' => $userId]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->db->close();
    }

    public function testWithoutOptionMonthCarriesWholeDocument(): void
    {
        $october = $this->service->build($this->supplierId, self::YEAR, 10);

        self::assertArrayNotHasKey('accrual_spread', $october['income_statement_ytd']);
        self::assertArrayNotHasKey('accrual_spread', $october['balance_sheet']);
        self::assertEqualsWithDelta(-9200.00, $this->monthResult($october), 0.001);
    }

    public function testMonthRowsCarryOnlyThatMonthsShareOfDocument(): void
    {
        $october = $this->service->build($this->supplierId, self::YEAR, 10, null, true);
        $november = $this->service->build($this->supplierId, self::YEAR, 11, null, true);

        self::assertTrue($october['income_statement_ytd']['accrual_spread']['applied']);
        self::assertEqualsWithDelta(6100.00, $october['income_statement_ytd']['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(-3100.00, $this->monthResult($october), 0.001, 'Říjen = 31 dnů.');
        self::assertEqualsWithDelta(-3000.00, $this->monthResult($november), 0.001, 'Listopad = 30 dnů.');
        self::assertEqualsWithDelta(-6100.00, $november['income_statement_ytd']['checks']['profit_current'], 0.001);
    }

    public function testBalanceSheetAndPdfCarryTheOption(): void
    {
        $data = $this->service->build($this->supplierId, self::YEAR, 10, null, true);

        self::assertTrue($data['balance_sheet']['checks']['balanced'], 'Rozvaha s rozlišením musí bilancovat.');
        self::assertEqualsWithDelta($data['income_statement_ytd']['checks']['profit_current'], $data['balance_sheet']['checks']['profit_current'], 0.001);

        self::assertStringStartsWith('%PDF', $this->pdf->render($data));
        $plain = $this->service->build($this->supplierId, self::YEAR, 10);
        self::assertStringStartsWith('%PDF', $this->pdf->render($plain));
    }

    public function testNoteIsInTemplateOnlyWithOption(): void
    {
        $twig = (new \ReflectionMethod(MonthlyReportPdfRenderer::class, 'twig'))->invoke($this->pdf);

        $withOption = $twig->render('monthly-report.twig', $this->service->build($this->supplierId, self::YEAR, 10, null, true));
        self::assertStringContainsString('Průběžné časové rozlišení mimo deník', $withOption);
        self::assertStringContainsString('6 100,00', $withOption);

        $without = $twig->render('monthly-report.twig', $this->service->build($this->supplierId, self::YEAR, 10));
        self::assertStringNotContainsString('Průběžné časové rozlišení mimo deník', $without);
    }

    /**
     * Výsledek hospodaření za samotný měsíc.
     *
     * @param array<string,mixed> $data
     */
    private function monthResult(array $data): float
    {
        foreach ($data['income_statement_month'] as $row) {
            if ($row['row_code'] === 'VH') {
                return (float) $row['amount'];
            }
        }
        self::fail('Výsledovka za měsíc nemá řádek VH.');
    }
}
