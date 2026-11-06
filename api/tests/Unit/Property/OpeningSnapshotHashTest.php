<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Service\Property\{PropertyInput};
use MyInvoice\Service\Property\Opening\{OpeningReadProof,OpeningException};
use PHPUnit\Framework\TestCase;
final class OpeningSnapshotHashTest extends TestCase
{
    private function decode(array $payload):array
    {
        $row=['snapshot_json'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),'snapshot_hash'=>PropertyInput::hash($payload)];return (new \ReflectionMethod(OpeningReadProof::class,'decode'))->invoke(null,$row,'snapshot');
    }
    public function testExactCanonicalSnapshotBytesForEmptyNumericStringAndNestedValues():void
    {
        $vectors=[[],['items'=>[]],['items'=>[['z'=>0.0,'a'=>-0.0,'null'=>null,'yes'=>true,'no'=>false,'utf8'=>'Případ Á / nárok','quoted'=>"a\"b\\c\n"]]],['items'=>[2=>'two',0=>'zero',1=>'one']],['items'=>[[1=>'one',0=>'zero'],['01'=>'leading','1'=>'integer','-1'=>'negative']]],[1=>'one',0=>'zero'],['items'=>[PHP_INT_MAX,PHP_INT_MIN,'99999999999999999999',1.25,1e25]],['items'=>[['groups'=>[['members'=>[1,2],'extent'=>['den'=>'3','num'=>'1']]]]],'request'=>['items'=>[['amount_cents'=>'1234','effective_at'=>'2026-01-01T00:00:00Z']]]]];
        foreach($vectors as $payload){self::assertSame(PropertyInput::hash($payload),PropertyInput::hashLists($payload,['items']));self::assertSame($payload,$this->decode($payload));}
    }
    public function testDepthAndInvalidJsonErrorsRemainIdentical():void
    {
        $capture=static function(callable $run):array{try{return ['ok',$run()];}catch(\JsonException $e){return ['error',$e->getCode()];}};
        foreach([['items'=>[INF]],['items'=>[NAN]],['items'=>["\xFF"]],["\xFF"=>[]]] as $payload)self::assertSame($capture(fn()=>PropertyInput::hash($payload)),$capture(fn()=>PropertyInput::hashLists($payload,['items'])));
        for($depth=507;$depth<=514;$depth++){$nested='leaf';for($i=0;$i<$depth;$i++)$nested=['key'=>$nested];foreach([['items'=>[$nested]],['other'=>$nested],['request'=>['items'=>[$nested]],'items'=>[]]] as $payload)self::assertSame($capture(fn()=>PropertyInput::hash($payload)),$capture(fn()=>PropertyInput::hashLists($payload,['items'])));}
    }
    public function testSnapshotVerificationAvoidsCopyingAllMaterializedItems():void
    {
        $groups=[];for($id=1;$id<=20;$id++)$groups[]=['members'=>[$id,$id+1],'role'=>'debtor','remaining_cap_cents'=>'1000','extent'=>['num'=>'1','den'=>'1']];$rows=[];for($id=1;$id<=4800;$id++)$rows[]=['case_key'=>'synthetic-'.$id,'liability_groups'=>$groups,'components'=>[['amount_cents'=>'180000','account_code'=>'315.100']]];$snapshot=['items'=>$rows,'request'=>['items'=>[]],'period_id'=>1];$row=['snapshot_json'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'snapshot_hash'=>PropertyInput::hash($snapshot)];unset($snapshot,$rows,$groups);gc_collect_cycles();memory_reset_peak_usage();$decoded=(new \ReflectionMethod(OpeningReadProof::class,'decode'))->invoke(null,$row,'snapshot');$transient=memory_get_peak_usage(false)-memory_get_usage(false);self::assertCount(4800,$decoded['items']);self::assertLessThan(16*1024*1024,$transient,'Canonical verification must stream complete items instead of sorting a second full graph');
    }
    public function testChangedFinancialItemCannotPassOriginalSnapshotHash():void
    {
        $value=['items'=>[['amount_cents'=>'180000','case_key'=>'synthetic']],'request'=>['items'=>[]]];$hash=PropertyInput::hash($value);$value['items'][0]['amount_cents']='180001';$this->expectException(OpeningException::class);(new \ReflectionMethod(OpeningReadProof::class,'decode'))->invoke(null,['snapshot_json'=>json_encode($value,JSON_THROW_ON_ERROR),'snapshot_hash'=>$hash],'snapshot');
    }
}