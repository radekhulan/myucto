<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Invoice\UnitPricePrecision;

final class AbraCatalogMapper
{
    /** @return array<string,mixed> */
    public function map(array $row): array
    {
        $key = AbraSource::sourceKey($row);
        $sku = trim((string) ($row['kod'] ?? ''));
        $name = trim((string) ($row['nazev'] ?? ''));
        $unit = AbraSource::reference($row['mj1'] ?? null);
        $type = match (AbraSource::reference($row['typZasobyK'] ?? null)) {
            'typZasoby.zbozi' => 'goods',
            'typZasoby.material' => 'material',
            'typZasoby.vyrobek' => 'product',
            default => null,
        };
        $price = AbraSource::number($row['cenaZaklBezDph'] ?? null);
        $ean = trim((string) ($row['eanKod'] ?? ''));
        $note = trim((string) ($row['poznam'] ?? ''));
        $blocked = [];
        $warnings = [];
        if ($key === '' || $sku === '' || mb_strlen($sku) > 50 || $name === '' || mb_strlen($name) > 255) {
            $blocked[] = 'catalog_identity_invalid';
        }
        if ($type === null) $blocked[] = 'catalog_type_unsupported';
        if ($unit === '') {
            $unit = 'ks';
            $warnings[] = 'catalog_unit_defaulted';
        }
        if (mb_strlen($unit) > 20) $blocked[] = 'catalog_unit_invalid';
        if (mb_strlen($ean) > 20) {
            $ean = '';
            $warnings[] = 'catalog_ean_skipped';
        }
        if ($price === null || abs($price) > 9999999999.99) {
            $price = null;
            $warnings[] = 'catalog_price_invalid';
        } elseif (!UnitPricePrecision::isWithinPrecision($price)) {
            $warnings[] = 'catalog_price_rounded';
        }
        $stocked = AbraSource::bool($row['skladove'] ?? null);
        if ($stocked === null) $warnings[] = 'catalog_stock_flag_unknown';
        $vatType = AbraSource::reference($row['typSzbDphK'] ?? null);
        $vatRate = match ($vatType) {
            'typSzbDph.dphZakl' => 21.0,
            'typSzbDph.dphSniz' => 12.0,
            'typSzbDph.dphNul' => 0.0,
            default => null,
        };
        if ($vatRate === null) $warnings[] = 'catalog_vat_rate_requires_review';
        $card = [
            'source_key' => $key, 'sku' => $sku, 'name' => $name, 'item_type' => $type,
            'unit' => $unit, 'tracking_mode' => AbraSource::bool($row['evidVyrCis'] ?? null) === true ? 'serial'
                : ((AbraSource::bool($row['evidSarze'] ?? null) === true || AbraSource::bool($row['evidExpir'] ?? null) === true) ? 'lot' : 'none'),
            'ean' => $ean === '' ? null : $ean, 'sale_price_without_vat' => $price === null ? null : self::unitPrice($price),
            'min_qty' => null, 'is_active' => !is_numeric($row['platiDo'] ?? null) || (int) $row['platiDo'] >= (int) date('Y'),
            'is_stocked' => $stocked ?? false, 'note' => $note === '' ? null : mb_substr($note, 0, 2000),
            'weight_g' => null, 'intrastat_cn8_code' => null, 'intrastat_net_mass_kg' => null,
            'intrastat_supplementary_unit' => null, 'intrastat_supplementary_unit_coefficient' => null,
            'vat_rate_percent' => $vatRate,
        ];
        $priceRows = [];
        $legacyPriceRows = [];
        $seenCurrencies = [];
        $sourceRate = AbraSource::number($row['szbDph'] ?? null);
        $priceVatMode = AbraSource::reference($row['typCenyDphK'] ?? null);
        $customerPrices = $row['odberatele'] ?? [];
        if (!is_array($customerPrices)) $customerPrices = [];
        if ($customerPrices !== [] && !array_is_list($customerPrices)) $customerPrices = [$customerPrices];
        foreach ($customerPrices as $priceRow) {
            if (!is_array($priceRow) || !empty($priceRow['firma']) || !empty($priceRow['skupCen'])
                || !empty($priceRow['stredisko'])) continue;
            $currency = AbraSource::currency($priceRow['mena'] ?? null);
            $amount = AbraSource::number($priceRow['prodejCena'] ?? null);
            if (!in_array($currency, ['EUR', 'GBP', 'USD'], true) || $amount === null || $amount < 0
                || $amount > 9999999999.99 || isset($seenCurrencies[$currency])) {
                $warnings[] = 'catalog_currency_price_requires_review';
                continue;
            }
            $seenCurrencies[$currency] = true;
            $legacyAmount = $amount;
            if ($currency !== 'USD' && $sourceRate !== null && $sourceRate >= 0 && $sourceRate < 100) {
                $legacyAmount /= 1 + $sourceRate / 100;
            }
            // Tvar, který zapisovaly dřívější převody (na haléře); podle něj se uložená cena
            // pozná jako nedotčená a smí se opravit.
            $legacyPriceRows[$currency] = number_format(round($legacyAmount, 2), 2, '.', '');
            if ($priceVatMode === 'typCeny.sDph') {
                if ($sourceRate === null || $sourceRate < 0 || $sourceRate >= 100) {
                    $warnings[] = 'catalog_currency_price_requires_review';
                    continue;
                }
                $amount /= 1 + $sourceRate / 100;
            } elseif ($priceVatMode !== 'typCeny.bezDph') {
                $warnings[] = 'catalog_currency_price_requires_review';
                continue;
            }
            $priceRows[$currency] = self::unitPrice($amount);
        }
        return ['card' => $card, 'source_key' => $key, 'source_hash' => AbraSource::hash($card),
            'price_rows' => $priceRows, 'legacy_price_rows' => $legacyPriceRows,
            'blockers' => $blocked, 'warnings' => $warnings];
    }

    /** Cena na šest desetinných míst ve tvaru, jaký vrací čtení z databáze („12.50", „0.0108"). */
    private static function unitPrice(float $price): string
    {
        return (string) UnitPricePrecision::toDecimalString(number_format($price, UnitPricePrecision::DECIMALS, '.', ''));
    }
}
