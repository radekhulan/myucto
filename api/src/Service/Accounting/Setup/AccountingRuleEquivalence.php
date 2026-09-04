<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

final class AccountingRuleEquivalence
{
    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public static function expense(array $left, array $right): bool
    {
        return self::nullableInt($left['vendor_client_id'] ?? null) === self::nullableInt($right['vendor_client_id'] ?? null)
            && self::text($left['vendor_name_contains'] ?? null) === self::text($right['vendor_name_contains'] ?? null)
            && self::text($left['description_contains'] ?? null) === self::text($right['description_contains'] ?? null)
            && self::rangeCovers($left, $right)
            && trim((string) ($left['expense_kind'] ?? '')) === trim((string) ($right['expense_kind'] ?? ''))
            && self::plain($left['target_account_code'] ?? null) === self::plain($right['target_account_code'] ?? null)
            && (bool) ($left['recurring_prepaid'] ?? false) === (bool) ($right['recurring_prepaid'] ?? false);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public static function bank(array $left, array $right): bool
    {
        return trim((string) ($left['direction'] ?? '')) === trim((string) ($right['direction'] ?? ''))
            && AccountNumberNormalizer::canonical((string) ($left['counterparty_account'] ?? ''))
                === AccountNumberNormalizer::canonical((string) ($right['counterparty_account'] ?? ''))
            && AccountNumberNormalizer::canonicalBankCode((string) ($left['counterparty_bank'] ?? ''))
                === AccountNumberNormalizer::canonicalBankCode((string) ($right['counterparty_bank'] ?? ''))
            && self::variableSymbol($left['variable_symbol'] ?? null) === self::variableSymbol($right['variable_symbol'] ?? null)
            && self::text($left['message_contains'] ?? null) === self::text($right['message_contains'] ?? null)
            && self::rangeCovers($left, $right)
            && self::plain($left['debit_account_code'] ?? null) === self::plain($right['debit_account_code'] ?? null)
            && self::plain($left['credit_account_code'] ?? null) === self::plain($right['credit_account_code'] ?? null)
            && self::operationType($left['operation_type'] ?? null) === self::operationType($right['operation_type'] ?? null)
            && strtoupper((string) ($left['applies_currency'] ?? 'CZK')) === strtoupper((string) ($right['applies_currency'] ?? 'CZK'))
            && self::text($left['counterparty_prefix'] ?? null) === self::text($right['counterparty_prefix'] ?? null);
    }

    private static function text(mixed $value): ?string
    {
        $normalized = BankMessageNormalizer::normalizeKeepDigits((string) $value);
        return $normalized === '' ? null : $normalized;
    }

    private static function plain(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private static function amount(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format(abs((float) $value), 4, '.', '');
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $proposal */
    private static function rangeCovers(array $existing, array $proposal): bool
    {
        $existingMin = self::amount($existing['amount_min'] ?? null);
        $existingMax = self::amount($existing['amount_max'] ?? null);
        $proposalMin = self::amount($proposal['amount_min'] ?? null);
        $proposalMax = self::amount($proposal['amount_max'] ?? null);
        return ($existingMin === null || ($proposalMin !== null && (float) $existingMin <= (float) $proposalMin))
            && ($existingMax === null || ($proposalMax !== null && (float) $existingMax >= (float) $proposalMax));
    }

    private static function operationType(mixed $value): string
    {
        return self::plain($value) ?? OperationType::BANK_RULE_CUSTOM;
    }

    private static function variableSymbol(mixed $value): ?string
    {
        $value = VariableSymbolNormalizer::digits((string) $value);
        return $value === '' ? null : $value;
    }
}
