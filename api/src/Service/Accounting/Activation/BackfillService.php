<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingBackfill;
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
            'balance' => ['debit_cents' => 0, 'credit_cents' => 0, 'balanced' => true],
            'document_coverage' => [],
            'failed_total' => 0,
        ];
        $processed = 0;
        $cancelled = fn (): bool => $this->jobs->isCancelRequested($jobId);
        $log = fn (string $line): mixed => $this->jobs->appendLog($jobId, $line);

        try {
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
            foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices'] as $type => $countKey) {
                $phase = $docReport[$type];
                $expected = (int) $counts[$countKey];
                $handled = (int) $phase['posted'] + (int) $phase['skipped'] + (int) $phase['failed'];
                $missing = max(0, $expected - $handled);
                $unexpected = max(0, $handled - $expected);
                $complete = $missing === 0 && $unexpected === 0;
                $report['document_coverage'][$type] = [
                    'expected' => $expected,
                    'posted_or_updated' => (int) $phase['posted'] + (int) $phase['updated'],
                    'skipped' => (int) $phase['skipped'],
                    'failed' => (int) $phase['failed'],
                    'handled' => $handled,
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
            $report['phases']['cash'] = array_diff_key($cashReport, ['processed' => true, 'cancelled' => true]);
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
            $report['phases']['bank'] = $bankReport;
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
            $this->jobs->saveReport($jobId, $report);
            if ($report['failed_total'] !== 0 || !$report['balance']['balanced']) {
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
                $this->jobs->markCompleted($jobId);
                if ($ownTx) $pdo->commit();
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
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
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit' THEN amount END), 0) * 100) AS SIGNED),
                    CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN amount END), 0) * 100) AS SIGNED)
               FROM journal_entry_lines WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_NUM) ?: [0, 0];
        $debit = (int) $row[0];
        $credit = (int) $row[1];
        return ['debit_cents' => $debit, 'credit_cents' => $credit, 'balanced' => $debit === $credit];
    }
}
