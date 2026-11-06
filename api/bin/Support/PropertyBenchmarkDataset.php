<?php

declare(strict_types=1);
namespace MyInvoice\Benchmark;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\{PropertyProfileRepository,PropertyRegistryRepository};
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Property\Charges\{ChargeCalculator,ChargePreviewService};
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use PDO;
use Psr\Container\ContainerInterface;
final class PropertyBenchmarkDataset
{
    public function __construct(private readonly Connection $db,private readonly ContainerInterface $container){}
    public function create(int $count,string $start,string $label):array
    {
        if($count<1||$count>400)throw new \InvalidArgumentException('Unit count must be1..400');
        $p=$this->db->pdo();$actor=(int)$p->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if($actor<1)throw new \RuntimeException('Synthetic CI user seed required');
        $p->exec("INSERT INTO supplier(company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id) SELECT 'Synthetic property benchmark','Synthetic1','Synthetic','11000',country_id,'benchmark@example.test',default_currency_id,default_vat_rate_id FROM supplier ORDER BY id LIMIT 1");$supplier=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'1900-01-01','cz_504')")->execute([$supplier]);
        $instant=$start.'T00:00:00+01:00';
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,$start);
        (new PropertyProfileRepository($this->db))->save($supplier,['expected_revision'=>0,'effective_from'=>$instant,'enabled'=>true,'reason'=>'Synthetic benchmark SVJ','title_ref'=>'Synthetic documented deed','idempotency_key'=>'profile'],$actor);
        $registry=new PropertyRegistryRepository($this->db);
        $request=static fn(string $code,array $state):array=>['code'=>$code,'expected_revision'=>0,'effective_from'=>$instant,'status'=>'confirmed','state'=>$state,'reason'=>'Synthetic benchmark registry','title_ref'=>'Synthetic documented deed','idempotency_key'=>$code];
        $house=$registry->create($supplier,'house',$request('HOUSE',['name'=>'Synthetic '.$label,'active'=>true,'address'=>['street'=>'Synthetic1','city'=>'Synthetic','postal_code'=>'11000','country'=>'CZ'],'declaration_ref'=>'Synthetic deed','share_scope'=>'HOUSE','registry_complete'=>true]),$actor);
        $service=$registry->create($supplier,'service',$request('WATER',['name'=>'Synthetic water','active'=>true,'measure'=>'m3','measure_label'=>'']),$actor);
        $country=(int)$p->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();$currency=(int)$p->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $client=$p->prepare('INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES(?,?,?,?,?,?,?,?)');
        $units=[];$states=[];
        for($n=1;$n<=$count;$n++){
            $members=[];for($j=0;$j<($n%20===0?2:1);$j++){$client->execute([$supplier,'Synthetic owner '.$n.'-'.$j,'Synthetic','Synthetic','11000',$country,'owner'.$n.'-'.$j.'@example.test',$currency]);$members[]=(int)$p->lastInsertId();}
            $code=sprintf('U%03d',$n);$groups=[['group_key'=>'OWNERS','kind'=>count($members)===2?'marital_community':'single','share'=>['num'=>'1','den'=>'1'],'members'=>$members,'title_ref'=>'Synthetic deed']];
            $state=['unit_no'=>$code,'kind'=>'apartment','active'=>true,'attributes'=>['share'=>['num'=>'1','den'=>(string)$count],'floor_area'=>['num'=>(string)(40+$n%40),'den'=>'1'],'persons'=>['num'=>(string)(1+$n%4),'den'=>'1']],'ownerships'=>$groups,'roles'=>[],'identifiers'=>[['kind'=>'vs','value'=>(string)(10000+$n),'payment_scope'=>'supplier','source'=>'manual','title_ref'=>'Synthetic payment title']],'services'=>[$service['id']]];
            $unit=$registry->create($supplier,'unit',$request($code,$state)+['parent_id'=>$house['id']],$actor);$units[]=$unit['id'];$states[$unit['id']]=$state;
        }
        $charges=new ChargeRepository($this->db);$components=[];
        foreach(['LONG'=>'long_term_advance','ADMIN'=>'admin_advance','WATER'=>'service_advance'] as $code=>$kind)$components[$code]=$charges->createComponent($supplier,['code'=>$code,'name'=>'Synthetic '.$code,'kind'=>$kind,'service_id'=>$code==='WATER'?$service['id']:null,'effective_at'=>$instant,'account_code'=>'','idempotency_key'=>$code],$actor)['id'];
        $rates=[];foreach([['LONG','share',(string)($count*500),'total_budget','management_share'],['ADMIN','equal','1000','rate_per_basis','own_administration_equal'],['WATER','unit','300','rate_per_basis','service_decision']] as [$code,$method,$amount,$mode,$legal])$rates[]=['component_id'=>$components[$code],'unit_ids'=>$units,'method'=>$method,'amount'=>$amount,'mode'=>$mode,'rounding'=>ChargeCalculator::ROUNDING,'legal_basis'=>$legal,'scope_ref'=>'Synthetic monthly decision '.$code,'correction'=>false,'correction_reason'=>''];
        $versionRequest=['house_id'=>$house['id'],'expected_revision'=>0,'effective_from'=>$instant,'effective_to'=>null,'basis_at'=>$instant,'frequency'=>'monthly','basis_policy'=>'fixed_registry_snapshot','period_policy'=>'no_automatic_proration','decision_ref'=>'Synthetic monthly decision','decided_at'=>(new \DateTimeImmutable($instant))->modify('-1 day')->format('c'),'reason'=>'Synthetic monthly benchmark','rates'=>$rates];
        $versions=[];$historical=$start<'2020-07-01';
        if($historical){$versionRequest['effective_to']='2020-07-01T00:00:00+02:00';foreach($versionRequest['rates'] as &$rate){$rate['legal_basis']='historical_documented';$rate['scope_ref']='Synthetic contemporary2015 approved deed and applicable-law assessment for '.$rate['component_id'];}unset($rate);}
        $preview=(new ChargePreviewService($this->db))->preview($supplier,$versionRequest);$version=$charges->saveVersion($supplier,['request'=>$versionRequest,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'version'],$actor);$versions[]=['from'=>$start,'id'=>$version['id']];
        if($historical){$versionRequest['effective_from']='2020-07-01T00:00:00+02:00';$versionRequest['basis_at']=$versionRequest['effective_from'];$versionRequest['effective_to']=null;$versionRequest['expected_revision']=1;$versionRequest['rates']=$rates;$versionRequest['decided_at']='2020-06-01T12:00:00+02:00';$preview=(new ChargePreviewService($this->db))->preview($supplier,$versionRequest);$modern=$charges->saveVersion($supplier,['request'=>$versionRequest,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'version-modern'],$actor);$versions[]=['from'=>'2020-07-01','id'=>$modern['id']];}
        return compact('supplier','actor','house','service','units','states','components','versions','start','label');
    }
    public function issueMonth(array $f,string $month):array
    {
        $supplier=$f['supplier'];$actor=$f['actor'];$first=$month.'-01';$end=(new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');$instant=(new \DateTimeImmutable($first,new \DateTimeZone('Europe/Prague')))->format('c');$year=(int)substr($month,0,4);$periods=new AccountingPeriodRepository($this->db);
        if($periods->findByYear($supplier,$year)===null)$periods->create($supplier,$year,$year.'-01-01',$year.'-12-31');
        $units=[];foreach($f['units'] as $id){$groups=[];foreach($f['states'][$id]['ownerships'] as $g)$groups[]=['group_key'=>$g['group_key'],'role'=>'debtor','mode'=>$g['kind']==='marital_community'?'solidary':'individual','basis'=>$g['kind']==='marital_community'?'sjm_713':'documented_title','members'=>$g['members'],'extent'=>$g['share'],'evidence_ref'=>'Synthetic assessed deed','legal_reason'=>'Synthetic explicit debtor assessment','valid_from'=>$f['start'].'T00:00:00+01:00','valid_to'=>null];$units[]=['unit_id'=>$id,'expected_revision'=>0,'previous_charge_id'=>null,'liability_groups'=>$groups,'notices'=>[['component_id'=>$f['components']['WATER'],'notice'=>['mode'=>'initial','title_ref'=>'Synthetic monthly advance decision']]]];}
        $accounts=[];foreach($f['components'] as $code=>$id)$accounts[]=['component_id'=>$id,'receivable_account'=>'315','counter_account'=>match($code){'LONG'=>'955.100','ADMIN'=>'324.200',default=>'324.110'},'basis_ref'=>'Synthetic monthly accounting decision','settlement_due_on'=>$code==='LONG'?($year+2).'-12-31':null,'dimensions'=>[]];
        $versionId=null;foreach($f['versions'] as $candidate)if($candidate['from']<=$first)$versionId=$candidate['id'];if($versionId===null)throw new \RuntimeException('No native dated version');
        $request=['version_id'=>$versionId,'source_key'=>'BENCH-MONTH-'.$month,'title'=>'prescription','issue_mode'=>'initial','period_from'=>$first,'period_to'=>$end,'claim_origin_at'=>$instant,'issued_at'=>$first.'T10:00:00+01:00','accounting_date'=>$first,'due_date'=>$month.'-15','reason'=>'Synthetic real monthly issuance','units'=>$units,'accounts'=>$accounts,'case_identity'=>['schema'=>1,'case_ref'=>'BENCH-MONTH','occurrence_ref'=>$month,'title_ref'=>'Synthetic monthly prescription','decision_ref'=>'Synthetic decision','decided_on'=>$first,'evidence_ref'=>'Synthetic approved monthly schedule']];
        $read=new IssuancePreviewService($this->db);$repository=$this->container->get(IssuanceRepository::class);$start=hrtime(true);$preview=$read->preview($supplier,$request);$previewMs=(hrtime(true)-$start)/1e6;
        $issueStart=hrtime(true);$input=['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'BENCH-MONTH-'.$month];$batch=$repository->issue($supplier,$input,$actor);$issueMs=(hrtime(true)-$issueStart)/1e6;
        $postStart=hrtime(true);$posted=$repository->post($supplier,$batch['id'],array_map(static fn($row)=>['id'=>$row['id'],'row_version'=>$row['row_version']],$batch['charges']),$actor);$postMs=(hrtime(true)-$postStart)/1e6;
        if(count($posted['charges'])!==count($f['units'])||array_unique(array_column($posted['charges'],'status'))!==['posted'])throw new \RuntimeException('Native monthly posting incomplete');
        if($this->db->pdo()->inTransaction())throw new \RuntimeException('Native month left an open transaction');
        $expected=(string)(count($f['units'])*180000);if($preview['snapshot']['total_cents']!==$expected)throw new \RuntimeException('Native monthly exact total mismatch');
        return ['supplier_id'=>$supplier,'month'=>$month,'batch_id'=>$batch['id'],'units'=>count($posted['charges']),'amount_cents'=>$expected,'preview_ms'=>$previewMs,'issue_ms'=>$issueMs,'post_ms'=>$postMs,'total_ms'=>(hrtime(true)-$start)/1e6];
    }
}
