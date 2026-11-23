<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro predikát „vydaný doklad k vyplacení už byl zákazníkovi PROPLACEN".
 *
 * Doklad k vyplacení ({@see \MyInvoice\Service\Invoice\RefundDocument}: dobropis nebo
 * faktura se zápornou částkou k úhradě) se nikdy neobjeví v `invoice_payments`, protože
 * peníze tečou opačným směrem. Vrácení peněz se proto eviduje výhradně stavem dokladu:
 * {@see \MyInvoice\Service\Invoice\InvoicePaymentService::markRefunded()}
 * nastaví `status='paid'` + `paid_at` (volá ji ruční „označit vrácené", bankovní párování
 * i GoPay vyúčtování, které k dobropisu spáruje pohyb `storno`).
 *
 * Saldokonto dovozuje uhrazenost vydaných dokladů z `invoice_payments`, takže bez
 * tohohle predikátu zůstal proplacený doklad navždy otevřenou zápornou položkou
 * (a konfrontace se zůstatkem hlavní knihy vykázala rozdíl v jeho výši, přestože
 * deník má obě strany — předpis i vratku — vyrovnané).
 *
 * Záporná zálohová faktura (vrácení zálohy, typicky převod z programu, který zálohu stornuje
 * zápornou proformou) je doklad k vyplacení stejně jako záporná faktura.
 *
 * NEproplacený doklad otevřenou položkou ZŮSTÁVÁ: v saldu partnera se svým
 * záporným znaménkem správně odečítá od neuhrazených faktur, dokud se buď
 * nezapočte, nebo nevrátí v penězích.
 *
 * Faktura s `parent_invoice_id` (finál k proformě, přeplatek zálohy) sem nepatří: označí
 * se jako zaplacená už při vystavení, peníze tím vrácené nejsou a její uhrazenost dál
 * počítá poměr plateb a záloh.
 */
final class CreditNoteRefundExpr
{
    /**
     * SQL predikát „doklad k vyplacení je k rozvahovému dni proplacený". Obsahuje JEDEN
     * placeholder pro `asOf` (v `SaldoRepository` jsou všechny placeholdery asOf,
     * takže se parametry nedají prohodit).
     *
     * @param string $alias alias tabulky `invoices`; prázdný řetězec = bez aliasu
     */
    public static function refundedAsOfSql(string $alias = 'i'): string
    {
        $p = $alias === '' ? '' : $alias . '.';

        return "(({$p}invoice_type = 'credit_note'"
            . " OR ({$p}invoice_type IN ('invoice', 'proforma') AND {$p}amount_to_pay < 0 AND {$p}parent_invoice_id IS NULL))"
            . " AND {$p}status = 'paid'"
            . " AND {$p}paid_at IS NOT NULL AND DATE({$p}paid_at) <= ?)";
    }

    /** PHP protějšek {@see refundedAsOfSql()} nad řádkem s `invoice_type`/`status`/`paid_at`. */
    public static function isRefundedAsOf(
        string $invoiceType,
        string $status,
        ?string $paidAt,
        string $asOf,
        float $amountToPay = 0.0,
        ?int $parentInvoiceId = null,
    ): bool {
        $refundDocument = $invoiceType === 'credit_note'
            || (in_array($invoiceType, ['invoice', 'proforma'], true) && round($amountToPay, 2) < 0 && $parentInvoiceId === null);

        return $refundDocument
            && $status === 'paid'
            && $paidAt !== null
            && substr($paidAt, 0, 10) <= $asOf;
    }
}
