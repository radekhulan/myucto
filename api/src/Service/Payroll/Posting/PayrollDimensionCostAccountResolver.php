<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;

/**
 * Jediné rozhodovací pravidlo pro výchozí nákladový účet zmrazených mzdových
 * dimenzí. Pracuje výhradně se snapshotem revize; do živého číselníku nikdy
 * nesahá, takže pozdější změna dimenze nemůže přepsat schválené období.
 */
final class PayrollDimensionCostAccountResolver
{
    /** @var list<string> */
    private const PRIORITY = ['cost_center', 'project', 'activity'];

    /** @param array<string,mixed> $employmentSnapshot */
    public function resolve(array $employmentSnapshot): ?string
    {
        $dimensions = $employmentSnapshot['dimensions'] ?? null;
        if (!is_array($dimensions) || !array_is_list($dimensions)) {
            return null;
        }

        $byType = [];
        foreach ($dimensions as $index => $dimension) {
            if (!is_array($dimension) || array_is_list($dimension)) {
                throw new \DomainException(
                    "Dimenze employment.dimensions.{$index} není objekt.",
                );
            }
            $account = $dimension['default_account_code'] ?? null;
            if ($account === null || $account === '') {
                continue;
            }
            if (!is_string($account) || !PayrollAccountCode::isValid($account)) {
                throw new \DomainException(
                    "Účet employment.dimensions.{$index}.default_account_code není platný.",
                );
            }
            PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous($account);
            $type = $dimension['type'] ?? null;
            if (is_string($type)) {
                $byType[$type] ??= $account;
            }
        }

        foreach (self::PRIORITY as $type) {
            if (isset($byType[$type])) {
                return $byType[$type];
            }
        }

        return null;
    }
}
