<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;

/** Převod hlavičky a položek Flexi na neměnný dokladový snapshot MyÚčta. */
final class AbraDocumentMapper
{
    /** @return array<string,mixed> */
    public function map(array $row, string $evidence): array
    {
        $kind = in_array($evidence, ['faktura-prijata', 'zavazek'], true) ? 'purchase' : 'issued';
        $key = AbraSource::sourceKey($row);
        $date = $this->firstDate($row, ['datVyst', 'datUcto', 'duzpUcto', 'duzpPuv']);
        $taxDate = $this->firstDate($row, ['duzpUcto', 'duzpPuv', 'datUcto', 'datVyst']);
        $dueDate = $this->firstDate($row, ['datSplat', 'datVyst', 'datUcto']);
        $currency = AbraSource::currency($row['mena'] ?? null);
        $blockers = [];
        if ($key === '') {
            $blockers[] = 'document_identity_missing';
        }
        if ($date === null || $dueDate === null) {
            $blockers[] = 'document_date_invalid';
        }
        if ($currency === '') {
            $blockers[] = 'document_currency_invalid';
        }

        $foreign = $currency !== 'CZK';
        $total = $this->totals($row, $foreign);
        if ($total === null) {
            $blockers[] = 'document_totals_invalid';
            $total = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'rounding' => 0.0];
        }

        $pricesIncludeVat = $this->pricesIncludeVat($row);
        $items = [];
        $warnings = ['document_snapshot_requires_review'];
        $taxClassificationRequiresReview = false;
        $deductions = [];
        $sourceItems = $this->items($row['polozkyDokladu'] ?? []);
        foreach ($sourceItems as $index => $item) {
            $mapped = $this->mapItem($item, $evidence, $foreign, $pricesIncludeVat, $index);
            if ($mapped === null) {
                $blockers[] = 'document_item_invalid';
                continue;
            }
            $items[] = $mapped;
            if ($mapped['vat_requires_review']) {
                $taxClassificationRequiresReview = true;
            }
            if ($kind === 'purchase' && ($mapped['vat_deduction'] !== 'none' || abs($mapped['vat']) >= 0.005)) {
                $deductions[$mapped['vat_deduction']] = true;
            }
            if ($mapped['quantity'] === 0.0 && ($mapped['base'] !== 0.0 || $mapped['vat'] !== 0.0 || $mapped['total'] !== 0.0)) {
                $warnings[] = 'document_zero_quantity_requires_review';
            }
        }
        if ($kind === 'purchase') {
            foreach ($items as &$item) {
                if (!$item['vat_requires_review'] || $item['source_vat_class'] !== '40-41'
                    || $item['vat_rate'] !== 0.0 || abs($item['base']) > 1.0
                    || $item['vat'] !== 0.0 || abs($item['total'] - $item['base']) > 0.02) {
                    continue;
                }
                $hasTaxedSibling = array_any($items, static fn (array $candidate): bool =>
                    $candidate['source_vat_class'] === '40-41'
                    && in_array($candidate['vat_classification'], ['40', '41'], true)
                    && $candidate['vat_rate'] > 0.0 && !$candidate['vat_requires_review']);
                if ($hasTaxedSibling) $item['vat_requires_review'] = false;
            }
            unset($item);
            $taxClassificationRequiresReview = array_any($items, static fn (array $item): bool => $item['vat_requires_review']);
        }
        if ($items === []) {
            $items[] = [
                'source_key' => $key . ':total',
                'description' => trim((string) ($row['popis'] ?? $row['kod'] ?? 'Doklad ABRA Flexi')),
                'quantity' => 1.0,
                'unit' => 'ks',
                'unit_price' => $pricesIncludeVat ? round($total['base'] + $total['vat'], 2) : $total['base'],
                'vat_rate' => abs($total['base']) >= 0.005 ? round($total['vat'] / $total['base'] * 100, 2) : 0.0,
                'base' => $total['base'],
                'vat' => $total['vat'],
                'total' => $total['total'],
                'vat_classification' => null,
                'vat_requires_review' => abs($total['vat']) >= 0.005,
                'vat_deduction' => 'none',
                'is_fixed_asset' => false,
                'vat_reverse' => false,
                'source_vat' => $total['vat'],
                'source_total' => $total['total'],
                'source_vat_class' => '',
                'import_tax_base_czk' => null,
                'import_tax_vat_czk' => null,
                'import_tax_excluded' => false,
            ];
            $warnings[] = 'document_items_missing_snapshot_total_used';
            $taxClassificationRequiresReview = abs($total['vat']) >= 0.005;
        }
        $legacyStoredTotal = round($total['base'] + $total['vat'], 2);
        $reverseItems = array_values(array_filter($items, static fn (array $item): bool => $item['vat_reverse'] ?? false));
        $reversePurchase = $kind === 'purchase' && $reverseItems !== []
            && !array_any($items, static fn (array $item): bool => !$item['vat_reverse']
                && ($item['vat_classification'] !== null || $item['vat_requires_review']
                    || abs($item['vat']) > 0.02 || abs($item['total'] - $item['base']) > 0.02))
            && abs($total['total'] - $total['base']) <= 0.02
            && abs(array_sum(array_column($items, 'base')) - $total['base']) <= 0.02
            && abs(array_sum(array_column($items, 'vat')) - $total['vat']) <= 0.02;
        if ($reversePurchase) {
            foreach ($items as &$item) {
                if ($item['vat_reverse'] && abs($item['total'] - $item['base'] - $item['vat']) > 0.02) {
                    $reversePurchase = false;
                    break;
                }
            }
            unset($item);
        }
        if ($reversePurchase) {
            $pricesIncludeVat = false;
            foreach ($items as &$item) {
                if ($item['vat_reverse']) {
                    $item['vat'] = 0.0;
                    $item['total'] = $item['base'];
                    $item['vat_requires_review'] = false;
                }
                $item['unit_price'] = $item['quantity'] === 0.0 ? $item['unit_price']
                    : round($item['base'] / $item['quantity'], 2);
            }
            unset($item);
            $total['vat'] = 0.0;
            $total['rounding'] = round($total['total'] - $total['base'], 2);
            $taxClassificationRequiresReview = false;
        }
        if (count($deductions) > 1) {
            $taxClassificationRequiresReview = true;
        }

