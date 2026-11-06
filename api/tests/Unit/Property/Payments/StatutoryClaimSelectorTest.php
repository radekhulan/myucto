<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Payments;
use MyInvoice\Service\Property\Payments\StatutoryAllocationPolicy;
use MyInvoice\Service\Property\Payments\StatutoryClaimSelector;
use MyInvoice\Service\Property\Payments\CreditReservationState;
use PHPUnit\Framework\TestCase;

final class StatutoryClaimSelectorTest extends TestCase
{
    private function debt(int $id, string $due = '2026-01-10'): array
    {
        return ['id'=>$id,'unit_id'=>$id,'debtor_ids'=>[11,12],'eligible_cents'=>'11500','due_date'=>$due,'reminder_at'=>null,
            'security'=>['kind'=>'unsecured','evidence'=>'Synthetic title'],'damages_for'=>null,
            'legal'=>['basis'=>'statutory','evidence'=>'Confirmed statutory relationship','regime'=>'general'],
            'components'=>['determined_cost'=>'500','default_interest'=>'700','interest'=>'300','principal'=>'10000']];
    }
    private function payment(string $amount='10000'): array
    {
        return ['supplier_id'=>1,'source_key'=>'bank:900','amount_cents'=>$amount,'payment_date'=>'2026-03-01','received_at'=>'2026-03-01T10:00:00+01:00',
            'payer_id'=>99,'debtor_id'=>11,'third_party'=>['basis'=>'debtor_consent','evidence'=>'Consent for synthetic payer'],
            'choice'=>null,'credit_entitlement'=>['party_ids'=>[11,12],'evidence'=>'Proven entitlement, not bank payer']];
    }
    public function testGeneralAndConsumerAllocateOneClaimOnlyDespiteTwoSjmMembers(): void
    {
        $debt=$this->debt(1);
        $general=StatutoryClaimSelector::preview($this->payment(),[$debt]);
        self::assertSame('8500',$general['allocations'][0]['components']['principal']);
        self::assertSame('10000',$general['allocated_cents']);
        $debt['legal']['regime']='consumer_in_default';
        $consumer=StatutoryClaimSelector::preview($this->payment(),[$debt]);
        self::assertSame('9500',$consumer['allocations'][0]['components']['principal']);
        self::assertSame('0',$consumer['allocations'][0]['components']['default_interest']);
        self::assertCount(1,$consumer['allocations']);
    }
    public function testTransitionDependsOnPriorLegalActRatherThanJustReceiptDate(): void
    {
        $debt=$this->debt(1); $debt['legal']=['basis'=>'legal_act','legal_act_date'=>'2020-06-01','evidence'=>'Pre-amendment agreement','regime'=>'consumer_in_default'];
        $plan=StatutoryClaimSelector::preview($this->payment(),[$debt]);
        self::assertSame('8500',$plan['allocations'][0]['components']['principal']);
        self::assertSame('cz_1932_before_2021_07_01',$plan['allocations'][0]['ruleset']);
    }
    public function testReminderMustPrecedePaymentAndChoiceOverridesDefaultOrder(): void
    {
        $a=$this->debt(8,'2026-01-01'); $b=$this->debt(2,'2026-02-01');
        $b['reminder_at']='2026-02-28T10:00:00+01:00';
        self::assertSame(2,StatutoryClaimSelector::preview($this->payment(),[$a,$b])['allocations'][0]['claim_id']);
        $b['reminder_at']='2026-03-02T10:00:00+01:00';
        self::assertSame(8,StatutoryClaimSelector::preview($this->payment(),[$a,$b])['allocations'][0]['claim_id']);
        $p=$this->payment(); $p['choice']=['claim_ids'=>[2],'evidence'=>'Debtor designation at payment'];
        self::assertSame(2,StatutoryClaimSelector::preview($p,[$a,$b])['allocations'][0]['claim_id']);
    }
    public function testEqualLegalPriorityIsReviewNotInternalIdOrder(): void
    {
        $plan=StatutoryClaimSelector::preview($this->payment(),[$this->debt(8),$this->debt(2)]);
        self::assertSame('review_required',$plan['status']); self::assertSame('ambiguous_priority',$plan['reason']); self::assertSame([],$plan['allocations']);
    }
    public function testUnprovenThirdPartyAndSecurityCannotAuthorizeAllocation(): void
    {
        $p=$this->payment(); $p['third_party']=null;
        self::assertSame('third_party_unproven',StatutoryClaimSelector::preview($p,[$this->debt(1)])['reason']);
        $a=$this->debt(1);$b=$this->debt(2);$b['security']=['kind'=>'secured','rank'=>10,'evidence'=>'Rank alone'];
        self::assertSame('security_comparison_required',StatutoryClaimSelector::preview($this->payment(),[$a,$b])['reason']);
    }
    public function testExcessCreditBelongsToProvenPersonsNotThirdPartyPayer(): void
    {
        $plan=StatutoryClaimSelector::preview($this->payment('12000'),[$this->debt(1)]);
        self::assertSame('11500',$plan['allocated_cents']);self::assertSame('500',$plan['credit_cents']);self::assertSame([11,12],$plan['credit_entitlement']['party_ids']);
        $p=$this->payment('12000');$p['credit_entitlement']=null;
        self::assertSame('credit_entitlement_required',StatutoryClaimSelector::preview($p,[$this->debt(1)])['reason']);
    }
    public function testDamagesWaitForUnderlyingClaimUnlessExpresslyDesignated(): void
    {
        $a=$this->debt(1,'2026-02-01');$b=$this->debt(2,'2026-01-01');$b['damages_for']=1;
        self::assertSame(1,StatutoryClaimSelector::preview($this->payment(),[$a,$b])['allocations'][0]['claim_id']);
    }
    public function testAClaimJoinedTwiceThroughPersonsIsRejectedRatherThanMultiplied(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StatutoryClaimSelector::preview($this->payment(),[$this->debt(1),$this->debt(1)]);
    }
    public function testExactLargeAmountsNeverCrossFloat(): void
    {
        $d=$this->debt(1);$d['components']=['determined_cost'=>'0','default_interest'=>'0','interest'=>'0','principal'=>'9007199254740993'];$d['eligible_cents']='9007199254740993';
        self::assertSame('9007199254740992',StatutoryClaimSelector::preview($this->payment('9007199254740992'),[$d])['allocated_cents']);
    }
    public function testReservationDoesNotExtinguishCreditAndCasBlocksConcurrentUse(): void
    {
        $state=CreditReservationState::initial('10000');
        $reserved=CreditReservationState::reserve($state,0,'r1','7000');
        self::assertSame('10000',$reserved['liability_cents']);self::assertSame('3000',CreditReservationState::available($reserved));
        try { CreditReservationState::reserve($reserved,0,'r2','4000');self::fail('Stale CAS accepted'); } catch(\DomainException $e) { self::assertSame('stale_credit_version',$e->getMessage()); }
        $paid=CreditReservationState::pay($reserved,1,'r1','bank:901');
        self::assertSame('3000',$paid['liability_cents']);
        self::assertSame($paid,CreditReservationState::pay($paid,1,'r1','bank:901'));
        $cancelled=CreditReservationState::cancel($reserved,1,'r1');self::assertSame('10000',CreditReservationState::available($cancelled));
    }
    public function testPartialRefundConsumesOnlyPerformedAmountAndKeepsRemainingReservation(): void
    {
        $reserved=CreditReservationState::reserve(CreditReservationState::initial('10000'),0,'r1','7000');
        $partial=CreditReservationState::pay($reserved,1,'r1','bank:901','3000');
        self::assertSame('7000',$partial['liability_cents']);
        self::assertSame('3000',CreditReservationState::available($partial));
        self::assertSame($partial,CreditReservationState::pay($partial,1,'r1','bank:901','3000'));
        $finished=CreditReservationState::pay($partial,2,'r1','bank:902','4000');
        self::assertSame('3000',$finished['liability_cents']);
        self::assertSame('3000',CreditReservationState::available($finished));
        try { CreditReservationState::pay($finished,2,'r1','bank:901','3001');self::fail('Changed retry accepted'); }
        catch (\DomainException $e) { self::assertSame('idempotency_conflict',$e->getMessage()); }
    }

