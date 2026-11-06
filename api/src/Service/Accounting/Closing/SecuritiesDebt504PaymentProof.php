<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Bank\BankStatementSource;
use PDO;

final class SecuritiesDebt504PaymentProof
{
    public function __construct(private readonly Connection $db,private readonly bool $currentRead=true) {}

    private function query(string $sql): \PDOStatement
    {
        if(!$this->currentRead)$sql=str_replace(' FOR UPDATE','',$sql);
        return $this->db->pdo()->prepare($sql);
    }

    public function current(int $supplier,int $entryId,int $lineId,array $asset,?string $account=null): array
    {
        $ledger=(new PaymentJournalProof($this->db))->snapshot($supplier,$entryId,$this->currentRead);
        $entry=$ledger['entry']; $kind=$entry['source_type']; $source=(int)$entry['source_id'];
        SecuritiesDebt504Terms::need(in_array($kind,['bank','cash'],true) && $source>0 && $entry['reversed_by']===null,'debt_native_payment_required');
        if ($kind==='bank') {
            $q=$this->query('SELECT b.*,bs.currency statement_currency,bs.source statement_source FROM bank_transactions b JOIN bank_statements bs ON bs.id=b.statement_id WHERE b.id=? AND '.BankStatementOwnershipResolver::sql('bs',true).' FOR UPDATE');
            $q->execute([$source,...BankStatementOwnershipResolver::params($supplier)]); $row=$q->fetch(PDO::FETCH_ASSOC);
            SecuritiesDebt504Terms::need($row!==false && BankStatementSource::isStatement($row['statement_source']) && ($row['source']??'statement')==='statement' && $row['matched_invoice_id']===null,'debt_native_payment_required');
            foreach (['payment_matches','invoice_payments','payroll_payment_matches'] as $table) {
                $q=$this->query('SELECT id FROM '.$table.' WHERE bank_transaction_id=? LIMIT 1 FOR UPDATE');$q->execute([$source]);
                SecuritiesDebt504Terms::need($q->fetchColumn()===false,'debt_payment_owned_elsewhere');
            }
            $date=$row['posted_at'];$amount=JournalLineAmount::decimal($row['amount']);$currency=$row['currency']?:$row['statement_currency'];
        } else {
            $q=$this->query('SELECT * FROM cash_documents WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$source]);$row=$q->fetch(PDO::FETCH_ASSOC);
            SecuritiesDebt504Terms::need($row!==false && $row['status']==='posted' && $row['doc_type']==='in' && $row['purpose']==='other' && $row['invoice_id']===null && $row['purchase_invoice_id']===null && $row['invoice_payment_id']===null && $row['reversal_entry_id']===null && (int)$row['journal_entry_id']===$entryId,'debt_native_payment_required');
            $date=$row['issue_date'];$amount=JournalLineAmount::decimal($row['total_amount']);$currency=$row['currency_code'];
        }
        SecuritiesDebt504Terms::need($entry['entry_date']===$date && bccomp($amount,'0',2)>0 && $currency===($asset['currency_code']??'CZK'),'debt_payment_source_changed');
        $money='0.00';$selected=null;
        foreach ($ledger['lines'] as $line) {
            SecuritiesDebt504Terms::need(!$line['is_red_storno'],'debt_payment_source_changed');
            if (str_starts_with($line['account_code'],$kind==='bank'?'221':'211')) {
                SecuritiesDebt504Terms::need($line['side']==='debit','debt_payment_source_changed');
                $money=bcadd($money,$currency==='CZK'?$line['amount']:($line['amount_foreign']??'0'),2);
            }
            if ($line['id']===$lineId) $selected=$line;
        }
        SecuritiesDebt504Terms::need($money===$amount && $selected!==null && $selected['side']==='credit' && $selected['account_code']===($account??$asset['account_code']),'debt_payment_source_changed');
        foreach (['dimensions','dimension_splits','cost_center','project_id','currency_code'] as $key)
            SecuritiesDebt504Terms::need(($selected[$key]??null)===($asset[$key]??null),'debt_tuple_changed');
        $value=$currency==='CZK'?$selected['amount']:($selected['amount_foreign']??'0.00');
        SecuritiesDebt504Terms::need(bccomp($value,'0',2)>0,'debt_payment_source_changed');
        return ['ledger'=>$ledger,'source'=>$row,'line'=>$selected,'amount'=>$value,'money_amount'=>$amount,'currency'=>$currency,'date'=>$date];
    }
}