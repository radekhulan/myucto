<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Action\Dashboard\PurchaseSummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Charakterizační (golden baseline) testy pro Dashboard\PurchaseSummaryAction —
 * ZAMYKAJÍ současný výstup per-currency agregací NÁKLADŮ PŘED přepisem SQL 10.6→11.8
 * (Epic SQL fáze 2, R2 = purchase_invoices.effective_cost_date). Přepis
 * GREATEST(COALESCE(tax_date, issue_date), issue_date) na generovaný sloupec musí
 * být behavior-preserving → čísla se nesmí hnout.
 *
 * Klíčové pokrytí GREATEST/COALESCE sémantiky (odlišné od vystavených faktur!):
 *   • tax_date < issue_date → GREATEST vytáhne issue_date (recognition = issue_date),
 *   • tax_date > issue_date přes hranici roku → recognition posunut na pozdější datum,
 *   • tax_date = NULL → COALESCE fallback na issue_date.
 *
 * Metoda: transakce + rollback, DELTA proti baseline. Roky 2000/2001 mimo rolling
 * okna; měsíční/rolling testy sázejí relativně k dnešku. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PurchaseSummaryActionCostTest extends TestCase
{
    private Connection $db;
    private PurchaseSummaryAction $action;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $eurId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->action = new PurchaseSummaryAction($this->db);

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country.');
        }
        $this->czkId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->eurId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->czkId === 0) {
            $this->markTestSkipped('Chybí CZK měna pro dodavatele.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->pdo->exec("UPDATE supplier SET is_vat_payer = 1 WHERE id = {$this->supplierId}");
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * costsByYear: rok = YEAR(GREATEST(COALESCE(tax_date, issue_date), issue_date)).
     * Prověřuje všechny tři GREATEST/COALESCE větve.
     */
    public function testCostsByYearPinsGreatestCoalesceAttribution(): void
    {
        $base2001 = $this->yearTotal($this->costsByYear(), 2001, 'CZK');
        $base2000 = $this->yearTotal($this->costsByYear(), 2000, 'CZK');
        $vendor = $this->vendor('Golden Dodavatel A');

        // Normální řádek 2001.
        $this->purchase($vendor, '2001-06-15', '2001-06-15', 800.0, 968.0);
        // tax_date < issue_date → GREATEST vytáhne issue_date (2001), ne tax_date (2000).
        $this->purchase($vendor, '2001-01-10', '2000-12-20', 300.0, 363.0);
        // tax_date NULL → COALESCE→issue_date→2001.
        $this->purchase($vendor, '2001-03-10', null, 150.0, 181.5);
        // tax_date > issue_date přes hranici roku → GREATEST vytáhne tax_date (2001).
        $this->purchase($vendor, '2000-12-20', '2001-01-05', 90.0, 108.9);
        // Kontrolní čistý řádek 2000.
        $this->purchase($vendor, '2000-06-15', '2000-06-15', 700.0, 847.0);

        $after = $this->costsByYear();
        self::assertEqualsWithDelta($base2001 + 1340.0, $this->yearTotal($after, 2001, 'CZK'), 0.01, 'Rok 2001 CZK = 800+300+150+90 (net, plátce).');
        self::assertEqualsWithDelta($base2000 + 700.0, $this->yearTotal($after, 2000, 'CZK'), 0.01, 'Rok 2000 CZK = jen kontrolní 700.');
    }

    /** kpi(): per-currency this_year / prev_year / prev_year_ytd. */
    public function testKpiPerCurrencyYtdTotals(): void
    {
        $baseThis = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'this_year');
        $basePrev = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'prev_year');
        $baseCnt = $this->kpiCur($this->kpi(2001, 2000), 'CZK', 'this_year_invoice_count');
        $vendor = $this->vendor('Golden Dodavatel KPI');

        $this->purchase($vendor, '2001-06-15', '2001-06-15', 800.0, 968.0);
        $this->purchase($vendor, '2001-09-20', '2001-09-20', 200.0, 242.0);
        $this->purchase($vendor, '2000-05-10', '2000-05-10', 500.0, 605.0);

        $after = $this->kpi(2001, 2000);
        self::assertEqualsWithDelta($baseThis + 1000.0, $this->kpiCur($after, 'CZK', 'this_year'), 0.01, 'this_year (2001) = 800+200.');
        self::assertEqualsWithDelta($basePrev + 500.0, $this->kpiCur($after, 'CZK', 'prev_year'), 0.01, 'prev_year (2000) = 500.');
        self::assertEqualsWithDelta($baseCnt + 2.0, $this->kpiCur($after, 'CZK', 'this_year_invoice_count'), 0.01, 'this_year_invoice_count = 2.');
    }

    /** topVendors(): přepočet na CZK přes exchange_rate + agregace přes měny na dodavatele. */
    public function testTopVendorsCzkConversionForFreshVendor(): void
    {
        $vendor = $this->vendor('Golden Dodavatel Multi');
        $this->purchase($vendor, '2001-06-15', '2001-06-15', 1000.0, 1210.0, $this->czkId, 1.0);
        if ($this->eurId > 0) {
            $this->purchase($vendor, '2001-07-15', '2001-07-15', 100.0, 121.0, $this->eurId, 25.0);
        }

        $row = $this->vendorRow($this->topVendors(2001), $vendor);
        self::assertNotNull($row, 'Čerstvý dodavatel je v žebříčku roku 2001.');
        $expected = $this->eurId > 0 ? 3500.0 : 1000.0;
        self::assertEqualsWithDelta($expected, (float) $row['total_czk'], 0.01, 'total_czk = CZK net + EUR net×kurz.');
        self::assertSame($this->eurId > 0 ? 2 : 1, (int) $row['invoice_count'], 'Počet faktur dodavatele.');
    }

    /** costsByMonth: bucket = DATE_FORMAT(GREATEST(COALESCE(tax_date, issue_date), issue_date)). */
    public function testCostsByMonthCurrentMonthBucket(): void
    {
        $ym = date('Y-m');
        $firstOfMonth = date('Y-m-01');
        $prevMonthDay = (new \DateTimeImmutable($firstOfMonth))->modify('-5 days')->format('Y-m-d');
        $base = $this->monthSlot($this->costsByMonth(), 'CZK', $ym);
        $vendor = $this->vendor('Golden Dodavatel Month');

        // issue=tax v aktuálním měsíci.
        $this->purchase($vendor, $firstOfMonth, $firstOfMonth, 300.0, 363.0);
        // issue v minulém měsíci, tax v aktuálním → GREATEST/COALESCE→aktuální měsíc.
        $this->purchase($vendor, $prevMonthDay, $firstOfMonth, 200.0, 242.0);

        $after = $this->monthSlot($this->costsByMonth(), 'CZK', $ym);
        self::assertNotNull($after, "Slot pro aktuální měsíc {$ym} existuje.");
        self::assertEqualsWithDelta(($base ?? 0.0) + 500.0, $after, 0.01, 'Aktuální měsíc CZK = 300+200.');
    }

    /** rolling12mCosts: okno posledních 12 měsíců + předchozích 12 měsíců. */
    public function testRolling12mWindows(): void
    {
        $today = date('Y-m-d');
        $mid = (new \DateTimeImmutable('today'))->modify('-18 months')->format('Y-m-d');
        $base = $this->rolling();
        $baseTotal = $this->rollingVal($base, 'CZK', 'total');
        $basePrev = $this->rollingVal($base, 'CZK', 'prev_period_total');
        $vendor = $this->vendor('Golden Dodavatel Rolling');

        $this->purchase($vendor, $today, $today, 400.0, 484.0);
        $this->purchase($vendor, $mid, $mid, 150.0, 181.5);

        $after = $this->rolling();
        self::assertEqualsWithDelta($baseTotal + 400.0, $this->rollingVal($after, 'CZK', 'total'), 0.01, 'Rolling 12m += dnešní 400.');
        self::assertEqualsWithDelta($basePrev + 150.0, $this->rollingVal($after, 'CZK', 'prev_period_total'), 0.01, 'Předchozích 12m (−18 měsíců) += 150.');
    }

    public function testPayableDashboardUsesBalanceAfterAccountSettlement(): void
    {
        $vendor = $this->vendor('Golden Dodavatel Settlement');
        $overdueDate = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $futureDate = (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');

        $kpiBefore = $this->kpi((int) date('Y'), (int) date('Y') - 1);
        $beforeUnpaid = (int) $kpiBefore['unpaid_count'];
        $beforeTotal = $this->kpiUnpaidTotal($kpiBefore, 'CZK');
        $overdueId = $this->purchase($vendor, $overdueDate, $overdueDate, 164.46, 199.0);
        self::assertSame($beforeUnpaid + 1,
            (int) $this->kpi((int) date('Y'), (int) date('Y') - 1)['unpaid_count']);

        $this->settle($overdueId, 199.0);
        $kpiAfter = $this->kpi((int) date('Y'), (int) date('Y') - 1);
        self::assertSame($beforeUnpaid, (int) $kpiAfter['unpaid_count']);
        self::assertEqualsWithDelta($beforeTotal, $this->kpiUnpaidTotal($kpiAfter, 'CZK'), 0.01);
        self::assertNotContains($overdueId, array_column(
            $this->call('overdue', [$this->pdo, $this->supplierId]),
            'id',
        ));

        $futureId = $this->purchase($vendor, $futureDate, $futureDate, 1000.0, 1210.0);
        $this->settle($futureId, 210.0);
        $upcoming = $this->call('unpaidUpcoming', [$this->pdo, $this->supplierId]);
        $row = null;
        foreach ($upcoming as $candidate) {
            if ((int) $candidate['id'] === $futureId) {
                $row = $candidate;
                break;
            }
        }
        self::assertNotNull($row);
        self::assertSame(1000.0, (float) $row['amount_to_pay']);
    }

    // ── reflection wrappery ────────────────────────────────────────────────────

    private function costsByYear(): array
    {
        return $this->call('costsByYear', [$this->pdo, $this->supplierId, true]);
    }

    private function kpi(int $year, int $prevYear): array
    {
        return $this->call('kpi', [$this->pdo, $year, $prevYear, $this->supplierId, true]);
    }

    private function topVendors(int $year): array
    {
        return $this->call('topVendors', [$this->pdo, $year, $this->supplierId, true]);
    }

    private function costsByMonth(): array
    {
        return $this->call('costsByMonth', [$this->pdo, $this->supplierId, true]);
    }

    private function rolling(): array
    {
        return $this->call('rolling12mCosts', [$this->pdo, $this->supplierId, true]);
    }

    private function call(string $method, array $args): mixed
    {
        $m = new ReflectionMethod($this->action, $method);
        return $m->invokeArgs($this->action, $args);
    }

    // ── extraktory ─────────────────────────────────────────────────────────────

    private function yearTotal(array $rows, int $year, string $cur): float
    {
        foreach ($rows as $r) {
            if ((int) $r['year'] === $year && $r['currency'] === $cur) {
                return (float) $r['total'];
            }
        }
        return 0.0;
    }

    private function kpiCur(array $kpi, string $cur, string $key): float
    {
        foreach ($kpi['per_currency'] as $r) {
            if ($r['currency'] === $cur) {
                return (float) $r[$key];
            }
        }
        return 0.0;
    }

    private function kpiUnpaidTotal(array $kpi, string $cur): float
    {
        foreach ($kpi['unpaid_per_currency'] as $row) {
            if ($row['currency'] === $cur) {
                return (float) $row['total'];
            }
        }
        return 0.0;
    }

    private function vendorRow(array $rows, int $vendorId): ?array
    {
        foreach ($rows as $r) {
            if ((int) $r['vendor_id'] === $vendorId) {
                return $r;
            }
        }
        return null;
    }

    private function monthSlot(array $rows, string $cur, string $ym): ?float
    {
        foreach ($rows as $r) {
            if ($r['currency'] !== $cur) {
                continue;
            }
            foreach ($r['months'] as $m) {
                if ($m['ym'] === $ym) {
                    return (float) $m['total'];
                }
            }
        }
        return null;
    }

    private function rollingVal(array $rows, string $cur, string $key): float
    {
        foreach ($rows as $r) {
            if ($r['currency'] === $cur) {
                return (float) $r[$key];
            }
        }
        return 0.0;
    }

    // ── seed helpers ───────────────────────────────────────────────────────────

    private function vendor(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "vendor@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(
        int $vendorId,
        string $issueDate,
        ?string $taxDate,
        float $net,
        float $gross,
        ?int $currencyId = null,
        float $exchangeRate = 1.0,
        string $status = 'received',
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, ?, "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, 'GOLDP-' . uniqid(), 'GOLDP-' . uniqid(),
            $issueDate, $taxDate, $issueDate, $issueDate,
            $currencyId ?? $this->czkId, $exchangeRate, $net, round($gross - $net, 2), $gross, $status, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function settle(int $purchaseId, float $amount): void
    {
        $accountId = (int) ($this->pdo->query(
            "SELECT id FROM chart_of_accounts
              WHERE supplier_id = {$this->supplierId} AND account_code LIKE '365%'
              ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $accountId, 'Test vyžaduje účet 365.');
        $this->pdo->prepare(
            "INSERT INTO invoice_settlements
                (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status, created_by)
             VALUES (?, 'purchase_invoice', ?, CURDATE(), ?, ?, 'confirmed', ?)"
        )->execute([$this->supplierId, $purchaseId, $amount, $accountId, $this->userId]);
    }
}
