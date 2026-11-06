<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro „záloha je vyúčtovaná konečnou fakturou" mimo saldokonto.
 *
 * Zálohu vyúčtuje konečná faktura dvěma cestami a obě musí platit všude, kde se záloha
 * vyřazuje z pohledávek, nákladů a nabídek k párování:
 *   - vazbou na dokladu: přijatá konečná faktura nese `advance_purchase_invoice_id`,
 *     vydaná `parent_invoice_id` na proformu,
 *   - čerpáním po dvojicích záloha × faktura (`purchase_advance_deductions` /
 *     `invoice_advance_deductions`), které zapisuje převod z účetního programu nebo
 *     akce Vyrovnat v saldokontu. Tahle cesta vazbu na dokladu nenastavuje, takže bez
 *     ní by vyúčtovaná záloha dál vypadala jako nevyúčtovaná: nezaplacená proforma
 *     v pohledávkách, zaplacená zálohová faktura v nákladech vedle konečné faktury
 *     (náklad dvakrát) a záloha v nabídce k propojení, které by čerpání zdvojilo.
 *
 * Saldokonto počítá čerpání po částkách ({@see \MyInvoice\Repository\SaldoRepository}),
 * tady jde jen o příznak „vyúčtovaná": stejně jako vazba na dokladu ho dává i částečné čerpání.
 *
 * Argumentem je alias zálohy ve vnějším dotazu (identifikátor, nikdy uživatelský vstup).
 */
final class AdvanceSettledSql
{
    /** Přijatá záloha (zálohová faktura nebo samostatný DDKP) `$alias` je vyúčtovaná. */
    public static function purchase(string $alias): string
    {
        self::assertAlias($alias);
        return "(EXISTS (SELECT 1 FROM purchase_invoices adv_fin
                          WHERE adv_fin.supplier_id = {$alias}.supplier_id AND adv_fin.advance_purchase_invoice_id = {$alias}.id)
                 OR EXISTS (SELECT 1 FROM purchase_advance_deductions adv_ded
                             WHERE adv_ded.supplier_id = {$alias}.supplier_id AND adv_ded.advance_invoice_id = {$alias}.id))";
    }

    /** Vydaná proforma `$alias` je vyúčtovaná konečnou fakturou. */
    public static function proforma(string $alias): string
    {
        self::assertAlias($alias);
        return "(EXISTS (SELECT 1 FROM invoices adv_fin
                          WHERE adv_fin.supplier_id = {$alias}.supplier_id AND adv_fin.parent_invoice_id = {$alias}.id
                            AND adv_fin.invoice_type = 'invoice')
                 OR EXISTS (SELECT 1 FROM invoice_advance_deductions adv_ded
                             WHERE adv_ded.supplier_id = {$alias}.supplier_id AND adv_ded.advance_invoice_id = {$alias}.id))";
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias zálohy.');
        }
    }
}
