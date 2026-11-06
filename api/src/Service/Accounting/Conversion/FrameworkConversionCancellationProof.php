<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FrameworkConversionRepository as R;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
final class FrameworkConversionCancellationProof
{
    private function __construct(public readonly array $row){}
    public static function current(Connection $db,int $supplier,int $conversion):self
    {
        E::need($db->pdo()->inTransaction(),'conversion_transaction_required');$r=new R($db);$row=$r->receipt($supplier,$conversion,true);E::need($row['status']==='applied','conversion_not_active');
        $source=$r->source($supplier,(int)$row['source_period_id'],(int)$row['target_period_id'],true);E::need(hash_equals($row['source_hash'],$source['source_hash']),'conversion_source_changed');E::need($source['target']['status']==='open','conversion_target_not_open');
        $entries=$r->rows('SELECT id FROM journal_entries WHERE supplier_id=? AND entry_date>=? ORDER BY id',[$supplier,$row['effective_from']],true);E::need(array_map('intval',array_column($entries,'id'))===[(int)$row['opening_entry_id']],'conversion_already_consumed');
        E::need($r->rows('SELECT entry_id FROM journal_line_pairing_items WHERE supplier_id=? AND entry_id=?',[$supplier,$row['opening_entry_id']],true)===[],'conversion_already_consumed');
        $steps=$r->rows("SELECT id FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND status NOT IN ('pending','skipped')",[$supplier,$row['target_period_id']],true);E::need($steps===[],'conversion_already_consumed');
        E::need((new PaymentJournalProof($db))->current($supplier,(int)$row['opening_entry_id'])===$row['receipt']['ledger'],'conversion_opening_changed');
        $after=[];foreach(['chart_of_accounts','accounting_account_definition_history','supplier_accounting_frameworks','dimension_types','dimension_values'] as $table)$after[$table]=$r->rows('SELECT * FROM '.$table.' WHERE supplier_id=? ORDER BY id',[$supplier],true);$after['supplier']=$r->rows('SELECT dimensions_enabled FROM supplier WHERE id=?',[$supplier],true);E::need($after===$row['receipt']['postimage'],'conversion_configuration_consumed');
        return new self($row);
    }
}
