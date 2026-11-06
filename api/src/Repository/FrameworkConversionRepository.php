<?php

declare(strict_types=1);
namespace MyInvoice\Repository;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
use MyInvoice\Service\Accounting\Closing\{ClosingEntryBuilder,Fx504Builder,Fx504CaseProjection};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;
final class FrameworkConversionRepository
{
    public function __construct(private readonly Connection $db) {}
    public static function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function hash(mixed $value):string{return hash('sha256',self::json($value));}
    public function transaction(int $supplier,callable $work):mixed
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work){
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            $q=$this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([$supplier]);E::need($q->fetchColumn()!==false,'conversion_tenant_missing',404);
            return $work();
        });
    }
    public function rows(string $sql,array $args,bool $current=false):array
    {
        E::need(!$current||$this->db->pdo()->inTransaction(),'conversion_transaction_required');
        $q=$this->db->pdo()->prepare($sql.($current?' FOR UPDATE':''));$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function periods(int $supplier,bool $current=false):array
    {return $this->rows('SELECT * FROM accounting_periods WHERE supplier_id=? ORDER BY starts_on,id',[$supplier],$current);}
    public function source(int $supplier,int $sourceId,int $targetId,bool $current=false):array
    {
        $periods=array_column($this->periods($supplier,$current),null,'id');$source=$periods[$sourceId]??null;$target=$periods[$targetId]??null;
        E::need($source!==null&&$target!==null,'conversion_period_missing',404);
        E::need(in_array($source['status'],['closed','reviewed','approved'],true),'conversion_native_close_required');
        E::need($target['starts_on']===(new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d'),'conversion_contiguous_period_required');
        $frameworkRepository=new AccountingFrameworkRepository($this->db);
        $frameworks=$current?$frameworkRepository->listForTenantForUpdate($supplier):array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['id','supplier_id','effective_from','accounting_framework'])),$frameworkRepository->listForTenant($supplier));
        $framework='cz_500';foreach($frameworks as $row)if($row['effective_from']<=$source['ends_on'])$framework=$row['accounting_framework'];
        E::need($framework==='cz_500','conversion_source_500_required');
        $atStart='cz_500';foreach($frameworks as $history)if($history['effective_from']<=$source['starts_on'])$atStart=$history['accounting_framework'];E::need($atStart==='cz_500','conversion_source_500_required');
        foreach($frameworks as $history)if($history['effective_from']>$source['starts_on']&&$history['effective_from']<=$source['ends_on'])E::need($history['accounting_framework']==='cz_500','conversion_source_500_required');
        $step=$this->rows("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='close_books'",[$supplier,$sourceId],$current)[0]??null;
        E::need(($step['status']??null)==='done','conversion_native_close_required');$payload=json_decode($step['payload'],true,512,JSON_THROW_ON_ERROR);
        $entry=(int)($payload['entry_id']??0);E::need($entry>0,'conversion_native_close_required');
        // Převod potřebuje rozvahu od poslední kotvy počátečních stavů a výsledovku
        // zdrojového období. Starší historie do výsledku nevstupuje, proto se ani
        // nečte; bez kotvy (firma nikdy neotevírala) jde rozvaha od začátku deníku.
        $anchor=$this->openingAnchor($supplier,$source['ends_on'],$current);
        $lower=$anchor===null?null:min($anchor,$source['starts_on']);
        $range=$lower===null?'':' AND e.entry_date>=?';
        $rangeArgs=$lower===null?[$supplier,$source['ends_on']]:[$supplier,$source['ends_on'],$lower];
        $headers=$this->rows(
            'SELECT e.* FROM journal_entries e
              WHERE e.supplier_id=? AND e.entry_date<=?'.$range.' AND e.posted_at IS NOT NULL
              ORDER BY e.id',
            $rangeArgs,
            $current,
        );
        $close=array_column($headers,null,'id')[$entry]??null;
        E::need($close!==null&&$close['source_type']==='closing'&&(int)$close['source_id']===$sourceId&&(int)$close['period_id']===$sourceId&&$close['reversed_by']===null&&$close['entry_date']===$source['ends_on'],'conversion_native_close_changed');
        $rows=$this->rows(
            // STRAIGHT_JOIN: rozsah data zápisu po idx_je_supplier_entry_date musí řídit
            // dotaz; jinak optimalizátor začne osnovou a projde všechny řádky firmy.
            'SELECT STRAIGHT_JOIN l.*,e.entry_date,e.period_id,e.source_type,e.source_id,e.row_version AS entry_version,a.account_code,a.account_type
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
               JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
              WHERE e.supplier_id=? AND e.entry_date<=?'.$range.' AND e.posted_at IS NOT NULL
              ORDER BY e.id,l.line_no,l.id',
            $rangeArgs,
            $current,
        );
        $assign=new DimensionAssignmentRepository($this->db);$ids=array_column($rows,'id');$dimensions=$assign->lineDimensions($supplier,$ids,$current);$splits=$assign->lineSplits($supplier,$ids,$current);
        foreach($rows as &$row){$row['dimensions']=$dimensions[$row['id']]??[];$row['dimension_splits']=$splits[$row['id']]??[];}unset($row);
        $closing=array_values(array_filter($rows,static fn($r)=>(int)$r['entry_id']===$entry));
        $expect=(new \MyInvoice\Service\Accounting\Conversion\FrameworkConversionNative500Adapter($this->db))->snapshot($supplier,$source,$current);
        E::need(self::journal($closing)===self::journal($expect['lines']),'conversion_native_close_changed');
        E::need(JournalLineAmount::cents($payload['profit']??'0')===JournalLineAmount::cents($expect['profit']),'conversion_profit_changed');
        $native=array_values(array_filter($rows,static fn($r)=>$r['source_type']!=='closing'&&($anchor===null||$r['entry_date']>=$anchor)));
        $bs=array_values(array_filter($native,static fn($r)=>in_array($r['account_type'],['asset','liability','equity'],true)));
        $plNative=array_values(array_filter($rows,static fn($r)=>(int)$r['entry_id']!==$entry&&$r['entry_date']>=$source['starts_on']&&in_array($r['account_type'],['revenue','expense'],true)));
        $origins=(new FxCaseOriginReadRepository($this->db))->snapshot($supplier,$bs,$current);
        foreach(['invoices','purchase_invoices'] as $key)foreach($origins['facts'][$key] as &$doc)if(!in_array($doc['status'],['draft','cancelled'],true))$doc['status']='active';unset($doc);
        $origins['facts_hash']=self::hash($origins['facts']);
        $definitions=(new AccountDefinitionRepository($this->db))->listForDate($supplier,$source['ends_on'],$current);
        $periodShape=array_intersect_key($source,array_flip(['id','supplier_id','fiscal_year','starts_on','ends_on']));
        $sourceHash=self::hash([$supplier,$periodShape,$step,$headers,$rows,$origins['facts'],$definitions]);
        return ['source'=>$source,'target'=>$target,'source_entry_id'=>$entry,'step'=>$step,'closing'=>$closing,'native'=>$native,'bs'=>$bs,'pl_native'=>$plNative,'origins'=>$origins,'profit_cents'=>JournalLineAmount::cents($expect['profit']),'source_hash'=>$sourceHash,'frameworks'=>$frameworks];
    }
    /**
     * Začátek období posledního platného zaúčtovaného zápisu počátečních stavů
     * nejpozději k datu. Jde po indexu (supplier_id, source_type) místo čtení
     * všech hlaviček deníku.
     */
    private function openingAnchor(int $supplier, string $endsOn, bool $current): ?string
    {
        $openings = $this->rows(
            "SELECT e.id, p.starts_on
               FROM journal_entries e
               JOIN accounting_periods p ON p.id = e.period_id AND p.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = 'opening' AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL AND e.entry_date <= ?",
            [$supplier, $endsOn],
            $current,
        );
        $anchor = null;
        foreach ($openings as $opening) {
            if ($anchor === null || $opening['starts_on'] > $anchor) {
                $anchor = (string) $opening['starts_on'];
            }
        }
        return $anchor;
    }

    public static function journal(array $rows):array
    {
        $out=[];foreach($rows as $r){$dimensions=$r['dimensions']??[];ksort($dimensions);$splits=$r['dimension_splits']??[];ksort($splits);$out[]=[(string)$r['account_code'],$r['side'],JournalLineAmount::signedCents($r),$dimensions,$splits,$r['cost_center']??null,isset($r['project_id'])?(int)$r['project_id']:null];}return $out;
    }
    public function cells(array $snapshot):array
    {
        $closing=[];foreach($snapshot['closing'] as $row)if(in_array($row['account_type'],['asset','liability','equity'],true)){
            $key=self::tuple($row);E::need(!isset($closing[$key]),'conversion_close_cell_ambiguous');
            $closing[$key]=['account_code'=>$row['account_code'],'account_type'=>$row['account_type'],'dimensions'=>$row['dimensions'],'cost_center'=>$row['cost_center'],'project_id'=>$row['project_id'],'czk_cents'=>($row['side']==='credit'?1:-1)*JournalLineAmount::signedCents($row),'close_line_ids'=>[(int)$row['id']]];
        }
        $parts=[];
        foreach($snapshot['bs'] as $row){
            foreach(Fx504Builder::sourceCells($row) as $cell){
                $tuple=self::tuple($cell);$currency=$cell['currency_code'];$origin=null;
                if($currency!=='CZK'){$candidates=$snapshot['origins']['origins'][$row['id']]??[];E::need(count($candidates)===1,'conversion_fx_origin_required',409,['line_id'=>(int)$row['id'],'required'=>'Native document or bank/cash journal with a unique currency case.']);$origin=Fx504CaseProjection::origin($candidates[0]);}
                $key=self::hash([$tuple,$currency,$origin]);
                $parts[$key]??=['tuple'=>$tuple,'account_code'=>$row['account_code'],'account_type'=>$row['account_type'],'dimensions'=>$cell['dimensions'],'cost_center'=>$cell['cost_center'],'project_id'=>$cell['project_id'],'currency_code'=>$currency,'origin'=>$origin,'czk_cents'=>0,'foreign_cents'=>0,'source_line_ids'=>[]];
                $parts[$key]['czk_cents']+=$cell['czk_cents'];$parts[$key]['foreign_cents']+=$cell['foreign_cents'];$parts[$key]['source_line_ids'][]=(int)$row['id'];
            }
        }
        $totals=[];$out=[];
        foreach($parts as $part){if($part['czk_cents']===0&&$part['foreign_cents']===0)continue;
            E::need($part['czk_cents']!==0,'conversion_zero_czk_foreign_basis_required');$tuple=$part['tuple'];unset($part['tuple']);
            E::need(isset($closing[$tuple]),'conversion_close_reconciliation');$totals[$tuple]=($totals[$tuple]??0)+$part['czk_cents'];
            E::need($part['foreign_cents']===0||($part['foreign_cents']>0)===($part['czk_cents']>0),'conversion_fx_sign_changed');
            $part['close_line_ids']=$closing[$tuple]['close_line_ids'];$part['role']=in_array(substr($part['account_code'],0,3),['428','429'],true)?'retained':'balance';$part['cell_key']=self::hash($part);$out[$part['cell_key']]=$part;
        }
        foreach($closing as $tuple=>$cell)E::need(($totals[$tuple]??0)===$cell['czk_cents'],'conversion_close_reconciliation');
        $results=[];
        foreach($snapshot['pl_native'] as $row)foreach(Fx504Builder::sourceCells(array_replace($row,['currency_code'=>'CZK','amount_foreign'=>null])) as $cell){
            $key=self::tuple(array_replace($cell,['account_code'=>'current_result']));$results[$key]??=['account_code'=>'current_result','account_type'=>'equity','dimensions'=>$cell['dimensions'],'cost_center'=>$cell['cost_center'],'project_id'=>$cell['project_id'],'currency_code'=>'CZK','origin'=>null,'czk_cents'=>0,'foreign_cents'=>0,'source_line_ids'=>[],'source_account_codes'=>[],'close_line_ids'=>[],'role'=>'pending_approval'];$results[$key]['czk_cents']+=$cell['czk_cents'];$results[$key]['source_line_ids'][]=(int)$row['id'];$results[$key]['source_account_codes'][]=$row['account_code'];
        }
        E::need(array_sum(array_column($results,'czk_cents'))===-$snapshot['profit_cents'],'conversion_profit_changed');
        foreach($results as $part){if($part['czk_cents']===0)continue;$part['source_line_ids']=array_values(array_unique($part['source_line_ids']));sort($part['source_line_ids'],SORT_NUMERIC);$part['source_account_codes']=array_values(array_unique($part['source_account_codes']));sort($part['source_account_codes'],SORT_STRING);foreach($snapshot['closing'] as $line)if(in_array($line['account_code'],$part['source_account_codes'],true))$part['close_line_ids'][]=(int)$line['id'];sort($part['close_line_ids'],SORT_NUMERIC);$part['cell_key']=self::hash($part);$out[$part['cell_key']]=$part;}

        usort($out,static fn($a,$b)=>strcmp(self::cellIdentity($a),self::cellIdentity($b)));E::need(array_sum(array_column($out,'czk_cents'))===0,'conversion_opening_unbalanced');return array_values($out);
    }
    public static function cellIdentity(array $cell):string
    {return self::hash(array_intersect_key($cell,array_flip(['account_code','account_type','dimensions','project_id','currency_code','origin','czk_cents','foreign_cents','source_line_ids','close_line_ids','role'])));}
    public static function tuple(array $row):string
    {$dims=$row['dimensions']??[];ksort($dims);E::need(($row['dimension_splits']??[])===[],'conversion_joint_cells_required');return self::hash([$row['account_code'],$dims,$row['cost_center']??null,isset($row['project_id'])?(int)$row['project_id']:null]);}

    public function writtenLedger(int $supplier,int $entry,array $header,array $lines):array
    {
        $actual=$this->rows('SELECT supplier_id,period_id,entry_date,document_date,document_no,description,source_type,source_id,posted_at,posted_by,reversed_by FROM journal_entries WHERE supplier_id=? AND id=?',[$supplier,$entry],true)[0]??null;
        E::need($actual!==null&&$actual['posted_at']!==null&&$actual['reversed_by']===null,'conversion_written_header_changed');
        $expected=['document_date'=>null,'document_no'=>null,'description'=>null,'source_type'=>'manual','source_id'=>null,'posted_by'=>null]+$header;
        foreach(['supplier_id','period_id','entry_date','document_date','document_no','description','source_type','source_id','posted_by'] as $key){$a=$actual[$key];$b=$header[$key]??$expected[$key];if(in_array($key,['supplier_id','period_id','source_id','posted_by'],true)){$a=$a===null?null:(int)$a;$b=$b===null?null:(int)$b;}E::need($a===$b,'conversion_written_header_changed');}
        if(isset($header['posted_at']))E::need($actual['posted_at']===$header['posted_at'],'conversion_written_header_changed');
        $accounts=array_column($this->rows('SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? ORDER BY id',[$supplier],true),'account_code','id');foreach($lines as &$line){$code=$accounts[$line['account_id']??0]??null;E::need($code!==null&&(!isset($line['account_code'])||$line['account_code']===$code),'conversion_written_lines_changed');$line['account_code']=$code;}unset($line);
        $ledger=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($supplier,$entry);
        E::need(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($lines)===\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($ledger['lines']),'conversion_written_lines_changed');
        return $ledger;
    }
    public function receipt(int $supplier,int $id,bool $current=false):array
    {
        $row=$this->rows('SELECT * FROM accounting_framework_conversions WHERE supplier_id=? AND id=?',[$supplier,$id],$current)[0]??null;E::need($row!==null,'conversion_not_found',404);
        $receipt=json_decode($row['receipt_json'],true,512,JSON_THROW_ON_ERROR);E::need(hash_equals($row['receipt_hash'],self::hash($receipt)),'conversion_receipt_changed');return $row+['receipt'=>$receipt,'request'=>json_decode($row['request_json'],true,512,JSON_THROW_ON_ERROR)];
    }
}
