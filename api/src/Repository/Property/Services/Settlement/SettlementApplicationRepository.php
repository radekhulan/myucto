<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\{DimensionPeriodGuard,SystemDimensionWritePolicy};
use MyInvoice\Service\Accounting\{JournalLineAmount,PostingService};
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentPostingDate,PaymentJournalProof};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Settlement\{SettlementSql,SettlementDocument,SettlementEvidence,SettlementOriginAdapter,SettlementPlanner,SettlementApplicationIntent,SettlementReversalIntent,SettlementHistory};
final class SettlementApplicationRepository
{
    use SettlementSql;
    public function __construct(private readonly Connection $db,private readonly SettlementDocumentRepository $documents,private readonly SettlementOriginAdapter $origins,private readonly JournalEntryRepository $journal,private readonly \MyInvoice\Repository\Property\Payments\PaymentRepository $payments){}
    public function preview(int $supplier,int $document,array $request,int $expectedVersion):array
    {return $this->transaction($supplier,fn()=>$this->previewLocked($supplier,$document,$request,$expectedVersion));}
    private function previewLocked(int $supplier,int $document,array $request,int $expected,bool $dimensions=true):array
    {
        $doc=$this->documents->findLatest($supplier,$document);$head=$this->head($supplier,$document);
        if($head['row_version']!==$expected)SettlementEvidence::reject('version_conflict');
        if($head['application_id']!==null)SettlementEvidence::reject('already_applied');
        self::assertRequestShape($request);
        $facts=$doc['document'];$live=$this->origins->current($supplier,$facts['unit_id'],$facts['service_id'],$request['accounting_date'],$doc['origin_receipt']['request']['selectors']);
        if(PropertyInput::hash($live['origins'])!==PropertyInput::hash($facts['origins']))SettlementEvidence::reject('document_origin_changed');
        $matching=array_filter($live['costs'],fn($cost)=>PropertyInput::hash($cost)===PropertyInput::hash($facts['cost']));if(count($matching)!==1)SettlementEvidence::reject('document_cost_changed');
        $normalized=$request;unset($normalized['claim_title']);$parties=[];$sourceByKey=[];foreach($facts['origins'] as $source)$sourceByKey[$source['kind'].':'.$source['id']]=$source;
        $allocations=array_column($live['allocations'],null,'id');
        foreach(['reliefs','opening_credits'] as $collection)foreach($request[$collection] as $i=>$part){
            $key=$collection==='reliefs'?($allocations[$part['allocation_id']]['origin_key']??null):($part['origin_key']??null);
            if(!isset($sourceByKey[$key]))SettlementEvidence::reject('origin_scope');
            $normalized[$collection][$i]['entitlement']=$this->entitlement($supplier,$part['entitlement'],$sourceByKey[$key]['advance_line'],false,$parties,$request['accounting_date']);
        }
        if($request['new_claim']!==null)$normalized['new_claim']=$this->entitlement($supplier,$request['new_claim'],$facts['cost']['line'],true,$parties,$request['accounting_date']);
        $references=[];$currentOrigins=[];$partyHead=null;
        foreach($live['origins'] as $source){$key=$source['kind'].':'.$source['id'];$evidence=$live['evidence'][$key];
            $currentOrigins[]=['source'=>$source,'version'=>1,'financial_head'=>PropertyInput::hash($evidence),'party_head'=>$partyHead,'prior_reduction_cents'=>'0','advance_available_cents'=>$source['prescribed_cents'],'active'=>true];
            if($source['debt_id']!==null)$references[]='claim:'.$source['debt_id'];
            (new SettlementHistory($this->db))->assertOriginAvailable($supplier,$source);
        }
        foreach($live['allocations'] as $allocation)$references[]='allocation:'.$allocation['id'];
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertReferencesMutable($supplier,self::mutatedPartyReferences($request,$sourceByKey));
        $references=array_values(array_unique($references));sort($references,SORT_STRING);$partyReceipt=['supplier_id'=>$supplier,'references'=>$references];$partyHead=PropertyInput::hash($partyReceipt);foreach($currentOrigins as &$origin)$origin['party_head']=$partyHead;unset($origin);
        foreach(SettlementEvidence::costSources($facts['cost']) as $part)(new SettlementHistory($this->db))->assertCostAvailable($supplier,$part['source_id']);
        $current=['supplier_id'=>$supplier,'segment_key'=>$facts['segment_key'],'as_of'=>max($facts['known_at'],$facts['cutoff_at'],$request['accounting_date'].'T23:59:59+01:00'),'revision'=>$expected+1,'application_id'=>null,'origins'=>$currentOrigins,'allocations'=>array_map(fn($a)=>['id'=>$a['id'],'origin_key'=>$a['origin_key'],'source_hash'=>$a['source_hash'],'amount_cents'=>$a['amount_cents'],'operation_version'=>$a['operation_version'],'financial_head'=>$a['current_source_hash'],'relieved_cents'=>'0','active'=>$a['active'],'accounting_date'=>$a['accounting_date']],$live['allocations']),'cost'=>['source'=>$facts['cost'],'version'=>1,'available_cents'=>$facts['actual_cents']],'parties'=>array_map(fn($p)=>['id'=>$p['id'],'supplier_id'=>$supplier],array_values($parties))];
        $input=$facts;unset($input['amounts']);$plan=SettlementPlanner::calculate(SettlementDocument::fromEvidence($input),$current,$normalized);
        $header=['supplier_id'=>$supplier,'period_id'=>$live['period_id'],'entry_date'=>$request['accounting_date'],'document_date'=>$request['accounting_date'],'source_type'=>'property_service_settlement','source_id'=>null];
        $this->validateJournal($header,$plan['lines']);
        $title=$plan['new_claim']===null?null:$this->claimTitle($supplier,$facts['unit_id'],$request['claim_title']??null,$request['accounting_date'],array_values($parties),$facts['recipient_ids']);
        if($plan['new_claim']===null&&isset($request['claim_title']))SettlementEvidence::reject('unexpected_claim_title');
        $snapshot=['document_id'=>$document,'document_hash'=>$doc['document_hash'],'expected_version'=>$expected,'request'=>$request,'current_origin_hash'=>$live['current_hash'],'origin_receipt'=>$live,'party_receipt'=>$partyReceipt,'plan'=>$plan,'parties'=>array_values($parties),'header'=>$header,'claim_title'=>$title];
        if($dimensions)(new SystemDimensionWritePolicy($this->db))->assertPropertyServiceSettlementJournal($header,$plan['lines'],\MyInvoice\Service\Property\Services\Settlement\SettlementHistoricalCarrierProof::fromCurrent($this->db,$this,$supplier,$document,$request,$expected));
        return $snapshot+['preview_hash'=>PropertyInput::hash($snapshot)];
    }
    /**
     * Zdroje, které vyúčtování skutečně mění: úhrady uvolněné do kreditu a pohledávky, jejichž
     * jistinu snižuje nevyužitá záloha. Jen ty nesmí být připnuté k právní historii osob.
     * Samotné použití zálohy převedenou pohledávku nemění, takže vyúčtování segmentu
     * s dluhem přešlým na nabyvatele (§ 1186 OZ) zůstává možné a dluh trvá beze změny.
     */
    private static function mutatedPartyReferences(array $request,array $sourceByKey):array
    {
        $out=[];
        foreach($request['reliefs'] as $part)$out[]='allocation:'.PropertyInput::id($part['allocation_id']??null,'allocation_id');
        $credited=[];
        foreach($request['opening_credits'] as $part){
            $key=(string)($part['origin_key']??'');
            $credited[$key]=bcadd($credited[$key]??'0',SettlementEvidence::cents($part['amount_cents']??null),0);
        }
        foreach($request['advances'] as $part){
            $key=(string)($part['origin_key']??'');
            $source=$sourceByKey[$key]??null;
            if($source===null||$source['debt_id']===null)continue;
            $reduction=bcsub(bcsub($source['prescribed_cents'],SettlementEvidence::cents($part['applied_cents']??null),0),$credited[$key]??'0',0);
            if(bccomp($reduction,'0',0)>0)$out[]='claim:'.$source['debt_id'];
        }
        return array_values(array_unique($out));
    }
    /**
     * Tvar požadavku se ověří dřív, než se z něj čte: chybějící pole nebo jiný typ je
     * validační chyba (422), ne TypeError s odpovědí 500.
     */
    private static function assertRequestShape(array $request):void
    {
        $required=['accounting_date','evidence','advances','reliefs','opening_credits','new_claim'];
        PropertyInput::fields($request,[...$required,'claim_title']);
        foreach($required as $key)if(!array_key_exists($key,$request))PropertyInput::invalid($key);
        SettlementEvidence::date($request['accounting_date']);
        PropertyInput::text($request['evidence'],'disposition.evidence',4000);
        foreach(['advances','reliefs','opening_credits'] as $collection){
            foreach(PropertyInput::list($request[$collection],$collection,10000) as $part){
                if(!is_array($part))PropertyInput::invalid($collection);
            }
        }
        foreach($request['reliefs'] as $part){
            PropertyInput::id($part['allocation_id']??null,'allocation_id');
            if(!is_array($part['entitlement']??null))PropertyInput::invalid('entitlement');
        }
        foreach($request['opening_credits'] as $part){
            if(!is_string($part['origin_key']??null))PropertyInput::invalid('origin_key');
            if(!is_array($part['entitlement']??null))PropertyInput::invalid('entitlement');
        }
        if($request['new_claim']!==null&&!is_array($request['new_claim']))PropertyInput::invalid('new_claim');
        if(array_key_exists('claim_title',$request)&&$request['claim_title']!==null&&!is_array($request['claim_title']))PropertyInput::invalid('claim_title');
    }
    public function apply(int $supplier,int $document,array $request,int $expected,string $previewHash,string $token,int $actor):array
    {
        return $this->transaction($supplier,function()use($supplier,$document,$request,$expected,$previewHash,$token,$actor){
            $envelope=['document_id'=>$document,'request'=>$request,'expected_version'=>$expected,'preview_hash'=>$previewHash,'token'=>$token,'actor'=>$actor];
            if($old=$this->retry($supplier,$token,$envelope))return $old;
            $preview=$this->previewLocked($supplier,$document,$request,$expected);if(!hash_equals($preview['preview_hash'],$previewHash))SettlementEvidence::reject('preview_stale');
            $id=$this->request($supplier,$document,null,$expected,$envelope,$preview,$token,$actor);
            $this->journal->insertPropertyServiceSettlement(SettlementApplicationIntent::fromPending($this->db,$this,$supplier,$id));
            return $this->forRequest($supplier,$id);
        });
    }
    public function reverse(int $supplier,int $application,string $date,int $expected,string $reason,string $token,int $actor):array
    {
        return $this->transaction($supplier,function()use($supplier,$application,$date,$expected,$reason,$token,$actor){
            $envelope=['application_id'=>$application,'accounting_date'=>$date,'expected_version'=>$expected,'reason'=>$reason,'token'=>$token,'actor'=>$actor];
            if($old=$this->retry($supplier,$token,$envelope))return $old;
            $preview=$this->reversalPreview($supplier,$application,$date,$expected,$reason);
            $id=$this->request($supplier,$preview['document_id'],$application,$expected,$envelope,$preview,$token,$actor);
            $this->journal->insertPropertyServiceSettlementReversal(SettlementReversalIntent::fromPending($this->db,$this,$supplier,$id));return $this->forRequest($supplier,$id);
        });
    }
    public function currentCarrierSnapshot(Connection $db,int $supplier,int $document,array $request,int $expected,?int $pending=null):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Settlement carrier requires its current owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);
        if($pending!==null)return $this->pendingCore($db,$supplier,$pending,false);
        $preview=$this->previewLocked($supplier,$document,$request,$expected,false);
        return ['preview'=>$preview,'journal'=>['header'=>$preview['header'],'lines'=>$preview['plan']['lines']]];
    }
    public function pending(Connection $db,int $supplier,int $requestId):array
    {return $this->pendingCore($db,$supplier,$requestId,true);}
    private function pendingCore(Connection $db,int $supplier,int $requestId,bool $dimensions):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Settlement requires its owner transaction.');
        (new PaymentLocks($this->db))->acquire($supplier);
        $row=$this->one('SELECT * FROM property_service_settlement_requests WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$requestId]);if(!$row||$this->one('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND request_id=? FOR UPDATE',[$supplier,$requestId]))SettlementEvidence::reject('intent_completed');
        $request=self::decode($row['request_json']);$stored=self::decode($row['preview_json']);
        if(PropertyInput::hash($request)!==$row['request_hash']||PropertyInput::hash($stored)!==$row['preview_hash']||$request['actor']!==(int)$row['created_by']||$request['token']!==$row['idempotency_key'])SettlementEvidence::reject('intent_corrupt');
        $preview=$row['reversal_of']===null?$this->previewLocked($supplier,(int)$row['document_id'],$request['request'],(int)$row['expected_version'],$dimensions):$this->reversalPreview($supplier,(int)$row['reversal_of'],$request['accounting_date'],(int)$row['expected_version'],$request['reason']);
        if(PropertyInput::hash($preview)!==$row['preview_hash'])SettlementEvidence::reject('intent_stale');
        $header=$preview['header']+['posted_at'=>$row['recorded_at'],'posted_by'=>(int)$row['created_by'],'description'=>$request['reason']??$request['request']['evidence']];$header['source_id']=$row['reversal_of']===null?$requestId:null;
        return ['row'=>$row,'preview'=>$preview,'journal'=>['header'=>$header,'lines'=>$preview['plan']['lines'],'source_entry_id'=>$preview['source_entry_id']??null]];
    }
    public function complete(Connection $db,int $supplier,int $requestId,int $entry):void
    {
        $raw=$this->one('SELECT * FROM property_service_settlement_requests WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$requestId]);
        $pending=$raw&&$raw['reversal_of']!==null?$this->completeReversalProof($db,$supplier,$raw,$entry):$this->pending($db,$supplier,$requestId);$row=$pending['row'];$preview=$pending['preview'];
        $ledger=(new PaymentJournalProof($this->db))->current($supplier,$entry);
        if($ledger['entry']['source_type']!=='property_service_settlement'|| $ledger['entry']['source_id']!==($row['reversal_of']===null?$requestId:null)||$ledger['entry']['entry_date']!==$pending['journal']['header']['entry_date']||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($pending['journal']['lines'])))SettlementEvidence::reject('ledger_mismatch');
        $effects=$row['reversal_of']===null?SettlementHistory::effects($preview,$ledger):[];$receipt=['preview'=>$preview,'ledger'=>$ledger,'effects'=>$effects];
        $this->execute('INSERT INTO property_service_settlement_applications (supplier_id,document_id,reversal_of,accounting_date,period_id,journal_entry_id,receipt_json,receipt_hash,request_hash,idempotency_key,created_by,recorded_at,request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?)',[$supplier,$row['document_id'],$row['reversal_of'],$ledger['entry']['entry_date'],$ledger['entry']['period_id'],$entry,self::json($receipt),PropertyInput::hash($receipt),$row['request_hash'],$row['idempotency_key'],$row['created_by'],$requestId]);$app=(int)$this->db->pdo()->lastInsertId();
        foreach($effects as $i=>$effect){
            $this->execute('INSERT INTO property_service_settlement_effects (supplier_id,application_id,ordinal,kind,debt_id,charge_line_id,opening_component_id,allocation_id,amount_cents,effect_json,effect_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$supplier,$app,$i,$effect['kind'],$effect['debt_id'],$effect['charge_line_id'],$effect['opening_component_id'],$effect['allocation_id'],$effect['amount_cents'],self::json($effect),PropertyInput::hash($effect)]);$effectId=(int)$this->db->pdo()->lastInsertId();
            if(isset($effect['credit'])){$credit=$effect['credit'];$this->execute('INSERT INTO unit_credit_lots (supplier_id,settlement_effect_id,unit_id,amount_cents,due_date,snapshot_json,snapshot_hash) VALUES (?,?,?,?,?,?,?)',[$supplier,$effectId,$credit['unit_id'],$credit['amount_cents'],$credit['due_date'],self::json($credit),PropertyInput::hash($credit)]);$creditId=(int)$this->db->pdo()->lastInsertId();foreach($credit['parties'] as $party)$this->execute('INSERT INTO unit_credit_parties (supplier_id,credit_id,client_id,party_json) VALUES (?,?,?,?)',[$supplier,$creditId,$party['id'],self::json($party)]);}
            if(isset($effect['claim'])){$fact=SettlementHistory::debtSnapshot($effectId,$effect['claim'],$entry);$debtRequest=['settlement_effect_id'=>$effectId];$token='service-settlement-effect-'.$effectId;$this->execute('INSERT INTO unit_payment_debts (supplier_id,settlement_effect_id,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$effectId,self::json($debtRequest),self::json($fact),PropertyInput::hash(['request'=>$debtRequest,'token'=>$token,'actor'=>(int)$row['created_by']]),PropertyInput::hash($fact),$token,$row['created_by']]);}
        }
        if($this->execute('UPDATE property_service_settlement_heads SET row_version=row_version+1,application_id=? WHERE supplier_id=? AND document_id=? AND row_version=?',[$app,$supplier,$row['document_id'],$row['expected_version']])->rowCount()!==1)SettlementEvidence::reject('version_conflict');
    }
    private function completeReversalProof(Connection $db,int $supplier,array $row,int $entry):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Reversal requires its owner transaction.');
        $preview=self::decode($row['preview_json']);$request=self::decode($row['request_json']);
        if(PropertyInput::hash($preview)!==$row['preview_hash']||PropertyInput::hash($request)!==$row['request_hash'])SettlementEvidence::reject('intent_corrupt');
        $original=$this->one('SELECT * FROM property_service_settlement_applications WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$row['reversal_of']]);
        if(!$original||$original['receipt_hash']!==$preview['application_hash']||$this->one('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND reversal_of=? FOR UPDATE',[$supplier,$row['reversal_of']]))SettlementEvidence::reject('intent_stale');
        $receipt=self::decode($original['receipt_json']);if(PropertyInput::hash($receipt)!==$original['receipt_hash'])SettlementEvidence::reject('history_corrupt');
        $expected=$receipt['ledger'];$expected['entry']['reversed_by']=$entry;$actual=(new PaymentJournalProof($this->db))->current($supplier,(int)$original['journal_entry_id']);if(PropertyInput::hash($actual)!==PropertyInput::hash($expected))SettlementEvidence::reject('ledger_mismatch');
        $head=$this->head($supplier,(int)$row['document_id']);if($head['row_version']!==(int)$row['expected_version']||$head['application_id']!==(int)$row['reversal_of'])SettlementEvidence::reject('intent_stale');
        return ['row'=>$row,'preview'=>$preview,'journal'=>['header'=>$preview['header'],'lines'=>$preview['plan']['lines']]];
    }
    private function reversalPreview(int $supplier,int $id,string $date,int $expected,string $reason):array
    {
        SettlementEvidence::date($date);PropertyInput::text($reason,'reason',4000);(new PaymentPostingDate($this->db))->assertOpen($supplier,$date);
        $app=(new SettlementHistory($this->db))->application($supplier,$id,true);if($app['reversal_of']!==null||!$app['active'])SettlementEvidence::reject('already_reversed');
        $head=$this->head($supplier,$app['document_id']);if($head['row_version']!==$expected||$head['application_id']!==$id)SettlementEvidence::reject('version_conflict');
        if($date<$app['accounting_date'])SettlementEvidence::reject('backdated_reversal');
        $refs=[];foreach($app['effects'] as $effect)$refs[]='service-settlement:'.$id.':effect:'.$effect['id'];
        (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertReferencesMutable($supplier,$refs);
        foreach($app['debt_ids'] as $debt){
            (new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertDebtMutable($supplier,$debt);
            if($this->one("SELECT a.id FROM unit_payment_allocations a JOIN unit_payment_operations p ON p.supplier_id=a.supplier_id AND p.id=a.operation_id WHERE a.supplier_id=? AND a.debt_id=? AND p.status='posted' LIMIT 1 FOR UPDATE",[$supplier,$debt])||$this->one('SELECT id FROM unit_claim_accessory_receipts WHERE supplier_id=? AND debt_id=? LIMIT 1 FOR UPDATE',[$supplier,$debt]))SettlementEvidence::reject('claim_in_use');
        }
        foreach($app['credit_ids'] as $credit){$native=$this->payments->credit($supplier,$credit);if($native['balance_cents']!==$native['snapshot']['amount_cents']||$native['reserved_cents']!=='0')SettlementEvidence::reject('credit_in_use');$lot=$this->one('SELECT * FROM unit_credit_lots WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$credit]);
            if($this->one("SELECT r.id FROM unit_credit_reservations r WHERE r.supplier_id=? AND r.credit_id=? AND r.status IN ('reserved','submitted','paid') LIMIT 1 FOR UPDATE",[$supplier,$credit])||$this->one("SELECT e.id FROM unit_credit_events e JOIN unit_payment_operations p ON p.supplier_id=e.supplier_id AND p.id=e.operation_id WHERE e.supplier_id=? AND e.credit_id=? AND p.status='posted' LIMIT 1 FOR UPDATE",[$supplier,$credit]))SettlementEvidence::reject('credit_in_use');
        }
        $period=$this->one('SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? FOR UPDATE',[$supplier,$date,$date]);
        $lines=array_map(fn($line)=>JournalLineAmount::reversal($line),$app['receipt']['preview']['plan']['lines']);
        return ['document_id'=>$app['document_id'],'application_id'=>$id,'expected_version'=>$expected,'application_hash'=>$app['receipt_hash'],'source_entry_id'=>$app['journal_entry_id'],'plan'=>['lines'=>$lines],'header'=>['supplier_id'=>$supplier,'period_id'=>(int)$period['id'],'entry_date'=>$date,'document_date'=>$date,'source_type'=>'property_service_settlement','source_id'=>null]];
    }
    private function entitlement(int $supplier,array $ent,array $source,bool $claim,array &$parties,string $date):array
    {
        SettlementEvidence::fields($ent,['party_ids','evidence','due_date','account_code']);
        foreach($ent['party_ids'] as $id)PropertyInput::id($id,'party_id');$found=(new PropertyReadRepository($this->db))->parties($supplier,$ent['party_ids'],true);if(count($found)!==count($ent['party_ids']))SettlementEvidence::reject('foreign_party');foreach($found as $party)$parties[$party['id']]=$party;
        $account=$this->one('SELECT * FROM '.\MyInvoice\Repository\AccountDefinitionRepository::relation($date,$supplier,true).' a WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$ent['account_code']]);
        if(!$account||!(bool)$account['is_active']||$account['account_type']!==($claim?'asset':'liability')||($claim?substr($account['account_code'],0,3)!=='315':$account['account_code']!=='379.910'))SettlementEvidence::reject('destination_account');
        $line=$source;$line['account_id']=(int)$account['id'];$line['account_code']=$account['account_code'];
        return ['party_ids'=>$ent['party_ids'],'evidence'=>$ent['evidence'],'due_date'=>$ent['due_date'],'line'=>$line];
    }
    private function claimTitle(int $supplier,int $unit,mixed $input,string $date,array $parties,array $recipients):array
    {
        if(!is_array($input))SettlementEvidence::reject('claim_title_required');SettlementEvidence::fields($input,['effective_at','title_ref','liability_groups','legal','security']);
        $at=\MyInvoice\Service\Property\PropertyInstant::normalize($input['effective_at']);PropertyInput::text($input['title_ref'],'title_ref',4000);
        $registry=(new PropertyReadRepository($this->db))->find($supplier,$unit,$at,null,true);
        $groups=\MyInvoice\Service\Property\Charges\Issuance\LiabilityPolicy::validate($input['liability_groups'],$registry['snapshot']['state']??[],$at,array_column($parties,'id'));
        $members=[];foreach($groups as $group)if($group['role']==='debtor')array_push($members,...$group['members']);sort($members);sort($recipients);if($members!==$recipients)SettlementEvidence::reject('claim_recipient_changed');
        PropertyInput::text($input['legal']['evidence']??null,'legal.evidence',4000);\MyInvoice\Service\Property\Payments\StatutoryAllocationPolicy::ruleset($date,$input['legal']);
        SettlementEvidence::fields($input['security'],['kind','evidence']);if(!in_array($input['security']['kind'],['secured','unsecured'],true))SettlementEvidence::reject('claim_security');PropertyInput::text($input['security']['evidence'],'security.evidence',4000);
        return ['effective_at'=>$at,'title_ref'=>$input['title_ref'],'liability_groups'=>$groups,'legal'=>$input['legal'],'security'=>$input['security'],'registry'=>$registry];
    }
    private function validateJournal(array $header,array $lines):void
    {
        PostingService::assertBalanced($lines);$accounts=(new \MyInvoice\Repository\AccountDefinitionRepository($this->db))->currentForDate((int)$header['supplier_id'],$header['entry_date']);foreach($lines as $line){$account=$accounts[(int)$line['account_id']]??null;if(!$account||!$account['is_active']||$account['account_code']!==$line['account_code'])SettlementEvidence::reject('account_unavailable');}
    }
    private function request(int $supplier,int $document,?int $reversal,int $expected,array $request,array $preview,string $token,int $actor):int
    {
        PropertyInput::id($actor,'actor');PropertyInput::code($token,'idempotency_key');
        $this->execute('INSERT IGNORE INTO property_service_settlement_heads (supplier_id,document_id) VALUES (?,?)',[$supplier,$document]);
        $this->execute('INSERT INTO property_service_settlement_requests (supplier_id,document_id,reversal_of,expected_version,request_json,request_hash,preview_json,preview_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$document,$reversal,$expected,self::json($request),PropertyInput::hash($request),self::json($preview),PropertyInput::hash($preview),$token,$actor]);return (int)$this->db->pdo()->lastInsertId();
    }
    private function head(int $supplier,int $document):array{$row=$this->one('SELECT row_version,application_id FROM property_service_settlement_heads WHERE supplier_id=? AND document_id=? FOR UPDATE',[$supplier,$document]);return ['row_version'=>(int)($row['row_version']??0),'application_id'=>isset($row['application_id'])?(int)$row['application_id']:null];}
    private function retry(int $supplier,string $token,array $request):?array{$row=$this->one('SELECT id,request_hash FROM property_service_settlement_requests WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token]);if(!$row)return null;if($row['request_hash']!==PropertyInput::hash($request))SettlementEvidence::reject('idempotency_conflict');return $this->forRequest($supplier,(int)$row['id']);}
    private function forRequest(int $supplier,int $request):array{$row=$this->one('SELECT id FROM property_service_settlement_applications WHERE supplier_id=? AND request_id=? FOR UPDATE',[$supplier,$request]);if(!$row)SettlementEvidence::reject('incomplete_application');return (new SettlementHistory($this->db))->application($supplier,(int)$row['id'],true);}
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
}
