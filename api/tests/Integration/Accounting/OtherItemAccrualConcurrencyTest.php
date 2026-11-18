<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Souběh změny období rozlišení ostatní pohledávky (závazku) s krokem uzávěrky: krok potvrzený jiným
 * spojením až po začátku transakce změny musí pojistka vidět (zamykací čtení po zámku
 * období), jinak by změna prošla a rozpuštění by nesedělo s odkladem.
 */
#[Group('integration')]
final class OtherItemAccrualConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'other_item_accrual_lock';

    public function testDeferralStepCommittedByOtherConnectionBlocksPeriodChange(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            // Bez izolace snímku: pojistku musí nést zamykací čtení, ne konflikt snímku.
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $periodId, $item] = $this->fixture($pdo, $container);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            $this->secondConnection()->prepare(
                "INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
                 VALUES (?, ?, '2099-12-31', 'Syntetický odklad', 'deferred_revenue_accrual', ?, NOW())"
            )->execute([$supplier, $periodId, ClosingSourceId::deferredRevenueAccrual($periodId)]);

            try {
                $container->get(OtherItemService::class)->updateAccrual($supplier, $item, ['accrual_to' => '2100-12-31'], null);
                self::fail('Změna období prošla přes krok odkladu potvrzený souběžně.');
            } catch (OtherItemException $e) {
                self::assertSame('accrual_deferral_posted', $e->errorCode);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /**
     * Krok uzávěrky drží zámek období (lockPeriod) a počítá odklad: změna období pohledávky
     * i závazku z téhož roku musí na zámek čekat, ne projít mezi výpočtem a zápisem kroku.
     *
     * @param 'receivable'|'payable' $side
     */
    #[DataProvider('sideProvider')]
    public function testPeriodChangeWaitsForLockedAccountingPeriod(string $side): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        $other = null;
        try {
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $periodId, $item] = $this->fixture($pdo, $container, $side);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

            $other = $this->secondConnection();
            $other->beginTransaction();
            $other->prepare('SELECT id FROM accounting_periods WHERE id = ? FOR UPDATE')->execute([$periodId]);

            try {
                $container->get(OtherItemService::class)->updateAccrual($supplier, $item, ['accrual_to' => '2100-12-31'], null);
                self::fail('Změna období prošla přes zámek období drženého krokem uzávěrky.');
            } catch (\PDOException $e) {
                self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            }
        } finally {
            if ($other !== null && $other->inTransaction()) $other->rollBack();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /**
     * Ostatní závazek (381): odklad nákladů potvrzený jiným spojením po začátku transakce
     * změny pojistka vidí; odklad výnosů (384) téhož období změnu závazku neblokuje.
     */
    public function testPrepaidDeferralCommittedByOtherConnectionBlocksPayablePeriodChange(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $periodId, $item] = $this->fixture($pdo, $container, 'payable');
            $insert = "INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
                       VALUES (?, ?, '2099-12-31', 'Syntetický odklad', ?, ?, NOW())";
            $other = $this->secondConnection();
            $other->prepare($insert)->execute([$supplier, $periodId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($periodId)]);
            $items = $container->get(OtherItemService::class);
            $items->updateAccrual($supplier, $item, ['accrual_to' => '2100-09-30'], null);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            $other->prepare($insert)->execute([$supplier, $periodId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($periodId)]);

            try {
                $items->updateAccrual($supplier, $item, ['accrual_to' => '2100-12-31'], null);
                self::fail('Změna období závazku prošla přes odklad nákladů potvrzený souběžně.');
            } catch (OtherItemException $e) {
                self::assertSame('accrual_deferral_posted', $e->errorCode);
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /** @return array<string,array{string}> */
    public static function sideProvider(): array
    {
        return ['pohledávka' => ['receivable'], 'závazek' => ['payable']];
    }

    /**
     * @param 'receivable'|'payable' $side
     * @return array{int,int,int} firma, období, zaúčtovaná pohledávka (závazek) s obdobím
     */
    private function fixture(PDO $pdo, \Psr\Container\ContainerInterface $container, string $side = 'receivable'): array
    {
        $supplier = (int) $pdo->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id LIMIT 1")->fetchColumn();
        if ($supplier === 0) self::fail('Sandbox nemá firmu v podvojném účetnictví (ci-seed), test souběhu by nic neověřil.');
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplier);
        $periods = $container->get(AccountingPeriodRepository::class);
        $period = $periods->findByYear($supplier, 2099);
        $periodId = $period === null ? $periods->create($supplier, 2099, '2099-01-01', '2099-12-31') : (int) $period['id'];
        $pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$periodId]);
        $pdo->prepare("DELETE FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'")
            ->execute([$supplier, $periodId]);
        $pdo->prepare("DELETE FROM journal_entries WHERE supplier_id = ? AND source_type = 'deferred_revenue_accrual' AND source_id = ?")
            ->execute([$supplier, ClosingSourceId::deferredRevenueAccrual($periodId)]);
        $pdo->prepare("DELETE FROM journal_entries WHERE supplier_id = ? AND source_type = 'prepaid_expense_accrual' AND source_id = ?")
            ->execute([$supplier, ClosingSourceId::prepaidExpenseAccrual($periodId)]);
        $items = $container->get(OtherItemService::class);
        $draft = $items->create($supplier, [
            ...($side === 'payable'
                ? ['side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné', 'counter_account_code' => '518']
                : ['side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický nájem', 'counter_account_code' => '602']),
            'issued_on' => '2099-07-01', 'accounting_on' => '2099-07-01', 'due_on' => '2099-07-15',
            'currency' => 'CZK', 'amount' => 36500,
            'accrual_from' => '2099-07-01', 'accrual_to' => '2100-06-30',
        ], null);
        $items->post($supplier, (int) $draft['id'], null);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$periodId]);
        return [$supplier, $periodId, (int) $draft['id']];
    }

    private function secondConnection(): PDO
    {
        $config = self::sandboxConfig(self::SANDBOX);
        return new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306)
            . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
