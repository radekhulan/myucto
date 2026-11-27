<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Průběžné účetní odpisy ve výkazech mimo deník (volba „Průběžné časové rozlišení
 * a odpisy"). Odpis roku zaúčtuje až krok uzávěrky k poslednímu dni období; výkaz
 * k datu uvnitř roku s volbou ukáže odpis za uplynulé celé měsíce na 551 a oprávkách,
 * rozvaha dál bilancuje. Po zaúčtování odpisů roku se nic nepřidává. Izolovaný
 * supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class FinancialStatementDepreciationSpreadTest extends TestCase
{
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private FinancialStatementService $statements;
    private AssetService $assets;
    private DepreciationPostingService $depreciation;

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
            $this->db           = $container->get(Connection::class);
            $this->statements   = $container->get(FinancialStatementService::class);
            $this->assets       = $container->get(AssetService::class);
            $this->depreciation = $container->get(DepreciationPostingService::class);
            $posting            = $container->get(PostingService::class);
            $periods            = $container->get(AccountingPeriodRepository::class);
            $seeder             = $container->get(ChartOfAccountsSeeder::class);
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

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Průběžné odpisy test s.r.o.', $czId, 'depreciation-spread@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $posting->postDocument($this->supplierId, 'manual', null, [
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
     * 72 000 Kč na 36 měsíců zařazené 10. 3.: odpis 2 000 Kč měsíčně od dubna.
     * K 31. 10. sedm měsíců (14 000), k 15. 10. jen šest (12 000), ke konci roku devět.
     */
    public function testInterimDepreciationForElapsedMonths(): void
    {
        $this->createAssetInUse('M-PO-1', self::YEAR . '-03-10', 72000.00, 36);

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);

        self::assertSame(0.0, $this->accountAmount($plain['rows'], '551'), 'Deník odpis roku ještě nenese.');
        self::assertEqualsWithDelta(14000.00, $this->accountAmount($spread['rows'], '551'), 0.001);
        self::assertEqualsWithDelta(14000.00, $spread['accrual_spread']['depreciation'], 0.001);
        self::assertSame(1, $spread['accrual_spread']['depreciation_assets']);
        self::assertEqualsWithDelta($plain['checks']['profit_current'] - 14000.00, $spread['checks']['profit_current'], 0.001);
        self::assertTrue($spread['checks']['profit_matches']);

        $midMonth = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-15', 'full', null, true);
        self::assertEqualsWithDelta(12000.00, $midMonth['accrual_spread']['depreciation'], 0.001, 'Rozběhnutý měsíc se nezapočítá.');

        $yearEnd = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        self::assertEqualsWithDelta(18000.00, $yearEnd['accrual_spread']['depreciation'], 0.001);

        $bsPlain = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full');
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);
        self::assertTrue($bs['checks']['balanced'], 'Rozvaha s průběžnými odpisy musí bilancovat.');
        self::assertTrue($bs['checks']['profit_matches']);
        self::assertEqualsWithDelta($bsPlain['checks']['assets_net'] - 14000.00, $bs['checks']['assets_net'], 0.001, 'Oprávky sníží aktiva netto.');
    }

    /**
     * Účetní odpisy podle daňových nemají měsíční rozpis: roční odpis se rozloží rovným
     * dílem na měsíce, ve kterých karta v roce odpisuje (duben až prosinec = 9).
     */
    public function testByTaxDepreciationIsSpreadEvenlyOverMonths(): void
    {
        $this->createAssetInUse('M-PO-3', self::YEAR . '-03-10', 72000.00, 36, 'by_tax');

        $yearEnd = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        $october = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);

        $year = $yearEnd['accrual_spread']['depreciation'];
        self::assertGreaterThan(0.0, $year);
        self::assertEqualsWithDelta(round($year * 7 / 9, 2), $october['accrual_spread']['depreciation'], 0.001);
    }

    /**
     * Odpis roku zaúčtovaný k poslednímu dni období: výkaz k tomu dni ho má v deníku a volba
     * nic nepřidá. Výkaz k 31. 10. ho v deníku ještě nemá (zápis je datovaný po dni výkazu),
     * proto volba ukáže uplynulé měsíce stejně jako u nezaúčtované karty.
     */
    public function testYearBookedAfterReportDateCountsElapsedMonths(): void
    {
        $this->createAssetInUse('M-PO-2', self::YEAR . '-03-10', 72000.00, 36);
        $result = $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertSame([], $result['errors']);

        $yearEnd = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        self::assertSame(0.0, $yearEnd['accrual_spread']['depreciation'], 'Ke konci období nese odpis deník.');
        self::assertSame(0, $yearEnd['accrual_spread']['depreciation_assets']);

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);

        self::assertSame(0.0, $this->accountAmount($plain['rows'], '551'), 'Zápis k 31. 12. ve výkazu k 31. 10. není.');
        self::assertEqualsWithDelta(14000.00, $spread['accrual_spread']['depreciation'], 0.001);
        self::assertSame(1, $spread['accrual_spread']['depreciation_assets']);
        self::assertEqualsWithDelta($plain['checks']['profit_current'] - 14000.00, $spread['checks']['profit_current'], 0.001);

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-10-31', 'full', null, true);
        self::assertTrue($bs['checks']['balanced']);
    }

    private function createAssetInUse(string $inventoryNumber, string $useDate, float $price, int $lifeMonths, string $accMethod = 'straight_line'): int
    {
        $created = $this->assets->create($this->supplierId, [
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => $price,
            'acquisition_date' => $useDate,
            'tax_method' => 'straight',
            'tax_group' => 1,
            'acc_method' => $accMethod,
            'acc_useful_life_months' => $lifeMonths,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];
        $this->assets->putIntoUse($this->supplierId, $assetId, $useDate, true,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        return $assetId;
    }

    /** @param list<array<string,mixed>> $rows */
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
}
