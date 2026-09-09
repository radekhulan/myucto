<?php

declare(strict_types=1);

/**
 * Reprodukovatelný syntetický benchmark katalogu skladu/e-shopu.
 *
 * Vždy pracuje jen s databází končící na _test a po sobě smaže throwaway
 * supplier. Katalog seeduje SQL (nejde o skladovou knihu); stav skladu vzniká
 * výhradně přes StockDocumentService v pěti hromadných příjemkách.
 *
 * Použití: php api/bin/benchmark-stock-catalog.php --size=10000
 *          php api/bin/benchmark-stock-catalog.php --size=30000 --iterations=25
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Service\Eshop\Pricing\CatalogPriceJobService;
use MyInvoice\Service\Eshop\CatalogReadService;
use MyInvoice\Service\Stock\StockDocumentService;

const BENCH_PREFIX = 'BENCH-CATALOG-';

/** @return array{size:int,iterations:int,max_batches:int} */
function options(array $argv): array
{
    $values = ['size' => 100, 'iterations' => 15, 'max_batches' => 100];
    foreach ($argv as $arg) {
        if (preg_match('/^--(size|iterations|max-batches)=(\d+)$/D', $arg, $m) === 1) {
            if ($m[1] === 'max-batches') {
                $m[1] = 'max_batches';
            }
            $values[$m[1]] = (int) $m[2];
        }
    }
    if ($values['size'] < 1 || $values['size'] > 30_000) {
        throw new InvalidArgumentException('--size musí být 1 až 30000.');
    }
    if ($values['iterations'] < 3 || $values['iterations'] > 100) {
        throw new InvalidArgumentException('--iterations musí být 3 až 100.');
    }
    if ($values['max_batches'] < 1 || $values['max_batches'] > 100) {
        throw new InvalidArgumentException('--max-batches musí být 1 až 100.');
    }
    return $values;
}

/** @param list<float> $durations @return array{p50:float,p95:float,min:float,max:float} */
function percentile(array $durations): array
{
    sort($durations, SORT_NUMERIC);
    $at = static fn (float $p): float => $durations[(int) ceil((count($durations) - 1) * $p)];
    return ['p50' => round($at(0.50), 3), 'p95' => round($at(0.95), 3), 'min' => round($durations[0], 3), 'max' => round($durations[array_key_last($durations)], 3)];
}

function sessionQuestions(PDO $pdo): int
{
    $stmt = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'");
    return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['Value'] ?? 0);
}

/** @return array{duration_ms:float,payload_bytes:int,rows:int,total:int,sql_questions:int} */
function measureList(PDO $pdo, StockItemRepository $items, int $sid, array $filters, int $iterations): array
{
    $samples = [];
    $payload = 0;
    $rows = 0;
    $total = 0;
    $questionsBefore = sessionQuestions($pdo);
    for ($i = 0; $i < $iterations; $i++) {
        $started = hrtime(true);
        [$result, $total] = $items->listPaged($sid, $filters, 50, 0);
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        $rows = count($result);
        $payload = strlen(json_encode(['data' => $result, 'total' => $total], JSON_THROW_ON_ERROR));
    }
    return percentile($samples) + ['payload_bytes' => $payload, 'rows' => $rows, 'total' => $total, 'sql_questions' => sessionQuestions($pdo) - $questionsBefore];
}

/** @return array{p50:float,p95:float,min:float,max:float,payload_bytes:int,rows:int,sql_questions:int} */
function measureRead(PDO $pdo, CatalogReadService $read, int $sid, array $body, int $iterations): array
{
    $samples = []; $payload = 0; $rows = 0; $before = sessionQuestions($pdo);
    for ($i = 0; $i < $iterations; $i++) {
        $started = hrtime(true);
        $response = $read->products($sid, $body);
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        $rows = count($response['items']);
        $payload = strlen(json_encode($response, JSON_THROW_ON_ERROR));
    }
    return percentile($samples) + ['payload_bytes' => $payload, 'rows' => $rows, 'sql_questions' => sessionQuestions($pdo) - $before];
}

function insertRows(PDO $pdo, string $sql, array $rows, int $chunk = 500): void
{
    foreach (array_chunk($rows, $chunk) as $batch) {
        $params = [];
        foreach ($batch as $row) {
            array_push($params, ...$row);
        }
        $pdo->prepare($sql . implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, count($batch[0]), '?')) . ')')))->execute($params);
    }
}

