<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\LiabilityPolicy;
use MyInvoice\Service\Property\Charges\Issuance\ServiceNoticePolicy;
use PHPUnit\Framework\TestCase;

final class ChargeIssuanceLegalTest extends TestCase
{
    private const AT = '2026-03-01T00:00:00+01:00';

    public function testSjmIsOneLiabilityGroupWithTwoMembersAndOneClaim(): void
    {
        $group = $this->group([11,12], 'solidary', 'sjm_713');
        $state = ['ownerships'=>[['kind'=>'marital_community','members'=>[11,12]]],'roles'=>[['client_id'=>99,'role'=>'payer']]];
        $result = LiabilityPolicy::validate([$group], $state, self::AT, [11,12,99]);
        self::assertCount(1, $result);
        self::assertSame(['num'=>'1','den'=>'1'], $result[0]['extent']);
        self::assertSame([11,12], $result[0]['members']);
        self::assertNotContains(99, $result[0]['members']);
    }

    public function testMissingTitleDoesNotTurnRegisteredOwnersOrPayerIntoDebtors(): void
    {
        $this->expectException(IssuanceException::class);
        LiabilityPolicy::validate([], ['ownerships'=>[['members'=>[11]]],'roles'=>[['client_id'=>99,'role'=>'payer']]], self::AT, [11,99]);
    }

    public function testExplicitCoownerLiabilityUsesExactFractionsWithoutMultiplyingClaim(): void
    {
        $groups=[];
        foreach([[11,'1','2'],[12,'3','10'],[13,'1','5']] as [$id,$num,$den]) $groups[]=$this->group([$id], 'individual', 'documented_title')+[];
        foreach($groups as $i=>&$group) $group['extent']=[['num'=>'1','den'=>'2'],['num'=>'3','den'=>'10'],['num'=>'1','den'=>'5']][$i]; unset($group);
        self::assertCount(3, LiabilityPolicy::validate($groups, [], self::AT, [11,12,13]));
        $groups[2]['extent']=['num'=>'1','den'=>'4'];
        $this->expectException(IssuanceException::class);
        LiabilityPolicy::validate($groups, [], self::AT, [11,12,13]);
    }

    public function testSjmCannotBeAssertedForUnrelatedCurrentRegistryPeople(): void
    {
        $this->expectException(IssuanceException::class);
        LiabilityPolicy::validate([$this->group([11,12], 'solidary', 'sjm_713')],
            ['ownerships'=>[['kind'=>'marital_community','members'=>[11,13]]]], self::AT, [11,12,13]);
    }

    public function testGuarantorDoesNotConsumeOrDuplicateDebtorExtent(): void
    {
        $guarantor=$this->group([13], 'individual', 'documented_title'); $guarantor['role']='guarantor';
        $result=LiabilityPolicy::validate([$this->group([11], 'individual', 'individual_title'),$guarantor], [], self::AT, [11,13]);
        self::assertSame(['debtor','guarantor'],array_column($result,'role'));
    }

    public function testServiceIncreaseCannotApplyInMonthOfDelivery(): void
    {
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($this->notice(), [11,12], '2026-02-01', '2026-02-28', true, '2026-02-28T23:30:00+01:00');
    }

    public function testDeliveryAtLocalMonthEndAllowsOnlyNextLocalMonth(): void
    {
        $notice=$this->notice();
        $notice['deliveries'][0]['delivered_at']='2026-02-28T23:30:00Z';
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice, [11,12], '2026-03-01', '2026-03-31', true, '2026-03-01T01:00:00+01:00');
    }

    public function testRepresentativeDeliveryRequiresExplicitScopeForEveryRecipient(): void
    {
        $notice=$this->notice();
        $notice['deliveries'][0]['recipient_id']=99;
        $notice['deliveries'][0]['represented_ids']=[11,12];
        $notice['deliveries'][0]['authority_ref']='Synthetic power for notices';
        $result=ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2026-03-31',true,'2026-03-01T12:00:00+01:00');
        self::assertSame('2026-03-01',$result['earliest_date']);
        self::assertSame([11,12],$result['covered_recipient_ids']);
        $notice['deliveries'][0]['represented_ids']=[11];
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2026-03-31',true,'2026-03-01T12:00:00+01:00');
    }

    public function testInitialLabelCannotBypassKnownChangedAdvance(): void
    {
        $notice=$this->notice(); $notice['mode']='initial';
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2026-03-31',true,'2026-03-01T12:00:00+01:00');
    }

    public function testChangeMustContainReasonAndWrittenEvidence(): void
    {
        $notice=$this->notice(); $notice['reason']='';
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2026-03-31',true,'2026-03-01T12:00:00+01:00');
    }

    public function testAnnualAdvanceChangeNeedsOwnTitleInsteadOfMonthlyAnalogy(): void
    {
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($this->notice(),[11,12],'2026-03-01','2027-02-28',true,'2026-03-01T12:00:00+01:00','annual');
    }

    public function testNonMonthlyAgreementRecordsExplicitEffectiveDateAndEveryBoundRecipient(): void
    {
        $notice=$this->notice();
        $notice['agreement']=['effective_at'=>'2026-02-28T23:45:00+01:00','recipient_ids'=>[11,12],
            'evidence_ref'=>'Synthetic written annual agreement','legal_basis'=>'Explicit competent annual advance decision'];
        $result=ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2027-02-28',true,'2026-03-01T12:00:00+01:00','annual');
        self::assertSame('non_monthly_title',$result['legal_rule']);
        self::assertSame('2026-02-28',$result['earliest_date']);
        $notice['agreement']['recipient_ids']=[11];
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice,[11,12],'2026-03-01','2027-02-28',true,'2026-03-01T12:00:00+01:00','annual');
    }

    public function testMonthlyAdvanceCannotSelectNonMonthlyAgreementByInputFlag(): void
    {
        $notice=$this->notice(); $notice['agreement']=['effective_at'=>'2026-02-28T23:45:00+01:00','recipient_ids'=>[11,12],
            'evidence_ref'=>'Synthetic agreement','legal_basis'=>'Synthetic title'];
        $this->expectException(IssuanceException::class);
        ServiceNoticePolicy::validate($notice,[11,12],'2026-02-28','2026-03-27',true,'2026-03-01T12:00:00+01:00','monthly');
    }

    private function group(array $members,string $mode,string $basis): array
    {
        return ['group_key'=>'G'.$members[0],'role'=>'debtor','mode'=>$mode,'basis'=>$basis,'members'=>$members,
            'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic obligation deed','legal_reason'=>'Synthetic assessed liability',
            'valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null];
    }
    private function notice(): array
    {
        return ['mode'=>'change','title_ref'=>'Synthetic decision','written_notice_ref'=>'Synthetic written notice','reason'=>'Synthetic price change',
            'deliveries'=>[['recipient_id'=>11,'represented_ids'=>[11,12],'authority_ref'=>'Synthetic power','delivered_at'=>'2026-02-28T23:30:00+01:00','evidence_ref'=>'Synthetic delivery proof']]];
    }
}
