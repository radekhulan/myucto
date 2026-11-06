<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Tax\Nonprofit\{NonprofitLegalProfileService,NonprofitTaxAssessmentService,NonprofitDppoDataProvider,NonprofitDppoComputation,NonprofitTaxAssessmentException};
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;
final class NonprofitDppoDataProviderTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;private PDO $pdo;private int $supplier;private int $period;private int $actor;private array $catalog;private array $accounts=[];private int $kind;private \Psr\Container\ContainerInterface $container;
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);$this->pdo=$this->db->pdo();self::assertStringEndsWith('_test',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn());$this->pdo->beginTransaction();$seed=(int)$this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();$this->actor=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();$this->supplier=$this->createIsolatedSupplier($this->pdo,$seed);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry',taxpayer_type='po' WHERE id=?")->execute([$this->supplier]);$this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")->execute([$this->supplier]);$this->period=(new AccountingPeriodRepository($this->db))->create($this->supplier,2026,'2025-07-01','2026-06-30');
        $this->catalog=(new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier,'2025-07-01');$this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'ACT','Synthetic activity')")->execute([$this->catalog['activity_kind']['id'],$this->supplier]);$this->kind=(int)$this->pdo->lastInsertId();
        foreach(['221'=>'asset','518'=>'expense','602'=>'revenue']as$code=>$type){$this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')->execute([$this->supplier,(string)$code,'Synthetic '.$code,$type]);$this->accounts[$code]=(int)$this->pdo->lastInsertId();}
    }
    protected function tearDown():void{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    private function profile():void
    {(new NonprofitLegalProfileService($this->db))->confirm($this->supplier,$this->period,['expected_revision'=>0,'idempotency_key'=>'legal','reason'=>'Synthetic review','profile'=>['kind'=>'narrow_vpp','entity_type'=>'association','main_activity_nonbusiness'=>true,'legal_basis'=>'zdp_17a','evidence'=>'Synthetic statutes']],$this->actor);}
    private function post(string $code,string $amount):int
    {
        $expense=$code==='518';$this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type) VALUES(?,?,'2025-08-01','manual')")->execute([$this->supplier,$this->period]);$entry=(int)$this->pdo->lastInsertId();$line=0;
        foreach([[$code,$expense?'debit':'credit'],['221',$expense?'credit':'debit']]as$no=>[$account,$side]){$this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount) VALUES(?,?,?,?,?,?)')->execute([$this->supplier,$entry,$no+1,$this->accounts[$account],$side,$amount]);if($no===0){$line=(int)$this->pdo->lastInsertId();foreach(['activity'=>$this->catalog['activity']['values']['main'],'activity_kind'=>$this->kind,'tax_regime'=>$this->catalog['tax_regime']['values']['subject']]as$axis=>$value)$this->pdo->prepare("INSERT INTO journal_entry_line_dimensions(supplier_id,line_id,dimension_type_id,dimension_value_id,assignment_origin) VALUES(?,?,?,?,'explicit')")->execute([$this->supplier,$line,$this->catalog[$axis]['id'],$value]);}}
        $this->pdo->prepare('UPDATE journal_entries SET posted_at=NOW() WHERE id=?')->execute([$entry]);return$line;
    }
    private function confirm(int $deductible=0):array
    {
        $j6=$this->container->get(NonprofitTaxAssessmentService::class);$snapshot=$j6->read($this->supplier,$this->period)['snapshot'];$a=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic status'],'activities'=>[$this->kind=>['evidence'=>'Synthetic activity']],'decisions'=>[],'funding'=>[]];
        foreach($snapshot['sources']as$row)$a['decisions'][$row['id']]=['title'=>$row['kind']==='revenue'?'rent':'cost','evidence'=>'Synthetic title','legal_basis'=>'zdp_24_1','deductible_cents'=>$row['kind']==='expense'?$deductible:0];
        return$j6->confirm($this->supplier,$this->period,$a,$snapshot['input_hash'],'Synthetic review','j6',$this->actor);
    }
    public function testCanonicalJ6AndNativeStatementsUseTrueFiscalInterval():void
    {
        $this->profile();$this->post('602','800000.00');$this->post('518','300000.00');$a=$this->confirm(10000000);$p=$this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier,2026);
        self::assertSame('2025-07-01',$p['period']['starts_on']);self::assertSame('2026-06-30',$p['period']['ends_on']);self::assertSame(500000,$p['vh']);self::assertSame(20000000,$p['nonprofit']['bridge']['line_cents'][40]);self::assertSame($a['id'],$p['source_manifest']['assessment_id']);self::assertArrayHasKey('UR',$p['statements']['epo_rows']);self::assertSame(0,NonprofitDppoComputation::validate($p,[])['unused_reduction_addback_kc']);
    }
    public function testNativeAdjustmentCannotAddBackPreviouslyExcludedExpenseTwice():void
    {
        $this->profile();$this->post('602','800000.00');$cost=$this->post('518','300000.00');$this->confirm(10000000);$input=['nonprofit'=>['adjustments'=>[['kind'=>'expense_addback','source_id'=>'line:'.$cost,'amount_cents'=>10000001,'legal_basis'=>'zdp_25','evidence'=>'Synthetic selected adjustment']]]];$this->expectException(TaxReturnException::class);$this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier,2026,$input);
    }
    public function testNativeSelectedAdjustmentBindsActualExpenseAndJ6Receipt():void
    {
        $this->profile();$this->post('602','800000.00');$cost=$this->post('518','300000.00');$a=$this->confirm(10000000);$input=['nonprofit'=>['adjustments'=>[['kind'=>'expense_addback','source_id'=>'line:'.$cost,'amount_cents'=>5000000,'legal_basis'=>'zdp_25','evidence'=>'Synthetic selected adjustment']]]];$p=$this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier,2026,$input);self::assertSame(25000000,NonprofitDppoComputation::validate($p,$input)['line_cents'][40]);self::assertSame($a['request_hash'],$p['nonprofit']['adjustments'][0]['proof']['request_hash']);
    }
    public function testLegacyJ6DoesNotCreateLegalProfileImplicitly():void
    {$this->confirm();$this->expectException(TaxReturnException::class);$this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier,2026);}
    public function testSameAmountLegalProfileRefreshInvalidatesDppoSourceProof():void
    {
        $this->profile();$this->confirm();$profile=new NonprofitLegalProfileService($this->db);$a=$profile->read($this->supplier,$this->period)['latest'];$facts=$a['profile'];unset($facts['reduction_band'],$facts['ruleset']);$profile->confirm($this->supplier,$this->period,['expected_revision'=>1,'idempotency_key'=>'legal-refresh','reason'=>'Synthetic refresh','profile'=>$facts],$this->actor);$this->expectException(NonprofitTaxAssessmentException::class);$this->container->get(NonprofitDppoDataProvider::class)->gather($this->supplier,2026);
    }
}
