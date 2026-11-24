<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy o tom, kolik vydaná konečná faktura čerpá z které zálohy (proformy).
 *
 * Čerpání nesou záporné odpočtové řádky § 37a konečné faktury (`invoice_type = 'invoice'`):
 * řádek se zápornou částkou mimo slevový. Zálohu, ze které řádek odečítá, určuje
 *   - `invoice_items.advance_invoice_id` (migrace 2298), vyznačený čerpáním záloh v detailu
 *     faktury ({@see \MyInvoice\Service\Invoice\AdvancePartialDrawdown}),
 *   - jinak vazba dokladu `invoices.parent_invoice_id` (vyúčtování vystavené z proformy
 *     a doklady před migrací 2298).
 * Faktura čerpající jedinou zálohu má tu zálohu i v `parent_invoice_id`, takže dosavadní
 * čtení vazby dokladu platí beze změny. Faktura čerpající víc záloh `parent_invoice_id`
 * nemá (jedna vazba by přisoudila celé čerpání jediné záloze) a její čerpání se čte jen
 * z řádků: {@see itemDraws()} pro saldokonto a čerpání po dvojicích, {@see advanceLines()}
 * a {@see finalIds()} pro zaúčtování, služby záloh a hlídání navazujících dokladů.
 *
 * Argumenty jsou SQL výrazy (alias, sloupec, `?` nebo celé číslo), nikdy uživatelský vstup.
 */
final class AdvanceDrawSql
{
    /** Podmínka odpočtového řádku nad aliasem řádku faktury. */
    public static function deductionLine(string $item): string
    {
        self::assertAlias($item);
        return "{$item}.total_with_vat < 0 AND COALESCE({$item}.item_kind, 'standard') <> 'discount'";
    }

    /** Čerpání zálohy z hlavičky a jejích odpočtových řádků v Kč. Bez řádků je 0. */
    public static function parentDrawCzk(string $final, string $rate): string
    {
        self::assertAlias($final);
        $advance = \MyInvoice\Service\Invoice\IssuedCzkAmounts::advancePaidSql($final, "{$final}.advance_paid_amount * {$rate}");
        return "COALESCE((SELECT CASE WHEN -SUM(di.total_without_vat) > 0.005
                                    THEN ROUND({$advance} - SUM(COALESCE(di.import_tax_base_czk, di.total_without_vat * {$rate})), 2)
                                    ELSE 0 END
                            FROM invoice_items di
                           WHERE di.invoice_id = {$final}.id
                             AND COALESCE(di.advance_invoice_id, {$final}.parent_invoice_id) = {$final}.parent_invoice_id
                             AND " . self::deductionLine('di') . '), 0)';
    }

    /**
     * Odpočtové řádky konečných faktur, které čerpají zálohu `$advanceExpr` (odvozená tabulka
     * se sloupci `final_id`, `status`, `exchange_rate`, `currency_id`, `item_id`, `vat_rate_id`,
     * `total_without_vat`, `total_vat`, `total_with_vat`). Faktury všech stavů, filtr stavu
     * je na volajícím. Oba výrazy se vyskytnou dvakrát (vyznačené řádky a vazba dokladu).
     */
    public static function advanceLines(string $supplierExpr, string $advanceExpr): string
    {
        $cols = 'f.id AS final_id, f.status, f.exchange_rate, f.currency_id, ii.id AS item_id, ii.vat_rate_id,
                 ii.total_without_vat, ii.total_vat, ii.total_with_vat, ii.import_tax_base_czk, ii.import_tax_vat_czk';
        return "SELECT {$cols}
                  FROM invoice_items ii
                  JOIN invoices f ON f.id = ii.invoice_id
                 WHERE ii.advance_invoice_id = {$advanceExpr} AND f.supplier_id = {$supplierExpr}
                   AND f.invoice_type = 'invoice' AND " . self::deductionLine('ii') . "
                UNION ALL
                SELECT {$cols}
                  FROM invoices f
                  JOIN invoice_items ii ON ii.invoice_id = f.id
                 WHERE f.supplier_id = {$supplierExpr} AND f.parent_invoice_id = {$advanceExpr}
                   AND f.invoice_type = 'invoice' AND ii.advance_invoice_id IS NULL AND " . self::deductionLine('ii');
    }

