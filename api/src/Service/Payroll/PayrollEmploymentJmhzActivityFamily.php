<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

final class PayrollEmploymentJmhzActivityFamily
{
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    public static function appliesTo(string $relationType): bool
    {
        return in_array($relationType, ['employment', 'small_scale_employment', 'dpc', 'dpp'], true);
    }

    public static function matches(
        string $relationType,
        string $activityCode,
        ?string $relationshipDetailCode,
    ): bool {
        return match ($relationType) {
            'employment', 'small_scale_employment' => preg_match('/^[1-9]$/D', $activityCode) === 1
                && $relationshipDetailCode === '1',
            'dpc' => preg_match('/^[A-J]$/D', $activityCode) === 1
                && $relationshipDetailCode === null,
            'dpp' => in_array($activityCode, self::DPP_ACTIVITY_CODES, true)
                && $relationshipDetailCode === null,
            default => false,
        };
    }
}
