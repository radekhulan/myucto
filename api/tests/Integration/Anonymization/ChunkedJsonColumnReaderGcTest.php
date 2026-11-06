<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChunkedJsonColumnReaderGcTest extends TestCase
{
    private GcHookPdo $pdo;
    private string $json;
    private bool $gcEnabled;

    protected function setUp(): void
    {
        $config = Bootstrap::buildContainer()->get(Config::class);
        $database = (string) $config->get('db.name');
        self::assertStringEndsWith('_test', $database);
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), $database);
        $host = (string) $config->get('db.host');
        self::assertContains($host, ['localhost', '127.0.0.1', '::1']);
        $this->pdo = new GcHookPdo(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $config->get('db.port', 3306), $database),
            (string) $config->get('db.user'), (string) $config->get('db.pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [GcHookStatement::class, [$this->pdo]]);
        self::assertStringContainsString('MariaDB', (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $this->pdo->exec('CREATE TEMPORARY TABLE nonprofit_tax_assessments (
            id BIGINT UNSIGNED PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL, UNIQUE KEY tenant_id (supplier_id,id)
        ) ENGINE=InnoDB');
        $this->json = json_encode(['rows' => [str_repeat('a', ChunkedJsonColumnReader::CHUNK_BYTES * 2)]], JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO nonprofit_tax_assessments VALUES (1,11,?)')->execute([$this->json]);
        $this->pdo->beginTransaction();
        $this->gcEnabled = gc_enabled();
        gc_disable();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->beforeExec = $this->pdo->afterExec = $this->pdo->afterMetadata = null;
            gc_collect_cycles();
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS nonprofit_tax_assessments');
        }
        if ($this->gcEnabled ?? false) gc_enable();
    }

    private function reader(): \Generator
    {
        return (new ChunkedJsonColumnReader($this->pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1);
    }

    private function abandon(\Generator $reader): void
    {
        // Stejně jako parser drží nedočtený generátor v cyklu až do příštího GC.
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $cycle->reader = $reader;
    }

    private function finish(\Generator $reader): string
    {
        $chunks = [];
        while ($reader->valid()) {
            $chunks[] = $reader->current();
            $reader->next();
        }
        return implode('', $chunks);
    }

    public function testGcAfterSavepointCreationCannotInvalidateNewReader(): void
    {
        $old = $this->reader();
        $old->rewind();
        $this->abandon($old);
        unset($old);
        $collected = 0;
        $this->pdo->afterPrefix = 'SAVEPOINT ';
        $this->pdo->afterExcept = 'SAVEPOINT ' . $this->pdo->lastSavepoint;
        $this->pdo->afterExec = static function (string $sql) use (&$collected): void {
            self::assertStringStartsWith('SAVEPOINT ', $sql);
            $collected = gc_collect_cycles();
        };
        self::assertSame($this->json, $this->finish($this->reader()));
        self::assertGreaterThan(0, $collected);
        self::assertSame([], $this->pdo->failures);
    }

    public function testGcDuringNewestReleaseDoesNotRunNestedRelease(): void
    {
        $old = $this->reader();
        $old->rewind();
        $new = $this->reader();
        $new->rewind();
        $this->abandon($old);
        unset($old);
        $collected = 0;
        $released = '';
        $this->pdo->beforeExec = static function (string $sql) use (&$collected, &$released): void {
            $released = $sql;
            $collected = gc_collect_cycles();
        };
        unset($new);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $released);
        self::assertGreaterThan(0, $collected);
        self::assertSame([], $this->pdo->failures, 'Úklid nesmí zrušit marker právě uvolňovaný vnější operací.');
        self::assertSame($this->json, $this->finish($this->reader()));
    }

    #[DataProvider('finishOrders')]
    public function testInterleavedReadersCanFinishInEitherOrder(bool $olderFirst): void
    {
        $first = $this->reader();
        $first->rewind();
        $firstChunk = $first->current();
        $second = $this->reader();
        $second->rewind();
        $secondChunk = $second->current();
        $first->next();
        $second->next();
        if ($olderFirst) {
            self::assertSame($this->json, $firstChunk . $this->finish($first));
            self::assertSame($this->json, $secondChunk . $this->finish($second));
        } else {
            self::assertSame($this->json, $secondChunk . $this->finish($second));
            self::assertSame($this->json, $firstChunk . $this->finish($first));
        }
        self::assertSame([], $this->pdo->failures);
    }

    public static function finishOrders(): iterable
    {
        yield 'older first' => [true];
        yield 'newer first' => [false];
    }

    public function testNewTransactionReaderCannotReviveOldReader(): void
    {
        $old = $this->reader();
        $old->rewind();
        $this->pdo->commit();
        $this->pdo->beginTransaction();
        self::assertSame($this->json, $this->finish($this->reader()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transakce archivního JSON byla nahrazena.');
        $old->next();
    }

    public function testFailedSavepointCreationKeepsExistingReaderAndOriginalException(): void
    {
        $old = $this->reader();
        $old->rewind();
        $failure = new \PDOException('synthetic savepoint creation failure');
        $this->pdo->beforePrefix = 'SAVEPOINT ';
        $this->pdo->beforeExcept = 'SAVEPOINT ' . $this->pdo->lastSavepoint;
        $this->pdo->beforeExec = static function (string $sql) use ($failure): void { throw $failure; };
        try {
            $this->reader()->rewind();
            self::fail('Vytvoření savepointu mělo selhat.');
        } catch (\PDOException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame($this->json, $this->finish($old));
    }

    public function testAbandonedSuffixDoesNotAccumulateBehindLiveReader(): void
    {
        $old = $this->reader();
        $old->rewind();
        for ($index = 0; $index < 20; $index++) {
            $temporary = $this->reader();
            $temporary->rewind();
            $this->abandon($temporary);
            unset($temporary);
            gc_collect_cycles();
            self::assertSame(1, $this->pdo->created - $this->pdo->released,
                'Po úklidu smí zbýt jen marker dosud živého readeru.');
        }
        self::assertSame($this->json, $this->finish($old));
        self::assertSame($this->pdo->created, $this->pdo->released);
    }

    #[DataProvider('abortedTransactions')]
    public function testCreationFailurePreservesAbortedTransactionError(int $code): void
    {
        $old = $this->reader();
        $old->rewind();
        $failure = new \PDOException('synthetic aborted transaction');
        $failure->errorInfo = ['40001', $code, 'synthetic aborted transaction'];
        $this->pdo->beforePrefix = 'SAVEPOINT ';
        $this->pdo->beforeExcept = 'SAVEPOINT ' . $this->pdo->lastSavepoint;
        $this->pdo->beforeExec = function (string $sql) use ($failure): void {
            $this->pdo->rollBack();
            throw $failure;
        };
        try {
            $this->reader()->rewind();
            self::fail('Odvolaná transakce nesmí projít.');
        } catch (\PDOException $caught) {
            self::assertSame($failure, $caught);
        }
        $this->pdo->beginTransaction();
        self::assertSame($this->json, $this->finish($this->reader()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transakce archivního JSON byla nahrazena.');
        $old->next();
    }

    #[DataProvider('abortedTransactions')]
    public function testAdvancePreservesAbortedTransactionError(int $code): void
    {
        $reader = $this->reader();
        $reader->rewind();
        $failure = new \PDOException('synthetic aborted transaction');
        $failure->errorInfo = ['40001', $code, 'synthetic aborted transaction'];
        $this->pdo->beforeExec = function (string $sql) use ($failure): void {
            $this->pdo->rollBack();
            throw $failure;
        };
        try {
            $reader->next();
            self::fail('Odvolaná transakce nesmí projít.');
        } catch (\PDOException $caught) {
            self::assertSame($failure, $caught, 'Vlastník transakce musí poznat původní chybu 1020/1213.');
        }
        self::assertFalse($this->pdo->inTransaction());
    }

    public static function abortedTransactions(): iterable
    {
        yield 'deadlock' => [1213];
        yield 'snapshot conflict' => [1020];
    }

    #[DataProvider('finishTransactionChanges')]
    public function testNormalFinishChecksTransactionAfterFinalMetadata(bool $withNewer, string $boundary): void
    {
        $reader = $this->reader();
        $reader->rewind();
        $newer = $withNewer ? $this->reader() : null;
        $newer?->rewind();
        $this->pdo->afterMetadata = function () use ($boundary): void {
            $this->pdo->rollBack();
            if ($boundary === 'replace') $this->pdo->beginTransaction();
        };
        try {
            $this->finish($reader);
            self::fail('Úspěšné dočtení musí znovu potvrdit transakci po posledních metadatech.');
        } catch (\RuntimeException $failure) {
            self::assertSame($boundary === 'replace' ? 'Transakce archivního JSON byla nahrazena.'
                : 'Transakce archivního JSON byla ukončena.', $failure->getMessage());
        } finally {
            unset($newer);
        }
    }

    public static function finishTransactionChanges(): iterable
    {
        foreach ([false, true] as $withNewer) {
            foreach (['replace', 'rollback'] as $boundary) yield ($withNewer ? 'older' : 'only') . '-' . $boundary => [$withNewer, $boundary];
        }
    }

    #[DataProvider('finishAbortCases')]
    public function testNormalFinishPreservesAbortedTransactionAfterMetadata(bool $withNewer, int $code): void
    {
        $reader = $this->reader();
        $reader->rewind();
        $newer = $withNewer ? $this->reader() : null;
        $newer?->rewind();
        $failure = new \PDOException('synthetic finish abort');
        $failure->errorInfo = ['40001', $code, 'synthetic finish abort'];
        $this->pdo->afterMetadata = function () use ($failure): void {
            $this->pdo->beforeExec = function () use ($failure): void {
                $this->pdo->rollBack();
                throw $failure;
            };
        };
        try {
            $this->finish($reader);
            self::fail('Dočtení nesmí spolknout odvolanou transakci.');
        } catch (\PDOException $caught) {
            self::assertSame($failure, $caught);
        } finally {
            $this->pdo->beforeExec = null;
            unset($newer);
        }
    }

    public static function finishAbortCases(): iterable
    {
        foreach ([false, true] as $withNewer) {
            foreach ([1213, 1020] as $code) yield ($withNewer ? 'older' : 'only') . '-' . $code => [$withNewer, $code];
        }
    }

    #[DataProvider('finalReleaseFailures')]
    public function testNormalFinishPropagatesLastReleaseFailure(int $code): void
    {
        $reader = $this->reader();
        $reader->rewind();
        $failure = new \PDOException('synthetic final release abort');
        $failure->errorInfo = ['40001', $code, 'synthetic final release abort'];
        $releases = 0;
        $this->pdo->afterMetadata = function () use ($code, $failure, &$releases): void {
            $this->pdo->beforePrefix = 'RELEASE SAVEPOINT ';
            $hook = function () use ($code, $failure, &$releases, &$hook): void {
                if (++$releases === 1) {
                    $this->pdo->beforeExec = $hook;
                    return;
                }
                $this->pdo->rollBack();
                if ($code === 1305) $this->pdo->beginTransaction();
                else throw $failure;
            };
            $this->pdo->beforeExec = $hook;
        };
        try {
            $this->finish($reader);
            self::fail('Poslední RELEASE po potvrzení tokenu musí zůstat součástí úspěšného dočtení.');
        } catch (\PDOException $caught) {
            self::assertSame(2, $releases);
            if ($code === 1305) self::assertSame(1305, (int) $caught->errorInfo[1]);
            else self::assertSame($failure, $caught);
        } finally {
            $this->pdo->beforeExec = null;
        }
    }

    public static function finalReleaseFailures(): iterable
    {
        foreach ([1305, 1213, 1020] as $code) yield (string) $code => [$code];
    }

    #[DataProvider('abortedTransactions')]
    public function testGarbageCleanupDoesNotSwallowAbortedTransaction(int $code): void
    {
        $reader = $this->reader();
        $reader->rewind();
        $failure = new \PDOException('synthetic cleanup abort');
        $failure->errorInfo = ['40001', $code, 'synthetic cleanup abort'];
        $this->pdo->beforeExec = function () use ($failure): void {
            $this->pdo->rollBack();
            throw $failure;
        };
        try {
            unset($reader);
            self::fail('Ani úklid nesmí zatajit odvolanou transakci.');
        } catch (\PDOException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testRollbackToCallerSavepointInvalidatesBothReaders(): void
    {
        $this->pdo->exec('SAVEPOINT caller');
        $readers = [$this->reader(), $this->reader()];
        foreach ($readers as $reader) $reader->rewind();
        $this->pdo->exec('ROLLBACK TO SAVEPOINT caller');
        foreach ($readers as $reader) {
            try {
                $reader->next();
                self::fail('Reader nesmí pokračovat po rollbacku před svůj marker.');
            } catch (\RuntimeException $failure) {
                self::assertSame('Transakce archivního JSON byla nahrazena.', $failure->getMessage());
            }
        }
    }
}

/** Skutečné MariaDB PDO; hooky pouze deterministicky spouštějí GC v mezikrocích. */
final class GcHookPdo extends PDO
{
    public ?\Closure $beforeExec = null;
    public ?\Closure $afterExec = null;
    public ?\Closure $afterMetadata = null;
    public ?string $beforePrefix = null;
    public ?string $afterPrefix = null;
    public ?string $beforeExcept = null;
    public ?string $afterExcept = null;
    public ?string $lastSavepoint = null;
    public int $created = 0;
    public int $released = 0;
    public array $failures = [];

    public function exec(string $statement): int|false
    {
        if (($this->beforePrefix === null || str_starts_with($statement, $this->beforePrefix)) && $statement !== $this->beforeExcept) {
            $before = $this->beforeExec;
            $this->beforeExec = null;
            $before?->__invoke($statement);
        }
        try {
            $result = parent::exec($statement);
        } catch (\PDOException $failure) {
            $this->failures[] = $failure->getMessage();
            throw $failure;
        }
        if (str_starts_with($statement, 'SAVEPOINT ')) {
            $this->lastSavepoint = substr($statement, 10);
            $this->created++;
        }
        if (str_starts_with($statement, 'RELEASE SAVEPOINT ')) $this->released++;
        if (($this->afterPrefix === null || str_starts_with($statement, $this->afterPrefix)) && $statement !== $this->afterExcept) {
            $after = $this->afterExec;
            $this->afterExec = null;
            $after?->__invoke($statement);
        }
        return $result;
    }
}

/** Hook až za skutečným fetch posledního metadatového důkazu. */
final class GcHookStatement extends \PDOStatement
{
    protected function __construct(private GcHookPdo $pdo) {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed
    {
        $row = parent::fetch($mode, $orientation, $offset);
        if (str_starts_with($this->queryString, 'SELECT OCTET_LENGTH(')) {
            $hook = $this->pdo->afterMetadata;
            $this->pdo->afterMetadata = null;
            $hook?->__invoke();
        }
        return $row;
    }
}
