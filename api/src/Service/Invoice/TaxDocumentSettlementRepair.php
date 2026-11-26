<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Daňové doklady k přijaté platbě (DDKP), které nesou úplatu uhrazenou částkou bez platby.
 *
 * DDKP dokumentuje úplatu, která přišla na zálohu. MyÚčto ho vystavuje s odečtenou zálohou
 * ve výši úplaty (`advance_paid_amount` = brutto, k úhradě 0) a platby k němu nevede
 * ({@see PaymentTaxDocumentCreator}). Převody ho zakládaly s nulovou zálohou a uhrazenou
 * částkou (`paid_total`) bez platby. První přepočet plateb (vrácení na neuhrazený, změna
 * plateb) ji smaže a DDKP se objeví v pohledávkách jako nezaplacený doklad.
 *
 * Oprava převede DDKP na tvar MyÚčta: záloha = celková částka dokladu, uhrazená částka 0.
 * Stav (uhrazeno), datum úhrady, položky, DPH ani deník se nemění. Doklad, který čeká na
 * převod do měny dokladu ({@see \MyInvoice\Service\Migration\Pohoda\PohodaForeignIssuedRepair},
 * poznámka „převzat v Kč"), se jen ohlásí: převod měny počítá s původním tvarem.
 * Doklad v uzavřeném nebo zamčeném období se jen ohlásí. U DDKP převedeného do měny dokladu
 * se srovná i korunový otisk zálohy a částky k úhradě (`import_advance_paid_czk`,
 * `import_amount_to_pay_czk`), ze kterého čtou korunové sestavy.
 * Idempotentní: opravený doklad už nemá co doplatit.
 */
final class TaxDocumentSettlementRepair
{
    public const SETTLE = 'tax_document_settle';
    public const FOREIGN_PENDING = 'foreign_pending';
    public const LOCKED = PaidWithoutPaymentRepair::LOCKED;
    public const AUDIT_ACTION = 'invoice.tax_document_settled';

    private const PAGE_SIZE = 500;
    private const FOREIGN_NOTE = '%převzat v Kč (%';

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentLockService $locks,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param array<string,bool> $options
     * @return \Generator<int,array<string,mixed>>
     */
    public function run(int $supplierId, bool $commit, array $options = []): \Generator
    {
        $this->locks->reset();
        $afterId = 0;
        do {
            $docs = $this->candidates($supplierId, $afterId);
            if ($docs === []) {
                break;
            }
            $afterId = (int) end($docs)['id'];
            $locks = $this->locks->lockedMapForSources($supplierId, 'invoice', array_map(static fn (array $d): int => (int) $d['id'], $docs));
            foreach ($docs as $doc) {
                $lock = $locks[(int) $doc['id']] ?? null;
                $locked = $lock !== null && ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked);
                $category = match (true) {
                    (bool) $doc['foreign_pending'] => self::FOREIGN_PENDING,
                    $locked => self::LOCKED,
                    default => self::SETTLE,
                };
                $eligible = $category === self::SETTLE;
                $rate = (string) $doc['currency'] === 'CZK' ? 1.0 : ((float) $doc['exchange_rate'] > 0 ? (float) $doc['exchange_rate'] : 1.0);
                $row = [
                    'doc_type' => 'tax_document',
                    'id' => (int) $doc['id'],
                    'supplier_id' => $supplierId,
                    'document_no' => (string) $doc['document_no'],
                    'invoice_type' => 'tax_document',
                    'issue_date' => (string) $doc['issue_date'],
                    'paid_at' => $doc['paid_at'] !== null ? substr((string) $doc['paid_at'], 0, 10) : null,
                    'currency' => (string) $doc['currency'],
                    'amount_to_pay' => round((float) $doc['amount_to_pay'], 2),
                    'settled' => 0.0,
                    'paid_total' => round((float) $doc['paid_total'], 2),
                    'missing' => round((float) $doc['amount_to_pay'], 2),
                    'missing_czk' => round((float) $doc['amount_to_pay'] * $rate, 2),
                    'origin' => (bool) $doc['taken_over'] ? 'migration' : (!empty($doc['import_batch_id']) ? 'import' : 'app'),
                    'ledger' => '-',
                    'locked_reasons' => $locked ? array_values(array_intersect($lock->reasons(), ['period_closed', 'period_closing', 'date_locked'])) : [],
                    'category' => $category,
                    'flags' => [$category],
                    'eligible' => $eligible,
                    'outcome' => $eligible ? 'would_reset' : 'kept',
                    'reason' => match ($category) {
                        self::FOREIGN_PENDING => 'Čeká na převod do měny dokladu (repair-pohoda-foreign-issued.php), ten počítá s původním tvarem.',
                        self::LOCKED => 'Doklad v uzavřeném nebo zamčeném období, jen ke kontrole.',
                        default => 'Úplatu nese uhrazená částka bez platby, převede se na odečtenou zálohu jako DDKP vystavený v MyÚčtu.',
                    },
                ];
                if ($commit && $eligible) {
                    try {
                        $row['outcome'] = $this->settle($supplierId, $doc) ? 'reset' : 'skipped';
                    } catch (\Throwable $e) {
                        $row['outcome'] = 'failed';
                        $row['reason'] = $e->getMessage();
                    }
                }
                yield $row;
            }
        } while (count($docs) === self::PAGE_SIZE);
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $supplierId, int $afterId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol AS document_no, i.issue_date, i.effective_tax_date, i.booked_at, i.status, i.paid_at,
                    i.amount_to_pay, i.paid_total, i.total_with_vat, i.advance_paid_amount, i.exchange_rate, c.code AS currency, i.import_batch_id,
                    (i.exchange_rate IS NULL AND COALESCE(i.note_below_items, '') LIKE ?) AS foreign_pending,
                    (" . TakenOverRecord::documentSql('invoice', 'i') . ") AS taken_over
               FROM invoices i
               JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ? AND i.status = 'paid' AND i.id > ?
                AND i.invoice_type = 'tax_document' AND i.total_with_vat > 0 AND i.amount_to_pay > 0.005
                AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id)
              ORDER BY i.id LIMIT " . self::PAGE_SIZE
        );
        $stmt->execute([self::FOREIGN_NOTE, $supplierId, $afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $doc */
    private function settle(int $supplierId, array $doc): bool
    {
        $id = (int) $doc['id'];
        $stmt = $this->db->pdo()->prepare(
            "UPDATE invoices i SET i.advance_paid_amount = i.total_with_vat, i.paid_total = 0,
                    i.import_advance_paid_czk = IF(i.import_total_with_vat_czk IS NOT NULL, i.import_total_with_vat_czk, i.import_advance_paid_czk),
                    i.import_amount_to_pay_czk = IF(i.import_amount_to_pay_czk IS NOT NULL, 0, NULL)
              WHERE i.supplier_id = ? AND i.id = ? AND i.status = 'paid' AND i.invoice_type = 'tax_document'
                AND i.total_with_vat > 0 AND i.amount_to_pay > 0.005
                AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id)"
        );
        $stmt->execute([$supplierId, $id]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        try {
            $this->pdf->invalidate($id, 'invalidate_payment_change');
            $this->stats->recomputeAfterCommit($id);
        } catch (\Throwable $e) {
            error_log('TaxDocumentSettlementRepair: obnova PDF nebo statistik selhala: ' . $e->getMessage());
        }
        $this->logger->log(self::AUDIT_ACTION, null, 'invoice', $id, [
            'source' => PaidWithoutPaymentRepair::AUDIT_SOURCE,
            'note' => 'DDKP nesl úplatu uhrazenou částkou bez platby, převeden na odečtenou zálohu.',
            'advance_paid_before' => round((float) $doc['advance_paid_amount'], 2),
            'paid_total_before' => round((float) $doc['paid_total'], 2),
            'total_with_vat' => round((float) $doc['total_with_vat'], 2),
        ], null, null, $supplierId);
        return true;
    }
}
