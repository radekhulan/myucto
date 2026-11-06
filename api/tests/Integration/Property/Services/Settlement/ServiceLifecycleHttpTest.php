<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};

final class ServiceLifecycleHttpTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;
    private \DI\Container $container;
    private \Slim\App $app;
    private array $f;
    protected function setUp(): void
    {
        $this->fixture=new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        (new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
        $this->db=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
        $this->container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);
        $this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$this->container);
        \MyInvoice\Routes::register($this->app);
        $this->app->add($this->container->get(CsrfMiddleware::class));
    }
    protected function tearDown(): void {(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);}
    private function invoke(string $method,mixed ...$args): mixed {return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function http(string $method,string $path,array $body=[],?array $permissions=null,string $auth='session',bool $csrf=true,?int $supplier=null,array $query=[]): array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/property/service-lifecycles'.$path)->withParsedBody($body)->withQueryParams($query)
          ->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'accountant'])
          ->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-lifecycle-csrf'])
          ->withAttribute('auth.effective_role',new EffectiveRole(99,'Synthetic lifecycle role','staff',true,$permissions??['property'=>1,'property.settlements.manage'=>2,'property.settlements.post'=>2,'property.settlements.reverse'=>2,'property.settlements.unwind'=>2]))
          ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier??$this->f['supplier'])
          ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))
          ->withHeader('Origin','https://example.test');
        if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-lifecycle-csrf');
        $response=$this->app->handle($q);return[$response->getStatusCode(),json_decode((string)$response->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    private function fingerprint(): string
    {
        $pdo=$this->db->pdo();$q=$pdo->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()");$types=array_column($q->fetchAll(\PDO::FETCH_ASSOC),'TABLE_TYPE','TABLE_NAME');$out=[];
        foreach(['property_service_lifecycle_cases','property_service_lifecycle_events','property_service_lifecycle_claims','property_service_lifecycle_commands','journal_entries','journal_entry_lines','journal_entry_line_dimensions','journal_entry_line_dimension_splits','document_dimensions','document_dimension_splits','property_opening_batches','property_opening_items','property_opening_components','property_opening_states','unit_payment_debts','unit_payment_debt_components','unit_payment_allocations','unit_credit_lots','property_service_settlement_applications','activity_log'] as $table) {
            if(!isset($types[$table]))continue;
            $q=$pdo->prepare('SELECT * FROM `'.$table.'`'.($types[$table]==='SYSTEM VERSIONED'?' FOR SYSTEM_TIME ALL':'').' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$rows=array_map(fn($a)=>json_encode($a,JSON_THROW_ON_ERROR),$q->fetchAll(\PDO::FETCH_ASSOC));sort($rows,SORT_STRING);$out[$table]=$rows;
        }
        return PropertyInput::hash($out);
    }
    private function review(array $command): array
    {
        $before=$this->fingerprint();[$status,$preview]=$this->http('POST','/preview/'.$command['operation'],['command'=>$command]);
        self::assertSame(200,$status,json_encode($preview));self::assertSame($before,$this->fingerprint(),'Preview changed persisted data/history');
        [$again,$repeated]=$this->http('POST','/preview/'.$command['operation'],['command'=>$command]);self::assertSame(200,$again,json_encode($repeated));self::assertSame($preview,$repeated,'Preview identity depends on discarded IDs/timestamps');self::assertSame($before,$this->fingerprint());
        return $preview;
    }
    private function commit(array $command,array $preview,string $token): array
    {
        $body=['command'=>$command,'expected_state'=>$preview['expected_state'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>$token];
        [$status,$done]=$this->http('POST','/commands/'.$command['operation'],$body);self::assertSame(200,$status,json_encode($done));$before=$this->fingerprint();
        [$status,$replay]=$this->http('POST','/commands/'.$command['operation'],$body);self::assertSame(200,$status,json_encode($replay));self::assertSame($done,$replay);self::assertSame($before,$this->fingerprint(),'Replay mutated ledger/command history');
        $body['preview_hash']=str_repeat('a',64);[$status,$conflict]=$this->http('POST','/commands/'.$command['operation'],$body);self::assertSame(409,$status,json_encode($conflict));self::assertSame($before,$this->fingerprint());return $done;
    }
    public function testNativeBalanceHttpStartPreviewCasReplayUnwindFinal(): void
    {
        $d=$this->invoke('base','service_balance_sheet');$this->f=$d['f'];$s=$this->f['supplier'];
        // The shared native fixture registers its estimate; detach only its fresh ownership records to exercise first registration through HTTP.
        foreach(['claims','events','cases'] as $suffix)$this->db->pdo()->prepare('DELETE FROM property_service_lifecycle_'.$suffix.' WHERE supplier_id=?')->execute([$s]);
        $c=['operation'=>'start','unit_id'=>$this->f['units']['U02'],'service_id'=>$this->f['service']['id'],'period_from'=>'2026-01-01','period_to'=>'2026-12-31','policy'=>'service_balance_sheet','evidence'=>'Synthetic approved HTTP service policy','service_cost_account'=>'315.210','entry_ids'=>$d['entries'],'coverage_title'=>'Synthetic original coverage title'];
        $preview=$this->review($c);self::assertSame('1100000',$preview['plan']['estimate_cents']);
        $this->db->pdo()->exec('SAVEPOINT changed_preview_source');
        try{$q=$this->db->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='SYNTHETIC-CHANGED' WHERE supplier_id=? AND entry_id=?");$q->execute([$s,$d['entries'][0]]);[$status,$stale]=$this->http('POST','/commands/start',['command'=>$c,'expected_state'=>$preview['expected_state'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'source-changed']);self::assertSame(409,$status,json_encode($stale));}
        finally{$this->db->pdo()->exec('ROLLBACK TO SAVEPOINT changed_preview_source');}
        $done=$this->commit($c,$preview,'native-start');$d['case']=$done['case_id'];
        $before=$this->fingerprint();[$status]=$this->http('POST','/commands/start',['command'=>$c,'expected_state'=>$preview['expected_state'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'new-stale-start']);self::assertSame(409,$status);self::assertSame($before,$this->fingerprint());
        [$status,$choices]=$this->http('GET','/choices',query:['date'=>'2027-04-01']);self::assertSame(200,$status,json_encode($choices));self::assertNotEmpty($choices['units']);self::assertNotEmpty($choices['accounts']);
        [$status,$sources]=$this->http('GET','/sources/estimates',query:['unit_id'=>$c['unit_id'],'service_id'=>$c['service_id'],'from'=>'2026-01-01','to'=>'2026-12-31']);self::assertSame(200,$status,json_encode($sources));self::assertContains($d['entries'][0],array_column($sources['items'],'id'));
        $this->completeCase($d);
    }
    public function testNativeProfitAndLossHttpPreviewLeavesOriginalUnreversed(): void
    {
        $d=$this->invoke('base','service_profit_and_loss');$this->f=$d['f'];$this->completeCase($d);
    }
    private function completeCase(array $d): void
    {
        $c=['operation'=>'unwind','case_id'=>$d['case'],'accounting_date'=>'2027-04-01'];$preview=$this->review($c);self::assertNotEmpty($preview['plan']['journals']);$this->commit($c,$preview,'native-unwind');
        $invoice=$this->invoke('invoice',$d);$c=['operation'=>'finalize','case_id'=>$d['case'],'accounting_date'=>'2027-04-01','invoice_id'=>$invoice['invoice'],'line_id'=>$invoice['line']['id'],'title'=>'Synthetic final legal service allocation'];$preview=$this->review($c);self::assertSame('1120000',$preview['plan']['final_cents']);$done=$this->commit($c,$preview,'native-final');
        [$status,$detail]=$this->http('GET','/'.$done['case_id']);self::assertSame(200,$status,json_encode($detail));self::assertSame('finalized',$detail['phase']);self::assertSame('1120000',$detail['final_cents']);
        $this->invoke('applySettlement',$d,$detail['cost_line_id'],'0');
    }
    public function testChoicesUsePragueWinterAndSummerDayEnd(): void
    {
        $d=$this->invoke('openingBase','service_balance_sheet',false);$this->f=$d['f'];
        foreach([['2026-01-01','2026-01-01T22:59:59.999999Z','2026-01-02T00:00:00+01:00'],['2026-07-01','2026-07-01T21:59:59.999999Z','2026-07-02T00:00:00+02:00']] as [$date,$expected,$next]){
            [$status,$choices]=$this->http('GET','/choices',query:['date'=>$date]);self::assertSame(200,$status,json_encode($choices));self::assertSame($expected,$choices['as_of']);self::assertNotEmpty($choices['units']);self::assertFalse(\MyInvoice\Service\Property\PropertyInstant::contains($next,null,$choices['as_of']));
        }
    }
    public function testNativeS6HttpStartOpeningAndSessionPermissionTenantGuards(): void
    {
        $d=$this->invoke('openingBase','service_profit_and_loss',false);$this->f=$d['f'];$s=$this->f['supplier'];foreach(['claims','events','cases'] as $suffix)$this->db->pdo()->prepare('DELETE FROM property_service_lifecycle_'.$suffix.' WHERE supplier_id=?')->execute([$s]);
        $c=['operation'=>'start_opening','unit_id'=>$this->f['units']['U02'],'service_id'=>$this->f['service']['id'],'period_from'=>'2026-01-01','period_to'=>'2026-12-31','policy'=>'service_profit_and_loss','evidence'=>'Synthetic original signed external policy','service_cost_account'=>'315.210','opening_item_id'=>$d['selector']['service_item_id']];
        $before=$this->fingerprint();
        foreach([['bearer',true,null],['session',false,null],['session',true,['property'=>1]]] as [$auth,$csrf,$permissions]){[$status]=$this->http('POST','/preview/start_opening',['command'=>$c],$permissions,$auth,$csrf);self::assertSame(403,$status);self::assertSame($before,$this->fingerprint());}
        [$nullStatus]=$this->http('POST','/preview/unwind',['command'=>['operation'=>'unwind','case_id'=>$d['case'],'accounting_date'=>null]]);self::assertSame(422,$nullStatus);self::assertSame($before,$this->fingerprint());
        [$status]=$this->http('POST','/preview/start_opening',['command'=>$c],supplier:$s+999999);self::assertNotSame(200,$status);self::assertSame($before,$this->fingerprint());
        $preview=$this->review($c);self::assertSame('11000000',$preview['plan']['estimate_cents']);$done=$this->commit($c,$preview,'native-opening-start');
        $unwind=['operation'=>'unwind','case_id'=>$done['case_id'],'accounting_date'=>'2027-04-01'];[$status]=$this->http('POST','/preview/unwind',['command'=>$unwind],['property'=>1,'property.settlements.manage'=>2,'property.settlements.reverse'=>2]);self::assertSame(403,$status);[$status,$body]=$this->http('POST','/preview/unwind',['command'=>$unwind],['property'=>1,'property.settlements.unwind'=>2]);self::assertSame(200,$status,json_encode($body));
        $preview=$this->review($unwind);self::assertCount(4,$preview['plan']['journals'][0]['lines']);$this->commit($unwind,$preview,'native-opening-unwind');
        $d['case']=$done['case_id'];$invoice=$this->invoke('invoice',$d);$final=['operation'=>'finalize','case_id'=>$d['case'],'accounting_date'=>'2027-04-01','invoice_id'=>$invoice['invoice'],'line_id'=>$invoice['line']['id'],'title'=>'Synthetic S6 final legal allocation'];$preview=$this->review($final);self::assertSame('11200000',$preview['plan']['final_cents']);$this->commit($final,$preview,'native-opening-final');
        [$status,$detail]=$this->http('GET','/'.$d['case']);self::assertSame(200,$status,json_encode($detail));self::assertSame('finalized',$detail['phase']);$this->invoke('applySettlement',$d,$detail['cost_line_id'],$d['paid']);
        [$status,$page]=$this->http('GET','');self::assertSame(200,$status);self::assertCount(1,$page['items']);
    }
}
