<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankTransactionPostingScope
{
    public static function sourceSql(string $entryAlias, string $transactionIdSql): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $entryAlias)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D', $transactionIdSql)
        ) {
            throw new \InvalidArgumentException('Neplatný alias účetní vazby banky.');
        }
        return "(($entryAlias.source_type = 'bank' AND $entryAlias.source_id = $transactionIdSql)
            OR ($entryAlias.source_type = 'payroll_payment' AND EXISTS (
                SELECT 1 FROM payroll_payment_matches bank_payroll_match
                 WHERE bank_payroll_match.supplier_id = $entryAlias.supplier_id
                   AND bank_payroll_match.id = $entryAlias.source_id
                   AND bank_payroll_match.bank_transaction_id = $transactionIdSql
            ) AND " . self::payrollFullyPostedSql($entryAlias . '.supplier_id', $transactionIdSql) . "))";
    }

    public static function payrollFullyPostedSql(string $supplierIdSql, string $transactionIdSql): string
    {
        foreach ([$supplierIdSql, $transactionIdSql] as $reference) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D', $reference)) {
                throw new \InvalidArgumentException('Neplatný alias účetní vazby banky.');
            }
        }
        return "(COALESCE((
            SELECT SUM(ABS(coverage_match.amount_minor))
              FROM payroll_payment_matches coverage_match
              JOIN journal_entries coverage_entry
                ON coverage_entry.supplier_id = coverage_match.supplier_id
               AND coverage_entry.source_type = 'payroll_payment'
               AND coverage_entry.source_id = coverage_match.id
               AND coverage_entry.reversed_by IS NULL AND coverage_entry.posted_at IS NOT NULL
             WHERE coverage_match.supplier_id = $supplierIdSql
               AND coverage_match.bank_transaction_id = $transactionIdSql
        ), 0) = (
            SELECT ROUND(ABS(coverage_transaction.amount) * 100)
              FROM bank_transactions coverage_transaction WHERE coverage_transaction.id = $transactionIdSql
        ))";
    }
}
