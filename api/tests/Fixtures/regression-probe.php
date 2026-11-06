<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

use MyUctoRegression\Instrumentation;
use MyUctoRegression\Network;
use MyUctoRegression\Safety;
use MyUctoRegression\Tripwire;

$mode = $argv[1] ?? '';
$root = dirname(__DIR__, 3);
try {
    if ($mode === 'unknown-signature') {
        $directory = sys_get_temp_dir() . '/regression-signature-' . bin2hex(random_bytes(8));
        mkdir($directory . '/api/src/Infrastructure/Database', 0700, true);
        $file = $directory . '/api/src/Infrastructure/Database/Connection.php';
        file_put_contents($file, file_get_contents($root . '/api/src/Infrastructure/Database/Connection.php') . "\n");
        try {
            Instrumentation::register($directory);
            $rejected = false;
        } catch (RuntimeException $error) {
            $rejected = $error->getMessage() === 'unsupported_database_source_signature';
        }
        unlink($file);
        foreach (['/api/src/Infrastructure/Database', '/api/src/Infrastructure', '/api/src', '/api', ''] as $suffix) {
            rmdir($directory . $suffix);
        }
        echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'network') {
        Network::install();
        try { @file_get_contents(sys_get_temp_dir() . '/missing-synthetic-cache-' . bin2hex(random_bytes(8))); } catch (Throwable) {}
        try { curl_init('https://example.invalid/'); } catch (Throwable) {}
        try { @file_get_contents('https://example.invalid/'); } catch (Throwable) {}
        echo json_encode(['violations' => Tripwire::violations()], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'union-syntax') {
        $statements = ['(SELECT 1) UNION ALL (SELECT 2) ORDER BY doc_type, doc_id', '(SELECT 1)',
            '((SELECT 1))', '(SELECT 1) UNION ALL (SELECT 2); COMMIT',
            '(SELECT 1) UNION ALL (SELECT 2) /*! COMMIT */', '(SELECT 1) UNION ALL (SELECT 2) /*+ hint */',
            '(SELECT 1 INTO OUTFILE \'x\') UNION ALL (SELECT 2)', '(SELECT SLEEP(1)) UNION ALL (SELECT 2)',
            '(SELECT 1) UNION ALL (UPDATE regression_probe SET amount = 1)',
            '(SELECT 1) UNION ALL (SELECT 2) UNION ALL (SELECT 3)',
            '(SELECT 1) UNION ALL (SELECT 2); SELECT 3', '(SELECT 1 FOR UPDATE) UNION ALL (SELECT 2)'];
        $rejected = [];
        foreach ($statements as $sql) {
            try { Tripwire::sql($sql); $rejected[] = false; }
            catch (RuntimeException) { $rejected[] = true; }
        }
        echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'savepoint-syntax') {
        $statements = [
            'SAVEPOINT dimension_period_1', 'RELEASE SAVEPOINT dimension_period_1', 'ROLLBACK TO SAVEPOINT dimension_period_1',
            'SAVEPOINT dimension_period_0', 'SAVEPOINT unrelated', 'SAVEPOINT dimension_period_1; COMMIT',
            'SAVEPOINT dimension_period_1; SELECT 1', 'SAVEPOINT dimension_period_1 /* comment */',
            'SAVEPOINT dimension_period_1 /*! COMMIT */', 'SAVEPOINT dimension_period_1 /*+ hint */',
            'COMMIT', 'BEGIN', 'START TRANSACTION', 'ROLLBACK', 'SET TRANSACTION READ WRITE',
            'RELEASE SAVEPOINT dimension_period_1; COMMIT', 'ROLLBACK TO SAVEPOINT dimension_period_1; COMMIT',
        ];
        $rejected = [];
        foreach ($statements as $sql) {
            try { Tripwire::sql($sql); $rejected[] = false; }
            catch (RuntimeException) { $rejected[] = true; }
        }
        echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
        exit(0);
    }
    require $root . '/api/vendor/autoload.php';
    if ($mode === 'config-source-mutated') {
        $directory = sys_get_temp_dir() . '/regression-config-signature-' . bin2hex(random_bytes(8));
        $path = $directory . '/api/src/Infrastructure/Config';
        mkdir($path, 0700, true);
        $file = $path . '/Config.php';
        $source = file_get_contents($root . '/api/src/Infrastructure/Config/Config.php');
        $mutations = [
            "return \$raw !== false && \$raw !== '' && !self::isUnresolvedEnvReference(\$raw);" => 'return true;',
            '$merged = self::applyEnvOverrides($merged);' => '$merged = $base;',
        ];
        $rejected = [];
        try {
            foreach ($mutations as $find => $replacement) {
                $mutated = str_replace($find, $replacement, $source, $count);
                if ($count !== 1) throw new LogicException('config_probe_mutation_not_applied');
                file_put_contents($file, $mutated);
                try {
                    \MyUctoRegression\ConfigSnapshot::prepare($directory,
                        new \MyInvoice\Infrastructure\Config\Config([]),
                        'myucto_snapshot_12345678_test', '127.0.0.1', $directory . '/runtime');
                    $rejected[] = false;
                } catch (RuntimeException $error) {
                    $rejected[] = $error->getMessage() === 'unsupported_config_source_signature';
                }
            }
        } finally {
            unlink($file);
            foreach (['/api/src/Infrastructure/Config', '/api/src/Infrastructure', '/api/src', '/api', ''] as $suffix) {
                rmdir($directory . $suffix);
            }
        }
        echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($mode === 'config-snapshot') {
        $data = [
            'db' => ['host' => 'localhost', 'name' => 'original_instance', 'user' => 'synthetic_user', 'pass' => 'synthetic_test_value', 'port' => 3307, 'strict' => true],
            'app' => ['timezone' => 'UTC', 'custom_option' => ['zero' => 0, 'nullable' => null]],
            'redis' => ['enabled' => true, 'prefix' => 'original-prefix'],
            'accounting' => ['nested_option' => ['value' => 'preserved']],
        ];
        $original = new \MyInvoice\Infrastructure\Config\Config($data, 'original-runtime');
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic-regression-runtime';
        \MyUctoRegression\ConfigSnapshot::prepare($root, $original, 'myucto_snapshot_12345678_test', '127.0.0.1', $runtime);
        $isolated = \MyUctoRegression\ConfigSnapshot::get();
        echo json_encode(['preserved' => [
            $data === $original->all(),
            'original-runtime' === $original->dataDir(),
            array_replace($data['db'], ['host' => '127.0.0.1', 'name' => 'myucto_snapshot_12345678_test']) === $isolated->get('db'),
            $data['app'] === $isolated->get('app'),
            $data['accounting'] === $isolated->get('accounting'),
            ['enabled' => false, 'prefix' => 'original-prefix'] === $isolated->get('redis'),
            $runtime === $isolated->dataDir(),
            $runtime . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' === $isolated->get('storage.cache_dir'),
        ]], JSON_THROW_ON_ERROR);
        exit(0);
    }
    $instrumented = str_ends_with($mode, '-guarded');
    $sqlite = str_starts_with($mode, 'sqlite-');
    $config = \MyInvoice\Infrastructure\Config\Config::load($root);
    $database = $argv[2] ?? '';
    if (!$sqlite) {
        Safety::database($database, (string) $config->get('db.name'));
        Safety::host((string) $config->get('db.host'), null);
        Tripwire::configure($database, null);
    }
    if ($instrumented) {
        Instrumentation::register($root);
    }
    if ($sqlite) {
        $pdo = new \MyInvoice\Infrastructure\Database\LoggingPdo('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC], new \Psr\Log\NullLogger());
        $pdo->beginTransaction();
        $rows = $pdo->query("SELECT '12345678901234567890.1234567890' AS amount, NULL AS nullable UNION ALL SELECT '0.0000000000', NULL")->fetchAll();
        $pdo->rollBack();
        echo json_encode(['rows' => $rows, 'transaction' => $pdo->inTransaction()], JSON_THROW_ON_ERROR);
        exit(0);
    }
    define('PHPUNIT_COMPOSER_INSTALL', $root . '/api/vendor/autoload.php');
    $data = $config->all();
    $data['db']['name'] = $database;
    $config = new \MyInvoice\Infrastructure\Config\Config($data);
    $first = new \MyInvoice\Infrastructure\Database\Connection($config);
    $pdo = $first->pdo();
    if (!$instrumented) {
        $pdo->beginTransaction();
    }
    $second = new \MyInvoice\Infrastructure\Database\Connection($config);
    $third = \MyInvoice\Infrastructure\Database\Connection::withoutSharedTestConnection(static fn () => new \MyInvoice\Infrastructure\Database\Connection($config));
    $thirdPdo = $third->pdo();
    if ($mode === 'maria-fx-union-guarded') {
        $repo = new \MyInvoice\Repository\ClosingRepository($first);
        $rows = $repo->openFxItems(3, '2025-12-31');
        $result = ['native_query_completed' => true, 'row_count' => count($rows),
            'read_only' => (int) $pdo->query('SELECT @@session.tx_read_only')->fetchColumn(), 'transaction_active' => $pdo->inTransaction()];
        $result['wrong_caller_rejected'] = false;
        try { $pdo->prepare('(SELECT amount FROM regression_probe) UNION ALL (SELECT amount FROM regression_probe) ORDER BY doc_type, doc_id'); }
        catch (RuntimeException $error) { $result['wrong_caller_rejected'] = $error->getMessage() === 'sql_write_or_unsafe_statement'; }
        $result['for_update_rejected'] = false;
        try { $repo->openFxItems(3, '2025-12-31', true); }
        catch (RuntimeException $error) { $result['for_update_rejected'] = $error->getMessage() === 'sql_write_or_unsafe_statement'; }
        $result['violations'] = Tripwire::violations();
        try { Tripwire::finish(); } catch (Throwable) {}
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (str_starts_with($mode, 'maria-savepoint-')) {
        $before = $pdo->query('SELECT amount FROM regression_probe WHERE id = 2')->fetchColumn();
        $guard = new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($first);
        $nested = $guard->transaction(static fn (): bool => $pdo->inTransaction());
        $rolled = false;
        try {
            $guard->transaction(static function () use ($pdo, $instrumented): void {
                if (!$instrumented) $pdo->exec('UPDATE regression_probe SET amount = 12.34 WHERE id = 2');
                throw new LogicException('synthetic_rollback');
            });
        } catch (LogicException) { $rolled = true; }
        $after = $pdo->query('SELECT amount FROM regression_probe WHERE id = 2')->fetchColumn();
        $result = ['nested' => $nested, 'rollback_executed' => $rolled, 'data_unchanged' => $before === $after,
            'transaction_active' => $pdo->inTransaction()];
        if ($instrumented) {
            $result['read_only'] = [(int) $pdo->query('SELECT @@session.tx_read_only')->fetchColumn(), (int) $thirdPdo->query('SELECT @@session.tx_read_only')->fetchColumn()];
            $result['forged_caller_rejected'] = false;
            try { $guard->transaction(static fn () => $pdo->exec('SAVEPOINT dimension_period_999')); }
            catch (RuntimeException $error) { $result['forged_caller_rejected'] = $error->getMessage() === 'sql_write_or_unsafe_statement'; }
            $result['dml_rejected'] = false;
            try { $pdo->prepare('UPDATE regression_probe SET amount = 1')->execute(); }
            catch (RuntimeException $error) { $result['dml_rejected'] = $error->getMessage() === 'sql_write_or_unsafe_statement'; }
            $result['violations'] = Tripwire::violations();
            try { Tripwire::finish(); } catch (Throwable) {}
        } else { $pdo->rollBack(); }
        $result['after_rollback'] = $pdo->inTransaction();
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    }
    $result = [
        'rows' => $pdo->query('SELECT amount, nullable FROM regression_probe ORDER BY id')->fetchAll(),
        'session' => $pdo->query('SELECT @@session.sql_mode AS mode, @@session.time_zone AS timezone')->fetch(),
        'attributes' => [$pdo->getAttribute(PDO::ATTR_ERRMODE), $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)],
        'same_instance' => $pdo === $first->pdo(),
        'shared_instance' => $pdo === $second->pdo(),
        'separate_instance' => $pdo !== $thirdPdo,
        'transaction' => $pdo->inTransaction(),
    ];
    if ($instrumented) {
        $result['read_only'] = [(int) $pdo->query('SELECT @@session.tx_read_only')->fetchColumn(), (int) $thirdPdo->query('SELECT @@session.tx_read_only')->fetchColumn()];
        try { $pdo->prepare('UPDATE regression_probe SET amount = 1')->execute(); } catch (Throwable) {}
        try { $pdo->query('SELECT amount FROM missing_synthetic_seed'); } catch (Throwable) {}
        $result['violations'] = Tripwire::violations();
        try { Tripwire::finish(); } catch (Throwable) {}
    } else {
        $pdo->rollBack();
    }
    $result['after_rollback'] = $pdo->inTransaction();
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error), 'violations' => Tripwire::violations()], JSON_THROW_ON_ERROR);
    exit(2);
}
