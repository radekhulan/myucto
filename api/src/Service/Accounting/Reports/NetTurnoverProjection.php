<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class NetTurnoverProjection
{
    public function __construct(private readonly StatementMapper $mapper) {}

    public static function fromStatement(array $statement): array
    {
        $result = self::fromStatementDecimal($statement);
        if ($result['finding'] === null) {
            $result['amount'] = JournalLineAmount::cents($result['amount']) / 100;
        }
        return $result;
    }

    public static function fromStatementDecimal(array $statement): array
    {
        if (!is_array($statement['checks'] ?? []) || !is_array($statement['rows'] ?? [])) {
            return ['amount' => null, 'finding' => 'net_turnover_invalid'];
        }
        $amounts = [];
        if (array_key_exists('net_turnover', $statement['checks'] ?? [])) {
            $amounts[] = $statement['checks']['net_turnover'];
        }
        $turnoverRows = 0;
        foreach ($statement['rows'] ?? [] as $row) {
            if (($row['row_code'] ?? null) === 'OBRAT') {
                $amounts[] = $row['amount'] ?? null;
                $turnoverRows++;
            }
        }
        if ($amounts === []) {
            return ['amount' => null, 'finding' => 'net_turnover_missing'];
        }
        if ($turnoverRows > 1) {
            return ['amount' => null, 'finding' => 'net_turnover_conflict'];
        }
        $cents = [];
        foreach ($amounts as $amount) {
            if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
                return ['amount' => null, 'finding' => 'net_turnover_invalid'];
            }
            try {
                $cents[] = JournalLineAmount::cents($amount);
            } catch (\InvalidArgumentException) {
                return ['amount' => null, 'finding' => 'net_turnover_invalid'];
            }
        }
        if (count(array_unique($cents)) !== 1) {
            return ['amount' => null, 'finding' => 'net_turnover_conflict'];
        }
        return ['amount' => bcdiv((string) $cents[0], '100', 2), 'finding' => null];
    }

    public function project(array $rows, array $map, array $balances, string $type, string $startsOn, array $selected = []): array
    {
        $included = NetTurnoverPolicy::directRows($rows, $type, $startsOn, $selected);
        $members = [];
        $findings = [];
        $total = 0;
        foreach ($balances as $balance) {
            $signed = JournalLineAmount::cents($balance['d']) - JournalLineAmount::cents($balance['md']);
            if ($signed === 0) {
                continue;
            }
            $mapped = $this->mapper->map($rows, $map, [$balance]);
            $amounts = [];
            foreach ($mapped as $code => $values) {
                if (!isset($included[$code])) {
                    continue;
                }
                foreach ($values['accounts'] as $account) {
                    $cents = JournalLineAmount::cents($account['amount']);
                    $amounts[$account['target'] === 'correction' ? -$cents : $cents] = true;
                }
            }
            if ($balance['account_type'] === 'revenue' && ($mapped === [] || (!NetTurnoverPolicy::isBusinessModel($startsOn) && $amounts === []))) {
                $findings[] = ['code' => 'unmapped_revenue', 'account_id' => (int) $balance['account_id'], 'account_code' => $balance['code']];
            }
            if ($amounts === []) {
                continue;
            }
            $cents = array_sum(array_keys($amounts));
            if (count($amounts) !== 1 || $balance['account_type'] !== 'revenue' || $cents !== $signed) {
                $findings[] = ['code' => 'ambiguous_revenue', 'account_id' => (int) $balance['account_id'], 'account_code' => $balance['code']];
            }
            $member = $balance['account_id'] . ':' . $balance['code'] . ':' . ($signed > 0 ? 'credit' : 'debit');
            $members[$member] = ($members[$member] ?? 0) + $cents;
            $total += $cents;
        }
        ksort($members, SORT_STRING);
        return ['amount' => $total / 100, 'members' => $members, 'findings' => $findings];
    }
}
