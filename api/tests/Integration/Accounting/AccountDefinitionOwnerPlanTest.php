<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Property\Opening\OpeningJournalPlan;
use MyInvoice\Service\Property\Payments\Workflow\{PaymentWorkflowJournalPlan,ClearingRevisionJournalPlan};
use MyInvoice\Service\Property\Funds\FundingJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Nonprofit\Capital\{CapitalService,CapitalException};
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitTaxAssessmentService,NonprofitTaxAssessmentException};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountDefinitionOwnerPlanTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private \DI\Container $c;
    private array $f;
    private array $ids;
    private array $catalog;
    private int $kind;
    private array $dimensions;
    protected function setUp():void
    {
        $this->c=Bootstrap::buildContainer();$this->db=$this->c->get(Connection::class);
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f=in_array($this->name(),['testFundingRegistrationUsesPolicyStartAfterFutureDeactivation','testFundingRejectsAccountInactiveAtPolicyStart','testFundingCannotConfirmAccountNotYetBornAtPolicyStart'],true)
            ? $this->nonprofitFundingFixture() : $this->issuanceFixture($this->db);
        $s=$this->f['supplier'];$chart=new ChartOfAccountsRepository($this->db);
        foreach(['315.100'=>'315','379.900'=>'379','518.007'=>'518'] as $code=>$parent)if(!$chart->findByCode($s,$code,'2026-01-01')){$a=$chart->findByCode($s,$parent,'2026-01-01');$chart->insert($s,['account_code'=>$code,'name'=>'Synthetic owner '.$code,'account_type'=>$a['account_type'],'normal_side'=>$a['normal_side'],'parent_id'=>$a['id']],'2026-01-01',$this->f['actor']);}
        $this->ids=array_column($chart->listForTenant($s,true,'2026-01-01'),'id','account_code');$definitions=new AccountDefinitionRepository($this->db);if(!$definitions->hasHistory($s,$this->ids['518.007'],true))$definitions->initializeCreated($s,$this->ids['518.007'],'2026-01-01',$this->f['actor']);
        $this->c->get(AccountingPeriodRepository::class)->create($s,2027,'2027-01-01','2027-12-31');
        $this->catalog=$this->c->get(SystemDimensionCatalog::class)->ensureForFramework($s,'2026-01-01');
        $this->db->pdo()->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'SYNTHETIC-OWNER','Synthetic accounting scope')")->execute([$this->catalog['activity_kind']['id'],$s]);$this->kind=(int)$this->db->pdo()->lastInsertId();
        $this->dimensions=[$this->catalog['activity']['id']=>$this->catalog['activity']['values']['main'],$this->catalog['activity_kind']['id']=>$this->kind,$this->catalog['tax_regime']['id']=>$this->catalog['tax_regime']['values']['exempt']];
    }
    private function nonprofitFundingFixture():array
    {
        $pdo=$this->db->pdo();$supplier=$this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$supplier]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'1900-01-01','cz_504')")->execute([$supplier]);
        $this->c->get(AccountingPeriodRepository::class)->create($supplier,2026,'2026-01-01','2026-12-31');
        (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,'2026-01-01');
        $country=(int)$pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currency=(int)$pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id)VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$supplier,'Synthetic NNO provider','Synthetic','Synthetic','10000',$country,'nno-provider@example.test',$currency]);
        $clients=[(int)$pdo->lastInsertId()];
        return compact('supplier','actor','clients');
    }
    protected function tearDown():void{if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function deactivate(string $code):void{$d=new AccountDefinitionRepository($this->db);$d->change($this->f['supplier'],$this->ids[$code],'2026-07-01',['is_active'=>false],$d->stateHash($this->f['supplier'],$this->ids[$code]),$this->f['actor'],'Synthetic future deactivation');}
    private function balance():array{return [['account_code'=>'221','side'=>'debit','amount'=>'10.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'10.00']];}
    public function testOpeningPlanUsesItsActualAccountingDate():void
    {
        $this->deactivate('221');$plan=(new OpeningJournalPlan($this->db))->resolve($this->f['supplier'],'2026-06-01','property_opening',$this->balance());
        self::assertSame('2026-06-01',$plan['header']['entry_date']);self::assertSame($this->ids['221'],$plan['lines'][0]['account_id']);
    }
    public function testPaymentPlanUsesItsActualAccountingDate():void
    {
        $this->deactivate('221');$plan=(new PaymentWorkflowJournalPlan($this->db))->resolve($this->f['supplier'],'2026-06-01','unit_payment',$this->balance());
        self::assertSame('2026-06-01',$plan['header']['entry_date']);self::assertSame($this->ids['221'],$plan['lines'][0]['account_id']);
    }
    public function testClearingRevisionUsesItsActualAccountingDate():void
    {
        $this->deactivate('379.900');$lines=[['account_code'=>'379.900','side'=>'debit','amount'=>'10.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'10.00']];
        $plan=(new ClearingRevisionJournalPlan($this->db))->current($this->f['supplier'],'2026-06-01',['lines'=>$lines]);
        self::assertSame('2026-06-01',$plan['header']['entry_date']);self::assertSame($this->ids['379.900'],$plan['lines'][0]['account_id']);
    }
    public function testIssuancePreviewKeepsEarlierCanonicalAccountAfterFutureDeactivation():void
    {
        $r=$this->f['issue_request'];foreach($r['accounts'] as &$a)$a['receivable_account']='315.100';unset($a);
        $owner=new IssuancePreviewService($this->db);$before=$owner->preview($this->f['supplier'],$r,true);
        $this->deactivate('315.100');$after=$owner->preview($this->f['supplier'],$r,true);
        self::assertSame($before['preview_hash'],$after['preview_hash']);self::assertSame($before['snapshot'],$after['snapshot']);
    }
    public function testFundingNewPlanDatesActivityAndHistoricalPlanKeepsItsIdentity():void
    {
        $plan=['request'=>['kind'=>'income','accounting_date'=>'2026-06-01','amount_cents'=>'1000'],'proofs'=>['bank'=>['money_account'=>'221'],'registry'=>['calculation'=>['lines'=>[['unit_id'=>array_values($this->f['units'])[0],'amount_cents'=>'1000']]]]]];
        $proof=new FundingJournalProof($this->db);$before=$proof->lines($this->f['supplier'],$plan,true);$this->deactivate('221');
        self::assertSame(PropertyInput::hash($before),PropertyInput::hash($proof->lines($this->f['supplier'],$plan,false)));
        self::assertSame($before,$proof->lines($this->f['supplier'],$plan,true));
    }
    public function testOwnerPlansRejectAccountInactiveAtTheirActualLaterDate():void
    {
        $this->deactivate('221');$this->deactivate('379.900');$s=$this->f['supplier'];$rejected=[];
        foreach(['opening'=>fn()=>(new OpeningJournalPlan($this->db))->resolve($s,'2026-08-01','property_opening',$this->balance()),'payment'=>fn()=>(new PaymentWorkflowJournalPlan($this->db))->resolve($s,'2026-08-01','unit_payment',$this->balance()),'clearing'=>fn()=>(new ClearingRevisionJournalPlan($this->db))->current($s,'2026-08-01',['lines'=>[['account_code'=>'379.900','side'=>'debit','amount'=>'10.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'10.00']]])] as $kind=>$call){try{$call();}catch(\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Opening\OpeningException $e){$rejected[]=$kind;}}
        self::assertSame(['opening','payment','clearing'],$rejected);
    }
    public function testIssuanceRejectsAccountInactiveAtTheActualAccountingDate():void
    {
        $r=$this->f['issue_request'];$r['accounting_date']='2026-08-01';foreach($r['accounts'] as &$a)$a['receivable_account']='315.100';unset($a);$this->deactivate('315.100');
        $this->expectException(\MyInvoice\Service\Property\Charges\Issuance\IssuanceException::class);
        (new IssuancePreviewService($this->db))->preview($this->f['supplier'],$r,true);
    }
    public function testFundingNewPlanRejectsAccountInactiveAtActualDateWithoutChangingHistoricalIdentity():void
    {
        $plan=['request'=>['kind'=>'income','accounting_date'=>'2026-08-01','amount_cents'=>'1000'],'proofs'=>['bank'=>['money_account'=>'221'],'registry'=>['calculation'=>['lines'=>[['unit_id'=>array_values($this->f['units'])[0],'amount_cents'=>'1000']]]]]];
        $proof=new FundingJournalProof($this->db);$before=$proof->lines($this->f['supplier'],$plan,false);$this->deactivate('221');self::assertSame($before,$proof->lines($this->f['supplier'],$plan,false));
        $this->expectException(\MyInvoice\Service\Property\Funds\FundingException::class);$proof->lines($this->f['supplier'],$plan,true);
    }
    private function post(string $account,string $amount,string $side='credit'):int
    {
        $lines=[];foreach([[$account,$side],['221',$side==='credit'?'debit':'credit']] as [$code,$direction])$lines[]=['account_code'=>$code,'side'=>$direction,'amount'=>$amount,'dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])];
        $entry=$this->c->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,$lines,['entry_date'=>'2026-06-01','posted'=>true,'posted_by'=>$this->f['actor']]);
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND account_id=?');$q->execute([$this->f['supplier'],$entry,$this->ids[$account]]);return(int)$q->fetchColumn();
    }
    private function capitalPolicy(int $line,string $prefix='518.007'):array{return ['kind'=>'fund','source_line_id'=>$line,'asset_id'=>null,'provider_id'=>null,'revenue_tax_regime_id'=>$this->catalog['tax_regime']['values']['exempt'],'title'=>'Synthetic fund','purpose'=>'Synthetic nonbusiness cost','evidence'=>'Synthetic approved source','decision_authority'=>'Synthetic board','valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>[$prefix]];}
    public function testCapitalRegistrationUsesPolicyStartAndHistoricalReceiptDoesNotRevalidateEligibility():void
    {
        $line=$this->post('911','1000.00');$this->deactivate('518.007');$service=new CapitalService($this->db,$this->c->get(PostingService::class));$policy=$this->capitalPolicy($line);
        $p=$service->previewSource($this->f['supplier'],$policy);$saved=$service->confirmSource($this->f['supplier'],$policy,$p['input_hash'],'Synthetic dated source registration','dated-capital',$this->f['actor']);
        self::assertSame('2026-01-01',$saved['policy']['valid_from']);self::assertSame($saved['record_hash'],$service->read($this->f['supplier'])['sources'][0]['source']['record_hash']);
    }
    private function bornLater():void{$this->ids['518.123']=(new ChartOfAccountsRepository($this->db))->insert($this->f['supplier'],['account_code'=>'518.123','name'=>'Synthetic future expense','account_type'=>'expense','normal_side'=>'debit','parent_id'=>$this->ids['518']],'2027-01-01',$this->f['actor']);$d=new AccountDefinitionRepository($this->db);if(!$d->hasHistory($this->f['supplier'],$this->ids['518.123'],true))$d->initializeCreated($this->f['supplier'],$this->ids['518.123'],'2027-01-01',$this->f['actor']);}
    public function testCapitalCannotConfirmAccountNotYetBornAtPolicyStart():void
    {
        $line=$this->post('911','1000.00');$this->bornLater();$this->expectException(CapitalException::class);
        (new CapitalService($this->db,$this->c->get(PostingService::class)))->previewSource($this->f['supplier'],$this->capitalPolicy($line,'518.123'));
    }
    public function testCapitalRejectsAccountInactiveAtPolicyStart():void
    {
        $line=$this->post('911','1000.00');$this->deactivate('518.007');$policy=$this->capitalPolicy($line);$policy['valid_from']='2026-08-01';
        $this->expectException(CapitalException::class);(new CapitalService($this->db,$this->c->get(PostingService::class)))->previewSource($this->f['supplier'],$policy);
    }
    private function fundingPolicy(string $prefix='518.007'):array
    {
        $income=$this->post('682','1000.00');$expense=$this->post('518.007','800.00','debit');$s=$this->f['supplier'];$period=(int)$this->db->pdo()->query('SELECT id FROM accounting_periods WHERE supplier_id='.$s.' AND fiscal_year=2026')->fetchColumn();
        $tax=$this->c->get(NonprofitTaxAssessmentService::class);$snapshot=$tax->read($s,$period)['snapshot'];
        $assessment=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic statutes'],'activities'=>[$this->kind=>['evidence'=>'Synthetic activity']],'decisions'=>['line:'.$income=>['title'=>'gift','evidence'=>'Synthetic gift agreement','facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>'Synthetic explicit election']],'line:'.$expense=>['title'=>'cost','evidence'=>'Synthetic expense','legal_basis'=>'zdp_24_1','deductible_cents'=>0]],'funding'=>[]];
        $tax->confirm($s,$period,$assessment,$snapshot['input_hash'],'Synthetic prior tax assessment','dated-tax',$this->f['actor']);
        return ['period'=>$period,'policy'=>['kind'=>'gift','title'=>'Synthetic gift','purpose'=>'Synthetic activity','evidence'=>'Synthetic budget','provider_id'=>reset($this->f['clients']),'budget_cents'=>70000,'valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>[$prefix],'source_anchor'=>'line:'.$income,'source_members'=>['line:'.$income]]];
    }
    public function testFundingRegistrationUsesPolicyStartAfterFutureDeactivation():void
    {
        $f=$this->fundingPolicy();$this->deactivate('518.007');$service=$this->c->get(FundingService::class);$p=$service->preview($this->f['supplier'],$f['period'],'dated-funding',$f['policy']);
        $saved=$service->confirm($this->f['supplier'],$f['period'],'dated-funding',$f['policy'],$p['input_hash'],0,'Synthetic dated gift registration','dated-funding',$this->f['actor']);self::assertSame('2026-01-01',$saved['policy']['valid_from']);
    }
    public function testFundingRejectsAccountInactiveAtPolicyStart():void
    {
        $f=$this->fundingPolicy();$this->deactivate('518.007');$f['policy']['valid_from']='2026-08-01';$this->expectException(NonprofitTaxAssessmentException::class);
        $this->c->get(FundingService::class)->preview($this->f['supplier'],$f['period'],'dated-funding',$f['policy']);
    }
    public function testFundingCannotConfirmAccountNotYetBornAtPolicyStart():void
    {
        $f=$this->fundingPolicy('518.123');$this->bornLater();$this->expectException(NonprofitTaxAssessmentException::class);
        $this->c->get(FundingService::class)->preview($this->f['supplier'],$f['period'],'dated-funding',$f['policy']);
    }
}
