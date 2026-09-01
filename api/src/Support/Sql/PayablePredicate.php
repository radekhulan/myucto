<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro predikát „co je nezaplacený ZÁVAZEK" nad `purchase_invoices`.
 *
 * Daňový doklad k poskytnuté záloze (DDKP, § 28 ZDPH) závazek na 321 nemá: peníze
 * odešly už na zálohové faktuře a doklad účtuje jen odpočet DPH (343/314). Jeho
 * `amount_to_pay` je ale GENERATED sloupec `total_with_vat − advance_paid_amount`,
 * takže nese PLNÉ BRUTTO už zaplacené zálohy. Ve stavu `received`/`booked` by se
 * proto vykázal jako závazek v plné výši, který nikdo nedluží — a v příkazu k úhradě
 * by z toho byla druhá platba témuž dodavateli (N-008).
 *
 * ZÁLOHA (`advance`) se tu naopak NEVYLUČUJE: nezaplacená zálohová faktura JE reálný
 * závazek. Tím se tenhle predikát liší od nákladového (`advanceCostExclude`) a proto
 * je samostatný — rozdíl hlídá {@see \MyInvoice\Tests\Architecture\AdvanceCostPredicateParityTest}.
 *
 * Vzniklo ve fázi F2: pravidlo žilo v šesti kopiích a čtyři závazkové dotazy
 * v `CrmAggregationService` ho neměly vůbec. Kopie vznikaly proto, že predikát nešlo
 * zavolat — byl to `private` helper uvnitř jedné akce. Volatelný SSOT je obrana,
 * kterou komentář „viz PurchaseSummaryAction" nikdy nebyl.
 */
final class PayablePredicate
{
    /** Druh dokladu, který nikdy nezakládá závazek na 321. */
    public const NON_PAYABLE_DOCUMENT_KIND = 'tax_document';

    /**
     * Fragment `AND …` vylučující DDKP ze závazkových sum, seznamů i cashflow.
     *
     * @param string $alias alias tabulky `purchase_invoices`; prázdný řetězec = bez aliasu
     */
    public static function excludeAdvanceVatDocument(string $alias = 'pi'): string
    {
        return ' AND ' . self::advanceVatDocumentCondition($alias);
    }

    /**
     * Totéž bez vedoucího `AND` — pro dotazy, které si klauzule skládají do pole.
     */
    public static function advanceVatDocumentCondition(string $alias = 'pi'): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return sprintf("COALESCE(%sdocument_kind, '') <> '%s'", $prefix, self::NON_PAYABLE_DOCUMENT_KIND);
    }

    /** Zbytek závazku po odečtení banky, vzájemného zápočtu a zápočtu proti účtu. */
    public static function remainingExpression(string $alias = 'pi'): string
    {
        return PurchaseSettledExpr::remaining($alias);
    }

    /** Podmínka, že na přijaté faktuře zbývá k úhradě více než půl haléře. */
    public static function outstandingBalanceCondition(string $alias = 'pi'): string
    {
        return '(' . self::remainingExpression($alias) . ') > 0.005';
    }

    /** SQL fragment vylučující plně vyrovnané přijaté faktury. */
    public static function excludeFullySettled(string $alias = 'pi'): string
    {
        return ' AND ' . self::outstandingBalanceCondition($alias);
    }
}
