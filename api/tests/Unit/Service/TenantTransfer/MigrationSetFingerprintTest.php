<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\MigrationSetFingerprint;
use PHPUnit\Framework\TestCase;

final class MigrationSetFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
        $this->temporaryDirectories = [];
    }

    public function testHashIsCanonicalAcrossInputOrder(): void
    {
        $firstChecksum = hash('sha256', 'first migration');
        $secondChecksum = hash('sha256', 'second migration');
        $fingerprinter = new MigrationSetFingerprint();

        $state = $fingerprinter->fromChecksums([
            '0002_second.sql' => $secondChecksum,
            '0001_first.sql' => $firstChecksum,
        ], [
            '0002_second.sql',
            '0001_first.sql',
        ]);
        $canonical = '{"format":"myucto-migration-set","migrations":['
            . '{"filename":"0001_first.sql","sha256":"' . $firstChecksum . '"},'
            . '{"filename":"0002_second.sql","sha256":"' . $secondChecksum . '"}'
            . '],"version":1}';

        self::assertTrue($state->isReady());
        self::assertSame(['0001_first.sql', '0002_second.sql'], $state->appliedMigrations);
        self::assertSame('sha256:' . hash('sha256', $canonical), $state->hash);
    }

    public function testChangedMigrationFileChangesFingerprint(): void
    {
        $fingerprinter = new MigrationSetFingerprint();
        $first = $fingerprinter->fromChecksums(
            ['0001_init.sql' => hash('sha256', 'version one')],
            ['0001_init.sql'],
        );
        $changed = $fingerprinter->fromChecksums(
            ['0001_init.sql' => hash('sha256', 'version two')],
            ['0001_init.sql'],
        );

        self::assertNotSame($first->hash, $changed->hash);
    }

    public function testPendingAndMissingMigrationsMakeStateNotReady(): void
    {
        $state = (new MigrationSetFingerprint())->fromChecksums([
            '0001_init.sql' => hash('sha256', 'one'),
            '0002_pending.sql' => hash('sha256', 'two'),
        ], [
            '0001_init.sql',
            '0003_missing.sql',
        ]);

        self::assertFalse($state->isReady());
        self::assertNull($state->hash);
        self::assertSame(['0002_pending.sql'], $state->pendingMigrations);
        self::assertSame(['0003_missing.sql'], $state->missingMigrationFiles);
    }

    public function testDirectoryInspectionHashesFileContentsAndFindsPendingFile(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-migration-set-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;
        file_put_contents($directory . DIRECTORY_SEPARATOR . '0002_pending.sql', 'SELECT 2;');
        file_put_contents($directory . DIRECTORY_SEPARATOR . '0001_init.sql', 'SELECT 1;');

        $state = (new MigrationSetFingerprint())->inspectDirectory($directory, ['0001_init.sql']);

        self::assertFalse($state->isReady());
        self::assertNotNull($state->hash);
        self::assertSame(['0002_pending.sql'], $state->pendingMigrations);
        self::assertSame([], $state->missingMigrationFiles);
    }

    public function testCaseInsensitiveMigrationNameCollisionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MigrationSetFingerprint())->fromChecksums([
            '0001_init.sql' => hash('sha256', 'one'),
            '0001_INIT.sql' => hash('sha256', 'two'),
        ], ['0001_init.sql']);
    }
}
