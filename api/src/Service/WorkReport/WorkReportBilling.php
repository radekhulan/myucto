<?php

declare(strict_types=1);

namespace MyInvoice\Service\WorkReport;

use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\TimeBilling;

/** Jediné místo pro validaci cenového ujednání, pásma i popisy fakturačních řádků. */
final class WorkReportBilling
{
    public static function validate(mixed $value): array
    {
        if (!is_array($value)) throw new \InvalidArgumentException('Chybí cenové ujednání.');
        if (!is_string($value['name'] ?? null) || !is_string($value['currency'] ?? null)) {
            throw new \InvalidArgumentException('Název a měna cenového ujednání musí být text.');
        }
        $name = trim($value['name']);
        $currency = strtoupper(trim($value['currency']));
        if ($name === '' || mb_strlen($name) > 200 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Vyplňte název a měnu cenového ujednání.');
        }
        $fixed = ($value['fixed_fee'] ?? null) === null ? null : self::number($value['fixed_fee']);
        $included = self::number($value['included_hours'] ?? 0);
        if ($fixed === null && $included > 0) throw new \InvalidArgumentException('Zahrnuté hodiny vyžadují paušál.');
        $bands = $value['bands'] ?? [];
        if (!is_array($bands) || !array_is_list($bands) || count($bands) > 100 || ($fixed === null && $bands === [])) {
            throw new \InvalidArgumentException('Zadejte paušál nebo hodinová pásma (nejvýše 100).');
        }
        $previous = $included;
        $normalized = [];
        foreach ($bands as $index => $band) {
            if (!is_array($band)) throw new \InvalidArgumentException('Neplatné hodinové pásmo.');
            $upper = ($band['up_to_hours'] ?? null) === null ? null : self::number($band['up_to_hours']);
            if (($upper === null && $index !== count($bands) - 1) || ($upper !== null && $upper <= $previous)) {
                throw new \InvalidArgumentException('Hranice pásem musí růst; pouze poslední pásmo je neomezené.');
            }
            $normalized[] = ['up_to_hours' => $upper, 'rate' => self::number($band['rate'] ?? 0),
                'description' => self::description($band['description'] ?? '{nazev} – {hodiny} h')];
            $previous = $upper ?? $previous;
        }
        if ($normalized !== [] && end($normalized)['up_to_hours'] !== null) {
            throw new \InvalidArgumentException('Poslední hodinové pásmo musí být neomezené.');
        }
        return ['name' => $name, 'currency' => $currency, 'fixed_fee' => $fixed,
            'included_hours' => $included,
            'fixed_description' => self::description($value['fixed_description'] ?? '{nazev} – {obdobi}'),
            'bands' => $normalized];
    }

