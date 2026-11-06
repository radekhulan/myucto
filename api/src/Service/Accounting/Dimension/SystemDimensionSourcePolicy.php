<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

final class SystemDimensionSourcePolicy
{
    public const MODULE_SOURCES = ['unit_charge', 'unit_settlement', 'unit_service_accrual', 'unit_credit', 'unit_credit_refund', 'fund_draw'];
    public const BALANCE_TYPES = ['asset', 'liability', 'equity'];

    public static function applies(string $source, string $accountType, string $enforcement): bool
    {
        if ($enforcement !== 'error') {
            return false;
        }
        return !in_array($source, ['closing', 'opening'], true) || in_array($accountType, self::BALANCE_TYPES, true);
    }

    public static function activityRule(int $typeId): array
    {
        return ['id' => 0, 'dimension_type_id' => $typeId, 'mask' => DimensionAccountMask::parse('5,6'),
            'enforcement' => 'error', 'default_value_id' => null, 'default_from_card' => false,
            'valid_from' => null, 'valid_to' => null, 'system_key' => 'activity', 'system_required' => true];
    }
}
