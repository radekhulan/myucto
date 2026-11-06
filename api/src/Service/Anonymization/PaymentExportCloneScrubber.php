<?php

declare(strict_types=1);
namespace MyInvoice\Service\Anonymization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\PaymentExportPlan;
use PDO;

final class PaymentExportCloneScrubber
{
    private bool $finished=false;
    private function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber,private readonly \Closure $text,
        private readonly array $exports,private readonly array $reservations,private readonly array $audits,private readonly string $savepoint) {}
    private function __clone() {}
    public function __serialize():array {throw new \LogicException('Export clone proof cannot be serialized.');}
    public static function capture(Connection $db,TextScrubber $scrubber,\Closure $text):?self
    {
        $pdo=$db->pdo();if(!$pdo->inTransaction())throw new \LogicException('Export clone requires the payment clone transaction.');
        $count=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('unit_payment_exports','unit_payment_export_items')")->fetchColumn();
        if($count!==2)throw new \LogicException('Export clone requires complete schema.');
        if(TriggerMetadata::any($pdo))throw new \LogicException('Export clone must precede trigger installation.');
        $exports=$pdo->query('SELECT * FROM unit_payment_exports ORDER BY supplier_id,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);$items=$pdo->query('SELECT * FROM unit_payment_export_items ORDER BY supplier_id,export_id,reservation_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        if($exports===[]&&$items===[])return null;
        $links=[];foreach($items as $item)$links[$item['supplier_id']][$item['export_id']][]=$item;
        $reservations=[];$audits=[];$used=0;
        foreach($exports as $row){$snapshot=self::decode($row['snapshot_json']);$request=self::decode($row['request_json']);self::hash($row['snapshot_hash'],$snapshot);self::hash($row['request_hash'],['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
            if(PropertyInput::hash($snapshot['request'])!==PropertyInput::hash($request)||base64_decode($row['content_base64'],true)!==PaymentExportPlan::bytes($snapshot)||!hash_equals($row['content_hash'],hash('sha256',PaymentExportPlan::bytes($snapshot))))self::corrupt();
            $parts=$links[$row['supplier_id']][$row['id']]??[];if(count($parts)!==count($snapshot['reservations']))self::corrupt();
            foreach($snapshot['reservations'] as $index=>$fact){$part=$parts[$index];$used++;if((int)$part['reservation_id']!==$fact['id']||$part['amount_cents']!==$fact['amount_cents'])self::corrupt();
                $q=$pdo->prepare('SELECT * FROM unit_credit_reservations WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$row['supplier_id'],$fact['id']]);$reservation=$q->fetch(PDO::FETCH_ASSOC);if(!$reservation)self::corrupt();
                $r=self::decode($reservation['request_json']);self::hash($reservation['request_hash'],['credit_id'=>(int)$reservation['credit_id'],'expected_version'=>$r['expected_version'],'request'=>$r['request'],'token'=>$reservation['idempotency_key'],'actor'=>(int)$reservation['created_by']]);
                if((int)$reservation['credit_id']!==$fact['credit_id']||$reservation['request_hash']!==$fact['request_hash']||PropertyInput::hash($r['request'])!==PropertyInput::hash($fact['request'])||bcadd($fact['paid_cents'],$fact['amount_cents'],0)!==$reservation['amount_cents'])self::corrupt();
                $q=$pdo->prepare('SELECT snapshot_json,snapshot_hash FROM unit_credit_lots WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$row['supplier_id'],$fact['credit_id']]);$credit=$q->fetch(PDO::FETCH_ASSOC);if(!$credit||$credit['snapshot_hash']!==$fact['credit_snapshot_hash'])self::corrupt();self::hash($credit['snapshot_hash'],self::decode($credit['snapshot_json']));
                $reservations[$row['supplier_id']][$fact['id']]=$reservation;
            }
            $q=$pdo->prepare("SELECT * FROM unit_payment_audit WHERE supplier_id=? AND object_kind='reservation' AND action='submitted' AND JSON_VALUE(payload_json,'$.export_id')=? ORDER BY id FOR UPDATE");$q->execute([$row['supplier_id'],$row['id']]);$audit=$q->fetchAll(PDO::FETCH_ASSOC);if(count($audit)!==count($parts))self::corrupt();
            $auditParts=array_column($parts,null,'reservation_id');
            foreach($audit as $event){$payload=self::decode($event['payload_json']);self::hash($event['payload_hash'],$payload);$part=$auditParts[$event['object_id']]??null;
                if(!$part||$payload['snapshot_hash']!==$row['snapshot_hash']||($payload['amount_cents']??null)!==$part['amount_cents']||(int)($payload['export_id']??0)!==(int)$row['id'])self::corrupt();unset($auditParts[$event['object_id']]);$audits[]=$event;}
            if($auditParts!==[])self::corrupt();
        }
        if($used!==count($items))self::corrupt();$savepoint='property_export_clone_'.bin2hex(random_bytes(10));$pdo->exec('SAVEPOINT '.$savepoint);
        return new self($db,$scrubber,$text,$exports,$reservations,$audits,$savepoint);
    }
    public function finish(array $hashes,\Closure $references):void
    {
        $pdo=$this->db->pdo();if($this->finished||!$pdo->inTransaction())throw new \LogicException('Export clone proof is no longer current.');
        try{$pdo->exec('RELEASE SAVEPOINT '.$this->savepoint);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1305)throw new \LogicException('Export clone proof belongs to a different transaction.',0,$e);throw $e;}$this->finished=true;
        foreach($this->reservations as $supplier=>$rows)foreach($rows as $id=>$row){$data=($this->text)(self::decode($row['request_json']));$expected=PropertyInput::hash(['credit_id'=>(int)$row['credit_id'],'expected_version'=>$data['expected_version'],'request'=>$data['request'],'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
            $q=$pdo->prepare('SELECT request_json,request_hash FROM unit_credit_reservations WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$current=$q->fetch(PDO::FETCH_ASSOC);if(!$current||($hashes[$row['request_hash']]??null)!==$expected||$current['request_hash']!==$expected||PropertyInput::hash(self::decode($current['request_json']))!==PropertyInput::hash($data))self::corrupt();
        }
        $newHashes=[];
        foreach($this->exports as $row){$request=$this->transform(self::decode($row['request_json']));$snapshot=$references($this->transform(self::decode($row['snapshot_json'])));$bytes=PaymentExportPlan::bytes($snapshot);$hash=PropertyInput::hash($snapshot);$newHashes[$row['snapshot_hash']]=$hash;
            $this->update('unit_payment_exports',$row,['request_json'=>self::json($request),'snapshot_json'=>self::json($snapshot),'request_hash'=>PropertyInput::hash(['request'=>$request,'token'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]),'snapshot_hash'=>$hash,'content_base64'=>base64_encode($bytes),'content_hash'=>hash('sha256',$bytes)]);
        }
        foreach($this->audits as $row){$expected=$references(($this->text)(self::decode($row['payload_json'])));$q=$pdo->prepare('SELECT payload_json,payload_hash FROM unit_payment_audit WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$row['supplier_id'],$row['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);if(!$current||$current['payload_hash']!==PropertyInput::hash($expected)||PropertyInput::hash(self::decode($current['payload_json']))!==PropertyInput::hash($expected))self::corrupt();
            $expected['snapshot_hash']=$newHashes[$expected['snapshot_hash']]??throw new \LogicException('Missing verified export snapshot map.');$this->update('unit_payment_audit',$row,['payload_json'=>self::json($expected),'payload_hash'=>PropertyInput::hash($expected)]);
        }
    }
    private function transform(array $raw):array
    {
        $out=($this->text)($raw);
        if(isset($raw['payer']))foreach(AnonymizationPolicy::COLUMNS['supplier_bank_accounts'] as $key=>$strategy)if($strategy!=='keep'&&is_string($raw['payer'][$key]??null))$out['payer'][$key]=$this->scrubber->byStrategy($strategy,$raw['payer'][$key]);
        $symbols=function(array $value):array{foreach($value['payment_symbols']??[] as $id=>$row)if(isset($row['message']))$value['payment_symbols'][$id]['message']=$this->scrubber->scrub($row['message']);return $value;};
        $out=$symbols($out);if(isset($out['request']))$out['request']=$symbols($out['request']);return $out;
    }
    private function update(string $table,array $row,array $values):void {$q=$this->db->pdo()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($values))).' WHERE supplier_id=? AND id=?');$q->execute([...array_values($values),$row['supplier_id'],$row['id']]);}
    private static function decode(string $json):array {return json_decode($json,true,flags:JSON_THROW_ON_ERROR);}
    private static function json(array $value):string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function hash(string $hash,array $value):void {if(!hash_equals($hash,PropertyInput::hash($value)))self::corrupt();}
    private static function corrupt():never {PaymentException::reject('clone_export_proof');}
}
