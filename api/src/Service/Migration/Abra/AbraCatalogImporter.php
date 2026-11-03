<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Migration\Shared\MigratedInventoryException;
use MyInvoice\Service\Migration\Shared\MigratedInventoryWriter;

final class AbraCatalogImporter
{
    private array $vatRates = [];
    private array $configuredCurrencies = [];

    public function __construct(
        private readonly Connection $db,
        private readonly AbraImportRepository $imports,
        private readonly MigratedInventoryWriter $writer,
        private readonly StockItemPriceRepository $prices,
        private readonly StockCurrencyRepository $currencies,
    ) {}

    /** @param list<array<string,mixed>> $rows @param callable():bool $cancelled @return array<string,mixed> */
    public function importPage(int $supplierId, array $rows, callable $cancelled): array
    {
        if (!$this->writer->stockEnabled($supplierId)) {
            throw new AbraException('stock_module_missing', 'Cílová firma nemá zapnutý modul Sklad.');
        }
        $report = ['created' => 0, 'skipped' => 0, 'changed' => 0, 'failed' => 0, 'warnings' => []];
        $warnings = [];
        $mapper = new AbraCatalogMapper();
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        $nested ? $pdo->exec('SAVEPOINT abra_catalog_page') : $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($cancelled()) throw new AbraException('cancelled', 'Převod byl zrušen.');
                $plan = $mapper->map($row);
                foreach ($plan['warnings'] as $warning) $warnings[$warning] = true;
                if ($plan['blockers'] !== []) {
                    foreach ($plan['blockers'] as $blocker) $warnings[$blocker] = true;
                    $report['failed']++;
                    continue;
                }
                $card = $plan['card'];
                $card['vat_rate_id'] = $card['vat_rate_percent'] === null ? null : $this->vatRateId($card['vat_rate_percent']);
                if ($card['vat_rate_percent'] !== null && $card['vat_rate_id'] === null) $warnings['catalog_vat_rate_requires_review'] = true;
                $mapped = $this->imports->lookup($supplierId, 'cenik', $plan['source_key']);
                if ($mapped !== null) {
                    if ($mapped['target_type'] !== 'stock_item' || !hash_equals((string) $mapped['source_hash'], $plan['source_hash'])) {
                        $report['changed']++;
                        $warnings['catalog_source_changed_requires_review'] = true;
                        continue;
                    }
                    try {
                        $this->writer->verifyItem($supplierId, (int) $mapped['target_id'], $card);
                        if (!$this->ensurePrices($supplierId, (int) $mapped['target_id'], $card,
                            $plan['price_rows'], $plan['legacy_price_rows'])) {
                            $report['changed']++;
                            $warnings['catalog_target_changed_requires_review'] = true;
                            continue;
                        }
                        $report['skipped']++;
                    } catch (MigratedInventoryException) {
                        $report['changed']++;
                        $warnings['catalog_target_changed_requires_review'] = true;
                    }
                    continue;
                }
                try {
                    $result = $this->writer->item($supplierId, $card);
                    if (!$this->ensurePrices($supplierId, $result['id'], $card, $plan['price_rows'])) {
                        $report['changed']++;
                        $warnings['catalog_target_changed_requires_review'] = true;
                        continue;
                    }
                    $this->imports->remember($supplierId, 'cenik', $plan['source_key'], $plan['source_hash'],
                        'stock_item', $result['id'], null);
                    $report[$result['created'] ? 'created' : 'skipped']++;
                } catch (MigratedInventoryException) {
                    $report['failed']++;
                    $warnings['catalog_target_conflict'] = true;
                }
            }
            $nested ? $pdo->exec('RELEASE SAVEPOINT abra_catalog_page') : $pdo->commit();
        } catch (\Throwable $error) {
            if (TransactionFailure::rollbackIfAborted($pdo, $error)) {
                throw $error;
            }
            $this->configuredCurrencies = [];
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT abra_catalog_page');
                $pdo->exec('RELEASE SAVEPOINT abra_catalog_page');
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $report['warnings'] = array_keys($warnings);
        return $report;
    }

    /** @param array<string,mixed> $card @param array<string,string> $foreignPrices @param array<string,string> $legacyPrices */
    private function ensurePrices(int $supplierId, int $itemId, array $card, array $foreignPrices,
        array $legacyPrices = []): bool
    {
        $amounts = $foreignPrices;
        if ($card['sale_price_without_vat'] !== null && (float) $card['sale_price_without_vat'] >= 0) {
            $amounts = ['CZK' => $card['sale_price_without_vat']] + $amounts;
        }
        $missing = [];
        $repairs = [];
        foreach ($amounts as $currency => $amount) {
            $existing = $this->prices->findByCurrency($supplierId, $itemId, $currency);
            if ($existing === null) {
                $missing[$currency] = $amount;
            } elseif ($existing['price_mode'] !== 'fixed' || !self::samePrice($existing['fixed_price'], $amount)
                || !self::samePrice($existing['computed_price'], $amount)) {
                // Opravit se smí jen cena, kterou zapsal dřívější převod: cizoměnová podle
                // starého výpočtu DPH, nebo jakákoli zaokrouhlená na haléře, dokud převody
                // nedržely šest desetinných míst.
                $previous = [number_format(round((float) $amount, 2), 2, '.', '')];
                if ($currency !== 'CZK' && isset($legacyPrices[$currency])) $previous[] = $legacyPrices[$currency];
                $written = false;
                foreach ($previous as $candidate) {
                    $written = $written || (self::samePrice($existing['fixed_price'], $candidate)
                        && self::samePrice($existing['computed_price'], $candidate));
                }
                if (!$written || $existing['price_mode'] !== 'fixed'
                    || $existing['is_manual_override'] || $existing['use_pricing_rules']
                    || $existing['rounding'] !== 'none') return false;
                $repairs[$currency] = [(int) $existing['id'], $amount];
            }
        }
        foreach ($repairs as $currency => [$priceId, $amount]) {
                $this->prices->upsert($supplierId, $itemId, $currency, [
                    'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => $amount,
                    'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => false,
                ]);
                $this->prices->updateComputed($supplierId, $priceId, $amount,
                    null, null, date('Y-m-d H:i:s'));
        }
        foreach ($missing as $currency => $amount) {
            $this->ensureCurrency($supplierId, $currency);
            $this->prices->upsert($supplierId, $itemId, $currency, [
                'price_mode' => 'fixed', 'markup_pct' => null, 'fixed_price' => $amount,
                'rounding' => 'none', 'is_manual_override' => false, 'use_pricing_rules' => false,
            ]);
            $created = $this->prices->findByCurrency($supplierId, $itemId, $currency);
            $this->prices->updateComputed($supplierId, (int) $created['id'], $amount, null, null, date('Y-m-d H:i:s'));
        }
        return true;
    }

    private static function samePrice(mixed $stored, string $price): bool
    {
        return $stored !== null && is_numeric($stored) && bccomp((string) $stored, $price, UnitPricePrecision::DECIMALS) === 0;
    }

    private function ensureCurrency(int $supplierId, string $currency): void
    {
        if (isset($this->configuredCurrencies[$supplierId][$currency])) return;
        if ($this->currencies->findByCode($supplierId, $currency) === null) {
            $this->currencies->insert($supplierId, [
                'code' => $currency,
                'name' => ['CZK' => 'Česká koruna', 'EUR' => 'Euro', 'GBP' => 'Britská libra', 'USD' => 'Americký dolar'][$currency],
                'symbol' => ['CZK' => 'Kč', 'EUR' => '€', 'GBP' => '£', 'USD' => '$'][$currency],
                'is_default' => $currency === 'CZK' && $this->currencies->listForSupplier($supplierId) === [],
            ]);
        }
        $this->configuredCurrencies[$supplierId][$currency] = true;
    }

    private function vatRateId(float $rate): ?int
    {
        $key = number_format($rate, 2, '.', '');
        if (array_key_exists($key, $this->vatRates)) return $this->vatRates[$key];
        $date = date('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT id FROM vat_rates WHERE country = "CZ" AND ABS(rate_percent - ?) < 0.001
            AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$rate, $date, $date]);
        $id = $stmt->fetchColumn();
        return $this->vatRates[$key] = $id === false ? null : (int) $id;
    }
}
