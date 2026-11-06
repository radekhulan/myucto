<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/PropertyBenchmarkDataset.php';
require __DIR__ . '/Support/PropertyBenchmarkRead.php';
require __DIR__ . '/Support/PropertyBenchmarkQueries.php';
require __DIR__ . '/Support/PropertyBenchmarkAdoption.php';
require __DIR__ . '/Support/PropertyBenchmarkAcceptance.php';

use MyInvoice\Benchmark\PropertyBenchmarkAcceptance;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;

$options = getopt('', ['phase:', 'output:']);
$phase = $options['phase'] ?? null;
$output = $options['output'] ?? null;
if (!is_string($phase) || !is_string($output) || !is_dir($output)) {
    throw new InvalidArgumentException('Use --phase=pilot|portfolio-50|heavy-adoption --output=<existing directory>');
}

$container = Bootstrap::buildContainer();
$runner = new PropertyBenchmarkAcceptance($container->get(Connection::class), $container);
$result = $runner->run($phase, $output);
echo json_encode([
    'complete' => $result['complete'],
    'phase' => $result['phase'],
    'subjects' => count($result['subjects']),
    'all_saldo_pages_within_1s' => $result['all_saldo_pages_within_1s'],
    'monthly_generation_within_30s' => $result['monthly_generation_within_30s'],
    'phase_acceptance_passed' => $result['phase_acceptance_passed'],
    'full_acceptance_claimed' => $result['full_acceptance_claimed'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['complete'] === true && $result['phase_acceptance_passed'] === true ? 0 : 2);
