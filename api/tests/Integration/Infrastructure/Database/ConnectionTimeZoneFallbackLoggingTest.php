<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure\Database;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

#[Group('integration')]
final class ConnectionTimeZoneFallbackLoggingTest extends TestCase
{
    public function testUnsupportedNamedTimeZoneFallsBackWithoutLoggingHandledDbError(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }

        $loaded = Config::load($rootDir);
        $data = $loaded->all();
        $data['app']['timezone'] = 'Test/Unsupported_Timezone';
        $config = new Config($data, $loaded->dataDir());

        $support = new ReflectionProperty(Connection::class, 'namedTimeZoneSupported');
        $support->setValue(null, null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $connection = Connection::withoutSharedTestConnection(
            static fn (): Connection => new Connection($config, $logger),
        );
        try {
            $pdo = $connection->pdo();
            self::assertMatchesRegularExpression(
                '/^[+-]\d{2}:\d{2}$/',
                (string) $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn(),
            );
        } finally {
            $connection->close();
            $support->setValue(null, null);
        }
    }
}
