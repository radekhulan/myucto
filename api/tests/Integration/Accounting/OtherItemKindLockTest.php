<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\OtherItemKindService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zamykací čtení položky (potvrzení, úhrada, storno) nesmí zamknout i řádek jejího druhu:
 * jinak se všechny operace nad položkami stejného druhu serializují a čekají i na úpravu
 * druhu. Druhé spojení vidí jen commitnutá data, proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class OtherItemKindLockTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'other_item_kind_lock';

    public function testLockingItemReadLeavesKindRowFree(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            $supplier = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
            if ($supplier === 0) self::markTestSkipped('Chybí firma.');
            $kind = $container->get(OtherItemKindService::class)->create($supplier,
                ['side' => 'payable', 'name' => 'Syntetický druh ' . bin2hex(random_bytes(4))], null);
            $item = $container->get(OtherItemService::class)->create($supplier, [
                'side' => 'payable', 'kind_id' => $kind['id'], 'title' => 'Syntetické nájemné',
                'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01', 'due_on' => '2099-01-20',
                'currency' => 'CZK', 'amount' => 1000, 'counter_account_code' => '518',
            ], null);

            $pdo->beginTransaction();
            $locked = $container->get(OtherItemRepository::class)->find($supplier, (int) $item['id'], true);
            self::assertSame($kind['name'], $locked['kind_name']);

            $other = $this->secondConnection();
            // Zamčený řádek druhu skončí po sekundě chybou 1205 místo výchozích 50 s.
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $other->beginTransaction();
            $other->prepare('SELECT id FROM other_item_kinds WHERE id = ? FOR UPDATE')->execute([(int) $kind['id']]);
            $other->rollBack();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    private function secondConnection(): PDO
    {
        $config = self::sandboxConfig(self::SANDBOX);
        return new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306)
            . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
