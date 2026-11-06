<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant,PropertyException};
use MyInvoice\Service\Property\Receivables\PropertyReceivableProjection;
final class ReceivableWorkflowReadRepository
{
    public function __construct(private readonly Connection $db) {}
    public function context(int $supplier,int $unitId,string $asOf,string $knownAt):array
    {
        PropertyInput::id($unitId,'unit_id');$asOf=PropertyInstant::normalize($asOf);$knownAt=PropertyInstant::normalize($knownAt);
        $pdo=$this->db->pdo();
        // Nový více-dotazový read kontrakt necertifikuje neověřený caller snapshot; legacy full-card zůstává kompatibilní.
        if($pdo->inTransaction())throw new PropertyException('property_receivable_snapshot_required','Právní přehled vyžaduje vlastní konzistentní transakci.',409);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try{
            $registry=new PropertyReadRepository($this->db);$unit=$registry->find($supplier,$unitId,$asOf,$knownAt);
            if($unit['kind']!=='unit')PropertyInput::invalid('unit_id');
            $unit['history']=array_values(array_filter($unit['history'],fn($r)=>PropertyInstant::normalize($r['recorded_at'])<=$knownAt));
            $card=(new PropertyReceivableProjection($this->db))->at($supplier,$asOf,$knownAt,['unit_id'=>$unitId]);
            $events=array_values(array_filter((new PartyEventRepository($this->db))->history($supplier),fn($e)=>$e['recorded_at']<=$knownAt&&$e['snapshot']['request']['unit_id']===$unitId));
            $ids=[];foreach($unit['history'] as $r)foreach($r['state']['ownerships']??[] as $g)array_push($ids,...$g['members']);
            foreach($card['claims'] as $c)foreach($c['groups'] as $g)array_push($ids,...$g['members']);
            foreach($events as $e)foreach($e['snapshot']['debts'] as $d)foreach([...$d['groups'],...$d['previous_liability']['groups']] as $g)array_push($ids,...$g['members']);
            $parties=$registry->parties($supplier,$ids);
            $q=$pdo->prepare('SELECT id FROM property_debt_certificates WHERE supplier_id=? AND unit_id=? AND recorded_at<=? ORDER BY id');$q->execute([$supplier,$unitId,PropertyInstant::sql($knownAt)]);
            $certificates=[];$certRepo=new DebtCertificateRepository($this->db);
            foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $id){$c=$certRepo->find($supplier,(int)$id);$certificates[]=['id'=>$c['id'],'recorded_at'=>$c['recorded_at'],'as_of'=>$c['snapshot']['request']['as_of'],'known_at'=>$c['snapshot']['request']['known_at'],'snapshot_hash'=>$c['snapshot_hash']];}
            $pdo->commit();
            return ['as_of'=>$asOf,'known_at'=>$knownAt,'unit'=>$unit,'projection'=>$card,'events'=>$events,'parties'=>$parties,'party_labels_scope'=>'current_directory','certificates'=>$certificates,'consistency'=>'request_snapshot'];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
