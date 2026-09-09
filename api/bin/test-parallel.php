<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Spustí testy paralelně, ale nikdy nad jednou databází.
 *
 * Pro každý ParaTest worker vytvoří klon již připravené `*_test` databáze.
 * Nepouští migrace ani seed čtyřikrát: kopíruje hotové DDL, data, triggery,
 * views a uložené rutiny. HTTP testy zůstávají sériové nad zdrojovou test DB,
 * protože jejich server musí číst tutéž databázi jako PHPUnit proces.
 *
 * Použití:
 *   php api/bin/test-parallel.php
 *   php api/bin/test-parallel.php --processes=4 --application
 *   php api/bin/test-parallel.php --source=myucto_test --keep-databases
 */

/** @return never */
function fail(string $message): never
{
    fwrite(STDERR, "[PARALLEL TEST] {$message}\n");
    exit(2);
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function assertTestDatabaseName(string $database, string $label): void
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $database) !== 1 || !str_ends_with($database, '_test')) {
        fail("{$label} musí být bezpečný název databáze končící _test.");
    }
}

/** @return array<string,mixed> */
function sourceConfig(string $rootDir): array
{
    $path = $rootDir . '/cfg.php';
    if (!is_file($path)) {
        fail('cfg.php chybí; pro izolované integrační testy není znám zdroj DB.');
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
        fail('cfg.php neobsahuje konfiguraci db.');
    }

    return $config;
}

/** @return list<string> */
function databaseObjects(PDO $pdo, string $schema, string $type): array
{
    $sql = $type === 'VIEW'
        ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'VIEW\' ORDER BY TABLE_NAME'
        : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE <> \'VIEW\' ORDER BY TABLE_NAME';
    $statement = $pdo->prepare($sql);
    $statement->execute([$schema]);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param list<string> $tables
 * @return list<string>
 */
function tablesInForeignKeyOrder(PDO $pdo, string $schema, array $tables): array
{
    $known = array_fill_keys($tables, true);
    $dependencies = array_fill_keys($tables, []);
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
    );
    $statement->execute([$schema]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string) $row['TABLE_NAME'];
        $parent = (string) $row['REFERENCED_TABLE_NAME'];
        if (isset($known[$table]) && isset($known[$parent]) && $table !== $parent) {
            $dependencies[$table][$parent] = true;
        }
    }

    $pending = array_fill_keys($tables, true);
    $ordered = [];
    while ($pending !== []) {
        $ready = [];
        foreach (array_keys($pending) as $table) {
            if (array_intersect_key($dependencies[$table], $pending) === []) {
                $ready[] = $table;
            }
        }
        if ($ready === []) {
            $ready = array_keys($pending);
        }
        sort($ready);
        foreach ($ready as $table) {
            unset($pending[$table]);
            $ordered[] = $table;
        }
    }

    return $ordered;
}

function showCreate(PDO $pdo, string $kind, string $schema, string $name): string
{
    $statement = $pdo->query('SHOW CREATE ' . $kind . ' ' . quoteIdentifier($schema) . '.' . quoteIdentifier($name));
    $row = $statement?->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("Nelze načíst DDL {$kind} {$name}.");
    }
    foreach ($row as $key => $value) {
        if (str_starts_with(strtolower((string) $key), 'create ') || $key === 'SQL Original Statement') {
            return (string) $value;
        }
    }

    throw new RuntimeException("SHOW CREATE {$kind} {$name} nevrátilo DDL.");
}

function sourceClonePlan(PDO $pdo, string $source): array
{
    assertTestDatabaseName($source, 'Zdroj');
    $tables = tablesInForeignKeyOrder($pdo, $source, databaseObjects($pdo, $source, 'BASE TABLE'));
    if ($tables === []) {
        throw new RuntimeException('Zdrojová testovací databáze nemá tabulky.');
    }
    $plan = ['source' => $source, 'tables' => [], 'views' => [], 'triggers' => [], 'routines' => []];
    $statement = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND UPPER(EXTRA) NOT LIKE '%GENERATED%'
         ORDER BY TABLE_NAME, ORDINAL_POSITION",
    );
    $statement->execute([$source]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
    }
    foreach ($tables as $table) {
        if (empty($columns[$table])) {
            throw new RuntimeException("Tabulka {$table} neobsahuje kopírovatelné sloupce.");
        }
        $plan['tables'][$table] = [
            'ddl' => showCreate($pdo, 'TABLE', $source, $table),
            'columns' => implode(', ', array_map(quoteIdentifier(...), $columns[$table])),
        ];
    }
    foreach (databaseObjects($pdo, $source, 'VIEW') as $view) {
        $plan['views'][] = showCreate($pdo, 'VIEW', $source, $view);
    }
    $statement = $pdo->prepare(
        'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
    );
    $statement->execute([$source]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
        $plan['triggers'][] = [
            'table' => (string) $trigger['EVENT_OBJECT_TABLE'],
            'ddl' => showCreate($pdo, 'TRIGGER', $source, (string) $trigger['TRIGGER_NAME']),
        ];
    }
    $statement = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME',
    );
    $statement->execute([$source]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $routine) {
        $plan['routines'][] = showCreate($pdo, (string) $routine['ROUTINE_TYPE'], $source, (string) $routine['ROUTINE_NAME']);
    }
    return $plan;
}