        $sourceTotal = $total['total'];
        $sourceTotalMismatch = abs($total['rounding']) > 1.0;
        if ($sourceTotalMismatch) {
            $total['total'] = round($total['base'] + $total['vat'], 2);
            $total['rounding'] = 0.0;
            $warnings[] = 'document_source_total_mismatch_requires_review';
        }

        $itemBase = round(array_sum(array_column($items, 'base')), 2);
        $itemVat = round(array_sum(array_column($items, 'vat')), 2);
        $itemTotal = round(array_sum(array_column($items, 'total')), 2);
        if (abs($itemBase - $total['base']) > 0.02 || abs($itemVat - $total['vat']) > 0.02
            || abs(($itemTotal + $total['rounding']) - $total['total']) > 0.02) {
            $warnings[] = 'document_item_totals_differ';
        }
        if ($foreign) {
            $warnings[] = 'foreign_document_tax_requires_review';
        }
        if ($taxClassificationRequiresReview) {
            $warnings[] = 'document_tax_classification_requires_review';
        }

        $partner = $this->partner($row);
        $docCode = trim((string) ($row['kod'] ?? $row['cisDosle'] ?? $key));
        $variableSymbol = preg_replace('/\D+/', '', (string) ($row['varSym'] ?? $row['kod'] ?? ''));
        $type = mb_strtolower(AbraSource::reference($row['typDokl'] ?? null));
        $isCredit = str_contains($type, 'dobrop') || str_contains($type, 'oprav') || $total['total'] < 0;
        $isAdvance = str_contains($type, 'zaloh') || str_contains($type, 'záloh');
        // ZDD = daňový doklad k přijaté platbě zálohy (§ 28 odst. 9 ZDPH); k záloze ho váže
        // AbraDocumentRelationLinker podle společné platby.
        $isTaxDocument = $kind === 'purchase' && !$isAdvance && $type === 'zdd';
        $storno = AbraSource::bool($row['storno'] ?? null) === true;
        $posted = AbraSource::bool($row['zuctovano'] ?? null) === true;
        $readyStatus = $storno ? 'cancelled' : ($kind === 'issued' ? 'sent' : ($posted ? 'booked' : 'received'));
        $readyBookedAt = $posted && !$isAdvance && !$storno
            ? ($this->firstDate($row, ['datUcto', 'datVyst']) ?? $date) . ' 00:00:00' : null;
        $bookedAt = $taxClassificationRequiresReview ? null : $readyBookedAt;
        $exchangeRate = $currency === 'CZK' ? null : $this->exchangeRate($row);
        if ($foreign && $exchangeRate === null) {
            $blockers[] = 'document_exchange_rate_invalid';
        }

