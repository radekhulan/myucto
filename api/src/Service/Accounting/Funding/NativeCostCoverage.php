<?php
declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Funding;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;
final class NativeCostCoverage
{
    /**
     * Výnosy krytí nákladu (zúčtování fondů, jiné ostatní výnosy, provozní dotace) podle
     * vyhlášky 504. Role účetního rámce pro ně neexistují; prefixy jsou referenční syntetiky
     * výkazových položek a CapitalService z nich účtuje rozpuštění zdrojů.
     */
    public const REVENUE_PREFIXES=['648','649','691'];
    public const EXCLUDED_INCOME_SOURCES=['closing','opening','property_funding','nonprofit_capital'];
    public function __construct(private readonly Connection $db) {}
    public function forCost(int $supplier,int $line,bool $lock=true):array
    {
        return $this->periods($supplier,$lock,function()use($supplier,$line,$lock):array{
            $native=$this->native($supplier,$line,$lock);$selected=null;$expenses=[];$direct=0;
            foreach($native['lines']as$row){
                if($row['id']===$line)$selected=$row;
                if(str_starts_with($row['account_code'],'5'))$expenses[]=$row;
                if(self::isCoverageAccount($row['account_code']))$direct+=JournalLineAmount::signedCents($row)*($row['side']==='credit'?1:-1);
            }
            if($selected===null||!str_starts_with($selected['account_code'],'5')||str_starts_with($selected['account_code'],'59')||$selected['side']!=='debit'||JournalLineAmount::signedCents($selected)<=0||in_array($native['entry']['source_type'],self::EXCLUDED_INCOME_SOURCES,true))self::reject('Náklad není způsobilý pro doložení přímého krytí.');
            if($direct>0&&count($expenses)!==1)self::reject('Přímé krytí více nákladových řádků vyžaduje samostatné doložené rozdělení.');
            $credits=$this->creditNotes($supplier,$native['entry'],$lock);
            return ['covered_cents'=>min(max($direct,0),JournalLineAmount::signedCents($selected)),'ledger'=>$native,'eligible'=>$credits===[],'review_reasons'=>$credits===[]?[]:['native_purchase_credit_note'],'credit_notes'=>$credits];
        });
    }
    public function financingCost(int $supplier,int $line,bool $lock=true):array
    {
        return $this->periods($supplier,$lock,function()use($supplier,$line,$lock):array{
            $proof=$this->forCost($supplier,$line,$lock);$cost=array_column($proof['ledger']['lines'],null,'id')[$line];
            if($cost['dimension_splits']!==[])self::reject('Náklad vyžaduje samostatné doložené rozdělení před financováním.');
            $q=$this->db->pdo()->prepare("SELECT t.id,t.system_key,v.id AS value_id,v.semantic_key FROM dimension_types t JOIN dimension_values v ON v.supplier_id=t.supplier_id AND v.type_id=t.id WHERE t.supplier_id=? AND t.system_key IN ('activity','activity_kind','tax_regime')".($lock?' FOR UPDATE':''));$q->execute([$supplier]);$found=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$type=(int)$row['id'];if(($cost['dimensions'][$type]??null)!==(int)$row['value_id'])continue;$definition=\MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog::DEFINITIONS[$row['system_key']];if(($cost['dimension_origins'][$type]['origin']??'unknown')==='unknown'||$definition['values']!==[]&&!array_key_exists($row['semantic_key']??'',$definition['values']))self::reject('Náklad vyžaduje průkazné systémové zařazení činnosti a daně.');$found[$row['system_key']]=true;}
            if(count($found)!==3)self::reject('Náklad vyžaduje úplné systémové zařazení činnosti a daně.');return $proof;
        });
    }
    public function income(int $supplier,int $line,array $prefixes=self::REVENUE_PREFIXES):array
    {
        return $this->incomeFact($supplier,$line,$prefixes,false);
    }
    public function incomePoolMember(int $supplier,int $line,array $prefixes=self::REVENUE_PREFIXES):array
    {
        return $this->incomeFact($supplier,$line,$prefixes,true);
    }
    private function incomeFact(int $supplier,int $line,array $prefixes,bool $signedContribution):array
    {
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$line,$prefixes,$signedContribution):array{
            $guard->lockPeriods($supplier);$native=$this->native($supplier,$line);$selected=null;
            foreach($native['lines']as$row){if($row['id']===$line)$selected=$row;if(str_starts_with($row['account_code'],'5'))self::reject('Výnos již přímo kryje náklad ve vlastním účetním zápisu.');}
            if(in_array($native['entry']['source_type'],self::EXCLUDED_INCOME_SOURCES,true)||!in_array($native['entry']['source_type'],['manual','bank','cash','invoice','purchase_invoice','other_item'],true))self::reject('Tento výnos vyžaduje vlastníka původního účetního toku.');
            $matches=false;foreach($prefixes as$prefix)if($selected!==null&&str_starts_with($selected['account_code'],$prefix))$matches=true;
            $amount=$selected===null?0:JournalLineAmount::signedCents($selected)*($selected['side']==='credit'?1:-1);
            if(!$matches||($signedContribution?$amount===0:($selected['side']!=='credit'||$amount<=0)))self::reject('Výnos není způsobilým zdrojem krytí.');
            return ['amount_cents'=>$amount,'line'=>$selected,'ledger'=>$native];
        });
    }
    public function assertCreditNotePosting(array $header):void
    {
        if(($header['source_type']??null)!=='purchase_invoice'||($header['source_id']??null)===null)return;
        $supplier=(int)$header['supplier_id'];$q=$this->db->pdo()->prepare('SELECT document_kind,parent_purchase_invoice_id FROM purchase_invoices WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,(int)$header['source_id']]);$note=$q->fetch(PDO::FETCH_ASSOC);
        if(!$note||$note['document_kind']!=='credit_note'||$note['parent_purchase_invoice_id']===null)return;
        $q=$this->db->pdo()->prepare("SELECT l.id,l.side,l.amount,l.is_red_storno FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='purchase_invoice' AND e.source_id=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND a.account_code LIKE '5%' AND a.account_code NOT LIKE '59%' ORDER BY l.id FOR UPDATE");$q->execute([$supplier,$note['parent_purchase_invoice_id']]);$costs=$q->fetchAll(PDO::FETCH_ASSOC);$coverage=new CostCoverage($this->db);
        foreach($costs as$cost){if($cost['side']!=='debit'||JournalLineAmount::signedCents($cost)<=0)continue;$id=(int)$cost['id'];$native=$this->forCost($supplier,$id);if($native['covered_cents']>0||bccomp($coverage->claimed($supplier,$id),'0',0)>0)self::reject('Původní náklad má potvrzené financování. Před zaúčtováním dobropisu vyžaduje řízené zrušení krytí.');}
    }
    private function creditNotes(int $supplier,array $entry,bool $lock=true):array
    {
        $suffix=$lock?' FOR UPDATE':'';
        if($entry['source_type']!=='purchase_invoice'||$entry['source_id']===null)return [];
        $owner=$this->db->pdo()->prepare('SELECT document_kind FROM purchase_invoices WHERE supplier_id=? AND id=?'.$suffix);$owner->execute([$supplier,$entry['source_id']]);$kind=$owner->fetchColumn();if($kind===false)return [['unresolved_purchase_id'=>$entry['source_id']]];if(!in_array($kind,['invoice','receipt'],true))return [$this->proof($supplier,$entry['id'],$lock)];
        $q=$this->db->pdo()->prepare("SELECT e.id FROM purchase_invoices p JOIN journal_entries e ON e.supplier_id=p.supplier_id AND e.source_type='purchase_invoice' AND e.source_id=p.id WHERE p.supplier_id=? AND p.document_kind='credit_note' AND p.parent_purchase_invoice_id=? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL ORDER BY p.id,e.id".$suffix);$q->execute([$supplier,$entry['source_id']]);$out=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN)as$id)$out[]=$this->proof($supplier,(int)$id,$lock);return $out;
    }
    public static function isCoverageAccount(string $code):bool
    {
        foreach(self::REVENUE_PREFIXES as$prefix)if(str_starts_with($code,$prefix))return true;return false;
    }
    private function native(int $supplier,int $line,bool $lock=true):array
    {
        $q=$this->db->pdo()->prepare('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=?'.($lock?' FOR UPDATE':''));$q->execute([$supplier,$line]);$entry=$q->fetchColumn();if($entry===false)self::reject('Zdrojový účetní řádek nebyl nalezen.');
        $native=$this->proof($supplier,(int)$entry,$lock);if($native['entry']['reversed_by']!==null)self::reject('Zdrojový účetní řádek byl stornován.');return $native;
    }
    /** Zamykací čtení běží pod zámkem období firmy, přehled čte snímek bez zámků. */
    private function periods(int $supplier,bool $lock,callable $work):array
    {
        if(!$lock)return $work();
        $guard=new DimensionPeriodGuard($this->db);
        return $guard->transaction(function()use($guard,$supplier,$work):array{$guard->lockPeriods($supplier);return $work();});
    }
    private function proof(int $supplier,int $entry,bool $lock):array
    {
        $proof=new PaymentJournalProof($this->db);
        return $lock?$proof->current($supplier,$entry):$proof->snapshot($supplier,$entry);
    }
    private static function reject(string $message):never{throw new PostingException('accounting_cost_coverage_source_invalid',$message,409);}
}
