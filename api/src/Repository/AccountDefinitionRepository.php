<?php

declare(strict_types=1);
namespace MyInvoice\Repository;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountDefinitionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;
final class AccountDefinitionRepository
{
    private const FIELDS=['name','normal_side','tax_deductibility','is_active'];
    /** Jen sloupce, které relace čtou: celý řádek nese LONGTEXT a mezivýsledek okna by skončil v dočasné tabulce na disku. */
    private const HISTORY_COLUMNS='d.id,d.supplier_id,d.account_id,d.effective_from,d.name,d.normal_side,d.tax_deductibility,d.is_active';
    public function __construct(private readonly Connection $db) {}

    private const CURRENT_TABLES=['chart_of_accounts','accounting_account_definition_history'];

    /**
     * Osnova firmy platná k datu čtená aktuálním čtením ({@see relation()} s `FOR UPDATE`),
     * řádky podle id. Zámky drží osnovu i historii definic do konce transakce, takže v ní
     * se stejné čtení opakuje jen po změně některé z tabulek nebo po hranici transakce
     * ({@see \MyInvoice\Infrastructure\Database\LoggingPdo::tableWriteCount()}).
     *
     * @return array<int,array<string,mixed>>
     */
    public function currentForDate(int $supplier,string $date): array
    {
        $pdo=$this->db->pdo();
        if(!$pdo->inTransaction())throw new \LogicException('Current account definitions require caller locks.');
        $read=function()use($pdo,$supplier,$date):array{$q=$pdo->prepare('SELECT * FROM '.self::relation($date,$supplier,true).' a WHERE supplier_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier]);return array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'id');};
        if(!$pdo instanceof \MyInvoice\Infrastructure\Database\LoggingPdo||!$pdo->statementsTracked())return $read();
        $writes=implode('|',array_map($pdo->tableWriteCount(...),self::CURRENT_TABLES));$key=$supplier.'|'.$date;
        self::$currentMemo??=new \WeakMap();$memo=self::$currentMemo[$pdo]??null;
        if($memo===null||$memo['writes']!==$writes)$memo=['writes'=>$writes,'rows'=>[]];
        $memo['rows'][$key]??=$read();self::$currentMemo[$pdo]=$memo;
        return $memo['rows'][$key];
    }

    /** @var \WeakMap<\PDO,array{writes:string,rows:array<string,array<int,array<string,mixed>>>}>|null */
    private static ?\WeakMap $currentMemo=null;

    public static function date(string $date): string
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<'1900-01-01')throw new AccountDefinitionException('account_definition_date_invalid',422);
        return $date;
    }
    public static function relation(string $date,?int $supplier=null,bool $currentRead=false): string
    {
        self::date($date);if($supplier!==null&&$supplier<=0)throw new AccountDefinitionException('account_definition_tenant_invalid',422);
        $scope=$supplier===null?'':' AND supplier_id='.$supplier;
        $lock=$currentRead?' FOR UPDATE':'';
        return "(SELECT ".self::definitionColumns()."
            FROM chart_of_accounts a LEFT JOIN
            (SELECT ".self::HISTORY_COLUMNS.",ROW_NUMBER() OVER(PARTITION BY supplier_id,account_id ORDER BY effective_from DESC,id DESC) AS rn
             FROM accounting_account_definition_history d WHERE effective_from<='$date'$scope$lock) h
             ON h.supplier_id=a.supplier_id AND h.account_id=a.id AND h.rn=1
            WHERE (h.id IS NOT NULL OR NOT EXISTS(SELECT 1 FROM accounting_account_definition_history f WHERE f.supplier_id=a.supplier_id AND f.account_id=a.id))".($supplier===null?'':' AND a.supplier_id='.$supplier).$lock.')';
    }
    private static function definitionColumns():string
    {
        return "a.id,a.supplier_id,a.account_code,a.account_type,a.is_synthetic,a.parent_id,a.created_at,a.is_clearing,
            CASE WHEN h.id IS NULL THEN a.name ELSE h.name END AS name,
            CASE WHEN h.id IS NULL THEN a.normal_side ELSE h.normal_side END AS normal_side,
            CASE WHEN h.id IS NULL THEN a.tax_deductibility ELSE h.tax_deductibility END AS tax_deductibility,
            CASE WHEN h.id IS NULL THEN a.is_active ELSE h.is_active END AS is_active";
    }
    public static function journalJoin(int $supplier,bool $currentRead=false):string
    {
        if($supplier<=0)throw new AccountDefinitionException('account_definition_tenant_invalid',422);
        $lock=$currentRead?' FOR UPDATE':'';
        return "JOIN (SELECT ".self::definitionColumns().",h.effective_from AS definition_from,h.next_from AS definition_until
            FROM chart_of_accounts a LEFT JOIN
            (SELECT ".self::HISTORY_COLUMNS.",LEAD(effective_from) OVER(PARTITION BY supplier_id,account_id ORDER BY effective_from,id) AS next_from
             FROM accounting_account_definition_history d WHERE supplier_id=$supplier$lock) h
             ON h.supplier_id=a.supplier_id AND h.account_id=a.id WHERE a.supplier_id=$supplier$lock) a
            ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            AND (a.definition_from IS NULL OR a.definition_from<=e.entry_date)
            AND (a.definition_until IS NULL OR e.entry_date<a.definition_until)";
    }
    public function listForDate(int $supplier,string $date,bool $currentRead=false): array
    {
        self::date($date);$suffix=$currentRead?' FOR UPDATE':'';
        if($currentRead&&!$this->db->pdo()->inTransaction())throw new \LogicException('Current account definitions require caller locks.');
        $q=$this->db->pdo()->prepare('SELECT * FROM chart_of_accounts WHERE supplier_id=? ORDER BY id'.$suffix);$q->execute([$supplier]);$accounts=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->pdo()->prepare('SELECT * FROM accounting_account_definition_history WHERE supplier_id=? ORDER BY account_id,effective_from,id'.$suffix);$q->execute([$supplier]);$history=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$this->valid($row);$history[(int)$row['account_id']][]=$row;}
        $out=[];
        foreach($accounts as $account){$rows=$history[(int)$account['id']]??[];$chosen=null;foreach($rows as $row)if($row['effective_from']<=$date)$chosen=$row;
            if($rows!==[]&&$chosen===null)continue;
            if($chosen!==null)foreach(self::FIELDS as $field)$account[$field]=$chosen[$field];
            $out[]=$account;
        }
        return $out;
    }
    public function findForDate(int $supplier,int $account,string $date,bool $currentRead=false): ?array
    {
        self::date($date);
        try{[$base,$history]=$this->state($supplier,$account,$currentRead);}catch(AccountDefinitionException $e){if($e->errorCode==='account_definition_not_found')return null;throw $e;}
        $chosen=null;foreach($history as $row)if($row['effective_from']<=$date)$chosen=$row;
        if($history!==[]&&$chosen===null)return null;
        if($chosen!==null)foreach(self::FIELDS as $field)$base[$field]=$chosen[$field];
        return $base;
    }
    public function hasHistory(int $supplier,?int $account=null,bool $currentRead=false): bool
    {
        $q=$this->db->pdo()->prepare('SELECT id FROM accounting_account_definition_history WHERE supplier_id=?'.($account===null?'':' AND account_id=?').' LIMIT 1'.($currentRead?' FOR UPDATE':''));$q->execute($account===null?[$supplier]:[$supplier,$account]);return $q->fetchColumn()!==false;
    }
    public function stateHash(int $supplier,int $account,bool $currentRead=false): string
    {
        [$base,$history]=$this->state($supplier,$account,$currentRead);return hash('sha256',json_encode([$base,$history],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public function change(int $supplier,int $account,string $date,array $changes,string $expectedHash,?int $actor,string $evidence): string
    {
        self::date($date);if(array_diff(array_keys($changes),self::FIELDS)!==[])throw new AccountDefinitionException('account_definition_identity_immutable',422);
        return $this->transaction($supplier,function()use($supplier,$account,$date,$changes,$expectedHash,$actor,$evidence){
            [$base,$history]=$this->state($supplier,$account,true);
            if(!hash_equals($this->stateHash($supplier,$account,true),$expectedHash))throw new AccountDefinitionException('account_definition_stale');
            if($history!==[]&&$date<$history[0]['effective_from'])throw new AccountDefinitionException('account_definition_before_creation');
            $current=$base;$next=null;
            foreach($history as $row){if($row['effective_from']<=$date){foreach(self::FIELDS as $field)$current[$field]=$row[$field];}elseif($next===null)$next=$row['effective_from'];}
            $definition=self::definition(array_replace($current,$changes));
            if($history!==[]&&self::definition($current)===$definition)return $expectedHash;
            $q=$this->db->pdo()->prepare('SELECT id,status FROM accounting_periods WHERE supplier_id=? AND ends_on>=?'.($next===null?'':' AND starts_on<?').' ORDER BY starts_on,id FOR UPDATE');$q->execute($next===null?[$supplier,$date]:[$supplier,$date,$next]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $period)if(!in_array($period['status'],['open'],true))throw new AccountDefinitionException('account_definition_period_locked');
            $q=$this->db->pdo()->prepare('SELECT e.id FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id WHERE e.supplier_id=? AND l.account_id=? AND e.posted_at IS NOT NULL AND e.entry_date>=?'.($next===null?'':' AND e.entry_date<?').' ORDER BY e.id LIMIT 1 FOR UPDATE');$q->execute($next===null?[$supplier,$account,$date]:[$supplier,$account,$date,$next]);if($q->fetchColumn()!==false)throw new AccountDefinitionException('account_definition_in_use');
            if($history===[]&&$date>'1900-01-01')$this->write($supplier,$account,'1900-01-01',self::definition($base),$actor,$evidence);
            $this->write($supplier,$account,$date,$definition,$actor,$evidence);return $this->stateHash($supplier,$account,true);
        });
    }
    public function initializeCreated(int $supplier,int $account,string $date,?int $actor=null): void
    {
        self::date($date);$this->transaction($supplier,function()use($supplier,$account,$date,$actor){[$base,$history]=$this->state($supplier,$account,true);if($history!==[])throw new AccountDefinitionException('account_definition_exists');$q=$this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND account_id=? LIMIT 1 FOR UPDATE');$q->execute([$supplier,$account]);if($q->fetchColumn()!==false)throw new AccountDefinitionException('account_definition_existing_account');$q=$this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND parent_id=? LIMIT 1 FOR UPDATE');$q->execute([$supplier,$account]);if($q->fetchColumn()!==false)throw new AccountDefinitionException('account_definition_existing_account');$this->write($supplier,$account,$date,self::definition($base),$actor,'account_created');});
    }
    public function transaction(int $supplier,callable $work): mixed
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([$supplier]);if($q->fetchColumn()===false)throw new AccountDefinitionException('account_definition_tenant_invalid',404);return $work();});
    }
    private function state(int $supplier,int $account,bool $current): array
    {
        $suffix=$current?' FOR UPDATE':'';if($current&&!$this->db->pdo()->inTransaction())throw new \LogicException('Current account definitions require caller locks.');
        $q=$this->db->pdo()->prepare('SELECT * FROM chart_of_accounts WHERE supplier_id=? AND id=?'.$suffix);$q->execute([$supplier,$account]);$base=$q->fetch(PDO::FETCH_ASSOC);if($base===false)throw new AccountDefinitionException('account_definition_not_found',404);
        $q=$this->db->pdo()->prepare('SELECT * FROM accounting_account_definition_history WHERE supplier_id=? AND account_id=? ORDER BY effective_from,id'.$suffix);$q->execute([$supplier,$account]);$history=$q->fetchAll(PDO::FETCH_ASSOC);foreach($history as $row)$this->valid($row);return[$base,$history];
    }
    public static function definition(array $row): array
    {
        $d=array_intersect_key($row,array_flip(self::FIELDS));if(!in_array($d['is_active']??null,[0,1,'0','1',true,false],true))throw new AccountDefinitionException('account_definition_invalid',422);$d['is_active']=(int)$d['is_active'];
        if(!is_string($d['name']??null)||trim($d['name'])===''||mb_strlen($d['name'])>190||!array_key_exists('normal_side',$d)||!in_array($d['normal_side'],[null,'debit','credit'],true)||!in_array($d['tax_deductibility']??null,['deductible','non_deductible'],true)||!in_array($d['is_active'],[0,1],true))throw new AccountDefinitionException('account_definition_invalid',422);
        return ['name'=>$d['name'],'normal_side'=>$d['normal_side'],'tax_deductibility'=>$d['tax_deductibility'],'is_active'=>$d['is_active']];
    }
    public static function encode(array $definition): string
    {
        return json_encode(self::definition($definition),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    public static function definitionHash(int $supplier,int $account,string $date,array $definition): string
    {
        return hash('sha256',$supplier.':'.$account.':'.self::date($date).':'.self::encode($definition));
    }
    private function write(int $supplier,int $account,string $date,array $definition,?int $actor,string $evidence): void
    {
        if(trim($evidence)===''||mb_strlen($evidence)>1000)throw new AccountDefinitionException('account_definition_evidence_required',422);
        $json=self::encode($definition);$hash=self::definitionHash($supplier,$account,$date,$definition);
        $q=$this->db->pdo()->prepare('INSERT INTO accounting_account_definition_history(supplier_id,account_id,effective_from,name,normal_side,tax_deductibility,is_active,definition_json,definition_hash,evidence,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE name=VALUES(name),normal_side=VALUES(normal_side),tax_deductibility=VALUES(tax_deductibility),is_active=VALUES(is_active),definition_json=VALUES(definition_json),definition_hash=VALUES(definition_hash),evidence=VALUES(evidence),created_by=VALUES(created_by),recorded_at=VALUES(recorded_at),row_version=row_version+1');$q->execute([$supplier,$account,$date,...array_values($definition),$json,$hash,$evidence,$actor]);
    }
    private function valid(array $row): void
    {
        $definition=self::definition($row);$decoded=json_decode($row['definition_json'],true,512,JSON_THROW_ON_ERROR);
        if($definition!==$decoded||self::encode($definition)!==$row['definition_json']||!hash_equals($row['definition_hash'],self::definitionHash((int)$row['supplier_id'],(int)$row['account_id'],$row['effective_from'],$definition)))throw new AccountDefinitionException('account_definition_corrupt');
    }
}
