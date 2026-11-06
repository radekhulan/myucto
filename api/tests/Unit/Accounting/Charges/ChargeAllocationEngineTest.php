<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Charges;

use MyInvoice\Service\Accounting\Charges\ChargeAllocationEngine;
use MyInvoice\Service\Accounting\Charges\ExactFraction;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeCalculator;
use MyInvoice\Service\Property\Charges\ChargeCalculator;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\TestCase;

final class ChargeAllocationEngineTest extends TestCase
{
    public function testNeutralKernelIsCallable(): void
    {
        self::assertTrue(class_exists(ChargeAllocationEngine::class));
        self::assertSame('3', ChargeAllocationEngine::allocate(['num'=>'1','den'=>'1'], [['id'=>1,'basis'=>['num'=>'3','den'=>'1']]], 'rate_per_basis')['total_cents']);
    }

    public function testPropertyJsonAndHashesKeepOriginalSeventyFivePreimages(): void
    {
        $units=[['id'=>3,'attributes'=>['floor_area'=>['num'=>'81','den'=>'2'],'share'=>['num'=>'1','den'=>'3'],'persons'=>['num'=>'2','den'=>'1']]],['id'=>1,'attributes'=>['floor_area'=>['num'=>'40','den'=>'1'],'share'=>['num'=>'1','den'=>'3'],'persons'=>['num'=>'1','den'=>'1']]],['id'=>2,'attributes'=>['floor_area'=>['num'=>'79','den'=>'2'],'share'=>['num'=>'1','den'=>'3'],'persons'=>['num'=>'0','den'=>'1']]]];
        $rows=[];
        foreach(['unit','equal','floor_area','share','person'] as $method) foreach(['rate_per_basis','total_budget'] as $mode) foreach(['0','0.01','-0.01','1','-1','12.34','999999999999.99'] as $amount) {
            $rate=['method'=>$method,'amount'=>$amount,'mode'=>$mode,'rounding'=>ChargeCalculator::ROUNDING];
            $result=ChargeCalculator::calculate($rate,$units);
            self::assertSame($result,ChargeCalculator::calculate($rate,array_reverse($units)));
            $rows[]=['rate'=>$rate,'result'=>$result,'canonical_hash'=>PropertyInput::hash($result)];
        }
        foreach(['0.000001','0.005','-0.005','0.333333','-999999.999999'] as $amount) {
            $rate=['method'=>'unit','amount'=>$amount,'mode'=>'rate_per_basis','rounding'=>ChargeCalculator::ROUNDING];
            $result=ChargeCalculator::calculate($rate,$units);
            $rows[]=['rate'=>$rate,'result'=>$result,'canonical_hash'=>PropertyInput::hash($result)];
        }
        self::assertCount(75,$rows);
        self::assertSame('2b3474fd84566904dd60eb057863b76c03e233d82cd99be0d6135c5c31c6dc4d',hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)));
    }

    public function testNativeMemberIdentityAndFortyThousandContribution(): void
    {
        $result=MembershipChargeCalculator::calculate('20000','rate_per_basis',[
            ['member_id'=>42,'basis'=>['num'=>'1','den'=>'1']],
            ['member_id'=>7,'basis'=>['num'=>'1','den'=>'1']],
        ]);
        self::assertSame('4000000',$result['total_cents']);
        self::assertSame([7,42],array_column($result['lines'],'member_id'));
        self::assertSame(['2000000','2000000'],array_column($result['lines'],'amount_cents'));
        self::assertArrayNotHasKey('unit_id',$result['lines'][0]);
        self::assertArrayNotHasKey('subject_id',$result['lines'][0]);
        self::assertSame(ChargeAllocationEngine::ROUNDING,$result['rounding']);
    }

    public function testMemberNegativeBudgetUsesSameResidualAndIdentityOrder(): void
    {
        $members=[['member_id'=>12,'basis'=>['num'=>'1','den'=>'3']],['member_id'=>3,'basis'=>['num'=>'1','den'=>'3']],['member_id'=>8,'basis'=>['num'=>'1','den'=>'3']]];
        $result=MembershipChargeCalculator::calculate('-0.01','total_budget',$members);
        self::assertSame(['-1','0','0'],array_column($result['lines'],'amount_cents'));
        self::assertSame([3,8,12],array_column($result['lines'],'member_id'));
        self::assertSame($result,MembershipChargeCalculator::calculate('-0.01','total_budget',array_reverse($members)));
    }

    public function testDuplicateMemberIsRejected(): void
    {
        $member=['member_id'=>1,'basis'=>['num'=>'1','den'=>'1']];
        $this->expectException(\InvalidArgumentException::class);
        MembershipChargeCalculator::calculate('20000','rate_per_basis',[$member,$member]);
    }

    public function testMissingMemberBasisIsRejectedInsteadOfInferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MembershipChargeCalculator::calculate('20000','rate_per_basis',[['member_id'=>1]]);
    }

    public function testNonzeroBudgetCannotUseZeroBasis(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChargeAllocationEngine::allocate(ExactFraction::decimalCents('1'),[['id'=>1,'basis'=>['num'=>'0','den'=>'1']]],'total_budget');
    }
}
