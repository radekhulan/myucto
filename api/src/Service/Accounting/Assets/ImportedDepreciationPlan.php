<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

/** Převzatý budoucí plán je předpis částek, nikoli potvrzená historie odpisů. */
final class ImportedDepreciationPlan
{
    /** @return array<string,mixed> */
    public static function validate(array $plan, DepreciationContext $ctx): array
    {
        if (($plan['version'] ?? null) !== 1 || !is_array($plan['accounting'] ?? null)
            || !array_is_list($plan['accounting']) || !is_array($plan['tax'] ?? null) || !array_is_list($plan['tax'])
            || count($plan['accounting']) > 3000 || $ctx->putIntoUseDate === null
            || $ctx->accUsefulLifeMonths === null || $ctx->openingAccMonths < 0 || $ctx->accUsefulLifeMonths < 0
            || $ctx->openingAccMonths > $ctx->accUsefulLifeMonths
            || $ctx->improvements !== [] || $ctx->accResidualValue !== 0.0
            || self::cents($ctx->openingTaxAmount) !== self::cents($ctx->openingAccAmount)) {
            self::invalid();
        }
        foreach (['source_program', 'source_tax_method', 'source_accounting_method'] as $key) {
            if (!is_string($plan[$key] ?? null) || trim($plan[$key]) === '' || mb_strlen($plan[$key]) > 160) self::invalid();
        }
        $next = self::monthIndex($ctx->putIntoUseDate) + 1 + $ctx->openingAccMonths;
        $years = [];
        $sum = 0;
        foreach ($plan['accounting'] as $row) {
            if (!is_array($row) || !is_string($row['month'] ?? null)
                || !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $row['month'])
                || self::monthIndex($row['month']) !== $next || !self::validCents($row['amount_cents'] ?? null)) self::invalid();
            $year = $ctx->calendar->fiscalYearOfMonthIndex($next++);
            $years[$year] = ($years[$year] ?? 0) + $row['amount_cents'];
            $sum += $row['amount_cents'];
        }
        if (count($plan['accounting']) !== $ctx->accUsefulLifeMonths - $ctx->openingAccMonths
            || $sum !== self::cents($ctx->inputPrice) - self::cents($ctx->openingAccAmount)) self::invalid();
        if ($ctx->openingAccMonths > 0 && $plan['accounting'] !== []) {
            $first = self::monthIndex($plan['accounting'][0]['month']);
            if ($first !== $ctx->calendar->firstMonthIndex($ctx->calendar->fiscalYearOfMonthIndex($first))) self::invalid();
        }
        $tax = [];
        $previous = 0;
        foreach ($plan['tax'] as $row) {
            if (!is_array($row) || !is_int($row['fiscal_year'] ?? null) || $row['fiscal_year'] <= $previous
                || !self::validCents($row['amount_cents'] ?? null)) self::invalid();
            $previous = $row['fiscal_year'];
            $tax[$previous] = $row['amount_cents'];
        }
        if ($tax !== $years) self::invalid();
        self::applyRevisions($plan, $ctx);
        return $plan;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(DepreciationContext $ctx, string $kind, ?int $forceYear = null): array
    {
        $plan = self::validate($ctx->importedPlan ?? [], $ctx);
        self::assertDisposalDate($ctx);
        $byYear = [];
        foreach (self::applyRevisions($plan, $ctx) as $month) {
            if ($ctx->disposalDate !== null && $month['month'] > substr($ctx->disposalDate, 0, 7)) break;
            $year = $ctx->calendar->fiscalYearOfMonthIndex(self::monthIndex($month['month']));
            $byYear[$year][] = $month;
        }
        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? null) === $kind) $confirmed[$entry['fiscal_year']] = $entry;
        }
        $remaining = self::cents($ctx->inputPrice) - self::cents($ctx->openingAccAmount);
        $out = [];
        foreach ($byYear as $year => $months) {
            $amount = array_sum(array_column($months, 'amount_cents'));
            $entry = $year === $forceYear ? null : ($confirmed[$year] ?? null);
            if ($entry !== null && (self::cents((float) $entry['amount']) !== $amount
                || self::cents((float) $entry['full_amount']) !== $amount
                || ($entry['is_paused'] ?? false) || ($entry['is_half'] ?? false))) {
                throw new AssetException('imported_plan_conflict', 'Potvrzený odpis nesouhlasí s převzatým plánem.');
            }
            $out[] = ['fiscal_year' => $year, 'amount' => $amount / 100.0, 'full_amount' => $amount / 100.0,
                'residual_start' => $remaining / 100.0, 'residual_end' => ($remaining - $amount) / 100.0,
                'is_half' => false, 'is_paused' => false, 'months_count' => count($months),
                'months' => $kind === 'accounting' ? array_map(static fn (array $m): array => [
                    'month' => $m['month'], 'amount' => $m['amount_cents'] / 100.0], $months) : null,
                'source' => $entry === null ? 'imported' : 'confirmed', 'note' => null];
            $remaining -= $amount;
        }
        return $out;
    }

    /** Účinný měsíční plán; původní převzaté řádky ani historii nemění. */
    public static function accounting(DepreciationContext $ctx): array
    {
        return self::applyRevisions(self::validate($ctx->importedPlan ?? [], $ctx), $ctx);
    }

    private static function applyRevisions(array $plan, DepreciationContext $ctx): array
    {
        $rows = $plan['accounting'];
        $history = $plan['revisions'] ?? [];
        if (!is_array($history) || !array_is_list($history) || count($history) > 1000) self::invalid();
        $previous = '';
        foreach ($history as $index => $revision) {
            if (!is_array($revision) || ($revision['revision'] ?? null) !== $index + 1
                || !is_string($revision['effective_from'] ?? null)
                || !\MyInvoice\Http\IsoDate::isValid($revision['effective_from'] . '-01')
                || $revision['effective_from'] < $previous
                || !is_int($revision['remaining_months'] ?? null) || $revision['remaining_months'] < 1 || $revision['remaining_months'] > 3000
                || !is_string($revision['reason'] ?? null) || trim($revision['reason']) === '' || mb_strlen($revision['reason']) > 2000
                || !is_int($revision['user_id'] ?? null) || $revision['user_id'] < 1
                || !is_string($revision['created_at'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $revision['created_at'])
                || !\MyInvoice\Http\IsoDate::isValid(substr($revision['created_at'], 0, 10))
                || ($revision['fiscal_start_month'] ?? null) !== $ctx->calendar->startMonth()) self::invalid();
            $prefix = [];
            $remaining = 0;
            $found = false;
            foreach ($rows as $row) {
                if ($row['month'] < $revision['effective_from']) $prefix[] = $row;
                else $remaining += $row['amount_cents'];
                if ($row['month'] === $revision['effective_from']) $found = true;
            }
            if (!$found || $remaining <= 0 || ($revision['remaining_amount_cents'] ?? null) !== $remaining
                || count($prefix) + $revision['remaining_months'] + $ctx->openingAccMonths > 3000) self::invalid();
            $monthly = intdiv($remaining, $revision['remaining_months']);
            $start = self::monthIndex($revision['effective_from']);
            for ($i = 0; $i < $revision['remaining_months']; $i++) {
                $month = $start + $i;
                if (intdiv($month, 12) > 9999) self::invalid();
                $prefix[] = ['month' => sprintf('%04d-%02d', intdiv($month, 12), $month % 12 + 1),
                    'amount_cents' => $i === $revision['remaining_months'] - 1
                        ? $remaining - $monthly * ($revision['remaining_months'] - 1) : $monthly];
            }
            $rows = $prefix;
            $previous = $revision['effective_from'];
        }
        return $rows;
    }

    public static function assertDisposalDate(DepreciationContext $ctx): void
    {
        if ($ctx->importedPlan !== null && $ctx->disposalDate !== null && $ctx->openingAccMonths > 0
            && self::monthIndex($ctx->disposalDate) <= self::monthIndex((string) $ctx->putIntoUseDate) + $ctx->openingAccMonths) {
            throw new AssetException('imported_plan_locked', 'Vyřazení nesmí zasáhnout historické měsíce zahrnuté v počátečních oprávkách.');
        }
    }

    private static function validCents(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 100000000000000;
    }

    private static function cents(float $value): int
    {
        if (!is_finite($value) || $value < 0 || $value > 1.0e12) self::invalid();
        return (int) round($value * 100);
    }

    private static function monthIndex(string $value): int
    {
        return (int) substr($value, 0, 4) * 12 + (int) substr($value, 5, 2) - 1;
    }

    private static function invalid(): never
    {
        throw new AssetException('imported_plan_invalid', 'Převzatý odpisový plán není úplný nebo nesouhlasí s kartou a zdaňovacími obdobími.');
    }
}
