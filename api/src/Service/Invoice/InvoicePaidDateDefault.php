<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Výchozí datum úhrady při „Označit jako zaplacené", když ho volající nezadá.
 *
 * U dokladu z importu souborů je dnešek špatný odhad: historický doklad, který import
 * převzal jako neuhrazený, by dostal úhradu (a v daňové evidenci příjem) v letošním roce.
 * Výchozí je proto pozdější z data vystavení a splatnosti, nejvýš dnešek. Ostatní doklady
 * mají dnešek jako dosud. Stejné pravidlo používá dialog úhrady v detailu faktury.
 */
final class InvoicePaidDateDefault
{
    /** @param array<string,mixed> $invoice řádek dokladu (import_batch_id, issue_date, due_date) */
    public static function forInvoice(array $invoice, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if (empty($invoice['import_batch_id'])) {
            return $today;
        }
        $dates = array_filter([
            substr((string) ($invoice['issue_date'] ?? ''), 0, 10),
            substr((string) ($invoice['due_date'] ?? ''), 0, 10),
        ], static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1);
        return $dates === [] ? $today : min(max($dates), $today);
    }
}
