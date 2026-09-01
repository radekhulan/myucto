<?php

declare(strict_types=1);

/**
 * DB backup — mariadb-dump → ZIP do storage/backup/.
 *
 * Rozvrh je v DATABÁZI (`backup_schedule_contract`, migrace 1521), ne tady:
 * self-host si nechá jeden dump denně, spravovaná instalace jede 4× (H-25).
 * Kód drží jen smluvní STROP — viz {@see BackupScheduleLimit}.
 *
 * Retence je pojmenovaný profil (H-05) — viz {@see BackupRetentionPolicy}.
 * ⚠️ Profil nese JEDNOTKU: „7" může být 7 dnů (při 4×/den 28 souborů) nebo
 * 7 kusů (necelé dva dny). Do logu se proto vypisuje i jednotka.
 *
 * Vyžaduje v PATH: mariadb-dump (případně mysqldump) a PHP ext-zip.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\DumpDefinerSanitizer;
use MyInvoice\Service\Backup\BackupRetentionPolicy;
use MyInvoice\Service\Backup\BackupSchedule;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);

$backupPdo = (new Connection($config))->pdo();
$run = CronRun::start($backupPdo, 'cron-backup');

// Smluvní strop frekvence (H-25). Rozvrh se sem nezapisuje — jen se hlásí, když
// se uložený stav rozešel s tím, co je zaplacené. Zálohu to NEZASTAVÍ: neběžet
// je horší než běžet často, a rozpor se řeší dodatkem, ne přeskočeným dumpem.
$scheduleIssue = BackupSchedule::inconsistency($backupPdo);
if ($scheduleIssue !== null) {
    fwrite(STDERR, "[WARN] rozvrh zálohy: $scheduleIssue\n");
}

$dbHost = (string) $config->get('db.host');
$dbName = (string) $config->get('db.name');
$dbUser = (string) $config->get('db.user');
$dbPass = (string) $config->get('db.pass');
$dbPort = (int)    $config->get('db.port', 3306);

// Resolve backup output dir v pořadí:
//   1) cfg.cron.backup.output_dir (explicitní override)
//   2) cfg.storage.backup_dir (sdílená dokumentovaná cesta)
//   3) MYINVOICE_DATA_DIR/storage/backup (PaaS deploy s ephemeral filesystem)
//   4) rootDir/storage/backup (klasický VPS / dev fallback)
// Per issue #34: bez tohoto fallbacku skončil ZIP v ephemeral filesystému image
// (Fly.io / Railway / Render) a zmizel s každým deployem, i když uživatel měl
// správně nastavený MYINVOICE_DATA_DIR=/data.
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

// Volitelné šifrování zálohy (cfg cron.backup.password, AES-256).
$zipPassword = BackupEncryption::passwordFromConfig($config);
if (($msg = BackupEncryption::unsupportedReason($zipPassword)) !== null) {
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

$date    = date('Y-m-d_H-i');
$file    = "$backupDir/$dbName-$date.zip";
$sqlTmp  = "$backupDir/.$dbName-$date.sql";
$sqlName = "$dbName-$date.sql";

// Test dostupnosti dump nástroje:
//   1) explicitní cesta z configu (db.dump_tool)
//   2) PATH (mariadb-dump → mysqldump)
//   3) běžné instalační lokace na Windows
$inPath = static function (string $bin): bool {
    $out = []; $rc = 1;
    @exec(sprintf('%s --version 2>&1', escapeshellarg($bin)), $out, $rc);
    return $rc === 0;
};
$tool = (string) $config->get('db.dump_tool', '');
if ($tool !== '' && !@is_executable($tool)) $tool = '';
if ($tool === '') {
    if      ($inPath('mariadb-dump')) $tool = 'mariadb-dump';
    else if ($inPath('mysqldump'))    $tool = 'mysqldump';
}
if ($tool === '' && stripos(PHP_OS, 'WIN') === 0) {
    $candidates = array_merge(
        glob('C:\\Program Files\\MariaDB*\\bin\\mariadb-dump.exe') ?: [],
        glob('C:\\Program Files\\MariaDB*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\Program Files\\MySQL\\*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mariadb-dump.exe')        ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mysqldump.exe')           ?: [],
        glob('C:\\xampp\\mysql\\bin\\mysqldump.exe')               ?: [],
        glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe')     ?: []
    );
    $tool = $candidates[0] ?? '';
}
if ($tool === '') {
    $msg = 'mariadb-dump ani mysqldump není v PATH (ani v běžných instalačních cestách). Nastav db.dump_tool v cfg.php.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// Heslo + connection params přes --defaults-extra-file (ne v cmdline, ne přes env).
// Win-fix: 'localhost' se občas nezresolvuje (mariadb-dump 2005 / 11003) → 127.0.0.1.
$dumpHost = ($dbHost === 'localhost') ? '127.0.0.1' : $dbHost;
$cnf = tempnam(sys_get_temp_dir(), 'myinv-dump-') ?: ($backupDir . '/.dump.cnf');
file_put_contents($cnf, sprintf(
    "[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
    $dumpHost,
    $dbPort,
    $dbUser,
    str_replace(['\\', '"'], ['\\\\', '\\"'], $dbPass)
));
@chmod($cnf, 0600);

// errFile i fallback cnf MUSÍ ležet ve vyřešeném $backupDir (ten je vytvořen výše),
// ne natvrdo v $rootDir/storage/backup — na Docker/PaaS s MYINVOICE_DATA_DIR ten
// adresář neexistuje a shell redirect `2>$errFile` selže s rc=2 ještě před dumpem (#42, zbytek po #34).
$errFile     = $backupDir . '/.last-error';
$skipRoutines = (bool) $config->get('db.backup_skip_routines', false);

$runDump = static function (bool $withRoutines) use ($tool, $cnf, $dbName, $errFile, $sqlTmp, $rootDir): array {
    @unlink($errFile);
    $routinesFlag = $withRoutines ? '--routines --triggers' : '--skip-routines --triggers';
    $cmd = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --quick %s %s 2>%s',
        escapeshellcmd($tool),
        escapeshellarg($cnf),
        $routinesFlag,
        escapeshellarg($dbName),
        escapeshellarg($errFile)
    );
    $out = fopen($sqlTmp, 'wb');
    if (!$out) return [1, "cannot open temp SQL: $sqlTmp"];

    $proc = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $rootDir
    );
    if (!is_resource($proc)) {
        fclose($out);
        return [1, 'cannot start backup process'];
    }
    fclose($pipes[0]);
    fclose($pipes[2]);
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false || $chunk === '') break;
        fwrite($out, $chunk);
    }
    fclose($pipes[1]);
    $rc = proc_close($proc);
    fclose($out);

    $err = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';
    return [$rc, $err];
};

// 1) dump do dočasného .sql souboru — primární pokus
[$rc, $err] = $runDump(!$skipRoutines);

// Auto-fallback: pokud user nemá oprávnění na SHOW CREATE PROCEDURE/FUNCTION,
// retry bez routines a hlasitě varuj.
$privIssue = !$skipRoutines
    && stripos($err, 'insufficient privileges') !== false
    && (stripos($err, 'PROCEDURE') !== false || stripos($err, 'FUNCTION') !== false);
if ($rc !== 0 && $privIssue) {
    fwrite(STDERR, "[WARN] mariadb-dump nemá privilegia pro stored procedures/functions:\n  $err\n");
    fwrite(STDERR, "[WARN] Retry BEZ --routines (procedury/funkce v této záloze NEBUDOU).\n");
    fwrite(STDERR, "[HINT] Pro plný backup grantni privilege, např.:\n");
    fwrite(STDERR, "  GRANT SELECT ON mysql.proc TO '$dbUser'@'%';\n");
    fwrite(STDERR, "  -- nebo pro MariaDB 10.5+:\n");
    fwrite(STDERR, "  GRANT SHOW CREATE ROUTINE ON $dbName.* TO '$dbUser'@'%';\n");
    fwrite(STDERR, "  -- nebo trvale: db.backup_skip_routines = true v cfg.php\n");
    [$rc, $err] = $runDump(false);
}

@unlink($cnf);

if ($rc !== 0 || !is_file($sqlTmp) || filesize($sqlTmp) < 100) {
    $msg = "Backup selhal (rc=$rc)" . ($err !== '' ? ": $err" : '');
    fwrite(STDERR, "$msg\n");
    @unlink($sqlTmp);
    $run->finish('error', null, $msg, 1);
    exit(1);
}

try {
    DumpDefinerSanitizer::sanitizeFile($sqlTmp);
} catch (Throwable $e) {
    $msg = 'Backup selhal při odstranění databázových DEFINERů: ' . $e->getMessage();
    fwrite(STDERR, "$msg\n");
    @unlink($sqlTmp);
    $run->finish('error', null, $msg, 1);
    exit(1);
}

// 2) zabalit do ZIP a dočasný .sql smazat
@unlink($file);
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($sqlTmp);
    fwrite(STDERR, "Cannot create ZIP: $file\n");
    exit(1);
}
$zip->addFile($sqlTmp, $sqlName);
if (defined('ZipArchive::CM_DEFLATE')) {
    $zip->setCompressionName($sqlName, ZipArchive::CM_DEFLATE, 9);
}
if (!\MyInvoice\Service\Backup\BackupZipPermissions::neutralize($zip, $sqlName)) {
    $zip->close();
    @unlink($sqlTmp); @unlink($file);
    $msg = 'ZIP permission normalization failed.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}
if (!BackupEncryption::encryptEntry($zip, $sqlName, $zipPassword)) {
    $zip->close();
    @unlink($sqlTmp); @unlink($file);
    $msg = 'ZIP encryption failed.';
    fwrite(STDERR, "$msg\n");
    $run->finish('error', null, $msg, 1);
    exit(1);
}
if (!$zip->close()) {
    @unlink($sqlTmp); @unlink($file);
    fwrite(STDERR, "ZIP close failed.\n");
    exit(1);
}
@unlink($sqlTmp);

if (!is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "ZIP backup is empty.\n");
    @unlink($file);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
echo "[" . date('Y-m-d H:i:s') . "] backup: " . basename($file) . " ({$size} KB)\n";

$report = ['file' => basename($file), 'size_kb' => $size];
if ($zipPassword !== '') {
    $report['encrypted'] = 'AES-256';
}

// Retence (H-05) — profil z konfigurace, ne konstanta v kódu.
//
// ⚠️ Do H-05 tu bylo natvrdo 30/365 a cfg klíče `cron.backup.*_retention_days`
// se NEČETLY VŮBEC — kdo si je nastavil, měl dojem, že něco změnil. Teď je
// jediným zdrojem pravdy BackupRetentionPolicy.
//
// ⚠️ Jednotka je součást nastavení. Při 4× denním dumpu (H-25) znamená „7 dnů"
// 28 souborů a „7 kusů" necelé dva dny — proto se vypisuje i do logu.
//
// Bere v potaz i staré .sql.gz formáty z dřívějška. Filtrujeme jen DB dumpy
// "{dbName}-YYYY-MM-DD[_HH-MM].{zip,sql.gz}" — PDF backup (cron-backup-pdf)
// má vlastní prefix "{dbName}-pdf-" a vlastní retention.
$retention = BackupRetentionPolicy::fromConfig($config);
$runsPerDay = BackupSchedule::current($backupPdo)['runs_per_day'];
echo "  - " . $retention->describe()
    . sprintf(' (při %d×/den drží ~%d denních souborů)', $runsPerDay, $retention->expectedDailyFiles($runsPerDay))
    . "\n";
$report['retention'] = $retention->describe();

$files = array_merge(
    glob($backupDir . '/' . $dbName . '-2*.zip')    ?: [],
    glob($backupDir . '/' . $dbName . '-2*.sql.gz') ?: []
);
$dated = [];
foreach ($files as $f) {
    $base = basename($f);
    if (str_starts_with($base, $dbName . '-pdf-')) continue;
    // Časová část je volitelná — dumpy z doby před H-25 mají jen datum.
    // Bez ní se za čas bere půlnoc, takže starší soubory nikdy nevytlačí
    // novější ze stejného dne.
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})(?:_(\d{2})-(\d{2}))?\.(zip|sql\.gz)$/', $f, $m)) continue;
    $at = new DateTimeImmutable($m[1] . ' ' . ($m[2] ?? '00') . ':' . ($m[3] ?? '00') . ':00');
    $dated[$f] = $at;
}

foreach ($retention->purgeList($dated, new DateTimeImmutable('now')) as $f) {
    @unlink($f);
    echo "  - retention: smazáno " . basename($f) . "\n";
    $report['retention_purged'] = ($report['retention_purged'] ?? 0) + 1;
}

$run->finish('ok', $report);
