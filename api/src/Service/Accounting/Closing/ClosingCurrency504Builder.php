<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class ClosingCurrency504Builder
{
    public static function balances(array $rows): array
    {
        $groups = [];
        $accounts = array_column($rows, null, 'account_code');
        foreach ($rows as $row) $groups[$row['currency_code'] ?? 'CZK'][] = $row;
        ksort($groups);
        $result = [];
        foreach ($groups as $currency => $members) {
            $local = array_column(ProfitDistribution504Builder::cells($members), null, 'key');
            $foreign = [];
            if ($currency !== 'CZK') {
                $fxRows = [];
                foreach ($members as $row) {
                    if ($row['amount_foreign'] === null) throw self::incomplete();
                    $row['signed_amount'] = JournalLineAmount::signedCents(['amount' => $row['amount_foreign'], 'is_red_storno' => $row['is_red_storno'] ?? false]) / 100;
                    $fxRows[] = $row;
                }
                $foreign = array_column(ProfitDistribution504Builder::cells($fxRows), null, 'key');
                if (array_diff_key($foreign, $local) !== []) throw self::incomplete();
            }
            foreach ($local as $key => $cell) {
                $cents = -$cell['available_cents'];
                $fxCents = -($foreign[$key]['available_cents'] ?? 0);
                if ($fxCents !== 0 && (($fxCents < 0) !== ($cents < 0))) throw self::incomplete();
                $balance = ['account_id' => (int) $accounts[$cell['account_code']]['account_id'],
                    'account_code' => $cell['account_code'], 'name' => $accounts[$cell['account_code']]['account_name'], 'bal' => $cents / 100]
                    + array_intersect_key($cell, ['dimensions' => true, 'cost_center' => true, 'project_id' => true]);
                if ($currency !== 'CZK') $balance += ['currency_code' => $currency, 'amount_foreign' => abs($fxCents) / 100, 'fx_rate' => null];
                $result[] = $balance;
            }
        }
        return $result;
    }

    private static function incomplete(): ClosingException
    {
        return new ClosingException('closing_currency_incomplete', 'Cizoměnový zůstatek nemá úplný souhlasný korunový a měnový podklad pro přenos do dalšího období.', 422);
    }
}
