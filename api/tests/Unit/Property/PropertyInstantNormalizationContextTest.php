<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Service\Property\{PropertyException,PropertyInstant,PropertyInstantNormalizationContext};
use MyInvoice\Service\Property\Receivables\ReceivableTimeline;
use PHPUnit\Framework\TestCase;
final class PropertyInstantNormalizationContextTest extends TestCase
{
    public function testRepeatedNormalizationRunsValidatorOncePerLiteral():void
    {
        $calls=0;$context=new PropertyInstantNormalizationContext(function($input)use(&$calls){$calls++;return PropertyInstant::normalize($input);});
        for($i=0;$i<2000;$i++)$last=$context->normalize('2026-03-29T03:30:00+02:00');self::assertSame('2026-03-29T01:30:00.000000Z',$last);
        self::assertSame(1,$calls);self::assertSame('2026-03-29T01:30:00.000000Z',$context->normalize('2026-03-29T01:30:00Z'));self::assertSame(2,$calls);
    }
    public function testInvalidValuesKeepExceptionAndAreNeverMemoized():void
    {
        $inputs=[null,true,42,[],new \stdClass(),'','2026-02-30T00:00:00Z','2026-03-29','2026-01-01T00:00:00-00:00','2026-01-01T00:00:00+14:01','2026-01-01T24:00:00Z','0000-01-01T00:00:00Z','1000-01-01T00:00:00+14:00','9999-12-31T23:59:59-14:00','2026-01-01T00:00:00.1234567Z'];
        foreach($inputs as $input){$calls=0;$context=new PropertyInstantNormalizationContext(function($value)use(&$calls){$calls++;return PropertyInstant::normalize($value);});
            for($i=0;$i<2;$i++){try{$context->normalize($input);self::fail('Invalid instant accepted');}catch(PropertyException $e){self::assertSame('property_invalid_instant',$e->errorCode);self::assertSame(422,$e->httpStatus);}}self::assertSame(2,$calls);
        }
    }
    public function testContextStorageRemainsBoundedWithoutChangingValues():void
    {
        $calls=0;$context=new PropertyInstantNormalizationContext(function($value)use(&$calls){$calls++;return PropertyInstant::normalize($value);});$start=new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $expected=[];$actual=[];for($i=0;$i<1100;$i++){$value=$start->modify('+'.$i.' seconds')->format('c');$expected[]=PropertyInstant::normalize($value);$actual[]=$context->normalize($value);}self::assertSame($expected,$actual);
        $context->normalize($start->format('c'));self::assertSame(1100,$calls);$context->normalize($start->modify('+1099 seconds')->format('c'));self::assertSame(1101,$calls);
    }
    public function testDayPrecisionAcrossSpringAndAutumnDstKeepsExactBoundaries():void
    {
        foreach([['2026-03-29','+01:00','+02:00'],['2026-10-25','+02:00','+01:00']] as [$day,$startOffset,$endOffset]){
            $next=(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');$event=['id'=>'day','kind'=>'claim','amount_cents'=>'100','date'=>$day,'effective_at'=>null,'recorded_at'=>'2020-01-01T00:00:00Z'];$known='2026-12-31T00:00:00Z';
            foreach([[$day.'T00:00:00'.$startOffset,null],[$day.'T12:00:00'.$endOffset,null],[$next.'T00:00:00'.$endOffset,true]] as [$at,$expected]){
                self::assertSame($expected,ReceivableTimeline::inclusion($event,$at,$known));self::assertSame($expected,PropertyInstant::withNormalizationContext(fn()=>ReceivableTimeline::inclusion($event,$at,$known)));
            }
        }
    }
    public function testNestedScopesAndFibersReleaseValuesEvenAfterFailure():void
    {
        $contexts=new \ReflectionProperty(PropertyInstant::class,'normalizationContexts');$count=static fn()=>count($contexts->getValue()??[]);
        self::assertSame(0,$count());
        $fiber=new \Fiber(function()use($count){PropertyInstant::withNormalizationContext(function()use($count){PropertyInstant::normalize('2026-01-01T00:00:00Z');self::assertSame(1,$count());\Fiber::suspend();self::assertSame(1,$count());});});$fiber->start();
        PropertyInstant::withNormalizationContext(function()use($count){self::assertSame(2,$count());PropertyInstant::withNormalizationContext(function()use($count){self::assertSame(2,$count());});});self::assertSame(1,$count());$fiber->resume();self::assertSame(0,$count());
        try{PropertyInstant::withNormalizationContext(fn()=>PropertyInstant::normalize('invalid'));self::fail('Exception lost');}catch(PropertyException){}self::assertSame(0,$count());
    }
}
