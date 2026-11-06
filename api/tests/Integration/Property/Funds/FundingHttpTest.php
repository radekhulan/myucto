<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Tests\Support\FundingFixture;
use MyInvoice\Middleware\{AuthMiddleware,CsrfMiddleware,SupplierScopeMiddleware,TenantDomainMiddleware};
use MyInvoice\Security\{EffectiveRole,PermissionCatalog};
use MyInvoice\Service\Property\PropertyInput;
use Slim\Psr7\Factory\{ServerRequestFactory,ResponseFactory};
use PHPUnit\Framework\TestCase;
final class FundingHttpTest extends TestCase
{
    use FundingFixture {setUp as private baseSetup;}
    private \Slim\App $app;
    protected function setUp():void {$this->baseSetup();$this->app=\Slim\Factory\AppFactory::create(new ResponseFactory(),$this->container);\MyInvoice\Routes::register($this->app);$this->app->add($this->container->get(CsrfMiddleware::class));}
    private function http(string $method,string $path,array $body=[],?array $permissions=null,string $auth='session',bool $csrf=true,?int $supplier=null,array $query=[],string $type='staff'):array
    {
        $q=(new ServerRequestFactory())->createServerRequest($method,'/api/property/funds'.$path)->withParsedBody($body)->withQueryParams($query)->withAttribute(AuthMiddleware::ATTR_METHOD,$auth)->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->f['actor'],'role'=>'accountant'])->withAttribute(AuthMiddleware::ATTR_SESSION,['csrf_token'=>'synthetic-fund-csrf'])->withAttribute('auth.effective_role',new EffectiveRole(123,'Synthetic funds',$type,true,$permissions??['property'=>1,'property.funds.post'=>2,'property.funds.reverse'=>2]))->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$supplier??$this->f['supplier'])->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT,new \MyInvoice\Service\Tenant\TenantDomainContext('canonical','example.test','https://example.test'))->withHeader('Origin','https://example.test');if($csrf)$q=$q->withHeader('X-CSRF-Token','synthetic-fund-csrf');$r=$this->app->handle($q);return[$r->getStatusCode(),json_decode((string)$r->getBody(),true,flags:JSON_THROW_ON_ERROR)];
    }
    private function fingerprint():string {$all=[];foreach(['property_funding_heads','property_funding_operations','property_funding_effects','property_funding_claims','journal_entries','journal_entry_lines','journal_entry_line_dimensions','journal_entry_line_dimension_splits'] as $table){$q=$this->db->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=?');$q->execute([$this->f['supplier']]);$all[$table]=$q->fetchAll(\PDO::FETCH_ASSOC);}return PropertyInput::hash($all);}
    private function income():array {return$this->fundingRequest('income','2026-03-01','6000000')+['bank_id'=>$this->fundingBank('2026-03-01','60000.00'),'key'=>$this->fundingKey('2026-03-01')];}
    public function testStaffNativePreviewReplaySourcesAndScope():void
    {
        $command=$this->income();$before=$this->fingerprint();[$status,$preview]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(200,$status,json_encode($preview));self::assertSame($before,$this->fingerprint());[$status,$again]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(200,$status);self::assertSame($preview,$again);self::assertSame($before,$this->fingerprint());[$status,$sources]=$this->http('GET','/sources/banks',query:['date'=>'2026-03-01']);self::assertSame(200,$status,json_encode($sources));self::assertSame($command['bank_id'],$sources['items'][0]['id']);
        $body=['command'=>$command,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'http-income'];[$status,$done]=$this->http('POST','/commands',$body);self::assertSame(200,$status,json_encode($done));$before=$this->fingerprint();[$status,$again]=$this->http('POST','/commands',$body);self::assertSame(200,$status);self::assertSame($done,$again);self::assertSame($before,$this->fingerprint());$body['idempotency_key']='stale';[$status]=$this->http('POST','/commands',$body);self::assertSame(409,$status);self::assertSame($before,$this->fingerprint());
        [$status,$read]=$this->http('GET','/balances',query:['date'=>'2026-03-31']);self::assertSame(200,$status,json_encode($read));self::assertTrue($read['matches']);self::assertSame('6000000',$read['totals']['379.920']);[$status,$choices]=$this->http('GET','/choices',query:['date'=>'2026-03-01']);self::assertSame(200,$status,json_encode($choices));self::assertCount(12,$choices['units']);[$status,$other]=$this->http('GET','/balances',supplier:1,query:['date'=>'2026-03-31']);self::assertSame(200,$status,json_encode($other));self::assertSame([],$other['totals']);
    }
    public function testCoverageCatalogOffersOnlyEligibleNativeRevenueAndReadableDescriptions():void
    {
        $supplier=$this->f['supplier'];$cost=$this->fundingCost('2026-03-01','549.900','500.00');
        $proof=(new \MyInvoice\Service\Property\Funds\FundingSourceProof($this->db))->cost($supplier,$cost,'2026-03-01','repair');
        $line=$proof['line'];$entries=[];
        foreach(['2026-02-28','2026-04-01'] as $date)$entries[]=$this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($supplier,'manual',null,[
            ['account_code'=>'649.100','side'=>'credit','amount'=>'200.00','dimensions'=>$line['dimensions'],'dimension_origins'=>$line['dimension_origins']],
            ['account_code'=>'221','side'=>'debit','amount'=>'200.00'],
        ],['entry_date'=>$date,'posted_by'=>$this->f['actor'],'description'=>'Synthetic insurance recovery']);
        [$status,$sources]=$this->http('GET','/sources/coverages',query:['date'=>'2026-03-01']);self::assertSame(200,$status,json_encode($sources));self::assertCount(1,$sources['items']);
        $item=$sources['items'][0];self::assertSame($entries[0],$item['ledger']['entry']['id']);self::assertSame('20000',$item['amount_cents']);self::assertSame('Synthetic insurance recovery',$item['description']);self::assertSame('2026-02-28',$item['entry_date']);self::assertSame('649.100',$item['account_code']);self::assertNotEmpty($item['account_name']);
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET is_red_storno=1 WHERE supplier_id=? AND id=?')->execute([$supplier,$item['id']]);
        [$status,$sources]=$this->http('GET','/sources/coverages',query:['date'=>'2026-03-01']);self::assertSame(200,$status,json_encode($sources));self::assertSame([],$sources['items']);self::assertSame('property_funding_coverage_source',$sources['incomplete'][0]['reason']);
    }
    public function testSessionCsrfReadOnlyClientAndSeparateReverseGrant():void
    {
        $command=$this->income();$before=$this->fingerprint();foreach(['bearer',''] as $auth){[$status]=$this->http('POST','/preview',['command'=>$command],auth:$auth);self::assertSame(403,$status,'blocked auth/CSRF/grant step');}[$status]=$this->http('POST','/preview',['command'=>$command],csrf:false);self::assertSame(403,$status,'csrf');[$status]=$this->http('POST','/preview',['command'=>$command],permissions:['property'=>1,'property.funds.post'=>1]);self::assertSame(403,$status,'readonly');[$status]=$this->http('POST','/preview',['command'=>$command],type:'client');self::assertSame(403,$status,'client');$reverse=$this->fundingRequest('reverse','2026-03-02');unset($reverse['amount_cents']);$reverse['operation_id']=1;[$status]=$this->http('POST','/preview',['command'=>$reverse],permissions:['property'=>1,'property.funds.post'=>2]);self::assertSame(403,$status,'reverse grant');self::assertSame($before,$this->fingerprint());$catalog=new PermissionCatalog();self::assertSame(2,$catalog->legacyPreset('accountant')['property.funds.post']);self::assertArrayNotHasKey('property.funds.reverse',$catalog->legacyPreset('accountant'));self::assertArrayNotHasKey('property.funds.post',$catalog->legacyPreset('readonly'));
    }
    public function testStaleNativeSourceAndSoftCloseRejectWithoutPartialWrites():void
    {
        $command=$this->income();[$status,$preview]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(200,$status);$this->db->pdo()->prepare("UPDATE bank_transactions SET counterparty_name='Synthetic changed owner income' WHERE id=?")->execute([$command['bank_id']]);$before=$this->fingerprint();[$status]=$this->http('POST','/commands',['command'=>$command,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'changed']);self::assertSame(409,$status);self::assertSame($before,$this->fingerprint());$this->db->pdo()->prepare("INSERT INTO accounting_supplier_settings(supplier_id,locked_until) VALUES(?,'2026-03-01') ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)")->execute([$this->f['supplier']]);[$status]=$this->http('POST','/preview',['command'=>$command]);self::assertSame(409,$status);self::assertSame($before,$this->fingerprint());
    }
}
