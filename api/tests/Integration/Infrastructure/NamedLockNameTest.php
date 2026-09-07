<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Named lock v MariaDB je SERVEROVÝ, ne per-databázový.
 *
 * Jméno složené jen z identifikátoru uvnitř instance (`supplier_id`) proto
 * kolinduje mezi instalacemi na sdíleném serveru — u SaaS hostingu i mezi
 * workery `bin/test-parallel.php`, kde má každý vlastní databázi na témže
 * serveru a `supplier_id` se opakují.
 *
 * Test kolizi POUŽÍVÁ jako důkaz: nejdřív ukáže, že nescopované jméno druhé
 * spojení skutečně zablokuje, a teprve pak že scopované ne. Bez první poloviny
 * by druhá netvrdila nic.
 */
#[Group('integration')]
final class NamedLockNameTest extends TestCase
{
    private Connection $db;
    private PDO $holder;

    protected function setUp(): void
    {
        // api/tests/Integration/Infrastructure -> kořen repozitáře jsou ČTYŘI úrovně.
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            // Druhá, nezávislá DB session na TÝŽ server — se sdíleným spojením
            // by si zámek vzalo totéž sezení a test by neměřil vůbec nic.
            $config = $container->get(Config::class);
            $this->holder = Connection::withoutSharedTestConnection(
                static fn (): Connection => new Connection($config),
            )->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->holder)) {
            $this->holder->query('SELECT RELEASE_ALL_LOCKS()');
        }
        if (isset($this->db)) {
            $this->db->pdo()->query('SELECT RELEASE_ALL_LOCKS()');
        }
    }

    public function testUnscopedNameCollidesAcrossInstancesButScopedDoesNot(): void
    {
        $db = $this->db;
        $pdo = $db->pdo();

        // Jak zámek vypadal před opravou: jen scope + supplier_id.
        $unscoped = 'automation_recommendations:1';
        self::assertSame(1, $this->getLock($this->holder, $unscoped), 'Druhá instance musí zámek nejdřív držet.');
        self::assertSame(
            0,
            $this->getLock($pdo, $unscoped),
            'Falzifikace: nescopované jméno se mezi instalacemi na sdíleném serveru MUSÍ srazit.',
        );

        // Po opravě: jméno nese otisk databáze, takže cizí instance nepřekáží.
        $mine = NamedLockName::for($db, 'automation_recommendations', 1);
        $theirs = NamedLockName::compose('automation_recommendations', 'jina_instance_test', 1);
        self::assertNotSame($mine, $theirs);

        self::assertSame(1, $this->getLock($this->holder, $theirs), 'Cizí instance drží svůj zámek.');
        self::assertSame(1, $this->getLock($pdo, $mine), 'Naše instance ho tím nesmí mít zablokovaný.');
        $pdo->query('SELECT RELEASE_ALL_LOCKS()');
    }

    public function testComposeIsStableAndDatabaseSpecific(): void
    {
        $a = NamedLockName::compose('scope', 'myucto_w1_test', 7);
        $b = NamedLockName::compose('scope', 'myucto_w2_test', 7);

        self::assertSame($a, NamedLockName::compose('scope', 'myucto_w1_test', 7));
        self::assertNotSame($a, $b);
        self::assertNotSame($a, NamedLockName::compose('scope', 'myucto_w1_test', 8));
        // MariaDB má na jméno zámku limit 64 znaků.
        self::assertLessThanOrEqual(64, strlen($a));
        self::assertLessThanOrEqual(64, strlen(NamedLockName::compose(str_repeat('x', 80), 'db', 9)));
    }

    public function testComposeRefusesUnknownDatabase(): void
    {
        $this->expectException(\RuntimeException::class);
        NamedLockName::compose('scope', '   ', 1);
    }

    private function getLock(PDO $pdo, string $name): int
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$name]);
        return (int) $statement->fetchColumn();
    }
}
