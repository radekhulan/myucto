<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

final class AuthoritativeTransactionReconciler
{
    public function __construct(private readonly PDO $pdo) {}

    public function candidates(array $transactions, int $supplierId, string $account, string $bank, string $currency, string $source, array $confirmations = [], array $fingerprints = []): array
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
        $byId = [];
        foreach ($stored as $row) {
            $byDateAmount[self::dateAmount($row)][] = $row;
            $byId[(int) $row['id']] = $row;
        }
        $known = [];
        if ($fingerprints !== []) {
            $alias = $this->pdo->prepare(
                'SELECT DISTINCT link.bank_transaction_id FROM bank_transaction_imports link
                 JOIN bank_statements imported ON imported.id = link.statement_id
                 WHERE link.import_fingerprint = ? AND imported.supplier_id = ? AND imported.source = ?'
            );
            foreach ($fingerprints as $index => $fingerprint) {
                $alias->execute([$fingerprint, $supplierId, $source]);
                $ids = array_values(array_filter(array_map('intval', $alias->fetchAll(PDO::FETCH_COLUMN)), static fn (int $id): bool => isset($byId[$id])));
                if (count($ids) > 1) throw new StatementReconciliationException();
                if ($ids !== []) $known[$index] = $ids[0];
            }
        }
        $matches = [];
        $reverse = [];
        $strong = [];
        foreach ($known as $index => $id) $reverse[$id][] = $index;
        foreach ($transactions as $index => $tx) {
            if (isset($known[$index])) continue;
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
        $result = $known;
        $review = [];
        foreach ($matches as $index => $ids) {
            if (count($ids) !== 1 || count($reverse[$ids[0]]) !== 1) {
                throw new StatementReconciliationException();
            }
            if (!$strong[$index][$ids[0]]) {
                $tx = $transactions[$index];
                $existing = $byId[$ids[0]];
                $key = hash('sha256', json_encode([
                    $supplierId, $accountKey, $currency, $source,
                    $fingerprints[$index] ?? null,
                    self::confirmationIdentity($tx), $ids[0], (int) $existing['statement_id'],
                    $existing['import_fingerprint'] ?? null, self::confirmationIdentity($existing),
                ], JSON_THROW_ON_ERROR));
                if (!in_array($key, $confirmations, true)) {
                    $review[] = [
                        'confirmation_key' => $key,
                        'posted_at' => $tx['posted_at'],
                        'amount' => number_format((float) $tx['amount'], 2, '.', ''),
                        'currency' => $currency,
                        'existing_transaction_id' => $ids[0],
                        'existing_statement_id' => (int) $existing['statement_id'],
                        'description' => (string) ($tx['description'] ?? ''),
                        'existing_description' => (string) ($existing['description'] ?? ''),
                        'counterparty_account' => (string) ($tx['counterparty_account'] ?? ''),
                        'existing_counterparty_account' => (string) ($existing['counterparty_account'] ?? ''),
                        'variable_symbol' => (string) ($tx['variable_symbol'] ?? ''),
                        'existing_variable_symbol' => (string) ($existing['variable_symbol'] ?? ''),
                    ];
                }
            }
            $result[$index] = $ids[0];
        }
        if ($review !== []) throw new StatementReconciliationException($review);
        return $result;
    }

    private static function confirmationIdentity(array $transaction): array
    {
        $identity = [];
        foreach (['posted_at', 'bank_ref', 'variable_symbol', 'constant_symbol', 'specific_symbol', 'counterparty_account', 'counterparty_bank', 'counterparty_name', 'description'] as $field) {
            $identity[$field] = (string) ($transaction[$field] ?? '');
        }
        $identity['amount'] = number_format((float) $transaction['amount'], 2, '.', '');
        return $identity;
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
        $accountA = self::account((string) ($a['counterparty_account'] ?? ''), (string) ($a['counterparty_bank'] ?? ''));
        $accountB = self::account((string) ($b['counterparty_account'] ?? ''), (string) ($b['counterparty_bank'] ?? ''));
        $symbolA = ltrim(trim((string) ($a['variable_symbol'] ?? '')), '0');
        $symbolB = ltrim(trim((string) ($b['variable_symbol'] ?? '')), '0');
        if ($accountA !== null && $accountA === $accountB && $symbolA !== '' && $symbolA === $symbolB) return true;
        $descriptionA = self::descriptionParts((string) ($a['description'] ?? ''));
        $descriptionB = self::descriptionParts((string) ($b['description'] ?? ''));
        return isset($descriptionA[0], $descriptionB[0])
            && (in_array($descriptionA[0], $descriptionB, true) || in_array($descriptionB[0], $descriptionA, true));
    }

    private static function descriptionParts(string $description): array
    {
        $parts = array_merge([$description], explode('|', $description));
        $parts = array_map(static fn (string $part): string =>
            (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($part)), $parts);
        return array_values(array_filter($parts, static fn (string $part): bool =>
            mb_strlen($part) >= 16 && preg_match('/\p{L}/u', $part) === 1));
    }

    private static function reference(array $tx): string
    {
        $reference = trim((string) ($tx['bank_ref'] ?? ''));
        return ctype_digit($reference) ? ltrim($reference, '0') : $reference;
    }
}
