<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PropertySaldoReadRepository;
use MyInvoice\Service\Property\PropertyException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
final class PropertySaldoSnapshotTest extends TestCase
{
    public function testCallerTransactionIsRejectedWithoutQueriesOrOwnershipChanges():void
    {
        $pdo=new SaldoSnapshotPdo(true);$read=$this->reader($pdo);
        try{$read->page(7,'2026-01-01T00:00:00Z','2026-01-01T00:00:00Z');self::fail('Caller transaction was accepted');}
        catch(PropertyException $e){self::assertSame('property_saldo_snapshot_required',$e->errorCode);self::assertSame(409,$e->httpStatus);}
        self::assertTrue($pdo->inTransaction());self::assertSame([],$pdo->calls);
    }
    public function testOwnedSnapshotPinsIsolationAndReadOnlyAndCloses():void
    {
        $pdo=new SaldoSnapshotPdo(false);$page=$this->reader($pdo)->page(7,'2026-01-01T00:00:00Z','2026-01-01T00:00:00Z');
        self::assertSame([],$page['items']);self::assertFalse($pdo->inTransaction());
        self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION READ ONLY','SELECT','COMMIT'],$pdo->calls);
    }
    public function testOwnedFailureRollsBackOnlyItsSnapshot():void
    {
        $pdo=new SaldoSnapshotPdo(false,true);
        try{$this->reader($pdo)->page(7,'2026-01-01T00:00:00Z','2026-01-01T00:00:00Z');self::fail('Read failure lost');}
        catch(\RuntimeException $e){self::assertSame('Synthetic read failure',$e->getMessage());}
        self::assertFalse($pdo->inTransaction());self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION READ ONLY','SELECT','ROLLBACK'],$pdo->calls);
    }
    private function reader(PDO $pdo):PropertySaldoReadRepository
    {
        $db=(new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();(new \ReflectionProperty(Connection::class,'pdo'))->setValue($db,$pdo);return new PropertySaldoReadRepository($db);
    }
}
final class SaldoSnapshotPdo extends PDO
{
    public array $calls=[];
    public function __construct(private bool $active,private bool $fail=false){}
    public function inTransaction():bool{return $this->active;}
    public function exec(string $statement):int|false{$this->calls[]=$statement;if(str_starts_with($statement,'START TRANSACTION'))$this->active=true;return 0;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->calls[]='SELECT';if($this->fail)throw new \RuntimeException('Synthetic read failure');return new SaldoEmptyStatement();}
    public function commit():bool{$this->calls[]='COMMIT';$this->active=false;return true;}
    public function rollBack():bool{$this->calls[]='ROLLBACK';$this->active=false;return true;}
}
final class SaldoEmptyStatement extends PDOStatement
{
    public function execute(?array $params=null):bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
}
