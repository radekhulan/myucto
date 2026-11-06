<?php

declare(strict_types=1);
namespace MyInvoice\Repository;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\{ClosingSourceId,SecuritiesDebt504Terms as T};
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use PDO;
final class SecuritiesDebt504FxCarryRepository
{
    public function __construct(private readonly Connection $db,private readonly bool $currentRead=true) {}
    public function read(int $supplier,int $lot): array
    {
        $q=$this->db->pdo()->prepare('SELECT c.*,p.ends_on FROM accounting_securities_debt_fx_carries c JOIN accounting_periods p ON p.supplier_id=c.supplier_id AND p.id=c.period_id WHERE c.supplier_id=? AND c.lot_id=? ORDER BY c.accounting_date,c.period_id,c.slot'.($this->currentRead?' FOR UPDATE':''));$q->execute([$supplier,$lot]);$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $r=SecuritiesDebt504Repository::decode($row['receipt_json']);
            T::need(SecuritiesDebt504Repository::hash($r)===$row['receipt_hash'] && $r['lot_id']===$lot && $r['period_id']===(int)$row['period_id'] && $r['slot']===$row['slot'] && $r['accounting_date']===$row['accounting_date'],'debt_fx_carry_corrupt');
            $ledger=(new PaymentJournalProof($this->db))->snapshot($supplier,(int)$row['journal_entry_id'],$this->currentRead);
            T::need($ledger===$r['ledger'] && $ledger['entry']['source_type']==='fx_revaluation' && $ledger['entry']['source_id']===($row['slot']==='saldo'?ClosingSourceId::fxSaldo((int)$row['period_id']):ClosingSourceId::fxReversal((int)$row['period_id'])) && $ledger['entry']['entry_date']===$row['accounting_date'] && $ledger['entry']['reversed_by']===null,'debt_fx_carry_changed');
            $actual=array_column($ledger['lines'],null,'id');$total='0.00';$principal=$interest='0.00';
            foreach($r['lines'] as $id=>$part){$line=$actual[$id]??null;T::need($line!==null && ($line['amount_foreign']??null)==='0.00','debt_fx_carry_corrupt');$value=bcmul($line['amount'],$line['side']==='debit'?'1':'-1',2);T::need(bcadd($part['principal_czk'],$part['interest_czk'],2)===$value && $part['principal_native']==='0.00' && $part['interest_native']==='0.00','debt_fx_carry_corrupt');$total=bcadd($total,$value,2);$principal=bcadd($principal,$part['principal_czk'],2);$interest=bcadd($interest,$part['interest_czk'],2);}
            T::need($principal===$r['principal_czk'] && $interest===$r['interest_czk'] && bcadd($principal,$interest,2)===$total,'debt_fx_carry_corrupt');
            if($row['slot']==='saldo'){
                $book=$native=$interestBook=$interestNative='0.00';foreach($r['basis']['lines'] as $part){$book=bcadd($book,$r['fair']?$part['interest_czk']:bcadd($part['principal_czk'],$part['interest_czk'],2),2);$native=bcadd($native,$r['fair']?$part['interest_native']:bcadd($part['principal_native'],$part['interest_native'],2),2);$interestBook=bcadd($interestBook,$part['interest_czk'],2);$interestNative=bcadd($interestNative,$part['interest_native'],2);}
                $target=JournalLineAmount::decimal(bcmul($native,$r['rate'],16));$interestTarget=JournalLineAmount::decimal(bcmul($interestNative,$r['rate'],16));
                T::need($total===bcsub($target,$book,2) && $interest===bcsub($interestTarget,$interestBook,2) && (!$r['fair']||$principal==='0.00'),'debt_fx_carry_corrupt');
            }
            $out[]=$r;
        }
        return $out;
    }
}
