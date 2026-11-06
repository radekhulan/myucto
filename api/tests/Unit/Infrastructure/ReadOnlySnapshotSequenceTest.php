<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure;

use MyInvoice\Infrastructure\Database\ReadOnlySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

/** Pořadí příkazů snímku (vzor PropertySaldoSnapshotTest): otevření, čtení, potvrzení nebo vrácení. */
final class ReadOnlySnapshotSequenceTest extends TestCase
{
    public function testOwnedSnapshotPinsIsolationAndReadOnlyAndCommits(): void
    {
        $pdo = new SnapshotRecordingPdo(false);
        self::assertSame('výsledek', ReadOnlySnapshot::run($pdo, function () use ($pdo): string {
            $pdo->calls[] = 'READ';
            return 'výsledek';
        }));
        self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'START TRANSACTION READ ONLY', 'READ', 'COMMIT'], $pdo->calls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testOwnedFailureRollsBackAndRethrows(): void
    {
        $pdo = new SnapshotRecordingPdo(false);
        try {
            ReadOnlySnapshot::run($pdo, static function (): never {
                throw new \RuntimeException('Syntetické selhání čtení');
            });
            self::fail('Chyba čtení se ztratila.');
        } catch (\RuntimeException $e) {
            self::assertSame('Syntetické selhání čtení', $e->getMessage());
        }
        self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'START TRANSACTION READ ONLY', 'ROLLBACK'], $pdo->calls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testFailedRollbackDoesNotMaskReadFailure(): void
    {
        $pdo = new SnapshotRecordingPdo(false, rollbackFails: true);
        try {
            ReadOnlySnapshot::run($pdo, static function (): never {
                throw new \RuntimeException('Syntetické selhání čtení');
            });
            self::fail('Chyba čtení se ztratila.');
        } catch (\RuntimeException $e) {
            self::assertSame('Syntetické selhání čtení', $e->getMessage());
        }
        self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'START TRANSACTION READ ONLY', 'ROLLBACK'], $pdo->calls);
    }

    public function testCallerTransactionIsLeftToTheCaller(): void
    {
        $pdo = new SnapshotRecordingPdo(true);
        ReadOnlySnapshot::run($pdo, function () use ($pdo): void {
            $pdo->calls[] = 'READ';
        });
        self::assertSame(['READ'], $pdo->calls);
        self::assertTrue($pdo->inTransaction());
    }
}

final class SnapshotRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private bool $active, private readonly bool $rollbackFails = false)
    {
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function exec(string $statement): int|false
    {
        $this->calls[] = $statement;
        if (str_starts_with($statement, 'START TRANSACTION')) $this->active = true;
        return 0;
    }

    public function commit(): bool
    {
        $this->calls[] = 'COMMIT';
        $this->active = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'ROLLBACK';
        if ($this->rollbackFails) {
            throw new \PDOException('Syntetické selhání rollbacku');
        }
        $this->active = false;
        return true;
    }
}
