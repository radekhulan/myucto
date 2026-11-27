<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Průběžné časové rozlišení a odpisy ve výkazu k datu, když deník (typicky převzatý
 * z jiného programu) nese rozpuštění 381/384 a odpisy s datem PO dni výkazu: k tomu dni
 * v deníku nejsou, výkaz s volbou z nich ukáže uplynulou část (roční rozpuštění poměrem
 * dnů, odpis za uplynulé celé měsíce), měsíční zápis rozpracovaného měsíce ne. Bez volby
 * a ke konci období beze změny; rozvaha i pohled po účtech bilancují se stejným VH.
 * Izolovaný supplier v transakci s rollbackem, syntetická data.
 */
#[Group('integration')]
final class FinancialStatementLaterDatedSpreadTest extends TestCase
{
    private const YEAR = 2093;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';
    private const AS_OF = self::YEAR . '-11-27';

    private Connection $db;
    private FinancialStatementService $statements;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->posting    = $container->get(PostingService::class);
            $periods          = $container->get(AccountingPeriodRepository::class);
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

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Pozdější zápisy test s.r.o.', $czId, 'later-dated-spread@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        // Zůstatky časového rozlišení a majetku převzaté na začátku roku.
        $this->post(self::STARTS_ON, '221', '384', 400000.00, 'PS-1');
        $this->post(self::STARTS_ON, '381', '221', 12000.00, 'PS-2');
        $this->post(self::STARTS_ON, '022', '221', 120000.00, 'PS-3');
        // Výnos během roku.
        $this->post(self::YEAR . '-03-10', '311', '602', 100000.00, 'FV-1');
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
     * Roční rozpuštění 384 i 381 zaúčtované k 31. 12. se k 27. 11. započte poměrem dnů
     * (331/365), měsíční řada rozpuštění jen do uzavřeného měsíce (listopad ne).
     */
    public function testYearlyReleasePostedAtYearEndIsSpreadByDays(): void
    {
        $this->post(self::ENDS_ON, '384', '602', 36500.00, 'CR-ROK');
        $this->post(self::ENDS_ON, '548', '381', 12000.00, 'CR-NAKL');
        foreach (['-10-31', '-11-30', '-12-31'] as $day) {
            $this->post(self::YEAR . $day, '384', '602', 1000.00, 'CR-MES');
        }

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);

        $revenue = AccrualScheduleCalculator::cumRecognized(36500.00, self::STARTS_ON, self::ENDS_ON, self::AS_OF);
        $expense = AccrualScheduleCalculator::cumRecognized(12000.00, self::STARTS_ON, self::ENDS_ON, self::AS_OF);
        self::assertSame(33100.0, $revenue);
        self::assertEqualsWithDelta(101000.00, $this->accountAmount($plain['rows'], '602'), 0.001, 'Bez volby jen zápisy do dne výkazu.');
        self::assertEqualsWithDelta(101000.00 + $revenue, $this->accountAmount($spread['rows'], '602'), 0.001);
        self::assertEqualsWithDelta($expense, $this->accountAmount($spread['rows'], '548'), 0.001);
        self::assertEqualsWithDelta($revenue, $spread['accrual_spread']['later_dated']['deferred_revenue'], 0.001);
        self::assertEqualsWithDelta($expense, $spread['accrual_spread']['later_dated']['prepaid_expense'], 0.001);
        self::assertSame(2, $spread['accrual_spread']['later_dated']['entries']);
        self::assertEqualsWithDelta($plain['checks']['profit_current'] + $revenue - $expense, $spread['checks']['profit_current'], 0.001);
        self::assertEqualsWithDelta($revenue - $expense, $spread['accrual_spread']['vh_effect'], 0.001);
        self::assertTrue($spread['checks']['profit_matches']);

