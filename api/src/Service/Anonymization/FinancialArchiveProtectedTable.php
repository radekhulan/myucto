<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Tabulky, jejichž řádky zachycené jako finanční archiv DPPO 504 nesmí projít
 * obecnou pseudonymizací: přepisuje je typově {@see NonprofitDppoArchiveCloneAdapter}.
 */
enum FinancialArchiveProtectedTable: string
{
    case SNAPSHOT = 'income_tax_return_snapshots';
    case TAX_RETURN = 'income_tax_returns';
    case SUBMISSION = 'tax_submissions';
}