        return [
            'evidence' => $evidence,
            'kind' => $kind,
            'source_key' => $key,
            'source_hash' => AbraSource::documentHash($row),
            'year' => $date !== null ? (int) substr($date, 0, 4) : 0,
            'document_no' => mb_substr($docCode !== '' ? $docCode : $key, 0, 50),
            'vendor_document_no' => mb_substr(trim((string) ($row['cisDosle'] ?? $docCode ?: $key)), 0, 50),
            'variable_symbol' => mb_substr((string) $variableSymbol, 0, 20),
            'date' => $date,
            'tax_date' => $taxDate,
            'due_date' => $dueDate,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'prices_include_vat' => $pricesIncludeVat,
            'invoice_type' => $storno ? 'cancellation' : ($isAdvance ? 'proforma' : ($isCredit ? 'credit_note' : 'invoice')),
            'document_kind' => $isAdvance ? 'advance' : ($isCredit || $storno ? 'credit_note'
                : ($isTaxDocument ? 'tax_document' : 'invoice')),
            'status' => $taxClassificationRequiresReview && !$storno ? 'draft' : $readyStatus,
            'ready_status' => $readyStatus,
            'source_cancelled' => $storno,
            'ready_booked_at' => $readyBookedAt,
            'vat_country' => mb_strtoupper(AbraSource::reference($row['statDph'] ?? null)),
            'booked_at' => $bookedAt,
            'vat_deduction' => count($deductions) === 1 ? (string) array_key_first($deductions) : 'none',
            'reverse_charge' => $kind === 'issued'
                ? VatReturnLineClassifier::isDomesticReverseSale(array_column($items, 'vat_classification')) : $reversePurchase,
            'legacy_stored_total_with_vat' => $legacyStoredTotal,
            'is_fixed_asset' => $kind === 'purchase' && $items !== []
                && count(array_column($items, 'is_fixed_asset')) === count($items)
                && !in_array(false, array_column($items, 'is_fixed_asset'), true),
            'partner' => $partner,
            'settlement_account' => AbraSource::account($row['primUcet'] ?? null),
            'counter_account' => AbraSource::account($row['protiUcet'] ?? null),
            'document_type' => AbraSource::reference($row['typDokl'] ?? null),
            'title' => mb_substr(trim((string) ($row['popis'] ?? '')) ?: ($docCode !== '' ? $docCode : $key), 0, 255),
            'items' => $items,
            'total_without_vat' => $total['base'],
            'total_vat' => $total['vat'],
            'total_with_vat' => $total['total'],
            'source_total_with_vat' => $sourceTotal,
            'stored_total_with_vat' => $kind === 'purchase'
                ? round($total['total'] - $total['rounding'], 2) : $total['total'],
            'rounding' => $total['rounding'],
            'paid_total' => max(0.0, abs($total['total']) - abs((float) (AbraSource::number($foreign
                ? ($row['zbyvaUhraditMen'] ?? null) : ($row['zbyvaUhradit'] ?? null)) ?? abs($total['total'])))),
            'paid_at' => AbraSource::date($row['datUhr'] ?? null),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return array<string,mixed> */
    private function partner(array $row): array
    {
        $relation = AbraSource::relation($row['firma'] ?? $row['adresar'] ?? null);
        $name = trim((string) ($row['nazFirmy'] ?? $row['nazevFirmy'] ?? $row['firmaNazev'] ?? 'Neurčená protistrana'));
        $ic = preg_replace('/\D+/', '', (string) ($row['ic'] ?? '')) ?: null;
        $dic = mb_strtoupper(trim((string) ($row['dic'] ?? ''))) ?: null;
        $identity = $relation['key'] ?? ($ic ?: ($dic ?: hash('sha256', implode('|', [
            $name, (string) ($row['ulice'] ?? ''), (string) ($row['mesto'] ?? ''),
        ]))));
        return [
            'source_key' => mb_substr((string) $identity, 0, 190),
            'name' => mb_substr($name !== '' ? $name : 'Neurčená protistrana', 0, 190),
            'ic' => $ic,
            'dic' => $dic,
            'street' => mb_substr(trim((string) ($row['ulice'] ?? $row['firmaUlice'] ?? '')), 0, 190),
            'city' => mb_substr(trim((string) ($row['mesto'] ?? $row['firmaMesto'] ?? '')), 0, 120),
            'zip' => mb_substr(trim((string) ($row['psc'] ?? $row['firmaPsc'] ?? '')), 0, 10),
            'country' => mb_strtoupper(AbraSource::reference($row['stat'] ?? $row['firmaStat'] ?? 'CZ')) ?: 'CZ',
            'email' => mb_substr(trim((string) ($row['email'] ?? $row['firmaEmail'] ?? '')), 0, 190),
            'phone' => mb_substr(trim((string) ($row['tel'] ?? $row['firmaTel'] ?? '')), 0, 40),
        ];
    }

    /** @return array{base:float,vat:float,total:float,rounding:float}|null */
    private function totals(array $row, bool $foreign): ?array
    {
        $suffix = $foreign ? 'Men' : '';
        $base = $this->firstNumber($row, ['sumZklCelkem' . $suffix, 'sumZkl' . $suffix, 'sumBezDph' . $suffix]);
        $vat = $this->firstNumber($row, ['sumDphCelkem' . $suffix, 'sumDph' . $suffix]);
        $total = $this->firstNumber($row, ['sumCelkem' . $suffix, 'sumCelkemBezZaloh' . $suffix]);
        if ($base === null || $vat === null || $total === null) {
            return null;
        }
        return [
            'base' => round($base, 2),
            'vat' => round($vat, 2),
            'total' => round($total, 2),
            'rounding' => round($total - $base - $vat, 2),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        foreach (['polozka', 'polozkyDokladu', 'faktura-vydana-polozka', 'faktura-prijata-polozka', 'prodejka-polozka', 'zavazek-polozka'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $value = $value[$key];
                break;
            }
        }
        if (!array_is_list($value)) {
            $value = [$value];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    /** @return array<string,mixed>|null */
    private function mapItem(array $item, string $evidence, bool $foreign, bool $pricesIncludeVat, int $index): ?array
    {
        $suffix = $foreign ? 'Men' : '';
        $quantity = AbraSource::number($item['mnozMj'] ?? 1) ?? 1.0;
        $base = $this->firstNumber($item, ['sumZkl' . $suffix, 'sumZklCelkem' . $suffix]);
        $vat = $this->firstNumber($item, ['sumDph' . $suffix, 'sumDphCelkem' . $suffix]);
        $total = $this->firstNumber($item, ['sumCelkem' . $suffix]);
        if ($base === null || $vat === null || $total === null) {
            return null;
        }
        $rate = AbraSource::number($item['szbDph'] ?? null);
        if ($rate === null) {
            $rate = abs($base) >= 0.005 ? round($vat / $base * 100, 2) : 0.0;
        }
        $sourceVatClass = AbraSource::reference($item['clenDph'] ?? null);
        $classification = AbraVatMapper::map($sourceVatClass, $evidence, $rate, $base, $vat);
        $purchase = in_array($evidence, ['faktura-prijata', 'zavazek'], true);
        return [
            'source_key' => AbraSource::sourceKey($item, (string) ($index + 1)),
            'description' => trim((string) ($item['nazev'] ?? $item['popis'] ?? $item['typPolozkyK'] ?? 'Položka ABRA Flexi')),
            'quantity' => $quantity,
            'unit' => mb_substr(AbraSource::reference($item['mj'] ?? null) ?: 'ks', 0, 20),
            'unit_price' => $quantity === 0.0 ? ($this->firstNumber($item, ['cenaMj' . $suffix]) ?? 0.0)
                : round(($pricesIncludeVat ? $total : $base) / $quantity, 2),
            'vat_rate' => round($rate, 2),
            'base' => round($base, 2),
            'vat' => round($vat, 2),
            'total' => round($total, 2),
            'vat_classification' => $classification['code'],
            'vat_requires_review' => $classification['review'],
            'vat_deduction' => $classification['deduction'],
            'is_fixed_asset' => $classification['fixed_asset'],
            'vat_reverse' => $classification['reverse'],
            'source_vat' => round($vat, 2),
            'source_total' => round($total, 2),
            'source_vat_class' => $sourceVatClass,
            'import_tax_base_czk' => $purchase && $foreign ? AbraSource::number($item['sumZkl'] ?? null) : null,
            'import_tax_vat_czk' => $purchase && $foreign ? AbraSource::number($item['sumDph'] ?? null) : null,
            'import_tax_excluded' => $purchase && in_array($sourceVatClass, ['000P', '000U'], true),
            'base_czk' => AbraSource::number($item['sumZkl'] ?? null),
            'total_czk' => AbraSource::number($item['sumCelkem'] ?? null),
        ];
    }

    private function pricesIncludeVat(array $row): bool
    {
        $type = mb_strtolower(AbraSource::reference($row['typCenyDphK'] ?? null));
        return str_contains($type, 'sdph') || str_contains($type, 's_dph');
    }

    private function exchangeRate(array $row): ?float
    {
        $rate = AbraSource::number($row['kurz'] ?? null);
        $units = AbraSource::number($row['kurzMnozstvi'] ?? 1) ?? 1.0;
        return $rate !== null && $rate > 0 && $units > 0 ? round($rate / $units, 6) : null;
    }

    /** @param list<string> $fields */
    private function firstDate(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            $date = AbraSource::date($row[$field] ?? null);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    /** @param list<string> $fields */
    private function firstNumber(array $row, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $value = AbraSource::number($row[$field]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }
}
