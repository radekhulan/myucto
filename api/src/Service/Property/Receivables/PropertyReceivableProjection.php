<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PartyEventRepository,PropertySaldoReadRepository};
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
final class PropertyReceivableProjection
{
    public function __construct(private readonly Connection $db){}
    public function at(int $supplier,string $asOf,string $knownAt,array $scope=[],bool $currentRead=false):array
    {
        $asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);PropertyInput::fields($scope,['unit_id','client_id']);foreach($scope as $id)PropertyInput::id($id,'scope');
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Current projection requires owner period locks.');
        $owned=!$this->db->pdo()->inTransaction();if($owned){$this->db->pdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->pdo()->exec('START TRANSACTION READ ONLY');}
        try{
            if(!$currentRead&&isset($scope['unit_id'])){
                // Karta jednotky čte jen uzávěr dané jednotky po indexech, ne celou firmu.
                $settlements=$this->serviceSettlementState($supplier,$asOf,$knownAt,$scope['unit_id']);
                $snapshot=PropertyInstant::withNormalizationContext(function()use($supplier,$asOf,$knownAt,$scope,$settlements):array{
                    $scoped=(new PropertySaldoReadRepository($this->db))->unitHistory($supplier,[$scope['unit_id']]);
                    $history=(new PropertyReceivableHistoryByUnit($scoped['history']))->forUnit($scope['unit_id']);
                    return self::reducePreparedReadHistory($supplier,$asOf,$knownAt,$scope,$history,$scoped['events'],$settlements);
                });
                if($owned)$this->db->pdo()->commit();return $snapshot+['snapshot_hash'=>PropertyInput::hashLists($snapshot,['claims','credits','current_party_heads'])];
            }
            $history=(new FinancialHistoryRepository($this->db))->readProjection($supplier,$currentRead);$events=(new PartyEventRepository($this->db))->history($supplier,$currentRead);
            $snapshot=self::reduceReadHistory($supplier,$asOf,$knownAt,$scope,$history,$events,$this->serviceSettlementState($supplier,$asOf,$knownAt,$scope['unit_id']??null));
            unset($history,$events);
            if($owned)$this->db->pdo()->commit();return $snapshot+['snapshot_hash'=>PropertyInput::hashLists($snapshot,['claims','credits','current_party_heads'])];
        }catch(\Throwable $e){if($owned&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();throw $e;}
    }
    /**
     * Stav vyúčtování služeb v rozsahu karty: none = žádný dokument vyúčtování,
     * applied = poslední revize všech dokumentů je zaúčtovaná (a nestornovaná),
     * pending = některý dokument čeká na zaúčtování, takže karta ještě nenese jeho
     * přeplatek ani nedoplatek.
     */
    private function serviceSettlementState(int $supplier,string $asOf,string $knownAt,?int $unit):string
    {
        $states=self::serviceSettlementStates($this->db,$supplier,$asOf,$knownAt,$unit===null?null:[$unit]);
        return self::combineSettlementStates($unit===null?array_values($states):[$states[$unit]??'none']);
    }
    /**
     * Stav vyúčtování pro více jednotek jedním dotazem (karta i stránkované saldo).
     * @param list<int>|null $units null = všechny jednotky firmy
     * @return array<int,string> unit_id => none|pending|applied (jednotky bez dokumentu chybí)
     */
    public static function serviceSettlementStates(Connection $db,int $supplier,string $asOf,string $knownAt,?array $units):array
    {
        if($units===[])return [];
        $sql='SELECT s.unit_id,COUNT(*) AS documents,COUNT(a.id) AS applied FROM property_service_settlements s'
            .' LEFT JOIN property_service_settlement_heads h ON h.supplier_id=s.supplier_id AND h.document_id=s.id'
            .' LEFT JOIN property_service_settlement_applications a ON a.supplier_id=h.supplier_id AND a.id=h.application_id'
            .' AND a.reversal_of IS NULL AND a.accounting_date<=? AND a.recorded_at<=?'
            .' WHERE s.supplier_id=? AND s.recorded_at<=?'
            .' AND NOT EXISTS(SELECT 1 FROM property_service_settlements n WHERE n.supplier_id=s.supplier_id AND n.previous_id=s.id AND n.recorded_at<=?)';
        $known=PropertyInstant::sql($knownAt);
        $day=(new \DateTimeImmutable($asOf))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        $args=[$day,$known,$supplier,$known,$known];
        if($units!==null){
            $sql.=' AND s.unit_id IN ('.implode(',',array_fill(0,count($units),'?')).')';
            array_push($args,...$units);
        }
        $q=$db->pdo()->prepare($sql.' GROUP BY s.unit_id');$q->execute($args);
        $states=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row)$states[(int)$row['unit_id']]=(int)$row['applied']===(int)$row['documents']?'applied':'pending';
        return $states;
    }
    /** @param list<string> $states */
    public static function combineSettlementStates(array $states):string
    {
        if(in_array('pending',$states,true))return 'pending';
        return in_array('applied',$states,true)?'applied':'none';
    }
    public static function reduceReadHistory(int $supplier,string $asOf,string $knownAt,array $scope,array $history,array $events,string $serviceSettlements='not_implemented'):array
    {
        return PropertyInstant::withNormalizationContext(fn()=>self::reducePreparedReadHistory($supplier,$asOf,$knownAt,$scope,$history,$events,$serviceSettlements));
    }
    private static function reducePreparedReadHistory(int $supplier,string $asOf,string $knownAt,array $scope,array $history,array $events,string $serviceSettlements):array
    {
        $asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);
            // Neukotvená oprava nedokládá vazbu na osobu; client_id proto blokátory nezužuje.
            $unresolved=UnresolvedNegativeChargeScope::at($history,$asOf,$knownAt,$scope['unit_id']??null);
            $rows=[];$credits=[];$total=['gross_receivable_cents'=>'0','gross_credit_cents'=>'0','reserved_credit_cents'=>'0','available_credit_cents'=>'0','guarantee_exposure_cents'=>'0'];$complete=$unresolved===[];$precision='instant';
            foreach($history['debts']+$history['unregistered_claims'] as $debt){
                if(isset($scope['unit_id'])&&$scope['unit_id']!==$debt['unit_id'])continue;
                if(!array_any($debt['events'],fn($e)=>ReceivableTimeline::inclusion($e,$asOf,$knownAt)!==false))continue;
                $liability=PropertyLiabilityProjection::project($debt,$events,$asOf,$knownAt);$totals=ReceivableTimeline::sum($debt['events'],$asOf,$knownAt);$groups=$liability['groups'];
                if(isset($scope['client_id'])){$groups=array_values(array_filter($groups,fn($g)=>in_array($scope['client_id'],$g['members'],true)));if($groups===[])continue;}
                $complete=$complete&&$totals['complete'];if($totals['precision']==='day')$precision='day';
                $sum=$totals['gross_receivable_cents'];$debtor=array_any($groups,fn($g)=>$g['role']==='debtor');$guarantee='0';foreach($groups as $g)if($g['role']==='guarantor'&&$g['remaining_cap_cents']!==null)$guarantee=bcadd($guarantee,$g['remaining_cap_cents'],0);
                $personal=null;
                if(isset($scope['client_id'])){$personal='0';foreach($groups as $g)if($g['role']==='debtor'){if($g['remaining_cap_cents']===null){$personal=null;$complete=false;break;}$personal=bcadd($personal,$g['remaining_cap_cents'],0);}if(!$liability['complete'])$complete=false;}
                $contribution=isset($scope['client_id'])?$personal:$sum;
                if($contribution!==null&&$debtor)$total['gross_receivable_cents']=bcadd($total['gross_receivable_cents'],$contribution,0);
                if(isset($scope['client_id']))$total['guarantee_exposure_cents']=bcadd($total['guarantee_exposure_cents'],$guarantee,0);
                $rows[]=['claim_key'=>$debt['id']>0?'debt:'.$debt['id']:'charge-line:'.$debt['charge_line_id'],'debt_id'=>$debt['id']>0?$debt['id']:null,'unit_id'=>$debt['unit_id'],'due_date'=>$debt['snapshot']['due_date'],'title_ref'=>$debt['snapshot']['title_ref'],'amount_cents'=>$sum,'person_liability_cents'=>$personal,'groups'=>$groups,'liability'=>$liability,'precision'=>$totals['precision'],'complete'=>$totals['complete'],'source_hash'=>$debt['snapshot_hash'],'events'=>$debt['events']];
            }
            foreach($history['credits'] as $credit){
                if(isset($scope['unit_id'])&&$scope['unit_id']!==$credit['unit_id'])continue;if(isset($scope['client_id'])&&!in_array($scope['client_id'],array_column($credit['snapshot']['parties'],'id'),true))continue;
                if(!array_any($credit['events'],fn($e)=>ReceivableTimeline::inclusion($e,$asOf,$knownAt)!==false))continue;
                $sum=ReceivableTimeline::sum($credit['events'],$asOf,$knownAt);$complete=$complete&&$sum['complete'];if($sum['precision']==='day')$precision='day';
                foreach(['gross_credit_cents','reserved_credit_cents','available_credit_cents'] as $key)if($sum[$key]!==null)$total[$key]=bcadd($total[$key],$sum[$key],0);
                $credits[]=['id'=>$credit['id'],'unit_id'=>$credit['unit_id'],'parties'=>$credit['snapshot']['parties'],'due_date'=>$credit['snapshot']['due_date'],'source_hash'=>$credit['snapshot_hash'],'events'=>$credit['events']]+$sum;
            }
            if(!$complete)foreach($total as &$value)$value=null;unset($value);
            $snapshot=['supplier_id'=>$supplier,'as_of'=>$asOf,'known_at'=>$knownAt,'scope'=>$scope,'claims'=>$rows,'credits'=>$credits,'totals'=>$total,'precision'=>$precision,
                'coverage'=>['financial_complete'=>$complete,'service_settlements'=>$serviceSettlements,'opening_import'=>'verified_recorded_batches','unresolved_negative_charge_line_ids'=>$unresolved,'scope'=>'recorded_s3_s4_s6','complete'=>false],
                'current_party_heads'=>array_map(fn($e)=>['id'=>$e['id'],'hash'=>$e['snapshot_hash']],$events)];
            unset($history,$debt,$credit);
        return $snapshot;
    }

}
