<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetFingerprint;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetStateProvider;
use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetUnavailable;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationSetStateProviderTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory === null) {
            return;
        }
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->temporaryDirectory);
        $this->temporaryDirectory = null;
    }

    public function testReadsAppliedMigrationsWithoutWritingDatabase(): void
    {
        $directory = $this->migrationDirectory([
            '0001_init.sql' => 'SELECT 1;',
            '0002_pending.sql' => 'SELECT 2;',
        ]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE migrations (filename VARCHAR(190) PRIMARY KEY)');
        $pdo->exec("INSERT INTO migrations (filename) VALUES ('0001_init.sql')");

        $state = (new MigrationSetStateProvider(
            $this->connection($pdo),
            new MigrationSetFingerprint(),
            $directory,
        ))->current();

        self::assertSame(['0001_init.sql'], $state->appliedMigrations);
        self::assertSame(['0002_pending.sql'], $state->pendingMigrations);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testMissingMigrationsTableFailsClosed(): void
    {
        $this->expectException(MigrationSetUnavailable::class);

        (new MigrationSetStateProvider(
            $this->connection(new PDO('sqlite::memory:'), false),
            new MigrationSetFingerprint(),
            $this->migrationDirectory([]),
        ))->current();
    }

    /** @param array<string,string> $files */
    private function migrationDirectory(array $files): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-migration-provider-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectory = $directory;
        foreach ($files as $filename => $contents) {
            file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $contents);
        }
        return $directory;
    }

    private function connection(PDO $pdo, bool $hasTable = true): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('hasTable')->willReturn($hasTable);
        $connection->method('pdo')->willReturn($pdo);
        return $connection;
    }
}