function cloneDatabase(PDO $pdo, array $plan, string $target): void
{
    $source = $plan['source'];
    assertTestDatabaseName($source, 'Zdroj');
    assertTestDatabaseName($target, 'Cíl');
    if ($source === $target) {
        throw new RuntimeException('Zdroj a cíl klonu musí být odlišné.');
    }
    $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($target));
    $pdo->exec('CREATE DATABASE ' . quoteIdentifier($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('USE ' . quoteIdentifier($target));
        foreach ($plan['tables'] as $name => $table) {
            try {
                $pdo->exec($table['ddl']);
            } catch (Throwable $e) {
                throw new RuntimeException("DDL tabulky {$name} nelze zkopírovat.", previous: $e);
            }
        }
        $targetTables = array_fill_keys(databaseObjects($pdo, $target, 'BASE TABLE'), true);
        $missingTables = array_diff_key($plan['tables'], $targetTables);
        if ($missingTables !== []) {
            throw new RuntimeException('Po kopii DDL chybí tabulky: ' . implode(', ', array_keys($missingTables)));
        }
        foreach ($plan['tables'] as $name => $table) {
            try {
                $pdo->exec(
                'INSERT INTO ' . quoteIdentifier($target) . '.' . quoteIdentifier($name) . ' (' . $table['columns'] . ')'
                . ' SELECT ' . $table['columns'] . ' FROM ' . quoteIdentifier($source) . '.' . quoteIdentifier($name),
                );
            } catch (Throwable $e) {
                throw new RuntimeException("Data tabulky {$name} nelze zkopírovat.", previous: $e);
            }
        }
        foreach ($plan['views'] as $ddl) {
            $pdo->exec($ddl);
        }
        foreach ($plan['triggers'] as $trigger) {
            if (!isset($targetTables[$trigger['table']])) {
                throw new RuntimeException('Trigger míří na chybějící tabulku.');
            }
            $pdo->exec($trigger['ddl']);
        }
        foreach ($plan['routines'] as $ddl) {
            $pdo->exec($ddl);
        }
    } catch (Throwable $e) {
        $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($target));
        throw $e;
    } finally {
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
    }
}

function runProcess(array $command, string $cwd, array $environment): int
{
    $process = new Process($command, $cwd, $environment);
    $process->setTimeout(null);
    $process->run(static function (string $type, string $output): void {
        $stream = $type === Process::ERR ? STDERR : STDOUT;
        fwrite($stream, $output);
    });

    return $process->getExitCode() ?? 1;
}

function reportOutputPath(mixed $path, string $invocationDirectory, string $option): string
{
    if (!is_string($path) || trim($path) === '' || str_contains($path, "\0") || str_ends_with($path, '/') || str_ends_with($path, '\\')) {
        throw new InvalidArgumentException("{$option} vyžaduje cestu k souboru.");
    }
    $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
        || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    if (!$absolute && str_contains($path, ':')) {
        throw new InvalidArgumentException("{$option} vyžaduje relativní nebo absolutní cestu k souboru.");
    }
    if (!$absolute) {
        $path = $invocationDirectory . DIRECTORY_SEPARATOR . $path;
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Nelze vytvořit adresář reportu.');
    }
    $directory = realpath($directory);
    if ($directory === false || !is_writable($directory)) {
        throw new RuntimeException('Adresář reportu není zapisovatelný.');
    }
    $file = $directory . DIRECTORY_SEPARATOR . basename($path);
    if (is_dir($file) || (file_exists($file) && !is_writable($file))) {
        throw new RuntimeException('Cíl reportu není zapisovatelný soubor.');
    }
    return $file;
}

function junitLogPaths(mixed $path, string $invocationDirectory): array
{
    $parallel = reportOutputPath($path, $invocationDirectory, '--log-junit');
    $http = preg_match('/\.xml$/i', $parallel) === 1
        ? substr($parallel, 0, -4) . '.http.xml'
        : $parallel . '.http.xml';
    $http = reportOutputPath($http, $invocationDirectory, '--log-junit');
    return ['parallel' => $parallel, 'http' => $http];
}

