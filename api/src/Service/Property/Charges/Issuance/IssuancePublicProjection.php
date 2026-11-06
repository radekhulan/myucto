<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

final class IssuancePublicProjection
{
    public static function charge(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'id', 'batch_id', 'unit_id', 'revision_no', 'previous_charge_id', 'title', 'issue_mode',
            'period_from', 'period_to', 'accounting_date', 'due_date', 'status', 'row_version',
            'journal_entry_id', 'reversal_entry_id', 'amount_cents',
        ])) + ['currency' => 'CZK', 'lines' => array_map(static fn (array $line): array => [
            'component_id' => $line['component_id'], 'service_id' => $line['service_id'],
            'amount_cents' => $line['amount_cents'],
            'receivable_account' => $line['account']['receivable_account'],
            'counter_account' => $line['account']['counter_account'],
            'dimensions' => (object) $line['dimensions'],
        ], $row['snapshot']['lines'])];
    }
}
