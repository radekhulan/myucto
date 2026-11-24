<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Activation\DocumentBackfill;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

final class ImportedIssuedInvoiceRepair
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly ImportedIssuedAccounting $scope,
        private readonly AccountingPeriodRepository $periods,
        private readonly PostingService $posting,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly DimensionPeriodGuard $guard,
        private readonly CommercialFeatureAccess $commercialFeatures,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
    ) {}

    /** @return \Generator<int,array<string,mixed>> */
    public function run(int $supplierId, ?string $batch, bool $commit, bool $resetPaid): \Generator
    {
        if ($commit && !$this->commercialFeatures->isAvailable()) {
            throw new \RuntimeException('Účtování vyžaduje aktivní licenci k účetnímu modulu.');
        }

        $supplier = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ?');
        $supplier->execute([$supplierId]);
        if ($supplier->fetchColumn() === false) {
            throw new \RuntimeException('Firma #' . $supplierId . ' neexistuje.');
        }

        $periodAt = $this->scope->periodLookup($supplierId);
        $lockedUntil = $this->posting->currentLockedUntil($supplierId);
        $filedByMonth = [];
        foreach ($this->candidates($supplierId, $batch) as $doc) {
            $period = $periodAt($doc['entry_date']);
            if ($period === null) {
                continue;
            }

            $reason = self::accountingLockReason($doc['entry_date'], $period, $lockedUntil);
            if ($reason === null) {
                $key = substr($doc['entry_date'], 0, 7) . '|' . $doc['oss'];
                if (!array_key_exists($key, $filedByMonth)) {
                    $filedByMonth[$key] = $this->filedReason($supplierId, $doc['entry_date'], (bool) $doc['oss']);
                }
                $reason = $filedByMonth[$key];
            }
            $outcome = $reason === null ? 'ready' : 'locked';
            $reset = false;
            if ($commit && $outcome === 'ready') {
                try {
                    [$outcome, $reason, $reset] = $this->apply($supplierId, $doc, $resetPaid);
                } catch (PostingException $e) {
                    $outcome = in_array($e->errorCode, ['date_locked', 'period_not_open'], true) ? 'locked' : 'failed';
                    $reason = $e->getMessage();
                } catch (\Throwable $e) {
                    $outcome = 'failed';
                    $reason = $e->getMessage();
                }
            }
            yield $doc + ['outcome' => $outcome, 'reason' => $reason ?? 'Chybí živý účetní zápis.', 'paid_reset' => $reset];
        }
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function candidates(int $supplierId, ?string $batch): \Generator
    {
        $pdo = $this->db->pdo();
        $scope = self::candidateSql();
        $paidWithoutPayment = self::paidWithoutPaymentSql();
        $batchWhere = $batch === null
            ? 'AND (i.import_batch_id > ? OR (i.import_batch_id = ? AND i.id > ?))'
            : 'AND i.import_batch_id = ? AND i.id > ?';
        $find = $pdo->prepare(
            "SELECT i.id, i.varsymbol, i.import_batch_id, i.effective_tax_date AS entry_date, i.status,
                    c.code AS currency, ({$paidWithoutPayment}) AS paid_without_payment
               FROM invoices i
               JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ? AND {$scope} {$batchWhere}
              ORDER BY i.import_batch_id, i.id LIMIT " . self::PAGE_SIZE,
        );
        $afterBatch = '';
        $afterId = 0;
        do {
            $find->execute($batch === null ? [$supplierId, $afterBatch, $afterBatch, $afterId] : [$supplierId, $batch, $afterId]);
            $docs = $find->fetchAll(PDO::FETCH_ASSOC);
            if ($docs === []) {
                break;
            }
            $ids = array_column($docs, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $totals = $pdo->prepare(
                "SELECT invoice_id, SUM(total_without_vat) AS base, SUM(total_vat) AS vat, MAX(oss_applicable) AS oss
                   FROM invoice_items WHERE invoice_id IN ({$placeholders}) GROUP BY invoice_id",
            );
            $totals->execute($ids);
            $byId = array_column($totals->fetchAll(PDO::FETCH_ASSOC), null, 'invoice_id');
            foreach ($docs as $doc) {
                $afterBatch = $doc['import_batch_id'];
                $afterId = (int) $doc['id'];
                yield $doc + ($byId[$afterId] ?? ['base' => '0.00', 'vat' => '0.00', 'oss' => 0]);
            }
        } while (count($docs) === self::PAGE_SIZE);
    }

    /** @return array{string,string,bool} */
    private function apply(int $supplierId, array $doc, bool $resetPaid): array
    {
        $pdo = $this->db->pdo();
        $id = (int) $doc['id'];
        $result = TransactionFailure::retrying($pdo, fn () => $this->guard->transaction(function () use ($pdo, $supplierId, $doc, $id, $resetPaid): array {
            $this->guard->lockPeriods($supplierId);
            $stmt = $pdo->prepare(
                'SELECT i.effective_tax_date, EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = i.id AND ii.oss_applicable = 1) AS oss
                   FROM invoices i WHERE i.supplier_id = ? AND i.id = ? AND i.import_batch_id = ? AND ' . self::candidateSql() . ' FOR UPDATE',
            );
            $stmt->execute([$supplierId, $id, $doc['import_batch_id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            $date = $doc['entry_date'];
            if ($current === false || $current['effective_tax_date'] !== $date) {
                return ['skipped', 'Doklad se mezitím změnil nebo už je zaúčtovaný.', false];
            }
            $period = $this->periods->findForDateForUpdate($supplierId, $date);
            if ($period === null) {
                return ['skipped', 'Datum dokladu už nepokrývá účetní období.', false];
            }
            $reason = self::accountingLockReason($date, $period, $this->posting->currentLockedUntil($supplierId))
                ?? $this->filedReason($supplierId, $date, (bool) $current['oss']);
            if ($reason !== null) {
                return ['locked', $reason, false];
            }

            $this->autoPoster->post($supplierId, 'invoice', $id, ['entry_date' => $date, 'posting_group_idempotent' => true]);
            $reset = $resetPaid && $this->resetUnpaidStatus($pdo, $supplierId, $id);
            return ['posted', $reset ? 'Zaúčtováno, stav opraven podle úhrad.' : 'Zaúčtováno.', $reset];
        }));
        if ($result[2]) {
            try {
                $this->pdf->invalidate($id, 'invalidate_unmark_paid');
                $this->stats->recomputeAfterCommit($id);
            } catch (\Throwable $e) {
                error_log('ImportedIssuedInvoiceRepair: obnova PDF nebo statistik selhala: ' . $e->getMessage());
            }
        }
        return $result;
    }

    private function resetUnpaidStatus(PDO $pdo, int $supplierId, int $id): bool
    {
        $check = $pdo->prepare(
            'SELECT 1 FROM invoices i WHERE i.supplier_id = ? AND i.id = ? AND ' . self::paidWithoutPaymentSql(' FOR UPDATE') . ' FOR UPDATE',
        );
        $check->execute([$supplierId, $id]);
        return $check->fetchColumn() !== false && InvoicePaymentService::recomputeLocked($pdo, $id)['became_unpaid'];
    }

    private static function candidateSql(): string
    {
        return 'i.import_batch_id IS NOT NULL AND ' . DocumentBackfill::scopeSql('invoice', 'i')
            . ' AND NOT ' . DocumentPostingGroup::activePostingExistsSql("'invoice'", 'i.id', 'i.supplier_id', otherItemCover: false);
    }

    private static function paidWithoutPaymentSql(string $lock = ''): string
    {
        return "i.status = 'paid' AND i.invoice_type <> 'tax_document' AND i.amount_to_pay > 0
            AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id AND p.supplier_id = i.supplier_id{$lock})
            AND NOT EXISTS (SELECT 1 FROM payment_matches p WHERE p.invoice_id = i.id AND p.supplier_id = i.supplier_id{$lock})
            AND NOT EXISTS (SELECT 1 FROM bank_transactions b WHERE b.matched_invoice_id = i.id{$lock})
            AND NOT EXISTS (SELECT 1 FROM cash_documents c WHERE c.invoice_id = i.id AND c.supplier_id = i.supplier_id{$lock})";
    }

    private static function accountingLockReason(string $date, array $period, ?string $lockedUntil): ?string
    {
        if ($period['status'] !== 'open') {
            return sprintf('Účetní období %d má stav %s.', $period['fiscal_year'], $period['status']);
        }
        return $lockedUntil !== null && $date <= $lockedUntil
            ? sprintf('Zámek účtování k %s.', $lockedUntil)
            : null;
    }

    private function filedReason(int $supplierId, string $date, bool $oss): ?string
    {
        $filed = $this->filedPeriods->describe($supplierId, $date, includeOss: $oss);
        return $filed === null ? null : 'Období už bylo podáno (' . $filed . ').';
    }
}