    public function testPartialLiabilityCapDoesNotPretendTheUnderlyingWholeClaimWasPaid(): void
    {
        $a=$this->debt(1);$a['eligible_cents']='1000';$b=$this->debt(2);$b['damages_for']=1;
        $result=StatutoryClaimSelector::preview($this->payment('2000'),[$a,$b]);
        self::assertSame('review_required',$result['status']);
        self::assertSame('underlying_claim_unresolved',$result['reason']);
        self::assertSame([],$result['allocations']);
    }

    public function testDocumentedSecurityComparisonCanResolveSecuredDebtsWithoutInventingRank(): void
    {
        $a=$this->debt(1);$b=$this->debt(2);$a['security']=['kind'=>'secured','evidence'=>'Synthetic first mortgage'];$b['security']=['kind'=>'secured','evidence'=>'Synthetic subordinated security'];
        $p=$this->payment();$p['security_comparison']=['tiers'=>[[2],[1]],'facts'=>[1=>$a['security'],2=>$b['security']],'evidence'=>'Documented enforceability and proceeds comparison'];
        $result=StatutoryClaimSelector::preview($p,[$a,$b]);self::assertSame('ready',$result['status']);self::assertSame(2,$result['allocations'][0]['claim_id']);
        $p['security_comparison']['facts'][2]['evidence']='Different security';self::assertSame('security_comparison_required',StatutoryClaimSelector::preview($p,[$a,$b])['reason']);
    }
    public function testOldDebtorDispositionHasDocumentedInterestEffectAndModernGeneralNeedsConsent(): void
    {
        $legal=['basis'=>'legal_act','legal_act_date'=>'2020-06-01','evidence'=>'Exact legal act creating this debt','regime'=>'general',
            'component_disposition'=>['order'=>['principal','interest','default_interest','determined_cost'],'debtor_evidence'=>'Payment designation',
                'old_interest_effect'=>['resolution'=>'separately_evidenced','evidence'=>'Separate actual accessory receipt for remaining interest on costs']]];
        $parts=StatutoryAllocationPolicy::allocate('1000',$this->debt(1)['components'],'2026-03-01',$legal);
        self::assertSame('1000',$parts['components']['principal']);
        $legal['legal_act_date']='2022-01-01';
        try{StatutoryAllocationPolicy::allocate('1000',$this->debt(1)['components'],'2026-03-01',$legal);self::fail('Missing creditor consent accepted.');}
        catch(\DomainException $e){self::assertSame('creditor_consent_required',$e->getMessage());}
        $legal['component_disposition']['creditor_consent']='Explicit consent';
        self::assertSame('1000',StatutoryAllocationPolicy::allocate('1000',$this->debt(1)['components'],'2026-03-01',$legal)['components']['principal']);
    }
}
