<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\{PropertyRegistryRepository,PropertyReadRepository};
use MyInvoice\Repository\Property\Receivables\{PartyEventRepository,FinancialHistoryRepository};
use MyInvoice\Service\Property\Receivables\PropertyLiabilityProjection;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture};
use PHPUnit\Framework\TestCase;
final class PropertyLiabilityTransferTest extends TestCase
{
    use ChargeIssuanceFixture,PaymentCorrectionFixture,\MyInvoice\Tests\Support\ReceivableFixture;
    private Connection $db;private array $f;
    protected function setUp():void{$this->db=Bootstrap::buildContainer()->get(Connection::class);$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);}
    protected function tearDown():void{if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testActualTwoTransfersPreserveDebtAndResolverAcceptsOnlyCurrentDebtor():void
    {
        $base=$this->settledPaymentFixture('1','5000');$f=$this->f;$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$b=$f['clients'][2];$c=$f['clients'][3];$repo=new PartyEventRepository($this->db);
        $first=$this->request($base,$b,1,null,'2026-02-02T10:00:00+01:00',[$old]);$preview=$repo->preview($f['supplier'],$first);$event=$repo->confirm($f['supplier'],$first,$preview['preview_hash'],'transfer-b',$f['actor']);
        self::assertSame($event['id'],$repo->confirm($f['supplier'],$first,$preview['preview_hash'],'transfer-b',$f['actor'])['id']);
        $second=$this->request($base,$c,2,$event['id'],'2026-02-03T10:00:00+01:00',[$old,$b]);$preview=$repo->preview($f['supplier'],$second);$secondEvent=$repo->confirm($f['supplier'],$second,$preview['preview_hash'],'transfer-c',$f['actor']);
        $history=(new FinancialHistoryRepository($this->db))->read($f['supplier'],true);$projection=(new PropertyLiabilityProjection($this->db))->at($f['supplier'],array_values($history['debts']),'2026-02-04T10:00:00+01:00','2027-01-01T00:00:00Z',true)[$base['debt']['id']];
        self::assertSame('99999',$projection['remaining_cents']);self::assertCount(3,$projection['groups']);self::assertSame($secondEvent['id'],$projection['event_id']);
        $bank=$this->bankAt('2026-02-04','10.00');$request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'1000','accounting_date'=>'2026-02-04','reason'=>'Synthetic guarantor payment','debts'=>[$base['debt']['id']],
            'payment'=>['payer_id'=>$old,'debtor_id'=>$c,'received_at'=>'2026-02-04T10:00:00+01:00','third_party'=>['basis'=>'guarantor','evidence'=>'Synthetic proven guarantee'],'choice'=>['claim_ids'=>[$base['debt']['id']],'evidence'=>'Synthetic debtor direction']]];
        $payment=$this->correctionPayments();$bad=$request;$bad['payment']['payer_id']=$f['clients'][4];
        try{$payment->preview($f['supplier'],$bad);self::fail('Unproven third party claimed guarantor capacity');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_guarantee_unproven',$e->errorCode);}
        $preview=$payment->preview($f['supplier'],$request);$done=$payment->apply($f['supplier'],$request,$preview['preview_hash'],'guarantor-payment',$f['actor']);
        self::assertSame($base['debt']['id'],$done['snapshot']['allocations'][0]['debt_id']);
        self::assertSame($c,$done['snapshot']['allocations'][0]['debtor_id']);
        $history=(new FinancialHistoryRepository($this->db))->read($f['supplier'],true);$projection=(new PropertyLiabilityProjection($this->db))->at($f['supplier'],array_values($history['debts']),'2026-02-05T00:00:00+01:00','2027-01-01T00:00:00Z',true)[$base['debt']['id']];
        self::assertSame('98999',$projection['remaining_cents']);self::assertSame('5000',$history['credits'][$base['operation']['credit_id']]['snapshot']['amount_cents']);
        self::assertSame([$f['clients'][1]],array_column($history['credits'][$base['operation']['credit_id']]['snapshot']['parties'],'id'));
        $bad=$second;$bad['reason']='Changed';try{$repo->confirm($f['supplier'],$bad,$preview['preview_hash'],'transfer-c',$f['actor']);self::fail('Changed token accepted');}catch(PropertyException $e){self::assertSame('property_receivable_idempotency_conflict',$e->errorCode);}
        $payment->reverse($f['supplier'],$done['id'],2,'2026-02-06','Synthetic later payment withdrawal','later-undo',$f['actor']);
        self::assertSame('reversed',$payment->operation($f['supplier'],$done['id'])['status']);
    }
    public function testConfirmedTransferRequiresTransferorAsGuarantorInPreviousScope():void
    {
        $base=$this->settledPaymentFixture('1000','0');
        $f=$this->f;
        $old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];
        $repo=new PartyEventRepository($this->db);

        $withoutGuarantee=$this->request($base,$f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[]);
        try{
            $repo->preview($f['supplier'],$withoutGuarantee);
            self::fail('Transfer released the transferor without § 1186 guarantee.');
        }catch(PropertyException $e){
            self::assertSame('property_receivable_transferor_guarantee_required',$e->errorCode);
            self::assertSame([$old],$e->details['members']);
        }

        $reduced=$withoutGuarantee;
        $reduced['debts'][0]['groups'][]=['group_key'=>'guarantor-'.$old,'role'=>'guarantor','mode'=>'individual','basis'=>'documented_title','members'=>[$old],
            'extent'=>['num'=>'1','den'=>'2'],'evidence_ref'=>'Synthetic liability title','legal_reason'=>'Synthetic partial guarantee','valid_from'=>'2026-02-02T10:00:00+01:00','valid_to'=>null];
        try{
            $repo->preview($f['supplier'],$reduced);
            self::fail('Transferor guarantee narrower than the transferred debt was accepted.');
        }catch(PropertyException $e){
            self::assertSame('property_receivable_transferor_guarantee_scope',$e->errorCode);
        }

        $full=$this->request($base,$f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);
        $event=$repo->confirm($f['supplier'],$full,$repo->preview($f['supplier'],$full)['preview_hash'],'guaranteed-transfer',$f['actor']);
        $roles=[];
        foreach($event['snapshot']['debts'][0]['groups'] as $group)$roles[$group['role']]=$group['members'];
        self::assertSame(['debtor'=>[$f['clients'][2]],'guarantor'=>[$old]],$roles);
    }
    public function testReversalCannotRestoreAmountAlreadyUsedByTwoSuccessionTitles():void
    {
        $base=$this->settledPaymentFixture('1000','0');$f=$this->f;$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$repo=new PartyEventRepository($this->db);
        $first=$this->request($base,$f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);$event=$repo->confirm($f['supplier'],$first,$repo->preview($f['supplier'],$first)['preview_hash'],'first',$f['actor']);
        $second=$this->request($base,$f['clients'][3],2,$event['id'],'2026-02-03T10:00:00+01:00',[$old,$f['clients'][2]]);$repo->confirm($f['supplier'],$second,$repo->preview($f['supplier'],$second)['preview_hash'],'second',$f['actor']);
        $before=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        try{$this->correctionPayments()->reverse($f['supplier'],$base['operation']['id'],2,'2026-02-04','Synthetic withdrawal','blocked-reverse',$f['actor']);self::fail('Pinned pre-transfer payment was reversed and restored an unproven successor balance.');}
        catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_party_history_in_use',$e->errorCode);}
        self::assertSame($before,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        self::assertSame('posted',$this->correctionPayments()->operation($f['supplier'],$base['operation']['id'])['status']);
    }
    public function testKnowledgeTimeAndIssuedCertificateSurviveLaterRecordedTransfer():void
    {
        $base=$this->settledPaymentFixture('1000','0');$f=$this->f;$old=$base['charge']['snapshot']['liability_groups'][0]['members'][0];$known=PartyEventRepository::now();
        $certificates=new \MyInvoice\Repository\Property\Receivables\DebtCertificateRepository($this->db);
        $request=['unit_id'=>$base['charge']['unit_id'],'as_of'=>'2026-02-04T00:00:00+01:00','known_at'=>$known,'requester_id'=>$old,'authority_evidence'=>'Synthetic request title','purpose'=>'Synthetic disclosed history','coverage_acknowledgement'=>'Recorded S3/S4 only; settlement/import not covered'];
        $preview=$certificates->preview($f['supplier'],$request);$certificate=$certificates->issue($f['supplier'],$request,$preview['preview_hash'],'certificate-before',$f['actor']);
        $repo=new PartyEventRepository($this->db);$transfer=$this->request($base,$f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);$event=$repo->confirm($f['supplier'],$transfer,$repo->preview($f['supplier'],$transfer)['preview_hash'],'transfer',$f['actor']);
        $projector=new \MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($this->db);
        $before=$projector->at($f['supplier'],$request['as_of'],$known,['unit_id'=>$base['charge']['unit_id']],true);$after=$projector->at($f['supplier'],$request['as_of'],PartyEventRepository::now(),['unit_id'=>$base['charge']['unit_id']],true);
        self::assertNull($before['claims'][0]['liability']['event_id']);self::assertSame($event['id'],$after['claims'][0]['liability']['event_id']);
        self::assertSame($before['totals'],$after['totals']);self::assertSame($certificate,$certificates->find($f['supplier'],$certificate['id'],true));
        self::assertSame($certificate['id'],$certificates->issue($f['supplier'],$request,$preview['preview_hash'],'certificate-before',$f['actor'])['id']);
        $bad=$request;$bad['purpose']='Changed';try{$certificates->issue($f['supplier'],$bad,$preview['preview_hash'],'certificate-before',$f['actor']);self::fail('Changed certificate token accepted');}catch(PropertyException $e){self::assertSame('property_receivable_idempotency_conflict',$e->errorCode);}
        $foreign=(int)$this->db->pdo()->query('SELECT id FROM supplier WHERE id<>'.$f['supplier'].' ORDER BY id LIMIT 1')->fetchColumn();try{$certificates->find($foreign,$certificate['id']);self::fail('Foreign certificate leaked');}catch(PropertyException $e){self::assertSame('property_receivable_certificate_not_found',$e->errorCode);}
        try{$this->db->pdo()->prepare('UPDATE property_claim_party_events SET recorded_at=recorded_at WHERE supplier_id=? AND id=?')->execute([$f['supplier'],$event['id']]);self::fail('Immutable event updated');}catch(\PDOException $e){self::assertSame(1644,(int)$e->errorInfo[1]);}
        try{$this->db->pdo()->prepare('DELETE FROM property_debt_certificates WHERE supplier_id=? AND id=?')->execute([$f['supplier'],$certificate['id']]);self::fail('Immutable certificate deleted');}catch(\PDOException $e){self::assertSame(1644,(int)$e->errorInfo[1]);}
        try{$this->db->pdo()->prepare("INSERT INTO property_claim_party_members(supplier_id,event_id,debt_id,group_key,role,client_id) VALUES (?,?,?,'foreign','debtor',?)")->execute([$foreign,$event['id'],$base['debt']['id'],$old]);self::fail('Cross-tenant party link inserted');}catch(\PDOException $e){self::assertSame(1452,(int)$e->errorInfo[1]);}

    }
}