    /**
     * Konečné faktury zálohy `$advanceExpr` (odvozená tabulka se sloupcem `id`): vazba dokladu
     * nebo aspoň jeden vyznačený odpočtový řádek. Všechny stavy, filtr stavu je na volajícím.
     * Připojuje se `FROM (…) fx JOIN invoices i ON i.id = fx.id`, ne jako `id IN (…)`: to by
     * MariaDB vyhodnotila jako závislý poddotaz pro každý doklad firmy. Výrazy jdou v pořadí
     * firma, záloha, záloha, firma.
     */
    public static function finalIds(string $supplierExpr, string $advanceExpr): string
    {
        return "SELECT f.id FROM invoices f
                 WHERE f.supplier_id = {$supplierExpr} AND f.parent_invoice_id = {$advanceExpr} AND f.invoice_type = 'invoice'
                UNION
                SELECT ii.invoice_id FROM invoice_items ii
                  JOIN invoices f ON f.id = ii.invoice_id
                 WHERE ii.advance_invoice_id = {$advanceExpr} AND f.supplier_id = {$supplierExpr} AND f.invoice_type = 'invoice'";
    }

    /**
     * Je záloha `$alias` čerpaná vyznačeným odpočtovým řádkem některé konečné faktury
     * (jakéhokoli stavu, shodně s vazbou dokladu v {@see AdvanceSettledSql::proforma()})?
     */
    public static function advanceHasDrawLines(string $alias): string
    {
        self::assertAlias($alias);
        return "EXISTS (SELECT 1 FROM invoice_items adv_ii
                          JOIN invoices adv_f ON adv_f.id = adv_ii.invoice_id
                         WHERE adv_ii.advance_invoice_id = {$alias}.id AND adv_f.supplier_id = {$alias}.supplier_id
                           AND adv_f.invoice_type = 'invoice')";
    }

    /**
     * Čerpání zálohy konečnou fakturou, které nenese vazba dokladu (faktura čerpá víc záloh,
     * nebo zálohu odlišnou od `parent_invoice_id`): odvozená tabulka ve tvaru řádku
     * `invoice_advance_deductions` (`supplier_id`, `final_invoice_id`, `advance_invoice_id`,
     * `amount`). `amount` = Kč, o které faktura snižuje zálohu na zálohovém účtu (324 MD),
     * tedy základ odpočtu po řádcích přepočtený kurzem dokladu se stejným zaokrouhlením jako
     * zaúčtování ({@see \MyInvoice\Service\Accounting\PostingService} zúčtuje každou zálohu
     * jejím základem). Platnost od zaúčtování faktury drží volající stejně jako u řádků
     * čerpání po dvojicích ({@see \MyInvoice\Repository\SaldoRepository::advanceDeductionCountsSql()}).
     *
     * Čerpání vázané hlavičkou dokladu sem nepatří: to saldokonto počítá z vazby dokladu.
     */
    public static function itemDraws(string $supplierExpr): string
    {
        return "SELECT f.supplier_id, ii.invoice_id AS final_invoice_id, ii.advance_invoice_id,
                       SUM(COALESCE(-ii.import_tax_base_czk, ROUND(-ii.total_without_vat * CASE WHEN dcur.code = 'CZK' OR f.exchange_rate IS NULL OR f.exchange_rate <= 0
                                                            THEN 1 ELSE f.exchange_rate END, 2))) AS amount
                  FROM invoice_items ii
                  JOIN invoices f ON f.id = ii.invoice_id
                  JOIN currencies dcur ON dcur.id = f.currency_id
                 WHERE ii.advance_invoice_id IS NOT NULL AND f.supplier_id = {$supplierExpr}
                   AND f.invoice_type = 'invoice' AND ii.advance_invoice_id <> COALESCE(f.parent_invoice_id, 0)
                   AND " . self::deductionLine('ii') . '
                 GROUP BY f.supplier_id, ii.invoice_id, ii.advance_invoice_id';
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
    }
}
