<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\Funds\{FundingSourceProof,FundingException};
final class FundingReadRepository
{
    public function __construct(private readonly Connection $db){}
    private function tx(int $supplier,callable $work):array {return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){(new PaymentLocks($this->db))->acquire($supplier);return$work();});}
    public function choices(int $supplier,string $date):array
    {return$this->tx($supplier,function()use($supplier,$date){$read=new PropertyReadRepository($this->db);$at=FundingSourceProof::instant($date);$accounts=$this->db->pdo()->prepare('SELECT id,account_code,name,account_type FROM chart_of_accounts WHERE supplier_id=? AND is_active=1 ORDER BY account_code');$accounts->execute([$supplier]);return['houses'=>$read->list($supplier,'house',$at,null,true),'units'=>$read->list($supplier,'unit',$at,null,true),'accounts'=>$accounts->fetchAll(\PDO::FETCH_ASSOC),'key_modes'=>['at_accounting_date','resolution_snapshot'],'classifications'=>['repair','improvement','administration']];});}
    private function lineLabel(int $supplier,int $line):array
    {$q=$this->db->pdo()->prepare('SELECT e.entry_date,e.description,e.source_type,e.source_id,a.account_code,a.name AS account_name FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.id=?');$q->execute([$supplier,$line]);return$q->fetch(\PDO::FETCH_ASSOC)?:[];}
    public function sources(int $supplier,string $kind,string $date,int $after=0,?string $classification=null):array
    {return$this->tx($supplier,function()use($supplier,$kind,$date,$after,$classification){
        $proof=new FundingSourceProof($this->db);$items=[];$skipped=[];
        if($classification!==null&&!in_array($classification,['repair','improvement','administration'],true))\MyInvoice\Service\Property\PropertyInput::invalid('classification');
        if($kind==='costs'){$codes=[];$roles=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db);foreach(['svj.common_part.repair','svj.common_part.improvement','svj.management.cost'] as $role)$codes[]=$roles->resolve($supplier,$role,$date);$q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.id>? AND e.entry_date<=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.side='debit' AND a.account_code IN (?,?,?) ORDER BY l.id LIMIT 101 FOR UPDATE");$q->execute([$supplier,$after,$date,...$codes]);}
        elseif($kind==='coverages'){$q=$this->db->pdo()->prepare("SELECT l.id FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND l.id>? AND e.entry_date<=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.source_type NOT IN ('property_funding','nonprofit_capital','closing','opening') AND l.side='credit' AND LEFT(a.account_code,3) IN ('648','649','691') ORDER BY l.id LIMIT 101 FOR UPDATE");$q->execute([$supplier,$after,$date]);}
        elseif($kind==='banks'){$q=$this->db->pdo()->prepare('SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id=t.statement_id WHERE s.supplier_id=? AND t.id>? AND t.posted_at=? ORDER BY t.id LIMIT 101 FOR UPDATE');$q->execute([$supplier,$after,$date]);}
        else FundingException::reject('source_kind');
        $ids=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));$more=count($ids)>100;$ids=array_slice($ids,0,100);
        foreach($ids as $id){try{if($kind==='costs'){$found=null;foreach($classification===null?['repair','improvement','administration']:[$classification] as $candidate){try{$found=$proof->cost($supplier,$id,$date,$candidate)+['classification'=>$candidate];break;}catch(FundingException $e){if($e->errorCode!=='property_funding_cost_source')throw$e;}}if($found===null)continue;$items[]=['id'=>$id]+$found+$this->lineLabel($supplier,$id);}elseif($kind==='coverages'){$items[]=['id'=>$id]+$proof->coverage($supplier,$id,$date)+$this->lineLabel($supplier,$id);}else{$row=$this->db->pdo()->prepare('SELECT amount FROM bank_transactions WHERE id=?');$row->execute([$id]);$items[]=$proof->bank($supplier,$id,$date,bccomp((string)$row->fetchColumn(),'0',2)>0?'in':'out');}}
        catch(FundingException|\MyInvoice\Service\Property\Payments\PaymentException $e){$skipped[]=['id'=>$id,'reason'=>$e->errorCode];}}
        return['items'=>$items,'incomplete'=>$skipped,'next_after_id'=>$more?end($ids):null];
    });}
}
