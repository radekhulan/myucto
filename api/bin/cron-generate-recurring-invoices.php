<?php

declare(strict_types=1);

/**
 * Cron — vygeneruje faktury z aktivních pravidelných šablon.
 *
 * Použití:
 *   php api/bin/cron-generate-recurring-invoices.php
 *   php api/bin/cron-generate-recurring-invoices.php --dry-run
 *
 * Pro každou šablonu kde:
 *   - status = 'active'
 *   - next_run_date <= CURDATE()
 *   - (end_date IS NULL OR next_run_date <= end_date)
 *   - supplier.auto_generate_recurring = 1
 *
 * Vygeneruje fakturu přes RecurringInvoiceGenerator (klon šablony + items,
 * volitelně rovnou vystaví a odešle podle per-šablona flagů auto_issue
 * a auto_send_email). Posune next_run_date o jeden cyklus; pokud nový
 * datum překročí end_date, šablona dostane status='expired'.
 *
 * Catch-up: pokud cron neběžel několik dní, generuje jen JEDNU fakturu
 * (aktuální cyklus) a posune o 1 krok. Backlog se odbavuje den po dni.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

$run = CronRun::start($pdo, 'cron-generate-recurring-invoices');

/** @var RecurringTemplateRepository $repo */
$repo = $container->get(RecurringTemplateRepository::class);
/** @var RecurringInvoiceGenerator $generator */
$generator = $container->get(RecurringInvoiceGenerator::class);

$startedAt = microtime(true);

$candidates = $repo->findDue();
$reminderCandidates = $repo->findReminderDue();
$report = [
    'dry_run'    => $dryRun,
    'candidates' => count($candidates),
    'reminder_candidates' => count($reminderCandidates),
    'opened'     => 0,
    'generated'  => 0,
    'issued'     => 0,
    'sent'       => 0,
    'reminders'  => 0,
    'errors'     => 0,
];

