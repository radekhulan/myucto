<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity as Identity;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\PropertyException;
use PHPUnit\Framework\TestCase;
final class IssuanceCaseIdentityTest extends TestCase
{
    private function identity(): array { return ['schema'=>1,'case_ref'=>'Případ Á','occurrence_ref'=>'2026-01','title_ref'=>'Synthetic contribution','decision_ref'=>'Decision A','decided_on'=>'2025-12-01','evidence_ref'=>'Synthetic approved decision']; }
    private function charge(int $id=1,int $supplier=10,int $unit=20,string $source='A',?array $identity=null): array
    {
        return ['id'=>$id,'supplier_id'=>$supplier,'unit_id'=>$unit,'source_key'=>$source,'revision_no'=>1,'previous_charge_id'=>null,'row_version'=>1,'status'=>'draft',
            'period_from'=>'2026-01-01','period_to'=>'2026-01-31','snapshot'=>$identity===null?[]:['case_identity'=>$identity]];
    }
    private function request(): array { return ['source_key'=>'B','period_from'=>'2026-01-01','period_to'=>'2026-01-31','case_identity'=>$this->identity()]; }
    public function testNfcCaseFoldAndWhitespaceDoNotErasePunctuationOrDiacritics(): void
    {
        $a=$this->identity();$b=$a;$b['case_ref']="  PŘÍPAD  A\u{0301}  ";
        self::assertSame(Identity::key(10,20,$a),Identity::key(10,20,$b));
        $b['case_ref']='Pripad A';self::assertNotSame(Identity::key(10,20,$a),Identity::key(10,20,$b));
        $b['case_ref']='Případ-Á';self::assertNotSame(Identity::key(10,20,$a),Identity::key(10,20,$b));
        $b['case_ref']="A\u{0301}";self::assertSame('Á',Identity::normalize($b)['case_ref']);
    }
    public function testTenantAndUnitAreIndependentIdentityScopes(): void
    {
        $families=Identity::families([$this->charge(identity:$this->identity())]);
        self::assertSame([],Identity::assertInitial(11,$this->request(),['unit_id'=>20],$families));
        self::assertSame([],Identity::assertInitial(10,$this->request(),['unit_id'=>21],$families));
    }
    public function testLegacyContinuationPreservesAbsentMetadataAndOverlapStillNeedsEvidence(): void
    {
        $old=$this->charge();$before=serialize($old);$families=Identity::families([$old]);
        $r=$this->request();unset($r['case_identity']);$r['source_key']='A';
        Identity::assertContinuation(10,$r,['unit_id'=>20],$families);
        self::assertSame($before,serialize($old));self::assertSame('legacy_unclassified',$families[0]['identity_status']);
        $this->expectException(IssuanceException::class);$this->expectExceptionMessage('Doložte odlišení');
        $r=$this->request();Identity::assertInitial(10,$r,['unit_id'=>20],$families);
    }
    public function testAllRootRevisionsMustKeepImmutableIdentity(): void
    {
        $root=$this->charge(identity:$this->identity());$next=$root;$next['id']=2;$next['revision_no']=2;$next['previous_charge_id']=1;
        $next['snapshot']['case_identity']['title_ref']='Changed';
        $this->expectException(IssuanceException::class);Identity::families([$root,$next]);
    }
    public function testDistinctDecisionOutsideIntervalDoesNotNeedEvidenceButSameDecisionOccurrenceDoes(): void
    {
        $old=$this->charge(identity:$this->identity());$families=Identity::families([$old]);$request=$this->request();
        $request['period_from']='2026-02-01';$request['period_to']='2026-02-28';$request['case_identity']['case_ref']='Independent';
        try {Identity::assertInitial(10,$request,['unit_id'=>20],$families);self::fail();}
        catch(IssuanceException $e){self::assertSame(['same_decision_occurrence'],$e->details['case_candidates'][0]['reasons']);}
        $request['case_identity']['decision_ref']='Another decision';self::assertSame([],Identity::assertInitial(10,$request,['unit_id'=>20],$families));
    }
    public function testOpaqueNumericSourceKeysRemainStringsDuringRepeatedNormalization(): void
    {
        $raw=['against_source_keys'=>['123','ABC','123'],'legal_reason'=>'Separate title','evidence_ref'=>'Synthetic deed'];
        $once=Identity::distinction($raw);self::assertSame(['123','ABC'],$once['against_source_keys']);
        self::assertSame($once,Identity::distinction($once));
    }
    public function testUnknownIdentityFieldsAndInvalidDateAreRejected(): void
    {
        $this->expectException(PropertyException::class);Identity::normalize($this->identity()+['trusted'=>true]);
    }
}