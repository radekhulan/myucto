<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

final class JournalTaxOrigin
{
    public static function cte(int $supplierId): string
    {
        return self::fromRoots($supplierId, "SELECT e.id, e.source_type, e.source_id, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = {$supplierId}
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entries parent
                    WHERE parent.supplier_id = {$supplierId} AND parent.reversed_by = e.id
               )");
    }

    public static function provisionsBeforeCte(int $supplierId): string
    {
        return self::fromRoots($supplierId, "SELECT e.id, e.source_type, e.source_id, e.reversed_by
              FROM journal_entries e
             WHERE e.supplier_id = {$supplierId} AND e.source_type = 'provision'
               AND e.source_id IS NOT NULL AND e.entry_date < ?");
    }

    private static function fromRoots(int $supplierId, string $roots): string
    {
        return "tax_journal_origins AS (
            {$roots}
            UNION ALL
            SELECT reversal.id, origin.source_type, origin.source_id, reversal.reversed_by
              FROM tax_journal_origins origin
              JOIN journal_entries reversal ON reversal.id = origin.reversed_by
             WHERE reversal.supplier_id = {$supplierId}
        )";
    }

    public static function includedSql(): string
    {
        return "(tax_origin.source_type <> 'closing' OR tax_origin.source_id >= ?)";
    }
}
