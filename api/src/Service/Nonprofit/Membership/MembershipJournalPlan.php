<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class MembershipJournalPlan
{
    public static function issue(string $receivableAccount, string $revenueAccount, string $amount, array $classification): array
    {
        $receivableAccount = self::account($receivableAccount);
        $revenueAccount = self::account($revenueAccount);
        if ($receivableAccount === $revenueAccount) {
            throw new MembershipException('nonprofit_membership_accounts_equal', 'Účet pohledávky a výnosu musí být odlišný.');
        }
        $decimal = JournalLineAmount::decimal($amount);
        if (JournalLineAmount::cents($decimal) <= 0) {
            throw new MembershipException('nonprofit_membership_amount_invalid', 'Členský příspěvek musí být kladný.');
        }
        $line = MembershipClassification::line($classification);
        return [
            ['account_code' => $receivableAccount, 'side' => 'debit', 'amount' => $decimal] + $line,
            ['account_code' => $revenueAccount, 'side' => 'credit', 'amount' => $decimal] + $line,
        ];
    }

    public static function reverse(array $issue): array
    {
        if (count($issue) !== 2 || ($issue[0]['side'] ?? null) !== 'debit' || ($issue[1]['side'] ?? null) !== 'credit'
            || ($issue[0]['amount'] ?? null) !== ($issue[1]['amount'] ?? null)) {
            throw new MembershipException('nonprofit_membership_journal_plan_invalid', 'Účetní plán členského příspěvku není přesný.');
        }
        return [
            array_replace($issue[0], ['side' => 'credit']),
            array_replace($issue[1], ['side' => 'debit']),
        ];
    }

    private static function account(string $account): string
    {
        $account = trim($account);
        if (!preg_match('/^[0-9]{3}(?:[.][0-9A-Za-z]{1,7})?$/D', $account)) {
            throw new MembershipException('nonprofit_membership_account_invalid', 'Vyberte platný účet z účtové osnovy.');
        }
        return $account;
    }
}