$today = date('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] cron-generate-recurring-invoices"
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($candidates) . " templates\n";

if (empty($candidates) && empty($reminderCandidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring', ?)")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
    $run->finish('ok', $report);
    exit(0);
}

if ($dryRun) {
    foreach ($candidates as $t) {
        $mode = (string) ($t['draft_open_mode'] ?? 'at_issue');
        $nextRun = (string) $t['next_run_date'];
        $action = ($mode === 'period_start' && $nextRun >= $today) ? 'OPEN-DRAFT' : 'ISSUE';
        printf(
            "  [DRY] #%d \"%s\" client=%s freq=%s next=%s mode=%s → %s (auto_issue=%d auto_send=%d)\n",
            (int) $t['id'],
            (string) $t['name'],
            (string) ($t['client_company_name'] ?? '?'),
            (string) $t['frequency'],
            $nextRun,
            $mode,
            $action,
            $t['auto_issue'] ? 1 : 0,
            $t['auto_send_email'] ? 1 : 0,
        );
    }
    foreach ($reminderCandidates as $t) {
        printf("  [DRY] ✉ reminder #%d \"%s\" next=%s (reminder %d dní předem)\n",
            (int) $t['id'], (string) $t['name'], (string) $t['next_run_date'], (int) ($t['reminder_days_before'] ?? 1));
    }
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  ({$ms} ms — DRY RUN, nic se nevytvořilo)\n";
    $run->finish('ok', $report);
    exit(0);
}

$ua = 'cron-generate-recurring-invoices/1.0';

foreach ($candidates as $t) {
    $tplId = (int) $t['id'];
    $mode = (string) ($t['draft_open_mode'] ?? 'at_issue');
    $nextRun = (string) $t['next_run_date'];
    try {
        if ($mode === 'period_start' && $nextRun >= $today) {
            // OPEN fáze — jsme uvnitř fakturovaného období VČETNĚ jeho posledního dne
            // (next_run_date). Koncept držíme otevřený i v den konce období, ať se do něj
            // stihne zapsat i práce z posledního dne; uzávěrka (issuePeriod) proběhne až
            // následující den (next_run_date < dnes). Datum vystavení i DUZP konceptu
            // přitom zůstávají na next_run_date (konec období) — viz openDraft/issuePeriod.
            // Otevři koncept (idempotentní), ať má uživatel kam psát vícepráce.
            $r = $generator->openDraft($tplId, null, '', $ua, expectedNextRunDate: $nextRun);
            if ($r['created']) {
                $report['opened']++;
                printf("  ⊕ #%d \"%s\" → koncept #%d otevřen (vystavení: %s)\n",
                    $tplId, (string) $t['name'], $r['invoice_id'], $nextRun);
            }
            // pokud created=false (koncept už existuje), tiše přeskoč
        } elseif ($mode === 'period_start') {
            // ISSUE fáze pro period_start — den po konci období (next_run_date < dnes).
            // Uzavři otevřený koncept a vystav (issue_date/DUZP zůstávají na next_run_date).
            $r = $generator->issuePeriod($tplId, null, '', $ua, expectedNextRunDate: $nextRun);
            $report['generated']++;
            if ($r['issued']) $report['issued']++;
            if (!empty($r['sent_to'])) $report['sent']++;
            printf("  ✓ #%d \"%s\" → faktura #%d %s%s (next: %s%s)\n",
                $tplId, (string) $t['name'], $r['invoice_id'],
                $r['varsymbol'] !== null ? $r['varsymbol'] : '(draft)',
                !empty($r['sent_to']) ? ' → ' . implode(', ', $r['sent_to']) : '',
                $r['new_next_run_date'] ?? '?',
                $r['template_status'] === 'expired' ? ', EXPIRED' : '');

            // Po uzávěrce rovnou otevři koncept DALŠÍHO období, pokud už začalo
            // (draftOpenDate <= dnes) — u end-of-month šablon je to 1. den nového
            // měsíce, takže 1.7. proběhne „uzavři červen (k 30.6.) → otevři červenec"
            // v jednom běhu a koncept je k dispozici hned od 1. dne období, ne až
            // další den. Idempotentní (openDraft si existující koncept nevytvoří
            // znovu). Přeskoč u expirované šablony (end_date prošel).
            $newNext = $r['new_next_run_date'] ?? null;
            if ($r['template_status'] !== 'expired'
                && $newNext !== null
                && RecurringInvoiceGenerator::draftOpenDate($newNext) <= $today
            ) {
                $open = $generator->openDraft($tplId, null, '', $ua, expectedNextRunDate: $newNext);
                if ($open['created']) {
                    $report['opened']++;
                    printf("  ⊕ #%d \"%s\" → koncept #%d otevřen (vystavení: %s)\n",
                        $tplId, (string) $t['name'], $open['invoice_id'], $newNext);
                }
            }
        } else {
            // Legacy at_issue — open+issue v jednom kroku (původní chování).
            $r = $generator->generate($tplId, null, null, '', $ua, expectedNextRunDate: $nextRun);
            $report['generated']++;
            if ($r['issued']) $report['issued']++;
            if (!empty($r['sent_to'])) $report['sent']++;
            printf("  ✓ #%d \"%s\" → faktura #%d %s%s (next: %s%s)\n",
                $tplId, (string) $t['name'], $r['invoice_id'],
                $r['varsymbol'] !== null ? $r['varsymbol'] : '(draft)',
                !empty($r['sent_to']) ? ' → ' . implode(', ', $r['sent_to']) : '',
                $r['new_next_run_date'] ?? '?',
                $r['template_status'] === 'expired' ? ', EXPIRED' : '');
        }
        // Úspěch → vyčisti případnou starou chybu (banner na šabloně zmizí).
        $repo->clearLastError($tplId);
    } catch (\Throwable $e) {
        $report['errors']++;
        // Zaznamenej chybu na šablonu → uživatel ji uvidí jako banner na detailu/seznamu.
        $repo->setLastError($tplId, $e->getMessage());
        fprintf(STDERR, "  ✗ #%d \"%s\" — %s\n", $tplId, (string) $t['name'], $e->getMessage());
    }
}

// ==========================================================================
// REMINDER fáze — den(y) před vystavením připomeň otevřené koncepty period_start.
// ==========================================================================
/** @var \MyInvoice\Service\Invoice\RecurringDraftReminder $reminder */
$reminder = $container->get(\MyInvoice\Service\Invoice\RecurringDraftReminder::class);
foreach ($reminderCandidates as $t) {
    $tplId = (int) $t['id'];
    $nextRun = (string) $t['next_run_date'];
    try {
        $inv = $repo->findPeriodInvoice($tplId, $nextRun);
        if ($inv === null || $inv['status'] !== 'draft') {
            // Žádný otevřený koncept (ještě nevznikl, nebo už vystaven) → není co připomínat.
            continue;
        }
        $sent = $reminder->send($t, (int) $inv['id'], $ua);
        $repo->markReminderSent($tplId, $nextRun);
        if ($sent) {
            $report['reminders']++;
            printf("  ✉ #%d \"%s\" → reminder pro koncept #%d (vystavení: %s)\n",
                $tplId, (string) $t['name'], (int) $inv['id'], $nextRun);
        }
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ reminder #%d \"%s\" — %s\n", $tplId, (string) $t['name'], $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): opened={$report['opened']}, generated={$report['generated']}, issued={$report['issued']}, sent={$report['sent']}, reminders={$report['reminders']}, errors={$report['errors']}\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
