<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class NonprofitLegalProfileTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;private PDO $pdo;private int $supplier;private int $period;private int $actor;private NonprofitLegalProfileService $service;
    protected function setUp():void
    {
        $c=Bootstrap::buildContainer();$this->db=$c->get(Connection::class);$this->pdo=$this->db->pdo();self::assertStringEndsWith('_test',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn());$this->pdo->beginTransaction();
        $seed=(int)$this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();self::assertGreaterThan(0,$seed);$this->actor=(int)$this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier=$this->createIsolatedSupplier($this->pdo,$seed);$this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$this->supplier]);$this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2025-01-01','cz_504')")->execute([$this->supplier]);$this->period=$c->get(AccountingPeriodRepository::class)->create($this->supplier,2026,'2025-07-01','2026-06-30');$this->service=new NonprofitLegalProfileService($this->db);
    }
    protected function tearDown():void{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    private function input(string $key='profile-1',int $revision=0,string $kind='narrow_vpp',string $type='association'):array
    {return ['profile'=>['kind'=>$kind,'entity_type'=>$type,'main_activity_nonbusiness'=>true,'evidence'=>'Synthetic articles','legal_basis'=>'zdp_17a'],'expected_revision'=>$revision,'reason'=>'Synthetic reviewed legal form','idempotency_key'=>$key];}
    public function testNativeFiscalPeriodRevisionHashAndExactRetry():void
    {
        $a=$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);
        self::assertSame($this->period,$a['period_id']);self::assertSame(1,$a['revision_no']);self::assertSame('standard',$a['profile']['reduction_band']);
        self::assertSame($a,$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor));
        $b=$this->service->confirm($this->supplier,$this->period,$this->input('profile-2',1),$this->actor);self::assertSame($a['id'],$b['previous_id']);self::assertNotSame($a['record_hash'],$b['record_hash']);self::assertCount(2,$this->service->history($this->supplier,$this->period));
        self::assertSame('2025-07-01',$this->service->read($this->supplier,$this->period)['period']['starts_on']);
    }
    public function testConcurrentRevisionIsRejected():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$this->expectException(TaxReturnException::class);$this->service->confirm($this->supplier,$this->period,$this->input('stale'),$this->actor);
    }
    public function testIdempotencyKeyCannotBeReassigned():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$b=$this->input();$b['reason']='Another review';$this->expectException(TaxReturnException::class);$this->service->confirm($this->supplier,$this->period,$b,$this->actor);
    }
    public function testTenantCannotReadOrConfirmForeignPeriod():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$other=$this->createIsolatedSupplier($this->pdo,$this->supplier);self::assertNull($this->service->latest($other,$this->period));$this->expectException(TaxReturnException::class);$this->service->confirm($other,$this->period,$this->input(),$this->actor);
    }
    public function testSvjModuleCannotBeDisabledToObtainVppAllowance():void
    {
        $module=new PropertyProfileRepository($this->db);$base=['expected_revision'=>0,'effective_from'=>'2025-01-01T00:00:00Z','effective_to'=>null,'enabled'=>true,'reason'=>'Synthetic SVJ module','title_ref'=>'Synthetic legal title','idempotency_key'=>'svj-module-1'];$module->save($this->supplier,$base,$this->actor);
        $base['expected_revision']=1;$base['enabled']=false;$base['idempotency_key']='svj-module-off';$base['effective_from']='2025-06-01T00:00:00Z';$module->save($this->supplier,$base,$this->actor);
        $this->expectException(TaxReturnException::class);$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);
    }
    public function testSvjLegalFormRetainedAcrossPeriods():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input('svj',0,'svj','svj'),$this->actor);
        $period=(new AccountingPeriodRepository($this->db))->create($this->supplier,2027,'2026-07-01','2027-06-30');$this->expectException(TaxReturnException::class);$this->service->confirm($this->supplier,$period,$this->input('wrong-vpp'),$this->actor);
    }
    public function testInvalidNestedRequestRollsBackOnlyItsSavepoint():void
    {
        try{$this->service->confirm($this->supplier,$this->period,$this->input('invalid',1),$this->actor);self::fail('CAS required');}catch(TaxReturnException $e){self::assertSame('nonprofit_legal_profile_changed',$e->errorCode);}
        self::assertTrue($this->pdo->inTransaction());self::assertSame([],$this->service->history($this->supplier,$this->period));self::assertSame(1,$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor)['revision_no']);
    }
    public function testClosedPeriodRetainsHistoricalProfileAndRejectsChanges():void
    {
        $a=$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE id=?")->execute([$this->period]);self::assertSame($a['record_hash'],$this->service->latest($this->supplier,$this->period)['record_hash']);$this->expectException(TaxReturnException::class);$this->service->confirm($this->supplier,$this->period,$this->input('closed',1),$this->actor);
    }
    public function testJ6ConfirmationBindsLegalProfileRevisionAndDetectsSameKindRefresh():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);
        $c=Bootstrap::buildContainer();$j6=$c->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);
        $assessment=['profile'=>['kind'=>'narrow_vpp','evidence'=>'Synthetic legal profile'],'activities'=>[],'decisions'=>[],'funding'=>[]];
        $before=$j6->read($this->supplier,$this->period)['snapshot'];self::assertSame(1,$before['legal_profile']['revision_no']);
        $a=$j6->confirm($this->supplier,$this->period,$assessment,$before['input_hash'],'Synthetic confirmation','j6-profile',$this->actor);
        $this->service->confirm($this->supplier,$this->period,$this->input('revision',1),$this->actor);
        $after=$j6->read($this->supplier,$this->period)['snapshot'];self::assertNotSame($before['input_hash'],$after['input_hash']);
        $guard=$c->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentGuard::class)->closingCheck($this->supplier,$this->period);
        self::assertFalse($guard['complete']);self::assertContains('assessment_stale',array_column($guard['findings'],'code'));
        self::assertSame($before['legal_profile']['record_hash'],$c->get(\MyInvoice\Repository\NonprofitTaxAssessmentRepository::class)->find($this->supplier,$a['id'])['snapshot']['legal_profile']['record_hash']);
    }
    public function testJ6CannotOverrideConfirmedLegalProfile():void
    {
        $this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$j6=Bootstrap::buildContainer()->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class);
        $this->expectException(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException::class);
        $j6->preview($this->supplier,$this->period,['profile'=>['kind'=>'non_vpp','evidence'=>'Synthetic conflicting choice'],'activities'=>[],'decisions'=>[],'funding'=>[]]);
    }
    public function testConfirmEndpointsRequireHumanSessionBeforeAnyFinancialServiceCall():void
    {
        $action=Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\NonprofitDppoAction::class);
        foreach(['bearer',null]as$auth)foreach(['confirmProfile','confirmUse','confirmReverse']as$method){$q=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/accounting/nonprofit-dppo')->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin'])->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,$auth)->withParsedBody([]);$r=$action->$method($q,new \Slim\Psr7\Response(),['id'=>(string)$this->period,'event'=>'1']);self::assertSame(403,$r->getStatusCode());self::assertSame('token_write_forbidden',json_decode((string)$r->getBody(),true)['error']['code']);}
        self::assertSame([],$this->service->history($this->supplier,$this->period));
    }
    public function testProfileActionCapabilitiesReflectSessionAndDedicatedPermission():void
    {
        $action=Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Closing\NonprofitDppoAction::class);
        foreach(['session'=>true,'bearer'=>false]as$auth=>$can){$q=(new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','/api/accounting/nonprofit-dppo')->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplier)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$this->actor,'role'=>'admin'])->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,$auth);$r=$action->profile($q,new \Slim\Psr7\Response(),['id'=>(string)$this->period]);$data=json_decode((string)$r->getBody(),true);if($auth==='bearer'){self::assertSame(403,$r->getStatusCode());self::assertSame('token_endpoint_forbidden',$data['error']['code']);self::assertArrayNotHasKey('capabilities',$data);self::assertArrayNotHasKey('latest',$data);continue;}self::assertSame(200,$r->getStatusCode());self::assertSame($can,$data['capabilities']['can_confirm']);self::assertFalse($data['capabilities']['filing_ready']);self::assertNull($data['latest']);self::assertNotContains('commercial_corporation',$data['entity_types']);}
    }
    public function testHistoricalProofCannotBeUpdated():void
    {
        $a=$this->service->confirm($this->supplier,$this->period,$this->input(),$this->actor);$this->expectException(\PDOException::class);$this->pdo->prepare("UPDATE nonprofit_legal_profiles SET record_hash=? WHERE id=?")->execute([str_repeat('0',64),$a['id']]);
    }
}
