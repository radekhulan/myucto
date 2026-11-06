<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingRoleProfile};

final class DeferredTax504Builder
{
    public const METHOD = '504-deferred-own-v1';

    public static function assessment(array $body): array
    {
        $method = $body['method'] ?? [];
        if (($method['enabled'] ?? false) !== true) {
            if (!empty($body['titles']) || !empty($body['opening_titles'])) throw self::methodError();
            return ['enabled' => false, 'method' => null];
        }
        if (($method['version'] ?? '') !== self::METHOD || !is_bool($method['first_adoption'] ?? null)) throw self::methodError();
        $normalized = ['enabled' => true, 'version' => self::METHOD, 'first_adoption' => $method['first_adoption']];
        foreach (['reference', 'faithful_image_reason', 'standard_departure_reason', 'presentation_reason'] as $key) $normalized[$key] = self::text($method[$key] ?? null);
        if ($normalized['first_adoption']) $normalized['first_adoption_reason'] = self::text($method['first_adoption_reason'] ?? null);
        elseif (!empty($body['opening_titles'])) throw self::methodError();
        return ['enabled' => true, 'method' => $normalized,
            'opening' => self::titles($body['opening_titles'] ?? []), 'closing' => self::titles($body['titles'] ?? [])];
    }

    public static function movements(array $assessment, array $balances, string $phase): array
    {
        if (($assessment['enabled'] ?? false) !== true || ($assessment['method']['version'] ?? '') !== self::METHOD
            || !in_array($phase, ['opening', 'closing'], true)
            || ($phase === 'opening' && !($assessment['method']['first_adoption'] ?? false))) throw self::methodError();
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        $counterpart = $profile->account($phase === 'opening' ? 'result.retained_profit' : 'deferred_tax.expense');
        $targets = $assessment[$phase]['cells'];
        $current = [];
        foreach ($balances as $cell) {
            if (!in_array($cell['kind'] ?? null, ['asset', 'liability'], true)
                || !is_int($cell['balance_cents'] ?? null) || $cell['balance_cents'] < 0) throw self::sourceError();
            $key = $cell['kind'] . ':' . LegalClosing504Builder::cellKey($cell);
            if (isset($current[$key])) throw self::sourceError();
            $current[$key] = $cell;
        }
        $lines = [];
        foreach (array_unique(array_merge(array_keys($targets), array_keys($current))) as $key) {
            $cell = $targets[$key] ?? $current[$key];
            $delta = ($targets[$key]['balance_cents'] ?? 0) - ($current[$key]['balance_cents'] ?? 0);
            if ($delta === 0) continue;
            $account = $profile->account('deferred_tax.' . $cell['kind']);
            $balanceDebit = ($cell['kind'] === 'asset') === ($delta > 0);
            $metadata = array_intersect_key($cell, array_flip(['dimensions', 'cost_center', 'project_id']));
            $metadata['amount'] = abs($delta) / 100.0;
            $debit = $balanceDebit ? $account : $counterpart;
            $credit = $balanceDebit ? $counterpart : $account;
            $lines[] = ['account_code' => $debit, 'side' => 'debit'] + $metadata;
            $lines[] = ['account_code' => $credit, 'side' => 'credit'] + $metadata;
        }
        return $lines;
    }

    private static function titles(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 200) throw self::methodError();
        $out = ['titles' => [], 'cells' => [], 'liability_cents' => 0, 'asset_cents' => 0, 'unrecognized_asset_cents' => 0];
        $references = [];
        foreach ($raw as $row) {
            if (!is_array($row) || ($row['temporary_confirmed'] ?? false) !== true || !in_array($row['kind'] ?? null, ['taxable', 'deductible'], true)) throw self::methodError();
            $reference = self::text($row['reference'] ?? null);
            if (isset($references[$reference])) throw self::methodError();
            $references[$reference] = true;
            $difference = LegalClosing504Builder::targetCents($row['difference'] ?? null);
            $rate = LegalClosing504Builder::targetCents($row['rate_percent'] ?? null);
            if ($difference > 100000000000 || $rate <= 0 || $rate > 10000) throw self::methodError();
            $evidence = self::text($row['evidence'] ?? null);
            $rateBasis = self::text($row['rate_basis'] ?? null);
            $asset = $row['kind'] === 'deductible';
            $recognized = $difference;
            $recoverability = null;
            if ($asset) {
                if (!array_key_exists('recognized_difference', $row) || $row['recognized_difference'] === null) throw self::recoverabilityError();
                $recognized = LegalClosing504Builder::targetCents($row['recognized_difference']);
                if ($recognized > $difference) throw self::recoverabilityError();
                if ($recognized > 0) {
                    if (!is_string($row['recoverability_evidence'] ?? null) || trim($row['recoverability_evidence']) === '') throw self::recoverabilityError();
                    $recoverability = self::text($row['recoverability_evidence']);
                }
            }
            $cell = LegalClosing504Builder::allocations([['amount' => '0.01'] + $row], 1)[0];
            unset($cell['amount']);
            $tax = intdiv($recognized * $rate + 5000, 10000);
            $potential = intdiv($difference * $rate + 5000, 10000);
            $kind = $asset ? 'asset' : 'liability';
            $key = $kind . ':' . LegalClosing504Builder::cellKey($cell);
            if ($tax > 0) {
                $out['cells'][$key] ??= $cell + ['kind' => $kind, 'balance_cents' => 0];
                $out['cells'][$key]['balance_cents'] += $tax;
            }
            $out[$kind . '_cents'] += $tax;
            if ($asset) $out['unrecognized_asset_cents'] += $potential - $tax;
            $out['titles'][] = ['reference' => $reference, 'evidence' => $evidence, 'kind' => $row['kind'], 'temporary_confirmed' => true,
                'difference_cents' => $difference, 'recognized_difference_cents' => $recognized, 'rate_basis_points' => $rate,
                'rate_basis' => $rateBasis, 'recognized_tax_cents' => $tax, 'unrecognized_tax_cents' => $asset ? $potential - $tax : 0,
                'recoverability_evidence' => $recoverability] + $cell;
        }
        return $out;
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) throw self::methodError();
        return trim($value);
    }

    private static function methodError(): ClosingException
    {
        return new ClosingException('deferred_tax_method_required', 'Potvrďte vlastní metodu odložené daně, její důvody a oddělené vykázání splatné a odložené daně.', 422);
    }

    private static function recoverabilityError(): ClosingException
    {
        return new ClosingException('deferred_tax_recoverability_required', 'Doložte využitelnou část odložené daňové pohledávky a pravděpodobnost budoucího uplatnění.', 422);
    }

    private static function sourceError(): ClosingException
    {
        return new ClosingException('deferred_tax_source_changed', 'Zůstatky odložené daně neodpovídají doloženému společnému rozdělení. Obnovte podklady.', 409);
    }
}
