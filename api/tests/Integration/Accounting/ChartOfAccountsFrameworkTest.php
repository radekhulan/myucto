<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use MyInvoice\Service\Accounting\Codebooks\ChartOfAccountsImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChartOfAccountsFrameworkTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private ChartOfAccountsSeeder $seeder;
    private ChartOfAccountsImportService $importer;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->framework('1900-01-01', 'cz_500');
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id) VALUES (?)')->execute([$this->supplierId]);
        $this->seeder = new ChartOfAccountsSeeder($this->db);
        $this->importer = new ChartOfAccountsImportService(new ChartOfAccountsRepository($this->db), $this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSeederUsesFrameworkAtExplicitBoundary(): void
    {
        $this->framework('2098-01-01', 'cz_504');
        $this->seeder->seedForSupplier($this->supplierId, '2098-01-01');
        $rows = $this->accounts();
        self::assertArrayHasKey('933', $rows);
        self::assertSame('equity', $rows['933']['account_type']);
        self::assertSame('equity', $rows['911']['account_type']);
        self::assertSame('closing', $rows['961']['account_type']);
        self::assertSame('closing', $rows['962']['account_type']);
        foreach (['411', '431', '701', '702', '710', '963', '568', '663'] as $code) {
            self::assertArrayNotHasKey($code, $rows);
        }
        self::assertSame(0, $this->seeder->seedForSupplier($this->supplierId, '2098-01-01'));
    }

    public function testDefaultDateUsesCurrentFrameworkAndDoesNotSeed500Rules(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        $this->seeder->seedForSupplier($this->supplierId);
        self::assertArrayHasKey('549.100', $this->accounts());
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM posting_rules WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        $stmt = $this->db->pdo()->prepare('SELECT fuel_account_code, vehicle_repair_account_code FROM accounting_supplier_settings WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(['fuel_account_code' => null, 'vehicle_repair_account_code' => null], $stmt->fetch());
    }

    public function test504DoesNotConvertOrFillExisting500Chart(): void
    {
        $this->seeder->seedForSupplier($this->supplierId, '2097-12-31');
        $this->db->pdo()->prepare("DELETE FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '013'")->execute([$this->supplierId]);
        $before = $this->accounts();
        self::assertArrayNotHasKey('013', $before);
        $this->framework('2098-01-01', 'cz_504');
        self::assertSame(0, $this->seeder->seedForSupplier($this->supplierId, '2098-01-01'));
        self::assertSame($before, $this->accounts());
    }

    public function test500ExactAccountParityBeforeFuture504Boundary(): void
    {
        $this->framework('2098-01-01', 'cz_504');
        self::assertSame(count(ChartOfAccountsTemplate::ACCOUNTS), $this->seeder->seedForSupplier($this->supplierId, '2097-12-31'));
        $actual = $this->accounts();
        self::assertCount(count(ChartOfAccountsTemplate::ACCOUNTS), $actual);
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $account) {
            $row = $actual[$account['code']];
            self::assertSame($account['name'], $row['name']);
            self::assertSame($account['type'], $row['account_type']);
            self::assertSame($account['normal_side'], $row['normal_side']);
            self::assertSame($account['parent_code'] ?? null, $row['parent_code']);
            self::assertSame(ChartOfAccountsTemplate::taxDeductibility($account['code']), $row['tax_deductibility']);
        }
        self::assertSame(0, $this->seeder->seedForSupplier($this->supplierId, '2097-12-31'));
    }

    public function test504ExpenseDefaultsNeverClaimConfirmedDeductibility(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        $this->seeder->seedForSupplier($this->supplierId);
        foreach ($this->accounts() as $row) {
            if ($row['account_type'] === 'expense') {
                self::assertSame('non_deductible', $row['tax_deductibility'], $row['account_code']);
            }
        }
    }

    public function test504DoesNotFillCustomPartialChart(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        self::assertTrue($this->csv('901;Vlastní název;equity;credit;')['ok']);
        $before = $this->accounts();
        self::assertSame(0, $this->seeder->seedForSupplier($this->supplierId));
        self::assertSame($before, $this->accounts());
    }

    public function test504SeededChartRoundTripKeepsEveryAccountAndParent(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        $this->seeder->seedForSupplier($this->supplierId);
        $before = $this->accounts();
        $csv = [];
        foreach ($before as $row) {
            $csv[] = implode(';', [$row['account_code'], $row['name'], $row['account_type'], $row['normal_side'] ?? '', $row['parent_code'] ?? '']);
        }
        $result = $this->csv(implode("\n", $csv));
        self::assertTrue($result['ok'], json_encode($result['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame(count($before), $result['skipped']);
        self::assertSame($before, $this->accounts());
    }

    public function test504ImportPreservesExistingAccountTaxAssessmentAndNameUpdate(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        self::assertTrue($this->csv('544;Úroky;expense;;')['ok']);
        self::assertSame('debit', $this->accounts()['544']['normal_side']);
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET tax_deductibility = 'deductible' WHERE supplier_id = ? AND account_code = '544'")->execute([$this->supplierId]);
        self::assertTrue($this->csv('544;Vlastní název;;;')['ok']);
        self::assertSame('Vlastní název', $this->accounts()['544']['name']);
        self::assertSame('deductible', $this->accounts()['544']['tax_deductibility']);
    }

    #[DataProvider('invalid504Rows')]
    public function test504ImportRejectsIncompatibleRolesAndGroups(string $row): void
    {
        $this->framework('2000-01-01', 'cz_504');
        foreach ([true, false] as $dryRun) {
            $result = $this->csv($row, $dryRun);
            self::assertFalse($result['ok']);
            self::assertSame(1, $result['failed']);
            self::assertSame([], $this->accounts());
        }
    }

    public static function invalid504Rows(): array
    {
        return [
            '500 equity' => ['411;Kapitál;equity;credit;'],
            '500 closing' => ['701;Otevření;closing;;'],
            '500 financial revenue' => ['663;Kurs;revenue;credit;'],
            'unknown group' => ['151;Záloha;asset;debit;'],
            'wrong funds role' => ['911;Fond;asset;debit;'],
            'wrong closing role' => ['961;Otevření;equity;credit;'],
            'wrong expense side' => ['544;Úrok;expense;credit;'],
            'analytic in wrong class' => ["901;Jmění;equity;credit;\n411.100;Analytika;;;901"],
        ];
    }

    public function test504AnalyticCannotEscapeParentSynthetic(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        $result = $this->csv("549;Náklady;expense;debit;\n544.100;Úrok;expense;debit;549");
        self::assertFalse($result['ok']);
        self::assertSame([], $this->accounts());
    }

    public function test504ImportSupportsCustomSyntheticsFreeClassesAndInheritedAnalytics(): void
    {
        $this->framework('2000-01-01', 'cz_504');
        $result = $this->csv("547;Vlastní ostatní náklady;expense;debit;\n547.100;Analytika;;;547\n471;Vnitřní účet;offbalance;;\n799;Vnitřní účet;offbalance;;\n899;Vnitřní účet;offbalance;;\n999;Vyrovnávací účet;offbalance;;");
        self::assertTrue($result['ok']);
        self::assertSame(6, $result['created']);
        $rows = $this->accounts();
        self::assertSame('547', $rows['547.100']['parent_code']);
        self::assertSame('expense', $rows['547.100']['account_type']);
        self::assertSame('non_deductible', $rows['547.100']['tax_deductibility']);
    }

    public function testImportDateUsesHistoryAndResetsBetweenCalls(): void
    {
        $this->framework('2098-01-01', 'cz_504');
        $row = '411;Kapitál;equity;credit;';
        self::assertFalse($this->csv($row, true, '2098-01-01')['ok']);
        self::assertTrue($this->csv($row, true, '2097-12-31')['ok']);
        self::assertTrue($this->csv($row, true)['ok']);
    }

    private function framework(string $date, string $framework): void
    {
        $this->db->pdo()->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, $date, $framework]);
    }

    private function csv(string $rows, bool $dryRun = false, ?string $date = null): array
    {
        return $this->importer->import($this->supplierId, null, "ucet;nazev;typ;strana;nadrizeny_ucet\n" . $rows, 'chart.csv', $dryRun, $date);
    }

    private function accounts(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT a.account_code, a.name, a.account_type, a.normal_side, a.tax_deductibility, p.account_code AS parent_code FROM chart_of_accounts a LEFT JOIN chart_of_accounts p ON p.id = a.parent_id WHERE a.supplier_id = ? ORDER BY a.account_code');
        $stmt->execute([$this->supplierId]);
        return array_column($stmt->fetchAll(), null, 'account_code');
    }
}
