<?php

declare(strict_types=1);

/**
 * Cron — noční integrity job nad účetním deníkem (Fáze A/A6 audit 2026-07).
 *
 * Použití:
 *   php api/bin/cron-journal-integrity-check.php
 *   php api/bin/cron-journal-integrity-check.php --dry-run          (nic neukládá, jen vypíše)
 *   php api/bin/cron-journal-integrity-check.php --supplier=12      (jen jeden dodavatel)
 *
 * Pro KAŽDÉHO dodavatele v podvojném účetnictví (accounting_mode='double_entry')
 * spustí ČISTĚ ČTECÍ kontroly konzistence doklad ↔ deník (viz JournalIntegrityService):
 *   orphan_entry, unbalanced_entry, booked_without_entry, entry_without_booked,
 *   amount_mismatch; u firmy s jednoduchým (párovým) zápisem navíc kontroly dvojic
 *   pair_unbalanced, pair_incomplete, pair_same_account, single_unpaired.
 *
 * Job NIC NEOPRAVUJE — jen zapíše poslední výsledek do `journal_integrity_findings`
 * (separátní diagnostická tabulka; účetní data se nemění). Dashboard čte tento
 * poslední běh jako action item `journal_integrity`.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\JournalIntegrityService;
use MyInvoice\Service\Cron\CronRun;

$dryRun = false;
$onlySupplier = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (str_starts_with($arg, '--supplier=')) { $onlySupplier = (int) substr($arg, 11); continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

/** @var JournalIntegrityService $service */
$service = $container->get(JournalIntegrityService::class);

$run = CronRun::start($pdo, 'cron-journal-integrity-check');
$startedAt = microtime(true);

$supplierIds = $service->doubleEntrySupplierIds();
if ($onlySupplier !== null) {
    $supplierIds = in_array($onlySupplier, $supplierIds, true) ? [$onlySupplier] : [];
}

$report = [
    'dry_run'          => $dryRun,
    'suppliers'        => count($supplierIds),
    'suppliers_with_findings' => 0,
    'findings_total'   => 0,
    'by_type'          => [],
    'tenant_by_type'   => [],
    'tenant_total'     => 0,
    'errors'           => 0,
];
foreach (JournalIntegrityService::TYPES as $t) {
    $report['by_type'][$t] = 0;
}
// EP-11 tenantové/strukturální invarianty se NEUKLÁDAJÍ (ENUM findings tabulky je
// nepokrývá) a vyhodnocují se vždy naživo. Dřív je nespouštěl nikdo kromě testu —
// pět tvrdých invariantů tak bylo v provozu mrtvý kód. Běží tedy tady a hlásí se
// do výstupu i do activity_log; porušení znamená tenant leak nebo poškozený zápis.
foreach (JournalIntegrityService::TENANT_TYPES as $t) {
    $report['tenant_by_type'][$t] = 0;
}

echo "[" . date('Y-m-d H:i:s') . "] cron-journal-integrity-check"
    . ($dryRun ? ' --dry-run' : '') . " — " . count($supplierIds) . " double-entry supplier(s)\n";

$checkedAt = date('Y-m-d H:i:s');
foreach ($supplierIds as $sid) {
    try {
        $findings = $dryRun ? $service->check($sid) : $service->checkAndStore($sid, $checkedAt);
        $supplierTotal = 0;
        $parts = [];
        foreach ($findings as $type => $finding) {
            $cnt = $finding['count'];
            // Typy dvojic se do souhrnu přidávají, až je některá firma vrátí: instance
            // bez jednoduchého zápisu má souhrn běhu beze změny.
            $report['by_type'][$type] ??= 0;
            $supplierTotal += $cnt;
            $report['by_type'][$type] += $cnt;
            if ($cnt > 0) {
                $parts[] = "{$type}={$cnt}";
            }
        }
        $report['findings_total'] += $supplierTotal;

        $tenant = $service->checkTenantIntegrity($sid);
        $tenantParts = [];
        foreach (JournalIntegrityService::TENANT_TYPES as $type) {
            $cnt = $tenant[$type]['count'];
            $report['tenant_by_type'][$type] += $cnt;
            $report['tenant_total'] += $cnt;
            if ($cnt > 0) {
                $tenantParts[] = "{$type}={$cnt}";
            }
        }

        if ($supplierTotal > 0) {
            $report['suppliers_with_findings']++;
            printf("  ⚠ supplier #%d — %d nález(ů): %s\n", $sid, $supplierTotal, implode(', ', $parts));
        } else {
            printf("  ✓ supplier #%d — čisté\n", $sid);
        }
        if ($tenantParts !== []) {
            printf("  ⛔ supplier #%d — porušené invarianty deníku: %s\n", $sid, implode(', ', $tenantParts));
        }
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ supplier #%d — %s\n", $sid, $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): suppliers={$report['suppliers']}, with_findings={$report['suppliers_with_findings']}, "
    . "findings={$report['findings_total']}, tenant_violations={$report['tenant_total']}, errors={$report['errors']}"
    . ($dryRun ? ' (DRY RUN, nic se neuložilo)' : '') . "\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.journal_integrity', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

// Pojistka § 33a: záznamy commitnuté těsně před pádem procesu čekají na zapečetění
// do dalšího zápisu; na tiché instanci by to mohlo trvat dlouho.
$sealed = $container->get(\MyInvoice\Service\ActivityLogHashChain::class)->sealPending();
if ($sealed > 0) {
    echo "  auditní stopa: dopečetěno {$sealed} záznamů\n";
}

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
