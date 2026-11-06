<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Invoice\InvoiceMath;

/** Náhradní účetní položky z jediné ověřené sazební rekapitulace a deníku. */
final class StereoNxRecapAccountItems
{
    /** @param array<string,int> $accountAmounts @return list<array<string,mixed>>|null */
    public static function reconstruct(array $document, array $accountAmounts, string $field): ?array
    {
        if (($document['origin'] ?? '') !== 'vat_recap' || count($document['items'] ?? []) !== 1
            || ($document['currency_code'] ?? 'CZK') !== 'CZK'
            || !is_bool($document['prices_include_vat'] ?? null)
            || array_diff($document['review_codes'] ?? [], ['issued_lines_missing']) !== []
            || count($accountAmounts) < 2 || count($accountAmounts) > 100) return null;
        $template = array_values($document['items'])[0];
        if (trim((string) ($template[$field] ?? '')) !== '') return null;
        $base = StereoNxRecapAmounts::moneyCents($template['total_without_vat'] ?? null);
        $vat = StereoNxRecapAmounts::moneyCents($template['total_vat'] ?? null);
        $gross = StereoNxRecapAmounts::moneyCents($template['total_with_vat'] ?? null);
        $rate = $template['vat_rate_snapshot'] ?? null;
        if ($base === null || $base === 0 || $vat === null || $gross !== $base + $vat
            || (!is_int($rate) && !is_float($rate)) || !is_finite((float) $rate) || $rate < 0 || $rate > 100) return null;
        $deduction = $template['vat_deduction'] ?? 'full';
        if ($field === 'expense_account_code' && (!in_array($deduction, ['full', 'reduced', 'none'], true)
            || ($deduction === 'none' && $vat !== 0))) return null;
        $reverse = ($document['reverse_charge'] ?? false) === true;
        $selfAssessed = StereoNxRecapAmounts::moneyCents($template['source_self_assessed_vat'] ?? 0);
        if ($selfAssessed === null || (!$reverse && $selfAssessed !== 0)) return null;

        // Kladné položky dobropisu nativní účtování obrací až při zaúčtování.
        // Zdrojové položky už se zápornými částkami podruhé nepřevracíme.
        $sign = ($document['target_document_kind'] ?? '') === 'credit_note' && $base > 0 ? -1 : 1;
        ksort($accountAmounts, SORT_STRING);
        $accounts = array_map('strval', array_keys($accountAmounts));
        $expected = [];
        $netItems = [];
        foreach ($accountAmounts as $amount) {
            $amount *= $sign;
            if ($amount === 0 || ($amount > 0) !== ($base > 0)) return null;
            $expected[] = $amount;
            $netItems[] = ['quantity' => 1.0, 'unit_price_without_vat' => $amount / 100, 'vat_rate_snapshot' => $rate];
        }
        if (array_sum($expected) !== $base) return null;
        // Tentýž veřejný výpočet používá běžné uložení faktury. Žádný vlastní
        // poměrný rozpis daně: nový rozpad musí přežít i následnou editaci.
        $net = InvoiceMath::compute($netItems, $reverse);
        $pricedItems = $netItems;
        if ($document['prices_include_vat']) {
            foreach ($pricedItems as $i => &$item) $item['unit_price_without_vat'] = $net['items'][$i]['with'];
            unset($item);
        }
        $computed = InvoiceMath::compute($pricedItems, $reverse, $document['prices_include_vat']);
        if (StereoNxRecapAmounts::moneyCents($computed['totals']['without_vat']) !== $base
            || StereoNxRecapAmounts::moneyCents($computed['totals']['vat']) !== $vat
            || StereoNxRecapAmounts::moneyCents($computed['totals']['with_vat']) !== $gross) return null;
        $assessed = $reverse ? InvoiceMath::compute($netItems) : null;
        if ($assessed !== null && StereoNxRecapAmounts::moneyCents($assessed['totals']['vat']) !== $selfAssessed) return null;
        $items = [];
        foreach ($computed['items'] as $i => $line) {
            if (StereoNxRecapAmounts::moneyCents($line['base']) !== $expected[$i]) return null;
            $account = $accounts[$i];
            $description = trim((string) ($template['description'] ?? ''));
            $items[] = array_replace($template, [
                'origin' => 'journal_reconstruction',
                'description' => $description === '' ? 'Účet ' . $account : $description . ' (' . $account . ')',
                'quantity' => 1.0,
                'unit_price' => $pricedItems[$i]['unit_price_without_vat'],
                'unit_price_without_vat' => $pricedItems[$i]['unit_price_without_vat'],
                'total_without_vat' => $line['base'], 'total_vat' => $line['vat'], 'total_with_vat' => $line['with'],
                'source_self_assessed_vat' => $assessed['items'][$i]['vat'] ?? 0.0,
                'target_' . $field => $account,
            ]);
        }
        return $items;
    }

}
