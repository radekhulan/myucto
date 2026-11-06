<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting\Funding;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
final class NativeCostCoverageTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $period;
    private int $actor;
    private int $provider;
    private int $kind;
    private array $dims;
    private PostingService $posting;
    protected function setUp():void
    {
        $c=Bootstrap::buildContainer();$this->db=$c->get(Connection::class);$this->pdo=$this->db->pdo();self::assertStringEndsWith('_test',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn());$this->pdo->beginTransaction();
        $seed=(int)$this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$seed);$this->actor=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();$this->supplier=$this->createIsolatedSupplier($this->pdo,$seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);$this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2026,'2026-01-01','2026-12-31');$this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $cat=$c->get(SystemDimensionCatalog::class)->ensureForFramework($this->supplier,'2026-01-01');$this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)VALUES(?,?,'COVERAGE','Synthetic documented activity')")->execute([$cat['activity_kind']['id'],$this->supplier]);$this->kind=(int)$this->pdo->lastInsertId();$this->dims=[$cat['activity']['id']=>$cat['activity']['values']['main'],$cat['activity_kind']['id']=>$this->kind,$cat['tax_regime']['id']=>$cat['tax_regime']['values']['exempt']];
        foreach(['221'=>'asset','321'=>'liability','518'=>'expense','551'=>'expense','648'=>'revenue','649'=>'revenue','682'=>'revenue','691'=>'revenue']as$code=>$type)$this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type)VALUES(?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic '.$code,$type]);
        $currency=(int)$this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();$this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)VALUES(?,'Synthetic coverage vendor','Synthetic street','Synthetic city','10000',203,'coverage@example.test','cs',?,0,1)")->execute([$this->supplier,$currency]);$this->provider=(int)$this->pdo->lastInsertId();$this->posting=$c->get(PostingService::class);
    }
    protected function tearDown():void{if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();}
    private function post(array $rows,string $source='manual',?int $sourceId=null,bool $posted=true):array
    {
        $lines=[];foreach($rows as$row)$lines[]=$row+['dimensions'=>$this->dims,'dimension_origins'=>array_fill_keys(array_keys($this->dims),['origin'=>'explicit','ref'=>null])];
        $entry=$this->posting->postDocument($this->supplier,$source,$sourceId,$lines,['entry_date'=>'2026-03-01','description'=>'Synthetic native coverage evidence','posted'=>$posted,'posted_by'=>$this->actor]);$q=$this->pdo->prepare('SELECT l.id,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? ORDER BY l.line_no,l.id');$q->execute([$this->supplier,$entry]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function ordinary(string $code,string $amount,bool $red=false,string $source='manual',?int $sourceId=null):int
    {
        $expense=str_starts_with($code,'5');return (int)$this->post([['account_code'=>$code,'side'=>$expense?'debit':'credit','amount'=>$amount,'is_red_storno'=>$red],['account_code'=>'221','side'=>$expense?'credit':'debit','amount'=>$amount,'is_red_storno'=>$red]],$source,$sourceId)[0]['id'];
    }
    private function assessment(int $gift,int $cost,int $funded,?int $correction=null):array
    {
        $tax=Bootstrap::buildContainer()->get(NonprofitTaxAssessmentService::class);$snapshot=$tax->read($this->supplier,$this->period)['snapshot'];$a=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic statutes'],'activities'=>[$this->kind=>['evidence'=>'Synthetic activity']],'decisions'=>[],'funding'=>[['source_id'=>'line:'.$gift,'cost_id'=>'line:'.$cost,'amount_cents'=>$funded,'evidence'=>'Synthetic confirmed gift use']]];
        foreach($snapshot['sources']as$row)$a['decisions'][$row['id']]=$row['kind']==='expense'?['title'=>'cost','deductible_cents'=>$row['amount_cents'],'legal_basis'=>'zdp_24_1','evidence'=>'Synthetic expense']:['title'=>'gift','facts'=>['claim_exemption'=>true,'qualifying_purpose'=>true,'election_evidence'=>'Synthetic documented gift election'],'evidence'=>'Synthetic gift'];
        if($correction!==null)$a['funding_costs']=[['anchor'=>'line:'.$cost,'members'=>['line:'.$cost,'line:'.$correction],'evidence'=>'Synthetic exact negative cost correction']];
        return [$tax,$snapshot,$a];
    }
    private function purchase(?int $parent=null):int
    {
        $q=$this->pdo->prepare("INSERT INTO purchase_invoices(supplier_id,vendor_id,vendor_invoice_number,document_kind,parent_purchase_invoice_id,issue_date,due_date,received_at,currency_id,vendor_snapshot,created_by)VALUES(?,?,?,?,?,'2026-03-01','2026-03-15','2026-03-01',?,'{}',?)");$q->execute([$this->supplier,$this->provider,'SYNTHETIC-'.bin2hex(random_bytes(4)),$parent===null?'invoice':'credit_note',$parent,(int)$this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn(),$this->actor]);return (int)$this->pdo->lastInsertId();
    }
    public function testNative648AndSignedContraAreOneCanonicalDirectCoverage():void
    {
        $rows=$this->post([['account_code'=>'518','side'=>'debit','amount'=>'800.00'],['account_code'=>'649','side'=>'credit','amount'=>'200.00'],['account_code'=>'648','side'=>'credit','amount'=>'50.00','is_red_storno'=>true],['account_code'=>'221','side'=>'credit','amount'=>'650.00']]);$native=new NativeCostCoverage($this->db);$cost=(int)$rows[0]['id'];self::assertSame(15000,$native->forCost($this->supplier,$cost)['covered_cents']);self::assertTrue($native->forCost($this->supplier,$cost)['eligible']);
        $this->expectException(PostingException::class);$this->expectExceptionMessage('přímo kryje');$native->income($this->supplier,(int)$rows[1]['id']);
    }
    public function testNativeDirectCoverageRejectsAmbiguousMultipleExpenseRows():void
    {
        $rows=$this->post([['account_code'=>'518','side'=>'debit','amount'=>'500.00'],['account_code'=>'551','side'=>'debit','amount'=>'300.00'],['account_code'=>'691','side'=>'credit','amount'=>'200.00'],['account_code'=>'221','side'=>'credit','amount'=>'600.00']]);$this->expectException(PostingException::class);$this->expectExceptionMessage('více nákladových');(new NativeCostCoverage($this->db))->forCost($this->supplier,(int)$rows[0]['id']);
    }
    public function testJ6ActualCostEnvelopePreservesNegativeCorrectionAndPureClaim():void
    {
        $gift=$this->ordinary('682','1000.00');$cost=$this->ordinary('518','800.00');$negative=$this->ordinary('518','100.00',true);[$tax,$snapshot,$a]=$this->assessment($gift,$cost,40000,$negative);$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic actual corrected costpool','cost-envelope',$this->actor);
        $helper=Bootstrap::buildContainer()->get(J6FundingCapacity::class);$pool=$helper->costEnvelope($this->supplier,$cost);self::assertSame(70000,$pool['net_cents']);self::assertSame([$cost,$negative],$pool['members']);self::assertSame('40000',$helper->claimedCost($this->supplier,$cost));self::assertNull($helper->costEnvelope($this->supplier,$cost,$this->period));self::assertSame('0',$helper->claimedCost($this->supplier,$cost,$this->period));
        $capacity=(new CostCoverage($this->db))->capacity($this->supplier,$cost);self::assertSame('40000',$capacity['claimed_cents']);self::assertSame(30000,(int)$capacity['available_cents']);
    }
    public function testPostedNativePurchaseCreditNoteIsExplicitlyIneligibleForUnpooledFunding():void
    {
        $purchase=$this->purchase();$cost=$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);$note=$this->purchase($purchase);$this->ordinary('518','100.00',true,'purchase_invoice',$note);$proof=(new NativeCostCoverage($this->db))->forCost($this->supplier,$cost);self::assertFalse($proof['eligible']);self::assertSame(['native_purchase_credit_note'],$proof['review_reasons']);self::assertCount(1,$proof['credit_notes']);
    }
    public function testNewPurchaseCreditNoteCannotInvalidateEarlierConfirmedJ6Coverage():void
    {
        $gift=$this->ordinary('682','1000.00');$purchase=$this->purchase();$cost=$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);[$tax,$snapshot,$a]=$this->assessment($gift,$cost,40000);$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic first native funded purchase','funded-purchase',$this->actor);$note=$this->purchase($purchase);
        $this->expectException(PostingException::class);$this->expectExceptionMessage('Původní náklad');(new NativeCostCoverage($this->db))->assertCreditNotePosting(['supplier_id'=>$this->supplier,'source_type'=>'purchase_invoice','source_id'=>$note]);
    }
    public function testNativePostingRejectsCreditNoteAfterConfirmedFunding():void
    {
        $gift=$this->ordinary('682','1000.00');$purchase=$this->purchase();$cost=$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);[$tax,$snapshot,$a]=$this->assessment($gift,$cost,40000);$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic funded purchase posting','funded-native-post',$this->actor);$note=$this->purchase($purchase);
        $this->expectException(PostingException::class);$this->expectExceptionMessage('Původní náklad');$this->ordinary('518','100.00',true,'purchase_invoice',$note);
    }
    public function testJ6CannotConfirmPurchaseCreditPoolWithoutNativeClearance():void
    {
        $gift=$this->ordinary('682','1000.00');$purchase=$this->purchase();$cost=$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);$note=$this->purchase($purchase);$negative=$this->ordinary('518','100.00',true,'purchase_invoice',$note);[$tax,$snapshot,$a]=$this->assessment($gift,$cost,40000,$negative);
        $this->expectException(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('dobropis');$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic unpooled corrected purchase','unpooled-credit',$this->actor);
    }
    public function testPostedCreditNoteCannotChangeParentInEditor():void
    {
        $first=$this->purchase();$second=$this->purchase();$note=$this->purchase($first);$this->ordinary('518','100.00',true,'purchase_invoice',$note);$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);$data=$repo->find($note,$this->supplier);$data['parent_purchase_invoice_id']=$second;
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('zaúčtovaný');$repo->updateDraft($note,$data,$this->supplier);
    }
    public function testPostedCreditNoteCannotChangeKindThroughShortcut():void
    {
        $note=$this->purchase($this->purchase());$this->ordinary('518','100.00',true,'purchase_invoice',$note);$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
        self::assertNotNull($repo->updateDocumentKind($note,$this->supplier,'invoice'));self::assertSame('credit_note',$repo->find($note,$this->supplier)['document_kind']);
    }
    public function testPostedInvoiceCannotBecomeCreditNoteThroughEditor():void
    {
        $purchase=$this->purchase();$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);$data=$repo->find($purchase,$this->supplier);$data['document_kind']='credit_note';$data['parent_purchase_invoice_id']=$this->purchase();
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('zaúčtovaný');$repo->updateDraft($purchase,$data,$this->supplier);
    }

    public static function creditNoteMutationPaths():array{return [['repost'],['mark_posted'],['replace_lines']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('creditNoteMutationPaths')]
    public function testDraftCreditNoteCannotBePostedByAnotherMutationPath(string $path):void
    {
        $gift=$this->ordinary('682','1000.00');$purchase=$this->purchase();$cost=$this->ordinary('518','800.00',false,'purchase_invoice',$purchase);[$tax,$snapshot,$a]=$this->assessment($gift,$cost,40000);$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic funded native mutation','funded-mutation-'.$path,$this->actor);$note=$this->purchase($purchase);
        $rows=[['account_code'=>'518','side'=>'debit','amount'=>'100.00','is_red_storno'=>true],['account_code'=>'221','side'=>'credit','amount'=>'100.00','is_red_storno'=>true]];
        $lines=$this->post($rows,'purchase_invoice',$note,false);$q=$this->pdo->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?');$q->execute([$this->supplier,$lines[0]['id']]);$entry=(int)$q->fetchColumn();$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        if($path==='replace_lines')$this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE supplier_id=? AND id=?')->execute([$this->supplier,$entry]);
        $this->expectException(PostingException::class);
        match($path){'repost'=>$this->post($rows,'purchase_invoice',$note),'mark_posted'=>$repo->markPosted($entry,$this->supplier,$this->actor),'replace_lines'=>$repo->replaceSomeLines($entry,$this->supplier,1,[],[])};
    }
    public function testNullKindCannotBypassPostedCreditNoteClassificationGuard():void
    {
        $note=$this->purchase($this->purchase());$this->ordinary('518','100.00',true,'purchase_invoice',$note);$repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);$data=$repo->find($note,$this->supplier);$data['document_kind']=null;
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('zaúčtovaný');$repo->updateDraft($note,$data,$this->supplier);
    }

    public function testEveryPooledIncomeMemberMustBeUnusedNativeRevenue():void
    {
        $anchor=$this->ordinary('682','100.00');$cost=$this->ordinary('518','800.00');$direct=$this->post([['account_code'=>'518','side'=>'debit','amount'=>'300.00'],['account_code'=>'682','side'=>'credit','amount'=>'300.00']]);[$tax,$snapshot,$a]=$this->assessment($anchor,$cost,40000);$a['funding_sources']=[['anchor'=>'line:'.$anchor,'members'=>['line:'.$anchor,'line:'.$direct[1]['id']],'evidence'=>'Synthetic pooled gift sources']];
        $this->expectException(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException::class);$this->expectExceptionMessage('přímo');$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic duplicate direct gift pool','pooled-direct-income',$this->actor);
    }
    public function testUnfundedPurchaseNegativeAdjustmentDoesNotBlockNativeCreditNote():void
    {
        $parent=$this->purchase();$this->post([['account_code'=>'518','side'=>'debit','amount'=>'800.00'],['account_code'=>'518','side'=>'debit','amount'=>'100.00','is_red_storno'=>true],['account_code'=>'321','side'=>'credit','amount'=>'700.00']],'purchase_invoice',$parent);$note=$this->purchase($parent);$lines=$this->post([['account_code'=>'518','side'=>'debit','amount'=>'100.00','is_red_storno'=>true],['account_code'=>'321','side'=>'credit','amount'=>'100.00','is_red_storno'=>true]],'purchase_invoice',$note);self::assertCount(2,$lines);
    }
    public function testSignedNativeGiftCorrectionRemainsInCanonicalIncomePool():void
    {
        $anchor=$this->ordinary('682','500.00');$negative=$this->ordinary('682','100.00',true);$cost=$this->ordinary('518','800.00');[$tax,$snapshot,$a]=$this->assessment($anchor,$cost,40000);$a['funding_sources']=[['anchor'=>'line:'.$anchor,'members'=>['line:'.$anchor,'line:'.$negative],'evidence'=>'Synthetic exact gift refund']];$confirmed=$tax->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic signed funding income pool','signed-income-pool',$this->actor);self::assertSame('confirmed',$confirmed['status']);self::assertSame(40000,$confirmed['result']['funding_usage'][0]['net_source_cents']);
    }
}
