<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
final class PropertyInputListHashTest extends TestCase
{
    private static function reference(array $payload):string
    {
        $sort=static function(mixed $value)use(&$sort):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);return array_map($sort,$value);};
        return hash('sha256',json_encode($sort($payload),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    }
    public function testExactCanonicalHashForListsObjectsKeysAndScalarValues():void
    {
        $vectors=[[],['claims'=>[]],['claims'=>[['z'=>0.0,'a'=>-0.0,'null'=>null,'yes'=>true,'no'=>false,'utf8'=>'Případ Á / nárok','quoted'=>"a\"b\\c\n"]]],
            ['claims'=>[2=>'two',0=>'zero',1=>'one']],['claims'=>[[1=>'one',0=>'zero'],['01'=>'leading','1'=>'integer','-1'=>'negative']]],
            [1=>'one',0=>'zero'],['claims'=>[PHP_INT_MAX,PHP_INT_MIN,'99999999999999999999',1.25,1e25]],
            ['claims'=>[(object)['z'=>1,'a'=>2]]],['claims'=>[['groups'=>[['members'=>[1,2],'extent'=>['den'=>'3','num'=>'1']]]]]]];
        foreach($vectors as $payload){self::assertSame(self::reference($payload),PropertyInput::hash($payload));self::assertSame(self::reference($payload),PropertyInput::hashLists($payload,['claims','credits']));}
        for($i=0;$i<100;$i++){
            $payload=['totals'=>['paid'=>(string)$i,'unknown'=>null],'claims'=>[['id'=>$i,'events'=>[['amount_cents'=>(string)(-$i),'kind'=>'claim']]],['id'=>$i+1,'groups'=>[]]],'credits'=>[]];
            self::assertSame(self::reference($payload),PropertyInput::hashLists($payload,['claims','credits']));
        }
    }
    public function testDepthAndInvalidJsonFailuresMatchOriginalHash():void
    {
        $capture=static function(callable $run):array{try{return ['ok',$run()];}catch(\JsonException $e){return ['error',$e->getCode()];}};
        foreach([['claims'=>[INF]],['claims'=>[NAN]],['claims'=>["\xFF"]],["\xFF"=>[]]] as $payload)
            self::assertSame($capture(fn()=>self::reference($payload)),$capture(fn()=>PropertyInput::hashLists($payload,['claims'])));
        for($depth=507;$depth<=514;$depth++){
            $nested='leaf';for($i=0;$i<$depth;$i++)$nested=['key'=>$nested];
            foreach([['claims'=>[$nested]],['other'=>$nested]] as $payload)
                self::assertSame($capture(fn()=>self::reference($payload)),$capture(fn()=>PropertyInput::hashLists($payload,['claims'])));
        }
    }
    #[RunInSeparateProcess]
    public function testHashDoesNotCopyAllRowsOrBuildWholeJson():void
    {
        $groups=[];for($id=1;$id<=20;$id++)$groups[]=['members'=>[$id,$id+1],'role'=>'debtor','remaining_cap_cents'=>'1000','extent'=>['num'=>'1','den'=>'1']];
        $rows=[];for($id=1;$id<=10000;$id++)$rows[]=['id'=>$id,'groups'=>$groups,'events'=>[['id'=>'claim:'.$id,'amount_cents'=>'1000']]];
        $snapshot=['claims'=>$rows,'credits'=>[],'totals'=>['amount_cents'=>'10000000']];
        $before=memory_get_usage(true);memory_reset_peak_usage();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/',PropertyInput::hashLists($snapshot,['claims','credits']));
        self::assertLessThan(8*1024*1024,memory_get_peak_usage(true)-$before);
    }
}
