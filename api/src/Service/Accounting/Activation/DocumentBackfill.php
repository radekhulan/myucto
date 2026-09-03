<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

final class DocumentBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @param (callable(int,int,array<string,array<string,int>>):void)|null $onProgress
     *        fn(zpracováno, celkem, čítače) — hlášení průběhu pro běh na pozadí
     *        ({@see \MyInvoice\Service\Accounting\PostingBackfillJobService}). Průvodce
     *        aktivací ho nepotřebuje: má vlastní fáze a hlásí je po nich.
     */
    public function run(
        int $supplierId,
        ?string $from,
        ?int $year,
        bool $dryRun,
        bool $asDrafts = false,
        ?callable $onLog = null,
        ?callable $isCancelled = null,
        bool $settlementsOnly = false,
        ?callable $onProgress = null,
    ): array {
        $pdo = $this->db->pdo();
        $emit = static function (string $line) use ($onLog): void {
            if ($onLog !== null) $onLog($line);
        };

        if (!$dryRun) {
            $seeded = $this->seeder->seedForSupplier($supplierId);
            $emit("Osnova: naseedováno {$seeded} nových účtů (idempotentně).\n");
        }

        $dateWhere = '';
        $bind = ['sid' => $supplierId];
        if ($year !== null) {
            $dateWhere .= ' AND YEAR(COALESCE(tax_date, issue_date)) = :yr';
            $bind['yr'] = $year;
        }
        if ($from !== null) {
            $dateWhere .= ' AND COALESCE(tax_date, issue_date) >= :from_date';
            $bind['from_date'] = $from;
        }

        $invoiceBind = $bind;
        $invoiceTypePlaceholders = [];
        foreach (PostingService::POSTABLE_ISSUED_INVOICE_TYPES as $index => $invoiceType) {
            $key = 'invoice_type_' . $index;
            $invoiceTypePlaceholders[] = ':' . $key;
            $invoiceBind[$key] = $invoiceType;
        }
        $invStmt = $pdo->prepare(
            "SELECT i.id, i.varsymbol AS doc_no, i.issue_date, i.tax_date,
                    COALESCE(i.tax_date, i.issue_date) AS entry_date,
                    c.company_name AS party
               FROM invoices i
          LEFT JOIN clients c ON c.id = i.client_id
              WHERE i.supplier_id = :sid
                AND i.status NOT IN ('draft','cancelled')
                AND i.invoice_type IN (" . implode(', ', $invoiceTypePlaceholders) . "){$dateWhere}"
                . ($settlementsOnly
                    ? " AND EXISTS (
                            SELECT 1 FROM invoices parent
                             WHERE parent.id = i.parent_invoice_id
                               AND parent.supplier_id = i.supplier_id
                               AND parent.invoice_type = 'proforma'
                        )"
                    : '') . "
           ORDER BY entry_date, i.id"
        );
        $invStmt->execute($invoiceBind);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        $piStmt = $pdo->prepare(
            "SELECT pi.id, pi.vendor_invoice_number AS doc_no, pi.issue_date, pi.tax_date,
                    COALESCE(pi.tax_date, pi.issue_date) AS entry_date,
                    c.company_name AS party
               FROM purchase_invoices pi
          LEFT JOIN clients c ON c.id = pi.vendor_id
              WHERE pi.supplier_id = :sid
                AND pi.status IN ('received','booked','paid'){$dateWhere}
                AND pi.document_kind <> 'advance'"
                . ($settlementsOnly
                    ? " AND EXISTS (
                            SELECT 1 FROM purchase_invoices adv
                             WHERE adv.id = pi.advance_purchase_invoice_id
                               AND adv.supplier_id = pi.supplier_id
                               AND adv.document_kind = 'advance'
                        )"
                    : '') . "
           ORDER BY entry_date, pi.id"
        );
        $piStmt->execute($bind);
        $purchases = $piStmt->fetchAll(PDO::FETCH_ASSOC);

        $emit('Ke zpracování: ' . count($invoices) . ' vydaných + ' . count($purchases) . " přijatých faktur.\n\n");

        $stats = [
            'invoice' => ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            'purchase_invoice' => ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
        ];
        $skipReasons = [];
        $documentIssues = [];
        $cancelled = false;
        $processed = 0;
        $existsStmt = $pdo->prepare(
            'SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL LIMIT 1'
        );
        $ensuredYears = [];

        $total = count($invoices) + count($purchases);
        $report = static function () use (&$processed, &$stats, $total, $onProgress): void {
            if ($onProgress !== null) $onProgress($processed, $total, $stats);
        };
        $report();

        $process = function (string $sourceType, array $doc) use (
            &$stats, &$skipReasons, &$documentIssues, &$processed, &$ensuredYears, &$cancelled,
            $supplierId, $dryRun, $asDrafts, $isCancelled, $existsStmt, $pdo, $emit, $report
        ): void {
            if ($isCancelled !== null && $isCancelled()) {
                $cancelled = true;
                return;
            }
            $processed++;
            $id = (int) $doc['id'];
            $entryDate = (string) $doc['entry_date'];
            $label = ($sourceType === 'invoice' ? 'FV' : 'PF') . " #{$id} ({$doc['doc_no']}, {$entryDate})";

            try {
                $lines = $sourceType === 'invoice'
                    ? $this->posting->buildFromInvoice($supplierId, $id)
                    : $this->posting->buildFromPurchaseInvoice($supplierId, $id);
                PostingService::assertBalanced($lines);
                $existsStmt->execute([$supplierId, $sourceType, $id]);
                $wasThere = $existsStmt->fetchColumn() !== false;

                if ($dryRun) {
                    $balance = PostingService::balanceCents($lines);
                    $stats[$sourceType][$wasThere ? 'updated' : 'posted']++;
                    $emit(sprintf("  [OK]   %-38s řádků=%d  MD=D=%s Kč\n", $label, count($lines), number_format($balance['debit'] / 100, 2, ',', ' ')));
                    return;
                }

                $yearKey = (int) substr($entryDate, 0, 4);
                if (!isset($ensuredYears[$yearKey])) {
                    $this->periods->ensureOpenPeriodFor($supplierId, $entryDate);
                    $ensuredYears[$yearKey] = true;
                }
                $party = trim((string) ($doc['party'] ?? ''));
                $entryId = $this->posting->postDocument($supplierId, $sourceType, $id, $lines, [
                    'entry_date' => $entryDate,
                    'document_date' => $doc['issue_date'] ?? null,
                    'document_no' => $doc['doc_no'] ?: null,
                    'description' => ($sourceType === 'invoice' ? 'Vydaná faktura' : 'Přijatá faktura')
                        . ' ' . ($doc['doc_no'] ?: ('#' . $id)) . ($party !== '' ? ' — ' . $party : ''),
                    'posted' => !$asDrafts,
                ]);

                if (!$asDrafts) {
                    $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
                    $pdo->prepare(
                        "UPDATE {$table} d
                            JOIN journal_entries je
                              ON je.id = ? AND je.supplier_id = d.supplier_id
                             AND je.source_type = ? AND je.source_id = d.id
                             SET d.booked_at = COALESCE(d.booked_at, je.posted_at),
                                 d.booked_by = COALESCE(d.booked_by, je.posted_by)
                           WHERE d.id = ? AND d.supplier_id = ?"
                    )->execute([$entryId, $sourceType, $id, $supplierId]);
                }

                $stats[$sourceType][$wasThere ? 'updated' : 'posted']++;
                $emit(sprintf($wasThere ? "  [UPD]  %-38s → zápis #%d\n" : "  [NEW]  %-38s → zápis #%d\n", $label, $entryId));
            } catch (PostingException $e) {
                $reason = match ($e->errorCode) {
                    'period_not_open' => 'period_closed',
                    default => $e->errorCode,
                };
                if (in_array($e->errorCode, ['document_not_postable', 'advance_payment_only', 'date_locked', 'period_not_open'], true)) {
                    $stats[$sourceType]['skipped']++;
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    $documentIssues[] = $this->documentIssue($sourceType, $doc, 'skipped', $reason, $e->getMessage());
                    $emit(sprintf("  [SKIP] %-38s %s\n", $label, $e->getMessage()));
                    return;
                }
                $stats[$sourceType]['failed']++;
                $documentIssues[] = $this->documentIssue($sourceType, $doc, 'failed', $e->errorCode, $e->getMessage());
                $emit(sprintf("  [FAIL] %-38s %s: %s\n", $label, $e->errorCode, $e->getMessage()));
            } catch (\Throwable $e) {
                $stats[$sourceType]['failed']++;
                $documentIssues[] = $this->documentIssue(
                    $sourceType,
                    $doc,
                    'failed',
                    'processing_error',
                    'Doklad se nepodařilo zpracovat. Podrobnost najdete v protokolu.',
                );
                $emit(sprintf("  [FAIL] %-38s %s\n", $label, $e->getMessage()));
            }
        };

        if ($invoices !== []) {
            $emit("Vydané faktury:\n");
            foreach ($invoices as $doc) {
                $process('invoice', $doc);
                $report();
                if ($cancelled) break;
            }
            $emit("\n");
        }
        if (!$cancelled && $purchases !== []) {
            $emit("Přijaté faktury:\n");
            foreach ($purchases as $doc) {
                $process('purchase_invoice', $doc);
                $report();
                if ($cancelled) break;
            }
            $emit("\n");
        }

        $emit("═══ KONTROLNÍ REPORT ═══════════════════════════════════════════\n");
        $verb = $dryRun ? 'postovatelné' : 'nové';
        foreach (['invoice' => 'Vydané faktury', 'purchase_invoice' => 'Přijaté faktury'] as $type => $label) {
            $s = $stats[$type];
            $emit(sprintf("  %-16s  %s=%d  updated=%d  skipped=%d  failed=%d\n", $label, $verb, $s['posted'], $s['updated'], $s['skipped'], $s['failed']));
        }

        $balStmt = $pdo->prepare(
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit' THEN amount END), 0) * 100) AS SIGNED) AS debit_cents,
                    CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN amount END), 0) * 100) AS SIGNED) AS credit_cents
               FROM journal_entry_lines WHERE supplier_id = ?"
        );
        $balStmt->execute([$supplierId]);
        $balance = $balStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $debitCents = (int) ($balance['debit_cents'] ?? 0);
        $creditCents = (int) ($balance['credit_cents'] ?? 0);
        $balanced = $debitCents === $creditCents;
        $emit("───────────────────────────────────────────────────────────────\n");
        $emit(sprintf("  BALANCE CHECK (celý deník firmy #%d):\n", $supplierId));
        $emit(sprintf("    Σ MD = %s Kč\n", number_format($debitCents / 100, 2, ',', ' ')));
        $emit(sprintf("    Σ D  = %s Kč\n", number_format($creditCents / 100, 2, ',', ' ')));
        $emit(sprintf("    → %s\n", $balanced ? 'PASS (podvojnost sedí)' : 'FAIL (deník NENÍ vyrovnaný — rozdíl ' . number_format(($debitCents - $creditCents) / 100, 2, ',', ' ') . ' Kč!)'));
        $emit("═══════════════════════════════════════════════════════════════\n");
        if ($dryRun) $emit("\n(dry-run — nic nebylo zapsáno; pro ostrý běh spusť bez --dry-run)\n");

        return $stats + [
            'skip_reasons' => $skipReasons,
            'document_issues' => $documentIssues,
            'balance' => ['debit_cents' => $debitCents, 'credit_cents' => $creditCents, 'balanced' => $balanced],
            'processed' => $processed,
            'cancelled' => $cancelled,
        ];
    }

    private function documentIssue(string $sourceType, array $doc, string $severity, string $errorCode, string $message): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => (int) $doc['id'],
            'document_no' => trim((string) ($doc['doc_no'] ?? '')),
            'entry_date' => (string) ($doc['entry_date'] ?? ''),
            'severity' => $severity,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }
}