$result = ['status' => 'failed'];
$sid = 0;
try {
    $opt = options($argv);
    $container = Bootstrap::buildContainer();
    $pdo = $container->get(Connection::class)->pdo();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!str_ends_with($database, '_test')) {
        throw new RuntimeException("Benchmark odmítnut: databáze '{$database}' nekončí na _test.");
    }
    foreach (['stock_items', 'stock_levels', 'stock_item_prices', 'stock_item_promo_prices', 'catalog_jobs', 'stock_locales', 'stock_currencies'] as $table) {
        if ($pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn() === false) {
            throw new RuntimeException("Benchmark vyžaduje migrovanou tabulku {$table}.");
        }
    }
    $country = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
    $currency = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $vat = (int) ($pdo->query('SELECT id FROM vat_rates WHERE is_reverse_charge = 0 ORDER BY is_default DESC, id LIMIT 1')->fetchColumn() ?: 0);
    $user = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($country === 0 || $currency === 0 || $vat === 0 || $user === 0) {
        throw new RuntimeException('Chybí minimální fixture data (country/currency/vat/user).');
    }

    $stamp = bin2hex(random_bytes(5));
    $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, is_vat_payer, stock_enabled, stock_auto_issue) VALUES (?, "Benchmark 1", "Praha", "11000", ?, ?, ?, ?, "tax_evidence", 1, 1, 0)')
        ->execute([BENCH_PREFIX . $stamp, $country, 'benchmark-' . $stamp . '@example.test', $currency, $vat]);
    $sid = (int) $pdo->lastInsertId();
    $started = hrtime(true);

    insertRows($pdo, 'INSERT INTO warehouses (supplier_id, code, name, is_default, is_active) VALUES ', array_map(
        static fn (int $n): array => [$sid, 'W' . $n, 'Benchmark sklad ' . $n, $n === 1 ? 1 : 0, 1], range(1, 5)
    ));
    $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE supplier_id = ? ORDER BY id'); $whStmt->execute([$sid]); $warehouses = array_map('intval', $whStmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($warehouses) !== 5) { throw new RuntimeException('Benchmark nevytvořil pět skladů.'); }
    insertRows($pdo, 'INSERT INTO stock_locales (supplier_id, code, name, display_order, is_default) VALUES ', [[$sid, 'cs', 'Čeština', 1, 1], [$sid, 'en', 'English', 2, 0], [$sid, 'de', 'Deutsch', 3, 0]]);
    insertRows($pdo, 'INSERT INTO stock_currencies (supplier_id, code, name, symbol, display_order, is_default) VALUES ', [[$sid, 'CZK', 'Česká koruna', 'Kč', 1, 1], [$sid, 'EUR', 'Euro', '€', 2, 0], [$sid, 'USD', 'Americký dolar', '$', 3, 0]]);
    $attributes = [];
    for ($a = 1; $a <= 20; $a++) { $attributes[] = [$sid, 'ATTR' . str_pad((string) $a, 2, '0', STR_PAD_LEFT), 'Benchmark atribut ' . $a, 'number', 1]; }
    insertRows($pdo, 'INSERT INTO stock_attributes (supplier_id, code, name, data_type, is_filterable) VALUES ', $attributes);
    $attrStmt = $pdo->prepare('SELECT id FROM stock_attributes WHERE supplier_id = ? ORDER BY id'); $attrStmt->execute([$sid]); $attributeIds = array_map('intval', $attrStmt->fetchAll(PDO::FETCH_COLUMN));

    $items = [];
    for ($i = 1; $i <= $opt['size']; $i++) {
        $items[] = [$sid, sprintf('BENCH-%05d', $i), 'Benchmark produkt ' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'goods', 'ks', '859000' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $vat, 1, 1, 1, 'weighted_avg'];
    }
    insertRows($pdo, 'INSERT INTO stock_items (supplier_id, sku, name, item_type, unit, ean, vat_rate_id, is_active, export_eshop, is_stocked, pricing_base) VALUES ', $items, 250);
    $idStmt = $pdo->prepare('SELECT id FROM stock_items WHERE supplier_id = ? ORDER BY id'); $idStmt->execute([$sid]); $itemIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
    $promoRows = [];
    foreach (array_slice($itemIds, 0, min(10, count($itemIds))) as $id) {
        $promoRows[] = [$sid, $id, 'CZK', '79.00', 'Benchmark promo', 'limited', '100.000', 1];
    }
    insertRows($pdo, 'INSERT INTO stock_item_promo_prices (supplier_id, stock_item_id, currency_code, promo_price, label, qty_mode, qty_limit, is_active) VALUES ', $promoRows);
    // Drž jen malý segment pomocných řádků. 30k × (3 i18n + 3 ceny + 20 atributů)
    // by jinak v PHP vzniklo 780 tisíc polí a zkreslilo benchmark limitem paměti.
    foreach (array_chunk($itemIds, 250, true) as $chunk) {
        $i18n = $prices = $values = [];
        foreach ($chunk as $index => $id) {
            foreach (['cs' => 'Produkt', 'en' => 'Product', 'de' => 'Produkt'] as $locale => $label) { $i18n[] = [$sid, $id, $locale, $label . ' ' . ($index + 1), 'bench-' . $locale . '-' . ($index + 1)]; }
            foreach (['CZK', 'EUR', 'USD'] as $code) { $prices[] = [$sid, $id, $code, 'fixed', null, (string) (100 + ($index % 100)), 'none', 0]; }
            foreach ($attributeIds as $a => $attributeId) { $values[] = [$sid, $id, $attributeId, ($index + $a) % 100]; }
        }
        insertRows($pdo, 'INSERT INTO stock_item_i18n (supplier_id, stock_item_id, locale, name, seo_slug) VALUES ', $i18n, 200);
        insertRows($pdo, 'INSERT INTO stock_item_prices (supplier_id, stock_item_id, currency_code, price_mode, markup_pct, fixed_price, rounding, is_manual_override) VALUES ', $prices, 200);
        insertRows($pdo, 'INSERT INTO stock_item_attribute_values (supplier_id, stock_item_id, attribute_id, value_num) VALUES ', $values, 250);
    }

    $documents = $container->get(StockDocumentService::class);
    foreach ($warehouses as $warehouseIndex => $warehouseId) {
        // Při 30k řádcích by jediný INSERT dokladu překročil MariaDB limit 65 535
        // placeholderů. Každý blok je přitom stále běžný hromadný doklad vytvořený
        // a zaúčtovaný přes doménovou službu, ne přímý zápis do stock_levels.
        foreach (array_chunk($itemIds, 1_000, true) as $documentIndex => $itemChunk) {
            $lines = [];
            foreach ($itemChunk as $itemIndex => $itemId) { $lines[] = ['stock_item_id' => $itemId, 'qty' => '1.000', 'unit_cost' => number_format(10 + ($itemIndex % 50), 6, '.', '')]; }
            $doc = $documents->create($sid, ['doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouseId, 'doc_date' => '2099-01-15', 'description' => 'Benchmark seed sklad ' . ($warehouseIndex + 1) . '/' . ($documentIndex + 1), 'lines' => $lines], $user);
            $documents->post($sid, (int) $doc['id'], $user);
        }
    }
    $seedMs = (hrtime(true) - $started) / 1_000_000;

    $repo = $container->get(StockItemRepository::class);
    $lists = [
        'name_sort' => measureList($pdo, $repo, $sid, ['sort' => 'name', 'direction' => 'asc'], $opt['iterations']),
        'qty_sort' => measureList($pdo, $repo, $sid, ['sort' => 'qty', 'direction' => 'desc'], $opt['iterations']),
        'search_filter' => measureList($pdo, $repo, $sid, ['q' => '0001', 'sort' => 'sku'], $opt['iterations']),
        'attribute_filter' => measureList($pdo, $repo, $sid, ['attribute_filters' => [['attribute_id' => $attributeIds[0], 'value_num_min' => 40, 'value_num_max' => 60]], 'sort' => 'name'], $opt['iterations']),
    ];
    if ($lists['name_sort']['total'] !== $opt['size'] || $lists['qty_sort']['total'] !== $opt['size'] || $lists['attribute_filter']['total'] <= 0) { throw new RuntimeException('Kontrola count/selection benchmarku selhala.'); }

    $read = $container->get(CatalogReadService::class);
    $readSets = [];
    foreach ([50, 500] as $batch) {
        if ($batch > count($itemIds)) { continue; }
        $ids = array_slice($itemIds, 0, $batch);
        $readSets['batch_' . $batch . '_selective'] = measureRead($pdo, $read, $sid, [
            'ids' => $ids, 'fields' => ['sku', 'name', 'is_active'], 'locales' => ['cs'], 'currencies' => ['CZK'],
        ], $opt['iterations']);
        $readSets['batch_' . $batch . '_full'] = measureRead($pdo, $read, $sid, [
            'ids' => $ids, 'fields' => ['sku', 'name', 'item_type', 'unit', 'ean', 'manufacturer_id', 'vat_rate_id', 'is_active', 'is_stocked', 'export_eshop', 'min_qty', 'weight_g', 'warranty_months', 'delivery_days', 'i18n', 'categories', 'tag_ids', 'attributes', 'fees', 'media', 'prices', 'availability'],
            'locales' => ['cs', 'en', 'de'], 'currencies' => ['CZK', 'EUR', 'USD'], 'warehouse_ids' => [$warehouses[0], $warehouses[1], $warehouses[2], $warehouses[3], $warehouses[4]],
        ], $opt['iterations']);
    }
    $priceRequests = [];
    foreach (array_slice($itemIds, 0, min(50, count($itemIds))) as $index => $id) { $priceRequests[] = ['id' => $id, 'qty' => $index % 3 === 0 ? '2.500' : '1.000']; }
    $priceSamples = [];
    $priceQuestions = sessionQuestions($pdo);
    foreach (['CZK', 'EUR', 'USD'] as $currencyCode) {
        $samples = [];
        for ($i = 0; $i < $opt['iterations']; $i++) { $at = hrtime(true); $priceResponse = $read->prices($sid, ['items' => $priceRequests, 'currency' => $currencyCode, 'on_date' => '2099-01-15']); $samples[] = (hrtime(true) - $at) / 1_000_000; }
        $priceSamples[$currencyCode] = percentile($samples) + ['payload_bytes' => strlen(json_encode($priceResponse, JSON_THROW_ON_ERROR)), 'rows' => count($priceResponse['items'])];
    }
    $priceSamples['sql_questions'] = sessionQuestions($pdo) - $priceQuestions;

    // Příjemky v seed fázi korektně frontují vlastní price_recompute úlohy. Pro
    // měření explicitně vytvořené dávky je odstraníme jako přípravu throwaway
    // tenanta, jinak tick() vezme nejstarší seed job a checkpoint patří jiné úloze.
    $pdo->prepare('DELETE FROM catalog_jobs WHERE supplier_id = ?')->execute([$sid]);
    $jobs = $container->get(CatalogPriceJobService::class);
    $jobQuestionsBefore = sessionQuestions($pdo);
    $jobStart = hrtime(true); $jobId = $jobs->enqueue($sid); $ticks = 0; $job = null;
    do {
        $job = $jobs->tick($sid, $opt['max_batches']);
        $ticks++;
        if ($ticks > (int) ceil($opt['size'] / (100 * $opt['max_batches'])) + 2) {
            throw new RuntimeException('Dávkové ceny nepřiměřeně opakují běhy.');
        }
    } while ($job !== null && $job['status'] !== 'completed');
    $jobMs = (hrtime(true) - $jobStart) / 1_000_000;
    if ($jobId <= 0 || $job === null || $job['status'] !== 'completed' || (int) $job['checkpoint'] !== $opt['size'] || (int) $job['total'] !== $opt['size']) {
        throw new RuntimeException('Dávkové ceny nedokončily korektně: ' . json_encode([
            'job_id' => $jobId,
            'ticks' => $ticks,
            'status' => $job['status'] ?? null,
            'checkpoint' => $job['checkpoint'] ?? null,
            'total' => $job['total'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }
    if (!empty($job['report']['failed_items']) || !empty($job['report']['stale_items'])) {
        throw new RuntimeException('Přepočet benchmarku obsahuje neúspěšné položky.');
    }

    $result = ['status' => 'ok', 'database' => $database, 'hardware' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'memory_limit' => ini_get('memory_limit')], 'config' => ['sku' => $opt['size'], 'warehouses' => 5, 'locales' => 3, 'attributes' => 20, 'currencies' => 3, 'iterations' => $opt['iterations'], 'price_job_max_batches' => $opt['max_batches'], 'sql_observer' => 'MariaDB SESSION STATUS Questions delta per measured block'], 'seed_ms' => round($seedMs, 3), 'list_filter_sort' => $lists, 'catalog_read' => ['products' => $readSets, 'prices_with_quantities' => $priceSamples, 'promo_sample_items' => count($promoRows)], 'price_job' => ['job_id' => $jobId, 'ticks' => $ticks, 'duration_ms' => round($jobMs, 3), 'sql_questions' => sessionQuestions($pdo) - $jobQuestionsBefore, 'status' => $job['status'], 'checkpoint' => (int) $job['checkpoint'], 'total' => (int) $job['total']], 'max_memory_bytes' => memory_get_peak_usage(true), 'correctness' => ['count' => $opt['size'], 'selection' => 'name/qty=' . $opt['size'] . ', attribute>0']];
} catch (Throwable $e) {
    $result = ['status' => 'failed', 'error' => $e->getMessage(), 'max_memory_bytes' => memory_get_peak_usage(true)];
} finally {
    if ($sid > 0 && isset($pdo)) {
        try {
            $pdo->prepare('DELETE FROM stock_documents WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM stock_takes WHERE supplier_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        } catch (Throwable $cleanup) {
            $result['status'] = 'failed';
            $result['cleanup_error'] = $cleanup->getMessage();
        }
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($result['status'] === 'ok' ? 0 : 1);
