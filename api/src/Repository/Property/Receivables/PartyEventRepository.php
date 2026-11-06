<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Receivables;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Charges\Issuance\LiabilityPolicy;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Receivables\{ReceivableException,ReceivableTimeline,PropertyLiabilityProjection};
use PDO;
final class PartyEventRepository
{
    public function __construct(private readonly Connection $db){}
    public function history(int $supplier,bool $currentRead=false):array
    {
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Party current read requires owner transaction.');
        $suffix=$currentRead?' FOR UPDATE':'';
        $rows=$this->all('SELECT * FROM property_claim_party_events WHERE supplier_id=? ORDER BY id'.$suffix,[$supplier]);
        $links=[];foreach($this->all('SELECT * FROM property_claim_party_event_debts WHERE supplier_id=? ORDER BY event_id,debt_id'.$suffix,[$supplier]) as $r)$links[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'predecessor_event_id'=>$r['predecessor_event_id']===null?null:(int)$r['predecessor_event_id'],'remaining_cents'=>$r['remaining_cents']];
        $members=[];foreach($this->all('SELECT * FROM property_claim_party_members WHERE supplier_id=? ORDER BY event_id,debt_id,group_key,client_id'.$suffix,[$supplier]) as $r)$members[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'group_key'=>$r['group_key'],'role'=>$r['role'],'client_id'=>(int)$r['client_id']];
        return $this->decodeHistory($rows,$links,$members);
    }
    public function historyForDebts(int $supplier,array $debtIds):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Party current read requires owner transaction.');
        foreach($debtIds as $id)PropertyInput::id($id,'debt_id');
        (new PaymentLocks($this->db))->acquire($supplier);
        $selected=array_fill_keys($debtIds,true);$events=[];
        do{
            $before=count($selected);$ids=json_encode(array_map('intval',array_keys($selected)),JSON_THROW_ON_ERROR);
            $linked=$this->all("SELECT l.event_id FROM property_claim_party_event_debts l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.debt_id WHERE l.supplier_id=? ORDER BY l.event_id FOR UPDATE",[$ids,$supplier]);
            $linkedIds=json_encode(array_values(array_unique(array_map('intval',array_column($linked,'event_id')))),JSON_THROW_ON_ERROR);
            $rows=$this->all("SELECT e.* FROM property_claim_party_events e WHERE e.supplier_id=? AND (EXISTS (SELECT 1 FROM JSON_TABLE(e.snapshot_json,'$.debts[*]' COLUMNS(debt_id BIGINT PATH '$.debt_id')) p JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=p.debt_id) OR e.id IN (SELECT id FROM JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted)) ORDER BY e.id FOR UPDATE",[$supplier,$ids,$linkedIds]);
            if(array_diff(array_column($linked,'event_id'),array_column($rows,'id'))!==[])ReceivableException::reject('history_corrupt');
            $eventIds=json_encode(array_map('intval',array_column($rows,'id')),JSON_THROW_ON_ERROR);$links=[];$members=[];
            foreach($this->all("SELECT l.* FROM property_claim_party_event_debts l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.event_id WHERE l.supplier_id=? ORDER BY l.event_id,l.debt_id FOR UPDATE",[$eventIds,$supplier]) as $r)$links[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'predecessor_event_id'=>$r['predecessor_event_id']===null?null:(int)$r['predecessor_event_id'],'remaining_cents'=>$r['remaining_cents']];
            foreach($this->all("SELECT l.* FROM property_claim_party_members l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.event_id WHERE l.supplier_id=? ORDER BY l.event_id,l.debt_id,l.group_key,l.client_id FOR UPDATE",[$eventIds,$supplier]) as $r)$members[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'group_key'=>$r['group_key'],'role'=>$r['role'],'client_id'=>(int)$r['client_id']];
            $events=$this->decodeHistory($rows,$links,$members);
            foreach($events as $event)foreach($event['snapshot']['debts'] as $debt)$selected[$debt['debt_id']]=true;
        }while(count($selected)!==$before);
        $ids=array_map('intval',array_keys($selected));sort($ids,SORT_NUMERIC);
        return ['events'=>$events,'debt_ids'=>$ids];
    }
    public function readHistoryForDebts(int $supplier,array $debtIds,array $unitIds=[]):array
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Party current read requires owner transaction.');
        foreach($debtIds as $id)PropertyInput::id($id,'debt_id');

        foreach($unitIds as $id)PropertyInput::id($id,'unit_id');$unitList=$unitIds===[]?'NULL':implode(',',array_map('intval',$unitIds));
        $selected=array_fill_keys($debtIds,true);$events=[];
        do{
            $before=count($selected);$ids=json_encode(array_map('intval',array_keys($selected)),JSON_THROW_ON_ERROR);
            $linked=$this->all("SELECT l.event_id FROM property_claim_party_event_debts l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.debt_id WHERE l.supplier_id=? ORDER BY l.event_id",[$ids,$supplier]);
            $linkedIds=json_encode(array_values(array_unique(array_map('intval',array_column($linked,'event_id')))),JSON_THROW_ON_ERROR);
            $rows=$this->all("SELECT e.* FROM property_claim_party_events e WHERE e.supplier_id=? AND (EXISTS (SELECT 1 FROM JSON_TABLE(e.snapshot_json,'$.debts[*]' COLUMNS(debt_id BIGINT PATH '$.debt_id')) p JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=p.debt_id) OR e.id IN (SELECT id FROM JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted) OR e.unit_id IN (".$unitList.") OR JSON_VALUE(e.request_json,'$.unit_id') IN (".$unitList.")) ORDER BY e.id",[$supplier,$ids,$linkedIds]);
            if(array_diff(array_column($linked,'event_id'),array_column($rows,'id'))!==[])ReceivableException::reject('history_corrupt');
            $eventIds=json_encode(array_map('intval',array_column($rows,'id')),JSON_THROW_ON_ERROR);$links=[];$members=[];
            foreach($this->all("SELECT l.* FROM property_claim_party_event_debts l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.event_id WHERE l.supplier_id=? ORDER BY l.event_id,l.debt_id",[$eventIds,$supplier]) as $r)$links[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'predecessor_event_id'=>$r['predecessor_event_id']===null?null:(int)$r['predecessor_event_id'],'remaining_cents'=>$r['remaining_cents']];
            foreach($this->all("SELECT l.* FROM property_claim_party_members l JOIN JSON_TABLE(?,'$[*]' COLUMNS(id BIGINT PATH '$')) wanted ON wanted.id=l.event_id WHERE l.supplier_id=? ORDER BY l.event_id,l.debt_id,l.group_key,l.client_id",[$eventIds,$supplier]) as $r)$members[$r['event_id']][]=['debt_id'=>(int)$r['debt_id'],'group_key'=>$r['group_key'],'role'=>$r['role'],'client_id'=>(int)$r['client_id']];
            $events=$this->decodeHistory($rows,$links,$members);
            foreach($events as $event)foreach($event['snapshot']['debts'] as $debt)$selected[$debt['debt_id']]=true;
        }while(count($selected)!==$before);
        $ids=array_map('intval',array_keys($selected));sort($ids,SORT_NUMERIC);
        return ['events'=>$events,'debt_ids'=>$ids];
    }
    public function decodeHistory(array $rows,array $links,array $members):array
    {
        $out=[];foreach($rows as $r){
            $snapshot=FinancialHistoryRepository::decode($r);$request=json_decode($r['request_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($r['request_hash'],PropertyInput::hash(['request'=>$request,'token'=>$r['idempotency_key'],'actor'=>(int)$r['created_by']]))||PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request']))ReceivableException::reject('history_corrupt');
            $expected=[];$parties=[];
            foreach($snapshot['debts'] as $debt){$expected[]=['debt_id'=>$debt['debt_id'],'predecessor_event_id'=>$debt['predecessor_event_id'],'remaining_cents'=>$debt['remaining_cents']];foreach($debt['groups'] as $g)foreach($g['members'] as $id)$parties[]=['debt_id'=>$debt['debt_id'],'group_key'=>$g['group_key'],'role'=>$g['role'],'client_id'=>$id];}
            usort($expected,fn($a,$b)=>$a['debt_id']<=>$b['debt_id']);usort($parties,fn($a,$b)=>[$a['debt_id'],$a['group_key'],$a['client_id']]<=>[$b['debt_id'],$b['group_key'],$b['client_id']]);
            if(PropertyInput::hash($expected)!==PropertyInput::hash($links[$r['id']]??[])||PropertyInput::hash($parties)!==PropertyInput::hash($members[$r['id']]??[])||(int)$r['unit_id']!==$request['unit_id']||(int)$r['registry_revision_id']!==$request['registry_revision_id']||PropertyInstant::sql($request['effective_at'])!==$r['effective_at'])ReceivableException::reject('history_corrupt');
            $out[]=['id'=>(int)$r['id'],'effective_at'=>FinancialHistoryRepository::instant($r['effective_at']),'recorded_at'=>FinancialHistoryRepository::instant($r['recorded_at']),'snapshot'=>$snapshot,'snapshot_hash'=>$r['snapshot_hash']];
        }return $out;
    }
    public function preview(int $supplier,array $request):array{return $this->transaction($supplier,fn()=> $this->plan($supplier,$request));}
    public function confirm(int $supplier,array $request,string $previewHash,string $token,int $actor):array
    {
        PropertyInput::code($token,'idempotency_key');PropertyInput::id($actor,'actor');
        return $this->transaction($supplier,function()use($supplier,$request,$previewHash,$token,$actor){
            $hash=PropertyInput::hash(['request'=>$request,'token'=>$token,'actor'=>$actor]);$old=$this->all('SELECT id,request_hash,snapshot_hash FROM property_claim_party_events WHERE supplier_id=? AND idempotency_key=? FOR UPDATE',[$supplier,$token])[0]??null;
            if($old){if(!hash_equals($old['request_hash'],$hash)||!hash_equals($old['snapshot_hash'],$previewHash))ReceivableException::reject('idempotency_conflict');foreach($this->history($supplier,true) as $event)if($event['id']===(int)$old['id'])return $event;}
            $plan=$this->plan($supplier,$request);if(!hash_equals($plan['preview_hash'],$previewHash))ReceivableException::reject('preview_stale');$snapshot=$plan['snapshot'];
            $this->execute('INSERT INTO property_claim_party_events(supplier_id,unit_id,registry_revision_id,effective_at,recorded_at,request_json,snapshot_json,request_hash,snapshot_hash,idempotency_key,created_by) VALUES (?,?,?,?,UTC_TIMESTAMP(6),?,?,?,?,?,?)',[$supplier,$request['unit_id'],$request['registry_revision_id'],PropertyInstant::sql($request['effective_at']),self::json($request),self::json($snapshot),$hash,$previewHash,$token,$actor]);$id=(int)$this->db->pdo()->lastInsertId();
            foreach($snapshot['debts'] as $debt){$this->execute('INSERT INTO property_claim_party_event_debts(supplier_id,event_id,debt_id,predecessor_event_id,remaining_cents) VALUES (?,?,?,?,?)',[$supplier,$id,$debt['debt_id'],$debt['predecessor_event_id'],$debt['remaining_cents']]);foreach($debt['groups'] as $g)foreach($g['members'] as $member)$this->execute('INSERT INTO property_claim_party_members(supplier_id,event_id,debt_id,group_key,role,client_id) VALUES (?,?,?,?,?,?)',[$supplier,$id,$debt['debt_id'],$g['group_key'],$g['role'],$member]);}
            foreach($this->history($supplier,true) as $event)if($event['id']===$id)return $event;throw new \LogicException('Inserted event missing.');
        });
    }
    private function plan(int $supplier,array $request):array
    {
        PropertyInput::fields($request,['unit_id','registry_revision_id','effective_at','decision','acquisition','debts','reason']);$at=PropertyInstant::normalize($request['effective_at']??null);$known=self::now();
        $decision=$request['decision']??null;if(!in_array($decision,['transfer_confirmed','transfer_not_applicable','review_required','superseding_correction'],true))PropertyInput::invalid('decision');
        PropertyInput::text($request['reason']??null,'reason',4000);$acquisition=$request['acquisition']??null;if(!is_array($acquisition))PropertyInput::invalid('acquisition');
        PropertyInput::fields($acquisition,['kind','applicable_law','legal_basis','evidence','had_and_could_discover','knowledge_evidence','guarantee_evidence']);
        foreach(['kind','applicable_law','legal_basis','evidence','knowledge_evidence','guarantee_evidence'] as $field)PropertyInput::text($acquisition[$field]??null,$field,4000);
        if(!is_bool($acquisition['had_and_could_discover']??null)||($decision==='transfer_confirmed'&&!$acquisition['had_and_could_discover']))PropertyInput::invalid('had_and_could_discover');
        $reader=new PropertyReadRepository($this->db);$unit=$reader->find($supplier,PropertyInput::id($request['unit_id']??null,'unit_id'),$at,null,true);
        if($unit['kind']!=='unit'||$unit['ownership_review_required']||($unit['revision']['id']??null)!==($request['registry_revision_id']??null))ReceivableException::reject('registry_proof');
        $wanted=PropertyInput::list($request['debts']??null,'debts');if($wanted===[])PropertyInput::invalid('debts');$seen=[];$ids=[];
        foreach($wanted as $part){PropertyInput::fields($part,['debt_id','expected_event_id','groups']);$id=PropertyInput::id($part['debt_id']??null,'debt_id');if(isset($seen[$id]))PropertyInput::invalid('debt_id');$seen[$id]=true;foreach($part['groups']??[] as $group)array_push($ids,...($group['members']??[]));}
        $parties=$reader->parties($supplier,$ids,true);$events=$this->history($supplier,true);$financial=(new FinancialHistoryRepository($this->db))->read($supplier,true);$debts=[];
        foreach($wanted as $part){
            $debt=$financial['debts'][$part['debt_id']]??null;if(!$debt||$debt['unit_id']!==$request['unit_id'])ReceivableException::reject('foreign_debt');
            $before=PropertyLiabilityProjection::project($debt,$events,$at,$known);
            if($before['head_event_id']!==($part['expected_event_id']??null))ReceivableException::reject('version_conflict');
            if(!$before['complete']&&$decision!=='superseding_correction')ReceivableException::reject('historical_precision');
            foreach($events as $event)foreach($event['snapshot']['debts'] as $old)if($old['debt_id']===$debt['id']&&$event['effective_at']>$at)ReceivableException::reject('subsequent_party_event');
            $baseline=[];$payerBaseline=[];$claimBaseline='0';
            foreach($debt['events'] as $effect){$included=ReceivableTimeline::inclusion($effect,$at,$known);if($included===null)ReceivableException::reject('historical_precision');if(!$included)ReceivableException::reject('subsequent_financial_event_review',['event_id'=>$effect['id']]);if($effect['kind']==='paid'){$baseline[$effect['group_key']]=bcadd($baseline[$effect['group_key']]??'0',$effect['amount_cents'],0);if(isset($effect['payer_id']))$payerBaseline[$effect['payer_id']]=bcadd($payerBaseline[$effect['payer_id']]??'0',$effect['amount_cents'],0);}if($effect['kind']==='claim')$claimBaseline=bcadd($claimBaseline,$effect['amount_cents'],0);}
            $groups=LiabilityPolicy::validate($part['groups']??[],$unit['revision']['state'],$at,array_column($parties,'id'));
            if($decision==='transfer_confirmed'){
                $owners=array_merge(...array_column($unit['revision']['state']['ownerships'],'members'));sort($owners,SORT_NUMERIC);
                $successors=[];foreach($groups as $g)if($g['role']==='debtor')array_push($successors,...$g['members']);sort($successors,SORT_NUMERIC);
                if($owners!==$successors)ReceivableException::reject('successor_registry_mismatch');
            }
            if($decision==='transfer_not_applicable'||$decision==='review_required'){
                $prior=$before['groups'];foreach($prior as &$g)unset($g['remaining_cap_cents']);unset($g);if(PropertyInput::hash($groups)!==PropertyInput::hash($prior))ReceivableException::reject('unchanged_roles_required');
            }
            $remaining=$before['remaining_cents'];if($remaining===null||bccomp($remaining,'0',0)<0)ReceivableException::reject('historical_precision');
            foreach($groups as $g){
                if(bcmod(bcmul($remaining,$g['extent']['num'],0),$g['extent']['den'],0)!=='0')ReceivableException::reject('fractional_liability_title_required');
                if($g['role']==='guarantor'&&$decision==='transfer_confirmed'){
                    $prior=null;foreach($before['groups'] as $old)if($old['members']===$g['members'])$prior=$old;
                    if($prior===null||bccomp(bcdiv(bcmul($remaining,$g['extent']['num'],0),$g['extent']['den'],0),$prior['remaining_cap_cents'],0)>0)ReceivableException::reject('guarantee_scope_unproven');
                }
            }
            if($decision==='transfer_confirmed')self::assertTransferorsGuarantee($before['groups'],$groups,$remaining);
            $debts[]=['debt_id'=>$debt['id'],'predecessor_event_id'=>$before['head_event_id'],'remaining_cents'=>$remaining,'groups'=>$groups,'group_paid_baseline'=>$baseline,'payer_paid_baseline'=>$payerBaseline,'claim_baseline_cents'=>$claimBaseline,'source_snapshot_hash'=>$debt['snapshot_hash'],'financial_events'=>$debt['events'],'previous_liability'=>$before];
        }
        usort($debts,fn($a,$b)=>$a['debt_id']<=>$b['debt_id']);$snapshot=['request'=>$request,'registry'=>['id'=>$unit['revision']['id'],'state_hash'=>$unit['revision']['state_hash']],'parties'=>$parties,'debts'=>$debts];return ['snapshot'=>$snapshot,'preview_hash'=>PropertyInput::hash($snapshot)];
    }
    /**
     * § 1186 odst. 2 OZ: převodce za přešlý dluh ručí. Každá dosavadní skupina dlužníků, která
     * nezůstává hlavním dlužníkem, se proto musí objevit jako ručitel se stejnými členy
     * a v dosavadním rozsahu, jinak by převod převodce tiše zprostil odpovědnosti.
     */
    private static function assertTransferorsGuarantee(array $priorGroups,array $groups,string $remaining):void
    {
        $debtors=[];
        $guarantors=[];
        foreach($groups as $group){
            $members=$group['members'];
            sort($members,SORT_NUMERIC);
            $key=implode(',',$members);
            if($group['role']==='debtor'){
                $debtors[$key]=true;
                continue;
            }
            $guarantors[$key]=bcdiv(bcmul($remaining,$group['extent']['num'],0),$group['extent']['den'],0);
        }
        foreach($priorGroups as $prior){
            if($prior['role']!=='debtor')continue;
            $cap=$prior['remaining_cap_cents']??null;
            if($cap===null)ReceivableException::reject('historical_precision');
            if(bccomp($cap,'0',0)<=0)continue;
            $members=$prior['members'];
            sort($members,SORT_NUMERIC);
            $key=implode(',',$members);
            if(isset($debtors[$key]))continue;
            if(!isset($guarantors[$key]))ReceivableException::reject('transferor_guarantee_required',['members'=>$members,'remaining_cap_cents'=>$cap]);
            if(bccomp($guarantors[$key],$cap,0)!==0)ReceivableException::reject('transferor_guarantee_scope',['members'=>$members,'remaining_cap_cents'=>$cap,'guarantee_cap_cents'=>$guarantors[$key]]);
        }
    }
    private function transaction(int $supplier,callable $work):mixed{return (new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return $work();});}
    private function execute(string $sql,array $args):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private function all(string $sql,array $args):array{return $this->execute($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    public static function now():string{return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');}
}
