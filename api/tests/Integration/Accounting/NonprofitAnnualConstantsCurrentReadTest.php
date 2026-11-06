<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
final class NonprofitAnnualConstantsCurrentReadTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testExactCurrentReadSeesCommittedOverrideAfterOldSnapshot(bool $missing):void
    {
        $cfg=Config::load(Bootstrap::rootDir());self::assertStringEndsWith('_test',(string)$cfg->get('db.name'));$db=Connection::withoutSharedTestConnection(fn()=>new Connection($cfg));$other=Connection::withoutSharedTestConnection(fn()=>new Connection($cfg));$pdo=$db->pdo();$repo=new TaxConstantsRepository($db);$year=2196;$previous=$pdo->query('SELECT data FROM tax_constants WHERE year=2196')->fetchColumn();$isolation=(int)$pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        try{
            $pdo->exec('DELETE FROM tax_constants WHERE year=2196');if(!$missing)$repo->upsert($year,['nonprofit_reduction_min'=>300000]);$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');$pdo->beginTransaction();
            if($missing){try{$repo->forExactYear($year);self::fail('Exact year must not fall back');}catch(\OutOfRangeException){self::assertTrue($pdo->inTransaction());}}else self::assertSame(300000,$repo->forExactYear($year)['nonprofit_reduction_min']);
            (new TaxConstantsRepository($other))->upsert($year,['nonprofit_reduction_min'=>450000]);
            if(!$missing)self::assertSame(300000,$repo->forExactYear($year)['nonprofit_reduction_min']);self::assertSame(450000,$repo->forExactYear($year,true)['nonprofit_reduction_min']);self::assertSame($year,$repo->forExactYear($year,true)['year']);self::assertTrue($pdo->inTransaction());
        }finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DELETE FROM tax_constants WHERE year=2196');if($previous!==false)$pdo->prepare('INSERT INTO tax_constants(year,data)VALUES(2196,?)')->execute([$previous]);$pdo->exec('SET SESSION innodb_snapshot_isolation='.$isolation);$other->close();$db->close();}
    }
    public function testCurrentReadRequiresCallerTransaction():void
    {$cfg=Config::load(Bootstrap::rootDir());self::assertStringEndsWith('_test',(string)$cfg->get('db.name'));$db=Connection::withoutSharedTestConnection(fn()=>new Connection($cfg));try{$this->expectException(\LogicException::class);(new TaxConstantsRepository($db))->forExactYear(2026,true);}finally{$db->close();}}
}
