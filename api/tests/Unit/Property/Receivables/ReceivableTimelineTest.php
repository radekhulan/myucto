<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Receivables;
use MyInvoice\Service\Property\Receivables\ReceivableTimeline;
use PHPUnit\Framework\TestCase;
final class ReceivableTimelineTest extends TestCase
{
    public function testReversalDoesNotEraseJanuaryAndCreditIsNeverNetted():void
    {
        $events=[self::event('claim','400000','2026-01-01'),self::event('paid','100000','2026-01-15'),self::event('paid','-100000','2026-02-01'),self::event('credit','50000','2026-01-10')];
        $january=ReceivableTimeline::sum($events,'2026-01-31T23:59:59.999999+01:00','2026-12-01T00:00:00Z');
        self::assertSame('300000',$january['gross_receivable_cents']);self::assertSame('50000',$january['gross_credit_cents']);
        $february=ReceivableTimeline::sum($events,'2026-02-02T00:00:00+01:00','2026-12-01T00:00:00Z');
        self::assertSame('400000',$february['gross_receivable_cents']);self::assertSame('50000',$february['gross_credit_cents']);
    }
    public function testUnknownTimeWithinDayReturnsUncertaintyInsteadOfInventedMidnight():void
    {
        $result=ReceivableTimeline::sum([self::event('claim','10000','2026-01-01')],'2026-01-01T12:00:00+01:00','2026-12-01T00:00:00Z');
        self::assertFalse($result['complete']);self::assertNull($result['gross_receivable_cents']);self::assertSame('day',$result['precision']);self::assertSame(['event-claim-2026-01-01'],$result['uncertain_event_ids']);
    }
    public function testKnownAtAndExplicitInstantAreIndependent():void
    {
        $event=self::event('claim','10000','2026-01-01');$event['effective_at']='2026-01-01T10:00:00+01:00';$event['recorded_at']='2026-02-01T10:00:00Z';
        self::assertSame('0',ReceivableTimeline::sum([$event],'2026-01-01T11:00:00+01:00','2026-01-31T10:00:00Z')['gross_receivable_cents']);
        self::assertSame('10000',ReceivableTimeline::sum([$event],'2026-01-01T11:00:00+01:00','2026-02-02T10:00:00Z')['gross_receivable_cents']);
    }
    private static function event(string $kind,string $amount,string $date):array{return ['id'=>'event-'.$kind.'-'.$date,'kind'=>$kind,'amount_cents'=>$amount,'date'=>$date,'effective_at'=>null,'recorded_at'=>'2026-01-01T00:00:00Z'];}
}
