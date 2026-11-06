<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Charges;

use MyInvoice\Service\Property\Charges\ChargeCalculator;
use MyInvoice\Service\Property\Charges\ChargeRequest;
use MyInvoice\Service\Property\Charges\ChargePlan;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\PropertyException;
use PHPUnit\Framework\TestCase;

final class ChargePolicyTest extends TestCase
{
    private static function request(): array
    {
        return ['house_id'=>1,'expected_revision'=>0,'effective_from'=>'2026-06-15T12:30:00+02:00','effective_to'=>null,'basis_at'=>'2026-06-15T12:30:00+02:00','basis_policy'=>'fixed_registry_snapshot','period_policy'=>'no_automatic_proration',
            'frequency'=>'monthly','decision_ref'=>'Synthetic decision','decided_at'=>'2026-06-10T12:00:00+02:00','reason'=>'Synthetic explicit midmonth amount',
            'rates'=>[['component_id'=>1,'unit_ids'=>[1,2],'method'=>'equal','mode'=>'total_budget','amount'=>'0.01','rounding'=>ChargeCalculator::ROUNDING,'legal_basis'=>'own_administration_equal','scope_ref'=>'Synthetic all units','correction'=>false,'correction_reason'=>'']]];
    }
    private static function registry(): array { return ['house'=>['registry_complete'=>true],'units'=>[['id'=>1,'services'=>[5],'attributes'=>['share'=>['num'=>'1','den'=>'2']]],['id'=>2,'services'=>[],'attributes'=>['share'=>['num'=>'1','den'=>'2']]]]]; }
    private static function component(): array { return [['id'=>1,'kind'=>'admin_advance','service_id'=>null]]; }
    public function testHistoricalDateNeedsContemporaryEvidenceInsteadOfModernStatutoryRule(): void
    {
        $r=self::request();$r['effective_from']=$r['basis_at']='2019-06-15T12:00:00+02:00';
        try { ChargeRequest::version($r); self::fail('Modern statutory rule used historically'); }
        catch(ChargeException $e) { self::assertSame('property_charge_historical_basis_required',$e->errorCode); }
        $r['rates'][0]['legal_basis']='historical_documented';
        $p=ChargePlan::calculate(ChargeRequest::version($r),self::registry(),self::component());
        self::assertSame('1',$p['total_cents']);self::assertSame('historical_legal_issuance_assessment',$p['issuance_facts'][0]['code']);
    }

    public function testNoImplicitProrationOrFrequency(): void
    {
        $r=self::request(); unset($r['frequency']); $this->expectException(PropertyException::class); ChargeRequest::version($r);
    }
    public function testSnapshotInstantMustBeExplicitEffectiveBoundary(): void
    {
        $r=self::request();$r['basis_at']='2026-06-01T00:00:00+02:00';$this->expectException(PropertyException::class);ChargeRequest::version($r);
    }
    public function testNegativeRateRequiresDocumentedCorrection(): void
    {
        $r=self::request();$r['rates'][0]['amount']='-0.01';$this->expectException(ChargeException::class);ChargeRequest::version($r);
    }
    public function testNegativeCorrectionAndHalfOpenIntervalAreCanonical(): void
    {
        $r=self::request();$r['rates'][0]['amount']='-0.01';$r['rates'][0]['correction']=true;$r['rates'][0]['correction_reason']='Synthetic correction';
        $v=ChargeRequest::version($r);$p=ChargePlan::calculate($v,self::registry(),self::component());
        self::assertSame('2026-06-15T10:30:00.000000Z',$v['basis_at']);self::assertSame('-1',$p['total_cents']);self::assertSame(['-1','0'],array_column($p['unit_totals'],'amount_cents'));
    }
    public function testOwnAdministrationCannotExcludeGarageOrCountPersons(): void
    {
        $r=self::request();$r['rates'][0]['unit_ids']=[1];$this->expectException(ChargeException::class);ChargePlan::calculate(ChargeRequest::version($r),self::registry(),self::component());
    }
    public function testShareRuleRequiresFullHouseAndExactSumOne(): void
    {
        $r=self::request();$r['rates'][0]['method']='share';$r['rates'][0]['legal_basis']='management_share';$registry=self::registry();$registry['units'][1]['attributes']['share']=['num'=>'1','den'=>'3'];
        $this->expectException(ChargeException::class);ChargePlan::calculate(ChargeRequest::version($r),$registry,self::component());
    }
    public function testServiceCannotInventGarageParticipation(): void
    {
        $r=self::request();$r['rates'][0]['legal_basis']='service_decision';$this->expectException(ChargeException::class);ChargePlan::calculate(ChargeRequest::version($r),self::registry(),[['id'=>1,'kind'=>'service_advance','service_id'=>5]]);
    }
    public function testServiceDecisionAllowsArchiveWithoutFutureDelivery(): void
    {
        $r=self::request();$r['rates'][0]['legal_basis']='service_decision';$r['rates'][0]['unit_ids']=[1];
        $p=ChargePlan::calculate(ChargeRequest::version($r),self::registry(),[['id'=>1,'kind'=>'service_advance','service_id'=>5]]);
        self::assertSame('1',$p['total_cents']);self::assertContains('delivered_at_per_recipient',$p['issuance_facts'][0]['required']);
        self::assertSame('future_issuance_only',$p['issuance_facts'][0]['affects']);
    }
}
