<?php
declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\{PaymentJournalProof,PaymentLocks};
use MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility;
use MyInvoice\Service\Bank\BankTransactionContext;
use PDO;

final class FundingCloneScrubber
{
    public const TABLES=['property_funding_heads','property_funding_operations','property_funding_effects','property_funding_claims'];
    private array $nodes=[];
    private array $replacements=[];
    private array $resolved=[];
    private array $resolving=[];
    private array $targets=[];
    private array $ledgerSuppliers=[];
    private array $bankSuppliers=[];
    private function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber,private array $hashes,private readonly \Closure $text,private readonly \Closure $map)
    {foreach($hashes as $hash)$this->targets[$hash]=true;}

    public static function captureInputs(Connection $db):array
    {
        self::context($db);
        $owner=new FundingRepository($db);$rows=self::rows($db);
        foreach($rows['property_funding_operations'] as $row){
            (new PaymentLocks($db))->acquire((int)$row['supplier_id']);
            if($row['receipt_json']===null)throw new \LogicException('Funding clone requires completed native operations.');
            $owner->operation((int)$row['supplier_id'],(int)$row['id']);
        }
        return [['funding_rows'=>$rows,'funding_rows_hash'=>PropertyInput::hash($rows)]];
    }

    public static function run(Connection $db,TextScrubber $scrubber,array $registryMap,array $hashes,callable $text,callable $map,array $inputs):array
    {
        self::context($db);$rows=self::rows($db);$captured=null;
        foreach($inputs as $input)if(isset($input['funding_rows'])){if($captured!==null)throw new \LogicException('Duplicate funding clone capture.');$captured=$input;}
        if($captured===null){foreach($rows as $tableRows)if($tableRows!==[])throw new \LogicException('Funding clone requires its original native proof capture.');return $hashes;}
        if(PropertyInput::hash($captured['funding_rows'])!==$captured['funding_rows_hash']||PropertyInput::hash($rows)!==$captured['funding_rows_hash'])throw new \LogicException('Funding clone capture changed before transformation.');
        $owner=new self($db,$scrubber,$hashes,\Closure::fromCallable($text),\Closure::fromCallable($map));
        foreach($registryMap as $supplier=>$proofs)foreach($proofs as $proof){$owner->hashes[$proof['before_state_hash']]=$proof['after_state_hash'];$owner->targets[$proof['after_state_hash']]=true;}
        foreach($rows['property_funding_operations'] as $row){
            $request=self::decode($row['request_json']);$snapshot=self::decode($row['snapshot_json']);$receipt=self::decode($row['receipt_json']);
            $identity=['request'=>$request,'preview_hash'=>$row['snapshot_hash'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']];
            foreach([[$request,null],[$snapshot,$row['snapshot_hash']],[$receipt,$row['receipt_hash']],[$identity,$row['request_hash']]] as [$data,$hash]){if($hash!==null&&PropertyInput::hash($data)!==$hash)throw new \LogicException('Funding clone original hash mismatch.');$owner->index($data,(int)$row['supplier_id']);}
        }
        foreach($rows['property_funding_effects'] as $row)if($row['parties_json']!==null)$owner->index(self::decode($row['parties_json']),(int)$row['supplier_id']);
        foreach($rows['property_funding_operations'] as $row){
            $request=$owner->transform(self::decode($row['request_json']));$snapshot=$owner->transform(self::decode($row['snapshot_json']));$receipt=$owner->transform(self::decode($row['receipt_json']));
            $requestHash=($owner->map)($row['request_hash'],['request'=>$request,'preview_hash'=>PropertyInput::hash($snapshot),'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
            $snapshotHash=($owner->map)($row['snapshot_hash'],$snapshot);$receiptHash=($owner->map)($row['receipt_hash'],$receipt);
            if($requestHash!==$owner->hash($row['request_hash'])||$snapshotHash!==$owner->hash($row['snapshot_hash'])||$receiptHash!==$owner->hash($row['receipt_hash']))throw new \LogicException('Funding clone graph materialization mismatch.');
            $db->pdo()->prepare('UPDATE property_funding_operations SET request_json=?,request_hash=?,snapshot_json=?,snapshot_hash=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([self::json($request),$requestHash,self::json($snapshot),$snapshotHash,self::json($receipt),$receiptHash,$row['supplier_id'],$row['id']]);
        }
        foreach($rows['property_funding_effects'] as $row)if($row['parties_json']!==null)$db->pdo()->prepare('UPDATE property_funding_effects SET parties_json=? WHERE supplier_id=? AND id=?')->execute([self::json($owner->transform(self::decode($row['parties_json']))),$row['supplier_id'],$row['id']]);
        $native=new FundingRepository($db);
        foreach($rows['property_funding_operations'] as $row){(new PaymentLocks($db))->acquire((int)$row['supplier_id']);$native->operation((int)$row['supplier_id'],(int)$row['id']);}
        foreach($owner->resolved as $old=>$new)if(($owner->map)($old,$owner->transform($owner->nodes[$old]))!==$new)throw new \LogicException('Funding clone graph mapping mismatch.');
        return $owner->resolved+$owner->hashes;
    }

    private static function context(Connection $db):void
    {if(!$db->pdo()->inTransaction()||TriggerMetadata::any($db->pdo()))throw new \LogicException('Funding clone requires its owner transaction before trigger installation.');}
    private static function rows(Connection $db):array
    {
        $rows=[];foreach(self::TABLES as $table){$order=match($table){'property_funding_heads'=>'supplier_id,house_id','property_funding_claims'=>'supplier_id,operation_id,source_kind,source_id,claim_role',default=>'supplier_id,id'};$rows[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order.' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);}return $rows;
    }
    private function index(array $data,?int $supplier=null):void
    {
        $this->nodes[PropertyInput::hash($data)]=$data;
        if(isset($data['entry']['id'],$data['lines'])){if($supplier===null)throw new \LogicException('Funding clone ledger tenant missing.');$this->ledgerSuppliers[$data['entry']['id']]=$supplier;}
        if(isset($data['statement_id'],$data['posted_at'],$data['id'])&&array_key_exists('statement_currency',$data)){if($supplier===null)throw new \LogicException('Funding clone bank tenant missing.');$this->bankSuppliers[$data['id']]=$supplier;}
        if(isset($data['batch_id'],$data['snapshot_hash'],$data['snapshot'],$data['unit_id'],$data['amount_cents'],$data['supplier_id'])){
            $charge=(new \MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository($this->db))->find((int)$data['supplier_id'],(int)$data['id'],true);
            if(PropertyInput::hash($data['snapshot'])!==$data['snapshot_hash']||($this->hashes[$data['snapshot_hash']]??null)!==$charge['snapshot_hash'])throw new \LogicException('Funding clone native prescription proof missing.');
            foreach(['id','supplier_id','batch_id','unit_id','revision_no','previous_charge_id','journal_entry_id','accounting_date','period_from','period_to','due_date','issued_at','claim_origin_at','issue_mode','source_key','amount_cents'] as $field)if($data[$field]!==$charge[$field])throw new \LogicException('Funding clone immutable prescription changed.');
            $this->replacements[$data['snapshot_hash']]=$charge['snapshot'];
            $replacement=$data;$replacement['snapshot']=$charge['snapshot'];$replacement['snapshot_hash']=$charge['snapshot_hash'];$this->replacements[PropertyInput::hash($data)]=$replacement;
        }
        if(isset($data['kind'],$data['revision']['state'],$data['revision']['state_hash'])){
            foreach([$data['revision'],...($data['history']??[])] as $revision){
                if(PropertyInput::hash($revision['state'])!==$revision['state_hash'])throw new \LogicException('Funding clone registry source mismatch.');
                $new=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState($data['kind'],$revision['state']);
                if(($this->hashes[$revision['state_hash']]??null)!==PropertyInput::hash($new))throw new \LogicException('Funding clone registry mapping missing.');
                $this->replacements[$revision['state_hash']]=$new;
            }
        }
        if(isset($data['source_etag'],$data['raw'],$data['money_account_receipt'])){
            if(PaymentSourceEligibility::etag($data)!==$data['source_etag'])throw new \LogicException('Funding clone bank source etag mismatch.');
            $this->nodes[$data['source_etag']]=['source'=>$data['raw'],'account'=>$data['money_account_receipt'],'register'=>$data['register'],'existing'=>$data['existing']];
        }
        foreach($data as $value)if(is_array($value))$this->index($value,$supplier);
    }
    private function hash(string $old):string
    {
        if(isset($this->hashes[$old]))return $this->hashes[$old];
        if(isset($this->resolved[$old]))return $this->resolved[$old];
        if(!isset($this->nodes[$old])){if(isset($this->targets[$old]))return $old;throw new \LogicException('Funding clone hash source missing.');}
        if(isset($this->resolving[$old]))throw new \LogicException('Funding clone hash cycle.');$this->resolving[$old]=true;
        $new=PropertyInput::hash($this->transform($this->nodes[$old]));unset($this->resolving[$old]);return $this->resolved[$old]=$new;
    }
    private function transform(array $data):array
    {
        $old=PropertyInput::hash($data);if(isset($this->replacements[$old]))return $this->replacements[$old];
        if(isset($data['entry']['id'],$data['lines'])){
            $ledger=(new PaymentJournalProof($this->db))->current($this->ledgerSuppliers[$data['entry']['id']]??throw new \LogicException('Funding clone ledger tenant missing.'),$data['entry']['id']);
            $ledger['entry']['reversed_by']=$data['entry']['reversed_by'];if(PropertyInput::hash($ledger)!==PropertyInput::hash($data))throw new \LogicException('Funding clone immutable native ledger changed.');return $ledger;
        }
        if(isset($data['statement_id'],$data['posted_at'],$data['id'])&&array_key_exists('statement_currency',$data)){
            $raw=(new BankTransactionContext($this->db))->lock($this->bankSuppliers[$data['id']]??throw new \LogicException('Funding clone bank tenant missing.'),(int)$data['id']);if($raw===null)throw new \LogicException('Funding clone bank source missing.');foreach(['id','statement_id','posted_at','amount','currency','match_status','matched_invoice_id','source','has_explicit_allocation','statement_supplier_id','statement_currency','recipient_bank','counterparty_bank'] as $field)if(($data[$field]??null)!==($raw[$field]??null))throw new \LogicException('Funding clone immutable bank facts changed.');return $raw;
        }
        foreach($data as $key=>&$value){
            if(is_array($value))$value=$this->transform($value);
            elseif(is_string($value)&&in_array((string)$key,['title','purpose_evidence','recipient_authority_ref'],true))$value=$this->scrubber->scrub($value);
            elseif($key==='cost_capacity_hash'&&is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value))continue;
            elseif(is_string($value)&&(str_ends_with((string)$key,'_hash')||$key==='source_etag')&&preg_match('/^[a-f0-9]{64}$/D',$value))$value=$this->reference((string)$key,$value);
            else $value=($this->text)([$key=>$value])[$key];
        }unset($value);if(isset($data['case_key'],$data['case_ref'],$data['occurrence_ref']))$data['case_key']=PropertyInput::hash(['case_ref'=>$data['case_ref'],'occurrence_ref'=>$data['occurrence_ref']]);return $data;
    }
    private function reference(string $key,string $hash):string
    {try{return $this->hash($hash);}catch(\LogicException $error){throw new \LogicException('Funding clone unresolved reference: '.$key,0,$error);}}
    private static function decode(string $json):array{return json_decode($json,true,flags:JSON_THROW_ON_ERROR);}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
}
