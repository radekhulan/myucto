<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;
/**
 * Výběry pro ruční zadání kapitálového zdroje. Jde o čtení (GET), proto bez transakce
 * a bez zámků: podklady se znovu ověřují a zamykají až při náhledu a potvrzení operace.
 */
final class CapitalChoices
{
    private const SEARCH_KEYS=['source_search','cost_search','provider_search','asset_search'];
    private const LIMIT=500;
    public function __construct(private readonly Connection $db) {}
    public function read(int $supplier,int $period,array $search=[]):array
    {
        $search=$this->search($search);
        $pdo=$this->db->pdo();
        $q=$pdo->prepare('SELECT id,starts_on,ends_on,status FROM accounting_periods WHERE supplier_id=? AND id=?');
        $q->execute([$supplier,$period]);
        $owner=$q->fetch(PDO::FETCH_ASSOC);
        if(!$owner)CapitalPolicy::changed();
        $owner['id']=(int)$owner['id'];
        $out=['period'=>$owner];
        $sourcePrefixes=[
            CapitalLedger::sourceAccount($this->db,$supplier,'investment',$owner['starts_on']).'%',
            CapitalLedger::sourceAccount($this->db,$supplier,'fund',$owner['starts_on']).'%',
        ];
        foreach(['source','cost']as$kind){
            $predicate=$kind==='source'
                ?"(a.account_code LIKE ? OR a.account_code LIKE ?) AND l.side='credit' AND NOT EXISTS(SELECT 1 FROM nonprofit_capital_sources s WHERE s.supplier_id=l.supplier_id AND s.source_line_id=l.id)"
                :"a.account_type='expense' AND a.account_code LIKE '5%' AND a.account_code NOT LIKE '59%' AND l.side='debit' AND e.period_id=?";
            $args=[$supplier];
            if($kind==='source')array_push($args,...$sourcePrefixes);
            if($kind==='cost')$args[]=$period;
            $args[]=$search[$kind.'_search'];
            $args[]=self::like($search[$kind.'_search']);
            $q=$pdo->prepare("SELECT l.id,l.amount,l.is_red_storno,a.account_code,e.entry_date,e.description FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE l.supplier_id=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.amount>0 AND l.is_red_storno=0 AND ".$predicate." AND (?='' OR CONCAT_WS(' ',a.account_code,e.description,e.document_no,l.id) LIKE ? ESCAPE '!') ORDER BY e.entry_date DESC,l.id DESC LIMIT ".(self::LIMIT+1));
            $q->execute($args);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $out[$kind.'s_more']=count($rows)>self::LIMIT;
            $out[$kind.'s']=array_map(static fn(array $row)=>[
                'id'=>(int)$row['id'],
                'amount_cents'=>JournalLineAmount::signedCents($row),
                'account_code'=>$row['account_code'],
                'date'=>$row['entry_date'],
                'description'=>$row['description'],
            ],array_slice($rows,0,self::LIMIT));
        }
        foreach(['provider','asset']as$kind){
            $sql=$kind==='provider'
                ?"SELECT id,CONCAT_WS(' ',NULLIF(company_name,''),first_name,last_name) label FROM clients WHERE supplier_id=? AND (?='' OR CONCAT_WS(' ',company_name,first_name,last_name,ic) LIKE ? ESCAPE '!') ORDER BY company_name,id LIMIT ".(self::LIMIT+1)
                :"SELECT id,CONCAT_WS(' ',inventory_number,name) label FROM assets WHERE supplier_id=? AND status IN ('draft','in_use') AND depreciator_ground='owner' AND (?='' OR CONCAT_WS(' ',inventory_number,name) LIKE ? ESCAPE '!') ORDER BY inventory_number,id LIMIT ".(self::LIMIT+1);
            $q=$pdo->prepare($sql);
            $q->execute([$supplier,$search[$kind.'_search'],self::like($search[$kind.'_search'])]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $out[$kind.'s_more']=count($rows)>self::LIMIT;
            $out[$kind.'s']=array_map(static fn(array $row)=>['id'=>(int)$row['id'],'label'=>$row['label']],array_slice($rows,0,self::LIMIT));
        }
        $q=$pdo->prepare("SELECT v.id,v.name label,t.system_key FROM dimension_values v JOIN dimension_types t ON t.supplier_id=v.supplier_id AND t.id=v.type_id WHERE v.supplier_id=? AND v.is_active=1 AND t.system_key IN ('activity_kind','tax_regime') ORDER BY t.system_key,v.code,v.id");
        $q->execute([$supplier]);
        $out['activities']=[];
        $out['tax_regimes']=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){
            $out[$row['system_key']==='activity_kind'?'activities':'tax_regimes'][]=['id'=>(int)$row['id'],'label'=>$row['label']];
        }
        $q=$pdo->prepare("SELECT account_code code,name label FROM chart_of_accounts WHERE supplier_id=? AND account_type='expense' AND account_code LIKE '5%' AND account_code NOT LIKE '59%' AND is_active=1 ORDER BY account_code");
        $q->execute([$supplier]);
        $out['accounts']=$q->fetchAll(PDO::FETCH_ASSOC);
        return $out;
    }
    private function search(array $search):array
    {
        if(array_diff(array_keys($search),self::SEARCH_KEYS)!==[])CapitalPolicy::invalid('Neplatný filtr podkladů.');
        foreach(self::SEARCH_KEYS as$key){
            $search[$key]??='';
            if(!is_string($search[$key])||mb_strlen($search[$key])>200)CapitalPolicy::invalid('Filtr podkladů musí být krátký text.');
        }
        return $search;
    }
    private static function like(string $term):string
    {
        return '%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
    }
}
