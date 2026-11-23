<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingBackfill;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;

final class BackfillService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingBackfillJobRepository $jobs,
        private readonly OpeningBalanceService $opening,
        private readonly DocumentBackfill $documents,
        private readonly CashBackfill $cash,
        private readonly BankPostingBackfill $bank,
        private readonly PendingBackfillCounter $pending,
        private readonly AccountingModeRepository $accountingModes,
        private readonly \MyInvoice\Service\Accounting\InvoiceSettlementService $settlements,
        private readonly ActivityLogger $logger,
        private readonly \MyInvoice\Service\Accounting\AutoPostingPolicyService $autoPosting,
        private readonly \MyInvoice\Service\Accounting\EntryForm\EntryFormInstallationDefault $entryFormDefault,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || !$this->jobs->markRunning($jobId)) {
            throw new \RuntimeException('Job není ve stavu queued.');
        }
        $supplierId = (int) $job['supplier_id'];
        $kind = (string) $job['kind'];
        $params = (array) ($job['params'] ?? []);
        $startsOn = (string) ($params['starts_on'] ?? '');
        if ($startsOn === '') throw new \RuntimeException('Job nemá datum zahájení.');
        $dryRun = $kind === 'dry_run';
        $userId = (int) $job['created_by'];
        $counts = $this->pending->count($supplierId, $startsOn);
        $this->jobs->updateProgress($jobId, 'opening', 0, $counts['total']);

        $report = [
            'starts_on' => $startsOn,
            'kind' => $kind,
            'phases' => [],
            'skip_reasons' => [],
            'document_issues' => [],
            'bank_issues' => [],
            'balance' => ['debit_cents' => 0, 'credit_cents' => 0, 'balanced' => true],
            'document_coverage' => [],
            'failed_total' => 0,
        ];
        $processed = 0;
        $cancelled = fn (): bool => $this->jobs->isCancelRequested($jobId);
        $log = fn (string $line): mixed => $this->jobs->appendLog($jobId, $line);

        try {
            // Výchozí forma zápisu instalace platí od prvního zápisu aktivace (otevírací
            // rozvaha i doúčtovaná historie). Kontrola nanečisto formu nezapisuje.
            if (!$dryRun) {
                $this->entryFormDefault->apply($supplierId, $userId, activating: true);
            }
            $opening = $this->opening->draft($supplierId);
            if (!$opening['totals']['balanced']) {
                throw new \MyInvoice\Service\Accounting\PostingException(
                    'opening_unbalanced',
                    'Otevírací rozvaha není vyrovnaná.',
                    422,
                );
            }
            if ($opening['rows'] === []) {
                $report['phases']['opening'] = ['status' => 'skipped'];
            } elseif ($dryRun) {
                // Kontrola nanečisto musí narazit na TOTÉŽ, na co narazí ostrý běh.
                // Dřív se ptala jen na vyrovnanost, takže nad zavřeným obdobím hlásila
                // failed_total: 0 — a teprve ostrý běh skončil ve stavu failed.
                $blocker = $this->opening->postBlocker($supplierId, $startsOn);
                if ($blocker !== null) {
                    throw new \MyInvoice\Service\Accounting\PostingException(
                        $blocker['code'],
                        $blocker['message'],
                        $blocker['status'],
                    );
                }
                $report['phases']['opening'] = ['status' => 'checked', 'rows' => count($opening['rows'])];
            } else {
                $posted = $this->opening->post($supplierId, $startsOn, ['user_id' => $userId, 'posted_by' => $userId]);
                $report['phases']['opening'] = ['status' => 'done'] + $posted;
            }
            if ($cancelled()) {
                $this->cancel($jobId, $supplierId, $report);
                return;
            }

            $this->jobs->updateProgress($jobId, 'documents', $processed);
            $docReport = $this->documents->run($supplierId, $startsOn, null, $dryRun, false, $log, $cancelled);
            $processed += (int) $docReport['processed'];
            $this->jobs->updateProgress($jobId, 'documents', $processed);
            $report['phases']['documents'] = [
                'invoice' => $docReport['invoice'],
                'purchase_invoice' => $docReport['purchase_invoice'],
            ];
            $report['document_issues'] = $docReport['document_issues'];
            // Kontrola úplnosti: každý doklad, který počítadlo hlásí jako čekající (bez
            // aktivního zápisu), musel projít během — zaúčtovaný, přeskočený i chybný.
            // Doklady, které už zápis mají, průvodce jen přepisuje; do porovnání nepatří.
            // Dřív se sem sčítalo posted+skipped+failed bez ohledu na existující zápis, takže
            // opakovaná aktivace nad zaúčtovanou historií v zamčeném období hlásila všechny
            // přeskočené přepisy jako „navíc" a aktivaci zablokovala.
            foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'] as $type => $countKey) {
                $phase = $docReport[$type];
                $coverage = $docReport['coverage'][$type];
                $expected = (int) $counts[$countKey];
                $handled = (int) $coverage['pending'];
                $missing = max(0, $expected - $handled);
                $unexpected = max(0, $handled - $expected);
                $complete = $missing === 0 && $unexpected === 0;
                $report['document_coverage'][$type] = [
                    'expected' => $expected,
                    'posted_or_updated' => (int) $phase['posted'] + (int) $phase['updated'],
                    'skipped' => (int) $phase['skipped'],
                    'failed' => (int) $phase['failed'],
                    'handled' => $handled,
                    'already_posted' => (int) $coverage['already_posted'],
                    'missing' => $missing,
                    'unexpected' => $unexpected,
                    'complete' => $complete,
                ];
                if (!$complete) {
                    $report['failed_total'] += max(1, abs($expected - $handled));
                    $log(sprintf(
                        'Kontrola úplnosti %s: očekáváno %d, zpracováno %d, chybí %d, navíc %d.',
                        $type,
                        $expected,
                        $handled,
                        $missing,
                        $unexpected,
                    ));
                }
            }
            $report['skip_reasons'] = $this->mergeReasons($report['skip_reasons'], $docReport['skip_reasons']);
            $report['balance'] = $docReport['balance'];
            $report['failed_total'] += $docReport['invoice']['failed'] + $docReport['purchase_invoice']['failed'];
            if ($docReport['cancelled'] || $cancelled()) {
                $this->cancel($jobId, $supplierId, $report);
                return;
            }

            $this->jobs->updateProgress($jobId, 'cash', $processed);
            $cashReport = $this->cash->run($supplierId, $startsOn, null, $dryRun, $log, $cancelled);
            $processed += (int) $cashReport['processed'];
            $this->jobs->updateProgress($jobId, 'cash', $processed);
            $report['phases']['cash'] = array_diff_key($cashReport, ['processed' => true, 'cancelled' => true, 'issues' => true]);
            $report['document_issues'] = array_merge($report['document_issues'], $cashReport['issues']);
            $report['skip_reasons'] = $this->mergeReasons($report['skip_reasons'], $cashReport['skip_reasons']);
            $report['failed_total'] += $cashReport['failed'];
            if ($cashReport['cancelled'] || $cancelled()) {
                $this->cancel($jobId, $supplierId, $report);
                return;
            }

            $this->jobs->updateProgress($jobId, 'bank', $processed);
            $bankReport = $this->bank->run(
                $supplierId,
                $startsOn,
                !$dryRun,
                (bool) ($params['with_rules'] ?? false),
                $userId,
                true,
            );
            $processed += (int) ($bankReport['candidates'] ?? 0);
            $this->jobs->updateProgress($jobId, 'bank', $processed);
            $report['phases']['bank'] = array_diff_key($bankReport, ['items' => true]);
            $report['bank_issues'] = (array) ($bankReport['items'] ?? []);
            $report['skip_reasons'] = $this->mergeReasons($report['skip_reasons'], (array) ($bankReport['skip_reasons'] ?? []));
            $this->jobs->appendLog($jobId, sprintf(
                'Banka: kandidátů %d, zaúčtováno %d, návrhů %d, přeskočeno %d.',
                $bankReport['candidates'] ?? 0,
                $bankReport['posted'] ?? 0,
                $bankReport['suggested'] ?? 0,
                $bankReport['skipped'] ?? 0,
            ));
            if ($cancelled()) {
                $this->cancel($jobId, $supplierId, $report);
                return;
            }

            // Zápočty proti účtu (invoice_settlements) — evidovaná úhrada, která nemá zápis.
            // Buď vznikla v daňové evidenci (deník tam není), nebo jí zápis smazalo hromadné
            // přeúčtování: `journal_entry_id` má ON DELETE SET NULL, takže vazba tiše zmizí
            // a zůstane doklad, který tvrdí „uhrazeno", zatímco saldokonto je otevřené.
            if ($dryRun) {
                $report['phases']['account_settlements'] = ['status' => 'skipped_dry_run'];
            } else {
                $this->jobs->updateProgress($jobId, 'account_settlements', $processed);
                $offsetReport = $this->settlements->postMissingEntries($supplierId, $userId);
                $report['phases']['account_settlements'] = $offsetReport;
                $report['failed_total'] += $offsetReport['failed'];
                if ($offsetReport['candidates'] > 0) {
                    $this->jobs->appendLog($jobId, sprintf(
                        'Zápočty proti účtu: kandidátů %d, doúčtováno %d, chyby %d.',
                        $offsetReport['candidates'],
                        $offsetReport['posted'],
                        $offsetReport['failed'],
                    ));
                }
                foreach ($offsetReport['errors'] as $err) {
                    $this->jobs->appendLog($jobId, $err);
                }
            }
            if ($cancelled()) {
                $this->cancel($jobId, $supplierId, $report);
                return;
            }

            if ($dryRun) {
                $report['phases']['advance_settlements'] = ['status' => 'after_payment_posting'];
            } else {
                $this->jobs->updateProgress($jobId, 'advance_settlements', $processed);
                $settlementReport = $this->documents->run(
                    $supplierId,
                    $startsOn,
                    null,
                    false,
                    false,
                    $log,
                    $cancelled,
                    true,
                );
                $report['phases']['advance_settlements'] = [
                    'invoice' => $settlementReport['invoice'],
                    'purchase_invoice' => $settlementReport['purchase_invoice'],
                ];
                $report['document_issues'] = array_merge(
                    $report['document_issues'],
                    $settlementReport['document_issues'],
                );
                $report['skip_reasons'] = $this->mergeReasons(
                    $report['skip_reasons'],
                    $settlementReport['skip_reasons'],
                );
                $report['failed_total'] += $settlementReport['invoice']['failed']
                    + $settlementReport['purchase_invoice']['failed'];
                $this->jobs->appendLog($jobId, sprintf(
                    'Zúčtování záloh: aktualizováno %d vydaných a %d přijatých dokladů, chyby %d.',
                    $settlementReport['invoice']['updated'],
                    $settlementReport['purchase_invoice']['updated'],
                    $settlementReport['invoice']['failed'] + $settlementReport['purchase_invoice']['failed'],
                ));
                if ($settlementReport['cancelled'] || $cancelled()) {
                    $this->cancel($jobId, $supplierId, $report);
                    return;
                }
            }

            $report['balance'] = $this->journalBalance($supplierId);
            $force = (array) ($params['force'] ?? []);
            if ($report['failed_total'] !== 0 && $report['balance']['balanced'] && !$dryRun && $force !== []) {
                // Vynucené dokončení potvrdil správce už při spuštění (počet a seznam chyb
                // z kontroly nanečisto). Nevyrovnaný deník ani fatální chyba se tím
                // neobchází: obojí zůstává o větev níž / v catch.
                $report['forced'] = self::forcedRecord($report, (int) ($force['by'] ?? $userId), (string) ($force['reason'] ?? ''));
            }
            $this->jobs->saveReport($jobId, $report);
            if (($report['failed_total'] !== 0 && !isset($report['forced'])) || !$report['balance']['balanced']) {
                $this->jobs->markFailed($jobId, 'Kontrola našla chyby nebo nevyvážený deník.');
                if (!$dryRun) {
                    $this->db->pdo()->prepare("UPDATE supplier SET accounting_activation_status = 'failed' WHERE id = ?")
                        ->execute([$supplierId]);
                }
                return;
            }

            if ($dryRun) {
                $this->jobs->markCompleted($jobId);
                return;
            }

            $this->complete($jobId, $supplierId, $startsOn, $userId, $report);
        } catch (\Throwable $e) {
            $report['failed_total'] = max(1, (int) $report['failed_total']);
            $report['fatal_error'] = $e instanceof \MyInvoice\Service\Accounting\PostingException
                ? $e->errorCode
                : 'activation_failed';
            $this->jobs->saveReport($jobId, $report);
            $this->jobs->markFailed($jobId, $e->getMessage());
            if (!$dryRun) {
                $this->db->pdo()->prepare("UPDATE supplier SET accounting_activation_status = 'failed' WHERE id = ?")
                    ->execute([$supplierId]);
            }
            throw $e;
        }
    }

    /**
     * Dokončí aktivaci nad doúčtováním, které skončilo chybami, po výslovném rozhodnutí
     * správce. Tvrdé brány zůstávají: běh nesmí skončit fatální chybou (otevírací rozvaha
     * nevyrovnaná nebo nezapsaná, přerušený běh), deník musí být vyrovnaný TEĎ, otevírací
     * rozvaha i datum zahájení se od běhu nesmí změnit a nesmí běžet jiné doúčtování.
     * Co zůstalo nedoúčtované, se dá dorovnat akcí „Doúčtovat chybějící zápisy".
     *
     * @return array<string,mixed> report doplněný o `forced`
     */
    public function forceComplete(int $jobId, int $supplierId, int $userId, string $reason): array
    {
        $job = $this->jobs->find($jobId, $supplierId);
        if ($job === null) {
            throw new PostingException('not_found', 'Doúčtování nebylo nalezeno.', 404);
        }
        $last = $this->jobs->lastForTenant($supplierId);
        if ($job['kind'] !== 'execute' || $job['status'] !== 'failed' || (int) ($last['id'] ?? 0) !== $jobId) {
            throw new PostingException('activation_force_wrong_state', 'Vynutit lze jen poslední neúspěšné doúčtování.', 409);
        }
        if ($this->jobs->activeForTenant($supplierId) !== null) {
            throw new PostingException('job_already_running', 'Doúčtování už běží.', 409);
        }
        $report = is_array($job['report_json'] ?? null) ? $job['report_json'] : null;
        if ($report === null || isset($report['fatal_error'])) {
            throw new PostingException(
                'activation_force_fatal',
                'Doúčtování skončilo dřív, než prošlo všechny zdroje. Opravte příčinu a spusťte ho znovu.',
                409,
            );
        }
        $params = (array) ($job['params'] ?? []);
        $state = $this->db->pdo()->prepare('SELECT accounting_starts_on, accounting_activation_status FROM supplier WHERE id = ?');
        $state->execute([$supplierId]);
        $supplier = $state->fetch(\PDO::FETCH_ASSOC) ?: [];
        $startsOn = (string) ($params['starts_on'] ?? '');
        $opening = $this->opening->draft($supplierId);
        if (!$opening['totals']['balanced']) {
            throw new PostingException('opening_unbalanced', 'Otevírací rozvaha není vyrovnaná.', 422);
        }
        if ($startsOn === '' || (string) ($supplier['accounting_starts_on'] ?? '') !== $startsOn
            || ($supplier['accounting_activation_status'] ?? null) !== 'failed'
            || $opening['hash'] !== (string) ($params['opening_hash'] ?? '')) {
            throw new PostingException('dry_run_required', 'Po změnách spusťte znovu kontrolu nanečisto.', 409);
        }
        $balance = $this->journalBalance($supplierId);
        if (!$balance['balanced']) {
            throw new PostingException('journal_unbalanced', 'Deník není vyrovnaný - aktivaci nelze dokončit.', 409);
        }

        $report['balance'] = $balance;
        $report['forced'] = self::forcedRecord($report, $userId, $reason);
        $this->jobs->saveReport($jobId, $report);
        $this->complete($jobId, $supplierId, $startsOn, $userId, $report);
        return $report;
    }

    /** @return array{by:int, at:string, reason:string, failed_total:int} */
    private static function forcedRecord(array $report, int $userId, string $reason): array
    {
        return [
            'by' => $userId,
            'at' => date('Y-m-d H:i:s'),
            'reason' => $reason,
            'failed_total' => (int) ($report['failed_total'] ?? 0),
        ];
    }

    private function complete(int $jobId, int $supplierId, string $startsOn, int $userId, array $report): void
    {
        $pdo = $this->db->pdo();
        // Byla jednotka v podvojném účetnictví UŽ PŘED tímhle během? Odpověď musí
        // padnout před UPDATE níž — potom už je nerozeznatelný první průchod
        // průvodcem od opakovaného po neúspěchu, a výchozí nastavení by přepsalo,
        // co si účetní mezitím nastavila ({@see AutoPostingPolicyService::applyAccountingUnitDefaults()}).
        $wasDoubleEntry = $pdo->prepare(
            "SELECT 1 FROM supplier WHERE id = ? AND accounting_mode = 'double_entry'"
        );
        $wasDoubleEntry->execute([$supplierId]);
        $isFirstActivation = $wasDoubleEntry->fetchColumn() === false;

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            // `accounting_enabled` se zapíná spolu s režimem: kdo doběhl
            // aktivací a nechal si doúčtovat historii, ten účetnictví vede.
            // S vypnutou nadstavbou by po dokončení průvodce nebylo v menu
            // nic z toho, co právě vzniklo — a uživatel by ji musel dohledat
            // v nastavení (u převodu z MyInvoice je vypnutá záměrně).
            $pdo->prepare(
                "UPDATE supplier
                    SET accounting_mode = 'double_entry', accounting_enabled = 1,
                        accounting_activation_status = 'completed'
                  WHERE id = ?"
            )->execute([$supplierId]);
            if ($isFirstActivation) {
                $this->autoPosting->applyAccountingUnitDefaults($supplierId, $userId);
            }
            $this->accountingModes->record($supplierId, $startsOn, 'double_entry');
            $this->logger->log(
                'supplier.accounting_activated',
                $userId,
                'supplier',
                $supplierId,
                $report,
                supplierId: $supplierId,
            );
            if (isset($report['forced'])) {
                $this->logger->log(
                    'supplier.accounting_activation_forced',
                    (int) $report['forced']['by'],
                    'supplier',
                    $supplierId,
                    [
                        'job_id' => $jobId,
                        'reason' => $report['forced']['reason'],
                        'failed_total' => $report['forced']['failed_total'],
                        'document_issues' => array_slice(array_values(array_filter(
                            (array) ($report['document_issues'] ?? []),
                            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'failed',
                        )), 0, 50),
                        'document_coverage' => $report['document_coverage'] ?? [],
                    ],
                    supplierId: $supplierId,
                );
            }
            $this->jobs->markCompleted($jobId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function cancel(int $jobId, int $supplierId, array $report): void
    {
        $this->jobs->saveReport($jobId, $report);
        $this->jobs->markCancelled($jobId);
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_activation_status = 'draft'
              WHERE id = ? AND accounting_activation_status = 'running'"
        )->execute([$supplierId]);
    }

    private function mergeReasons(array $left, array $right): array
    {
        foreach ($right as $reason => $count) $left[$reason] = ($left[$reason] ?? 0) + (int) $count;
        return $left;
    }

    private function journalBalance(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit' THEN signed_amount END), 0) * 100) AS SIGNED),
                    CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN signed_amount END), 0) * 100) AS SIGNED)
               FROM journal_entry_lines WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_NUM) ?: [0, 0];
        $debit = (int) $row[0];
        $credit = (int) $row[1];
        return ['debit_cents' => $debit, 'credit_cents' => $credit, 'balanced' => $debit === $credit];
    }
}
