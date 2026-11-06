<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504Policy;
use PHPUnit\Framework\TestCase;

final class SecuritiesDebt504CouponDayCountTest extends TestCase
{
    private function period(string $from,string $to,string $dayCount,?int $frequency): array
    {
        $period=['from'=>$from,'to_exclusive'=>$to,'annual_rate_fraction'=>'0.04','day_count'=>$dayCount,'rounding'=>'half_away_from_zero_2_cumulative','terms_reference'=>'Synthetic prospectus'];
        if($frequency!==null)$period['frequency']=$frequency;
        return $period;
    }

    public function testLeapYearCouponPeriodPaysExactlyTheContractCouponUnderActAct(): void
    {
        $leap=$this->period('2027-07-01','2028-07-01','ACT/ACT-ICMA',1);
        self::assertSame('40000.00',SecuritiesDebt504Policy::coupon($leap,'1000000.00','2027-07-01','2028-07-01'));
        self::assertSame('20109.29',SecuritiesDebt504Policy::coupon($leap,'1000000.00','2027-07-01','2028-01-01'));
        self::assertSame('19890.71',SecuritiesDebt504Policy::coupon($leap,'1000000.00','2028-01-01','2028-07-01'));
        self::assertSame('40109.59',SecuritiesDebt504Policy::coupon($this->period('2027-07-01','2028-07-01','ACT/365F',null),'1000000.00','2027-07-01','2028-07-01'));
    }

    public function testSemiAnnualCouponIsHalfTheAnnualRate(): void
    {
        self::assertSame('20000.00',SecuritiesDebt504Policy::coupon($this->period('2026-01-01','2026-07-01','ACT/ACT-ICMA',2),'1000000.00','2026-01-01','2026-07-01'));
    }

    public function testIrregularOrUndeclaredFrequencyNeedsReview(): void
    {
        foreach([[$this->period('2026-01-01','2026-08-01','ACT/ACT-ICMA',2),'debt_coupon_period_irregular'],[$this->period('2026-01-01','2027-01-01','ACT/ACT-ICMA',null),'debt_coupon_frequency_required'],[$this->period('2026-01-01','2027-01-01','ACT/365F',1),'debt_coupon_period_invalid']] as [$period,$code]){
            try{SecuritiesDebt504Policy::coupon($period,'1000000.00',$period['from'],$period['to_exclusive']);self::fail($code);}
            catch(\DomainException $e){self::assertSame($code,$e->getMessage());}
        }
    }
}
