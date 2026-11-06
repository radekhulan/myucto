<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Services\Reconciliation\ServiceReconciliation;
use PHPUnit\Framework\TestCase;
final class SettlementCompositeReconciliationTest extends TestCase
{
    private SettlementCompositeCostTest $fixture;
    private Connection $db;
    protected function setUp():void{$this->fixture=new SettlementCompositeCostTest('testReferenceNativeCompositeClears132kCostAndAdvanceWithoutInventedEntry');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);$this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);}
    protected function tearDown():void{if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function native(string $method,mixed ...$args):mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    public function testCompositeContributorsReconcileOnlyOnceAndTypedReverseKeepsPastAccountingDatesExact():void
    {
        $d=$this->native('base',false,true);$s=$d['f']['supplier'];$p=$this->native('applied',$d);$read=new ServiceReconciliation($this->db);$now=$read->at($s,'2027-04-01');self::assertTrue($now['matches'],json_encode($now));foreach(['315.210','324.110','388.210','389.110'] as $account)self::assertSame('0',$now['totals'][$account]);$past=$read->at($s,'2026-12-31');self::assertTrue($past['matches'],json_encode($past));self::assertSame('8000000',$past['totals']['315.210']);self::assertSame('5000000',$past['totals']['388.210']);self::assertSame('5000000',$past['totals']['389.110']);$p['owner']->reverse($s,$p['done']['id'],'2027-04-02',1,'Synthetic canonical composite correction','composite-reconcile-reverse',$d['f']['actor']);self::assertSame($now['totals'],$read->at($s,'2027-04-01')['totals']);$reversed=$read->at($s,'2027-04-02');self::assertTrue($reversed['matches'],json_encode($reversed));self::assertSame('13200000',$reversed['totals']['315.210']);self::assertSame('13200000',$reversed['totals']['324.110']);
    }
}
