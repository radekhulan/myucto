<?php

declare(strict_types=1);

/**
 * Denní cleanup — login_attempts (>24h), expirované a staré odvolané sessions,
 * použité password_resets, login_otps + trusted_devices, WebAuthn flow/proofy,
 * expirované jednorázové granty a log files >90 dní.
 *
 * POZN: PDF se NEMAŽE. Aktivní cache může pominout (renderer ji znovu vytvoří),
 * ale archivovaná historie (storage/invoices/sup-N/_archive) obsahuje verze
 * skutečně odeslané klientovi a je důkazem fakturace.
 *
 * Použití (Windows Task Scheduler):
 *   php api/bin/cron-cleanup.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

$run = CronRun::start($pdo, 'cron-cleanup');
$startedAt = microtime(true);
$report = [];

// 1) login_attempts — drop záznamy starší 24 hodin
$n = $pdo->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 24 HOUR");
$report['login_attempts'] = (int) $n;

// 1b) rate_limit_counters — DB fallback limiteru. Běžný úklid dělá middleware sám
// při zakládání nového bucketu (viz migrace 1317); tohle je jen záchranná brzda pro
// instalace bez provozu. Maže se podle absolutní expirace okna, ne podle stáří řádku.
$n = $pdo->exec("DELETE FROM rate_limit_counters WHERE expires_at < UNIX_TIMESTAMP()");
$report['rate_limit_counters'] = (int) $n;

// 2) sessions — TIMESTAMP expires_at porovnáváme s CURRENT_TIMESTAMP, aby
// MariaDB správně zohlednila session timezone. UTC DATETIME revoked_at naopak
// porovnáváme s UTC_TIMESTAMP. Nahrazené generace bez revoked_at držíme jako
// logout tombstones až do jejich absolutní expirace.
$n = $pdo->exec('DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP(6)');
$report['expired_sessions'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM sessions
      WHERE revoked_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY'
);
$report['revoked_sessions'] = (int) $n;

// 2b) WebAuthn ceremonies a MFA proofy — po expiraci/spotřebování drž nejvýše 1 den.
$n = $pdo->exec(
    'DELETE FROM webauthn_ceremonies
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['webauthn_ceremonies'] = (int) $n;
$n = $pdo->exec(
    'DELETE FROM mfa_step_up_proofs
      WHERE expires_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY
         OR used_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY'
);
$report['mfa_step_up_proofs'] = (int) $n;

// 3) password_resets — použité nebo expirované >7 dní
$n = $pdo->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW() - INTERVAL 7 DAY");
$report['password_resets'] = (int) $n;

// 3b) login_otps (e-mailové 2FA kódy) — použité/expirované >1 den
$n = $pdo->exec("DELETE FROM login_otps WHERE used_at < NOW() - INTERVAL 1 DAY OR expires_at < NOW() - INTERVAL 1 DAY");
$report['login_otps'] = (int) $n;

// 3c) trusted_devices — expirovaná „zapamatovaná zařízení"
$n = $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()");
$report['trusted_devices'] = (int) $n;

// 3d) api_request_log — log volání veřejného API (MCP server) starší 90 dní.
// Není to auditní stopa podle § 33a (ta je v activity_log a maže se NIKDY), jen
// provozní přehled „co token volal", takže retence je krátká a bez pečetění.
$n = $pdo->exec("DELETE FROM api_request_log WHERE ts < NOW() - INTERVAL 90 DAY");
$report['api_request_log'] = (int) $n;

// 3e) Jednorázové mzdové download granty — audit stažení zůstává v activity_log.
$n = $pdo->exec(
    'DELETE FROM payroll_document_download_grants
      WHERE expires_at < NOW() - INTERVAL 1 DAY
         OR used_at < NOW() - INTERVAL 1 DAY'
);
$report['payroll_download_grants'] = (int) $n;

// 3f) Ostatní jednorázové mzdové download granty (W30 / D-06). Uklízel se jen
// grant na jednotlivý dokument; granty na PERIOD-EXPORT, platební export
// a artefakt podání se hromadily donekonečna. Není to jen kosmetika:
// nepoužitý grant je platný odkaz na kompletní mzdový archiv období, takže
// každý zapomenutý řádek je živá cesta k datům, kterou nikdo neeviduje.
// Retence je stejná jako u dokumentových grantů — jeden den po expiraci nebo
// po použití; audit stažení zůstává v activity_log, ne tady.
foreach ([
    'payroll_period_export_download_grants' => 'payroll_period_export_grants',
    'payroll_payment_export_download_grants' => 'payroll_payment_export_grants',
    'payroll_submission_artifact_download_grants'
        => 'payroll_submission_artifact_grants',
] as $grantTable => $reportKey) {
    $n = $pdo->exec(
        'DELETE FROM ' . $grantTable
        . ' WHERE expires_at < NOW() - INTERVAL 1 DAY'
        . '    OR used_at < NOW() - INTERVAL 1 DAY'
    );
    $report[$reportKey] = (int) $n;
}

// 4) ARES/VIES cache — starší 30 dní
$n = $pdo->exec("DELETE FROM ares_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['ares_cache'] = (int) $n;
$n = $pdo->exec("DELETE FROM vies_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['vies_cache'] = (int) $n;

// 5) Log files — Monolog rotuje, ale když je config zapnutý max_files, držíme se ho
$logDir = (string) $config->get('logging.path', $rootDir . '/log/app.log');
$logDir = dirname($logDir);
$maxFiles = (int) $config->get('logging.max_files', 90);
$logDeleted = 0;
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log') ?: [];
    if (count($files) > $maxFiles) {
        usort($files, fn ($a, $b) => filemtime($a) - filemtime($b));
        $toDel = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($toDel as $f) if (@unlink($f)) $logDeleted++;
    }
}
$report['log_files'] = $logDeleted;

// 6) Měsíční exporty — smaž dokončené/neúspěšné/zrušené joby starší 7 dní
//    vč. jejich ZIP souboru (retence: do ručního smazání, jinak reaper po 7 dnech).
$exportBase = ($config->dataDir() ?? $rootDir) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'monthly-exports';
$stmt = $pdo->query(
    "SELECT id, result_path FROM import_jobs
      WHERE source = 'monthly_export'
        AND status IN ('completed', 'failed', 'cancelled')
        AND COALESCE(finished_at, created_at) < NOW() - INTERVAL 7 DAY"
);
if ($stmt === false) {
    throw new RuntimeException('Monthly export cleanup query failed.');
}
$exportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$exportFilesDeleted = 0;
$exportIds = [];
foreach ($exportRows as $r) {
    $exportIds[] = (int) $r['id'];
    $rel = (string) ($r['result_path'] ?? '');
    if ($rel === '') continue;
    $abs = realpath($exportBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
    $baseReal = realpath($exportBase);
    // Path-traversal guard — maž jen v rámci storage/monthly-exports.
    if ($abs !== false && $baseReal !== false && is_file($abs)
        && str_starts_with(strtolower($abs), strtolower($baseReal) . DIRECTORY_SEPARATOR)) {
        if (@unlink($abs)) $exportFilesDeleted++;
    }
}
if ($exportIds !== []) {
    $in = implode(',', array_fill(0, count($exportIds), '?'));
    $pdo->prepare("DELETE FROM import_jobs WHERE id IN ($in)")->execute($exportIds);
}
$report['monthly_export_jobs']  = count($exportIds);
$report['monthly_export_files'] = $exportFilesDeleted;

// Pročisti cron_runs — drž max 500 posledních záznamů na skript.
$report['cron_runs_purged'] = CronRun::purgeOld($pdo, 500);

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] cron-cleanup ({$ms} ms): " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

// Audit do activity_log
$pdo->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.cleanup', ?)"
)->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', $report);
