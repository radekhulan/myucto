<?php

declare(strict_types=1);

$fixture = (string) getenv('AUDIT_GATE_FIXTURE');
$config = json_decode((string) file_get_contents($fixture . '/scenario.json'), true, flags: JSON_THROW_ON_ERROR);
$script = basename(str_replace('\\', '/', $argv[1] ?? ''));
$greenCheck = $script === 'test-parallel.php' && (in_array('--audit-check', $argv, true) || in_array('--green-check', $argv, true));
$allowed = ['test-parallel.php', 'check-invariants.php', 'cross-check.php', 'check-schema.php', 'compare_dph.php', 'checks_shape_sweep.php'];
if (!in_array($script, $allowed, true)) {
    fwrite(STDERR, 'Unexpected audit command: ' . $script);
    exit(75);
}
file_put_contents($fixture . '/calls.jsonl', json_encode([
    'script' => $script,
    'green_check' => $greenCheck,
    'args' => array_slice($argv, 2),
    'database' => getenv('MYINVOICE_DB_NAME'),
    'cwd' => str_replace('\\', '/', (string) getcwd()),
], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if ($greenCheck) {
    exit($config['green']);
}
if ($script === 'test-parallel.php') {
    echo $config['tests'] === 0 ? "OK (1 test, 1 assertion)\n" : "Tests: 1, Assertions: 0, Errors: 1.\n";
    exit($config['tests']);
}
echo "Synthetic data check: {$script}\n";
exit($config['data']);
