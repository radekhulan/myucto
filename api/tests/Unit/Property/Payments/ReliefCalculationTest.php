<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Payments;
use MyInvoice\Service\Property\Payments\ReliefCalculation;
use PHPUnit\Framework\TestCase;
final class ReliefCalculationTest extends TestCase
{
    public function testOnlyPaidExcessBecomesCreditWithoutReleasingPayment():void
    {
        self::assertSame(['principal_cents'=>'7000','paid_cents'=>'7000','relief_cents'=>'1000','unpaid_cents'=>'0'],ReliefCalculation::calculate('10000','0','8000','3000'));
        self::assertSame(['principal_cents'=>'0','paid_cents'=>'0','relief_cents'=>'10000','unpaid_cents'=>'0'],ReliefCalculation::calculate('10000','0','10000','10000'));
        self::assertSame(['principal_cents'=>'6000','paid_cents'=>'4000','relief_cents'=>'0','unpaid_cents'=>'2000'],ReliefCalculation::calculate('10000','1000','4000','3000'));
    }
    public function testSuccessiveCorrectionConsumesRemainingPrincipalOnly():void
    {
        $this->expectException(\DomainException::class);
        ReliefCalculation::calculate('10000','3000','7000','7001');
    }
    public function testLargeCentBoundaryIsExact():void
    {
        self::assertSame('1',ReliefCalculation::calculate('9007199254740993','0','9007199254740993','1')['relief_cents']);
    }
}
