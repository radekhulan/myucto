<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Repository\Property\Payments\{DebtRepository,PaymentRepository,ReliefCorrectionRepository};
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Charges\{ChargePreviewService};
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Payments\{PaymentException,PaymentSourceProof,PaymentLocks};
trait PaymentCorrectionFixture
{
    private function settledPaymentFixture(string $paid,string $extra):array
    {
        $f=$this->f;$issuance=$this->paymentFixtureContainer()->get(IssuanceRepository::class);$request=$f['issue_request'];$preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$request);
        $batch=$issuance->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'initial'],$f['actor']);$batch=$issuance->post($f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
        $charge=array_values(array_filter($batch['charges'],fn($c)=>$c['unit_id']===$f['units']['G12']))[0];
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? AND component_id=?');$q->execute([$f['supplier'],$charge['id'],$f['components']['ADMIN']]);$line=(int)$q->fetchColumn();
        $debt=(new DebtRepository($this->db))->register($f['supplier'],['charge_id'=>$charge['id'],'expected_version'=>2,'principal_line_ids'=>[$line],'title_ref'=>'Synthetic separate administration debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding'],'damages_for'=>null],'debt',$f['actor']);
        $bank=$this->correctionBank(bcdiv(bcadd($paid,$extra,0),'100',2));$debtor=$charge['snapshot']['liability_groups'][0]['members'][0];
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>$paid,'accounting_date'=>'2026-02-01','reason'=>'Synthetic payment','debts'=>[$debt['id']],
            'payment'=>['payer_id'=>$debtor,'debtor_id'=>$debtor,'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt['id']],'evidence'=>'Synthetic debtor disposition']],
            'credit_entitlement'=>['unit_id'=>$charge['unit_id'],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic third person credit title','due_date'=>'2026-03-01','third_party'=>self::syntheticThirdParty()]];
        $this->documentRefundAccount($f['clients'][1]);
        $operation=$this->correctionPayments()->apply($f['supplier'],$request,$this->correctionPayments()->preview($f['supplier'],$request)['preview_hash'],'payment',$f['actor']);
        if($extra!=='0'){$creditRequest=$request;$creditRequest['source']['expected_version']=1;$creditRequest['amount_cents']=$extra;$creditRequest['debts']=[];unset($creditRequest['payment']);$credit=$this->correctionPayments()->apply($f['supplier'],$creditRequest,$this->correctionPayments()->preview($f['supplier'],$creditRequest)['preview_hash'],'separate-credit',$f['actor']);$operation['credit_id']=$credit['credit_id'];}
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=? AND charge_line_id=?');$q->execute([$f['supplier'],$operation['id'],$line]);$allocation=(int)$q->fetchColumn();
        return ['charge'=>$charge,'line_id'=>$line,'allocation_id'=>$allocation,'debt'=>$debt,'bank'=>$bank,'operation'=>$operation,'total_claims'=>array_reduce($batch['charges'],fn($sum,$c)=>bcadd($sum,$c['amount_cents'],0),'0')];
    }
    private function negativePaymentCorrection(array $base,string $admin,string $relief):array
    {
        $f=$this->f;$version=self::chargeRequest($f);$version['expected_revision']=1;foreach($version['rates'] as &$rate)if($rate['component_id']===$f['components']['ADMIN'])$rate['amount']=$admin;unset($rate);
        $preview=(new ChargePreviewService($this->db))->preview($f['supplier'],$version);$saved=(new ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$version,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'corrected-version'],$f['actor']);
        $request=$f['issue_request'];$request['version_id']=$saved['id'];$request['issue_mode']='difference';$request['accounting_date']='2026-02-02';$request['issued_at']='2026-02-02T10:00:00+01:00';$request['units']=array_values(array_filter($request['units'],fn($u)=>$u['unit_id']===$base['charge']['unit_id']));$request['units'][0]['expected_revision']=1;$request['units'][0]['previous_charge_id']=$base['charge']['id'];
        $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$request);$batch=$this->paymentFixtureContainer()->get(IssuanceRepository::class)->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'negative'],$f['actor']);$charge=$batch['charges'][0];
        $q=$this->db->pdo()->prepare('SELECT id FROM unit_charge_lines WHERE supplier_id=? AND charge_id=?');$q->execute([$f['supplier'],$charge['id']]);$line=(int)$q->fetchColumn();
        return ['adjustment_charge_id'=>$charge['id'],'expected_version'=>1,'accounting_date'=>'2026-02-02','reason'=>'Synthetic reduction and paid relief','anchors'=>[['adjustment_line_id'=>$line,'original_line_id'=>$base['line_id'],'amount_cents'=>ltrim($charge['amount_cents'],'-'),'evidence'=>'Synthetic precise original legal debt anchor']],
            'reliefs'=>$relief==='0'?[]:[['allocation_id'=>$base['allocation_id'],'amount_cents'=>$relief]],'credit_entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic creditor title independent of payer','due_date'=>'2026-03-01']];
    }
    /** Věřitel kreditu mimo vlastníky jednotky potřebuje doložený titul. */
    private static function syntheticThirdParty():array{return ['basis'=>'other_documented','evidence'=>'Synthetic documented creditor title'];}
    /** Účet příjemce vratky musí být u oprávněné osoby ručně doložený. */
    private function documentRefundAccount(int $client,string $account='1000000005',string $bank='0100'):void
    {
        $this->db->pdo()->prepare('INSERT IGNORE INTO client_bank_accounts (supplier_id,client_id,account_number,bank_code,account_key,bank_key,source_manual) VALUES (?,?,?,?,?,?,1)')
            ->execute([$this->f['supplier'],$client,$account,$bank,ltrim($account,'0'),$bank]);
    }
    private function correctionPayments():PaymentRepository{return $this->paymentFixtureContainer()->get(PaymentRepository::class);}
    private function correctionWriter():ReliefCorrectionRepository{return $this->paymentFixtureContainer()->get(ReliefCorrectionRepository::class);}
    private function correctionBank(string $amount):int
    {
        $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK','2026-02-01')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01',?,'CZK','1000000005','0100')")->execute([$statement,$amount]);$id=(int)$pdo->lastInsertId();
        $this->paymentFixtureContainer()->get(PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>'379.900','side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>'2026-02-01','posted_by'=>$this->f['actor']]);return $id;
    }
    private function paymentFixtureContainer():\DI\Container{$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);return $container;}
}
