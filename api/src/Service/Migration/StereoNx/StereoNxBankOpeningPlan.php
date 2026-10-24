<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Doložený počáteční stav banky se převádí na kotvu výpisu, nikoli na příjem. */
final class StereoNxBankOpeningPlan
{
    public static function resolve(array $payments, array $journal): array
    {
        $records = $payments['records'] ?? [];
        $movements = $records['bank_transactions'] ?? [];
        if ($movements === []) return $payments;
        $entries = StereoNxOpeningMovementProof::index($journal)['bank'];
        $accounts = array_column($records['bank_accounts'] ?? [], null, 'source_key');
        $statements = array_column($records['bank_statements'] ?? [], null, 'source_key');
        $statementCounts = [];
        $earliest = [];
        $openingCounts = [];
        $physicalPeriods = [];
        foreach ($movements as $movement) {
            $statementKey = (string) ($movement['statement_key'] ?? '');
            $statementCounts[$statementKey] = ($statementCounts[$statementKey] ?? 0) + 1;
            $statement = $statements[$statementKey] ?? null;
            $account = $accounts[$statement['account_key'] ?? ''] ?? null;
            $parts = StereoNxOpeningMovementProof::parts((string) ($movement['source_key'] ?? ''), 4);
            if ($account === null || $parts === null) continue;
            $key = (string) $movement['source_key'];
            $physical = StereoNxAccountMappingPlan::bankIdentity($account) . '|' . $parts[0];
            $physicalPeriods[$key] = $physical;
            $date = (string) ($movement['date'] ?? '');
            $earliest[$physical] = min($earliest[$physical] ?? $date, $date);
            if (StereoNxOpeningMovementProof::hasOpening($entries[$key] ?? [])) $openingCounts[$physical] = ($openingCounts[$physical] ?? 0) + 1;
        }
        $paymentsByMovement = [];
        foreach ($records['payments'] ?? [] as $payment) {
            if (($payment['movement_type'] ?? '') === 'bank') $paymentsByMovement[(string) $payment['movement_key']] = true;
        }
        $reviewsByMovement = [];
        foreach ($records['reviews'] ?? [] as $review) {
            if (($review['kind'] ?? '') === 'bank') {
                $reviewsByMovement[(string) $review['source_key']] = [...($reviewsByMovement[(string) $review['source_key']] ?? []),
                    ...($review['review_codes'] ?? [])];
            }
        }
        $resolved = [];
        foreach ($movements as $movement) {
            $key = (string) ($movement['source_key'] ?? '');
            $matched = $entries[$key] ?? [];
            if (!StereoNxOpeningMovementProof::hasOpening($matched)) continue;
            $statementKey = (string) ($movement['statement_key'] ?? '');
            $statement = $statements[$statementKey] ?? [];
            $account = $accounts[$statement['account_key'] ?? ''] ?? [];
            $physical = $physicalPeriods[$key] ?? '';
            $proof = null;
            // Vyřazené pohyby už plán neobsahuje: nelze doložit čistotu výpisu ani pořadí banky.
            if (($payments['counts']['skipped_bank_transactions'] ?? 0) === 0
                && ($statementCounts[$statementKey] ?? 0) === 1
                && ($openingCounts[$physical] ?? 0) === 1
                && ($earliest[$physical] ?? null) === ($movement['date'] ?? null)
                && !isset($paymentsByMovement[$key])
                && array_diff($reviewsByMovement[$key] ?? [], ['movement_journal_unverified']) === []) {
                $proof = self::prove($movement, $statement, $account, $matched);
            }
            if ($proof === null) {
                self::warning($payments, ['level' => 'warning', 'code' => 'bank_opening_unverified',
                    'source_key' => $key, 'statement_key' => $statementKey,
                    'message' => 'Počáteční stav banky nemá jednoznačný důkaz čistého výpisu; pohyb zůstává k ruční kontrole.']);
                continue;
            }
            $payments['records']['bank_openings'][] = ['movement' => $movement, 'statement_key' => $statementKey,
                'opening_cents' => $proof['amount'], 'entry_keys' => $proof['entries'], 'account_code' => $proof['account'],
                'date' => $movement['date'], 'balance_date' => $proof['balance_date']];
            $resolved[$key] = true;
            self::warning($payments, ['level' => 'info', 'code' => 'bank_opening_balance',
                'source_key' => $key, 'statement_key' => $statementKey,
                'message' => 'Počáteční stav banky se převezme bez peněžního pohybu jako stav k ' . $proof['balance_date']
                    . ', tedy před začátkem období. Původní otevírací účetní zápis zůstává zachován.']);
        }
        if ($resolved === []) return $payments;
        $payments['records']['bank_transactions'] = array_values(array_filter($movements,
            static fn (array $movement): bool => !isset($resolved[(string) $movement['source_key']])));
        $payments['records']['reviews'] = array_values(array_filter($records['reviews'] ?? [],
            static fn (array $review): bool => ($review['kind'] ?? '') !== 'bank' || !isset($resolved[(string) $review['source_key']])));
        $payments['counts']['bank_transactions'] = count($payments['records']['bank_transactions']);
        $payments['counts']['bank_openings'] = count($payments['records']['bank_openings']);
        $payments['counts']['requires_review'] = count($payments['records']['reviews']);
        foreach ($payments['warnings'] as $i => &$warning) {
            if (($warning['code'] ?? '') !== 'pending_reconciliation') continue;
            if ($payments['counts']['requires_review'] === 0) unset($payments['warnings'][$i]);
            else $warning['count'] = $payments['counts']['requires_review'];
        }
        unset($warning);
        $payments['warnings'] = array_values($payments['warnings']);
        return $payments;
    }

    private static function prove(array $movement, array $statement, array $account, array $entries): ?array
    {
        $parts = StereoNxOpeningMovementProof::parts((string) ($movement['source_key'] ?? ''), 4);
        $statementParts = StereoNxOpeningMovementProof::parts((string) ($statement['source_key'] ?? ''), 3);
        $date = (string) ($movement['date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parts === null || $statementParts !== array_slice($parts, 0, 3)
            || (string) ($statement['account_key'] ?? '') !== $parts[1]
            || (string) ($account['source_key'] ?? '') !== $parts[1]
            || ($statement['date'] ?? null) !== $date || $parsed === false || $parsed->format('Y-m-d') !== $date
            || ($movement['currency'] ?? '') !== 'CZK' || ($statement['currency'] ?? '') !== 'CZK'
            || ($account['currency'] ?? '') !== 'CZK') return null;
        $proof = StereoNxOpeningMovementProof::prove($movement, $entries, '221',
            isset($account['target_analytic_suffix']) ? '221.' . $account['target_analytic_suffix'] : null);
        if ($proof === null) return null;
        // Nativní curr_balance znamená konec dne, počáteční stav proto kotví předchozí den.
        return ['amount' => $proof['opening_cents'], 'account' => $proof['account_code'], 'entries' => $proof['entry_keys'],
            'balance_date' => $parsed->modify('-1 day')->format('Y-m-d')];
    }

    private static function warning(array &$plan, array $warning): void
    {
        foreach ($plan['warnings'] ?? [] as $existing) {
            if (($existing['code'] ?? '') === $warning['code'] && ($existing['source_key'] ?? '') === $warning['source_key']) return;
        }
        $plan['warnings'][] = $warning;
    }
}
