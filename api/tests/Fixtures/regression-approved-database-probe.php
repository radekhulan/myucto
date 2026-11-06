<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 3);
$mode = $argv[1] ?? '';
$directory = sys_get_temp_dir() . '/regression-approved-db-' . bin2hex(random_bytes(8));
$base = $directory . '/api/src/Infrastructure/Database';
mkdir($base, 0700, true);
try {
    copy($root . '/api/src/Infrastructure/Database/Connection.php', $base . '/Connection.php');
    foreach (['LoggingPdo', 'LoggingPdoStatement'] as $name) copy(__DIR__ . '/database-instrumentation/' . $name . '.approved.php.txt', $base . '/' . $name . '.php');
    if ($mode === 'mutated') {
        file_put_contents($base . '/LoggingPdo.php', "\n", FILE_APPEND);
        try {
            \MyUctoRegression\Instrumentation::register($directory);
            $rejected = false;
        } catch (RuntimeException $error) {
            $rejected = $error->getMessage() === 'unsupported_database_source_signature';
        }
        echo json_encode(['rejected'=>$rejected], JSON_THROW_ON_ERROR);
    } else {
        if ($mode === 'guarded') \MyUctoRegression\Instrumentation::register($directory);
        else spl_autoload_register(static function (string $class) use ($base): void {
            foreach (['LoggingPdo', 'LoggingPdoStatement'] as $name) if ($class === 'MyInvoice\\Infrastructure\\Database\\' . $name) { require $base . '/' . $name . '.php'; return; }
        }, true, true);
        $pdo = new \MyInvoice\Infrastructure\Database\LoggingPdo('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC], new \Psr\Log\NullLogger());
        $pdo->beginTransaction();
        $rows = $pdo->query("SELECT '12345678901234567890.1234567890' AS amount,NULL AS nullable UNION ALL SELECT '0.0000000000',NULL")->fetchAll();
        $session = ['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'error_mode'=>$pdo->getAttribute(PDO::ATTR_ERRMODE),'fetch_mode'=>$pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),'foreign_keys'=>(int)$pdo->query('SELECT foreign_keys FROM pragma_foreign_keys')->fetchColumn(),'transaction'=>$pdo->inTransaction()];
        $pdo->rollBack();
        echo json_encode(['rows'=>$rows,'transaction'=>$pdo->inTransaction(),'session'=>$session], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    echo json_encode(['error'=>get_class($error)], JSON_THROW_ON_ERROR);
    exit(2);
} finally {
    foreach (['Connection','LoggingPdo','LoggingPdoStatement'] as $name) unlink($base . '/' . $name . '.php');
    foreach (['/api/src/Infrastructure/Database','/api/src/Infrastructure','/api/src','/api',''] as $suffix) rmdir($directory . $suffix);
}

