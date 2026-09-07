<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

final class AuthoritativeTransactionReconciler
{
    public function __construct(private readonly PDO $pdo) {}

    public function candidates(array $transactions, int $supplierId, string $account, string $bank, string $currency, string $source): array
    {
        if ($transactions === [] || $supplierId <= 0 || !in_array($source, ['gpc', 'bank_api'], true)) return [];
        $accountKey = self::account($account, $bank);
        if ($accountKey === null || $currency === '') return [];
        $dates = array_column($transactions, 'posted_at');
        $query = $this->pdo->prepare(
            "SELECT bt.*, bs.source AS statement_source, bs.account_number AS own_account, bs.bank_code AS own_bank
             FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
             WHERE bs.supplier_id = ? AND bs.source IN ('gpc', 'bank_api')
               AND bs.currency = ? AND COALESCE(NULLIF(bt.currency, ''), bs.currency) = ? AND bt.posted_at BETWEEN ? AND ?"
        );
        $query->execute([$supplierId, $currency, $currency, min($dates), max($dates)]);
        $stored = array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool =>
            self::account((string) $row['own_account'], (string) $row['own_bank']) === $accountKey
        ));
        $byDateAmount = [];
        foreach ($stored as $row) {
            $byDateAmount[self::dateAmount($row)][] = $row;
        }
        $matches = [];
        $reverse = [];
        $strong = [];
        foreach ($transactions as $index => $tx) {
            $possible = array_values(array_filter($byDateAmount[self::dateAmount($tx)] ?? [], static fn (array $row): bool =>
                $row['statement_source'] !== $source && self::compatible($tx, $row)
            ));
            $exact = array_values(array_filter($possible, static fn (array $row): bool =>
                self::reference($tx) !== '' && self::reference($tx) === self::reference($row)
            ));
            foreach ($exact !== [] ? $exact : $possible as $row) {
                $id = (int) $row['id'];
                $matches[$index][] = $id;
                $reverse[$id][] = $index;
                $strong[$index][$id] = self::strong($tx, $row);
            }
        }
        $result = [];
        foreach ($matches as $index => $ids) {
            if (count($ids) !== 1 || count($reverse[$ids[0]]) !== 1 || !$strong[$index][$ids[0]]) {
                throw new \InvalidArgumentException('Výpis obsahuje nejednoznačnou shodu s dříve načtenými pohyby API/GPC. Import nebyl uložen; pohyby je nutné nejprve zkontrolovat.');
            }
            $result[$index] = $ids[0];
        }
        return $result;
    }

    public static function account(string $number, string $bank): ?string
    {
        $number = strtoupper((string) preg_replace('/\s+/', '', $number));
        if (preg_match('/^(?:CZ|SK)\d{2}(\d{4})(\d{16})$/D', $number, $m) === 1) {
            if ($bank !== '' && $bank !== $m[1]) return null;
            $bank = $m[1];
            $number = $m[2];
        }
        if (preg_match('#^(.+)/(\d{4})$#D', $number, $m) === 1) {
            if ($bank !== '' && $bank !== $m[2]) return null;
            $number = $m[1];
            $bank = $m[2];
        }
        if (preg_match('/^\d{4}$/D', $bank) !== 1 || $bank === '0000') return null;
        if (preg_match('/^(\d{1,6})-(\d{1,10})$/D', $number, $m) === 1) {
            $number = str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
        } elseif (preg_match('/^(?:\d{1,10}|\d{16})$/D', $number) === 1) {
            $number = str_pad($number, 16, '0', STR_PAD_LEFT);
        } else {
            return null;
        }
        return trim($number, '0') === '' ? null : $bank . ':' . $number;
    }

    private static function compatible(array $a, array $b): bool
    {
        if ($a['posted_at'] !== $b['posted_at']
            || number_format((float) $a['amount'], 2, '.', '') !== number_format((float) $b['amount'], 2, '.', '')) return false;
        foreach (['variable_symbol', 'constant_symbol', 'specific_symbol'] as $key) {
            $left = ltrim(trim((string) ($a[$key] ?? '')), '0');
            $right = ltrim(trim((string) ($b[$key] ?? '')), '0');
            if ($left !== '' && $right !== '' && $left !== $right) return false;
        }
        $accountA = self::account((string) ($a['counterparty_account'] ?? ''), (string) ($a['counterparty_bank'] ?? ''));
        $accountB = self::account((string) ($b['counterparty_account'] ?? ''), (string) ($b['counterparty_bank'] ?? ''));
        if ($accountA !== null && $accountB !== null) return $accountA === $accountB;
        return true;
    }

    private static function dateAmount(array $tx): string
    {
        return $tx['posted_at'] . ':' . number_format((float) $tx['amount'], 2, '.', '');
    }

    private static function strong(array $a, array $b): bool
    {
        $refA = self::reference($a);
        $refB = self::reference($b);
        if ($refA !== '' && $refA === $refB) return true;
        return false;
    }

    private static function reference(array $tx): string
    {
        $reference = trim((string) ($tx['bank_ref'] ?? ''));
        return ctype_digit($reference) ? ltrim($reference, '0') : $reference;
    }
}
