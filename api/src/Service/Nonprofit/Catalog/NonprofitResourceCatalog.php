<?php

declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Catalog;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Nonprofit\Capital\CapitalService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

final class NonprofitResourceCatalog
{
    public function __construct(private readonly Connection $db,private readonly FundingCatalogRead $funding) {}

    public function funding(int $supplier,int $after=0,int $limit=25,?int $id=null): array
    {
        return $this->snapshot($supplier,function()use($supplier,$after,$limit,$id):array{
            return $this->funding->read($supplier,$after,$limit,$id);
        });
    }

    public function capital(int $supplier,int $after=0,int $limit=25,?int $id=null,bool $events=false): array
    {
        return $this->snapshot($supplier,function()use($supplier,$after,$limit,$id,$events):array{
            $native=(new CapitalService($this->db))->read($supplier);$items=[];$eventItems=[];$found=$id===null;
            foreach($native['sources']as$report){
                $source=$report['source'];$policy=$source['policy'];$sourceId=$source['id'];
                $items[$sourceId]=[
                    'id'=>$sourceId,'kind'=>$source['kind'],'source_line_id'=>$source['source_line_id'],'asset_id'=>$source['asset_id'],'provider_client_id'=>$source['provider_id'],
                    'valid_from'=>$policy['valid_from'],'valid_to'=>$policy['valid_to'],'activity_kind_ids'=>$policy['activity_kind_ids'],'account_prefixes'=>$policy['account_prefixes'],
                    'amount_cents'=>$this->cents($source['amount_cents']),'used_cents'=>$this->cents($report['used_cents']),'available_cents'=>$this->cents($report['available_cents']),
                    'proof_status'=>'current','event_count'=>count($report['events']),
                ];
                if($sourceId!==$id)continue;$found=true;
                foreach($report['events']as$event)$eventItems[$event['id']]=[
                    'id'=>$event['id'],'source_id'=>$event['source_id'],'operation'=>$event['operation'],'date'=>$event['receipt']['entry']['entry_date'],
                    'amount_cents'=>$this->cents($event['amount_cents']),'entry_id'=>$event['entry_id'],'cost_line_id'=>$event['cost_line_id'],'depreciation_id'=>$event['depreciation_id'],
                    'previous_id'=>$event['previous_id'],'original_id'=>$event['original_id'],'active'=>$event['active'],'proof_status'=>'current',
                ];
            }
            if(!$found)$this->notFound();
            return $this->result($events?$eventItems:$items,$after,$limit,$events?null:$id,'current_canonical_capital_ledger',[],['recorded_sources_verified'=>true,'recorded_events_verified'=>true]);
        });
    }

    private function result(array $items,int $after,int $limit,?int $id,string $provenance,array $findings,array $coverage): array
    {
        $findings[]=['code'=>'pending_coverage_not_assessed'];
        $meta=['temporal_basis'=>'current_knowledge','consistency'=>'canonical_owner_transaction','provenance'=>$provenance,
            'coverage'=>['complete'=>false,'pending_coverage'=>'not_assessed','findings'=>$findings]+$coverage,'financial_authorization'=>false];
        if($id!==null){if(!isset($items[$id]))$this->notFound();return ['item'=>$items[$id]]+$meta;}
        ksort($items,SORT_NUMERIC);$page=array_slice(array_values(array_filter($items,static fn(array $item):bool=>$item['id']>$after)),0,$limit+1);$more=count($page)>$limit;$page=array_slice($page,0,$limit);
        return ['items'=>$page,'next_cursor'=>$more?$page[array_key_last($page)]['id']:null]+$meta;
    }

    private function cents(?int $value): ?string{return $value===null?null:(string)$value;}
    private function notFound(): never{throw new NonprofitTaxAssessmentException('nonprofit_catalog_not_found','Zdroj nebyl nalezen v dostupné firmě.',404);}
    private function snapshot(int $supplier,callable $read): array
    {
        $pdo=$this->db->pdo();
        if(!$pdo->inTransaction())$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        elseif($pdo->query('SELECT @@tx_isolation')->fetchColumn()!=='REPEATABLE-READ')throw new NonprofitTaxAssessmentException('nonprofit_catalog_snapshot_required','Čtení vyžaduje konzistentní databázový snapshot.',409);
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$read):array{$guard->lockPeriods($supplier);return $read();});
    }
}
