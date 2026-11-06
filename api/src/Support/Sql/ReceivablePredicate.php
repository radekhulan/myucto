<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Predikát „vystavený doklad po splatnosti, který ještě někdo dluží" nad `invoices`.
 *
 * Pohledávková sémantika je stejná jako u seznamu /invoices?overdue=1: počítají se
 * i nezaplacené NESPÁROVANÉ proformy a vyřazují se finální doklady k zaplacené
 * proformě (`amount_to_pay = 0`). Sdílí ho akce na úvodní stránce
 * ({@see \MyInvoice\Service\Crm\CrmAggregationService::actionItems()}) a seznam firem
 * ({@see \MyInvoice\Service\Supplier\SupplierDirectory}), aby obě místa ukazovala
 * stejné číslo.
 */
final class ReceivablePredicate
{
    /**
     * @param string $alias    alias tabulky `invoices`
     * @param string $operator `<` nebo `<=` z {@see \MyInvoice\Service\Invoice\OverduePolicy::comparisonOperator()}
     * @param string $todaySql SQL výraz s dnešním datem (`CURDATE()` nebo placeholder)
     */
    public static function overdueOpen(string $alias, string $operator, string $todaySql = 'CURDATE()'): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1 || !in_array($operator, ['<', '<='], true)) {
            throw new \InvalidArgumentException('Neplatný alias nebo operátor splatnosti.');
        }

        return "$alias.status IN ('issued', 'sent', 'reminded')
                AND $alias.due_date $operator $todaySql
                AND ($alias.invoice_type != 'proforma'
                     OR NOT EXISTS (SELECT 1 FROM invoices ch
                                     WHERE ch.parent_invoice_id = $alias.id AND ch.invoice_type = 'invoice'))
                AND $alias.invoice_type NOT IN ('rent_calendar','advance_calendar')
                AND ($alias.invoice_type NOT IN ('invoice','proforma','tax_document') OR $alias.amount_to_pay - $alias.paid_total > 0)";
    }
}
