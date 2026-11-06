<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\Funds\{FundingRead,FundingSourceProof};
trait FundingFixture
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private \DI\Container $container;
    protected function setUp():void
    {
        $this->fundingConnect();$this->db->pdo()->beginTransaction();$this->f=$this->fundingBase();
    }
    private function fundingConnect():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    /** Firma SVJ s předpisy a účty fondů, nad kterou testy fondů staví. */
    private function fundingBase():array
    {
        $this->f=$this->issuanceFixture($this->db);
        $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);foreach(['955.100','324.110','324.200','549.900','549.910','518.200','649.100','649.200'] as $code){if($accounts->findByCode($this->f['supplier'],$code))continue;$parent=$accounts->findByCode($this->f['supplier'],substr($code,0,3));$accounts->insert($this->f['supplier'],['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
        return $this->f;
    }
    protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function fundingOpening():void
    {
        $items=[];foreach($this->f['units'] as $code=>$unit){$share=$this->f['states'][$code]['attributes']['share'];$amount=bcdiv(bcmul('30000000',$share['num'],0),$share['den'],0);$items[]=['unit_id'=>$unit,'service_id'=>null,'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2030-12-31','occurrence_ref'=>'2026','case_ref'=>'long-'.$code,'kind'=>'advance','title_ref'=>'Synthetic signed long advance takeover','evidence_ref'=>'Synthetic reconciled paid advance','components'=>[['key'=>'long','role'=>'long_term_advance','account_code'=>'955.100','amount_cents'=>$amount]],'advance_state'=>['purpose_ref'=>'Synthetic repairs and improvements 2026','settlement_due_on'=>'2030-12-31','prescribed_cents'=>$amount,'paid_cents'=>$amount,'used_cents'=>'0','unpaid_cases'=>[]]];}
        $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic initial long advances','items'=>$items,'other_balances'=>[['account_code'=>'221','side'=>'debit','amount_cents'=>'30000000','dimensions'=>[]]]];$owner=$this->container->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$owner->apply($this->f['supplier'],$request,$owner->preview($this->f['supplier'],$request)['preview_hash'],'funding-opening',$this->f['actor']);
    }
    private function fundingIssue():array
    {
        $request=$this->f['issue_request'];foreach($request['accounts'] as &$account){if($account['component_id']===$this->f['components']['LONG'])$account['counter_account']='955.100';if($account['component_id']===$this->f['components']['ADMIN'])$account['counter_account']='324.200';if($account['component_id']===$this->f['components']['WATER'])$account['counter_account']='324.110';}unset($account);$owner=$this->container->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);$preview=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($this->f['supplier'],$request);$batch=$owner->issue($this->f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'funding-annual'],$this->f['actor']);return $owner->post($this->f['supplier'],$batch['id'],array_map(fn($charge)=>['id'=>$charge['id'],'row_version'=>1],$batch['charges']),$this->f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
    }
    private function fundingReduceIssuedLong(array $batch):void
    {
        $f=$this->f;$request=self::chargeRequest($f);$request['expected_revision']=1;foreach($request['rates'] as &$rate)if($rate['component_id']===$f['components']['LONG'])$rate['amount']='30000';unset($rate);$preview=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($this->db))->preview($f['supplier'],$request);$version=(new \MyInvoice\Repository\Property\Charges\ChargeRepository($this->db))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'reduced-long'],$f['actor']);$request=$batch['request'];$request['version_id']=$version['id'];$request['issue_mode']='difference';$request['accounting_date']='2026-03-01';$request['issued_at']='2026-03-01T10:00:00+01:00';$byUnit=array_column($batch['charges'],null,'unit_id');foreach($request['units'] as &$unit){$unit['expected_revision']=1;$unit['previous_charge_id']=$byUnit[$unit['unit_id']]['id'];}unset($unit);$preview=(new \MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService($this->db))->preview($f['supplier'],$request);$owner=$this->container->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class);$next=$owner->issue($f['supplier'],['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'reduce-issued-long'],$f['actor']);$owner->post($f['supplier'],$next['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$next['charges']),$f['actor']);
    }
    private function fundingKey(string $date,bool $admin=false):array {return ['mode'=>'at_accounting_date','at'=>FundingSourceProof::instant($date),'method'=>$admin?'equal':'share','legal_basis'=>$admin?'own_administration_equal':'management_share','evidence_ref'=>'Synthetic explicit statutory unit key'];}
    private function fundingRequest(string $kind,string $date,string $amount='1'):array {return ['kind'=>$kind,'house_id'=>$this->f['house']['id'],'accounting_date'=>$date,'expected_revision'=>(new FundingRepository($this->db))->list($this->f['supplier'],$this->f['house']['id'])['revision'],'evidence_ref'=>'Synthetic signed specific authorization','reason'=>'Synthetic funding '.$kind,'amount_cents'=>$amount];}
    private function fundingApply(array $request,string $token):array {$owner=new FundingRepository($this->db);$preview=$owner->preview($this->f['supplier'],$request,$this->f['actor']);return $owner->apply($this->f['supplier'],$request,$preview['preview_hash'],$token,$this->f['actor']);}
    private function fundingCost(string $date,string $code,string $amount):int
    {
        $s=$this->f['supplier'];$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($s,$date);$kind=$this->container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->createValue($s,$cat['activity_kind']['id'],['code'=>'FUND-'.str_replace('.','',$code),'name'=>'Synthetic property management'],$this->f['actor']);$dims=[$cat['activity']['id']=>$cat['activity']['values']['main'],$cat['activity_kind']['id']=>$kind['id'],$cat['tax_regime']['id']=>$cat['tax_regime']['values']['subject']];$origins=array_fill_keys(array_keys($dims),['origin'=>'explicit','ref'=>null]);
        $entry=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($s,'manual',null,[['account_code'=>$code,'side'=>'debit','amount'=>$amount,'dimensions'=>$dims,'dimension_origins'=>$origins],['account_code'=>'321','side'=>'credit','amount'=>$amount]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);$q=$this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='debit'");$q->execute([$s,$entry]);return(int)$q->fetchColumn();
    }
    private function fundingDraw(string $date,string $classification,string $amount,int $cost,string $sourceKind='opening'):array
    {
        $r=$this->fundingRequest('draw',$date,$amount)+['classification'=>$classification,'ownership'=>'owners_common_parts','cost_line_id'=>$cost,'key'=>$this->fundingKey($date,$classification==='administration'),'other_coverage'=>[],'sources'=>[]];$source=new FundingSourceProof($this->db);$key=$source->registry($this->f['supplier'],$this->f['house']['id'],$date,$r['key'],$amount);$available=(new FundingRead($this->db))->at($this->f['supplier'],$this->f['house']['id'],$date)['sources'];foreach($key['calculation']['lines'] as $allocation){foreach($available as $candidate)if($candidate['unit_id']===$allocation['unit_id']&&$candidate['source']['kind']===$sourceKind&&($classification==='administration'?$candidate['account_code']==='324.200':str_starts_with($candidate['account_code'],'955'))){$r['sources'][]=['source'=>$candidate['source'],'amount_cents'=>$allocation['amount_cents'],'purpose_evidence'=>'Synthetic explicit source purpose compatibility'];break;}}return$r;
    }
    private function fundingBank(string $date,string $amount):int {$pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES(?,'Synthetic funding',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES(?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);return(int)$pdo->lastInsertId();}
}
