<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MyUctoRegression\Instrumentation;
use MyUctoRegression\ConfigSnapshot;
use MyUctoRegression\Manifest;
use MyUctoRegression\Network;
use MyUctoRegression\Safety;
use MyUctoRegression\Tripwire;

ini_set('display_errors', '0');
ob_start();
$response = ['status' => 'error', 'error' => 'worker_not_completed'];
$output = null;
try {
    $request = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $root = realpath($request['code_root']);
    if ($root === false) {
        throw new RuntimeException('invalid_code_root');
    }
    $roots = [$root, dirname(__DIR__, 2)];
    Safety::outside($argv[1], $roots);
    $output = Safety::outside($request['response'], $roots);
    $runtime = Safety::outside($request['runtime'], $roots);
    if (!is_dir($runtime) || is_file($runtime . '/cfg.local.php')) {
        throw new RuntimeException('invalid_runtime_directory');
    }
    $asOf = Safety::date($request['as_of']);
    Network::install();
    require $root . '/api/vendor/autoload.php';
    $config = \MyInvoice\Infrastructure\Config\Config::load($root);
    Safety::database($request['database'], (string) $config->get('db.name'));
    $host = $request['docker_host'] ?? (string) $config->get('db.host', '127.0.0.1');
    Safety::host($host, $request['docker_host'] ?? null);
    ConfigSnapshot::prepare($root, $config, $request['database'], $host, $runtime);
    putenv('MYINVOICE_DATA_DIR=' . $runtime);
    putenv('MYINVOICE_REDIS_ENABLED=false');
    putenv('REDIS_URL');
    foreach (['DATABASE_URL', 'MYSQL_URL', 'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'] as $key) {
        putenv($key);
    }
    foreach (['HOST' => $host, 'PORT' => $config->get('db.port', 3306), 'NAME' => $request['database'], 'USER' => $config->get('db.user'), 'PASS' => $config->get('db.pass', '')] as $key => $value) {
        putenv('MYINVOICE_DB_' . $key . '=' . $value);
    }
    Tripwire::configure($request['database'], $request['docker_host'] ?? null);
    Instrumentation::register($root, true);
    $container = \MyInvoice\Bootstrap::buildContainer();
    $container->set(\Psr\Clock\ClockInterface::class, new class ($asOf) implements \Psr\Clock\ClockInterface {
        public function __construct(private readonly string $date) {}
        public function now(): DateTimeImmutable { return new DateTimeImmutable($this->date . 'T00:00:00'); }
    });
    if (date('Y-m-d') !== $request['execution_date']) {
        throw new RuntimeException('execution_day_changed');
    }
    $connection = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
    $pdo = $connection->pdo();
    $data = $request['action'] === 'preflight'
        ? Manifest::discover($pdo, $asOf)
        : Manifest::normalize(Manifest::invoke($container, $request['case']), $asOf);
    Tripwire::finish();
    if (date('Y-m-d') !== $request['execution_date']) {
        throw new RuntimeException('execution_day_changed');
    }
    $response = ['status' => 'ok', 'data' => $data];
} catch (Throwable $error) {
    $safeCodes = ['missing_exchange_rates', 'execution_day_changed', 'unknown_manifest_case', 'unreviewed_result_object', 'unsupported_database_source_signature', 'unsupported_database_method_signature', 'unsupported_config_source_signature', 'unsupported_bootstrap_source_signature', 'test_database_required', 'local_test_host_required', 'sticky_violation', 'invalid_filed_vat_xml', 'invalid_filed_vat_period', 'invalid_historical_taxpayer_type', 'invalid_provisions_pagination'];
    $code = in_array($error->getMessage(), $safeCodes, true) ? $error->getMessage() : 'case_exception';
    $candidateCode = get_object_vars($error)['errorCode'] ?? null;
    $domainCode = is_string($candidateCode) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $candidateCode) ? $candidateCode : null;
    $response = ['status' => 'error', 'error' => $code, 'exception_class' => get_class($error), 'domain_code' => $domainCode, 'violations' => Tripwire::violations(), 'diagnostics' => Tripwire::diagnostics()];
    if ($error instanceof \MyUctoRegression\ProjectionUnavailable) $response['framework_proof'] = $error->frameworkProof;
}
$unexpectedOutput = ob_get_clean();
if ($unexpectedOutput !== '') {
    $response = ['status' => 'error', 'error' => 'unexpected_worker_output'];
}
try {
    if ($output === null) {
        exit(2);
    }
    Safety::writeJson($output, $response);
} catch (Throwable) {
    exit(2);
}
exit($response['status'] === 'ok' ? 0 : 2);
