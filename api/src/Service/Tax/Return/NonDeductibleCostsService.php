<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Σ daňově neuznatelných nákladů (§25 ZDP) z deníku za období — jeden zdroj pravdy sdílený
 * {@see DppoReturnDataProvider} (§7 DPPO ř. 40) i {@see DpfoReturnDataProvider} (§7 DPFO,
 * Fáze E nález N1: FO s podvojným účetnictvím musí nedaňové náklady přičíst zpět k základu,
 * stejně jako PO — jinak podá systematicky podhodnocený §7 základ).
 *
 * Nedaňovost nese buď účet (`chart_of_accounts.tax_deductibility='non_deductible'` — 513,
 * 543, 545…), nebo hlavička přijaté faktury (`tax_deductible=0`). Jsou to dvě nezávislé
 * osy: starší nebo ručně účtovaná nedaňová služba může věcně zůstat na daňovém účtu 518,
 * ale pro DPFO/DPPO se přičte zpět. Podmínka je OR, takže nedaňový doklad na nedaňovém
 * účtu se nezapočítá dvakrát.
 *
 * Vylučuje JEN close_books zápis (source_id < STOCK_SLOT_BASE) a 59x (daň z příjmů je z VH
 * vyloučena, přidávat ji by ji do základu započetlo dvakrát); slotovaná skladová manka §3.4
 * na neuznatelném 549 (source_id >= STOCK_SLOT_BASE) se POČÍTAJÍ.
 */
final class NonDeductibleCostsService
{
    public function __construct(private readonly Connection $db) {}

    public function sum(int $supplierId, string $startsOn, string $endsOn): float
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0) AS c
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN tax_journal_origins tax_origin ON tax_origin.id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
          LEFT JOIN purchase_invoices pi ON tax_origin.source_type = 'purchase_invoice'
                                         AND pi.id = tax_origin.source_id
                                         AND pi.supplier_id = e.supplier_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND a.account_type = 'expense'
                AND a.account_code NOT LIKE '59%'
                AND (a.tax_deductibility = 'non_deductible' OR COALESCE(pi.tax_deductible, 1) = 0)"
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
