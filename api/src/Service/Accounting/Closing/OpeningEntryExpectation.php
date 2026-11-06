<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountingRoleProfile;

final class OpeningEntryExpectation
{
    public static function fromClosingLines(array $lines, float $profit, ?AccountingRoleProfile $profile = null): array
    {
        $profile ??= new AccountingRoleProfile();
        $bs = [];
        $pl = [];
        foreach ($lines as $line) {
            $balance = [
                'account_id' => (int) $line['account_id'],
                'account_code' => (string) $line['account_code'],
                'name' => (string) ($line['account_name'] ?? ''),
                'bal' => $line['side'] === 'credit' ? (float) $line['amount'] : -(float) $line['amount'],
            ] + array_intersect_key($line, ['dimensions' => true, 'dimension_splits' => true, 'cost_center' => true, 'project_id' => true]);
            if (in_array((string) $line['account_type'], ['revenue', 'expense'], true)) {
                $pl[] = $balance;
            } elseif (in_array((string) $line['account_type'], ['asset', 'liability', 'equity'], true)
                && ($profile->framework !== AccountingFrameworkService::CZ_504 || !$profile->isTransientAccount($balance['account_code']))) {
                if ($profile->framework === AccountingFrameworkService::CZ_504) {
                    $balance += array_intersect_key($line, ['currency_code' => true, 'amount_foreign' => true, 'fx_rate' => true]);
                }
                $bs[] = $balance;
            }
        }
        return (new ClosingEntryBuilder())->openingLines($bs, $profit, $profile, ClosingEntryBuilder::resultBalances($pl));
    }
}
