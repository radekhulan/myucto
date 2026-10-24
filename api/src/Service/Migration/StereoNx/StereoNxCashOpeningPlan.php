<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Počáteční pokladní stav se potvrzuje výhradně proti původní otevírací kontaci. */
final class StereoNxCashOpeningPlan
{
    public static function resolve(array $payments, array $journal): array
    {
        $records = $payments['records'] ?? [];
        $movements = $records['cash_transactions'] ?? [];
        if ($movements === []) return $payments;
        $entries = StereoNxOpeningMovementProof::index($journal)['cash'];
        $counts = array_count_values(array_column($movements, 'source_key'));
        $linked = [];
        foreach ($records['payments'] ?? [] as $payment) {
            if (($payment['movement_type'] ?? '') === 'cash') $linked[(string) $payment['movement_key']] = true;
        }
        $reviews = [];
        foreach ($records['reviews'] ?? [] as $review) {
            if (($review['kind'] ?? '') === 'cash') {
                $key = (string) $review['source_key'];
                $reviews[$key] = [...($reviews[$key] ?? []), ...($review['review_codes'] ?? [])];
            }
        }
        // Identita je uvnitř původního pohybu; žádné odvozené pole nepřidáváme do jeho hashe.
        $openings = [];
        foreach ($records['cash_openings'] ?? [] as $opening) $openings[$opening['movement']['source_key']] = $opening;
        $resolved = [];
        foreach ($movements as $movement) {
            $key = (string) ($movement['source_key'] ?? '');
            $matched = $entries[$key] ?? [];
            if (!StereoNxOpeningMovementProof::hasOpening($matched)) continue;
            $proof = null;
            // Pending není důkaz absence DPH či vazby. Ty dokládá samostatný index raw polí v prepare().
            if ((($payments['cash_opening_candidates'][$key] ?? false) === true
                    || ($payments['non_vat_cash_candidates'][$key] ?? false) === true)
                && ($counts[$key] ?? 0) === 1 && !isset($linked[$key])
                && array_diff([...($movement['review_codes'] ?? []), ...($reviews[$key] ?? [])],
                    ['pending_reconciliation', 'movement_journal_unverified']) === []) {
                $proof = StereoNxOpeningMovementProof::prove($movement, $matched, '211');
            }
            if ($proof === null) {
                self::warning($payments, ['level' => 'warning', 'code' => 'cash_opening_unverified', 'source_key' => $key,
                    'message' => 'Počáteční stav pokladny nemá jednoznačný důkaz; doklad zůstává k ruční kontrole.']);
                continue;
            }
            $openings[$key] = ['movement' => $movement, ...$proof];
            $resolved[$key] = true;
            self::warning($payments, ['level' => 'info', 'code' => 'cash_opening_balance', 'source_key' => $key,
                'message' => 'Počáteční stav pokladny je doložen původní otevírací kontací; převezme se bez nového účetního zápisu.']);
        }
        if ($resolved === []) return $payments;
        $payments['records']['cash_openings'] = array_values($openings);
        $payments['records']['reviews'] = array_values(array_filter($records['reviews'] ?? [],
            static fn (array $review): bool => ($review['kind'] ?? '') !== 'cash' || !isset($resolved[(string) $review['source_key']])));
        $payments['counts']['cash_openings'] = count($openings);
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

    private static function warning(array &$plan, array $warning): void
    {
        foreach ($plan['warnings'] ?? [] as $existing) {
            if (($existing['code'] ?? '') === $warning['code'] && ($existing['source_key'] ?? '') === $warning['source_key']) return;
        }
        $plan['warnings'][] = $warning;
    }
}