if (defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY')) {
    return;
}

require_once dirname(__DIR__) . '/tests/Support/ParallelRuntime.php';

$options = getopt('', ['processes::', 'source::', 'keep-databases', 'testsuite::', 'application', 'timings::', 'log-junit::']);
if (in_array('--help', $_SERVER['argv'], true) || in_array('-h', $_SERVER['argv'], true)) {
    fwrite(STDOUT, "Použití: php api/bin/test-parallel.php [--processes=N] [--source=DB] [--keep-databases] [--timings=PATH] [--log-junit=PATH] [--testsuite=Application|Architecture|Invariants|--application]\n");
    exit(0);
}

foreach (['timings', 'log-junit'] as $reportOption) {
    if (in_array('--' . $reportOption . '=', $_SERVER['argv'], true)) {
        $options[$reportOption] = '';
    }
}

$suite = $options['testsuite'] ?? null;
$application = array_key_exists('application', $options);
if ($application && is_string($suite) && $suite !== '') {
    fail('--application a --testsuite nelze kombinovat.');
}
if (is_string($suite) && $suite !== '' && !in_array($suite, ['Application', 'Architecture', 'Invariants'], true)) {
    fail('--testsuite podporuje jen Application, Architecture nebo Invariants.');
}

$timingPath = null;
if (array_key_exists('timings', $options)) {
    try {
        $timingPath = reportOutputPath($options['timings'], (string) getcwd(), '--timings');
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
}

$junitPaths = [];
if (array_key_exists('log-junit', $options)) {
    try {
        $junitPaths = junitLogPaths($options['log-junit'], (string) getcwd());
    } catch (Throwable $e) {
        fail($e->getMessage());
    }
}

$rootDir = dirname(__DIR__, 2);
$apiDir = dirname(__DIR__);
$config = sourceConfig($rootDir);
$dbConfig = $config['db'];
$configuredDb = (string) ($dbConfig['name'] ?? '');
$source = (string) ($options['source'] ?? getenv('MYINVOICE_DB_NAME') ?: ($configuredDb . '_test'));
assertTestDatabaseName($source, 'Zdroj');
if ($source === $configuredDb) {
    fail('Zdroj nesmí být databáze z cfg.php.');
}

$processes = (int) ($options['processes'] ?? min(4, max(2, (int) (getenv('NUMBER_OF_PROCESSORS') ?: 4))));
if ($processes < 2 || $processes > 16) {
    fail('--processes musí být mezi 2 a 16.');
}

$runId = bin2hex(random_bytes(6));
$prefix = preg_replace('/_test$/', '_parallel_' . $runId, $source);
if (!is_string($prefix) || $prefix === '') {
    fail('Nelze odvodit prefix paralelních databází.');
}
$databases = [];
for ($worker = 1; $worker <= $processes; $worker++) {
    $database = $prefix . '_' . $worker . '_test';
    assertTestDatabaseName($database, 'Cíl');
    if (strlen($database) > 64) {
        fail('Odvozený název paralelní DB je delší než 64 znaků. Použij kratší --source.');
    }
    $databases[] = $database;
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=utf8mb4',
    (string) ($dbConfig['host'] ?? '127.0.0.1'),
    (int) ($dbConfig['port'] ?? 3306),
);
$pdo = new PDO(
    $dsn,
    (string) ($dbConfig['user'] ?? ''),
    (string) ($dbConfig['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$keepDatabases = array_key_exists('keep-databases', $options);
$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$runtimeRoot = \MyInvoice\Tests\Support\ParallelRuntime::createRoot($runId);
$environment['MYINVOICE_PARALLEL_RUNTIME_ROOT'] = $runtimeRoot;
$environment['MYINVOICE_PARALLEL_TEMP_BASE'] = sys_get_temp_dir();
unset($environment['TEST_TOKEN'], $environment['UNIQUE_TEST_TOKEN']);
$parallelEnvironment = array_replace($environment, [
    'MYINVOICE_PARALLEL_DB_PREFIX' => $prefix,
    'MYINVOICE_DB_NAME' => $source,
]);
$parallelConfig = $apiDir . '/phpunit-parallel.xml';

$timings = ['run_id' => $runId, 'processes' => $processes, 'phases' => []];
$started = hrtime(true);
$phase = static function (string $name, callable $action) use (&$timings): mixed {
    $start = hrtime(true);
    try {
        return $action();
    } finally {
        $timings['phases'][$name] = (hrtime(true) - $start) / 1e9;
    }
};
$exitCode = 1;
try {
    // Bootstrap jednou aplikuje případné nové migrace a srovná seed zdrojové
    // DB do očekávaného testovacího stavu. Klony tak začínají stejně jako běžný
    // sériový běh, jen bez čtyřnásobného migrátoru a seedování.
    fwrite(STDOUT, "[PARALLEL TEST] Připravuji zdrojovou testovací DB {$source}.\n");
    $sourceEnvironment = array_replace($environment, ['MYINVOICE_DB_NAME' => $source]);
    unset($sourceEnvironment['MYINVOICE_PARALLEL_DB_PREFIX']);
    if ($phase('source_bootstrap', fn () => runProcess(
        [
            PHP_BINARY,
            $apiDir . '/vendor/phpunit/phpunit/phpunit',
            '--configuration=' . $parallelConfig,
            '--list-suites',
        ],
        $apiDir,
        $sourceEnvironment,
    )) !== 0) {
        throw new RuntimeException('Příprava zdrojové testovací DB selhala.');
    }

    fwrite(STDOUT, "[PARALLEL TEST] Klonuji {$source} pro {$processes} workery (bez migrací a seedování).\n");
    $plan = $phase('source_clone_plan', fn () => sourceClonePlan($pdo, $source));
    foreach ($databases as $worker => $database) {
        $phase('clone_' . ($worker + 1), fn () => cloneDatabase($pdo, $plan, $database));
    }

    $paraTest = $apiDir . '/vendor/brianium/paratest/bin/paratest';
    $command = [
        PHP_BINARY,
        $paraTest,
        '--configuration=' . $parallelConfig,
        '--processes=' . $processes,
        '--exclude-group=http-integration',
        '--colors=auto',
    ];
    if ($junitPaths !== []) {
        $command[] = '--log-junit=' . $junitPaths['parallel'];
    }
    if ($application) {
        $command[] = '--testsuite=Application';
    } elseif (is_string($suite) && $suite !== '') {
        $command[] = '--testsuite=' . $suite;
    }
    $exitCode = $phase('paratest', fn () => runProcess($command, $apiDir, $parallelEnvironment));

    // Black-box HTTP testy nelze sdílet mezi workery: běžící server má jednu DB.
    // Spouštějí se proto až po ParaTestu nad původní izolovanou testovací DB.
    if ($exitCode === 0 && ($application || $suite === null || $suite === '' || $suite === 'Integration')) {
        $httpEnvironment = array_replace($environment, ['MYINVOICE_DB_NAME' => $source]);
        unset($httpEnvironment['MYINVOICE_PARALLEL_DB_PREFIX']);
        $exitCode = $phase('http_tail', fn () => runProcess(
            [
                PHP_BINARY,
                $apiDir . '/vendor/phpunit/phpunit/phpunit',
                '--configuration=' . $parallelConfig,
                '--group=http-integration',
                '--colors=auto',
                ...($junitPaths !== [] ? ['--log-junit=' . $junitPaths['http']] : []),
            ],
            $apiDir,
            $httpEnvironment,
        ));
    }
} catch (Throwable $e) {
    $details = $e->getMessage();
    for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
        $details .= ' — ' . $previous->getMessage();
    }
    fwrite(STDERR, '[PARALLEL TEST] ' . $details . "\n");
    $exitCode = 1;
} finally {
    try {
        $phase('cleanup', function () use ($keepDatabases, $databases, $pdo, $runtimeRoot): void {
            if ($keepDatabases) {
                fwrite(STDOUT, "[PARALLEL TEST] Klony a runtime ponechány: {$runtimeRoot}\n");
                return;
            }
            try {
                foreach ($databases as $database) {
                    $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($database));
                }
            } finally {
                \MyInvoice\Tests\Support\ParallelRuntime::removeRoot($runtimeRoot);
            }
        });
    } catch (Throwable $e) {
        fwrite(STDERR, '[PARALLEL TEST] Cleanup selhal: ' . $e->getMessage() . "\n");
        $exitCode = 1;
    }
    $timings['total_seconds'] = (hrtime(true) - $started) / 1e9;
    $timings['exit_code'] = $exitCode;
    $timings['runner_peak_memory_bytes'] = memory_get_peak_usage(true);
    $timings['test_discovery'] = 'included_in_paratest';
    $timingPath ??= $apiDir . '/.phpunit.cache/parallel-' . $runId . '.json';
    if (!is_dir(dirname($timingPath))) {
        mkdir(dirname($timingPath), 0770, true);
    }
    if (file_put_contents($timingPath, json_encode($timings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
        fwrite(STDERR, "[PARALLEL TEST] Nelze zapsat měření.\n");
        $exitCode = 1;
    } else {
        fwrite(STDOUT, "[PARALLEL TEST] Měření: {$timingPath}\n");
    }
}

exit($exitCode);
