<?php

declare(strict_types=1);

/**
 * Denní záloha sekce Dokumenty — storage/documents/ (VŠECHNY typy souborů, ne
 * jen PDF) → ZIP do storage/backup/{dbname}-documents-YYYY-MM-DD_H-i.zip.
 *
 * Záměrně ODDĚLENO od cron-backup-pdf.php — ten Dokumenty nezahrnuje.
 * Vynechává regenerovatelné náhledy (_thumbs/) a dočasné soubory (.tmp-*).
 * Retention: 30 denních + měsíční (1. v měsíci) drženy 365 dní.
 *
 * Vyžaduje PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\BackupLocation;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$dbName  = (string) $config->get('db.name');

$db = new Connection($config);
$run = CronRun::start($db->pdo(), 'cron-backup-documents');

// Resolve backup output dir — stejné pořadí jako cron-backup-pdf.php (issue #34).
$backupDir = BackupLocation::resolve($config);
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

if (!class_exists(ZipArchive::class)) {
    $msg = 'PHP ext-zip není nainstalována.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// Volitelné šifrování zálohy (cfg cron.backup.password, AES-256).
$zipPassword = BackupEncryption::passwordFromConfig($config);
if (($msg = BackupEncryption::unsupportedReason($zipPassword)) !== null) {
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

if (!is_dir(RuntimePaths::storage('documents')) && !is_dir(RuntimePaths::storage('journal'))) {
    echo "[" . date('Y-m-d H:i:s') . "] backup-documents: storage/documents/ ani storage/journal/ neexistuje, nic k záloze.\n";
    $run->finish('ok', ['files' => 0, 'note' => 'no documents dir']);
    exit(0);
}

$date = date('Y-m-d_H-i');
$file = "$backupDir/$dbName-documents-$date.zip";

// Content-addressed cesty nejsou užitečné pro člověka ani pro ruční obnovu.
// Sdílený layout je proto překládá přes metadata DB do složek a původních názvů;
// bez metadata zůstanou v explicitní složce _neprirazene, aby se neztratil bajt.
$files = (new \MyInvoice\Service\Backup\ReadableDocumentArchiveLayout($db->pdo()))->all();

if (count($files) === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] backup-documents: žádné dokumenty k záloze.\n";
    $run->finish('ok', ['files' => 0, 'note' => 'no documents to back up']);
    exit(0);
}

@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    $run->finish('error', null, 'cannot create zip', 1);
    exit(1);
}
foreach ($files as $item) {
    if (!$zip->addFile($item['source'], $item['entry'])) {
        fwrite(STDERR, "Cannot add to ZIP: " . $item['source'] . "\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'cannot add file', 1);
        exit(1);
    }
    if (!\MyInvoice\Service\Backup\BackupZipPermissions::neutralize($zip, $item['entry'])) {
        fwrite(STDERR, "Cannot normalize ZIP entry permissions: " . $item['entry'] . "\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip permission normalization failed', 1);
        exit(1);
    }
    if (!BackupEncryption::encryptEntry($zip, $item['entry'], $zipPassword)) {
        fwrite(STDERR, "Cannot encrypt ZIP entry: " . $item['entry'] . "\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip encryption failed', 1);
        exit(1);
    }
}
if (!$zip->close()) {
    @unlink($file);
    fwrite(STDERR, "ZIP close failed.\n");
    $run->finish('error', null, 'zip close failed', 1);
    exit(1);
}

if (!is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "ZIP backup is empty.\n");
    @unlink($file);
    $run->finish('error', null, 'empty zip', 1);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
$count = count($files);
echo "[" . date('Y-m-d H:i:s') . "] backup-documents: " . basename($file) . " ({$count} souborů, {$size} KB)\n";

$report = ['file' => basename($file), 'files' => $count, 'size_kb' => $size];
if ($zipPassword !== '') {
    $report['encrypted'] = 'AES-256';
}

// Retention: smaž zálohy starší 30 dní (1. v měsíci drž 365 dní).
// Filtrujeme jen vlastní prefix "{dbName}-documents-", aby se nedotklo DB dumpů ani PDF záloh.
$prefix = $dbName . '-documents-';
$existing = glob($backupDir . '/' . $prefix . '*.zip') ?: [];
$now = time();
foreach ($existing as $f) {
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})(?:_\d{2}-\d{2})?\.zip$/', $f, $m)) continue;
    $age = $now - strtotime($m[1]);
    $isMonthly = str_ends_with($m[1], '-01');
    $maxAge = $isMonthly ? 365 * 86400 : 30 * 86400;
    if ($age > $maxAge) {
        @unlink($f);
        echo "  - retention: smazáno " . basename($f) . "\n";
        $report['retention_purged'] = ($report['retention_purged'] ?? 0) + 1;
    }
}

$run->finish('ok', $report);
