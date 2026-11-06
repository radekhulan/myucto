<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výkazy s průběžným časovým rozlišením mimo deník (volba výsledovky a rozvahy).
 *
 * Doklad s obdobím od–do je v deníku celý v nákladu či výnosu dne dokladu. S volbou
 * výkaz k rozvahovému dni započte jen poměrnou část období do toho dne včetně a zbytek
 * ukáže na 381/384; rozvaha dál bilancuje. Co už uzávěrka na 381/384 zaúčtovala
 * (odklad ke konci roku, rozpuštění v dalším roce), se znovu neupravuje. Bez volby
 * jsou výkazy beze změny. Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class FinancialStatementAccrualSpreadTest extends TestCase
{
    // 2091 = běžné období (365 dnů); období dokladů přesahují do 2092 (přestupný, 366 dnů).
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private FinancialStatementService $statements;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $partnerId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->posting    = $container->get(PostingService::class);
            $this->closing    = $container->get(ClosingService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        )->execute(['Průběžné rozlišení test s.r.o.', $this->czId, 'accrual-spread@example.com', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Obchodní partner", "Test 1", "Praha", "11000", ?, "CZ12345678", "partner@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->partnerId = (int) $pdo->lastInsertId();

        // Základní kapitál, ať rozvaha nemá jen upravované účty.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 200000.00],
            ['account_code' => '411', 'side' => 'credit', 'amount' => 200000.00],
        ], ['entry_date' => self::STARTS_ON, 'description' => 'Vklad', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
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

    /**
     * Pojistné 36 600 Kč (1. 7. 2091 – 30. 6. 2092, 366 dnů) a roční předplatné 36 600 Kč
     * na stejné období: k 31. 12. připadá na 2092 182 dnů = 18 200 Kč na 381 a 384.
     */
    public function testYearEndSpreadMovesOverlapTo381And384AndBalances(): void
    {
        $this->seedDocuments();

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);

        self::assertArrayNotHasKey('accrual_spread', $plain, 'Bez volby se výstup výkazu nemění.');
        self::assertTrue($spread['accrual_spread']['applied']);
        self::assertEqualsWithDelta(18200.00, $spread['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(18200.00, $spread['accrual_spread']['deferred_revenue'], 0.001);
        // Náklad −18 200 a výnos −18 200 se ve VH vyruší, řádky ale ne.
        self::assertEqualsWithDelta($plain['checks']['profit_current'], $spread['checks']['profit_current'], 0.001);
        self::assertTrue($spread['checks']['profit_matches']);
        self::assertEqualsWithDelta(36600.00 + 9200.00, $this->accountAmount($plain['rows'], '548') + $this->accountAmount($plain['rows'], '518'), 0.001);
        self::assertEqualsWithDelta(18400.00, $this->accountAmount($spread['rows'], '548'), 0.001);
        self::assertEqualsWithDelta(9200.00, $this->accountAmount($spread['rows'], '518'), 0.001, 'Období končící v roce se nerozlišuje.');
        self::assertEqualsWithDelta(18400.00, $this->accountAmount($spread['rows'], '602'), 0.001);

        $bsPlain = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        self::assertTrue($bsPlain['checks']['balanced']);
        self::assertTrue($bs['checks']['balanced'], 'Rozvaha s průběžným rozlišením musí bilancovat.');
        self::assertTrue($bs['checks']['profit_matches']);
        self::assertEqualsWithDelta(0.0, $this->accountAmount($bsPlain['assets'], '381'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->accountAmount($bs['assets'], '381'), 0.001);
        self::assertEqualsWithDelta(18200.00, abs($this->accountAmount($bs['liabilities'], '384')), 0.001);
        self::assertEqualsWithDelta($bsPlain['checks']['assets_net'] + 18200.00, $bs['checks']['assets_net'], 0.001);
    }

    /**
     * K 31. 10. 2091: předplatné od 1. 7. má za sebou 123 dnů z 366 (12 300 Kč výnos),
     * zbytek 24 300 Kč je na 384. Nájem 1. 10. – 31. 12. (9 200 Kč, 92 dnů) má za sebou
     * 31 dnů, na 381 jde 61/92 = 6 100 Kč. Pojistné zaúčtované 15. 11. k 31. 10. ve výkazu
     * není, takže se neupravuje.
     */
    public function testMidYearSpreadUsesDaysUpToReportDate(): void
    {
        $this->seedDocuments();
        $asOf = self::YEAR . '-10-31';

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, $asOf, 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, $asOf, 'full', null, true);
        self::assertEqualsWithDelta(6100.00, $spread['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(24300.00, $spread['accrual_spread']['deferred_revenue'], 0.001);
        self::assertSame(2, $spread['accrual_spread']['documents']);
        self::assertEqualsWithDelta(3100.00, $this->accountAmount($spread['rows'], '518'), 0.001);
        self::assertEqualsWithDelta(12300.00, $this->accountAmount($spread['rows'], '602'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->accountAmount($spread['rows'], '548'), 0.001);
        self::assertEqualsWithDelta($plain['checks']['profit_current'] - 24300.00 + 6100.00, $spread['checks']['profit_current'], 0.001);

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, $asOf, 'full', null, true);
        self::assertTrue($bs['checks']['balanced'], 'Rozvaha k datu uprostřed roku musí bilancovat.');
        self::assertTrue($bs['checks']['profit_matches']);
        self::assertEqualsWithDelta($spread['checks']['profit_current'], $bs['checks']['profit_current'], 0.001);
        self::assertEqualsWithDelta(6100.00, $this->accountAmount($bs['assets'], '381'), 0.001);
        self::assertEqualsWithDelta(24300.00, abs($this->accountAmount($bs['liabilities'], '384')), 0.001);
    }

    /** Období celé za rozvahovým dnem jde na 381 celé. */
    public function testPeriodEntirelyAfterReportDateIsDeferredWhole(): void
    {
        $pi = $this->purchase('PF-2091-PRED', 12000.00, self::YEAR . '-03-10');
        $this->purchaseItem($pi, 12000.00, '2091-06-01', '2091-11-30', '518');
        $this->postPurchase($pi, '518', 12000.00, self::YEAR . '-03-10');

        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-04-30', 'full', null, true);
        self::assertEqualsWithDelta(12000.00, $spread['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->accountAmount($spread['rows'], '518'), 0.001);
    }

    /**
     * Nezaúčtovaná přijatá faktura náklad ve výkazu nemá, takže se ani neodkládá
     * (uzávěrku by zastavila kontrola nezaúčtovaných dokladů, výkaz ne).
     */
    public function testUnpostedPurchaseIsNotDeferred(): void
    {
        $pi = $this->purchase('PF-2091-NEZ', 12000.00, self::YEAR . '-03-10');
        $this->purchaseItem($pi, 12000.00, '2091-03-01', '2092-02-29', '518');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-06-30', 'full', null, true);
        self::assertEqualsWithDelta(0.0, $bs['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->accountAmount($bs['assets'], '381'), 0.001);
        self::assertTrue($bs['checks']['balanced']);
    }

    /**
     * Po zaúčtování kroku uzávěrky „Časové rozlišení" je přesah k 31. 12. v deníku.
     * Výkaz s volbou k 31. 12. ho znovu neodkládá a je stejný jako bez volby. K datu
     * uprostřed roku zápis uzávěrky (31. 12.) ve výkazu není, takže úprava platí dál.
     */
    public function testNoDoubleCountAfterClosingDeferral(): void
    {
        $this->seedDocuments();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $bsPlain = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        self::assertEqualsWithDelta(18200.00, $this->accountAmount($bsPlain['assets'], '381'), 0.001, 'Odklad je v deníku.');
        self::assertEqualsWithDelta(0.0, $bs['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(0.0, $bs['accrual_spread']['deferred_revenue'], 0.001);
        self::assertEqualsWithDelta(18200.00, $this->accountAmount($bs['assets'], '381'), 0.001, 'Odklad uzávěrky se nesmí započíst dvakrát.');
        self::assertEqualsWithDelta($bsPlain['checks']['assets_net'], $bs['checks']['assets_net'], 0.001);
        self::assertTrue($bs['checks']['balanced']);

        $mid = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);
        self::assertEqualsWithDelta(6100.00, $this->accountAmount($mid['assets'], '381'), 0.001);
        self::assertTrue($mid['checks']['balanced']);
    }

    /**
     * Rozpuštění v dalším roce (openNext) je v deníku. Výkaz 2092 s volbou doklady roku
     * 2091 neupravuje: jejich přesah už v 2092 je jako tranše rozpuštění.
     */
    public function testReleasedDeferralIsNotAdjustedInNextYear(): void
    {
        $this->seedDocuments();
        $this->driveClose();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $next = $this->periods->findByYear($this->supplierId, self::YEAR + 1);
        self::assertNotNull($next);

        $asOf = (self::YEAR + 1) . '-03-31';
        $plain = $this->statements->incomeStatement($this->supplierId, (int) $next['id'], $asOf, 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, (int) $next['id'], $asOf, 'full', null, true);
        self::assertEqualsWithDelta(0.0, $spread['accrual_spread']['prepaid_expense'], 0.001);
        self::assertEqualsWithDelta(0.0, $spread['accrual_spread']['deferred_revenue'], 0.001);
        self::assertEqualsWithDelta($plain['checks']['profit_current'], $spread['checks']['profit_current'], 0.001);
        self::assertEqualsWithDelta(18200.00, $this->accountAmount($spread['rows'], '548'), 0.001, 'Tranše rozpuštění 2092.');
        // Sloupec minulého období (uzavřený rok s odkladem uzávěrky) se volbou nemění.
        self::assertEqualsWithDelta(0.0, $spread['accrual_spread']['prev']['prepaid_expense'], 0.001);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function seedDocuments(): void
    {
        $pi = $this->purchase('PF-2091-POJ', 36600.00, self::YEAR . '-11-15');
        $this->purchaseItem($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->postPurchase($pi, '548', 36600.00, self::YEAR . '-11-15');

        $rent = $this->purchase('PF-2091-NAJ', 9200.00, self::YEAR . '-10-01');
        $this->purchaseItem($rent, 9200.00, '2091-10-01', '2091-12-31', '518');
        $this->postPurchase($rent, '518', 9200.00, self::YEAR . '-10-01');

        $inv = $this->invoice('20910001', 36600.00, self::YEAR . '-07-01');
        $this->invoiceItem($inv, 36600.00, '2091-07-01', '2092-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $inv, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /**
     * Součet částek účtu (syntetiky) přes řádky výkazu.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function accountAmount(array $rows, string $code): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            foreach ($row['accounts'] ?? [] as $account) {
                if ((string) $account['account_code'] === $code) {
                    $sum += (float) $account['amount'];
                }
            }
        }
        return round($sum, 2);
    }

    private function purchase(string $number, float $base, string $date): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->partnerId, $number, $date, $date, $date, $date,
            $this->currencyId, $base, $base, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseItem(int $purchaseId, float $base, string $from, string $to, string $account): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Služba na období", 1, "ks", ?, ?, 21.00, ?, 0, ?, 0, ?, ?, ?)'
        )->execute([$purchaseId, $base, $this->vatRateId, $base, $base, $account, $from, $to]);
    }

    private function postPurchase(int $purchaseId, string $account, float $base, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, [
            ['account_code' => $account, 'side' => 'debit', 'amount' => $base],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $base],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    private function invoice(string $number, float $base, string $date): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, "invoice", NULL, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "sent", "1", ?)'
        )->execute([
            $this->supplierId, $number, $this->partnerId, $date, $date, $date,
            $this->currencyId, $base, $base, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoiceItem(int $invoiceId, float $base, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 accrual_from, accrual_to)
             VALUES (?, "Roční předplatné", 1, "ks", ?, ?, 0, ?, 0, ?, 0, ?, ?)'
        )->execute([$invoiceId, $base, $this->vatRateId, $base, $base, $from, $to]);
    }

    /** start → precheck → povinné kroky se zaúčtovaným časovým rozlišením, inventarizace. */
    private function driveClose(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runPrepaidExpenseAccrual($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runDeferredRevenueAccrual($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'done', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $this->rv(), ['complete' => true], $items, ['user_id' => $this->userId]);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }
}
