<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\LegalProvisionLedgerService;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JournalReversalTaxOriginTest extends TestCase
{
    private Connection $db;
    private PostingService $posting;
    private DppoReturnDataProvider $provider;
    private int $supplierId;
    private array $periodIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje lokální testovací databázi.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->provider = $container->get(DppoReturnDataProvider::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier (company_name, street, city, zip, email, taxpayer_type, country_id, default_currency_id, default_vat_rate_id)
            SELECT 'Test daňového původu', 'Testovací 1', 'Vzorov', '10000', 'tax-origin@example.com', 'po',
                (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1),
                (SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1)");
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        foreach ([2091, 2092, 2093] as $year) {
            $this->periodIds[$year] = $container->get(AccountingPeriodRepository::class)->create($this->supplierId, $year, "$year-01-01", "$year-12-31");
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testStockReversalChainPreservesReportAndTaxAmountsAcrossYears(): void
    {
        $stock = $this->post('closing', ClosingSourceId::stockClosing($this->periodIds[2091]), '112', '501', 100000);
        $technical = $this->post('closing', $this->periodIds[2091], '710', '501', 700000);
        foreach ([$stock, $technical] as $entryId) {
            $reversal = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => '2092-06-01']);
            $this->posting->reverse($this->supplierId, $reversal, ['entry_date' => '2093-06-01']);
        }
        $ledger = new LedgerReportRepository($this->db);
        foreach ([2091 => 100000.0, 2092 => -100000.0, 2093 => 100000.0] as $year => $expected) {
            $data = $this->provider->gather($this->supplierId, $year);
            self::assertSame($expected, $data['vh']);
            $balances = $ledger->syntheticBalances($this->supplierId, "$year-12-31", "$year-01-01");
            $expense = array_values(array_filter($balances, static fn (array $r): bool => $r['code'] === '501'))[0];
            self::assertSame($expected, round($expense['d'] - $expense['md'], 2));
        }
    }

    public function testNondeductiblePurchaseReversalUsesTheOriginalInvoice(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT ?, 'Testovací dodavatel', 'Testovací 2', 'Vzorov', '10000', country_id, 'vendor-origin@example.com', default_currency_id FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $this->supplierId]);
        $vendorId = (int) $pdo->lastInsertId();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot, status, tax_deductible, created_by) VALUES (?, ?, 'TEST-STORNO', '2091-06-01', '2091-06-01', '2091-06-15', '2091-06-01', ?, '{}', 'received', 0, (SELECT id FROM users ORDER BY id LIMIT 1))")
            ->execute([$this->supplierId, $vendorId, $currencyId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $entry = $this->post('purchase_invoice', $purchaseId, '518', '321', 100000);
        $reversal = $this->posting->reverse($this->supplierId, $entry, ['entry_date' => '2092-06-01']);
        $this->posting->reverse($this->supplierId, $reversal, ['entry_date' => '2093-06-01']);
        $service = new NonDeductibleCostsService($this->db);
        foreach ([2091 => 100000.0, 2092 => -100000.0, 2093 => 100000.0] as $year => $expected) {
            self::assertSame($expected, $service->sum($this->supplierId, "$year-01-01", "$year-12-31"));
            $data = $this->provider->gather($this->supplierId, $year);
            self::assertSame($expected, $data['non_deductible_costs']);
            $computed = (new DppoReturnCalculator())->compute($data, [], []);
            self::assertSame(0.0, $computed['summary']['base']);
            self::assertSame(0.0, $computed['tax']);
        }
    }

    public function testLegalReserveCreationAndBalancePreserveSamePeriodReversalChain(): void
    {
        $entry = $this->post('manual', 1, '552', '451', 5000);
        $reversal = $this->posting->reverse($this->supplierId, $entry, ['entry_date' => '2091-07-01']);
        $this->posting->reverse($this->supplierId, $reversal, ['entry_date' => '2091-08-01']);
        $technical = $this->post('closing', $this->periodIds[2091], '451', '702', 5000);
        $this->posting->reverse($this->supplierId, $technical, ['entry_date' => '2091-09-01']);
        $result = (new LegalProvisionLedgerService($this->db))->forPeriod($this->supplierId, $this->periodIds[2091], '2091-01-01', '2091-12-31');
        self::assertSame(5000.0, $result['legal_reserve_created']);
        self::assertSame(5000.0, $result['legal_reserve_balance']);
    }

    private function post(string $type, int $sourceId, string $debit, string $credit, float $amount): int
    {
        return $this->posting->postDocument($this->supplierId, $type, $sourceId, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2091-06-01', 'posted' => true]);
    }
}
