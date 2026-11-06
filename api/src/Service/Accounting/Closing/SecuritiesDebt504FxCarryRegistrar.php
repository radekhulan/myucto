<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Closing;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{SecuritiesDebt504Repository as R,Fx504CaseReadRepository};
use MyInvoice\Service\Accounting\{AccountingRoleProfile,JournalLineAmount};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;
final class SecuritiesDebt504FxCarryRegistrar
{
    public function __construct(private readonly Connection $db) {}
    public function beginRefresh(int $supplier,int $period): void
    {
        (new SecuritiesDebt504UseGuard($this->db))->assertFxPeriodMutable($supplier,$period);
        $q=$this->db->pdo()->prepare('SELECT DISTINCT c.lot_id,p.ends_on FROM accounting_securities_debt_fx_carries c JOIN accounting_periods p ON p.supplier_id=c.supplier_id AND p.id=c.period_id WHERE c.supplier_id=? AND c.period_id=? FOR UPDATE');$q->execute([$supplier,$period]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)(new R($this->db))->current($supplier,(int)$row['lot_id'],$row['ends_on']);
        $this->db->pdo()->prepare("DELETE c FROM accounting_securities_debt_claims c JOIN journal_entry_lines l ON l.supplier_id=c.supplier_id AND l.id=c.line_id JOIN accounting_securities_debt_fx_carries f ON f.supplier_id=c.supplier_id AND f.lot_id=c.lot_id AND f.journal_entry_id=l.entry_id WHERE c.supplier_id=? AND f.period_id=? AND c.component='fx_carry'")->execute([$supplier,$period]);
        $this->db->pdo()->prepare('DELETE FROM accounting_securities_debt_fx_carries WHERE supplier_id=? AND period_id=?')->execute([$supplier,$period]);
    }
    public function synchronize(int $supplier,int $period): void
    {
        (new R($this->db))->transaction($supplier,function()use($supplier,$period){
            $q=$this->db->pdo()->prepare("SELECT s.*,p.ends_on FROM accounting_closing_steps s JOIN accounting_periods p ON p.supplier_id=s.supplier_id AND p.id=s.period_id WHERE s.supplier_id=? AND s.period_id=? AND s.step_key='fx_revaluation' FOR UPDATE");$q->execute([$supplier,$period]);$step=$q->fetch(PDO::FETCH_ASSOC);
            SecuritiesDebt504Terms::need($step!==false && $step['status']==='done','debt_fx_carry_unproven');$payload=R::decode($step['payload']);$record=$payload['fx504']??null;
            SecuritiesDebt504Terms::need(is_array($record) && ($record['case_projection_version']??null)===1 && hash('sha256',json_encode($record['case_materialization'],JSON_THROW_ON_ERROR))===($record['case_materialization_hash']??null),'debt_fx_carry_unproven');
            $inventory=(new R($this->db))->closingProof($supplier,$step['ends_on']);$saved=$record['securities_assessment']['debt_lifecycle']['lots']??[];
            foreach($inventory['lots'] as $lot){
                if($lot['currency']==='CZK')continue;$found=array_values(array_filter($saved,fn($r)=>$r['id']===$lot['id']));SecuritiesDebt504Terms::need(count($found)===1 && $found[0]===$lot,'debt_fx_basis_changed');
                $treatments=array_values(array_filter($record['securities_assessment']['debt_treatments']??[],fn($t)=>($t['lot_id']??null)===$lot['id']));
                SecuritiesDebt504Terms::need(count($treatments)===1 && is_bool($treatments[0]['fair_value']??null),'debt_fx_carry_unproven');$fair=$treatments[0]['fair_value'];
                $book=$native=$interestBook=$interestNative='0.00';$lineIds=[];
                foreach($lot['lines'] as $id=>$part){$b=$fair?$part['interest_czk']:bcadd($part['principal_czk'],$part['interest_czk'],2);$n=$fair?$part['interest_native']:bcadd($part['principal_native'],$part['interest_native'],2);$book=bcadd($book,$b,2);$native=bcadd($native,$n,2);$interestBook=bcadd($interestBook,$part['interest_czk'],2);$interestNative=bcadd($interestNative,$part['interest_native'],2);if($b!=='0.00'||$n!=='0.00')$lineIds[]=(int)$id;}
                if($lineIds===[]||($book==='0.00'&&$native==='0.00'))continue;sort($lineIds,SORT_NUMERIC);
                $positions=array_values(array_filter($payload['preview']['positions']??[],fn($p)=>($p['case_origin']??null)===$lot['origin']));
                SecuritiesDebt504Terms::need(count($positions)===1,'debt_fx_carry_unproven');$position=$positions[0];$ids=$position['source_line_ids'];sort($ids,SORT_NUMERIC);
                SecuritiesDebt504Terms::need($ids===$lineIds && $position['booked_czk']===$book && $position['foreign_balance']===$native && $position['slot']==='saldo' && $position['finding']===null && is_string($position['rate']),'debt_fx_basis_changed');
                $delta=bcsub(JournalLineAmount::decimal(bcmul($native,$position['rate'],16)),$book,2);if($delta==='0.00')continue;
                $ledger=$this->ledger($supplier,$period,$record,'saldo',$step['ends_on']);$parts=[];$total='0.00';
                foreach($record['case_materialization']['saldo'] as $index=>$proof){
                    if(($proof['origin']??null)!==$lot['origin'])continue;$line=$ledger['lines'][$index]??null;$contra=$ledger['lines'][$index+1]??null;$expected=$proof['line'];
                    SecuritiesDebt504Terms::need($line!==null && $contra!==null && Fx504CaseReadRepository::same($line,$expected) && bccomp($line['fx_rate'],$position['rate'],6)===0 && $line['currency_code']===$lot['currency'] && $line['amount_foreign']==='0.00' && !$line['is_red_storno'],'debt_fx_carry_changed');
                    $sign=$line['side']==='debit'?'1':'-1';$change=bcmul($line['amount'],$sign,2);$role=(new AccountingRoleProfile('cz_504'))->account(bccomp($change,'0',2)>0?'fx.gain':'fx.loss');
                    SecuritiesDebt504Terms::need($contra['account_code']===$role && $contra['side']!==$line['side'] && $contra['amount']===$line['amount'] && ($contra['currency_code']??null)===null && !$contra['is_red_storno'] && array_diff_assoc($line['dimensions'],$contra['dimensions'])===[],'debt_fx_carry_changed');
                    $parts[$line['id']]=['principal_czk'=>$change,'principal_native'=>'0.00','interest_czk'=>'0.00','interest_native'=>'0.00'];$total=bcadd($total,$change,2);
                }
                SecuritiesDebt504Terms::need($parts!==[] && $total===$delta,'debt_fx_carry_changed');
                $interestDelta=bcsub(JournalLineAmount::decimal(bcmul($interestNative,$position['rate'],16)),$interestBook,2);$principalDelta=bcsub($delta,$interestDelta,2);SecuritiesDebt504Terms::need(!$fair||$principalDelta==='0.00','debt_fx_carry_changed');
                $first=array_key_first($parts);$parts[$first]['interest_czk']=$interestDelta;$parts[$first]['principal_czk']=bcsub($parts[$first]['principal_czk'],$interestDelta,2);
                $receipt=['lot_id'=>$lot['id'],'period_id'=>$period,'slot'=>'saldo','accounting_date'=>$step['ends_on'],'basis'=>['terms'=>$lot['terms'],'lines'=>$lot['lines']],'fair'=>$fair,'rate'=>$position['rate'],'origin'=>$lot['origin'],'ledger'=>$ledger,'lines'=>$parts,'principal_czk'=>$principalDelta,'interest_czk'=>$interestDelta];$this->write($supplier,$receipt);
                if(($record['slots']['reversal']['status']??null)==='written' && ($record['slots']['reversal']['entry_id']??null)!==null){
                    $q=$this->db->pdo()->prepare('SELECT reversed_by FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$record['slots']['reversal']['entry_id']]);if($q->fetchColumn()!==null)continue;
                    $reverseDate=(new \DateTimeImmutable($step['ends_on']))->modify('+1 day')->format('Y-m-d');$mirror=$this->ledger($supplier,$period,$record,'reversal',$reverseDate);$reverseParts=[];
                    foreach($parts as $id=>$part){$index=array_search($id,array_column($ledger['lines'],'id'),true);$line=$mirror['lines'][$index]??null;SecuritiesDebt504Terms::need($line!==null && Fx504CaseReadRepository::same($line,JournalLineAmount::reversal($ledger['lines'][$index])),'debt_fx_carry_changed');foreach(['principal_czk','interest_czk'] as $field)$part[$field]=bcsub('0',$part[$field],2);$reverseParts[$line['id']]=$part;}
                    $this->write($supplier,array_replace($receipt,['slot'=>'reversal','accounting_date'=>$reverseDate,'ledger'=>$mirror,'lines'=>$reverseParts,'principal_czk'=>bcsub('0',$principalDelta,2),'interest_czk'=>bcsub('0',$interestDelta,2)]));
                }
            }
        });
    }
    private function ledger(int $supplier,int $period,array $record,string $slot,string $date): array
    {
        $id=$record['slots'][$slot]['entry_id']??null;SecuritiesDebt504Terms::need(is_int($id)&&$id>0 && $record['slots'][$slot]['status']==='written','debt_fx_carry_unproven');$ledger=(new PaymentJournalProof($this->db))->current($supplier,$id);
        SecuritiesDebt504Terms::need($ledger['entry']['source_type']==='fx_revaluation' && $ledger['entry']['source_id']===($slot==='saldo'?ClosingSourceId::fxSaldo($period):ClosingSourceId::fxReversal($period)) && $ledger['entry']['entry_date']===$date && $ledger['entry']['reversed_by']===null,'debt_fx_carry_changed');return $ledger;
    }
    private function write(int $supplier,array $receipt): void
    {
        $q=$this->db->pdo()->prepare('SELECT receipt_json FROM accounting_securities_debt_fx_carries WHERE supplier_id=? AND lot_id=? AND period_id=? AND slot=? FOR UPDATE');$q->execute([$supplier,$receipt['lot_id'],$receipt['period_id'],$receipt['slot']]);$old=$q->fetchColumn();if($old!==false){SecuritiesDebt504Terms::need(R::decode($old)===$receipt,'debt_fx_refresh_required');return;}
        $byId=array_column($receipt['ledger']['lines'],null,'id');foreach($receipt['lines'] as $id=>$part){(new R($this->db))->available($supplier,(int)$id,'fx_carry');$this->db->pdo()->prepare("INSERT INTO accounting_securities_debt_claims(supplier_id,lot_id,event_id,line_id,component,amount_cents) VALUES(?,?,NULL,?,'fx_carry',?)")->execute([$supplier,$receipt['lot_id'],$id,bcmul($byId[$id]['amount'],'100',0)]);}
        $this->db->pdo()->prepare('INSERT INTO accounting_securities_debt_fx_carries(supplier_id,lot_id,period_id,slot,journal_entry_id,accounting_date,receipt_json,receipt_hash) VALUES(?,?,?,?,?,?,?,?)')->execute([$supplier,$receipt['lot_id'],$receipt['period_id'],$receipt['slot'],$receipt['ledger']['entry']['id'],$receipt['accounting_date'],R::json($receipt),R::hash($receipt)]);
    }
}
