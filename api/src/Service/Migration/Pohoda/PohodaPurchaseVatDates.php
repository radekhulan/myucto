<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Data DPH přijatého dokladu z POHODY v modelu MyÚčta - jediné místo pravidla pro převod
 * ({@see InvoiceImporter}) i opravu dříve převedených dokladů ({@see PohodaDuzpRepair}).
 *
 * POHODA vede u přijatého dokladu (faktura, závazek, záloha, interní doklad) dvě data:
 *
 *  - „Datum KH DPH" (XML `dateKHDPH`, MDB `FA.DatKHDPH`) je DUZP z dokladu dodavatele.
 *    Jde do kontrolního hlášení jako DPPD (B.2) nebo DUZP (B.1, A.2);
 *  - „Datum odp./doruč." (XML `dateTax`, MDB `FA.DatZdPln`) je den, ke kterému účetní
 *    uplatnila odpočet. Určuje období přiznání.
 *
 * MyÚčto nese DUZP v `tax_date` a období odpočtu v `received_at` s `received_at_source =
 * 'manual'` ({@see \MyInvoice\Service\Report\VatLedgerService::purchaseClaimDateExpr()}:
 * období = pozdější z data přijetí, DUZP a vystavení). Ruční datum přijetí se zapisuje jen
 * tehdy, když odpočet leží PO DUZP. Odpočet před DUZP MyÚčto nepřipustí (doklad jde do
 * období DUZP), takže by datum přijetí nic neneslo - a převzatý doklad tak nikdy nemá
 * ruční datum přijetí dřív než DUZP. Podle toho {@see PohodaDuzpRepair} pozná doklad
 * převedený před opravou.
 *
 * Výjimkou je zahraniční přenesení daňové povinnosti: tam evidence DPH řadí doklad přímo
 * podle `tax_date` a datum přijetí ignoruje. Kdyby DUZP padlo do jiného měsíce než datum,
 * ke kterému POHODA daň přiznala, posunulo by samovyměření (ř. 3-6, 12, 13 a odpočet)
 * do jiného období, než jaké POHODA podala, a převzaté přiznání by se rozešlo s podaným
 * i se zápisy na 343 v deníku. Model obě data u takového dokladu neunese, přednost proto
 * má období: `tax_date` je datum odpočtu z POHODY a KH (A.2) ponese toto datum místo DUZP.
 * Ve stejném měsíci se období nemění, `tax_date` je tedy DUZP jako u ostatních dokladů.
 */
final class PohodaPurchaseVatDates
{
    /**
     * `tax` = `tax_date` (null = chybí obě data, volající doplní datum vystavení),
     * `received` = ruční datum přijetí, nebo null (výchozí datum přijetí převodu),
     * `kept_deduction` = zahraniční přenesení, u kterého `tax_date` drží období POHODY
     * místo DUZP.
     *
     * @return array{tax:?string,received:?string,kept_deduction:bool}
     */
    public static function fromPohoda(?string $duzp, ?string $deduction, bool $claimFollowsTaxDate): array
    {
        if ($duzp === null || $deduction === null) {
            return ['tax' => $duzp ?? $deduction, 'received' => null, 'kept_deduction' => false];
        }
        if ($claimFollowsTaxDate && !self::sameMonth($duzp, $deduction)) {
            return ['tax' => $deduction, 'received' => null, 'kept_deduction' => true];
        }
        return ['tax' => $duzp, 'received' => $deduction > $duzp ? $deduction : null, 'kept_deduction' => false];
    }

    public static function sameMonth(string $a, string $b): bool
    {
        return substr($a, 0, 7) === substr($b, 0, 7);
    }
}
