<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\TestCase;
final class JsonGraphEqualityTest extends TestCase
{
    public function testCompleteGraphEqualityMatchesOriginalCanonicalHash():void
    {
        $pairs=[[[],[]],[['amount_cents'=>'180000'],['amount_cents'=>'180000']],[['amount_cents'=>'180000'],['amount_cents'=>'180001']],[['z'=>1,'a'=>2],['a'=>2,'z'=>1]],[['items'=>[2=>'two',0=>'zero',1=>'one']],['items'=>[0=>'zero',1=>'one',2=>'two']]],[['items'=>[['01'=>'leading','1'=>'integer','-1'=>'negative']]],['items'=>[['-1'=>'negative','1'=>'integer','01'=>'leading']]]],[['amount'=>'1'],['amount'=>1]],[['v'=>1],['v'=>1.0]],[['v'=>0.0],['v'=>-0.0]],[['v'=>[]],['v'=>null]],[['yes'=>true,'no'=>false,'text'=>'Případ Á / nárok'],['text'=>'Případ Á / nárok','no'=>false,'yes'=>true]],[['a'=>PHP_INT_MAX,'b'=>PHP_INT_MIN],['b'=>PHP_INT_MIN,'a'=>PHP_INT_MAX]]];
        for($i=0;$i<100;$i++){$a=['components'=>[['amount_cents'=>(string)$i,'dimensions'=>[2=>3,1=>4]]],'parties'=>[['members'=>[$i,$i+1],'role'=>'debtor']]];$b=$a;if($i%3===0)$b['components'][0]['dimensions']=[1=>4,2=>3];if($i%7===0)$b['components'][0]['amount_cents']=(string)($i+1);$pairs[]=[$a,$b];}
        foreach($pairs as [$left,$right])self::assertSame(PropertyInput::hash($left)===PropertyInput::hash($right),PropertyInput::sameJsonGraph($left,$right));
    }
    public function testInvalidJsonAndDepthBoundariesKeepOriginalFailureCodes():void
    {
        $outcome=static function(callable $call):array{try{return ['ok',$call()];}catch(\JsonException $e){return ['error',$e->getCode()];}};
        foreach([['v'=>INF],['v'=>NAN],['v'=>"\xFF"],["\xFF"=>[]]] as $graph)self::assertSame($outcome(fn()=>PropertyInput::hash($graph)===PropertyInput::hash($graph)),$outcome(fn()=>PropertyInput::sameJsonGraph($graph,$graph)));
        for($depth=507;$depth<=514;$depth++){$nested='leaf';for($i=0;$i<$depth;$i++)$nested=['key'=>$nested];foreach([['items'=>[$nested]],['other'=>$nested],[$nested]] as $graph)self::assertSame($outcome(fn()=>PropertyInput::hash($graph)===PropertyInput::hash($graph)),$outcome(fn()=>PropertyInput::sameJsonGraph($graph,$graph)));}
    }
    public function testNestedObjectsCannotEnterTypedGraphOrRunSerializationCallbacks():void
    {
        $called=false;$object=new class($called) implements \JsonSerializable {public function __construct(private bool &$called){}public function jsonSerialize():mixed{$this->called=true;return ['amount_cents'=>'1'];}};
        foreach([['nested'=>[['object'=>$object]]],['nested'=>(object)['a'=>1]]] as $graph){try{PropertyInput::sameJsonGraph($graph,$graph);self::fail('Nested PHP objects are outside decoded JSON graph contract');}catch(\InvalidArgumentException $e){self::assertFalse($called);}}
    }
    public function testNestedResourcesAreRejected():void
    {
        $handle=fopen('php://memory','r+');try{$this->expectException(\InvalidArgumentException::class);PropertyInput::sameJsonGraph(['nested'=>[['resource'=>$handle]]],[]);}finally{fclose($handle);}
    }
}