        $bsPlain = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);
        self::assertTrue($bs['checks']['balanced'], 'Rozvaha s průběžným rozlišením musí bilancovat.');
        self::assertTrue($bs['checks']['profit_matches']);
        self::assertEqualsWithDelta($bsPlain['checks']['assets_net'] - $expense, $bs['checks']['assets_net'], 0.001, '381 klesne o rozpuštěný náklad.');

        $yearEnd = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full', null, true);
        self::assertSame(0, $yearEnd['accrual_spread']['later_dated']['entries'], 'Ke konci období nese vše deník.');
        self::assertEqualsWithDelta(
            $this->statements->incomeStatement($this->supplierId, $this->periodId, self::ENDS_ON, 'full')['checks']['profit_current'],
            $yearEnd['checks']['profit_current'], 0.001,
        );
    }

    /**
     * Převzaté odpisy: měsíční zápisy až do 31. 12. se k 27. 11. neupravují (do října je
     * deník nese, listopad není uplynulý celý měsíc). Roční odpis zaúčtovaný k 31. 12. se
     * započte za deset uplynulých měsíců.
     */
    public function testLaterDatedDepreciationCountsElapsedMonths(): void
    {
        for ($m = 1; $m <= 12; $m++) {
            $this->post(date('Y-m-t', (int) strtotime(sprintf('%d-%02d-01', self::YEAR, $m))), '551', '082', 1000.00, 'DM-MES');
        }
        $this->post(self::ENDS_ON, '551', '082', 12000.00, 'DM-ROK');

        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);

        self::assertEqualsWithDelta(10000.00, $this->accountAmount($plain['rows'], '551'), 0.001, 'Deník k 27. 11. nese odpisy do října.');
        self::assertEqualsWithDelta(20000.00, $this->accountAmount($spread['rows'], '551'), 0.001, 'Roční odpis za leden až říjen.');
        self::assertEqualsWithDelta(10000.00, $spread['accrual_spread']['later_dated']['depreciation'], 0.001);
        self::assertSame(1, $spread['accrual_spread']['later_dated']['entries']);

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);
        self::assertTrue($bs['checks']['balanced']);
    }

    /** Zápis s jinými účty (DPH, závazek) ani storno se neupravuje; bez volby beze změny. */
    public function testOtherLaterDatedEntriesAreNotAdjusted(): void
    {
        $this->post(self::ENDS_ON, '518', '321', 5000.00, 'FP-1');
        $this->post(self::ENDS_ON, '384', '321', 5000.00, 'CR-JINE');

        $spread = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);
        $plain = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        self::assertSame(0, $spread['accrual_spread']['later_dated']['entries']);
        self::assertEqualsWithDelta($plain['checks']['profit_current'], $spread['checks']['profit_current'], 0.001);
        self::assertArrayNotHasKey('accrual_spread', $plain);
    }

    /**
     * Pohled po účtech (záložky Účet 710 a Účet 702) s volbou: tytéž úpravy přímo v obratech
     * a konečných stavech účtů včetně analytik, označené v buňce `spread`; VH je stejný jako
     * ve výkazu s volbou a rozvaha po účtech bilancuje. Bez volby beze změny.
     */
    public function testAccountViewShowsSameAdjustmentsOnAccounts(): void
    {
        $this->post(self::ENDS_ON, '384', '602', 36500.00, 'CR-ROK');
        $this->post(self::ENDS_ON, '551', '082', 12000.00, 'DM-ROK');
        $columns = ['hide_zero' => true, 'analytics' => true];

        $statement = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full', null, true);
        $plain = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF, null, $columns);
        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::AS_OF, null, $columns, true);

        self::assertArrayNotHasKey('accrual_spread', $plain);
        self::assertNull($this->cell($plain['columns'], 'profit_loss', '602')['spread'] ?? null);
        self::assertEqualsWithDelta($statement['checks']['profit_current'], $view['profit_loss']['profit'], 0.001, 'Po účtech stejný VH jako výkaz s volbou.');
        self::assertEqualsWithDelta($statement['checks']['profit_current'], $view['columns']['profit_loss']['result']['ks'], 0.001);
        self::assertEqualsWithDelta($view['columns']['profit_loss']['result']['ks'], $view['columns']['balance']['result']['ks'], 0.001, 'Rozvaha po účtech bilancuje.');
        self::assertTrue($view['checks']['profit_matches']);
        self::assertTrue($view['accrual_spread']['applied']);

        $revenue = $this->cell($view['columns'], 'profit_loss', '602');
        self::assertEqualsWithDelta(33100.00, $revenue['spread'], 0.001);
        self::assertEqualsWithDelta(100000.00 + 33100.00, $revenue['ks'], 0.001);
        self::assertEqualsWithDelta(33100.00, $revenue['month'], 0.001, 'Úprava patří k datu výkazu, tedy i do obratu jeho měsíce.');
        self::assertEqualsWithDelta(10000.00, $this->cell($view['columns'], 'profit_loss', '551')['spread'], 0.001);
        $deferred = $this->cell($view['columns'], 'balance', '384');
        self::assertEqualsWithDelta(-33100.00, $deferred['spread'], 0.001);
        self::assertEqualsWithDelta(400000.00 - 33100.00, $deferred['ks'], 0.001);
        self::assertEqualsWithDelta(-10000.00, $this->cell($view['columns'], 'balance', '082')['spread'], 0.001);
    }

    private function post(string $date, string $debit, string $credit, float $amount, string $docNo): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'document_no' => $docNo, 'description' => 'Syntetický zápis ' . $docNo, 'posted' => true,
            'user_id' => $this->userId, 'posted_by' => $this->userId]);
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

    /**
     * Syntetická buňka účtu ve sloupcovém pohledu.
     *
     * @param array<string,mixed> $columns
     * @return array<string,mixed>
     */
    private function cell(array $columns, string $part, string $code): array
    {
        foreach ($columns[$part]['lines'] as $line) {
            foreach (['left', 'right'] as $side) {
                $cell = $line[$side] ?? null;
                if (is_array($cell) && ($cell['kind'] ?? '') === 'synthetic' && $cell['account_code'] === $code) {
                    return $cell;
                }
            }
        }
        self::fail('Účet ' . $code . ' v pohledu po účtech chybí.');
    }
}
