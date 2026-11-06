<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final readonly class AccountingRoleProfile
{
    public function __construct(public string $framework = AccountingFrameworkService::CZ_500)
    {
        if (!in_array($framework, [AccountingFrameworkService::CZ_500, AccountingFrameworkService::CZ_504], true)) {
            throw new \InvalidArgumentException('Unknown accounting framework.');
        }
    }

    public function account(string $role, ?string $legacy500Account = null): string
    {
        $accounts = $this->accounts();
        $default = $accounts[$role] ?? throw new \InvalidArgumentException('Unknown account role: ' . $role);
        return $this->framework === AccountingFrameworkService::CZ_500 && $legacy500Account
            ? $legacy500Account
            : $default;
    }

    public function accounts(): array
    {
        return $this->framework === AccountingFrameworkService::CZ_504
            ? ['closing.opening_balance' => '961', 'closing.final_balance' => '962', 'result.current' => '933', 'result.pending_approval' => '931', 'result.retained_profit' => '932', 'result.retained_loss' => '932',
                'result.funds' => '911', 'result.own_equity' => '901',
                'deferred_tax.expense' => '592', 'deferred_tax.liability' => '959.100', 'deferred_tax.asset' => '349',
                'reserve.legal.expense' => '556', 'reserve.legal.balance' => '941', 'provision.legal.expense' => '559', 'provision.legal.balance' => '391',
                'fx.loss' => '545', 'fx.gain' => '645', 'interest.expense' => '544', 'interest.income' => '644', 'bank.fee' => '549.100', 'rounding.loss' => '549', 'rounding.gain' => '649', 'svj.advance.long_term'=>'955.100','svj.advance.admin'=>'324.200','svj.common_part.repair'=>'549.900','svj.common_part.improvement'=>'549.910','svj.management.cost'=>'518.200','svj.coverage.long_term'=>'649.100','svj.coverage.admin'=>'649.200','svj.owner_common_part_income'=>'379.920',
                'nonprofit.membership_revenue' => '684']
            : ['closing.opening_balance' => '701', 'closing.final_balance' => '702', 'result.current' => '710', 'result.pending_approval' => '431', 'result.retained_profit' => '428', 'result.retained_loss' => '429',
                'deferred_tax.expense' => '592', 'deferred_tax.liability' => '481', 'deferred_tax.asset' => '481',
                'reserve.legal.expense' => '552', 'reserve.legal.balance' => '451', 'provision.legal.expense' => '558', 'provision.legal.balance' => '391',
                'fx.loss' => '563', 'fx.gain' => '663', 'interest.expense' => '562', 'interest.income' => '662', 'bank.fee' => '568', 'rounding.loss' => '548', 'rounding.gain' => '648'];
    }

    public function configuredAccount(string $role, ?int $accountId, ?string $legacy500Account = null): string
    {
        $account = $this->account($role, $legacy500Account);
        if ($this->framework === AccountingFrameworkService::CZ_504 && $accountId !== null) {
            throw new AccountingFrameworkException('accounting_framework_account_binding_required', 'Vlastní účet nemá ověřenou vazbu na účetní rámec.');
        }
        return $account;
    }

    public function openingAccount(string $code): string
    {
        return $this->framework === AccountingFrameworkService::CZ_504 && str_starts_with($code, $this->account('result.retained_profit'))
            ? $this->account('result.pending_approval')
            : $code;
    }

    public function isClosingAccount(string $code): bool
    {
        return str_starts_with($code, $this->framework === AccountingFrameworkService::CZ_504 ? '96' : '70');
    }

    public function isTransientAccount(string $code): bool
    {
        return $this->isClosingAccount($code) || str_starts_with($code, $this->account('result.current'));
    }

    public function assertCompatibleOpening(self $next): void
    {
        if ($this->framework !== $next->framework) {
            throw new AccountingFrameworkException('accounting_framework_transition_not_supported', 'Převod počátečních stavů mezi účetními rámci zatím není podporován.', 409);
        }
    }
}
