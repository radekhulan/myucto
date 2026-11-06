<?php

declare(strict_types=1);

namespace MyInvoice\Support\Spreadsheet;

/**
 * Nahraný sešit nelze bezpečně načíst: není to podporovaný formát, je po rozbalení
 * příliš velký nebo má víc řádků či sloupců, než volající připouští.
 *
 * reason: 'unreadable' | 'too_large' | 'too_many_rows' | 'too_many_columns'
 */
final class SpreadsheetLimitException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
