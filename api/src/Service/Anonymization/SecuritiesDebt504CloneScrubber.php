<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\SecuritiesDebt504Repository as R;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504PaymentProof;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;

final class SecuritiesDebt504CloneScrubber
{
    public const TABLES=['accounting_securities_debt_lots','accounting_securities_debt_events','accounting_securities_debt_claims','accounting_securities_debt_fx_carries'];
    public const COMMANDS='accounting_securities_debt_commands';
    private const TEXT=['lot_reference','instrument_identity','contract_reference','classification_reference','terms_reference','policy_reference','evidence','reference','scope_reference','inception_reference','registration_reference','statement_reference','employees_reference','market','market_reference','market_calendar_reference','cost_center','ref'];
    public static function captureInputs(Connection $db): array
    {
        self::context($db);$pdo=$db->pdo();$pdo->beginTransaction();
        try {
            $rows=self::rows($db);$native=[];$payments=[];$unbound=self::unbound($rows[self::TABLES[1]]);
            foreach($rows[self::TABLES[0]] as $lot){
                $supplier=(int)$lot['supplier_id'];$repo=new R($db);$date=self::date($lot,$rows[self::TABLES[1]]);
                $state=$repo->transaction($supplier,fn()=> $repo->current($supplier,(int)$lot['id'],$date));
                $entry=$state['facts']['acquisition']['entry']['id'];$native[$supplier][$entry]=(new PaymentJournalProof($db))->current($supplier,$entry);
            }
            foreach($rows[self::TABLES[1]] as $event){
                $supplier=(int)$event['supplier_id'];$receipt=R::decode($event['receipt_json']);
                if(isset($receipt['ledger'])){$id=$receipt['ledger']['entry']['id'];$native[$supplier][$id]=(new PaymentJournalProof($db))->current($supplier,$id);}
                if(isset($receipt['payment'])&&!isset($unbound[$supplier][(int)$event['id']])){$saved=$receipt['payment'];$id=$saved['ledger']['entry']['id'];$payments[$supplier][$id.':'.$saved['line']['id']]=(new SecuritiesDebt504PaymentProof($db))->current($supplier,$id,$saved['line']['id'],$saved['line']);$native[$supplier][$id]=$payments[$supplier][$id.':'.$saved['line']['id']]['ledger'];}
            }
            foreach($rows[self::TABLES[3]] as $carry){$r=R::decode($carry['receipt_json']);$supplier=(int)$carry['supplier_id'];$id=$r['ledger']['entry']['id'];$native[$supplier][$id]=(new PaymentJournalProof($db))->current($supplier,$id);}
            $pdo->commit();return ['rows'=>$rows,'native'=>$native,'payments'=>$payments];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function run(Connection $db,TextScrubber $scrubber,array $inputs): array
    {
        self::context($db);$pdo=$db->pdo();$pdo->beginTransaction();
        try {
            $rows=self::rows($db);
            if(($inputs['rows']??null)!==$rows)throw new \LogicException('Debt clone source preimage mismatch.');
            $native=[];
            foreach($inputs['native'] as $supplier=>$entries)foreach($entries as $id=>$before){
                $actual=(new PaymentJournalProof($db))->current((int)$supplier,(int)$id);
                if(self::financial($before)!==self::financial($actual))throw new \LogicException('Debt clone financial ledger changed.');
                $native[$supplier][$id]=$actual;
            }
            $factsByLot=[];$hashes=[];$unbound=self::unbound($rows[self::TABLES[1]]);
            foreach($rows[self::TABLES[0]] as $row){
                $facts=self::text(R::decode($row['facts_json']),$scrubber);$supplier=(int)$row['supplier_id'];$entry=$facts['acquisition']['entry']['id'];$facts['acquisition']=$native[$supplier][$entry];
                $asset=array_values(array_filter($facts['acquisition']['lines'],fn($line)=>$line['id']===$facts['asset']['id']));
                if(count($asset)!==1)throw new \LogicException('Debt clone asset missing.');$facts['asset']=$asset[0];$factsByLot[$supplier][(int)$row['id']]=$facts;
                $hashes[$row['facts_hash']]=R::hash($facts);
                $pdo->prepare('UPDATE accounting_securities_debt_lots SET lot_reference=?,facts_json=?,facts_hash=? WHERE supplier_id=? AND id=?')->execute([$facts['terms']['lot_reference'],R::json($facts),R::hash($facts),$supplier,$row['id']]);
            }
            foreach($rows[self::TABLES[1]] as $row){
                $supplier=(int)$row['supplier_id'];$request=self::text(R::decode($row['request_json']),$scrubber);$receipt=self::text(R::decode($row['receipt_json']),$scrubber);
                if(isset($receipt['ledger']))$receipt['ledger']=$native[$supplier][$receipt['ledger']['entry']['id']];
                if(isset($receipt['payment'])&&!isset($unbound[$supplier][(int)$row['id']])){
                    $saved=$receipt['payment'];$entry=$saved['ledger']['entry']['id'];$account=$row['operation']==='sale'?\MyInvoice\Service\Accounting\Closing\SecuritiesDebt504Policy::SALE_REVENUE_ACCOUNT:null;
                    $actual=(new SecuritiesDebt504PaymentProof($db))->current($supplier,$entry,$saved['line']['id'],$factsByLot[$supplier][(int)$row['lot_id']]['asset'],$account);
                    $before=$inputs['payments'][$supplier][$entry.':'.$saved['line']['id']]??null;
                    if($before===null||self::source($before)!==self::source($actual))throw new \LogicException('Debt clone financial source changed.');$receipt['payment']=$actual;
                }
                $hashes[$row['request_hash']]=R::hash($request);$hashes[$row['receipt_hash']]=R::hash($receipt);
                $pdo->prepare('UPDATE accounting_securities_debt_events SET request_json=?,request_hash=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([R::json($request),R::hash($request),R::json($receipt),R::hash($receipt),$supplier,$row['id']]);
            }
            foreach($rows[self::TABLES[3]] as $row){$receipt=self::text(R::decode($row['receipt_json']),$scrubber);$receipt['ledger']=$native[(int)$row['supplier_id']][$receipt['ledger']['entry']['id']];$hashes[$row['receipt_hash']]=R::hash($receipt);$pdo->prepare('UPDATE accounting_securities_debt_fx_carries SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND lot_id=? AND period_id=? AND slot=?')->execute([R::json($receipt),R::hash($receipt),$row['supplier_id'],$row['lot_id'],$row['period_id'],$row['slot']]);}
            $pdo->exec('DELETE FROM '.self::COMMANDS);
            foreach($rows[self::TABLES[0]] as $row){$repo=new R($db);$repo->transaction((int)$row['supplier_id'],fn()=> $repo->current((int)$row['supplier_id'],(int)$row['id'],self::date($row,$rows[self::TABLES[1]])));}
            $pdo->commit();return $hashes;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function context(Connection $db): void
    {
        if($db->pdo()->inTransaction()||TriggerMetadata::any($db->pdo()))throw new \LogicException('Debt clone requires its own transaction before trigger installation.');
    }
    private static function rows(Connection $db): array
    {
        $rows=[];
        foreach(self::TABLES as $table){$order=$table===self::TABLES[2]?'supplier_id,line_id':($table===self::TABLES[3]?'supplier_id,lot_id,period_id,slot':'supplier_id,id');$rows[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.$order.' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);foreach($rows[$table] as $row)foreach(['facts','request','receipt'] as $prefix)if(isset($row[$prefix.'_json'])&&R::hash(R::decode($row[$prefix.'_json']))!==$row[$prefix.'_hash'])throw new \LogicException('Debt clone source hash mismatch.');}
        return $rows;
    }
    /**
     * Odvázaná událost (má storno) drží platbu jen jako zmrazený historický podklad.
     * SecuritiesDebt504Repository::current() ji proti živému dokladu neověřuje, protože
     * účetní smí bankovní doklad po odvázání upravit; klon proto nesmí být přísnější.
     */
    private static function unbound(array $events): array
    {
        $out=[];foreach($events as $event)if($event['reversal_of']!==null)$out[(int)$event['supplier_id']][(int)$event['reversal_of']]=true;return $out;
    }
    private static function date(array $lot,array $events): string
    {
        $date=$lot['acquired_on'];foreach($events as $event)if($event['supplier_id']===$lot['supplier_id']&&$event['lot_id']===$lot['id'])$date=max($date,$event['accounting_date']);return $date;
    }
    private static function text(array $data,TextScrubber $scrubber): array
    {
        foreach($data as $key=>&$value){if($key==='cost_center'&&is_string($value))$value=$scrubber->byStrategy('cost_center',$value);elseif(is_array($value))$value=self::text($value,$scrubber);elseif(is_string($value)&&in_array((string)$key,self::TEXT,true))$value=$scrubber->scrub($value);}unset($value);return $data;
    }
    private static function financial(array $data): array
    {
        foreach($data as $key=>&$value){if(in_array((string)$key,['cost_center','ref'],true))$value=null;elseif(is_array($value))$value=self::financial($value);}unset($value);return $data;
    }
    private static function source(array $payment): array
    {
        $keys=$payment['ledger']['entry']['source_type']==='bank'?['id','statement_id','posted_at','value_date','amount','currency','source','matched_invoice_id','statement_currency','statement_source']:['id','supplier_id','issue_date','total_amount','currency_code','status','doc_type','purpose','invoice_id','purchase_invoice_id','invoice_payment_id','journal_entry_id','reversal_entry_id'];
        return ['source'=>array_intersect_key($payment['source'],array_fill_keys($keys,true)),'amount'=>$payment['amount'],'money_amount'=>$payment['money_amount'],'currency'=>$payment['currency'],'date'=>$payment['date']];
    }
}
