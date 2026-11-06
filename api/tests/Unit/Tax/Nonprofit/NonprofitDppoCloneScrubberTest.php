<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;
use MyInvoice\Service\Anonymization\{NonprofitDppoCloneScrubber,Pseudonymizer,TextScrubber};
use MyInvoice\Service\Tax\Nonprofit\{NonprofitDppoLegalRules,NonprofitTaxRules};
use PHPUnit\Framework\TestCase;

final class NonprofitDppoCloneScrubberTest extends TestCase
{
    public function testLegalHistoryScrubsEvidenceAndRebasesBothCanonicalHashesWithoutChangingLegalFacts():void
    {
        $profile=NonprofitDppoLegalRules::profile(['kind'=>'narrow_vpp','entity_type'=>'association','main_activity_nonbusiness'=>true,'evidence'=>'Synthetic legal-private@synthetic.invalid','legal_basis'=>'zdp_17a']);
        $rows=[];$previous=null;
        foreach([1,2]as$id){$row=['id'=>$id,'supplier_id'=>10,'period_id'=>20,'revision_no'=>$id,'previous_id'=>$previous['id']??null,'profile'=>$profile,'module_proof'=>[],'reason'=>'Synthetic reason-private@synthetic.invalid','idempotency_key'=>'private-token-'.$id,'created_by'=>30];$row['request_hash']=NonprofitTaxRules::hash(['period_id'=>20,'expected_revision'=>$id-1,'profile'=>$profile,'reason'=>$row['reason'],'actor'=>30]);$row['record_hash']='old-record-'.$id;$rows[]=$row;$previous=$row;}
        $result=NonprofitDppoCloneScrubber::rebaseProfiles($rows,new TextScrubber(new Pseudonymizer('synthetic-dppo-clone')));
        self::assertCount(2,$result['rows']);self::assertStringNotContainsString('legal-private@synthetic.invalid',NonprofitTaxRules::canonicalJson($result));self::assertStringNotContainsString('reason-private@synthetic.invalid',NonprofitTaxRules::canonicalJson($result));
        foreach($result['rows']as$index=>$row){self::assertSame('association',$row['profile']['entity_type']);self::assertSame('narrow_vpp',$row['profile']['kind']);self::assertSame('standard',$row['profile']['reduction_band']);self::assertSame('zdp_17a',$row['profile']['legal_basis']);self::assertSame($rows[$index]['supplier_id'],$row['supplier_id']);self::assertSame($row['record_hash'],$result['hashes'][$rows[$index]['record_hash']]);$record=array_intersect_key($row,array_flip(['supplier_id','period_id','revision_no','previous_id','profile','module_proof','reason','idempotency_key','request_hash','created_by']));$record['parent_hash']=$index===0?null:$result['rows'][$index-1]['record_hash'];self::assertSame(NonprofitTaxRules::hash($record),$row['record_hash']);}
    }
    public function testNumericIdentityFieldsKeepScrubbedValuesForStringsAndIntegers():void
    {
        $scrubber=new TextScrubber(new Pseudonymizer('synthetic-dppo-numeric-identity'));
        $before=['supplier'=>['ic'=>'12345679','phone'=>'700000002','zip'=>'10000'],'history'=>[['ic'=>12345679,'phone'=>700000001,'zip'=>10000]]];
        $expected=json_decode($scrubber->scrubJson(NonprofitTaxRules::canonicalJson($before)),true,512,JSON_THROW_ON_ERROR);
        $actual=NonprofitDppoCloneScrubber::transform($scrubber,$before);
        foreach(['supplier','history']as$group){$original=$group==='history'?$before[$group][0]:$before[$group];$result=$group==='history'?$actual[$group][0]:$actual[$group];$target=$group==='history'?$expected[$group][0]:$expected[$group];
            foreach($original as$key=>$value){self::assertNotSame($value,$target[$key]);self::assertSame($target[$key],$result[$key]);}
        }
    }
    public function testTypedFinancialAndPeriodFieldsSurviveIdentityDictionaryCollisions():void
    {
        $pseudonymizer=new Pseudonymizer('synthetic-dppo-typed-values');$pseudonymizer->dictionary()->add('90000000','11111111');$pseudonymizer->dictionary()->add('2026-01-01','2027-02-02');
        $hash=str_repeat('9',64);$pseudonymizer->dictionary()->add($hash,str_repeat('8',64));
        $before=['amount'=>'90000000','saving_cents'=>'90000000','reduction_kc'=>'90000000','effective_from'=>'2026-01-01','record_hash'=>$hash,'account_code'=>'90000000','ic'=>'90000000','evidence'=>'90000000'];
        $actual=NonprofitDppoCloneScrubber::transform(new TextScrubber($pseudonymizer),$before);
        foreach(['amount','saving_cents','reduction_kc','effective_from','record_hash','account_code']as$key)self::assertSame($before[$key],$actual[$key]);
        self::assertNotSame($before['ic'],$actual['ic']);self::assertSame('11111111',$actual['evidence']);
    }

}
