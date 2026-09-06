<?php

declare(strict_types=1);

/**
 * Auto-scan bankovnich emailovych aviz pres IMAP.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-bank-email-notices');

// Preflight PŘED stavbou kontejneru, stejně jako u cron-bank-scan: bez jediné
// zapnuté schránky nemá skener co dělat a zjišťovat to až přes DI kontejner
// znamená u instalace bez IMAPu 48 zbytečných bootstrapů denně.
if (!CronPreflight::hasBankEmailNoticeAccounts($conn->pdo())) {
    fwrite(STDOUT, "[bank-email-notices] žádná zapnutá IMAP schránka — přeskočeno.\n");
    $run->finish('ok', ['skipped' => 'no enabled imap accounts']);
    exit(0);
}

$container = Bootstrap::buildContainer();
$scanner = $container->get(BankEmailNoticeScanner::class);

$scanAll = (bool) $config->get('app.scan_all_suppliers', false);
if ($scanAll) {
    $supplierIds = array_map('intval', $conn->pdo()->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN));
} else {
    $supplierIds = [(int) $config->get('app.default_supplier_id', 1)];
}

$started = microtime(true);
$summary = [
    'suppliers' => 0,
    'processed' => 0,
    'matched' => 0,
    'known_skipped' => 0,
    'old_skipped' => 0,
    'errors' => 0,
    'details' => [],
];

foreach ($supplierIds as $sid) {
    if ($sid <= 0) continue;
    fwrite(STDOUT, '[' . date('H:i:s') . "] supplier {$sid} — bank email notices scan\n");
    try {
        $result = $scanner->scanSupplier($sid);
        $summary['suppliers']++;
        foreach (['processed', 'matched', 'known_skipped', 'old_skipped', 'errors'] as $key) {
            $summary[$key] += (int) ($result[$key] ?? 0);
        }
        if (!empty($result['error'])) {
            $summary['details'][] = ['supplier_id' => $sid, 'error' => $result['error']];
        }
    } catch (\Throwable $e) {
        $summary['errors']++;
        $summary['details'][] = ['supplier_id' => $sid, 'error' => $e->getMessage()];
        fwrite(STDERR, "[bank-email-notices] supplier {$sid}: " . $e->getMessage() . "\n");
    }
}

$summary['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
echo '[' . date('Y-m-d H:i:s') . '] bank-email-notices: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.bank_email_notices', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);

$run->finish($summary['errors'] > 0 ? 'error' : 'ok', $summary, $summary['errors'] > 0 ? 'Některé skeny selhaly.' : null);
