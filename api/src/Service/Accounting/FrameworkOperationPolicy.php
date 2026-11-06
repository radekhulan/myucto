<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class FrameworkOperationPolicy
{
    public static function allows(AccountingRoleProfile $profile, string $operation, array $context = []): bool
    {
        if(in_array($operation,['svj.common_part.repair','svj.common_part.improvement','svj.asset.repair','svj.asset.improvement'],true)) {
            if($profile->framework!==AccountingFrameworkService::CZ_504||!is_string($context['evidence_ref']??null)||trim($context['evidence_ref'])==='')return false;
            return str_starts_with($operation,'svj.common_part.')?($context['ownership']??null)==='owners_common_parts':in_array($context['ownership']??null,['svj_owned','documented_other_right'],true);
        }
        if (!in_array($operation, ['profit_distribution', 'deferred_tax', 'reserve.other', 'reserve.income_tax', 'reserve.legal', 'provision.accounting', 'provision.legal'], true)) {
            throw new \InvalidArgumentException('Unknown framework operation: ' . $operation);
        }
        if ($profile->framework === AccountingFrameworkService::CZ_500) {
            return true;
        }
        if ($operation === 'profit_distribution') {
            foreach (['decision_reference', 'decision_authority'] as $key) {
                if (!is_string($context[$key] ?? null) || trim($context[$key]) === '' || mb_strlen($context[$key]) > 500) return false;
            }
            return true;
        }
        if ($operation === 'deferred_tax') {
            try { return \MyInvoice\Service\Accounting\Closing\DeferredTax504Builder::assessment($context)['enabled']; }
            catch (\MyInvoice\Service\Accounting\Closing\ClosingException) { return false; }
        }
        return in_array($operation, ['reserve.legal', 'provision.legal'], true)
            && ($context['taxable_activity'] ?? null) === true
            && is_string($context['legal_basis'] ?? null)
            && trim($context['legal_basis']) !== '';
    }

    public static function assertAllowed(AccountingRoleProfile $profile, string $operation, array $context = []): void
    {
        if (!self::allows($profile, $operation, $context)) {
            throw new AccountingFrameworkException('accounting_framework_operation_not_supported', 'Tato operace není v daném účetním rámci podporována.', 422);
        }
    }
}
