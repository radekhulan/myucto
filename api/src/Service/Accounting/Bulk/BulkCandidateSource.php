<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

/**
 * Operace, která si záznamy vybírá sama (filtr nad řádky deníku u přeúčtování), místo
 * záznamů výběru ({@see BulkSelection::rows()}).
 */
interface BulkCandidateSource
{
    /** @return list<array<string,mixed>> nejvýš {@see BulkJobRunner::LIMIT} + 1 záznamů */
    public function candidates(BulkContext $ctx): array;
}
