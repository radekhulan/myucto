<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Tests\Integration\Property\Services\Settlement\ServiceLifecycleNativeTest;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitSvjSourceFacts;
use PHPUnit\Framework\TestCase;

final class NonprofitSvjSourceFactsTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;private Connection $db;
    protected function setUp():void
    {$this->fixture=new ServiceLifecycleNativeTest('testNativeProfitAndLossYearRolloverHasOnlyDifferenceIncome');(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);$this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);}
    protected function tearDown():void
    {(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    public function testActualLifecycleBindsExactEstimateUnwindAndFinalCostInsteadOfWholeInvoice():void
    {
        $data=(new \ReflectionMethod($this->fixture,'base'))->invoke($this->fixture,'service_profit_and_loss');$f=$data['f'];
        $invoice=(new \ReflectionMethod($this->fixture,'invoice'))->invoke($this->fixture,$data);$owner=$data['owner'];
        $owner->unwind($f['supplier'],$data['case'],'2027-04-01',$f['actor']);$final=$owner->finalize($f['supplier'],$data['case'],$invoice['invoice'],$invoice['line']['id'],'2027-04-01','Synthetic final legal allocation',$f['actor']);
        $state=$owner->reconciliationSources($f['supplier'],$data['case']);$map=NonprofitSvjSourceFacts::project($state);
        self::assertSame('expense',$map[$invoice['line']['id']]['kind']);self::assertArrayNotHasKey($final['cost_line_id'],$map);self::assertGreaterThanOrEqual(6,count($map));
        $sources=[];foreach($map as$id=>$facts)$sources[]=['id'=>'line:'.$id,'line_id'=>$id,'origin'=>['line_id'=>$id]];
        $sources[]=['id'=>'line:999999999','line_id'=>999999999,'origin'=>['line_id'=>999999999]];
        $snapshot=['legal_profile'=>['profile'=>['kind'=>'svj']],'period'=>['starts_on'=>'2027-01-01','ends_on'=>'2027-12-31'],'sources'=>$sources];
        $attached=(new NonprofitSvjSourceFacts($this->db))->attach($f['supplier'],$snapshot);
        self::assertCount(count($map),array_filter($attached['sources'],static fn(array $row):bool=>isset($row['svj_owner_facts'])));self::assertArrayNotHasKey('svj_owner_facts',$attached['sources'][array_key_last($attached['sources'])]);
        foreach($attached['sources']as$row)if(isset($row['svj_owner_facts']))self::assertSame($data['case'],$row['svj_owner_facts']['case_id']);
    }
}
