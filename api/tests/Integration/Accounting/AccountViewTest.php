<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\AccountViewPdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozvaha a výsledovka po účtech (FinancialStatementService::accountView): struktura,
 * součty, dělení výsledkových účtů podle mapy VZZ a invariant
 * VH z rozvahových účtů == VH z výsledkových účtů == VH výkazu zisku a ztráty.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class AccountViewTest extends TestCase
{
    private const YEAR = 2099;
    private const AS_OF = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private FinancialStatementService $statements;
    private AccountingPeriodRepository $periods;
    private ReportXlsxExporter $xlsx;
    private AccountViewPdfRenderer $pdf;

    private int $supplierId = 0;
    private int $userId = 0;
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
            $this->db         = $container->get(Connection::class);
            $this->posting    = $container->get(PostingService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->xlsx       = $container->get(ReportXlsxExporter::class);
            $this->pdf        = $container->get(AccountViewPdfRenderer::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "po-uctech@example.com", ?, ?)'
        );
        $stmt->execute(['Po účtech test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

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

    public function testSectionsAndSubtotalsFollowIncomeStatementMap(): void
    {
        $this->seedScenario();

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);
        $pl = $view['profit_loss'];
        $sections = array_column($pl['sections'], null, 'key');

        self::assertSame(['operating', 'financial', 'unassigned', 'tax', 'transfer'], array_column($pl['sections'], 'key'));
        self::assertSame(['518', '551', '559'], array_column($sections['operating']['expenses'], 'account_code'));
        self::assertSame(['602'], array_column($sections['operating']['revenues'], 'account_code'));
        self::assertSame(['562'], array_column($sections['financial']['expenses'], 'account_code'));
        self::assertSame(['662'], array_column($sections['financial']['revenues'], 'account_code'));
        self::assertSame(['591'], array_column($sections['tax']['expenses'], 'account_code'));
        self::assertSame([], $sections['unassigned']['expenses']);

        self::assertSame(self::cents(900.00), self::cents($sections['operating']['expense_total']));
        self::assertSame(self::cents(1000.00), self::cents($sections['operating']['revenue_total']));
        self::assertSame(self::cents(100.00), self::cents($pl['operating_profit']), 'Provozní VH = 1000 − 900.');
        self::assertSame(self::cents(-150.00), self::cents($pl['financial_profit']), 'Finanční VH = 50 − 200.');
        self::assertSame(self::cents(-50.00), self::cents($pl['profit_before_tax']));
        self::assertSame(self::cents(-80.00), self::cents($pl['profit_after_tax']), 'Po zdanění = −50 − daň 30.');
        self::assertSame(self::cents(-80.00), self::cents($pl['profit']));

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $rows = array_column($vzz['rows'], null, 'row_code');
        self::assertSame(self::cents($rows['PVH']['amount']), self::cents($pl['operating_profit']), 'Provozní VH == řádek PVH výkazu.');
        self::assertSame(self::cents($rows['FVH']['amount']), self::cents($pl['financial_profit']), 'Finanční VH == řádek FVH výkazu.');
        self::assertSame(self::cents($rows['VHPZ']['amount']), self::cents($pl['profit_before_tax']));
        self::assertSame(self::cents($rows['VHPO']['amount']), self::cents($pl['profit_after_tax']));
    }

    public function testProfitInvariantBalanceEqualsProfitLossEqualsStatement(): void
    {
        $this->seedScenario();

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);
        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $pav = array_column($bs['liabilities'], null, 'row_code')['P.A.V.'];

        self::assertSame(self::cents(-80.00), self::cents($view['balance']['profit']));
        self::assertSame(self::cents($view['balance']['profit']), self::cents($view['profit_loss']['profit']), 'VH rozvaha po účtech == výsledovka po účtech.');
        self::assertSame(self::cents($vzz['checks']['profit_current']), self::cents($view['profit_loss']['profit']), 'VH po účtech == VZZ checks.profit_current.');
        self::assertSame(self::cents($pav['amount']), self::cents($view['balance']['profit']), 'VH po účtech == rozvaha P.A.V.');
        self::assertTrue($view['checks']['profit_matches']);
        self::assertSame(0, self::cents($view['checks']['technical_residual']));
        self::assertSame(
            self::cents($view['balance']['md']) - self::cents($view['balance']['d']),
            self::cents($view['balance']['profit']),
            'VH = Σ MD − Σ D rozvahových účtů.',
        );
    }

    public function testBalanceClassesAndAnalyticsWithoutCompensation(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '221'"
        )->fetchColumn();
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, ?, ?, "asset", "debit", 0, ?)'
        );
        $ins->execute([$this->supplierId, '221001', 'Běžný účet', $parentId]);
        $ins->execute([$this->supplierId, '221002', 'Kontokorent', $parentId]);

        $this->manual([self::l('221001', 'debit', 5000.00), self::l('411', 'credit', 5000.00)], self::YEAR . '-01-10');
        $this->manual([self::l('518', 'debit', 2000.00), self::l('221002', 'credit', 2000.00)], self::YEAR . '-02-10');

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);
        $classes = array_column($view['balance']['classes'], null, 'class');

        self::assertSame(['2', '4'], array_column($view['balance']['classes'], 'class'));
        $bank = $classes['2']['accounts'][0];
        self::assertSame('221', $bank['account_code']);
        self::assertSame(self::cents(5000.00), self::cents($bank['md']), 'Běžný účet na straně MD.');
        self::assertSame(self::cents(2000.00), self::cents($bank['d']), 'Kontokorent na straně D, bez kompenzace.');
        self::assertSame(['221001', '221002'], array_column($bank['analytics'], 'account_code'));
        self::assertSame([], $classes['4']['accounts'][0]['analytics'], 'Syntetika bez analytik rozpad nemá.');

        self::assertSame(self::cents(5000.00), self::cents($view['balance']['md']));
        self::assertSame(self::cents(7000.00), self::cents($view['balance']['d']));
        self::assertSame(self::cents(-2000.00), self::cents($view['balance']['profit']), 'Ztráta 2000 z nákladu 518.');
        self::assertSame(self::cents(-2000.00), self::cents($view['profit_loss']['profit']));
    }

    public function testClosingEntryDoesNotChangeTheView(): void
    {
        $this->seedScenario();
        $before = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);

        // Uzávěrka převádí výsledkové účty na 710 a rozvahové na 702; pohled po účtech
        // ukazuje zůstatky před ní, tedy právě obsah těchto dvou účtů.
        $this->posting->postDocument($this->supplierId, 'closing', null, [
            self::l('602', 'debit', 1000.00),
            self::l('710', 'credit', 1000.00),
            self::l('710', 'debit', 1000.00),
            self::l('311', 'credit', 1000.00),
        ], ['entry_date' => self::AS_OF, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $after = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);
        self::assertSame($before['balance'], $after['balance']);
        self::assertSame($before['profit_loss'], $after['profit_loss']);
        self::assertTrue($after['checks']['profit_matches']);
    }

    public function testOpeningEntryKeepsProfitAndTechnicalResidualZero(): void
    {
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('211', 'debit', 1000.00),
            self::l('701', 'credit', 1000.00),
            self::l('701', 'debit', 1000.00),
            self::l('431', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-01-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->manual([self::l('211', 'debit', 300.00), self::l('602', 'credit', 300.00)], self::YEAR . '-03-01');

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::YEAR . '-06-30');

        self::assertSame(self::cents(300.00), self::cents($view['balance']['profit']), '431 z minulého roku do VH běžného roku nepatří.');
        self::assertSame(self::cents(300.00), self::cents($view['profit_loss']['profit']));
        self::assertSame(0, self::cents($view['checks']['technical_residual']));
        self::assertTrue($view['checks']['profit_matches']);
        self::assertSame(self::YEAR . '-06-30', $view['as_of']);
    }

    public function testAccountOutsideStatementMapIsListedAsUnassigned(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, "570", "Vlastní nákladový účet", "expense", "debit", 1, NULL)'
        )->execute([$this->supplierId]);
        $this->manual([self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-03-01');
        $this->manual([self::l('570', 'debit', 400.00), self::l('321', 'credit', 400.00)], self::YEAR . '-03-02');

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF);
        $sections = array_column($view['profit_loss']['sections'], null, 'key');

        self::assertSame(['570'], array_column($sections['unassigned']['expenses'], 'account_code'));
        self::assertSame(1, $view['checks']['unassigned_count']);
        self::assertSame(self::cents(600.00), self::cents($view['profit_loss']['profit']), 'VH zahrnuje i nezařazený účet.');
        self::assertTrue($view['checks']['profit_matches']);

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        self::assertNotSame(self::cents($vzz['checks']['profit_current']), self::cents($view['profit_loss']['profit']),
            'Výkaz nezařazený účet nemá, proto se pohled po účtech musí odlišit a hlásit ho.');
    }

    public function testExportsRender(): void
    {
        $this->seedScenario();
        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF, null, ['hide_zero' => true, 'analytics' => true]);

        foreach (['balance', 'profit_loss'] as $part) {
            foreach (['czk', 'thousands'] as $unit) {
                $xlsx = $this->xlsx->accountView($view, $part, $unit);
                self::assertStringStartsWith('PK', $xlsx['bytes']);
                $pdf = $this->pdf->render($view + ['part' => $part, 'unit' => $unit]);
                self::assertStringStartsWith('%PDF', $pdf);
            }
        }
    }

    /**
     * Výnos 602 1000, náklady 518 500 + 551 300 + 559 100 (provozní), úroky 562 200
     * a výnosové úroky 662 50 (finanční), daň 591 30.
     */
    private function seedScenario(): void
    {
        $this->manual([self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-03-01');
        $this->manual([self::l('518', 'debit', 500.00), self::l('321', 'credit', 500.00)], self::YEAR . '-03-05');
        $this->manual([self::l('551', 'debit', 300.00), self::l('081', 'credit', 300.00)], self::YEAR . '-06-30');
        $this->manual([self::l('559', 'debit', 100.00), self::l('391', 'credit', 100.00)], self::YEAR . '-06-30');
        $this->manual([self::l('562', 'debit', 200.00), self::l('321', 'credit', 200.00)], self::YEAR . '-07-31');
        $this->manual([self::l('221', 'debit', 50.00), self::l('662', 'credit', 50.00)], self::YEAR . '-08-31');
        $this->manual([self::l('591', 'debit', 30.00), self::l('341', 'credit', 30.00)], self::YEAR . '-12-31');
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
