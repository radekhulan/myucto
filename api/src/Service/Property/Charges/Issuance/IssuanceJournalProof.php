<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class IssuanceJournalProof
{
    public function __construct(private readonly Connection $db) {}

    public static function lines(array $charge): array
    {
        $out=[];
        foreach($charge['snapshot']['lines'] as $line) {
            $amount=bcdiv(ltrim($line['amount_cents'],'-'),'100',2);
            $negative=str_starts_with($line['amount_cents'],'-');
            $origins=[];
            foreach($line['dimensions'] as $type=>$value) $origins[$type]=['origin'=>'module','ref'=>'unit_charge:'.$charge['id']];
            foreach([[$line['account']['receivable_account'],$negative?'credit':'debit'],[$line['account']['counter_account'],$negative?'debit':'credit']] as [$code,$side])
                $out[]=['account_code'=>$code,'side'=>$side,'amount'=>$amount,'dimensions'=>$line['dimensions'],'dimension_origins'=>$origins];
        }
        return $out;
    }

    public function assertEntry(int $supplier,int $entryId,array $charge,bool $reversal=false): void
    {
        $this->assertEntries($supplier,[['entry_id'=>$entryId,'charge'=>$charge,'reversal'=>$reversal]]);
    }

    public function assertEntries(int $supplier,array $proofs): void
    {
        if(!$this->db->pdo()->inTransaction()) throw new \LogicException('Issued ledger proof requires current transaction reads.');
        $ids=[];
        foreach($proofs as $proof){$ids[]=$proof['entry_id'];if($proof['reversal'])$ids[]=$proof['charge']['journal_entry_id'];}
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$entries=[];$lines=[];
        foreach(array_chunk($ids,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$this->db->pdo()->prepare('SELECT * FROM journal_entries WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id FOR UPDATE');$stmt->execute([$supplier,...$chunk]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$entries[(int)$row['id']]=$row;
            $stmt=$this->db->pdo()->prepare('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.entry_id IN ('.$marks.') ORDER BY l.entry_id,l.line_no,l.id FOR UPDATE');$stmt->execute([$supplier,...$chunk]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$lines[(int)$row['entry_id']][]=$row;
        }
        $assignments=new DimensionAssignmentRepository($this->db);$lineIds=[];foreach($lines as $entryLines)array_push($lineIds,...array_column($entryLines,'id'));
        $dims=$assignments->lineDimensions($supplier,$lineIds,true);$splits=$assignments->lineSplits($supplier,$lineIds,true);$origins=$assignments->lineOrigins($supplier,$lineIds,true);
        foreach($proofs as $proof){
            $entryId=$proof['entry_id'];$charge=$proof['charge'];$reversal=$proof['reversal'];$entry=$entries[$entryId]??null;
            if(!$entry||$entry['source_type']!=='unit_charge'||$entry['posted_at']===null||(!$reversal&&(int)$entry['source_id']!==$charge['id']))self::fail();
            $expectedReversal=!$reversal&&($charge['status']??null)==='reversed'?($charge['reversal_entry_id']??null):null;
            if(($entry['reversed_by']===null?null:(int)$entry['reversed_by'])!==$expectedReversal||(!$reversal&&$entry['entry_date']!==$charge['accounting_date']))self::fail();
            if($reversal&&($entry['source_id']!==null||(int)($entries[$charge['journal_entry_id']]['reversed_by']??0)!==$entryId))self::fail();
            $actual=$lines[$entryId]??[];$expected=self::lines($charge);
            if(count($actual)!==count($expected))self::fail();
            foreach($actual as $i=>$line){
                $want=$expected[$i];$side=$reversal?($want['side']==='debit'?'credit':'debit'):$want['side'];
                if($line['account_code']!==$want['account_code']||$line['side']!==$side||JournalLineAmount::decimal($line['amount'])!==$want['amount']
                    ||!empty($line['is_red_storno'])||$line['currency_code']!==null||$line['amount_foreign']!==null||$line['fx_rate']!==null
                    ||$line['cost_center']!==null||$line['project_id']!==null
                    ||PropertyInput::hash($dims[$line['id']]??[])!==PropertyInput::hash($want['dimensions'])||($splits[$line['id']]??[])!==[]
                    ||PropertyInput::hash($origins[$line['id']]??[])!==PropertyInput::hash($want['dimension_origins']))self::fail();
            }
        }
    }

    private static function fail(): never
    {
        throw new IssuanceException('property_charge_issue_ledger_mismatch','Deník neodpovídá přesným částkám a dimenzím předpisu; operace byla vrácena.',409);
    }
}
