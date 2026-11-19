<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\Report\VatCrossCheckService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola, která se nepodařila spočítat, se nesmí hlásit jako splněná. Neplátce DPH
 * kontroly DPH vůbec nepouští (netýkají se ho), u plátce je výjimka nesplněná kontrola
 * s důvodem `check_failed`.
 */
#[Group('integration')]
final class ClosingChecksFailureTest extends TestCase
{
    private const YEAR = 2094;

    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private bool $inTx = false;
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $vatClearing = $this->createStub(VatClearingService::class);
            $vatClearing->method('staleForRange')->willThrowException(new \RuntimeException('zúčtování nedostupné'));
            $crossCheck = $this->createStub(VatCrossCheckService::class);
            $crossCheck->method('checkAccountBalanceVsReturn')->willThrowException(new \RuntimeException('přiznání nedostupné'));
            $container->set(VatClearingService::class, $vatClearing);
            $container->set(VatCrossCheckService::class, $crossCheck);
            $this->db = $container->get(Connection::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testVatPayerGetsFailedChecksInsteadOfOk(): void
    {
        $byKey = $this->checksFor(true);
        foreach (['vat_clearing_stale', 'vat_343_vs_return'] as $key) {
            self::assertArrayHasKey($key, $byKey);
            self::assertFalse($byKey[$key]['ok'], $key . ': výjimka nesmí projít jako splněná kontrola.');
            self::assertSame('warning', $byKey[$key]['severity']);
            self::assertSame('check_failed', $byKey[$key]['value']['reason'] ?? null);
        }
    }

    public function testNonPayerSkipsVatChecksAsNotApplicable(): void
    {
        $byKey = $this->checksFor(false);
        foreach (['vat_clearing_stale', 'vat_343_vs_return'] as $key) {
            self::assertTrue($byKey[$key]['ok'], $key);
            self::assertSame('info', $byKey[$key]['severity']);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function checksFor(bool $vatPayer): array
    {
        $pdo = $this->db->pdo();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, is_vat_payer, vat_period)
             VALUES ("Kontroly s.r.o.", "Testovací 1", "Praha", "11000", ?, "kontroly@example.com", ?, ?, ?, "quarterly")'
        )->execute([$czId, $currencyId, $vatRateId, $vatPayer ? 1 : 0]);
        $supplierId = (int) $pdo->lastInsertId();
        $this->seeder->seedForSupplier($supplierId);
        $periodId = $this->periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $period = $this->periods->findById($supplierId, $periodId);

        $checks = $this->closing->buildChecks($supplierId, $period, self::YEAR . '-01-01', self::YEAR . '-03-31', onlyKeys: ['vat_clearing_stale', 'vat_343_vs_return']);
        return array_column($checks, null, 'key');
    }
}
