<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/PropertyBenchmarkDataset.php';
require __DIR__ . '/Support/PropertyBenchmarkRead.php';
require __DIR__ . '/Support/PropertyBenchmarkAdoption.php';

use MyInvoice\Benchmark\PropertyBenchmarkAdoption;
use MyInvoice\Benchmark\PropertyBenchmarkDataset;
use MyInvoice\Benchmark\PropertyBenchmarkRead;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;

$options = getopt('', ['units:', 'months:', 'output:']);
$units = filter_var($options['units'] ?? 12, FILTER_VALIDATE_INT);
$months = filter_var($options['months'] ?? 2, FILTER_VALIDATE_INT);
$output = $options['output'] ?? null;
if (!in_array($units, [12, 400], true) || !in_array($months, [2, 12, 120], true) || !is_string($output) || !is_dir($output)) {
    throw new InvalidArgumentException('Use --units=12|400 --months=2|12|120 --output=<existing directory>');
}
$firstMonth = $months === 120 ? '2016-01' : '2025-01';
PropertyBenchmarkAdoption::plan($units, $months, $firstMonth);

$container = Bootstrap::buildContainer();
$db = $container->get(Connection::class);
$pdo = $db->pdo();
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$host = $container->get(Config::class)->get('db.host');
if (preg_match('/^myucto_nno_property_benchmark_[a-z0-9_]+_test$/D', $database) !== 1
    || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Exact owned local synthetic benchmark DB required');
}
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$started = hrtime(true);
$fixture = (new PropertyBenchmarkDataset($db, $container))->create($units, '2016-01-01', 'S6 adoption ' . $units . 'x' . $months);
$catalogMs = (hrtime(true) - $started) / 1e6;
$result = (new PropertyBenchmarkAdoption($db, $container))->apply($fixture, $months, $firstMonth);
$result['catalog_ms'] = $catalogMs;
$result['seed_memory_limit'] = ini_get('memory_limit');
$result['seed_peak_bytes'] = memory_get_peak_usage(true);

$reconciliationStarted = hrtime(true);
$reconciliation = (new PropertyReconciliation($db))->at($fixture['supplier'], '2026-01-31');
$reconciliationMs = (hrtime(true) - $reconciliationStarted) / 1e6;
if (!$reconciliation['matches']
    || $reconciliation['ledger_cents'] !== ['receivable' => $result['total_cents'], 'credit' => '0']
    || $reconciliation['ledger_cents'] !== $reconciliation['subledger_cents']) {
    throw new RuntimeException('Native S6 GL/subledger reconciliation failed');
}

$knownAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
$reader = new PropertyBenchmarkRead($db);
$currentAsOf = '2026-01-31T23:59:59+01:00';
$beforeAsOf = '2025-12-31T23:59:59+01:00';
if ($units <= 25) {
    $current = $reader->page($fixture['supplier'], $currentAsOf, $knownAt, 25);
    $before = $reader->page($fixture['supplier'], $beforeAsOf, $knownAt, 25);
    $unitRows = [];
    foreach ($fixture['units'] as $index => $id) {
        $unitRows[] = ['id' => $id, 'code' => sprintf('U%03d', $index + 1)];
    }
    $currentBytes = json_encode($current['page']['items'], JSON_THROW_ON_ERROR);
    $beforeBytes = json_encode($before['page']['items'], JSON_THROW_ON_ERROR);
    if ($currentBytes !== json_encode($reader->reference($fixture['supplier'], $currentAsOf, $knownAt, $unitRows), JSON_THROW_ON_ERROR)
        || $beforeBytes !== json_encode($reader->reference($fixture['supplier'], $beforeAsOf, $knownAt, $unitRows), JSON_THROW_ON_ERROR)) {
        throw new RuntimeException('Native adopted saldo exact-byte reference mismatch');
    }
    foreach ($current['page']['items'] as $item) {
        if ($item['totals']['gross_receivable_cents'] !== (string) ($months * 180000)
            || $item['claim_count'] !== $result['claims_per_unit']) {
            throw new RuntimeException('Exact adopted unit principal count/sum mismatch');
        }
    }
    foreach ($before['page']['items'] as $item) {
        if ($item['totals']['gross_receivable_cents'] !== '0' || $item['claim_count'] !== 0) {
            throw new RuntimeException('Adopted claims cannot invent pre-adoption ledger history');
        }
    }
    $readProof = [
        'reference_parity' => true,
        'current_sha256' => hash('sha256', $currentBytes),
        'before_adoption_sha256' => hash('sha256', $beforeBytes),
        'current_ms' => $current['ms'],
        'before_adoption_ms' => $before['ms'],
    ];
} else {
    $current = $reader->traverse($fixture['supplier'], $currentAsOf, $knownAt, $units, 100);
    $before = $reader->traverse($fixture['supplier'], $beforeAsOf, $knownAt, $units, 100);
    if ($current['gross_receivable_cents'] !== $result['total_cents']
        || $current['claim_count_min'] !== $result['claims_per_unit']
        || $current['claim_count_max'] !== $result['claims_per_unit']
        || $before['gross_receivable_cents'] !== '0'
        || $before['claim_count_min'] !== 0
        || $before['claim_count_max'] !== 0) {
        throw new RuntimeException('Bounded adopted saldo proof mismatch');
    }
    $readProof = [
        'reference_parity' => false,
        'reference_reason' => 'Full-history PHP reference is restricted to the 12-unit pilot',
        'current' => $current,
        'before_adoption' => $before,
    ];
}

$result += [
    'database' => $database,
    'bootstrap_reflection_verified' => str_starts_with(strtolower((string) realpath((new ReflectionClass(Bootstrap::class))->getFileName())), strtolower((string) realpath(Bootstrap::rootDir())) . DIRECTORY_SEPARATOR),
    'native_reconciliation' => true,
    'reconciliation_ms' => $reconciliationMs,
    'read_proof' => $readProof,
    'full_acceptance_claimed' => false,
];
$name = 'adoption-' . $units . 'x' . $months;
file_put_contents($output . '/' . $name . '.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
