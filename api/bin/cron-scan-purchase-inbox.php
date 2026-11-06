<?php

declare(strict_types=1);

/**
 * Auto-scan inbox přijatých faktur (PDF / ISDOC) — periodic cron.
 *
 * Konfigurace v cfg.php:
 *   'purchase_invoice' => [
 *     'inbox_dir' => 'C:/Users/.../Faktury/Inbox',  // adresář kam dodavatelé posílají PDF
 *     'allowed_exts' => ['pdf', 'isdoc', 'xml'],
 *   ]
 *
 * Skenuje rekurzivně inbox_dir, hledá podporované soubory a seskupuje je do zásilek
 * podle základu jména (`faktura.isdoc` + `faktura.pdf` = jeden doklad). Pro každou:
 *   - SHA-256 dedup vůči purchase_invoices.pdf_hash i source_hash
 *   - Je-li v zásilce ISDOC/ISDOCX/XML → data z něj, sourozenecké PDF se jen archivuje
 *     (AI se nevolá vůbec)
 *   - Samotné PDF s embedded ISDOC → parse + vytvoř draft
 *   - Samotné PDF bez ISDOC a tenant má Anthropic AI nakonfigurovanou →
 *     volá AI extract + vytvoří draft
 *   - Jinak skipne s důvodem
 *
 * Globální inbox_dir patří právě jedné firmě: purchase_invoice.cron_supplier_id.
 * Bez něj se použije explicitní app.default_supplier_id (jen bez scan_all_suppliers),
 * nebo jediná existující firma. Více firem bez určení vlastníka je chyba konfigurace.
 * Podadresáře neurčují vlastníka; sdílený inbox se nikdy neskenuje za všechny firmy.
 *
 * Idempotent: SHA-256 dedup zaručuje, že stejný soubor se nezpracuje 2×.
 * Cron může běžet libovolně často (typicky každých 5-15 min).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Cron\PurchaseInboxSupplierScope;
use MyInvoice\Service\Import\PurchaseInvoiceInboxScanner;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-scan-purchase-inbox');

$inboxDir = (string) $config->get('purchase_invoice.inbox_dir', '');
if ($inboxDir === '' || !is_dir($inboxDir)) {
    fwrite(STDERR, "[scan-purchase-inbox] cfg.purchase_invoice.inbox_dir neexistuje: " . ($inboxDir ?: '(prázdné)') . "\n");
    $run->finish('ok', ['skipped' => 'inbox_dir not configured', 'inbox_dir' => $inboxDir]);
    exit(0);
}

$pdo = $conn->pdo();
$scope = new PurchaseInboxSupplierScope();
try {
    $supplierId = $scope->resolve($pdo, $config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[scan-purchase-inbox] " . $e->getMessage() . "\n");
    $run->finish('error', ['error' => $e->getMessage()]);
    exit(1);
}

if ($supplierId === null) {
    fwrite(STDERR, "[scan-purchase-inbox] Žádní suppliers ke skenování.\n");
    $run->finish('ok', ['skipped' => 'no suppliers']);
    exit(0);
}
$supplierIds = [$supplierId];
if ($scope->warning !== null) {
    fwrite(STDERR, "[scan-purchase-inbox] WARN: {$scope->warning}
");
}

// Cron user — actions ho použijí pro created_by audit (FK users.id).
// 1) explicit config app.cron_user_id pokud nastaven a existuje
// 2) fallback: první aktivní admin (nejnižší id)
$cronUserId = (int) $config->get('app.cron_user_id', 0);
if ($cronUserId > 0) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$cronUserId]);
    if (!$stmt->fetchColumn()) {
        fwrite(STDERR, "[scan-purchase-inbox] WARN: app.cron_user_id={$cronUserId} neexistuje/neaktivní, fallback na admin\n");
        $cronUserId = 0;
    }
}
if ($cronUserId === 0) {
    $stmt = $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.system_key = 'superadmin' AND r.role_type = 'superadmin' AND r.is_active = 1
            AND u.is_active = 1 ORDER BY u.id ASC LIMIT 1"
    );
    $cronUserId = (int) ($stmt->fetchColumn() ?: 0);
    if ($cronUserId === 0) {
        fwrite(STDERR, "[scan-purchase-inbox] FATAL: žádný aktivní admin uživatel — nelze pokračovat (created_by FK).\n");
        $run->finish('error', ['error' => 'no admin user for created_by audit']);
        exit(1);
    }
}

$container = Bootstrap::buildContainer();
$scanner   = $container->get(PurchaseInvoiceInboxScanner::class);

$started      = microtime(true);
$totalSummary = ['suppliers' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];
// Selhání celého skenu (výjimka) je chyba úlohy. Jednotlivý vadný soubor ne: zůstane
// v přehledu non_imported, jinak by jedna rozbitá PDF shazovala každý běh cronu.
$scanErrors = 0;

// Live progress callback — píše per-file na STDOUT s explicit fflush,
// protože PHP stdout je full-buffered když je pipnutý (Tee-Object).
// `-d output_buffering=0` vypne jen PHP userland buffer, ne C stdio buffer,
// takže bez fflush by se output v cmd okně objevil až na konci běhu.
$progress = static function (array $event): void {
    if (($event['phase'] ?? '') === 'start') {
        $line = sprintf(
            "  [%d/%d] %s …\n",
            (int) ($event['index'] ?? 0),
            (int) ($event['total'] ?? 0),
            basename((string) ($event['file'] ?? '')),
        );
    } else {
        $status = (string) ($event['status'] ?? '?');
        $reason = (string) ($event['reason'] ?? '');
        $extra  = '';
        if (!empty($event['purchase_invoice_id'])) {
            $extra = ' (#' . (int) $event['purchase_invoice_id'] . ')';
        }
        $line = sprintf("      → %s%s%s\n", $status, $extra, $reason !== '' ? ' — ' . $reason : '');
        if (!empty($event['warning'])) {
            $line .= '      ⚠ ' . (string) $event['warning'] . "\n";
        }
    }
    fwrite(STDOUT, $line);
    fflush(STDOUT);
};

foreach ($supplierIds as $sid) {
    fwrite(STDOUT, "[" . date('H:i:s') . "] supplier {$sid} — scan start\n");
    fflush(STDOUT);
    try {
        $result = $scanner->scan($sid, $cronUserId, false, $progress);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[scan-purchase-inbox] supplier {$sid}: " . $e->getMessage() . "\n");
        $totalSummary['failed']++;
        $scanErrors++;
        $totalSummary['details'][] = ['supplier_id' => $sid, 'status' => 'failed', 'reason' => $e->getMessage()];
        continue;
    }
    $totalSummary['suppliers']++;
    $totalSummary['created'] += (int) ($result['created'] ?? 0);
    $totalSummary['skipped'] += (int) ($result['skipped'] ?? 0);
    $totalSummary['failed']  += (int) ($result['failed']  ?? 0);
    // Jen failed/skipped do logu (úspěšné importy jsou očekávaný šum) — plus doklady,
    // které vznikly, ale nesou varování ke spárovanému PDF (ty chce člověk vidět).
    foreach ($result['details'] ?? [] as $d) {
        $status = (string) ($d['status'] ?? '');
        if (($status !== 'imported' && $status !== 'created') || !empty($d['warning'])) {
            $totalSummary['details'][] = ['supplier_id' => $sid] + $d;
        }
    }
}

$ms = (int) ((microtime(true) - $started) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] scan-purchase-inbox ({$ms} ms): "
    . json_encode([
        'suppliers' => $totalSummary['suppliers'],
        'created'   => $totalSummary['created'],
        'skipped'   => $totalSummary['skipped'],
        'failed'    => $totalSummary['failed'],
    ], JSON_UNESCAPED_UNICODE) . "\n";

// Vypsat issue items (jen pokud existují) pro debug v log souboru
if (!empty($totalSummary['details'])) {
    echo "  Non-imported items (" . count($totalSummary['details']) . "):\n";
    foreach ($totalSummary['details'] as $d) {
        $file = isset($d['file']) ? basename((string) $d['file']) : '?';
        echo "    [{$d['status']}] {$file} — " . ($d['reason'] ?? '') . "\n";
        if (!empty($d['warning'])) {
            echo "        ⚠ " . (string) $d['warning'] . "\n";
        }
    }
}

// Normalize details: relative path (skrýt absolute system path), limit na ~50 items pro JSON velikost
$inboxDirCfg = (string) $config->get('purchase_invoice.inbox_dir', '');
$inboxReal   = $inboxDirCfg !== '' ? realpath($inboxDirCfg) : false;
$detailsForReport = array_slice(array_map(static function (array $d) use ($inboxReal): array {
    $file = (string) ($d['file'] ?? '');
    if ($inboxReal !== false && $file !== '' && str_starts_with($file, $inboxReal)) {
        $file = ltrim(substr($file, strlen($inboxReal)), DIRECTORY_SEPARATOR . '/');
    } else {
        $file = basename($file);
    }
    return [
        'supplier_id' => $d['supplier_id'] ?? null,
        'file'        => $file,
        'status'      => (string) ($d['status'] ?? ''),
        'reason'      => (string) ($d['reason'] ?? ''),
        'warning'     => (string) ($d['warning'] ?? ''),
    ];
}, $totalSummary['details']), 0, 50);

$run->finish($scanErrors > 0 ? 'error' : 'ok', [
    'suppliers'           => $totalSummary['suppliers'],
    'created'             => $totalSummary['created'],
    'skipped'             => $totalSummary['skipped'],
    'failed'              => $totalSummary['failed'],
    'duration_ms'         => $ms,
    'non_imported_count'  => count($totalSummary['details']),
    'non_imported'        => $detailsForReport,
    'non_imported_truncated' => count($totalSummary['details']) > count($detailsForReport),
] + ($scope->warning !== null ? ['warning' => $scope->warning] : []));
exit($scanErrors > 0 ? 1 : 0);
