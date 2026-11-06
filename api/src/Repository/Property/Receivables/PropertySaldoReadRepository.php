<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Receivables\{PropertyReceivableHistoryByUnit,PropertyReceivableProjection,PropertySaldoCursor,PropertySaldoSummary};
use PDO;
final class PropertySaldoReadRepository
{
    public function __construct(private readonly Connection $db){}
    public function page(int $supplier,string $asOf,string $knownAt,int $limit=25,?string $cursor=null):array
    {
        $asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);
        if($limit<1||$limit>100)PropertyInput::invalid('limit');$after=PropertySaldoCursor::decode($cursor,$supplier,$asOf,$knownAt);
        $pdo=$this->db->pdo();
        // Nový scoped kontrakt vyžaduje vlastní snapshot. Legacy full-card ponechává převzetí
        // transakce kvůli kompatibilitě; SESSION izolace ani INNODB_TRX nedokazují její režim.
        if($pdo->inTransaction())throw new \MyInvoice\Service\Property\PropertyException('property_saldo_snapshot_required','Přehled salda vyžaduje vlastní konzistentní transakci.',409);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION READ ONLY');
        try{$result=PropertyInstant::withNormalizationContext(fn()=>$this->readPage($pdo,$supplier,$asOf,$knownAt,$limit,$after));$pdo->commit();return $result;}
        catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function readPage(PDO $pdo,int $supplier,string $asOf,string $knownAt,int $limit,int $after):array
    {
            $q=$pdo->prepare("SELECT id,code FROM property_objects WHERE supplier_id=? AND kind='unit' AND id>? ORDER BY id LIMIT ".($limit+1));$q->execute([$supplier,$after]);$units=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($units)>$limit;if($more)array_pop($units);$ids=array_map('intval',array_column($units,'id'));
            $items=[];$provenance=[];$settlements=PropertyReceivableProjection::serviceSettlementStates($this->db,$supplier,$asOf,$knownAt,$ids);
            if($ids!==[]){
                $scoped=$this->unitHistory($supplier,$ids);$provenance=$scoped['provenance'];
                $histories=new PropertyReceivableHistoryByUnit($scoped['history']);
                foreach($units as $unit){$id=(int)$unit['id'];$card=PropertyReceivableProjection::reduceReadHistory($supplier,$asOf,$knownAt,['unit_id'=>$id],$histories->forUnit($id),$scoped['events'],$settlements[$id]??'none');$items[]=PropertySaldoSummary::fromCard($card,$scoped['accessory_allocations'])+['unit_code'=>$unit['code']];}
                unset($scoped,$histories);
            }
            $digest=PropertyInput::hash(['schema'=>1,'supplier_id'=>$supplier,'as_of'=>$asOf,'known_at'=>$knownAt,'unit_ids'=>$ids,'provenance'=>$provenance]);
            $result=['kind'=>'recorded_property_saldo_page','as_of'=>$asOf,'known_at'=>$knownAt,'items'=>$items,'next_cursor'=>$more?PropertySaldoCursor::encode($supplier,$asOf,$knownAt,$ids[array_key_last($ids)]):null,
                'read_scope_digest'=>$digest,'proof_scope'=>['kind'=>'selected_unit_financial_closure','unit_ids'=>$ids,'schema'=>1],
                'consistency'=>'request_snapshot','coverage'=>['scope'=>'recorded_s3_s4_s6','complete'=>false,'service_settlements'=>PropertyReceivableProjection::combineSettlementStates(array_map(fn($item)=>$item['coverage']['service_settlements'],$items)),'opening_import'=>'verified_recorded_batches']];
            return $result;
    }
    /**
     * Uzavřená finanční historie vybraných jednotek: dluhy, platby a právní události jen těchto
     * jednotek po indexech, bez čtení celé firmy. Volající drží konzistentní transakci.
     * Jako čtení celé historie ({@see FinancialHistoryRepository::read()}) běží v jednom
     * rozsahu čtení, takže vyúčtování sdílené uzávěrem, dluhy i kredity se ověří jednou.
     */
    public function unitHistory(int $supplier,array $unitIds):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Unit history requires a consistent transaction.');
        foreach($unitIds as $id)PropertyInput::id($id,'unit_id');
        return \MyInvoice\Service\Property\Services\Settlement\SettlementHistory::inReadScope(fn():array=>$this->readUnitHistory($supplier,$unitIds));
    }
    private function readUnitHistory(int $supplier,array $unitIds):array
    {
        $reader=new PropertySaldoReadClosure($this->db);$partyReader=new PartyEventRepository($this->db);$selected=[];
        do{
            $closure=$reader->units($supplier,$unitIds,$selected);
            $party=$partyReader->readHistoryForDebts($supplier,$closure->debtIds,$unitIds);
            $selected=$party['debt_ids'];
            $debtIds=$closure->debtIds;sort($debtIds,SORT_NUMERIC);
        }while($selected!==$debtIds);
        $charges=(new IssuanceReadRepository($this->db))->findMany($supplier,$closure->chargeIds);
        PropertySaldoReadProof::materializations($closure->rows,$charges);
        PropertySaldoReadProof::creditEvents($closure->rows);
        $history=(new FinancialHistoryRepository($this->db))->reduceReadHistory($charges,fn(string $table)=>$closure->rows[$table]??[]);
        PropertySaldoReadProof::parties($history,$party['events']);
        $accessoryAllocations=[];
        foreach($closure->rows['unit_payment_allocations'] as $allocation)if($allocation['accessory_id']!==null)$accessoryAllocations[(int)$allocation['id']]=true;
        $provenance=[];
        foreach($closure->rows as $table=>$rows)foreach($rows as $row)$provenance[$table][]=PropertyInput::hash($row);
        foreach($charges as $charge)$provenance['unit_charges'][]=PropertyInput::hash($charge);
        foreach($party['events'] as $event)$provenance['party_events'][]=PropertyInput::hash($event);
        $provenance['financial_history'][]=PropertyInput::hash($history);
        return ['history'=>$history,'events'=>$party['events'],'accessory_allocations'=>$accessoryAllocations,'provenance'=>$provenance];
    }
}
