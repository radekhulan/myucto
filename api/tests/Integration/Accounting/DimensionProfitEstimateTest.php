<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\YearEndTaxEstimateService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výsledovka po dimenzi s odhadem do konce roku: zaúčtované zápisy po dni sestavy,
 * nezaúčtované časové rozlišení podle dimenzí zdrojového řádku dokladu a odpisy podle
 * dimenzí karty majetku. Součet sestavy = odhad VH před zdaněním firmy, bez přepínače
 * je výstup sestavy beze změny a zaúčtovaný odpis leží na stejné hodnotě jako jeho odhad.
 */
#[Group('integration')]
final class DimensionProfitEstimateTest extends TestCase
{
    private const YEAR = 2047;
    private const TO = '2047-11-27';

    private Connection $db;
    private DimensionProfitService $profit;
    private YearEndTaxEstimateService $estimates;
    private ClosingService $closing;
    private PostingService $posting;
    private AssetService $assets;
    private DepreciationPostingService $depreciation;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $typeId = 0;
    private int $valueA = 0;
    private int $valueB = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->profit = $container->get(DimensionProfitService::class);
            $this->estimates = $container->get(YearEndTaxEstimateService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->posting = $container->get(PostingService::class);
            $this->assets = $container->get(AssetService::class);
            $this->depreciation = $container->get(DepreciationPostingService::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $dimensions = $container->get(DimensionService::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, ic, dic, accounting_mode)
             VALUES (?, "Zkušební 1", "Vzorov", "10000", ?, "odhad-dimenze@example.com", ?, ?, "po", "12345678", "CZ12345678", "double_entry")'
        )->execute(['Odhad po dimenzích s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        $dimensions->setEnabled($this->supplierId, true);
        $this->typeId = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $this->valueA = (int) $dimensions->createValue($this->supplierId, $this->typeId, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $this->valueB = (int) $dimensions->createValue($this->supplierId, $this->typeId, ['code' => 'S-B', 'name' => 'Středisko B'])['id'];

        // Výnos 1 000 000 na A, náklad 300 000 bez hodnoty, výnos 5 000 na B po dni sestavy.
        $this->post(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000, $this->valueA]]);
        $this->post(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        $this->post(self::YEAR . '-12-15', [['311', 'debit', 5000], ['602', 'credit', 5000, $this->valueB]]);

        // Pojistné 36 600 na A s krytím 1. 7. 2047 – 30. 6. 2048: část 2048 je náklad příštích období.
        $vendorId = $this->vendor();
        $pi = $this->purchase($vendorId, 'PF-ODH-1', 36600.00, self::YEAR . '-07-01');
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Pojistné", 1, "ks", 36600, ?, 21.00, 36600, 0, 36600, 0, "548", ?, ?)'
        )->execute([$pi, $this->vatRateId, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30']);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$this->typeId => $this->valueA]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [1 => [$this->typeId => $this->valueA]]);

        // Stroj na středisku B s plánem účetních odpisů, odpis roku zatím nezaúčtovaný.
        $asset = $this->assets->create($this->supplierId, [
            'inventory_number' => 'ODH-DIM-1',
            'name' => 'Zkušební stroj',
            'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'put_into_use_date' => self::YEAR . '-01-10',
            'status' => 'in_use',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        (new DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'asset', (int) $asset['asset']['id'], [$this->typeId => $this->valueB], []);
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

    public function testEstimateColumnsSplitByDimensionAndSumToProfitBeforeTax(): void
    {
        $prepaid = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['total'];
        $pending = $this->depreciation->previewYear($this->supplierId, self::YEAR)['pending_accounting'];
        self::assertGreaterThan(0.0, $prepaid);
        self::assertGreaterThan(0.0, $pending);
        $estimate = $this->estimates->estimate($this->supplierId, $this->periodId);
        self::assertTrue($estimate['applicable']);

        $report = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['estimate' => true]);

        self::assertTrue($report['estimate']['applicable'], (string) $report['estimate']['reason']);
        self::assertSame('2047-11-28', $report['estimate']['posted_later_from']);
        self::assertTrue($report['estimate']['check']['matches']);
        $rows = array_column($report['rows'], null, 'value_id');
        $a = $rows[$this->valueA]['estimate']['total'];
        $b = $rows[$this->valueB]['estimate']['total'];

        self::assertSame([0.0, $prepaid, 0.0, 0.0], self::floats([$a['posted_later'], $a['accruals'], $a['depreciation'], $a['other']]),
            'Náklad příštích období leží na hodnotě zdrojového řádku faktury.');
        self::assertSame(round(1000000 - 36600 + $prepaid, 2), (float) $a['result']);
        self::assertSame([5000.0, 0.0, -$pending, 0.0], self::floats([$b['posted_later'], $b['accruals'], $b['depreciation'], $b['other']]),
            'Zápis po dni sestavy a odpis karty leží na hodnotě B.');
        self::assertSame(round(5000 - $pending, 2), (float) $b['result']);
        self::assertSame(['posted_later' => 0.0, 'accruals' => 0.0, 'depreciation' => 0.0, 'other' => 0.0, 'result' => -300000.0], self::floats($report['unassigned_estimate']));

        $sum = (int) round($report['totals_estimate']['result'] * 100);
        self::assertSame((int) round($estimate['vh_before_tax'] * 100), $sum, 'Součet sestavy = odhad VH před zdaněním firmy.');
        self::assertSame(round($report['totals']['result'] + 5000 + $prepaid - $pending, 2), (float) $report['totals_estimate']['result']);
    }

    public function testWithoutToggleReportIsUnchanged(): void
    {
        $plain = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['accounts' => true]);
        $with = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['accounts' => true, 'estimate' => true]);

        self::assertArrayNotHasKey('estimate', $plain);
        self::assertArrayNotHasKey('totals_estimate', $plain);
        self::assertTrue($with['estimate']['applicable']);
        unset($with['estimate'], $with['unassigned_estimate'], $with['totals_estimate']);
        foreach ($with['rows'] as $i => $row) {
            unset($with['rows'][$i]['estimate']);
        }
        self::assertSame(json_encode($plain), json_encode($with), 'Bez přepínače je sestava beze změny.');
    }

    public function testPostedDepreciationLandsOnSameValueAsItsEstimate(): void
    {
        $before = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['estimate' => true]);
        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $after = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['estimate' => true]);

        $b0 = array_column($before['rows'], null, 'value_id')[$this->valueB]['estimate']['total'];
        $b1 = array_column($after['rows'], null, 'value_id')[$this->valueB]['estimate']['total'];
        self::assertSame(0.0, (float) $b1['depreciation'], 'Zaúčtovaný odpis už není odhadem.');
        self::assertSame(round($b0['posted_later'] + $b0['depreciation'], 2), (float) $b1['posted_later'], 'Zaúčtovaný odpis leží na stejné hodnotě jako jeho odhad.');
        self::assertSame((float) $b0['result'], (float) $b1['result']);
        self::assertSame((float) $before['totals_estimate']['result'], (float) $after['totals_estimate']['result']);
        self::assertTrue($after['estimate']['check']['matches']);
    }

    public function testEstimateNeedsRangeFromPeriodStartAndSingleCompany(): void
    {
        $report = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-02-01', self::TO, [$this->supplierId], ['estimate' => true]);
        self::assertSame(['applicable' => false, 'reason' => 'range_not_period'], array_intersect_key($report['estimate'], ['applicable' => 1, 'reason' => 1]));
        self::assertArrayNotHasKey('estimate', $report['rows'][0]);

        $report = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId, $this->supplierId + 1], ['estimate' => true]);
        self::assertSame('multiple_companies', $report['estimate']['reason']);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $report = $this->profit->build($this->supplierId, $this->typeId, self::YEAR . '-01-01', self::TO, [$this->supplierId], ['estimate' => true]);
        self::assertSame('period_closed', $report['estimate']['reason']);
    }

    /**
     * @param array<array-key,int|float> $values
     * @return array<array-key,float>
     */
    private static function floats(array $values): array
    {
        return array_map(static fn (int|float $v): float => (float) $v, $values);
    }

    /** @param list<array{0:string,1:string,2:float|int,3?:int}> $lines účet, strana, částka, hodnota střediska */
    private function post(string $date, array $lines): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, array_map(fn (array $l): array => [
            'account_code' => $l[0], 'side' => $l[1], 'amount' => (float) $l[2],
        ] + (isset($l[3]) ? ['dimensions' => [$this->typeId => $l[3]]] : []), $lines), [
            'entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
    }

    private function vendor(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Pojišťovna a.s.", "Ulice 1", "Praha", "11000", ?, "CZ12345679", ?, "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, 'odhad-dim' . uniqid() . '@example.com', $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(int $vendorId, string $number, float $base, string $issue): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $base, $base, $this->userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
