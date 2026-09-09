<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\CatalogJobItemRepository;

final class PriceMatrixCsv
{
    public const MAX_BYTES = 50_000_000;
    public const MAX_ROWS = 90000;

    private const HEADERS = [
        'item_id', 'sku', 'name', 'currency_code', 'operation', 'price_mode', 'markup_pct',
        'fixed_price', 'rounding', 'is_manual_override', 'use_pricing_rules', 'price',
        'cost_czk', 'margin_pct', 'rate', 'profile_id', 'rule_id',
    ];

    public function __construct(private readonly CatalogJobItemRepository $items) {}

    /** @return \Generator<int,string> */
    public function export(int $supplierId, int $jobId, string $view): \Generator
    {
        if (!in_array($view, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Neplatná podoba CSV exportu.');
        }
        yield "\xEF\xBB\xBF" . self::row(self::HEADERS);
        $after = 0;
        $rowCount = 0;
        do {
            $batch = $this->items->batch($supplierId, $jobId, $after, 500);
            foreach ($batch as $entry) {
                $after = (int) $entry['ordinal'];
                $state = $entry[$view] ?? null;
                if (!is_array($state)) {
                    continue;
                }
                foreach ((array) ($state['cells'] ?? []) as $currency => $cell) {
                    if (++$rowCount > self::MAX_ROWS) {
                        throw new \RuntimeException('price_matrix_csv_row_limit');
                    }
                    $cell = is_array($cell) ? $cell : [];
                    $operation = $cell === [] ? 'delete' : 'upsert';
                    $values = [
                        $state['id'] ?? $entry['stock_item_id'], $state['sku'] ?? '', $state['name'] ?? '', $currency,
                        $operation, $cell['price_mode'] ?? '', $cell['markup_pct'] ?? '', $cell['fixed_price'] ?? '',
                        $cell['rounding'] ?? '', isset($cell['is_manual_override']) ? (int) $cell['is_manual_override'] : '',
                        isset($cell['use_pricing_rules']) ? (int) $cell['use_pricing_rules'] : '', $cell['price'] ?? '',
                        $cell['cost_czk'] ?? '', $cell['margin_pct'] ?? '', $cell['rate'] ?? '',
                        $cell['profile_id'] ?? '', $cell['rule_id'] ?? '',
                    ];
                    $values[1] = self::safe($values[1]);
                    $values[2] = self::safe($values[2]);
                    yield self::row(array_map('strval', $values));
                }
            }
        } while ($batch !== []);
    }

    /** @return array{selection:array<string,mixed>,options:array<string,mixed>} */
    public function import(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES || str_contains($contents, "\0")) {
            throw new \InvalidArgumentException('CSV soubor je prázdný nebo překračuje limit 50 MB.');
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ';', '"', '');
        if ($headers !== self::HEADERS) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV nemá očekávanou hlavičku cenové matice.');
        }
        $ids = [];
        $currencies = [];
        $overrides = [];
        $rowCount = 0;
        while (($values = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($values) !== count(self::HEADERS)) {
                fclose($stream);
                throw new \InvalidArgumentException('CSV obsahuje neúplný řádek.');
            }
            if (++$rowCount > self::MAX_ROWS) {
                fclose($stream);
                throw new \InvalidArgumentException('CSV obsahuje více než 90 000 cenových řádků.');
            }
            $row = array_combine(self::HEADERS, $values);
            if (!self::validId((string) $row['item_id']) || !preg_match('/^[A-Z]{3}$/D', (string) $row['currency_code'])) {
                fclose($stream);
                throw new \InvalidArgumentException('CSV obsahuje neplatné ID nebo měnu.');
            }
            $operation = (string) $row['operation'];
            if (!in_array($operation, ['lock_current', 'set_fixed', 'unlock_to_rules', 'delete', 'upsert'], true)) {
                fclose($stream);
                throw new \InvalidArgumentException('CSV obsahuje neplatnou operaci.');
            }
            $id = (int) $row['item_id'];
            $currency = (string) $row['currency_code'];
            $override = ['item_id' => $id, 'currency_code' => $currency, 'operation' => $operation];
            if (isset($overrides[$id . ':' . $currency])) {
                fclose($stream);
                throw new \InvalidArgumentException('CSV obsahuje duplicitní operaci pro kartu a měnu.');
            }
            if ($operation === 'set_fixed') {
                $override['fixed_price'] = $row['fixed_price'];
                $override['rounding'] = $row['rounding'];
            } elseif ($operation === 'upsert') {
                $override['definition'] = [
                    'price_mode' => $row['price_mode'],
                    'markup_pct' => $row['markup_pct'] === '' ? null : $row['markup_pct'],
                    'fixed_price' => $row['fixed_price'] === '' ? null : $row['fixed_price'],
                    'rounding' => $row['rounding'],
                    'is_manual_override' => self::csvBool($row['is_manual_override']),
                    'use_pricing_rules' => self::csvBool($row['use_pricing_rules']),
                ];
            }
            $ids[$id] = $id;
            $currencies[$currency] = $currency;
            $overrides[$id . ':' . $currency] = $override;
        }
        fclose($stream);
        if ($overrides === []) {
            throw new \InvalidArgumentException('CSV neobsahuje žádnou cenovou operaci.');
        }
        return [
            'selection' => ['all_matching' => false, 'ids' => array_values($ids)],
            'options' => [
                'currencies' => array_values($currencies),
                'ensure_missing' => false,
                'reprice' => false,
                'overrides' => array_values($overrides),
            ],
        ];
    }

    private static function csvBool(string $value): bool
    {
        if (!in_array($value, ['0', '1'], true)) {
            throw new \InvalidArgumentException('CSV obsahuje neplatnou boolean hodnotu.');
        }
        return $value === '1';
    }

    private static function safe(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private static function validId(string $value): bool
    {
        if (!preg_match('/^[1-9]\d*$/D', $value)) {
            return false;
        }
        $maximum = (string) PHP_INT_MAX;
        return strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }

    private static function row(array $values): string
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, $values, ';', '"', '');
        rewind($stream);
        $row = stream_get_contents($stream);
        fclose($stream);
        return $row === false ? '' : $row;
    }
}
