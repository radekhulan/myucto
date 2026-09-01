<?php

declare(strict_types=1);

/**
 * Denní záloha mzdového úložiště — storage/payroll-documents/,
 * storage/payroll-period-exports/ a storage/payroll-payment-exports/
 * → ZIP do storage/backup/{dbname}-payroll-YYYY-MM-DD_H-i.zip.
 *
 * PROČ SAMOSTATNÝ CRON: mzdové soubory nepokrývala žádná ze stávajících záloh.
 * `cron-backup.php` dělá jen dump databáze, `cron-backup-documents.php` bere
 * `storage/documents` a `storage/journal`, `cron-backup-pdf.php` faktury
 * a výkazy práce. Výplatní pásky, měsíční archivy a soubory platebních příkazů
 * tak byly jen na disku: po obnově ze zálohy by databáze měla otisky, revize
 * i doručení, ale obsah k nim ne — a jsou to dokumenty se zákonnou archivační
 * lhůtou.
 *
 * Artefakty podání (JMHZ, přehledy pojišťovnám) tady nejsou schválně: ukládají
 * se do sloupce `content_ciphertext` v `payroll_submission_artifacts`, takže
 * jedou v dumpu databáze a druhá kopie by byla jen zdvojený osobní údaj.
 *
 * Soubory se ukládají tak, jak leží — šifrované a pod otiskem. Důvod je
 * v {@see \MyInvoice\Service\Backup\PayrollBackupArchiveLayout}; pro člověka
 * je v ZIPu `MANIFEST.csv` a `CTI-MNE.txt`.
 *
 * Retention: 30 denních + měsíční (1. v měsíci) drženy 365 dní — stejně jako
 * u ostatních záloh, ať se pravidla nemusí pamatovat zvlášť.
 *
 * Vyžaduje PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\PayrollBackupArchiveLayout;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$dbName  = (string) $config->get('db.name');

$db = new Connection($config);
$run = CronRun::start($db->pdo(), 'cron-backup-payroll');

// Resolve backup output dir — stejné pořadí jako ostatní zálohy (issue #34).
$backupDir = (string) $config->get('cron.backup.output_dir', '');
if ($backupDir === '') {
    $backupDir = (string) $config->get('storage.backup_dir', '');
}
if ($backupDir === '') {
    $dataDir = (string) (getenv('MYINVOICE_DATA_DIR') ?: '');
    $backupDir = $dataDir !== '' ? rtrim($dataDir, '/\\') . '/storage/backup' : $rootDir . '/storage/backup';
}
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

if (!class_exists(ZipArchive::class)) {
    $msg = 'PHP ext-zip není nainstalována.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// Volitelné šifrování zálohy (cfg cron.backup.password, AES-256). U mzdových
// dat to dává smysl dvojnásob, ale nevynucuje se: samotné soubory už šifrované
// jsou a tvrdý požadavek by na instalacích bez hesla zálohu úplně zastavil.
$zipPassword = BackupEncryption::passwordFromConfig($config);
if (($msg = BackupEncryption::unsupportedReason($zipPassword)) !== null) {
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

$layout = new PayrollBackupArchiveLayout($db->pdo());
$files = $layout->all();

if (count($files) === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] backup-payroll: mzdové úložiště je prázdné, nic k záloze.\n";
    $run->finish('ok', ['files' => 0, 'note' => 'no payroll files']);
    exit(0);
}

$date = date('Y-m-d_H-i');
$file = "$backupDir/$dbName-payroll-$date.zip";

@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    $run->finish('error', null, 'cannot create zip', 1);
    exit(1);
}

/**
 * Zapíše položku a hned na ni pověsí normalizaci práv i šifrování. Kdyby
 * kterýkoli krok selhal, rozdělaný ZIP se maže — poloviční záloha je horší
 * než žádná, protože vypadá jako hotová.
 */
$pridej = static function (callable $zapis, string $entry) use ($zip, $file, $run, $zipPassword): void {
    if (!$zapis()) {
        fwrite(STDERR, "Cannot add to ZIP: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'cannot add file', 1);
        exit(1);
    }
    if (!\MyInvoice\Service\Backup\BackupZipPermissions::neutralize($zip, $entry)) {
        fwrite(STDERR, "Cannot normalize ZIP entry permissions: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip permission normalization failed', 1);
        exit(1);
    }
    if (!BackupEncryption::encryptEntry($zip, $entry, $zipPassword)) {
        fwrite(STDERR, "Cannot encrypt ZIP entry: $entry\n");
        $zip->close();
        @unlink($file);
        $run->finish('error', null, 'zip encryption failed', 1);
        exit(1);
    }
};

foreach ($files as $item) {
    $pridej(
        static fn (): bool => $zip->addFile($item['source'], $item['entry']),
        $item['entry'],
    );
}

$manifest = $layout->manifestCsv();
$pridej(
    static fn (): bool => $zip->addFromString('MANIFEST.csv', $manifest),
    'MANIFEST.csv',
);

$navod = implode("\n", [
    'ZALOHA MZDOVEHO ULOZISTE MYUCTO.CZ',
    '',
    'Obsahuje vyplatni pasky a dalsi mzdove dokumenty, mesicni a rocni archivy',
    'a soubory platebnich prikazu. Podani (JMHZ, prehledy pojistovnam) tu nejsou -',
    'ta jsou ulozena primo v databazi a jedou v jejim dumpu.',
    '',
    'OBNOVA',
    '  Rozbalte obsah archivu do adresare storage/ instalace. Cesty uvnitr uz',
    '  odpovidaji cilovemu rozlozeni, nic se neprejmenovava.',
    '',
    'DULEZITE',
    '  Soubory jsou sifrovane a adresovane svym otiskem. Samy o sobe se otevrit',
    '  NEDAJI a bez odpovidajiciho dumpu databaze jsou k nicemu - obnovujte je',
    '  vzdy spolu s dumpem ze stejneho dne.',
    '',
    '  Co se pod kterym otiskem skryva, je v MANIFEST.csv.',
    '',
]);
$pridej(
    static fn (): bool => $zip->addFromString('CTI-MNE.txt', $navod),
    'CTI-MNE.txt',
);

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
echo "[" . date('Y-m-d H:i:s') . "] backup-payroll: " . basename($file) . " ({$count} souborů, {$size} KB)\n";

$report = ['file' => basename($file), 'files' => $count, 'size_kb' => $size];
if ($zipPassword !== '') {
    $report['encrypted'] = 'AES-256';
}

// Retention: smaž zálohy starší 30 dní (1. v měsíci drž 365 dní).
// Filtrujeme jen vlastní prefix "{dbName}-payroll-", ať se nedotkne cizích záloh.
$prefix = $dbName . '-payroll-';
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
