<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Úhradu téže položky potvrdí souběžný požadavek až poté, co si druhá transakce vytvořila
 * snímek. Druhá nesmí počítat zbytek ze snímku, jinak položku přeplatí a na účtu vznikne
 * druhý zápis. Druhé spojení commituje, proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class OtherItemAllocationConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'other_item_lock';

    /** @return array<string,array{bool}> */
    public static function paths(): array
    {
        return ['párování z banky' => [true], 'úhrada z detailu položky' => [false]];
    }

    #[DataProvider('paths')]
    public function testStaleSnapshotCannotOverpayItem(bool $fromBank): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $item, $first, $second] = $this->fixture($pdo, $container);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM other_item_allocations')->fetchColumn();
            $other = $this->secondConnection();
            $other->prepare(
                "INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
                 VALUES (?, ?, ?, 1000, '2099-01-20')"
            )->execute([$supplier, $item, $first]);

            try {
                if ($fromBank) {
                    $container->get(OtherItemBankMatchService::class)->match($supplier, $second, $item, null, [], true);
                } else {
                    $container->get(OtherItemService::class)->allocate($supplier, $item,
                        ['bank_transaction_id' => $second, 'amount' => 1000.0], null);
                }
                self::fail('Položka se přeplatila, protože zbytek pocházel ze snímku transakce.');
            } catch (OtherItemException $e) {
                self::assertSame('overpayment', $e->errorCode, $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    public function testSnapshotIsolationConflictSurfacesAsAbortedTransaction(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=1');
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $item, $first, $second] = $this->fixture($pdo, $container);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM other_item_allocations')->fetchColumn();
            $this->secondConnection()->prepare(
                "INSERT INTO other_item_allocations (supplier_id, other_item_id, bank_transaction_id, amount, payment_on)
                 VALUES (?, ?, ?, 1000, '2099-01-20')"
            )->execute([$supplier, $item, $first]);

            try {
                $container->get(OtherItemService::class)->allocate($supplier, $item,
                    ['bank_transaction_id' => $second, 'amount' => 1000.0], null);
                self::fail('Položka se přeplatila i při izolaci snímku.');
            } catch (OtherItemException $e) {
                self::assertSame('overpayment', $e->errorCode);
            } catch (\PDOException $e) {
                self::assertTrue(TransactionFailure::aborted($e), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /** @return array{int,int,int,int} firma, položka, dva volné pohyby */
    private function fixture(PDO $pdo, \Psr\Container\ContainerInterface $container): array
    {
        $supplier = (int) $pdo->query("SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id LIMIT 1")->fetchColumn();
        if ($supplier === 0) self::markTestSkipped('Chybí firma v podvojném účetnictví.');
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplier);
        $periods = $container->get(AccountingPeriodRepository::class);
        if ($periods->findByYear($supplier, 2099) === null) {
            $periods->create($supplier, 2099, '2099-01-01', '2099-12-31');
        }
        $items = $container->get(OtherItemService::class);
        $draft = $items->create($supplier, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01', 'due_on' => '2099-01-20',
            'currency' => 'CZK', 'amount' => 1000, 'counter_account_code' => '518',
        ], null);
        $items->post($supplier, (int) $draft['id'], null);
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplier, 'souběh', hash('sha256', uniqid('', true)), '1000000005', '0100', '2099-01-20', 'CZK']);
        $statement = (int) $pdo->lastInsertId();
        $transaction = function () use ($pdo, $statement): int {
            $pdo->prepare(
                'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
                 VALUES (?, "2099-01-20", -1000, "CZK", "unmatched", "Syntetický partner", "Syntetická platba")'
            )->execute([$statement]);
            return (int) $pdo->lastInsertId();
        };
        $first = $transaction();
        return [$supplier, (int) $draft['id'], $first, $transaction()];
    }

    private function secondConnection(): PDO
    {
        $config = self::sandboxConfig(self::SANDBOX);
        $pdo = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306)
            . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    }
}