    private static function number(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 999999999) {
            throw new \InvalidArgumentException('Cena a hranice hodin musí být nezáporné číslo.');
        }
        return round((float) $value, 6);
    }

    private static function description(mixed $value): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Popis musí být text.');
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 1000) throw new \InvalidArgumentException('Popis musí mít 1 až 1000 znaků.');
        preg_match_all('/\{([^{}]+)\}/u', $value, $matches);
        foreach ($matches[1] as $token) {
            if (!in_array($token, ['nazev', 'zakazka', 'obdobi', 'limit', 'od', 'do', 'hodiny'], true)) {
                throw new \InvalidArgumentException('Neznámý zástupný údaj v popisu: {' . $token . '}.');
            }
        }
        return $value;
    }

    public static function period(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $value)) {
            throw new \InvalidArgumentException('Období účtování musí mít formát YYYY-MM.');
        }
        return $value;
    }

    public static function calculate(array $report, array $invoice = [], float $vatRate = 0): array
    {
        foreach (['title', 'project_name'] as $field) {
            if (isset($report[$field]) && !is_string($report[$field])) throw new \InvalidArgumentException('Název výkazu a zakázky musí být text.');
        }
        $settings = self::validate($report['billing_snapshot'] ?? null);
        $period = self::period($report['billing_period'] ?? null);
        if (($invoice['currency'] ?? $settings['currency']) !== $settings['currency']) {
            throw new \InvalidArgumentException('Měna cenového ujednání se liší od měny faktury.');
        }
        $hours = 0.0;
        $entries = [];
        $timeEntries = $report['items'] ?? [];
        if (!is_array($timeEntries) || !array_is_list($timeEntries)) throw new \InvalidArgumentException('Řádky práce musí být seznam.');
        foreach ($timeEntries as $entry) {
            if (!is_array($entry)) throw new \InvalidArgumentException('Neplatný řádek práce.');
            if (isset($entry['description']) && !is_string($entry['description'])) throw new \InvalidArgumentException('Popis práce musí být text.');
            $entry = TimeBilling::normalizeWorkReportItem($entry);
            self::number($entry['hours'] ?? 0);
            $entries[] = ['description' => (string) ($entry['description'] ?? ''), 'work_date' => $entry['work_date'] ?? null, 'hours' => (float) ($entry['hours'] ?? 0), 'duration_minutes' => $entry['duration_minutes']];
            $hours += $entry['duration_minutes'] !== null ? $entry['duration_minutes'] / 60 : (float) ($entry['hours'] ?? 0);
        }
        $hours = round($hours, 9);
        if ($hours > 999999.99) throw new \InvalidArgumentException('Součet hodin výkazu je příliš vysoký.');
        $included = $settings['included_hours'];
        if ($settings['bands'] === [] && $included > 0 && $hours > $included) throw new \InvalidArgumentException('Výkaz překračuje zahrnuté hodiny; doplňte neomezené hodinové pásmo.');
        $rows = [];
        $format = static fn (float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        $make = static function (string $key, string $description, float $quantity, float $rate, float $from, ?float $to, bool $fixed = false) use ($report, $invoice, $settings, $period, $included, $vatRate, $format): array {
            if ($period === null && str_contains($description, '{obdobi}')) throw new \InvalidArgumentException('Popis obsahuje {obdobi}; vyplňte období účtování.');
            $minutes = !$fixed && abs($quantity * 60 - round($quantity * 60)) < 0.000001 ? (int) round($quantity * 60) : null;
            // Čas v celých minutách počítá TimeBilling přesně; ostatní množství má
            // stejná tři desetinná místa jako invoice_items.quantity.
            if (!$fixed && $minutes === null) {
                $quantity = round($quantity, 3);
                if ($quantity <= 0) throw new \InvalidArgumentException('Použité hodinové pásmo je menší než přesnost fakturačního množství (0,001 h).');
            }
            $description = strtr($description, ['{nazev}' => (string) ($report['title'] ?? $settings['name']), '{zakazka}' => (string) ($report['project_name'] ?? $invoice['project_name'] ?? ''),
                '{obdobi}' => $period ?? '', '{limit}' => $format($fixed ? $included : ($to ?? $from)), '{od}' => $format($from),
                '{do}' => $to === null ? '∞' : $format($to), '{hodiny}' => $format($fixed ? $included : $quantity)]);
            return ['billing_key' => $key, 'work_report_billing_key' => $key, 'linked_work_report_id' => isset($report['id']) ? (int) $report['id'] : null,
                'description' => $description, 'quantity' => $quantity, 'duration_minutes' => $minutes,
                'unit' => $fixed ? 'ks' : 'h', 'unit_price_without_vat' => $rate,
                'vat_rate_id' => $report['vat_rate_id'] ?? $invoice['default_vat_rate_id'] ?? null,
                'vat_rate_snapshot' => $vatRate, 'item_kind' => 'standard'];
        };
        if ($settings['fixed_fee'] !== null) $rows[] = $make('fixed', $settings['fixed_description'], 1, $settings['fixed_fee'], 0, $included, true);
        $from = $included;
        foreach ($settings['bands'] as $index => $band) {
            $used = max(0, min($hours, $band['up_to_hours'] ?? $hours) - $from);
            if ($used > 0.000000001) $rows[] = $make('band:' . $index, $band['description'], $used, $band['rate'], $from, $band['up_to_hours']);
            $from = $band['up_to_hours'] ?? $hours;
        }
        $computed = InvoiceMath::compute($rows, !empty($invoice['reverse_charge']), !empty($invoice['prices_include_vat']));
        foreach ($rows as $index => &$row) {
            $row['total_without_vat'] = $computed['items'][$index]['base'];
            $row['total_vat'] = $computed['items'][$index]['vat'];
            $row['total_with_vat'] = $computed['items'][$index]['with'];
            $row['total_amount'] = TimeBilling::invoiceAmount($row);
        }
        unset($row);
        if (array_sum(array_column($rows, 'total_amount')) > 9999999999.99) throw new \InvalidArgumentException('Cena výkazu je příliš vysoká.');
        $fingerprint = hash('sha256', json_encode([$settings, $period, $hours, $entries, $rows, (bool) ($invoice['prices_include_vat'] ?? false), (bool) ($invoice['reverse_charge'] ?? false)], JSON_THROW_ON_ERROR));
        return ['items' => $rows, 'total_hours' => $hours, 'included_hours' => min($hours, $included),
            'extra_hours' => max(0, $hours - $included), 'total_amount' => array_sum(array_column($rows, 'total_amount')),
            'totals' => $computed['totals'], 'vat_breakdown' => $computed['vat_breakdown'], 'fingerprint' => $fingerprint];
    }
}
