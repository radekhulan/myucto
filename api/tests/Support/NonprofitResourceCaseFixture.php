<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentConfirmationService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;

trait NonprofitResourceCaseFixture
{
    private int $period;private int $kind;private int $provider;private array $axes;private array $dimensions;private array $accountIds=[];private int $income;private int $expense;private array $assessment;private array $taxReceipt;private array $fundingPolicy;
    private const PRIVATE_SENTINEL='PRIVATE-RESOURCE@example.test';
    private function resourceBase():void
    {
        $pdo=$this->db->pdo();$pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);$this->period=$this->container->get(AccountingPeriodRepository::class)->create($this->supplier,2026,'2026-01-01','2026-12-31');$pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $this->axes=$this->container->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier,'2026-01-01');$pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'SPORT',?)")->execute([$this->axes['activity_kind']['id'],$this->supplier,self::PRIVATE_SENTINEL]);$this->kind=(int)$pdo->lastInsertId();
        $this->dimensions=[$this->axes['activity']['id']=>$this->axes['activity']['values']['main'],$this->axes['activity_kind']['id']=>$this->kind,$this->axes['tax_regime']['id']=>$this->axes['tax_regime']['values']['exempt']];
        foreach(['221'=>'asset','022'=>'asset','082'=>'asset','551'=>'expense','518'=>'expense','901'=>'equity','911'=>'equity','648'=>'revenue','649'=>'revenue','691'=>'revenue','682'=>'revenue']as$code=>$type){$pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,?,?,?)')->execute([$this->supplier,(string)$code,self::PRIVATE_SENTINEL,$type]);$this->accountIds[$code]=(int)$pdo->lastInsertId();}
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)VALUES(?,?,?,?,'10000',203,?,'cs',?,0,1)")->execute([$this->supplier,self::PRIVATE_SENTINEL,self::PRIVATE_SENTINEL,self::PRIVATE_SENTINEL,self::PRIVATE_SENTINEL,(int)$pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn()]);$this->provider=(int)$pdo->lastInsertId();
    }
    private function nativePost(string $account,string $amount,string $side='credit'):int
    {
        $entry=$this->container->get(PostingService::class)->postDocument($this->supplier,'manual',null,[['account_code'=>$account,'side'=>$side,'amount'=>$amount,'dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])],['account_code'=>'221','side'=>$side==='credit'?'debit':'credit','amount'=>$amount,'dimensions'=>$this->dimensions,'dimension_origins'=>array_fill_keys(array_keys($this->dimensions),['origin'=>'explicit','ref'=>null])]],['entry_date'=>'2026-01-01','description'=>self::PRIVATE_SENTINEL,'posted'=>true,'posted_by'=>$this->actor]);
        $q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND account_id=?');$q->execute([$this->supplier,$entry,$this->accountIds[$account]]);return(int)$q->fetchColumn();
    }
    private function fundingSource(bool $use=true):array
    {
        $this->resourceBase();$this->income=$this->nativePost('682','1000.00');$this->expense=$this->nativePost('518','800.00','debit');$tax=$this->container->get(NonprofitTaxAssessmentService::class);$snapshot=$tax->read($this->supplier,$this->period)['snapshot'];
        $this->assessment=['profile'=>['kind'=>'narrow_vpp','evidence'=>self::PRIVATE_SENTINEL],'activities'=>[$this->kind=>['evidence'=>self::PRIVATE_SENTINEL]],'decisions'=>['line:'.$this->income=>['title'=>'gift','evidence'=>self::PRIVATE_SENTINEL,'facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>self::PRIVATE_SENTINEL]],'line:'.$this->expense=>['title'=>'cost','evidence'=>self::PRIVATE_SENTINEL,'legal_basis'=>'zdp_24_1','deductible_cents'=>80000]],'funding'=>[]];
        $this->taxReceipt=$tax->confirm($this->supplier,$this->period,$this->assessment,$snapshot['input_hash'],self::PRIVATE_SENTINEL,'initial-tax',$this->actor);
        $this->fundingPolicy=['kind'=>'gift','title'=>self::PRIVATE_SENTINEL,'purpose'=>self::PRIVATE_SENTINEL,'evidence'=>self::PRIVATE_SENTINEL,'provider_id'=>$this->provider,'budget_cents'=>70000,'valid_from'=>'2026-01-01','valid_to'=>'2026-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>['518'],'source_anchor'=>'line:'.$this->income,'source_members'=>['line:'.$this->income]];
        $service=$this->container->get(FundingService::class);$preview=$service->preview($this->supplier,$this->period,'PRIVATE-SOURCE-KEY',$this->fundingPolicy);$source=$service->confirm($this->supplier,$this->period,'PRIVATE-SOURCE-KEY',$this->fundingPolicy,$preview['input_hash'],$preview['expected_revision'],self::PRIVATE_SENTINEL,'gift-source',$this->actor);
        if($use){$a=$this->assessment;$a['funding']=[['source_id'=>'line:'.$this->income,'cost_id'=>'line:'.$this->expense,'amount_cents'=>60000,'evidence'=>self::PRIVATE_SENTINEL]];$snapshot=$tax->read($this->supplier,$this->period)['snapshot'];$this->taxReceipt=$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],self::PRIVATE_SENTINEL,'actual-use',$this->actor,$this->taxReceipt['id']);}
        return $source;
    }
    /**
     * Potvrdí období fixture stránkovanou cestou J6 se stejnými rozhodnutími, volitelným čerpáním daru
     * a volitelně deklarovaným fondem nákladu.
     */
    private function pagedFundingAssessment(int $drawdown, bool $costPool = false): int
    {
        $drafts = $this->container->get(J6AssessmentDraftService::class);
        $draft = $drafts->openCurrent($this->supplier, $this->period, $this->assessment['profile'], null, $this->actor);
        $decisions = [];
        foreach ($this->assessment['decisions'] as $id => $payload) $decisions[] = ['source_id' => $id, 'payload' => $payload];
        $draft = $drafts->patchDecisions($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $decisions, [], $this->actor);
        $activities = [];
        foreach ($this->assessment['activities'] as $id => $payload) $activities[] = ['activity_kind_id' => (string) $id, 'payload' => $payload];
        $draft = $drafts->patchActivities($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $activities, [], $this->actor);
        if ($drawdown > 0) {
            $draft = $drafts->patchFundingLinks($this->supplier, $this->period, $draft['id'], $draft['revision_no'], [[
                'source_id' => 'line:' . $this->income, 'cost_id' => 'line:' . $this->expense,
                'payload' => ['amount_cents' => $drawdown, 'evidence' => self::PRIVATE_SENTINEL],
            ]], [], $this->actor);
        }
        if ($costPool) {
            $anchor = 'line:' . $this->expense;
            $draft = $drafts->patchFundingPools($this->supplier, $this->period, $draft['id'], $draft['revision_no'], [[
                'pool_kind' => 'expense', 'anchor' => $anchor, 'payload' => ['evidence' => self::PRIVATE_SENTINEL],
            ]], [], $this->actor);
            $draft = $drafts->patchFundingMembers($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
                'expense', $anchor, [$anchor], [], $this->actor);
        }
        $context = new J6DraftContext($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $this->actor, $draft['source_input_hash']);
        return $this->container->get(J6AssessmentConfirmationService::class)
            ->confirm($context, 'paged-funding-' . $draft['id'], self::PRIVATE_SENTINEL, $this->taxReceipt['id'])->assessmentId;
    }
    private function capitalSource(string $kind='fund'):array
    {
        $this->resourceBase();$asset=null;
        if($kind==='investment'){$pdo=$this->db->pdo();$pdo->prepare("INSERT INTO assets(supplier_id,inventory_number,name,input_price,acquisition_date,put_into_use_date,status,accumulated_account_code,tax_method,acc_method,acc_useful_life_months)VALUES(?,'RESOURCE-ASSET',?,500000,'2025-12-01','2025-12-01','in_use','082','none','straight_line',60)")->execute([$this->supplier,self::PRIVATE_SENTINEL]);$asset=(int)$pdo->lastInsertId();foreach($this->dimensions as$type=>$value)$pdo->prepare("INSERT INTO document_dimensions(supplier_id,doc_type,doc_id,item_no,dimension_type_id,dimension_value_id,assignment_origin)VALUES(?,'asset',?,0,?,?,'explicit')")->execute([$this->supplier,$asset,$type,$value]);}
        $line=$this->nativePost($kind==='investment'?'901':'911',$kind==='investment'?'300000.00':'5000.00');$policy=['kind'=>$kind,'source_line_id'=>$line,'asset_id'=>$asset,'provider_id'=>$this->provider,'revenue_tax_regime_id'=>$this->axes['tax_regime']['values']['exempt'],'title'=>self::PRIVATE_SENTINEL,'purpose'=>self::PRIVATE_SENTINEL,'evidence'=>self::PRIVATE_SENTINEL,'decision_authority'=>self::PRIVATE_SENTINEL,'valid_from'=>'2026-01-01','valid_to'=>'2035-12-31','activity_kind_ids'=>[$this->kind],'account_prefixes'=>[$kind==='investment'?'551':'518']];
        $service=new CapitalService($this->db,$this->container->get(PostingService::class));$preview=$service->previewSource($this->supplier,$policy);return $service->confirmSource($this->supplier,$policy,$preview['input_hash'],self::PRIVATE_SENTINEL,'capital-source',$this->actor);
    }
    private function domainDmlCounters():array
    {
        $rows=$this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_replace','Com_create_table','Com_alter_table','Com_truncate')")->fetchAll(\PDO::FETCH_KEY_PAIR);ksort($rows);return$rows;
    }
}
