<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Service\Accounting\AccountingFrameworkService;

final class JointAllocationSourceEligibility
{
    public static function assert(array $line, string $targetDate, string $framework): void
    {
        if ($line['posted_at'] === null || $line['reversed_by'] !== null || in_array($line['source_type'], ['closing', 'opening'], true)
            || !in_array($line['account_type'], ['expense', 'revenue'], true)) {
            throw new JointAllocationException('allocation_source_ineligible', 'Alokovat lze účinný zaúčtovaný výsledkový řádek mimo uzávěrku a otevření.', 409);
        }
        if ($targetDate < $line['source_date']) {
            throw new JointAllocationException('allocation_backdating', 'Přeúčtování nesmí předcházet zdrojovému účetnímu případu.', 409);
        }
        if ($framework !== AccountingFrameworkService::CZ_504) {
            throw new JointAllocationException('allocation_framework_unsupported', 'Zdrojový řádek musí být zaúčtován v rámci 504.');
        }
        if (!empty($line['claim_batch_id']) || !empty($line['allocation_batch_id']) || !empty($line['cancellation_id'])) {
            throw new JointAllocationException('allocation_source_in_use', 'Řádek již patří k neměnné alokaci.', 409);
        }
        if (bccomp($line['amount'], '0', 2) === 0 && bccomp($line['amount_foreign'] ?? '0', '0', 2) === 0) {
            throw new JointAllocationException('allocation_empty_source', 'Zdrojový řádek nemá částku v účetní ani cizí měně.');
        }
    }
}
