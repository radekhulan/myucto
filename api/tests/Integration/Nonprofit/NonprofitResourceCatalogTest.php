<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Tests\Support\{ResourceReadBearerFixture,NonprofitResourceCaseFixture};
use MyInvoice\Service\Nonprofit\Catalog\NonprofitResourceCatalog;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use PHPUnit\Framework\TestCase;

final class NonprofitResourceCatalogTest extends TestCase
{
    use ResourceReadBearerFixture,NonprofitResourceCaseFixture;
    public function testActualFundingReaderKeepsNativeAmountsStableIdentityAndNoDomainDml():void
    {
        $source=$this->fundingSource();$catalog=$this->container->get(NonprofitResourceCatalog::class);$before=$this->domainDmlCounters();$direct=$catalog->funding($this->supplier);self::assertSame($before,$this->domainDmlCounters());
        $token=$this->token();[$status,$body]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-funding',$token);self::assertSame(200,$status,json_encode($body));self::assertSame($direct,$body);self::assertCount(1,$body['items']);$item=$body['items'][0];self::assertSame($source['id'],$item['id']);self::assertNull($item['used_cents']);self::assertNull($item['available_cents']);self::assertSame('recorded_chain_verified',$item['proof_status']);self::assertNull($item['source_current']);self::assertNull($item['usage_count']);self::assertFalse($body['coverage']['complete']);self::assertSame('not_assessed',$body['coverage']['pending_coverage']);self::assertSame('unknown',$body['coverage']['purpose_decision']);self::assertSame('not_available',$body['coverage']['usage_links']);$this->assertRedacted($body);
        $policy=$this->fundingPolicy;$policy['budget_cents']=75000;$service=$this->container->get(FundingService::class);$preview=$service->preview($this->supplier,$this->period,'PRIVATE-SOURCE-KEY',$policy);$revision=$service->confirm($this->supplier,$this->period,'PRIVATE-SOURCE-KEY',$policy,$preview['input_hash'],$preview['expected_revision'],self::PRIVATE_SENTINEL,'revise-budget',$this->actor);
        [$status,$detail]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-funding/'.$source['id'],$token);self::assertSame(200,$status,json_encode($detail));self::assertSame($source['id'],$detail['item']['id']);self::assertSame($revision['id'],$detail['item']['current_revision_id']);self::assertSame(2,$detail['item']['revision_no']);self::assertSame('75000',$detail['item']['budget_cents']);self::assertNull($detail['item']['available_cents']);$this->assertRedacted($detail);
        [$status,$scoped]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-funding',$token,['supplier_id'=>$this->otherSupplier],['X-Supplier-Id'=>(string)$this->otherSupplier]);self::assertSame(200,$status);self::assertSame($detail['item'],$scoped['items'][0]);
    }
    public function testWithdrawnAssessmentDoesNotRewriteRecordedMetadataOrInventCurrentStatus():void
    {
        $source=$this->fundingSource();$this->container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService::class)->withdraw($this->supplier,$this->period,$this->taxReceipt['id'],self::PRIVATE_SENTINEL,'withdraw-tax',$this->actor);
        [$status,$body]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-funding/'.$source['id'],$this->token());self::assertSame(200,$status,json_encode($body));self::assertNull($body['item']['source_current']);self::assertNull($body['item']['available_cents']);self::assertSame('recorded_chain_verified',$body['item']['proof_status']);self::assertSame('unknown',$body['coverage']['purpose_decision']);self::assertContains('purpose_decision_coverage_unknown',array_column($body['coverage']['findings'],'code'));$this->assertRedacted($body);
    }
    public function testCanonicalFundUseAndNativeReversalAreRedactedAndPaged():void
    {
        $source=$this->capitalSource();$service=new CapitalService($this->db,$this->container->get(PostingService::class));$cost=$this->nativePost('518','800.00','debit');$preview=$service->previewFund($this->supplier,$source['id'],$cost,60000,'2026-01-01');$event=$service->confirmFund($this->supplier,$source['id'],$cost,60000,'2026-01-01',$preview['input_hash'],self::PRIVATE_SENTINEL,'actual-use',$this->actor);
        $catalog=$this->container->get(NonprofitResourceCatalog::class);$before=$this->domainDmlCounters();$catalog->capital($this->supplier);self::assertSame($before,$this->domainDmlCounters());$token=$this->token();[$status,$body]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$source['id'],$token);self::assertSame(200,$status,json_encode($body));self::assertSame('60000',$body['item']['used_cents']);self::assertSame('440000',$body['item']['available_cents']);$this->assertRedacted($body);
        $preview=$service->previewReverse($this->supplier,$event['id'],'2026-02-01');$reverse=$service->confirmReverse($this->supplier,$event['id'],'2026-02-01',$preview['input_hash'],self::PRIVATE_SENTINEL,'reverse-use',$this->actor);
        [$status,$first]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$source['id'].'/events',$token,['limit'=>1]);self::assertSame(200,$status,json_encode($first));self::assertSame($event['id'],$first['items'][0]['id']);self::assertFalse($first['items'][0]['active']);self::assertSame($event['id'],$first['next_cursor']);self::assertSame($cost,$first['items'][0]['cost_line_id']);
        [$status,$second]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$source['id'].'/events',$token,['after_id'=>$first['next_cursor'],'limit'=>1]);self::assertSame(200,$status);self::assertSame($reverse['id'],$second['items'][0]['id']);self::assertSame($event['id'],$second['items'][0]['original_id']);self::assertSame('reverse',$second['items'][0]['operation']);self::assertSame('2026-02-01',$second['items'][0]['date']);self::assertNull($second['next_cursor']);$this->assertRedacted($first);$this->assertRedacted($second);
    }
    public function testCanonicalInvestmentDepreciationProofIsReadFromOwner():void
    {
        $source=$this->capitalSource('investment');$result=$this->container->get(DepreciationPostingService::class)->bookYear($this->supplier,2026,['posted_by'=>$this->actor,'user_id'=>$this->actor]);self::assertSame([],$result['errors']);
        $token=$this->token();[$status,$body]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$source['id'],$token);self::assertSame(200,$status,json_encode($body));self::assertSame('5999760',$body['item']['used_cents']);self::assertGreaterThan(0,$body['item']['asset_id']);self::assertSame('investment',$body['item']['kind']);
        [$status,$events]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$source['id'].'/events',$token);self::assertSame(200,$status,json_encode($events));self::assertGreaterThan(0,$events['items'][0]['depreciation_id']);self::assertNull($events['items'][0]['cost_line_id']);self::assertTrue($events['items'][0]['active']);$this->assertRedacted($body);$this->assertRedacted($events);
    }
    public function testSourcePaginationForeignIdsAndReadGrantsAreNativeScoped():void
    {
        $source=$this->capitalSource();$service=new CapitalService($this->db,$this->container->get(PostingService::class));$policy=$source['policy'];$policy['source_line_id']=$this->nativePost('911','7000.00');$preview=$service->previewSource($this->supplier,$policy);$second=$service->confirmSource($this->supplier,$policy,$preview['input_hash'],self::PRIVATE_SENTINEL,'second-source',$this->actor);$own=$this->supplier;$this->supplier=$this->otherSupplier;$foreign=$this->capitalSource();$this->supplier=$own;
        $token=$this->token();[$status,$page]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital',$token,['limit'=>1]);self::assertSame(200,$status,json_encode($page));self::assertSame($source['id'],$page['next_cursor']);[$status,$next]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital',$token,['after_id'=>$page['next_cursor'],'limit'=>1]);self::assertSame(200,$status);self::assertSame($second['id'],$next['items'][0]['id']);self::assertNull($next['next_cursor']);
        [$status,$empty]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital',$token,['after_id'=>$second['id']]);self::assertSame(200,$status);self::assertSame([],$empty['items']);self::assertNull($empty['next_cursor']);self::assertFalse($empty['coverage']['complete']);
        foreach(['','/events']as$suffix){[$status]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital/'.$foreign['id'].$suffix,$token);self::assertSame(404,$status);}
        [$status]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital',$this->token(['property'=>1]));self::assertSame(403,$status);
    }
    public function testNativeSourceDriftFailsClosedBeforeReturningAnyCapitalDtos():void
    {
        $source=$this->capitalSource();$this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND id=?')->execute([$this->supplier,$source['source_line_id']]);[$status,$body]=$this->http('GET','/api/v1/accounting/catalog/nonprofit-capital',$this->token());self::assertSame(409,$status);self::assertSame('nonprofit_capital_changed',$body['error']['code']);self::assertArrayNotHasKey('items',$body);
    }
    public function testUnsupportedTimesInvalidFiltersAndEveryMutationRemainForbidden():void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET legal_form='706' WHERE id=?")->execute([$this->supplier]);
        $token=$this->token();foreach(['nonprofit-funding','nonprofit-capital']as$resource){$path='/api/v1/accounting/catalog/'.$resource;foreach(['date','as_of','known_at']as$field){[$status,$body]=$this->http('GET',$path,$token,[$field=>'2026-01-01']);self::assertSame(409,$status);self::assertSame('nonprofit_catalog_time_unsupported',$body['error']['code']);}foreach([['limit'=>0],['limit'=>101],['after_id'=>-1],['payload'=>[]]]as$query){[$status]=$this->http('GET',$path,$token,$query);self::assertSame(422,$status);}foreach(['POST','PUT','PATCH','DELETE']as$method){[$status]=$this->http($method,$path,$token);self::assertSame(403,$status);}}
    }
    private function assertRedacted(array $body):void
    {
        $json=json_encode($body,JSON_THROW_ON_ERROR);foreach([self::PRIVATE_SENTINEL,'PRIVATE-SOURCE-KEY','policy_json','basis_json','request_hash','record_hash','receipt','evidence','created_by','cost_center','company_name','decision_authority']as$sentinel)self::assertStringNotContainsString($sentinel,$json);self::assertFalse($body['financial_authorization']);self::assertSame('not_assessed',$body['coverage']['pending_coverage']);
    }
}
