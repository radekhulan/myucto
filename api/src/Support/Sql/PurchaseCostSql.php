<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro náklad přijatého dokladu sčítaný z hlavičky (přehledy nákladů,
 * CRM, souhrny, daňový profil, sestavy).
 *
 * Konečná faktura, která odečítá poskytnutou zálohu s daňovým dokladem k platbě (DDKP)
 * označeným odpočtovým řádkem ({@see \MyInvoice\Service\PurchaseInvoice\PurchaseAdvanceDeduction}),
 * má v hlavičce celkovou částku už po odečtu zálohy. Odpočtový řádek ale nákladem není,
 * stejně jako DDKP, který se z nákladů vyřazuje vždy: náklad je plnění, které faktura
 * vyúčtovává. Náklad dokladu = hlavička MINUS součet odpočtových řádků zálohy
 * (u syntetického případu −69,88 − (−735,54) = 665,66). Zaúčtování to dělá stejně
 * ({@see \MyInvoice\Service\Accounting\PostingService::purchaseAdvanceDeduction()}).
 *
 * Odpočtové řádky se předagregují po dokladu v odvozené tabulce omezené firmou, takže se
 * k přehledu připojují LEFT JOINem bez korelovaného poddotazu na každý doklad. Odvozená
 * tabulka vychází z konečných faktur firmy s vyplněnou zálohou (`idx_pi_supplier_advance`)
 * a řádky odpočtu dohledá po `ix_pii_advance_purchase_invoice`. Index řádků firmu nezná,
 * takže jako první tabulka by dotaz procházel odpočtové řádky všech firem.
 *
 * Použití: `FROM purchase_invoices pi ` . deductionJoin('pi', $supplierId) a v SELECT
 * {@see cost()}, {@see net()} nebo {@see gross()} místo `pi.total_without_vat` /
 * `pi.total_with_vat`. Hlídá to Architecture\PurchaseCostSqlGuardTest.
 */
final class PurchaseCostSql
{
    /**
     * LEFT JOIN odpočtů zálohy dokladu `$doc` (alias odvozené tabulky `{$doc}_ded`).
     * Bez firmy (`null`) jde odvozená tabulka jen po indexu označených řádků; volající,
     * který firmu zná, ji má předat.
     */
    public static function deductionJoin(string $doc, ?int $supplierId): string
    {
        self::assertAlias($doc);
        return " LEFT JOIN (SELECT ded_i.purchase_invoice_id,
                                   SUM(ded_i.total_without_vat) AS base, SUM(ded_i.total_vat) AS vat,
                                   SUM(ded_i.total_with_vat) AS gross
                              " . self::deductionRowsFrom($supplierId) . "
                             GROUP BY ded_i.purchase_invoice_id) {$doc}_ded ON {$doc}_ded.purchase_invoice_id = {$doc}.id ";
    }

    /**
     * {@see deductionJoin()} pro dotaz na horké cestě (hlavičky měsíců seznamu): firma bez
     * jediného odpočtového řádku dostane místo odvozené tabulky konstantní řádek s nulami,
     * takže se doklady nedohledávají v odpočtech ani po indexu. Rozhodne jeden dotaz nad
     * konečnými fakturami firmy se zálohou, který skončí prvním odpočtovým řádkem.
     */
    public static function deductionJoinFor(\PDO $pdo, string $doc, ?int $supplierId): string
    {
        self::assertAlias($doc);
        $stmt = $pdo->prepare('SELECT 1 ' . self::deductionRowsFrom($supplierId) . ' LIMIT 1');
        $stmt->execute();
        if ($stmt->fetchColumn() !== false) {
            return self::deductionJoin($doc, $supplierId);
        }
        return " CROSS JOIN (SELECT 0 AS base, 0 AS vat, 0 AS gross) {$doc}_ded ";
    }

    /** Náklad bez DPH: základ hlavičky bez odpočtu zálohy. */
    public static function net(string $doc): string
    {
        self::assertAlias($doc);
        return "({$doc}.total_without_vat - COALESCE({$doc}_ded.base, 0))";
    }

    /** Náklad s DPH (neplátce): celková částka hlavičky bez odpočtu zálohy. */
    public static function gross(string $doc): string
    {
        self::assertAlias($doc);
        return "({$doc}.total_with_vat - COALESCE({$doc}_ded.gross, 0))";
    }

    /** DPH hlavičky bez daně odpočtu zálohy (pro výpočty, které z brutta odečítají DPH). */
    public static function vat(string $doc): string
    {
        self::assertAlias($doc);
        return "({$doc}.total_vat - COALESCE({$doc}_ded.vat, 0))";
    }

    /** Náklad podle plátcovství: plátce bez DPH, neplátce s DPH. */
    public static function cost(string $doc, bool $isVatPayer): string
    {
        return $isVatPayer ? self::net($doc) : self::gross($doc);
    }

    /**
     * FROM a WHERE odpočtových řádků zálohy (`ded_i`) na konečných fakturách (`ded_f`).
     * S firmou vychází z jejích konečných faktur s vyplněnou zálohou a STRAIGHT_JOIN pořadí
     * drží i při statistikách, podle kterých by optimalizátor začal řádky všech firem.
     */
    private static function deductionRowsFrom(?int $supplierId): string
    {
        if ($supplierId === null) {
            return 'FROM purchase_invoice_items ded_i
                    JOIN purchase_invoices ded_f ON ded_f.id = ded_i.purchase_invoice_id
                   WHERE ded_i.advance_purchase_invoice_id IS NOT NULL
                     AND ded_i.advance_purchase_invoice_id = ded_f.advance_purchase_invoice_id
                     AND ded_i.total_with_vat < 0';
        }

        return "FROM purchase_invoices ded_f
                STRAIGHT_JOIN purchase_invoice_items ded_i
                     ON ded_i.advance_purchase_invoice_id = ded_f.advance_purchase_invoice_id
                    AND ded_i.purchase_invoice_id = ded_f.id
               WHERE ded_f.supplier_id = {$supplierId} AND ded_f.advance_purchase_invoice_id IS NOT NULL
                 AND ded_i.total_with_vat < 0";
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
    }
}
